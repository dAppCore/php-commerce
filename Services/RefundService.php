<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Services;

use Core\Mod\Commerce\Models\Payment;
use Core\Mod\Commerce\Models\Refund;
use Core\Mod\Commerce\Notifications\RefundProcessed;
use Core\Tenant\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RefundService
{
    public function __construct(
        protected CommerceService $commerce
    ) {}

    /**
     * Process a full refund for a payment.
     */
    public function refundFull(
        Payment $payment,
        string $reason = 'requested_by_customer',
        ?string $notes = null,
        ?User $initiatedBy = null
    ): Refund {
        $refundableAmount = $this->getMaxRefundableAmount($payment);

        return $this->refund($payment, $refundableAmount, $reason, $notes, $initiatedBy);
    }

    /**
     * Process a partial refund for a payment.
     */
    public function refund(
        Payment $payment,
        float $amount,
        string $reason = 'requested_by_customer',
        ?string $notes = null,
        ?User $initiatedBy = null
    ): Refund {
        // Validate refund amount
        $maxRefundable = $payment->amount - $payment->refunded_amount;

        if ($amount > $maxRefundable) {
            throw new \InvalidArgumentException(
                "Refund amount ({$amount}) exceeds maximum refundable amount ({$maxRefundable})"
            );
        }

        if ($amount <= 0) {
            throw new \InvalidArgumentException('Refund amount must be greater than zero');
        }

        // Can only refund successful or partially refunded payments
        if (! in_array($payment->status, ['succeeded', 'partially_refunded'])) {
            throw new \InvalidArgumentException('Can only refund successful payments');
        }

        return DB::transaction(function () use ($payment, $amount, $reason, $notes, $initiatedBy) {
            // Create refund record
            $refund = Refund::create([
                'payment_id' => $payment->id,
                'amount' => $amount,
                'currency' => $payment->currency,
                'status' => 'pending',
                'reason' => $reason,
                'notes' => $notes,
                'initiated_by' => $initiatedBy?->id,
            ]);

            // Process refund through gateway
            try {
                $gateway = $this->commerce->getGateway($payment->gateway);
                $result = $gateway->refund($payment, $amount, $reason);

                if ($result['success']) {
                    $refund->markAsSucceeded($result['refund_id'] ?? null);

                    // Send notification
                    $this->notifyRefundProcessed($payment, $refund);

                    Log::info('Refund processed successfully', [
                        'refund_id' => $refund->id,
                        'payment_id' => $payment->id,
                        'amount' => $amount,
                    ]);
                } else {
                    $refund->markAsFailed($result);

                    Log::warning('Refund failed at gateway', [
                        'refund_id' => $refund->id,
                        'payment_id' => $payment->id,
                        'response' => $result,
                    ]);
                }
            } catch (\Exception $e) {
                $refund->markAsFailed(['error' => $e->getMessage()]);

                Log::error('Refund processing error', [
                    'refund_id' => $refund->id,
                    'payment_id' => $payment->id,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }

            return $refund;
        });
    }

    /**
     * Check if a payment can be refunded.
     */
    public function canRefund(Payment $payment): bool
    {
        if (! in_array($payment->status, ['succeeded', 'partially_refunded'])) {
            return false;
        }

        if ($payment->isFullyRefunded()) {
            return false;
        }

        // Check gateway-specific refund window (usually 180 days for Stripe)
        $refundWindowDays = config('commerce.refunds.window_days', 180);
        if ($payment->created_at && $payment->created_at->diffInDays(now()) > $refundWindowDays) {
            return false;
        }

        return true;
    }

    /**
     * Get maximum refundable amount for a payment.
     */
    public function getMaxRefundableAmount(Payment $payment): float
    {
        return max(0, $payment->amount - $payment->refunded_amount);
    }

    /**
     * Notify user of processed refund.
     */
    protected function notifyRefundProcessed(Payment $payment, Refund $refund): void
    {
        if (! config('commerce.notifications.refund_processed', true)) {
            return;
        }

        $workspace = $payment->workspace;
        $owner = $workspace?->owner();

        if ($owner) {
            $owner->notify(new RefundProcessed($refund));
        }
    }

    /**
     * Get refund history for a payment.
     */
    public function getRefundsForPayment(Payment $payment): Collection
    {
        return $payment->refunds()->latest()->get();
    }

    /**
     * Get all refunds for a workspace.
     */
    public function getRefundsForWorkspace(int $workspaceId): Collection
    {
        return Refund::query()
            ->whereHas('payment', function ($query) use ($workspaceId) {
                $query->where('workspace_id', $workspaceId);
            })
            ->with('payment')
            ->latest()
            ->get();
    }
}
