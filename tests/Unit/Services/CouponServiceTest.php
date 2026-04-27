<?php

declare(strict_types=1);

use Carbon\Carbon;
use Core\Mod\Commerce\Data\Coupon as CouponData;
use Core\Mod\Commerce\Data\ValidationResult;
use Core\Mod\Commerce\Models\Coupon as CouponModel;
use Core\Mod\Commerce\Models\CouponUsage;
use Core\Mod\Commerce\Models\Order;
use Core\Mod\Commerce\Models\OrderItem;
use Core\Mod\Commerce\Services\CouponService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::dropIfExists('coupon_usages');
    Schema::dropIfExists('order_items');
    Schema::dropIfExists('orders');
    Schema::dropIfExists('coupons');

    Schema::create('coupons', function (Blueprint $table): void {
        $table->id();
        $table->string('code')->index();
        $table->string('name');
        $table->text('description')->nullable();
        $table->string('type');
        $table->decimal('value', 10, 2);
        $table->decimal('min_amount', 10, 2)->nullable();
        $table->decimal('max_discount', 10, 2)->nullable();
        $table->string('applies_to')->default('all');
        $table->json('package_ids')->nullable();
        $table->unsignedInteger('max_uses')->nullable();
        $table->unsignedInteger('max_uses_per_workspace')->default(1);
        $table->unsignedInteger('used_count')->default(0);
        $table->string('duration')->default('once');
        $table->unsignedInteger('duration_months')->nullable();
        $table->timestamp('valid_from')->nullable();
        $table->timestamp('valid_until')->nullable();
        $table->boolean('is_active')->default(true);
        $table->string('stripe_coupon_id')->nullable();
        $table->string('btcpay_coupon_id')->nullable();
        $table->timestamps();
    });

    Schema::create('orders', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('workspace_id')->nullable();
        $table->string('orderable_type')->nullable();
        $table->unsignedBigInteger('orderable_id')->nullable();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->string('order_number')->unique();
        $table->string('status')->default('pending');
        $table->string('type')->default('new');
        $table->string('billing_cycle')->nullable();
        $table->string('currency', 3)->default('GBP');
        $table->decimal('subtotal', 10, 2)->default(0);
        $table->decimal('tax_amount', 10, 2)->default(0);
        $table->decimal('discount_amount', 10, 2)->default(0);
        $table->decimal('total', 10, 2)->default(0);
        $table->unsignedBigInteger('coupon_id')->nullable();
        $table->json('billing_address')->nullable();
        $table->json('metadata')->nullable();
        $table->timestamp('paid_at')->nullable();
        $table->timestamps();
    });

    Schema::create('order_items', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('order_id');
        $table->string('item_type');
        $table->unsignedBigInteger('item_id')->nullable();
        $table->string('item_code')->nullable();
        $table->string('description');
        $table->unsignedInteger('quantity')->default(1);
        $table->decimal('unit_price', 10, 2);
        $table->decimal('line_total', 10, 2);
        $table->string('billing_cycle')->default('onetime');
        $table->json('metadata')->nullable();
        $table->timestamp('created_at')->nullable();
    });

    Schema::create('coupon_usages', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('coupon_id');
        $table->unsignedBigInteger('workspace_id');
        $table->unsignedBigInteger('order_id');
        $table->decimal('discount_amount', 10, 2);
        $table->timestamp('created_at')->nullable();
    });

    CouponModel::unsetEventDispatcher();
    CouponUsage::unsetEventDispatcher();
    Order::unsetEventDispatcher();
    OrderItem::unsetEventDispatcher();

    $this->service = new CouponService();
});

afterEach(function (): void {
    Schema::dropIfExists('coupon_usages');
    Schema::dropIfExists('order_items');
    Schema::dropIfExists('orders');
    Schema::dropIfExists('coupons');
});

