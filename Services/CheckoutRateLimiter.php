<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Services;

use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;

/**
 * Rate limiter for checkout and coupon validation attempts.
 *
 * Prevents abuse by limiting checkout creation and coupon validation
 * per customer/IP combination. Uses a sliding window approach.
 */
class CheckoutRateLimiter
{
    /**
     * Maximum checkout attempts per window.
     */
    private const MAX_ATTEMPTS = 5;

    /**
     * Window duration in seconds (15 minutes).
     */
    private const DECAY_SECONDS = 900;

    /**
     * Maximum coupon validation attempts per window.
     *
     * More aggressive than checkout to prevent brute-forcing codes.
     */
    private const MAX_COUPON_ATTEMPTS = 10;

    /**
     * Coupon window duration in seconds (5 minutes).
     */
    private const COUPON_DECAY_SECONDS = 300;

    public function __construct(
        protected readonly RateLimiter $limiter
    ) {
    }

    /**
     * Check if the customer/IP has exceeded checkout rate limits.
     */
    public function tooManyAttempts(?int $workspaceId, ?int $userId, Request $request): bool
    {
        $key = $this->throttleKey($workspaceId, $userId, $request);

        return $this->limiter->tooManyAttempts($key, self::MAX_ATTEMPTS);
    }

    /**
     * Increment the checkout attempt counter.
     */
    public function increment(?int $workspaceId, ?int $userId, Request $request): void
    {
        $key = $this->throttleKey($workspaceId, $userId, $request);

        $this->limiter->hit($key, self::DECAY_SECONDS);
    }

    /**
     * Get the number of attempts made.
     */
    public function attempts(?int $workspaceId, ?int $userId, Request $request): int
    {
        return $this->limiter->attempts($this->throttleKey($workspaceId, $userId, $request));
    }

    /**
     * Get seconds until rate limit resets.
     */
    public function availableIn(?int $workspaceId, ?int $userId, Request $request): int
    {
        return $this->limiter->availableIn($this->throttleKey($workspaceId, $userId, $request));
    }

    /**
     * Clear rate limit (e.g., after successful checkout).
     */
    public function clear(?int $workspaceId, ?int $userId, Request $request): void
    {
        $this->limiter->clear($this->throttleKey($workspaceId, $userId, $request));
    }

    /**
     * Check if customer/IP has exceeded coupon validation rate limits.
     */
    public function tooManyCouponAttempts(?int $workspaceId, ?int $userId, Request $request): bool
    {
        $key = $this->couponThrottleKey($workspaceId, $userId, $request);

        return $this->limiter->tooManyAttempts($key, self::MAX_COUPON_ATTEMPTS);
    }

    /**
     * Increment the coupon validation attempt counter.
     */
    public function incrementCoupon(?int $workspaceId, ?int $userId, Request $request): void
    {
        $key = $this->couponThrottleKey($workspaceId, $userId, $request);

        $this->limiter->hit($key, self::COUPON_DECAY_SECONDS);
    }

    /**
     * Get seconds until coupon rate limit resets.
     */
    public function couponAvailableIn(?int $workspaceId, ?int $userId, Request $request): int
    {
        return $this->limiter->availableIn($this->couponThrottleKey($workspaceId, $userId, $request));
    }

    /**
     * Generate throttle key for coupon validation.
     */
    protected function couponThrottleKey(?int $workspaceId, ?int $userId, Request $request): string
    {
        if ($workspaceId) {
            return "coupon:workspace:{$workspaceId}";
        }

        if ($userId) {
            return "coupon:user:{$userId}";
        }

        $ip = $request->ip() ?? 'unknown';

        return "coupon:ip:{$ip}";
    }

    /**
     * Generate throttle key from workspace/user/IP.
     *
     * Rate limiting hierarchy:
     * - Authenticated user with workspace: workspace_id
     * - Authenticated user without workspace: user_id
     * - Guest: IP address
     */
    protected function throttleKey(?int $workspaceId, ?int $userId, Request $request): string
    {
        if ($workspaceId) {
            return "checkout:workspace:{$workspaceId}";
        }

        if ($userId) {
            return "checkout:user:{$userId}";
        }

        $ip = $request->ip() ?? 'unknown';

        return "checkout:ip:{$ip}";
    }
}
