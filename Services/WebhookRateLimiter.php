<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Services;

use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;

/**
 * Rate limiter for webhook endpoints.
 *
 * Provides IP-based rate limiting to prevent abuse of webhook endpoints.
 * Supports different limits for known gateway IPs vs unknown sources.
 *
 * Security considerations:
 * - Webhook endpoints are public (no auth) but use signature verification
 * - A malicious actor could exhaust rate limits, blocking legitimate webhooks
 * - Trusted gateway IPs get higher limits to prevent this attack vector
 * - Per-IP limiting ensures one abuser doesn't affect others
 */
class WebhookRateLimiter
{
    /**
     * Default maximum webhook requests per IP per minute.
     */
    private const DEFAULT_MAX_ATTEMPTS = 60;

    /**
     * Maximum requests for trusted gateway IPs per minute.
     * Higher limit since these are legitimate payment processor requests.
     */
    private const TRUSTED_MAX_ATTEMPTS = 300;

    /**
     * Window duration in seconds (1 minute).
     */
    private const DECAY_SECONDS = 60;

    public function __construct(
        protected readonly RateLimiter $limiter
    ) {}

    /**
     * Check if the IP has exceeded webhook rate limits.
     */
    public function tooManyAttempts(Request $request, string $gateway): bool
    {
        $key = $this->throttleKey($request, $gateway);
        $maxAttempts = $this->getMaxAttempts($request, $gateway);

        return $this->limiter->tooManyAttempts($key, $maxAttempts);
    }

    /**
     * Increment the webhook attempt counter.
     */
    public function increment(Request $request, string $gateway): void
    {
        $key = $this->throttleKey($request, $gateway);

        $this->limiter->hit($key, self::DECAY_SECONDS);
    }

    /**
     * Get the number of attempts made.
     */
    public function attempts(Request $request, string $gateway): int
    {
        return $this->limiter->attempts($this->throttleKey($request, $gateway));
    }

    /**
     * Get seconds until rate limit resets.
     */
    public function availableIn(Request $request, string $gateway): int
    {
        return $this->limiter->availableIn($this->throttleKey($request, $gateway));
    }

    /**
     * Get remaining attempts before rate limit is hit.
     */
    public function remainingAttempts(Request $request, string $gateway): int
    {
        $maxAttempts = $this->getMaxAttempts($request, $gateway);
        $attempts = $this->attempts($request, $gateway);

        return max(0, $maxAttempts - $attempts);
    }

    /**
     * Check if the request IP is from a trusted gateway.
     */
    public function isTrustedGatewayIp(Request $request, string $gateway): bool
    {
        $ip = $request->ip();
        if (! $ip) {
            return false;
        }

        $trustedIps = $this->getTrustedIps($gateway);

        foreach ($trustedIps as $trustedIp) {
            if ($this->ipMatches($ip, $trustedIp)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get max attempts based on whether IP is trusted.
     */
    protected function getMaxAttempts(Request $request, string $gateway): int
    {
        // Check for gateway-specific config first
        $configKey = "commerce.webhooks.rate_limits.{$gateway}";
        $gatewayConfig = config($configKey);

        if ($gatewayConfig) {
            if ($this->isTrustedGatewayIp($request, $gateway)) {
                return (int) ($gatewayConfig['trusted'] ?? self::TRUSTED_MAX_ATTEMPTS);
            }

            return (int) ($gatewayConfig['default'] ?? self::DEFAULT_MAX_ATTEMPTS);
        }

        // Fall back to global webhook config
        if ($this->isTrustedGatewayIp($request, $gateway)) {
            return (int) config('commerce.webhooks.rate_limits.trusted', self::TRUSTED_MAX_ATTEMPTS);
        }

        return (int) config('commerce.webhooks.rate_limits.default', self::DEFAULT_MAX_ATTEMPTS);
    }

    /**
     * Get trusted IPs for a gateway.
     *
     * Returns IP addresses or CIDR ranges that are known to belong to
     * the payment gateway.
     *
     * @return array<string>
     */
    protected function getTrustedIps(string $gateway): array
    {
        // Check gateway-specific trusted IPs first
        $gatewayIps = config("commerce.webhooks.trusted_ips.{$gateway}", []);

        // Merge with global trusted IPs
        $globalIps = config('commerce.webhooks.trusted_ips.global', []);

        return array_merge($gatewayIps, $globalIps);
    }

    /**
     * Check if an IP matches a trusted IP or CIDR range.
     */
    protected function ipMatches(string $ip, string $trustedIp): bool
    {
        // Direct match
        if ($ip === $trustedIp) {
            return true;
        }

        // CIDR range match
        if (str_contains($trustedIp, '/')) {
            return $this->ipInCidr($ip, $trustedIp);
        }

        return false;
    }

    /**
     * Check if IP is within a CIDR range.
     */
    protected function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $mask] = explode('/', $cidr, 2);
        $mask = (int) $mask;

        // Handle IPv4
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);

            if ($ipLong === false || $subnetLong === false) {
                return false;
            }

            $maskLong = -1 << (32 - $mask);

            return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
        }

        // Handle IPv6
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $ipBinary = inet_pton($ip);
            $subnetBinary = inet_pton($subnet);

            if ($ipBinary === false || $subnetBinary === false) {
                return false;
            }

            // Build a bitmask from the prefix length
            $maskBinary = str_repeat("\xff", (int) ($mask / 8));
            if ($mask % 8) {
                $maskBinary .= chr(0xFF << (8 - ($mask % 8)));
            }
            $maskBinary = str_pad($maskBinary, 16, "\x00");

            return ($ipBinary & $maskBinary) === ($subnetBinary & $maskBinary);
        }

        return false;
    }

    /**
     * Generate throttle key from gateway and IP.
     *
     * Each gateway has separate rate limits per IP to prevent
     * cross-gateway interference.
     */
    protected function throttleKey(Request $request, string $gateway): string
    {
        $ip = $request->ip() ?? 'unknown';

        return "webhook:{$gateway}:ip:{$ip}";
    }
}