function couponServiceTestOrder(array $lineTotals = [100.00], int $workspaceId = 10): Order
{
    $order = Order::forceCreate([
        'workspace_id' => $workspaceId,
        'order_number' => 'ORD-'.uniqid(),
        'status' => 'pending',
        'type' => 'new',
        'currency' => 'GBP',
        'subtotal' => array_sum($lineTotals),
        'tax_amount' => 0,
        'discount_amount' => 0,
        'total' => array_sum($lineTotals),
    ]);

    foreach ($lineTotals as $index => $lineTotal) {
        OrderItem::create([
            'order_id' => $order->id,
            'item_type' => 'package',
            'item_id' => $index + 1,
            'item_code' => 'PKG-'.$index,
            'description' => 'Package '.$index,
            'quantity' => 1,
            'unit_price' => $lineTotal,
            'line_total' => $lineTotal,
            'billing_cycle' => 'monthly',
        ]);
    }

    return $order->load('items');
}

describe('CouponService create()', function (): void {
    it('Good: creates and persists a percent coupon DTO', function (): void {
        $coupon = $this->service->create(' save20 ', 'percent', 20, 5, Carbon::now()->addMonth());

        expect($coupon)->toBeInstanceOf(CouponData::class)
            ->and($coupon->code)->toBe('SAVE20')
            ->and($coupon->type)->toBe('percent')
            ->and($coupon->maxUses)->toBe(5)
            ->and(CouponModel::byCode('SAVE20')->exists())->toBeTrue();
    });

    it('Bad: rejects an invalid discount type', function (): void {
        $this->service->create('SAVE20', 'bogus', 20, 5, null);
    })->throws(InvalidArgumentException::class);

    it('Ugly: rejects duplicate sanitised codes', function (): void {
        $this->service->create('SAVE20', 'percent', 20, 5, null);

        $this->service->create(' save20 ', 'percent', 25, 5, null);
    })->throws(InvalidArgumentException::class);
});

describe('CouponService validate()', function (): void {
    it('Good: validates a live coupon and calculates the order discount', function (): void {
        $this->service->create('SAVE20', 'percent', 20, 5, Carbon::now()->addMonth());
        $order = couponServiceTestOrder([100.00]);

        $result = $this->service->validate('SAVE20', $order);

        expect($result)->toBeInstanceOf(ValidationResult::class)
            ->and($result->valid)->toBeTrue()
            ->and($result->discountAmount)->toBe(20.00)
            ->and($result->discountType)->toBe('percent');
    });

    it('Bad: rejects an expired coupon', function (): void {
        $this->service->create('OLD10', 'fixed', 10, 5, Carbon::now()->subDay());
        $order = couponServiceTestOrder([50.00]);

        $result = $this->service->validate('OLD10', $order);

        expect($result->valid)->toBeFalse()
            ->and($result->reason)->toBe('Coupon has expired');
    });

    it('Ugly: rejects hostile coupon code input before lookup', function (): void {
        $order = couponServiceTestOrder([50.00]);

        $result = $this->service->validate("'; DROP TABLE coupons; --", $order);

        expect($result->valid)->toBeFalse()
            ->and($result->reason)->toBe('Invalid coupon code format');
    });
});

describe('CouponService apply()', function (): void {
    it('Good: applies a fixed coupon across line items and records usage', function (): void {
        $coupon = $this->service->create('FLAT30', 'fixed', 30, 5, Carbon::now()->addMonth());
        $order = couponServiceTestOrder([100.00, 50.00], 22);

        $applied = $this->service->apply($coupon, $order);

        expect((float) $applied->discount_amount)->toBe(30.00)
            ->and((float) $applied->total)->toBe(120.00)
            ->and($applied->items->pluck('line_total')->map(fn (mixed $value): float => (float) $value)->all())
            ->toBe([80.00, 40.00])
            ->and(CouponUsage::query()->count())->toBe(1)
            ->and((float) CouponUsage::query()->first()->discount_amount)->toBe(30.00);
    });

    it('Bad: refuses to apply an inactive coupon', function (): void {
        $coupon = $this->service->create('PAUSED', 'fixed', 10, 5, Carbon::now()->addMonth());
        CouponModel::byCode('PAUSED')->firstOrFail()->update(['is_active' => false]);
        $order = couponServiceTestOrder([100.00]);

        $this->service->apply($coupon, $order);
    })->throws(RuntimeException::class, 'Coupon is inactive');

    it('Ugly: caps a large fixed coupon at the order subtotal', function (): void {
        $coupon = $this->service->create('FREEBIE', 'fixed', 999, 5, Carbon::now()->addMonth());
        $order = couponServiceTestOrder([20.00]);

        $applied = $this->service->apply($coupon, $order);

        expect((float) $applied->discount_amount)->toBe(20.00)
            ->and((float) $applied->total)->toBe(0.00)
            ->and((float) $applied->items->first()->line_total)->toBe(0.00);
    });
});

