<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Listeners;

use Core\Mod\Commerce\Events\SubscriptionCreated;
use Core\Tenant\Models\AgentReferralBonus;
use Illuminate\Support\Facades\Log;
use Mod\Trees\Models\TreePlanting;

/**
 * Rewards agents when their referred user subscribes.
 *
 * Part of the Trees for Agents conversion bonus system:
 * 1. If the referred user had a queued tree, it plants immediately
 * 2. The agent gets a guaranteed next referral (skips queue)
 *
 * This creates a virtuous cycle: agents who refer real users that convert
 * get rewarded with immediate trees and guaranteed future referrals.
 */
class RewardAgentReferralOnSubscription
{
    /**
     * Handle the subscription created event.
     */
    public function handle(SubscriptionCreated $event): void
    {
        $subscription = $event->subscription;
        $workspace = $subscription->workspace;

        if (! $workspace) {
            return;
        }

        // Get all user IDs in this workspace
        $userIds = $workspace->users()->pluck('users.id')->toArray();

        if (empty($userIds)) {
            return;
        }

        // Find agent referral tree plantings for these users
        $agentReferrals = TreePlanting::forAgent()
            ->whereIn('user_id', $userIds)
            ->whereIn('status', [TreePlanting::STATUS_QUEUED, TreePlanting::STATUS_PENDING])
            ->get();

        if ($agentReferrals->isEmpty()) {
            return; // No agent referrals to reward
        }

        foreach ($agentReferrals as $planting) {
            $this->processConversion($planting);
        }
    }

    /**
     * Process a conversion for a single tree planting.
     */
    protected function processConversion(TreePlanting $planting): void
    {
        $wasQueued = $planting->isQueued();

        // If the tree was queued, plant it immediately
        if ($wasQueued) {
            $planting->update(['status' => TreePlanting::STATUS_PENDING]);
            $planting->markConfirmed();

            Log::info('Queued tree planted immediately on conversion', [
                'tree_planting_id' => $planting->id,
                'provider' => $planting->provider,
                'model' => $planting->model,
                'user_id' => $planting->user_id,
            ]);
        }

        // Grant the agent a guaranteed next referral
        $bonus = AgentReferralBonus::grantGuaranteedReferral(
            $planting->provider ?? 'unknown',
            $planting->model
        );

        Log::info('Agent referral bonus granted on conversion', [
            'provider' => $planting->provider,
            'model' => $planting->model,
            'total_conversions' => $bonus->total_conversions,
            'next_referral_guaranteed' => $bonus->next_referral_guaranteed,
        ]);
    }
}
