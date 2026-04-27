<?php

declare(strict_types=1);

use Core\Mod\Commerce\Services\WebhookRateLimiter;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

// ============================================================================
// WebhookRateLimiter Unit Tests
// ============================================================================

describe('WebhookRateLimiter', function () {
    beforeEach(function () {
        // Clear rate limiter cache before each test
        app(RateLimiter::class)->clear('webhook:stripe:ip:127.0.0.1');
        app(RateLimiter::class)->clear('webhook:btcpay:ip:127.0.0.1');
        app(RateLimiter::class)->clear('webhook:stripe:ip:3.18.12.63');
    });

    describe('basic rate limiting', function () {
        it('allows requests under the limit', function () {
            $limiter = app(WebhookRateLimiter::class);

            $request = Request::create('/webhooks/stripe', 'POST');
            $request->server->set('REMOTE_ADDR', '127.0.0.1');

            // First request should be allowed
            expect($limiter->tooManyAttempts($request, 'stripe'))->toBeFalse();
        });

        it('blocks requests over the limit', function () {
            $limiter = app(WebhookRateLimiter::class);

            $request = Request::create('/webhooks/stripe', 'POST');
            $request->server->set('REMOTE_ADDR', '127.0.0.1');

            // Use a low limit for testing
            config(['commerce.webhooks.rate_limits.default' => 3]);

            // Make requests up to the limit
            for ($i = 0; $i < 3; $i++) {
                $limiter->increment($request, 'stripe');
            }

            // Next request should be blocked
            expect($limiter->tooManyAttempts($request, 'stripe'))->toBeTrue();
        });

        it('tracks attempts per gateway separately', function () {
            $limiter = app(WebhookRateLimiter::class);

            $request = Request::create('/webhooks/test', 'POST');
            $request->server->set('REMOTE_ADDR', '127.0.0.1');

            config(['commerce.webhooks.rate_limits.default' => 3]);

            // Exhaust Stripe limit
            for ($i = 0; $i < 3; $i++) {
                $limiter->increment($request, 'stripe');
            }

            // Stripe should be blocked
            expect($limiter->tooManyAttempts($request, 'stripe'))->toBeTrue();

            // BTCPay should still be allowed (separate counter)
            expect($limiter->tooManyAttempts($request, 'btcpay'))->toBeFalse();
        });

        it('tracks attempts per IP separately', function () {
            $limiter = app(WebhookRateLimiter::class);

            config(['commerce.webhooks.rate_limits.default' => 3]);

            $request1 = Request::create('/webhooks/stripe', 'POST');
            $request1->server->set('REMOTE_ADDR', '192.168.1.1');

            $request2 = Request::create('/webhooks/stripe', 'POST');
            $request2->server->set('REMOTE_ADDR', '192.168.1.2');

            // Exhaust limit for IP 1
            for ($i = 0; $i < 3; $i++) {
                $limiter->increment($request1, 'stripe');
            }

            // IP 1 should be blocked
            expect($limiter->tooManyAttempts($request1, 'stripe'))->toBeTrue();

            // IP 2 should still be allowed
            expect($limiter->tooManyAttempts($request2, 'stripe'))->toBeFalse();
        });

        it('reports remaining attempts correctly', function () {
            $limiter = app(WebhookRateLimiter::class);

            $request = Request::create('/webhooks/stripe', 'POST');
            $request->server->set('REMOTE_ADDR', '127.0.0.1');

            config(['commerce.webhooks.rate_limits.default' => 10]);

            expect($limiter->remainingAttempts($request, 'stripe'))->toBe(10);

            $limiter->increment($request, 'stripe');
            expect($limiter->remainingAttempts($request, 'stripe'))->toBe(9);

            $limiter->increment($request, 'stripe');
            expect($limiter->remainingAttempts($request, 'stripe'))->toBe(8);
        });

        it('returns retry-after time when rate limited', function () {
            $limiter = app(WebhookRateLimiter::class);

            $request = Request::create('/webhooks/stripe', 'POST');
            $request->server->set('REMOTE_ADDR', '127.0.0.1');

            config(['commerce.webhooks.rate_limits.default' => 1]);

            $limiter->increment($request, 'stripe');

            // Should have a retry-after time
            $retryAfter = $limiter->availableIn($request, 'stripe');
            expect($retryAfter)->toBeGreaterThan(0)
                ->and($retryAfter)->toBeLessThanOrEqual(60);
        });
    });

    describe('trusted gateway IPs', function () {
        it('identifies trusted Stripe IPs', function () {
            $limiter = app(WebhookRateLimiter::class);

            // Configure a trusted IP
            config(['commerce.webhooks.trusted_ips.stripe' => ['3.18.12.63']]);

            $trustedRequest = Request::create('/webhooks/stripe', 'POST');
            $trustedRequest->server->set('REMOTE_ADDR', '3.18.12.63');

            $untrustedRequest = Request::create('/webhooks/stripe', 'POST');
            $untrustedRequest->server->set('REMOTE_ADDR', '192.168.1.1');

            expect($limiter->isTrustedGatewayIp($trustedRequest, 'stripe'))->toBeTrue();
            expect($limiter->isTrustedGatewayIp($untrustedRequest, 'stripe'))->toBeFalse();
        });

        it('gives higher limits to trusted IPs', function () {
            $limiter = app(WebhookRateLimiter::class);

            config([
                'commerce.webhooks.rate_limits.default' => 5,
                'commerce.webhooks.rate_limits.trusted' => 100,
                'commerce.webhooks.trusted_ips.stripe' => ['3.18.12.63'],
            ]);

            $trustedRequest = Request::create('/webhooks/stripe', 'POST');
            $trustedRequest->server->set('REMOTE_ADDR', '3.18.12.63');

            $untrustedRequest = Request::create('/webhooks/stripe', 'POST');
            $untrustedRequest->server->set('REMOTE_ADDR', '192.168.1.1');

            // Untrusted IP should have 5 remaining attempts
            expect($limiter->remainingAttempts($untrustedRequest, 'stripe'))->toBe(5);

            // Trusted IP should have 100 remaining attempts
            expect($limiter->remainingAttempts($trustedRequest, 'stripe'))->toBe(100);
        });

        it('supports CIDR ranges for trusted IPs', function () {
            $limiter = app(WebhookRateLimiter::class);

            config(['commerce.webhooks.trusted_ips.stripe' => ['10.0.0.0/24']]);

            $inRangeRequest = Request::create('/webhooks/stripe', 'POST');
            $inRangeRequest->server->set('REMOTE_ADDR', '10.0.0.50');

            $outOfRangeRequest = Request::create('/webhooks/stripe', 'POST');
            $outOfRangeRequest->server->set('REMOTE_ADDR', '10.0.1.50');

            expect($limiter->isTrustedGatewayIp($inRangeRequest, 'stripe'))->toBeTrue();
            expect($limiter->isTrustedGatewayIp($outOfRangeRequest, 'stripe'))->toBeFalse();
        });

        it('supports global trusted IPs', function () {
            $limiter = app(WebhookRateLimiter::class);

            config([
                'commerce.webhooks.trusted_ips.global' => ['10.10.10.10'],
                'commerce.webhooks.trusted_ips.stripe' => [],
                'commerce.webhooks.trusted_ips.btcpay' => [],
            ]);

            $request = Request::create('/webhooks/test', 'POST');
            $request->server->set('REMOTE_ADDR', '10.10.10.10');

            // Global IP should be trusted for both gateways
            expect($limiter->isTrustedGatewayIp($request, 'stripe'))->toBeTrue();
            expect($limiter->isTrustedGatewayIp($request, 'btcpay'))->toBeTrue();
        });
    });

    describe('gateway-specific configuration', function () {
        it('uses gateway-specific rate limits when configured', function () {
            $limiter = app(WebhookRateLimiter::class);

            config([
                'commerce.webhooks.rate_limits.default' => 60,
                'commerce.webhooks.rate_limits.stripe' => [
                    'default' => 30,
                    'trusted' => 150,
                ],
                'commerce.webhooks.rate_limits.btcpay' => [
                    'default' => 40,
                    'trusted' => 200,
                ],
            ]);

            $stripeRequest = Request::create('/webhooks/stripe', 'POST');
            $stripeRequest->server->set('REMOTE_ADDR', '127.0.0.1');

            $btcpayRequest = Request::create('/webhooks/btcpay', 'POST');
            $btcpayRequest->server->set('REMOTE_ADDR', '127.0.0.2');

            // Stripe should have 30 limit
            expect($limiter->remainingAttempts($stripeRequest, 'stripe'))->toBe(30);

            // BTCPay should have 40 limit
            expect($limiter->remainingAttempts($btcpayRequest, 'btcpay'))->toBe(40);
        });
    });
});

