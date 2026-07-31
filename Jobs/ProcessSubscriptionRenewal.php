<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Jobs;

use Core\Mod\Commerce\Events\SubscriptionRenewed;
use Core\Mod\Commerce\Models\Subscription;
use Core\Tenant\Models\EntitlementLog;
use Core\Tenant\Models\WorkspacePackage;
use Core\Tenant\Services\EntitlementService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Process subscription renewal: extend package, reset cycle boosts, update usage.
 *
 * This job is dispatched when a subscription renews (payment succeeds for new period).
 * It ensures entitlements are extended and cycle-bound boosts are reset.
 */
class ProcessSubscriptionRenewal implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public Subscription $subscription,
        public ?\DateTimeInterface $newPeriodEnd = null
    ) {
    }

    public function handle(EntitlementService $entitlements): void
    {
        $workspace = $this->subscription->workspace;

        if (! $workspace) {
            Log::warning('ProcessSubscriptionRenewal: Subscription has no workspace', [
                'subscription_id' => $this->subscription->id,
            ]);

            return;
        }

        $workspacePackage = $this->subscription->workspacePackage;

        if (! $workspacePackage) {
            Log::warning('ProcessSubscriptionRenewal: Subscription has no workspace package', [
                'subscription_id' => $this->subscription->id,
            ]);

            return;
        }

        $previousExpiry = $workspacePackage->expires_at;
        $newExpiry = $this->newPeriodEnd ?? $this->subscription->current_period_end;

        // 1. Extend package expiry
        $workspacePackage->update([
            'expires_at' => $newExpiry,
            'billing_cycle_anchor' => now(),
            'status' => WorkspacePackage::STATUS_ACTIVE,
        ]);

        // 2. Expire cycle-bound boosts from the previous billing cycle
        $entitlements->expireCycleBoundBoosts($workspace);

        // 3. Invalidate entitlement cache
        $entitlements->invalidateCache($workspace);

        // 4. Log the renewal
        EntitlementLog::logPackageAction(
            $workspace,
            EntitlementLog::ACTION_PACKAGE_RENEWED,
            $workspacePackage,
            source: EntitlementLog::SOURCE_COMMERCE,
            metadata: [
                'subscription_id' => $this->subscription->id,
                'previous_expires_at' => $previousExpiry?->toIso8601String(),
                'new_expires_at' => $newExpiry?->toIso8601String(),
            ]
        );

        Log::info('Subscription renewal processed', [
            'subscription_id' => $this->subscription->id,
            'workspace_id' => $workspace->id,
            'package_code' => $workspacePackage->package->code ?? 'unknown',
            'new_expiry' => $newExpiry?->toIso8601String(),
        ]);

        // 5. Fire event for any additional listeners
        event(new SubscriptionRenewed($this->subscription, $previousExpiry));
    }
}
