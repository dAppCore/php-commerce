<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Services;

use Carbon\Carbon;
use Core\Mod\Commerce\Data\DunningSchedule;
use Core\Mod\Commerce\Data\PaymentResult;
use Core\Mod\Commerce\Models\Invoice;
use Core\Mod\Commerce\Models\Subscription;
use Core\Mod\Commerce\Notifications\AccountSuspended;
use Core\Mod\Commerce\Notifications\PaymentFailed;
use Core\Mod\Commerce\Notifications\PaymentRetry;
use Core\Mod\Commerce\Notifications\SubscriptionCancelled;
use Core\Mod\Commerce\Notifications\SubscriptionPaused;
use Core\Tenant\Services\EntitlementService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Dunning service for failed payment recovery.
 *
 * Flow:
 * 1. Payment fails → invoice marked as overdue, subscription marked past_due
 * 2. Retry attempts scheduled (exponential backoff: 1, 3, 7 days by default)
 * 3. After max retries → subscription paused, notification sent
 * 4. After suspend_after_days → workspace suspended via EntitlementService
 * 5. After cancel_after_days → subscription cancelled, workspace downgraded
 */
class DunningService
{
    public function __construct(
        protected CommerceService $commerce,
        protected SubscriptionService $subscriptions,
        protected EntitlementService $entitlements,
    ) {}

    /**
     * Build and persist the failed-payment retry schedule for a subscription.
     */
    public function schedule(Subscription $subscription): DunningSchedule
    {
        if (in_array($subscription->status, ['cancelled', 'expired'], true)) {
            throw new InvalidArgumentException('Cannot schedule dunning for an ended subscription.');
        }

        $anchor = $this->dunningAnchor($subscription);
        $retryDays = $this->retryDays();
        $retryDates = array_map(
            fn (int $days): Carbon => $anchor->copy()->addDays($days),
            $retryDays
        );
        $suspensionDate = $anchor->copy()->addDays($this->suspendAfterDays());
        $schedule = new DunningSchedule($retryDates, $suspensionDate);

        $metadata = $subscription->metadata ?? [];
        $metadata['dunning'] = [
            'stage' => 'scheduled',
            'started_at' => $anchor->toISOString(),
            'retry_dates' => array_map(
                fn (Carbon $date): string => $date->toISOString(),
                $retryDates
            ),
            'suspension_date' => $suspensionDate->toISOString(),
        ];

        $updates = ['metadata' => $metadata];
        if (in_array($subscription->status, ['active', 'trialing'], true)) {
            $updates['status'] = 'past_due';
        }

        $subscription->update($updates);

        Log::info('Dunning schedule created', [
            'subscription_id' => $subscription->id,
            'workspace_id' => $subscription->workspace_id,
            'retry_dates' => $metadata['dunning']['retry_dates'],
            'suspension_date' => $metadata['dunning']['suspension_date'],
        ]);

        return $schedule;
    }

    /**
     * Retry payment for an overdue invoice.
     */
    public function retry(Invoice $invoice): PaymentResult
    {
        if ($invoice->isPaid()) {
            $subscription = $this->findSubscriptionForInvoice($invoice);
            if ($subscription) {
                $this->recover($subscription);
            }

            return PaymentResult::successful($invoice->payment, $invoice->charge_attempts ?? 0);
        }

        if (! $invoice->auto_charge) {
            return PaymentResult::failed(
                'Invoice is not configured for automatic charging.',
                $invoice->charge_attempts ?? 0
            );
        }

        $attempts = ($invoice->charge_attempts ?? 0) + 1;

        $invoice->update([
            'status' => 'overdue',
            'charge_attempts' => $attempts,
            'last_charge_attempt' => now(),
        ]);

        try {
            $successful = $this->commerce->retryInvoicePayment($invoice->fresh());
        } catch (\Throwable $e) {
            $nextRetry = $this->calculateNextRetry($attempts);

            $invoice->update([
                'next_charge_attempt' => $nextRetry,
            ]);

            Log::error('Payment retry exception', [
                'invoice_id' => $invoice->id,
                'attempt' => $attempts,
                'error' => $e->getMessage(),
            ]);

            return PaymentResult::failed($e->getMessage(), $attempts, $nextRetry);
        }

        $invoice->refresh();
        $subscription = $this->findSubscriptionForInvoice($invoice);

        if ($successful) {
            $invoice->update([
                'next_charge_attempt' => null,
            ]);

            if ($subscription) {
                $this->recover($subscription);
            }

            Log::info('Payment retry succeeded', [
                'invoice_id' => $invoice->id,
                'subscription_id' => $subscription?->id,
                'attempt' => $attempts,
            ]);

            return PaymentResult::successful($invoice->payment, $attempts);
        }

        $nextRetry = $this->calculateNextRetry($attempts);
        $invoice->update([
            'next_charge_attempt' => $nextRetry,
        ]);

        if ($subscription) {
            $this->notify($subscription, 'retry');
        }

        Log::info('Payment retry failed', [
            'invoice_id' => $invoice->id,
            'subscription_id' => $subscription?->id,
            'attempt' => $attempts,
            'next_retry' => $nextRetry,
        ]);

        return PaymentResult::failed('Payment retry failed.', $attempts, $nextRetry);
    }