// ============================================================================
// Webhook Controller Rate Limiting Integration Tests
// ============================================================================

describe('Webhook Controller Rate Limiting', function () {
    beforeEach(function () {
        // Clear rate limiters
        app(RateLimiter::class)->clear('webhook:stripe:ip:127.0.0.1');
        app(RateLimiter::class)->clear('webhook:btcpay:ip:127.0.0.1');
    });

    it('returns 429 when Stripe webhook rate limit exceeded', function () {
        config(['commerce.webhooks.rate_limits.default' => 2]);

        // Make requests until rate limited
        for ($i = 0; $i < 2; $i++) {
            $this->postJson(route('api.webhook.stripe'), [], [
                'Stripe-Signature' => 'invalid',
            ]);
        }

        // Next request should be rate limited
        $response = $this->postJson(route('api.webhook.stripe'), [], [
            'Stripe-Signature' => 'invalid',
        ]);

        $response->assertStatus(429);
        $response->assertHeader('Retry-After');
        $response->assertHeader('X-RateLimit-Remaining', '0');
    });

    it('returns 429 when BTCPay webhook rate limit exceeded', function () {
        config(['commerce.webhooks.rate_limits.default' => 2]);

        // Make requests until rate limited
        for ($i = 0; $i < 2; $i++) {
            $this->postJson(route('api.webhook.btcpay'), [], [
                'BTCPay-Sig' => 'invalid',
            ]);
        }

        // Next request should be rate limited
        $response = $this->postJson(route('api.webhook.btcpay'), [], [
            'BTCPay-Sig' => 'invalid',
        ]);

        $response->assertStatus(429);
        $response->assertHeader('Retry-After');
    });

    it('allows requests from trusted IPs with higher limits', function () {
        config([
            'commerce.webhooks.rate_limits.default' => 2,
            'commerce.webhooks.rate_limits.trusted' => 100,
            'commerce.webhooks.trusted_ips.stripe' => ['127.0.0.1'],
        ]);

        // Trusted IP should be able to make many requests
        for ($i = 0; $i < 10; $i++) {
            $response = $this->postJson(route('api.webhook.stripe'), [], [
                'Stripe-Signature' => 'invalid',
            ]);

            // Should get 401 (invalid signature) not 429 (rate limited)
            $response->assertStatus(401);
        }
    });
});
