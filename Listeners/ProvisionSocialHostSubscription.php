<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Listeners;

use Core\Mod\Commerce\Events\SubscriptionCancelled;
use Core\Mod\Commerce\Events\SubscriptionCreated;
use Core\Mod\Commerce\Events\SubscriptionRenewed;
use Core\Mod\Commerce\Events\SubscriptionUpdated;
use Core\Mod\Commerce\Jobs\ProcessSubscriptionRenewal;
use Core\Tenant\Models\Package;
use Core\Tenant\Models\WorkspacePackage;
use Core\Tenant\Services\EntitlementService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Provisions SocialHost entitlements when subscriptions are created/modified.
 *
 * This listener bridges Commerce subscriptions to Entitlement packages,
 * automatically granting or revoking SocialHost features based on subscription status.
 */
class ProvisionSocialHostSubscription implements ShouldQueue
{
    public function __construct(
        protected EntitlementService $entitlementService
    ) {}

    /**
     * Handle subscription created event.
     */
    public function handleSubscriptionCreated(SubscriptionCreated $event): void
    {
        $subscription = $event->subscription;

        if (! $this->isSocialHostProduct($subscription)) {
            return;
        }

        $this->provisionPackage($subscription);

        Log::info('SocialHost subscription provisioned', [
            'subscription_id' => $subscription->id,
            'workspace_id' => $subscription->workspace_id,
            'product' => $subscription->product->slug ?? 'unknown',
        ]);
    }

    /**
     * Handle subscription updated event.
     */
    public function handleSubscriptionUpdated(SubscriptionUpdated $event): void
    {
        $subscription = $event->subscription;

        if (! $this->isSocialHostProduct($subscription)) {
            return;
        }

        // Handle plan changes (upgrades/downgrades)
        if ($subscription->wasChanged('product_id') || $subscription->wasChanged('price_id')) {
            // Remove old package
            $this->revokePackage($subscription, $event->previousStatus);

            // Add new package
            $this->provisionPackage($subscription);
        }

        // Handle status changes
        if ($subscription->wasChanged('status')) {
            if ($subscription->status === 'active') {
                $this->provisionPackage($subscription);
            } elseif (in_array($subscription->status, ['cancelled', 'expired'])) {
                $this->revokePackage($subscription);
            }
        }

        Log::info('SocialHost subscription updated', [
            'subscription_id' => $subscription->id,
            'workspace_id' => $subscription->workspace_id,
            'status' => $subscription->status,
        ]);
    }

    /**
     * Handle subscription cancelled event.
     */
    public function handleSubscriptionCancelled(SubscriptionCancelled $event): void
    {
        $subscription = $event->subscription;

        if (! $this->isSocialHostProduct($subscription)) {
            return;
        }

        if ($event->immediate) {
            // Immediate cancellation - revoke now
            $this->revokePackage($subscription);
        } else {
            // End of period cancellation - package stays until period ends
            // The scheduled task will handle revocation at period end
            Log::info('SocialHost subscription scheduled for cancellation', [
                'subscription_id' => $subscription->id,
                'ends_at' => $subscription->current_period_end,
            ]);
        }
    }

    /**
     * Check if this is a SocialHost subscription.
     */
    protected function isSocialHostProduct($subscription): bool
    {
        // Check via workspace package -> package
        $workspacePackage = $subscription->workspacePackage;

        if ($workspacePackage && $workspacePackage->package) {
            $packageCode = $workspacePackage->package->code;

            return str_starts_with($packageCode, 'social-');
        }

        // Check subscription metadata
        $metadata = $subscription->metadata ?? [];
        if (isset($metadata['product_line']) && $metadata['product_line'] === 'socialhost') {
            return true;
        }

        return false;
    }