    /**
     * Suspend a subscription and its workspace after dunning is exhausted.
     */
    public function suspend(Subscription $subscription): void
    {
        if (in_array($subscription->status, ['cancelled', 'expired'], true)) {
            throw new InvalidArgumentException('Cannot suspend an ended subscription.');
        }

        $workspace = $subscription->workspace;
        if (! $workspace) {
            throw new InvalidArgumentException('Cannot suspend a subscription without a workspace.');
        }

        $metadata = $subscription->metadata ?? [];
        $metadata['dunning'] = array_merge($metadata['dunning'] ?? [], [
            'stage' => 'suspended',
            'suspended_at' => now()->toISOString(),
        ]);

        $subscription->update([
            'status' => 'suspended',
            'paused_at' => $subscription->paused_at ?? now(),
            'metadata' => $metadata,
        ]);

        $this->entitlements->suspendWorkspace($workspace, 'dunning');
        $this->notify($subscription->fresh(), 'suspended');

        Log::info('Subscription suspended due to dunning', [
            'subscription_id' => $subscription->id,
            'workspace_id' => $subscription->workspace_id,
        ]);
    }

    /**
     * Send the notification for a dunning stage and dispatch a lightweight stage event.
     */
    public function notify(Subscription $subscription, string $stage): void
    {
        $stage = $this->normaliseStage($stage);
        Event::dispatch('commerce.dunning.notified', [$subscription, $stage]);

        if (! config('commerce.dunning.send_notifications', true)) {
            return;
        }

        $workspace = $subscription->workspace;
        $owner = $workspace?->owner();

        if (! $owner) {
            Log::warning('Dunning notification skipped because no workspace owner was found', [
                'subscription_id' => $subscription->id,
                'workspace_id' => $subscription->workspace_id,
                'stage' => $stage,
            ]);

            return;
        }

        $notification = match ($stage) {
            'failed' => new PaymentFailed($subscription),
            'retry' => $this->retryNotification($subscription),
            'paused' => new SubscriptionPaused($subscription),
            'suspended' => new AccountSuspended($subscription),
            'cancelled' => new SubscriptionCancelled($subscription),
        };

        $owner->notify($notification);
    }

    /**
     * Clear dunning state once payment has recovered.
     */
    public function recover(Subscription $subscription): void
    {
        $wasRestricted = in_array($subscription->status, ['paused', 'suspended'], true);
        $metadata = $subscription->metadata ?? [];
        unset($metadata['dunning']);

        $updates = [
            'metadata' => $metadata,
        ];

        if (in_array($subscription->status, ['past_due', 'paused', 'suspended'], true)) {
            $updates['status'] = 'active';
            $updates['paused_at'] = null;
        }

        $subscription->update($updates);

        if ($subscription->workspace_id) {
            Invoice::query()
                ->where('workspace_id', $subscription->workspace_id)
                ->whereNotNull('next_charge_attempt')
                ->update(['next_charge_attempt' => null]);
        }

        if ($wasRestricted && $subscription->workspace) {
            $this->entitlements->reactivateWorkspace($subscription->workspace, 'dunning_recovery');
        }

        Log::info('Dunning state cleared after payment recovery', [
            'subscription_id' => $subscription->id,
            'workspace_id' => $subscription->workspace_id,
        ]);
    }

