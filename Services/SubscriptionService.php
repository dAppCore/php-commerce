<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Services;

use Carbon\Carbon;
use Core\Mod\Commerce\Exceptions\PauseLimitExceededException;
use Core\Mod\Commerce\Models\Subscription;
use Core\Tenant\Models\Package;
use Core\Tenant\Models\Workspace;
use Core\Tenant\Models\WorkspacePackage;
use Core\Tenant\Services\EntitlementService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SubscriptionService
{
    public function __construct(
        protected CommerceService $commerce,
        protected EntitlementService $entitlements,
    ) {}

    /**
     * Create a new subscription for a workspace package.
     */
    public function create(
        WorkspacePackage $workspacePackage,
        string $billingCycle = 'monthly',
        ?string $gateway = null,
        ?string $gatewaySubscriptionId = null
    ): Subscription {
        // Use fixed days for predictable billing periods
        $periodEnd = $billingCycle === 'yearly'
            ? Carbon::now()->addDays(365)
            : Carbon::now()->addDays(30);

        return Subscription::create([
            'workspace_id' => $workspacePackage->workspace_id,
            'workspace_package_id' => $workspacePackage->id,
            'status' => 'active',
            'gateway' => $gateway ?? config('commerce.default_gateway', 'btcpay'),
            'gateway_subscription_id' => $gatewaySubscriptionId,
            'current_period_start' => Carbon::now(),
            'current_period_end' => $periodEnd,
            'billing_cycle' => $billingCycle,
        ]);
    }

    /**
     * Cancel a subscription (set to expire at period end).
     */
    public function cancel(Subscription $subscription, ?string $reason = null): Subscription
    {
        $subscription->update([
            'cancelled_at' => Carbon::now(),
            'cancellation_reason' => $reason,
        ]);

        return $subscription->fresh();
    }

    /**
     * Resume a cancelled subscription (before it expires).
     */
    public function resume(Subscription $subscription): Subscription
    {
        if (! $subscription->cancelled_at) {
            return $subscription;
        }

        // Only resume if still within billing period
        if ($subscription->current_period_end && $subscription->current_period_end->isFuture()) {
            $subscription->update([
                'cancelled_at' => null,
                'cancellation_reason' => null,
            ]);
        }

        return $subscription->fresh();
    }

    /**
     * Renew a subscription for another billing period.
     */
    public function renew(Subscription $subscription): Subscription
    {
        $billingCycle = $subscription->billing_cycle ?? 'monthly';

        $newPeriodStart = $subscription->current_period_end ?? Carbon::now();
        // Use fixed days for predictable billing periods
        $newPeriodEnd = $billingCycle === 'yearly'
            ? $newPeriodStart->copy()->addDays(365)
            : $newPeriodStart->copy()->addDays(30);

        $subscription->update([
            'current_period_start' => $newPeriodStart,
            'current_period_end' => $newPeriodEnd,
            'cancelled_at' => null,
            'cancellation_reason' => null,
        ]);

        return $subscription->fresh();
    }

    /**
     * Expire a subscription (end it immediately or at period end).
     */
    public function expire(Subscription $subscription): Subscription
    {
        $subscription->update([
            'status' => 'expired',
            'ended_at' => Carbon::now(),
        ]);

        // Revoke the associated workspace package if configured
        if ($subscription->workspacePackage) {
            $subscription->workspacePackage->update([
                'status' => 'expired',
                'expires_at' => Carbon::now(),
            ]);
        }

        return $subscription->fresh();
    }

    /**
     * Pause a subscription (for dunning/failed payments).
     *
     * @param  bool  $force  Skip pause limit check (for dunning/system use)
     *
     * @throws PauseLimitExceededException When pause limit exceeded and not forced
     */
    public function pause(Subscription $subscription, bool $force = false): Subscription
    {
        // Cannot pause a subscription that is not active
        if ($subscription->status !== 'active') {
            return $subscription;
        }

        // Check if pause is allowed by config
        if (! config('commerce.subscriptions.allow_pause', true)) {
            throw new \InvalidArgumentException('Subscription pausing is not enabled.');
        }

        // Check pause limit unless forced (e.g., by dunning service)
        if (! $force && ! $subscription->canPause()) {
            $maxPauseCycles = config('commerce.subscriptions.max_pause_cycles', 3);

            throw new PauseLimitExceededException($subscription, $maxPauseCycles);
        }

        $subscription->update([
            'status' => 'paused',
            'paused_at' => Carbon::now(),
            'pause_count' => ($subscription->pause_count ?? 0) + 1,
        ]);

        Log::info('Subscription paused', [
            'subscription_id' => $subscription->id,
            'pause_count' => $subscription->fresh()->pause_count,
            'forced' => $force,
        ]);

        return $subscription->fresh();
    }

    /**
     * Unpause a subscription.
     */
    public function unpause(Subscription $subscription): Subscription
    {
        if ($subscription->status !== 'paused') {
            return $subscription;
        }

        $subscription->update([
            'status' => 'active',
            'paused_at' => null,
        ]);

        return $subscription->fresh();
    }

    /**
     * Change subscription to a different package (upgrade/downgrade).
     *
     * @param  bool  $prorate  Whether to prorate (charge/credit difference immediately)
     * @param  bool  $immediate  Whether to apply change immediately or at period end
     */
    public function changePlan(
        Subscription $subscription,
        Package $newPackage,
        bool $prorate = true,
        bool $immediate = true
    ): array {
        return DB::transaction(function () use ($subscription, $newPackage, $prorate, $immediate) {
            $workspace = $subscription->workspace;
            $currentPackage = $subscription->workspacePackage?->package;
            $billingCycle = $subscription->billing_cycle ?? 'monthly';

            // Calculate proration if enabled
            $proration = null;
            if ($prorate && $currentPackage && $immediate) {
                $proration = $this->calculateProration(
                    $subscription,
                    $currentPackage,
                    $newPackage,
                    $billingCycle
                );
            }

            if ($immediate) {
                // Provision new package immediately
                $newWorkspacePackage = $this->entitlements->provisionPackage(
                    $workspace,
                    $newPackage->code,
                    [
                        'subscription_id' => $subscription->id,
                        'source' => $subscription->gateway,
                        'prorated_from' => $currentPackage?->code,
                    ]
                );

                // Update subscription to point to new package
                $subscription->update([
                    'workspace_package_id' => $newWorkspacePackage->id,
                    'metadata' => array_merge($subscription->metadata ?? [], [
                        'plan_change' => [
                            'from' => $currentPackage?->code,
                            'to' => $newPackage->code,
                            'changed_at' => now()->toISOString(),
                            'proration' => $proration?->toArray(),
                        ],
                    ]),
                ]);

                // Revoke old package entitlements
                if ($currentPackage) {
                    $this->entitlements->revokePackage($workspace, $currentPackage->code);
                }

                Log::info('Subscription plan changed immediately', [
                    'subscription_id' => $subscription->id,
                    'from_package' => $currentPackage?->code,
                    'to_package' => $newPackage->code,
                    'proration' => $proration?->toArray(),
                ]);
            } else {
                // Schedule change for end of billing period
                $subscription->update([
                    'metadata' => array_merge($subscription->metadata ?? [], [
                        'pending_plan_change' => [
                            'to_package_id' => $newPackage->id,
                            'to_package_code' => $newPackage->code,
                            'scheduled_for' => $subscription->current_period_end?->toISOString(),
                        ],
                    ]),
                ]);

                Log::info('Subscription plan change scheduled', [
                    'subscription_id' => $subscription->id,
                    'to_package' => $newPackage->code,
                    'scheduled_for' => $subscription->current_period_end,
                ]);
            }

            return [
                'subscription' => $subscription->fresh(),
                'proration' => $proration,
                'immediate' => $immediate,
            ];
        });
    }

    /**
     * Calculate proration for a plan change.
     */
    public function calculateProration(
        Subscription $subscription,
        Package $currentPackage,
        Package $newPackage,
        string $billingCycle = 'monthly'
    ): ProrationResult {
        $now = Carbon::now();
        $periodStart = $subscription->current_period_start;
        $periodEnd = $subscription->current_period_end;

        // Calculate days in period and days remaining
        // Note: diffInDays returns absolute value when using absolute: true (default in Carbon 2)
        // In Carbon 3, we need to ensure we get positive values
        $totalPeriodDays = (int) $periodStart->diffInDays($periodEnd, absolute: true);
        $daysUsed = (int) $periodStart->diffInDays($now, absolute: true);
        $daysRemaining = (int) max(0, $now->diffInDays($periodEnd, absolute: true));

        // Avoid division by zero
        if ($totalPeriodDays <= 0) {
            $totalPeriodDays = $billingCycle === 'yearly' ? 365 : 30;
        }

        $usedPercentage = $daysUsed / $totalPeriodDays;
        $remainingPercentage = 1 - $usedPercentage;

        // Get prices for the billing cycle
        $currentPrice = $currentPackage->getPrice($billingCycle);
        $newPrice = $newPackage->getPrice($billingCycle);

        // Calculate credit from unused current plan time
        $creditAmount = round($currentPrice * $remainingPercentage, 2);

        // Calculate prorated cost for new plan for remaining period
        $proratedNewCost = round($newPrice * $remainingPercentage, 2);

        // Net amount: positive = customer pays, negative = credit
        $netAmount = round($proratedNewCost - $creditAmount, 2);

        return new ProrationResult(
            daysRemaining: $daysRemaining,
            totalPeriodDays: $totalPeriodDays,
            usedPercentage: round($usedPercentage, 4),
            currentPlanPrice: $currentPrice,
            newPlanPrice: $newPrice,
            creditAmount: $creditAmount,
            proratedNewPlanCost: $proratedNewCost,
            netAmount: $netAmount,
            currency: config('commerce.currency', 'GBP'),
        );
    }

    /**
     * Preview proration without making changes.
     */
    public function previewPlanChange(
        Subscription $subscription,
        Package $newPackage,
        ?string $billingCycle = null
    ): ProrationResult {
        $currentPackage = $subscription->workspacePackage?->package;

        if (! $currentPackage) {
            throw new \InvalidArgumentException('Subscription has no current package');
        }

        $billingCycle = $billingCycle ?? $subscription->billing_cycle ?? 'monthly';

        return $this->calculateProration(
            $subscription,
            $currentPackage,
            $newPackage,
            $billingCycle
        );
    }

    /**
     * Apply scheduled plan change (called when period ends).
     */
    public function applyScheduledPlanChange(Subscription $subscription): ?Subscription
    {
        $pendingChange = $subscription->metadata['pending_plan_change'] ?? null;

        if (! $pendingChange) {
            return null;
        }

        $newPackage = Package::find($pendingChange['to_package_id']);

        if (! $newPackage) {
            Log::warning('Scheduled plan change failed: package not found', [
                'subscription_id' => $subscription->id,
                'package_id' => $pendingChange['to_package_id'],
            ]);

            return null;
        }

        // Apply the change without proration (since it's at period end)
        $result = $this->changePlan($subscription, $newPackage, prorate: false, immediate: true);

        // Clear the pending change
        $metadata = $subscription->metadata ?? [];
        unset($metadata['pending_plan_change']);
        $subscription->update(['metadata' => $metadata]);

        return $result['subscription'];
    }

    /**
     * Cancel a pending plan change.
     */
    public function cancelScheduledPlanChange(Subscription $subscription): Subscription
    {
        $metadata = $subscription->metadata ?? [];
        unset($metadata['pending_plan_change']);

        $subscription->update(['metadata' => $metadata]);

        return $subscription->fresh();
    }

    /**
     * Check if subscription has a pending plan change.
     */
    public function hasPendingPlanChange(Subscription $subscription): bool
    {
        return isset($subscription->metadata['pending_plan_change']);
    }

    /**
     * Get pending plan change details.
     */
    public function getPendingPlanChange(Subscription $subscription): ?array
    {
        return $subscription->metadata['pending_plan_change'] ?? null;
    }

    /**
     * Get subscriptions expiring soon (for renewal reminders).
     */
    public function getExpiringSoon(int $days = 7): Collection
    {
        return Subscription::query()
            ->active()
            ->whereNull('cancelled_at')
            ->where('current_period_end', '<=', Carbon::now()->addDays($days))
            ->where('current_period_end', '>', Carbon::now())
            ->with('workspace', 'workspacePackage.package')
            ->get();
    }

    /**
     * Get subscriptions that have failed payment and need dunning.
     */
    public function getFailedPayments(): Collection
    {
        return Subscription::query()
            ->where('status', 'past_due')
            ->with('workspace', 'workspacePackage.package')
            ->get();
    }

    /**
     * Process expired subscriptions (called by scheduler).
     */
    public function processExpired(): int
    {
        $expired = Subscription::query()
            ->active()
            ->whereNotNull('cancelled_at')
            ->where('current_period_end', '<=', Carbon::now())
            ->get();

        foreach ($expired as $subscription) {
            $this->expire($subscription);
        }

        return $expired->count();
    }
}
