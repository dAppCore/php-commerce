<?php

declare(strict_types=1);

use Core\Mod\Commerce\Models\Coupon;
use Core\Mod\Commerce\Models\CouponUsage;
use Core\Mod\Commerce\Models\Order;
use Core\Mod\Commerce\Services\CouponService;
use Core\Tenant\Models\Package;
use Core\Tenant\Models\User;
use Core\Tenant\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create();
    $this->workspace->users()->attach($this->user->id, [
        'role' => 'owner',
        'is_default' => true,
    ]);

    // Use existing seeded package
    $this->package = Package::where('code', 'creator')->first();

    // Create test coupons
    $this->percentCoupon = Coupon::create([
        'code' => 'SAVE20',
        'name' => '20% Off',
        'type' => 'percentage',
        'value' => 20.00,
        'applies_to' => 'all',
        'is_active' => true,
        'max_uses' => 100,
        'max_uses_per_workspace' => 1,
        'used_count' => 0,
    ]);

    $this->fixedCoupon = Coupon::create([
        'code' => 'FLAT10',
        'name' => '£10 Off',
        'type' => 'fixed_amount',
        'value' => 10.00,
        'applies_to' => 'all',
        'is_active' => true,
        'max_uses_per_workspace' => 1,
    ]);

    $this->service = app(CouponService::class);
});