    /**
     * Handle a failed payment for an invoice.
     *
     * Called by payment gateways when a charge fails.
     */
    public function handlePaymentFailure(Invoice $invoice, ?Subscription $subscription = null): void
    {
        $currentAttempts = $invoice->charge_attempts ?? 0;
        $isFirstFailure = $currentAttempts === 0;

        // For first failure, apply initial grace period before scheduling retry
        $nextRetry = $isFirstFailure
            ? $this->calculateInitialRetry()
            : $this->calculateNextRetry($currentAttempts);

        $invoice->update([
            'status' => 'overdue',
            'charge_attempts' => $currentAttempts + 1,
            'last_charge_attempt' => now(),
            'next_charge_attempt' => $nextRetry,
        ]);

        // Mark subscription as past due if provided
        if ($subscription && $subscription->isActive()) {
            $subscription->markPastDue();
        }

        // Send initial failure notification
        if (config('commerce.dunning.send_notifications', true)) {
            $owner = $invoice->workspace?->owner();
            if ($owner && $subscription) {
                $owner->notify(new PaymentFailed($subscription));
            }
        }

        Log::info('Payment failure handled', [
            'invoice_id' => $invoice->id,
            'subscription_id' => $subscription?->id,
            'attempt' => $invoice->charge_attempts,
            'next_retry' => $invoice->next_charge_attempt,
        ]);
    }

    /**
     * Handle a successful payment recovery.
     *
     * Called when a retry succeeds or customer manually pays.
     */
    public function handlePaymentRecovery(Invoice $invoice, ?Subscription $subscription = null): void
    {
        // Clear dunning state from invoice
        $invoice->update([
            'next_charge_attempt' => null,
        ]);

        // Reactivate subscription if it was paused
        if ($subscription && $subscription->isPaused()) {
            $this->subscriptions->unpause($subscription);

            // Reactivate workspace entitlements
            $this->entitlements->reactivateWorkspace($subscription->workspace, 'dunning_recovery');
        } elseif ($subscription && $subscription->isPastDue()) {
            $subscription->update(['status' => 'active']);
        }

        Log::info('Payment recovery successful', [
            'invoice_id' => $invoice->id,
            'subscription_id' => $subscription?->id,
        ]);
    }

    /**
     * Get invoices due for retry.
     */
    public function getInvoicesDueForRetry(): Collection
    {
        return Invoice::query()
            ->whereIn('status', ['sent', 'overdue'])
            ->where('auto_charge', true)
            ->whereNotNull('next_charge_attempt')
            ->where('next_charge_attempt', '<=', now())
            ->with('workspace')
            ->get();
    }

