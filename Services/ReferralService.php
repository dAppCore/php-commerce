<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Services;

use Core\Mod\Commerce\Models\Order;
use Core\Mod\Commerce\Models\Referral;
use Core\Mod\Commerce\Models\ReferralCode;
use Core\Mod\Commerce\Models\ReferralCommission;
use Core\Mod\Commerce\Models\ReferralPayout;
use Core\Tenant\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mod\Bio\Models\Page;

/**
 * Service for managing referrals and affiliate commissions.
 *
 * Handles:
 * - Referral tracking and attribution
 * - Commission calculation and maturation
 * - Payout processing
 * - Referral code management
 */
class ReferralService
{
    /**
     * Track a referral click from session data.
     */
    public function trackClick(
        string $code,
        ?string $sourceUrl = null,
        ?string $landingPage = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        array $utmParams = []
    ): ?Referral {
        // Find the referrer by code (namespace or custom code)
        $referrerId = $this->resolveReferrerFromCode($code);

        if (! $referrerId) {
            return null;
        }

        // Generate unique tracking ID
        $trackingId = Str::uuid()->toString();

        return Referral::create([
            'referrer_id' => $referrerId,
            'code' => $code,
            'status' => Referral::STATUS_PENDING,
            'source_url' => $sourceUrl,
            'landing_page' => $landingPage,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent ? Str::limit($userAgent, 512) : null,
            'utm_source' => $utmParams['source'] ?? null,
            'utm_medium' => $utmParams['medium'] ?? null,
            'utm_campaign' => $utmParams['campaign'] ?? null,
            'tracking_id' => $trackingId,
            'clicked_at' => now(),
        ]);
    }

    /**
     * Resolve referrer user ID from a code.
     *
     * Checks:
     * 1. Custom referral codes
     * 2. User namespaces (bio page URLs)
     */
    public function resolveReferrerFromCode(string $code): ?int
    {
        // Check custom referral codes first
        $referralCode = ReferralCode::valid()->byCode($code)->first();
        if ($referralCode && $referralCode->user_id) {
            return $referralCode->user_id;
        }

        // Check user namespaces (bio pages)
        $page = Page::with('user')
            ->where('url', $code)
            ->first();

        if ($page && $page->user && $page->user->hasActivatedReferrals()) {
            return $page->user_id;
        }

        return null;
    }

    /**
     * Convert a referral when user signs up.
     */
    public function convertReferral(User $referee, ?string $trackingId = null, ?int $referrerUserId = null): ?Referral
    {
        // Find pending referral by tracking ID or referrer
        $referral = null;

        if ($trackingId) {
            $referral = Referral::pending()
                ->where('tracking_id', $trackingId)
                ->whereNull('referee_id')
                ->first();
        }

        if (! $referral && $referrerUserId) {
            // Create new referral if we have referrer ID from session
            $referral = Referral::create([
                'referrer_id' => $referrerUserId,
                'code' => '', // Will be filled from session context
                'status' => Referral::STATUS_PENDING,
                'clicked_at' => now(),
            ]);
        }

        if (! $referral) {
            return null;
        }

        // Prevent self-referral
        if ($referral->referrer_id === $referee->id) {
            Log::info('Self-referral prevented', [
                'user_id' => $referee->id,
                'referral_id' => $referral->id,
            ]);

            return null;
        }

        // Mark as converted
        $referral->markConverted($referee);

        // Update referrer's referral count
        $referrer = $referral->referrer;
        if ($referrer) {
            $referrer->increment('referral_count');
        }

        // Increment code usage if applicable
        $referralCode = ReferralCode::byCode($referral->code)->first();
        if ($referralCode) {
            $referralCode->incrementUsage();
        }

        Log::info('Referral converted', [
            'referral_id' => $referral->id,
            'referrer_id' => $referral->referrer_id,
            'referee_id' => $referee->id,
        ]);

        return $referral;
    }

    /**
     * Get or create referral for a referee user.
     */
    public function getReferralForUser(User $referee): ?Referral
    {
        return Referral::active()
            ->forReferee($referee->id)
            ->first();
    }

    /**
     * Calculate and create commission for an order.
     */
    public function createCommissionForOrder(Order $order): ?ReferralCommission
    {
        // Find the referee (user who made the purchase)
        $referee = $order->user;
        if (! $referee) {
            return null;
        }

        // Find active referral for this user
        $referral = Referral::active()
            ->forReferee($referee->id)
            ->first();

        if (! $referral) {
            return null;
        }

        // Check if commission already exists for this order
        $existingCommission = ReferralCommission::where('order_id', $order->id)->first();
        if ($existingCommission) {
            return $existingCommission;
        }

        // Get commission rate from referral code or default
        $commissionRate = $this->getCommissionRateForReferral($referral);

        // Create commission
        $commissionData = ReferralCommission::calculateForOrder($referral, $order, $commissionRate);
        $commission = ReferralCommission::create($commissionData);

        // Mark referral as qualified if first purchase
        if (! $referral->isQualified()) {
            $referral->markQualified();
        }

        Log::info('Referral commission created', [
            'commission_id' => $commission->id,
            'referral_id' => $referral->id,
            'order_id' => $order->id,
            'amount' => $commission->commission_amount,
        ]);

        return $commission;
    }