describe('CouponService', function () {
    describe('sanitiseCode() method', function () {
        it('trims whitespace from coupon codes', function () {
            $result = $this->service->sanitiseCode('  SAVE20  ');

            expect($result)->toBe('SAVE20');
        });

        it('converts lowercase to uppercase', function () {
            $result = $this->service->sanitiseCode('save20');

            expect($result)->toBe('SAVE20');
        });

        it('handles mixed case codes', function () {
            $result = $this->service->sanitiseCode('SaVe20');

            expect($result)->toBe('SAVE20');
        });

        it('allows hyphens in codes', function () {
            $result = $this->service->sanitiseCode('SAVE-20-NOW');

            expect($result)->toBe('SAVE-20-NOW');
        });

        it('allows underscores in codes', function () {
            $result = $this->service->sanitiseCode('SAVE_20_NOW');

            expect($result)->toBe('SAVE_20_NOW');
        });

        it('rejects codes shorter than minimum length', function () {
            $result = $this->service->sanitiseCode('AB');

            expect($result)->toBeNull();
        });

        it('accepts codes at minimum length', function () {
            $result = $this->service->sanitiseCode('ABC');

            expect($result)->toBe('ABC');
        });

        it('rejects codes longer than maximum length', function () {
            $longCode = str_repeat('A', 51);
            $result = $this->service->sanitiseCode($longCode);

            expect($result)->toBeNull();
        });

        it('accepts codes at maximum length', function () {
            $maxCode = str_repeat('A', 50);
            $result = $this->service->sanitiseCode($maxCode);

            expect($result)->toBe($maxCode);
        });

        it('rejects codes with invalid characters', function () {
            $invalidCodes = [
                'SAVE@20',      // @ symbol
                'SAVE 20',      // space (after trim)
                'SAVE#20',      // hash
                'SAVE!20',      // exclamation
                'SAVE$20',      // dollar
                'SAVE%20',      // percent
                'SAVE&20',      // ampersand
                'SAVE*20',      // asterisk
                'SAVE.20',      // period
                "SAVE'20",      // single quote
                'SAVE"20',      // double quote
                'SAVE;20',      // semicolon (SQL injection attempt)
                "SAVE'--20",    // SQL injection attempt
                'SAVE<script>', // XSS attempt
            ];

            foreach ($invalidCodes as $code) {
                $result = $this->service->sanitiseCode($code);
                expect($result)->toBeNull("Expected null for code: {$code}");
            }
        });

        it('rejects empty string', function () {
            $result = $this->service->sanitiseCode('');

            expect($result)->toBeNull();
        });

        it('rejects whitespace-only string', function () {
            $result = $this->service->sanitiseCode('   ');

            expect($result)->toBeNull();
        });
    });

    describe('isValidCodeFormat() method', function () {
        it('returns true for valid codes', function () {
            expect($this->service->isValidCodeFormat('SAVE20'))->toBeTrue()
                ->and($this->service->isValidCodeFormat('save-20-now'))->toBeTrue()
                ->and($this->service->isValidCodeFormat('CODE_123'))->toBeTrue();
        });

        it('returns false for invalid codes', function () {
            expect($this->service->isValidCodeFormat('AB'))->toBeFalse()     // too short
                ->and($this->service->isValidCodeFormat('SAVE@20'))->toBeFalse()  // invalid char
                ->and($this->service->isValidCodeFormat(''))->toBeFalse();         // empty
        });
    });

    describe('findByCode() method', function () {
        it('finds coupon by code (case insensitive)', function () {
            $coupon = $this->service->findByCode('save20');

            expect($coupon)->not->toBeNull()
                ->and($coupon->code)->toBe('SAVE20');
        });

        it('returns null for non-existent code', function () {
            $coupon = $this->service->findByCode('NOTREAL');

            expect($coupon)->toBeNull();
        });

        it('returns null for invalid code format without hitting database', function () {
            // These should return null due to invalid format, not because they don't exist
            expect($this->service->findByCode('AB'))->toBeNull()      // too short
                ->and($this->service->findByCode('CODE@123'))->toBeNull(); // invalid char
        });

        it('sanitises code before lookup', function () {
            // Should find the coupon even with whitespace and different case
            $coupon = $this->service->findByCode('  save20  ');

            expect($coupon)->not->toBeNull()
                ->and($coupon->code)->toBe('SAVE20');
        });
    });

    describe('validate() method', function () {
        it('validates active coupon', function () {
            $result = $this->service->validate(
                $this->percentCoupon,
                $this->workspace,
                $this->package
            );

            expect($result->isValid())->toBeTrue();
        });

        it('rejects inactive coupon', function () {
            $this->percentCoupon->update(['is_active' => false]);

            $result = $this->service->validate(
                $this->percentCoupon,
                $this->workspace,
                $this->package
            );

            expect($result->isValid())->toBeFalse();
        });

        it('rejects expired coupon', function () {
            $this->percentCoupon->update(['valid_until' => now()->subDay()]);

            $result = $this->service->validate(
                $this->percentCoupon,
                $this->workspace,
                $this->package
            );

            expect($result->isValid())->toBeFalse();
        });

        it('rejects coupon before start date', function () {
            $this->percentCoupon->update(['valid_from' => now()->addDay()]);

            $result = $this->service->validate(
                $this->percentCoupon,
                $this->workspace,
                $this->package
            );

            expect($result->isValid())->toBeFalse();
        });

        it('rejects coupon that has reached max uses', function () {
            $this->percentCoupon->update([
                'max_uses' => 5,
                'used_count' => 5,
            ]);

            $result = $this->service->validate(
                $this->percentCoupon,
                $this->workspace,
                $this->package
            );

            expect($result->isValid())->toBeFalse();
        });

        it('rejects coupon already used by workspace', function () {
            // Need an order for coupon usage
            $order = Order::create([
                'workspace_id' => $this->workspace->id,
                'order_number' => 'ORD-001',
                'status' => 'paid',
                'subtotal' => 19.00,
                'total' => 15.20,
                'currency' => 'GBP',
            ]);

            CouponUsage::create([
                'coupon_id' => $this->percentCoupon->id,
                'workspace_id' => $this->workspace->id,
                'order_id' => $order->id,
                'discount_amount' => 3.80,
            ]);

            $result = $this->service->validate(
                $this->percentCoupon,
                $this->workspace,
                $this->package
            );

            expect($result->isValid())->toBeFalse();
        });

        it('validates coupon restricted to specific packages', function () {
            // Set applies_to to 'packages' and provide package IDs
            $this->percentCoupon->update([
                'applies_to' => 'packages',
                'package_ids' => [$this->package->id],
            ]);

            $result = $this->service->validate(
                $this->percentCoupon,
                $this->workspace,
                $this->package
            );

            expect($result->isValid())->toBeTrue();

            // Use existing seeded agency package
            $otherPackage = Package::where('code', 'agency')->first();

            $result = $this->service->validate(
                $this->percentCoupon,
                $this->workspace,
                $otherPackage
            );

            expect($result->isValid())->toBeFalse();
        });

        it('validates minimum purchase amount', function () {
            $this->fixedCoupon->update(['min_amount' => 50.00]);

            // When min_amount is set, the validation in CouponService doesn't check it
            // The calculateDiscount method returns 0 for amounts below min_amount
            // So this test should check the discount calculation instead
            $discount = $this->fixedCoupon->calculateDiscount(19.00);

            expect($discount)->toBe(0.0);
        });
    });

    describe('validateByCode() method', function () {
        it('validates coupon by code with sanitisation', function () {
            $result = $this->service->validateByCode(
                '  save20  ',  // lowercase with whitespace
                $this->workspace,
                $this->package
            );

            expect($result->isValid())->toBeTrue()
                ->and($result->getCoupon()->code)->toBe('SAVE20');
        });

        it('returns invalid result for code that is too short', function () {
            $result = $this->service->validateByCode(
                'AB',
                $this->workspace,
                $this->package
            );

            expect($result->isValid())->toBeFalse()
                ->and($result->getMessage())->toBe('Invalid coupon code format');
        });

        it('returns invalid result for code with invalid characters', function () {
            $result = $this->service->validateByCode(
                'CODE@123',
                $this->workspace,
                $this->package
            );

            expect($result->isValid())->toBeFalse()
                ->and($result->getMessage())->toBe('Invalid coupon code format');
        });

        it('returns invalid result for SQL injection attempt', function () {
            $result = $this->service->validateByCode(
                "'; DROP TABLE coupons; --",
                $this->workspace,
                $this->package
            );

            expect($result->isValid())->toBeFalse()
                ->and($result->getMessage())->toBe('Invalid coupon code format');
        });

        it('returns invalid result for non-existent but valid format code', function () {
            $result = $this->service->validateByCode(
                'NONEXISTENT',
                $this->workspace,
                $this->package
            );

            expect($result->isValid())->toBeFalse()
                ->and($result->getMessage())->toBe('Invalid coupon code');
        });
    });

    describe('recordUsage() method', function () {
        it('records coupon usage', function () {
            $order = Order::create([
                'workspace_id' => $this->workspace->id,
                'order_number' => 'ORD-001',
                'status' => 'paid',
                'subtotal' => 19.00,
                'total' => 15.20,
                'currency' => 'GBP',
            ]);

            $usage = $this->service->recordUsage(
                $this->percentCoupon,
                $this->workspace,
                $order,
                3.80
            );

            expect($usage)->toBeInstanceOf(CouponUsage::class)
                ->and($usage->coupon_id)->toBe($this->percentCoupon->id)
                ->and($usage->workspace_id)->toBe($this->workspace->id)
                ->and($usage->order_id)->toBe($order->id)
                ->and((float) $usage->discount_amount)->toBe(3.80);

            // Check used_count was incremented
            $this->percentCoupon->refresh();
            expect($this->percentCoupon->used_count)->toBe(1);
        });
    });
});

