<?php

declare(strict_types=1);

use Carbon\Carbon;
use Core\Mod\Commerce\Contracts\PaymentGatewayContract;
use Core\Mod\Commerce\DTOs\CouponValidationResult;
use Core\Mod\Commerce\DTOs\ProrationResult;
use Core\Mod\Commerce\Services\BTCPayGateway;
use Core\Mod\Commerce\Services\StripeGateway;

it('exposes readonly RFC DTOs', function (): void {
    $coupon = new CouponValidationResult(
        valid: true,
        reason: null,
        discountAmount: 5.0,
        discountType: 'fixed',
    );

    $proration = new ProrationResult(
        creditAmount: 10.0,
        chargeAmount: 25.0,
        effectiveDate: Carbon::parse('2026-04-25 12:00:00'),
    );

    expect($coupon->toArray())->toMatchArray([
        'valid' => true,
        'discount_amount' => 5.0,
        'discount_type' => 'fixed',
    ])->and($proration->netAmount())->toBe(15.0);
});

it('provides Stripe and BTCPay RFC gateway implementations', function (): void {
    expect(is_subclass_of(StripeGateway::class, PaymentGatewayContract::class))->toBeTrue()
        ->and(is_subclass_of(BTCPayGateway::class, PaymentGatewayContract::class))->toBeTrue();
});