    /**
     * Get commission rate for a referral.
     */
    public function getCommissionRateForReferral(Referral $referral): float
    {
        // Check if referral code has custom rate
        $referralCode = ReferralCode::valid()->byCode($referral->code)->first();

        if ($referralCode && $referralCode->commission_rate !== null) {
            return $referralCode->commission_rate;
        }

        return ReferralCommission::DEFAULT_COMMISSION_RATE;
    }

    /**
     * Mature commissions that are ready.
     */
    public function matureReadyCommissions(): int
    {
        $commissions = ReferralCommission::readyToMature()->get();
        $count = 0;

        foreach ($commissions as $commission) {
            $commission->markMatured();
            $count++;

            // Also mature the referral if this is the first matured commission
            $referral = $commission->referral;
            if ($referral && ! $referral->hasMatured()) {
                $referral->markMatured();
            }
        }

        if ($count > 0) {
            Log::info('Matured referral commissions', ['count' => $count]);
        }

        return $count;
    }

    /**
     * Cancel commission for a refunded/chargedback order.
     */
    public function cancelCommissionForOrder(Order $order, string $reason = 'Order refunded'): void
    {
        $commission = ReferralCommission::where('order_id', $order->id)->first();

        if ($commission && ! $commission->isPaid()) {
            $commission->cancel($reason);

            Log::info('Referral commission cancelled', [
                'commission_id' => $commission->id,
                'order_id' => $order->id,
                'reason' => $reason,
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Payout Management
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get user's available balance (matured, unpaid commissions).
     */
    public function getAvailableBalance(User $user): float
    {
        return (float) ReferralCommission::forReferrer($user->id)
            ->matured()
            ->unpaid()
            ->sum('commission_amount');
    }

    /**
     * Get user's pending balance (not yet matured).
     */
    public function getPendingBalance(User $user): float
    {
        return (float) ReferralCommission::forReferrer($user->id)
            ->pending()
            ->sum('commission_amount');
    }

    /**
     * Get user's total lifetime earnings.
     */
    public function getLifetimeEarnings(User $user): float
    {
        return (float) ReferralCommission::forReferrer($user->id)
            ->whereIn('status', [
                ReferralCommission::STATUS_MATURED,
                ReferralCommission::STATUS_PAID,
            ])
            ->sum('commission_amount');
    }

    /**
     * Get user's total paid out amount.
     */
    public function getTotalPaidOut(User $user): float
    {
        return (float) ReferralPayout::forUser($user->id)
            ->completed()
            ->sum('amount');
    }

    /**
     * Request a payout.
     */
    public function requestPayout(
        User $user,
        string $method,
        ?float $amount = null,
        ?string $btcAddress = null
    ): ReferralPayout {
        return DB::transaction(function () use ($user, $method, $amount, $btcAddress) {
            // Get available balance
            $availableBalance = $this->getAvailableBalance($user);

            // Default to full balance
            $amount = $amount ?? $availableBalance;

            // Validate amount
            $minimumPayout = ReferralPayout::getMinimumPayout($method);
            if ($amount < $minimumPayout) {
                throw new \InvalidArgumentException(
                    "Minimum payout amount is GBP {$minimumPayout} for {$method}"
                );
            }

            if ($amount > $availableBalance) {
                throw new \InvalidArgumentException(
                    "Requested amount exceeds available balance of GBP {$availableBalance}"
                );
            }

            // Validate BTC address if needed
            if ($method === ReferralPayout::METHOD_BTC && ! $btcAddress) {
                throw new \InvalidArgumentException('BTC address is required for Bitcoin payouts');
            }

            // Create payout
            $payout = ReferralPayout::create([
                'user_id' => $user->id,
                'payout_number' => ReferralPayout::generatePayoutNumber(),
                'method' => $method,
                'btc_address' => $btcAddress,
                'amount' => $amount,
                'currency' => 'GBP',
                'status' => ReferralPayout::STATUS_REQUESTED,
                'requested_at' => now(),
            ]);

            // Assign matured commissions to this payout up to amount
            $commissionsToAssign = ReferralCommission::forReferrer($user->id)
                ->matured()
                ->unpaid()
                ->orderBy('matured_at')
                ->get();

            $assignedAmount = 0;
            foreach ($commissionsToAssign as $commission) {
                if ($assignedAmount >= $amount) {
                    break;
                }

                $commission->update(['payout_id' => $payout->id]);
                $assignedAmount += $commission->commission_amount;
            }

            Log::info('Payout requested', [
                'payout_id' => $payout->id,
                'user_id' => $user->id,
                'method' => $method,
                'amount' => $amount,
            ]);

            return $payout;
        });
    }

    /**
     * Process a payout (admin action).
     */
    public function processPayout(ReferralPayout $payout, User $admin): void
    {
        if (! $payout->isRequested()) {
            throw new \InvalidArgumentException('Payout is not in requested status');
        }

        $payout->markProcessing($admin);

        Log::info('Payout processing started', [
            'payout_id' => $payout->id,
            'admin_id' => $admin->id,
        ]);
    }

    /**
     * Complete a payout (admin action).
     */
    public function completePayout(
        ReferralPayout $payout,
        ?string $btcTxid = null,
        ?float $btcAmount = null,
        ?float $btcRate = null
    ): void {
        if (! $payout->isProcessing()) {
            throw new \InvalidArgumentException('Payout is not in processing status');
        }

        $payout->markCompleted($btcTxid, $btcAmount, $btcRate);

        Log::info('Payout completed', [
            'payout_id' => $payout->id,
            'btc_txid' => $btcTxid,
        ]);
    }

    /**
     * Fail a payout (admin action).
     */
    public function failPayout(ReferralPayout $payout, string $reason): void
    {
        $payout->markFailed($reason);

        Log::info('Payout failed', [
            'payout_id' => $payout->id,
            'reason' => $reason,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Referral Code Management
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Create a custom referral code.
     */
    public function createCode(array $data): ReferralCode
    {
        return ReferralCode::create(array_merge([
            'type' => ReferralCode::TYPE_CUSTOM,
            'cookie_days' => ReferralCode::DEFAULT_COOKIE_DAYS,
            'is_active' => true,
        ], $data));
    }

    /**
     * Create a campaign referral code.
     */
    public function createCampaignCode(
        string $code,
        string $campaignName,
        ?int $userId = null,
        ?float $commissionRate = null,
        array $metadata = []
    ): ReferralCode {
        return ReferralCode::create([
            'code' => strtoupper($code),
            'user_id' => $userId,
            'type' => ReferralCode::TYPE_CAMPAIGN,
            'commission_rate' => $commissionRate,
            'cookie_days' => ReferralCode::DEFAULT_COOKIE_DAYS,
            'is_active' => true,
            'campaign_name' => $campaignName,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Find a referral code by code string.
     */
    public function findCode(string $code): ?ReferralCode
    {
        return ReferralCode::byCode($code)->first();
    }

    /**
     * Validate a referral code.
     */
    public function validateCode(string $code): bool
    {
        // Check custom codes
        $referralCode = ReferralCode::valid()->byCode($code)->first();
        if ($referralCode) {
            return true;
        }

        // Check user namespaces
        $referrerId = $this->resolveReferrerFromCode($code);

        return $referrerId !== null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Statistics
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Get referral statistics for a user.
     */
    public function getStatsForUser(User $user): array
    {
        $referrals = Referral::forReferrer($user->id);

        return [
            'total_referrals' => $referrals->count(),
            'pending_referrals' => $referrals->clone()->pending()->count(),
            'converted_referrals' => $referrals->clone()->converted()->count(),
            'qualified_referrals' => $referrals->clone()->qualified()->count(),
            'available_balance' => $this->getAvailableBalance($user),
            'pending_balance' => $this->getPendingBalance($user),
            'lifetime_earnings' => $this->getLifetimeEarnings($user),
            'total_paid_out' => $this->getTotalPaidOut($user),
        ];
    }

    /**
     * Get global referral statistics (admin).
     */
    public function getGlobalStats(): array
    {
        return [
            'total_referrals' => Referral::count(),
            'active_referrals' => Referral::active()->count(),
            'qualified_referrals' => Referral::qualified()->count(),
            'total_commissions' => ReferralCommission::sum('commission_amount'),
            'pending_commissions' => ReferralCommission::pending()->sum('commission_amount'),
            'matured_commissions' => ReferralCommission::matured()->sum('commission_amount'),
            'paid_commissions' => ReferralCommission::paid()->sum('commission_amount'),
            'pending_payouts' => ReferralPayout::pending()->sum('amount'),
            'completed_payouts' => ReferralPayout::completed()->sum('amount'),
        ];
    }

    /**
     * Disqualify a referral (admin action).
     */
    public function disqualifyReferral(Referral $referral, string $reason): void
    {
        DB::transaction(function () use ($referral, $reason) {
            // Disqualify the referral
            $referral->disqualify($reason);

            // Cancel any unpaid commissions
            $referral->commissions()
                ->whereIn('status', [
                    ReferralCommission::STATUS_PENDING,
                    ReferralCommission::STATUS_MATURED,
                ])
                ->each(fn ($c) => $c->cancel('Referral disqualified: '.$reason));

            // Decrement referrer's referral count if they have one
            $referrer = $referral->referrer;
            if ($referrer && $referrer->referral_count > 0) {
                $referrer->decrement('referral_count');
            }
        });

        Log::info('Referral disqualified', [
            'referral_id' => $referral->id,
            'reason' => $reason,
        ]);
    }
}