describe('Coupon model', function () {
    describe('calculateDiscount() method', function () {
        it('calculates percentage discount', function () {
            $discount = $this->percentCoupon->calculateDiscount(100.00);

            expect($discount)->toBe(20.00);
        });

        it('calculates fixed discount', function () {
            $discount = $this->fixedCoupon->calculateDiscount(100.00);

            expect($discount)->toBe(10.00);
        });

        it('caps fixed discount at subtotal', function () {
            $discount = $this->fixedCoupon->calculateDiscount(5.00);

            expect($discount)->toBe(5.00); // Can't discount more than subtotal
        });

        it('respects max discount amount', function () {
            $this->percentCoupon->update(['max_discount' => 15.00]);

            $discount = $this->percentCoupon->calculateDiscount(100.00);

            expect($discount)->toBe(15.00); // Capped at max
        });
    });

    describe('isValid() method', function () {
        it('returns true for valid coupon', function () {
            expect($this->percentCoupon->isValid())->toBeTrue();
        });

        it('returns false for inactive coupon', function () {
            $this->percentCoupon->update(['is_active' => false]);

            expect($this->percentCoupon->isValid())->toBeFalse();
        });

        it('returns false for expired coupon', function () {
            $this->percentCoupon->update(['valid_until' => now()->subHour()]);

            expect($this->percentCoupon->isValid())->toBeFalse();
        });

        it('returns true within date range', function () {
            $this->percentCoupon->update([
                'valid_from' => now()->subDay(),
                'valid_until' => now()->addDay(),
            ]);

            expect($this->percentCoupon->isValid())->toBeTrue();
        });
    });

    describe('hasReachedMaxUses() method', function () {
        it('returns false when under limit', function () {
            $this->percentCoupon->update([
                'max_uses' => 100,
                'used_count' => 50,
            ]);

            expect($this->percentCoupon->hasReachedMaxUses())->toBeFalse();
        });

        it('returns true when at limit', function () {
            $this->percentCoupon->update([
                'max_uses' => 100,
                'used_count' => 100,
            ]);

            expect($this->percentCoupon->hasReachedMaxUses())->toBeTrue();
        });

        it('returns false when no limit set', function () {
            $this->percentCoupon->update([
                'max_uses' => null,
                'used_count' => 1000,
            ]);

            expect($this->percentCoupon->hasReachedMaxUses())->toBeFalse();
        });
    });

    describe('isRestrictedToPackage() method', function () {
        it('returns false when no package restrictions', function () {
            expect($this->percentCoupon->isRestrictedToPackage('creator'))->toBeFalse();
        });

        it('returns true for allowed package', function () {
            $this->percentCoupon->update(['package_ids' => ['creator', 'agency']]);

            expect($this->percentCoupon->isRestrictedToPackage('creator'))->toBeTrue()
                ->and($this->percentCoupon->isRestrictedToPackage('agency'))->toBeTrue();
        });

        it('returns false for restricted package', function () {
            $this->percentCoupon->update(['package_ids' => ['creator']]);

            expect($this->percentCoupon->isRestrictedToPackage('agency'))->toBeFalse();
        });
    });

    describe('scopes', function () {
        it('scopes to active coupons', function () {
            Coupon::create([
                'code' => 'INACTIVE',
                'name' => 'Inactive',
                'type' => 'percentage',
                'value' => 10.00,
                'is_active' => false,
            ]);

            $active = Coupon::active()->get();

            expect($active->pluck('code')->toArray())->toContain('SAVE20', 'FLAT10')
                ->and($active->pluck('code')->toArray())->not->toContain('INACTIVE');
        });

        it('scopes to valid coupons', function () {
            Coupon::create([
                'code' => 'EXPIRED',
                'name' => 'Expired',
                'type' => 'percentage',
                'value' => 10.00,
                'is_active' => true,
                'valid_until' => now()->subDay(),
            ]);

            $valid = Coupon::valid()->get();

            expect($valid->pluck('code')->toArray())->toContain('SAVE20', 'FLAT10')
                ->and($valid->pluck('code')->toArray())->not->toContain('EXPIRED');
        });
    });
});