    /**
     * Provision the entitlement package for the subscription.
     */
    protected function provisionPackage($subscription): void
    {
        $workspacePackage = $subscription->workspacePackage;
        $workspace = $subscription->workspace;

        if (! $workspace) {
            return;
        }

        // If we already have a workspace package linked, just ensure it's active
        if ($workspacePackage) {
            $workspacePackage->update([
                'status' => WorkspacePackage::STATUS_ACTIVE,
                'expires_at' => $subscription->current_period_end,
                'metadata' => array_merge($workspacePackage->metadata ?? [], [
                    'subscription_id' => $subscription->id,
                    'source' => 'commerce',
                ]),
            ]);

            return;
        }

        // Otherwise, get package from subscription metadata
        $packageCode = $subscription->metadata['package_code'] ?? null;

        if (! $packageCode) {
            Log::warning('SocialHost subscription missing package_code', [
                'subscription_id' => $subscription->id,
            ]);

            return;
        }

        $package = Package::where('code', $packageCode)->first();

        if (! $package) {
            Log::warning('SocialHost package not found', [
                'package_code' => $packageCode,
            ]);

            return;
        }

        // Check if already provisioned
        $existing = WorkspacePackage::where([
            'workspace_id' => $workspace->id,
            'package_id' => $package->id,
        ])->first();

        if ($existing) {
            // Update existing assignment
            $existing->update([
                'status' => WorkspacePackage::STATUS_ACTIVE,
                'expires_at' => $subscription->current_period_end,
                'metadata' => array_merge($existing->metadata ?? [], [
                    'subscription_id' => $subscription->id,
                    'source' => 'commerce',
                ]),
            ]);

            // Link to subscription
            $subscription->update(['workspace_package_id' => $existing->id]);
        } else {
            // Create new assignment
            $newPackage = WorkspacePackage::create([
                'workspace_id' => $workspace->id,
                'package_id' => $package->id,
                'status' => WorkspacePackage::STATUS_ACTIVE,
                'expires_at' => $subscription->current_period_end,
                'metadata' => [
                    'subscription_id' => $subscription->id,
                    'source' => 'commerce',
                ],
            ]);

            // Link to subscription
            $subscription->update(['workspace_package_id' => $newPackage->id]);
        }
    }

    /**
     * Revoke the entitlement package for the subscription.
     */
    protected function revokePackage($subscription, ?string $previousPackageCode = null): void
    {
        $workspacePackage = $subscription->workspacePackage;

        if ($workspacePackage) {
            $workspacePackage->update([
                'status' => WorkspacePackage::STATUS_CANCELLED,
            ]);

            return;
        }

        // Fallback to package code lookup
        $workspace = $subscription->workspace;

        if (! $workspace) {
            return;
        }

        $packageCode = $previousPackageCode ?? ($subscription->metadata['package_code'] ?? null);

        if (! $packageCode) {
            return;
        }

        $package = Package::where('code', $packageCode)->first();

        if (! $package) {
            return;
        }

        // Deactivate the package assignment
        WorkspacePackage::where([
            'workspace_id' => $workspace->id,
            'package_id' => $package->id,
        ])->update([
            'status' => WorkspacePackage::STATUS_CANCELLED,
        ]);
    }

    /**
     * Handle subscription renewed event.
     *
     * Dispatches a job to process the renewal asynchronously.
     */
    public function handleSubscriptionRenewed(SubscriptionRenewed $event): void
    {
        $subscription = $event->subscription;

        if (! $this->isSocialHostProduct($subscription)) {
            return;
        }

        // Dispatch renewal processing job
        ProcessSubscriptionRenewal::dispatch(
            $subscription,
            $subscription->current_period_end
        );

        Log::info('SocialHost subscription renewal queued', [
            'subscription_id' => $subscription->id,
            'workspace_id' => $subscription->workspace_id,
            'new_period_end' => $subscription->current_period_end?->toIso8601String(),
        ]);
    }

    /**
     * Get the events this listener should handle.
     */
    public function subscribe($events): array
    {
        return [
            SubscriptionCreated::class => 'handleSubscriptionCreated',
            SubscriptionUpdated::class => 'handleSubscriptionUpdated',
            SubscriptionCancelled::class => 'handleSubscriptionCancelled',
            SubscriptionRenewed::class => 'handleSubscriptionRenewed',
        ];
    }
}