describe('CouponService expire()', function (): void {
    it('Good: expires an active coupon immediately', function (): void {
        $coupon = $this->service->create('SPRING', 'percent', 15, 5, Carbon::now()->addMonth());

        $this->service->expire($coupon);

        $model = CouponModel::byCode('SPRING')->firstOrFail();
        expect($model->is_active)->toBeFalse()
            ->and($model->valid_until?->isPast() || $model->valid_until?->isCurrentSecond())->toBeTrue();
    });

    it('Bad: fails when the DTO no longer points to a persisted coupon', function (): void {
        $coupon = $this->service->create('GONE', 'fixed', 10, 5, null);
        CouponModel::byCode('GONE')->firstOrFail()->delete();

        $this->service->expire($coupon);
    })->throws(ModelNotFoundException::class);

    it('Ugly: can expire an already expired coupon without reactivating it', function (): void {
        $coupon = $this->service->create('ANCIENT', 'fixed', 10, 5, Carbon::now()->subMonth());

        $this->service->expire($coupon);

        $model = CouponModel::byCode('ANCIENT')->firstOrFail();
        expect($model->is_active)->toBeFalse()
            ->and($model->valid_until?->isPast() || $model->valid_until?->isCurrentSecond())->toBeTrue();
    });
});

describe('CouponService report()', function (): void {
    it('Good: reports redemption totals by coupon', function (): void {
        $coupon = $this->service->create('SAVE10', 'fixed', 10, 5, Carbon::now()->addMonth());
        $this->service->apply($coupon, couponServiceTestOrder([50.00], 44));

        $report = $this->service->report();

        expect($report['total_coupons'])->toBe(1)
            ->and($report['total_redemptions'])->toBe(1)
            ->and($report['total_discount_amount'])->toBe(10.00)
            ->and($report['by_coupon'][0]['code'])->toBe('SAVE10')
            ->and($report['by_coupon'][0]['redemptions'])->toBe(1);
    });

    it('Bad: reports zero redemption stats when nothing has been applied', function (): void {
        $this->service->create('UNUSED', 'percent', 5, 5, Carbon::now()->addMonth());

        $report = $this->service->report();

        expect($report['total_coupons'])->toBe(1)
            ->and($report['total_redemptions'])->toBe(0)
            ->and($report['total_discount_amount'])->toBe(0.00)
            ->and($report['by_coupon'][0]['redemptions'])->toBe(0);
    });

    it('Ugly: includes expired coupon counts alongside active redemption data', function (): void {
        $coupon = $this->service->create('LIVE10', 'fixed', 10, 5, Carbon::now()->addMonth());
        $this->service->create('EXPIRED', 'fixed', 10, 5, Carbon::now()->subMonth());
        $this->service->apply($coupon, couponServiceTestOrder([40.00], 55));

        $report = $this->service->report();

        expect($report['total_coupons'])->toBe(2)
            ->and($report['active_coupons'])->toBe(2)
            ->and($report['expired_coupons'])->toBe(1)
            ->and($report['by_coupon'][0]['code'])->toBe('LIVE10');
    });
});