    /**
     * Attempt to retry payment for an invoice.
     *
     * @return bool True if payment succeeded
     */
    public function retryPayment(Invoice $invoice): bool
    {
        $retryDays = config('commerce.dunning.retry_days', [1, 3, 7]);
        $maxRetries = count($retryDays);

        try {
            $success = $this->commerce->retryInvoicePayment($invoice);

            if ($success) {
                // Find associated subscription and recover
                $subscription = $this->findSubscriptionForInvoice($invoice);
                $this->handlePaymentRecovery($invoice, $subscription);

                return true;
            }

            // Payment failed - schedule next retry or escalate
            $attempts = ($invoice->charge_attempts ?? 0) + 1;
            $nextRetry = $this->calculateNextRetry($attempts);

            $invoice->update([
                'charge_attempts' => $attempts,
                'last_charge_attempt' => now(),
                'next_charge_attempt' => $nextRetry,
            ]);

            // Send retry notification
            if (config('commerce.dunning.send_notifications', true)) {
                $owner = $invoice->workspace?->owner();
                if ($owner) {
                    $owner->notify(new PaymentRetry($invoice, $attempts, $maxRetries));
                }
            }

            Log::info('Payment retry failed', [
                'invoice_id' => $invoice->id,
                'attempt' => $attempts,
                'next_retry' => $nextRetry,
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('Payment retry exception', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get subscriptions that should be paused (past retry threshold).
     */
    public function getSubscriptionsForPause(): Collection
    {
        $retryDays = config('commerce.dunning.retry_days', [1, 3, 7]);
        $pauseAfterDays = array_sum($retryDays) + 1; // Day after last retry

        return Subscription::query()
            ->where('status', 'past_due')
            ->whereHas('workspace.invoices', function ($query) use ($pauseAfterDays) {
                $query->whereIn('status', ['sent', 'overdue'])
                    ->where('auto_charge', true)
                    ->where('last_charge_attempt', '<=', now()->subDays($pauseAfterDays))
                    ->whereNull('next_charge_attempt'); // No more retries scheduled
            })
            ->with('workspace')
            ->get();
    }

    /**
     * Pause a subscription due to payment failure.
     *
     * Uses force=true to bypass the pause limit check for dunning,
     * as payment failures must always result in a pause regardless
     * of how many times the subscription has been paused before.
     */
    public function pauseSubscription(Subscription $subscription): void
    {
        $this->subscriptions->pause($subscription, force: true);

        if (config('commerce.dunning.send_notifications', true)) {
            $owner = $subscription->workspace?->owner();
            if ($owner) {
                $owner->notify(new SubscriptionPaused($subscription));
            }
        }

        Log::info('Subscription paused due to dunning', [
            'subscription_id' => $subscription->id,
            'workspace_id' => $subscription->workspace_id,
        ]);
    }

    /**
     * Get subscriptions that should have their workspace suspended.
     */
    public function getSubscriptionsForSuspension(): Collection
    {
        $suspendAfterDays = config('commerce.dunning.suspend_after_days', 14);

        return Subscription::query()
            ->where('status', 'paused')
            ->where('paused_at', '<=', now()->subDays($suspendAfterDays))
            ->whereDoesntHave('workspace.workspacePackages', function ($query) {
                $query->where('status', 'suspended');
            })
            ->with('workspace')
            ->get();
    }

    /**
     * Suspend a workspace due to prolonged payment failure.
     */
    public function suspendWorkspace(Subscription $subscription): void
    {
        $workspace = $subscription->workspace;

        if (! $workspace) {
            return;
        }

        // Use EntitlementService to suspend workspace
        $this->entitlements->suspendWorkspace($workspace, 'dunning');

        if (config('commerce.dunning.send_notifications', true)) {
            $owner = $workspace->owner();
            if ($owner) {
                $owner->notify(new AccountSuspended($subscription));
            }
        }

        Log::info('Workspace suspended due to dunning', [
            'subscription_id' => $subscription->id,
            'workspace_id' => $workspace->id,
        ]);
    }

    /**
     * Get subscriptions that should be cancelled.
     */
    public function getSubscriptionsForCancellation(): Collection
    {
        $cancelAfterDays = config('commerce.dunning.cancel_after_days', 30);

        return Subscription::query()
            ->where('status', 'paused')
            ->where('paused_at', '<=', now()->subDays($cancelAfterDays))
            ->with('workspace')
            ->get();
    }

    /**
     * Cancel a subscription due to non-payment.
     */
    public function cancelSubscription(Subscription $subscription): void
    {
        $workspace = $subscription->workspace;

        $this->subscriptions->cancel($subscription, 'Non-payment');
        $this->subscriptions->expire($subscription);

        // Send cancellation notification
        if (config('commerce.dunning.send_notifications', true)) {
            $owner = $workspace?->owner();
            if ($owner) {
                $owner->notify(new SubscriptionCancelled($subscription));
            }
        }

        Log::info('Subscription cancelled due to non-payment', [
            'subscription_id' => $subscription->id,
            'workspace_id' => $subscription->workspace_id,
        ]);
    }

    /**
     * Calculate the initial retry date (after first failure).
     *
     * Respects the initial_grace_hours config to give customers
     * time to fix their payment method before automated retries.
     */
    public function calculateInitialRetry(): Carbon
    {
        $graceHours = config('commerce.dunning.initial_grace_hours', 24);
        $retryDays = config('commerce.dunning.retry_days', [1, 3, 7]);

        // Use the larger of: grace period OR first retry interval
        $firstRetryDays = $retryDays[0] ?? 1;
        $graceInDays = $graceHours / 24;

        $daysUntilRetry = max($graceInDays, $firstRetryDays);

        return now()->addHours((int) ($daysUntilRetry * 24));
    }

    /**
     * Calculate the next retry date based on attempt count.
     *
     * Uses exponential backoff from config.
     */
    public function calculateNextRetry(int $currentAttempts): ?Carbon
    {
        $retryDays = config('commerce.dunning.retry_days', [1, 3, 7]);

        // Account for the initial attempt (attempt 0 used grace period)
        $retryIndex = $currentAttempts;

        if ($retryIndex >= count($retryDays)) {
            return null; // No more retries
        }

        $daysUntilNext = $retryDays[$retryIndex] ?? null;

        return $daysUntilNext ? now()->addDays($daysUntilNext) : null;
    }

    /**
     * Get the dunning status for a subscription.
     *
     * @return array{stage: string, days_overdue: int, next_action: string, next_action_date: ?Carbon}
     */
    public function getDunningStatus(Subscription $subscription): array
    {
        $workspace = $subscription->workspace;
        $overdueInvoice = $workspace?->invoices()
            ->whereIn('status', ['sent', 'overdue'])
            ->where('auto_charge', true)
            ->orderBy('due_date')
            ->first();

        if (! $overdueInvoice) {
            return [
                'stage' => 'none',
                'days_overdue' => 0,
                'next_action' => 'none',
                'next_action_date' => null,
            ];
        }

        $daysOverdue = $overdueInvoice->due_date
            ? (int) $overdueInvoice->due_date->diffInDays(now(), false)
            : 0;

        $suspendDays = config('commerce.dunning.suspend_after_days', 14);
        $cancelDays = config('commerce.dunning.cancel_after_days', 30);

        if ($subscription->status === 'active' || $subscription->status === 'past_due') {
            return [
                'stage' => 'retry',
                'days_overdue' => max(0, $daysOverdue),
                'next_action' => $overdueInvoice->next_charge_attempt ? 'retry' : 'pause',
                'next_action_date' => $overdueInvoice->next_charge_attempt,
            ];
        }

        if ($subscription->status === 'paused') {
            $pausedDays = $subscription->paused_at
                ? (int) $subscription->paused_at->diffInDays(now(), false)
                : 0;

            if ($pausedDays < $suspendDays) {
                return [
                    'stage' => 'paused',
                    'days_overdue' => max(0, $daysOverdue),
                    'next_action' => 'suspend',
                    'next_action_date' => $subscription->paused_at?->addDays($suspendDays),
                ];
            }

            return [
                'stage' => 'suspended',
                'days_overdue' => max(0, $daysOverdue),
                'next_action' => 'cancel',
                'next_action_date' => $subscription->paused_at?->addDays($cancelDays),
            ];
        }

        return [
            'stage' => 'cancelled',
            'days_overdue' => max(0, $daysOverdue),
            'next_action' => 'none',
            'next_action_date' => null,
        ];
    }

    /**
     * Find the subscription associated with an invoice.
     */
    protected function findSubscriptionForInvoice(Invoice $invoice): ?Subscription
    {
        if (! $invoice->workspace_id) {
            return null;
        }

        return Subscription::query()
            ->where('workspace_id', $invoice->workspace_id)
            ->whereIn('status', ['active', 'past_due', 'paused', 'suspended'])
            ->first();
    }

    /**
     * @return array<int, int>
     */
    protected function retryDays(): array
    {
        $retryDays = config('commerce.dunning.retry_days', [1, 3, 7]);

        if (! is_array($retryDays)) {
            throw new InvalidArgumentException('Dunning retry days must be configured as an array.');
        }

        return array_map(function (mixed $days): int {
            if (! is_numeric($days) || (int) $days < 1) {
                throw new InvalidArgumentException('Dunning retry days must be positive integers.');
            }

            return (int) $days;
        }, array_values($retryDays));
    }

    protected function suspendAfterDays(): int
    {
        $days = config('commerce.dunning.suspend_after_days', 14);

        if (! is_numeric($days) || (int) $days < 1) {
            throw new InvalidArgumentException('Dunning suspension days must be a positive integer.');
        }

        return (int) $days;
    }

    protected function dunningAnchor(Subscription $subscription): Carbon
    {
        $startedAt = data_get($subscription->metadata, 'dunning.started_at');

        if ($startedAt) {
            return Carbon::parse($startedAt);
        }

        $invoice = $this->latestDunningInvoice($subscription);
        $anchor = $invoice?->last_charge_attempt
            ?? $invoice?->due_date
            ?? now();

        return $anchor instanceof Carbon
            ? $anchor->copy()
            : Carbon::parse($anchor);
    }

    protected function latestDunningInvoice(Subscription $subscription): ?Invoice
    {
        if (! $subscription->workspace_id) {
            return null;
        }

        return Invoice::query()
            ->where('workspace_id', $subscription->workspace_id)
            ->whereIn('status', ['sent', 'pending', 'overdue'])
            ->orderByRaw('COALESCE(last_charge_attempt, due_date, created_at) DESC')
            ->first();
    }

    protected function retryNotification(Subscription $subscription): PaymentRetry|PaymentFailed
    {
        $invoice = $this->latestDunningInvoice($subscription);

        if (! $invoice) {
            return new PaymentFailed($subscription);
        }

        return new PaymentRetry(
            $invoice,
            $invoice->charge_attempts ?? 0,
            count($this->retryDays())
        );
    }

    protected function normaliseStage(string $stage): string
    {
        return match (strtolower(trim($stage))) {
            'failed', 'payment_failed', 'payment-failed' => 'failed',
            'retry', 'payment_retry', 'payment-retry' => 'retry',
            'pause', 'paused' => 'paused',
            'suspend', 'suspended', 'suspension' => 'suspended',
            'cancel', 'cancelled', 'cancellation' => 'cancelled',
            default => throw new InvalidArgumentException("Unknown dunning stage [{$stage}]."),
        };
    }
}
