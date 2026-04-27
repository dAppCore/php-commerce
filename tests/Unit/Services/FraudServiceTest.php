<?php

declare(strict_types=1);

use Core\Mod\Commerce\Data\FraudScore;
use Core\Mod\Commerce\Models\Order;
use Core\Mod\Commerce\Services\FraudService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::dropIfExists('orders');

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
        $table->string('billing_name')->nullable();
        $table->string('billing_email')->nullable();
        $table->decimal('tax_rate', 6, 4)->nullable();
        $table->string('tax_country', 2)->nullable();
        $table->json('billing_address')->nullable();
        $table->json('metadata')->nullable();
        $table->timestamp('paid_at')->nullable();
        $table->timestamps();
    });

    Order::unsetEventDispatcher();
    Cache::flush();

    config([
        'commerce.fraud.enabled' => true,
        'commerce.fraud.score.review_threshold' => 50,
        'commerce.fraud.score.block_threshold' => 80,
        'commerce.fraud.velocity.enabled' => true,
        'commerce.fraud.velocity.max_orders_per_ip_hourly' => 1,
        'commerce.fraud.velocity.max_orders_per_email_daily' => 1,
        'commerce.fraud.velocity.max_failed_payments_hourly' => 1,
        'commerce.fraud.geo.enabled' => true,
        'commerce.fraud.geo.flag_country_mismatch' => true,
        'commerce.fraud.geo.high_risk_countries' => ['IR'],
        'commerce.fraud.actions.log' => false,
        'commerce.fraud.actions.auto_block' => true,
        'commerce.fraud.stripe_radar.enabled' => true,
        'commerce.fraud.stripe_radar.block_threshold' => 'highest',
        'commerce.fraud.stripe_radar.review_threshold' => 'elevated',
    ]);

    $this->service = new FraudService();
});

afterEach(function (): void {
    Schema::dropIfExists('orders');
});

function fraudServiceTestOrder(array $overrides = []): Order
{
    return Order::forceCreate(array_merge([
        'workspace_id' => 10,
        'orderable_id' => 10,
        'user_id' => null,
        'order_number' => 'ORD-'.uniqid(),
        'status' => 'pending',
        'type' => 'new',
        'currency' => 'GBP',
        'subtotal' => 100,
        'tax_amount' => 20,
        'discount_amount' => 0,
        'total' => 120,
        'billing_name' => 'Ada Lovelace',
        'billing_email' => 'ada@example.test',
        'tax_country' => 'GB',
        'billing_address' => ['country' => 'GB'],
        'metadata' => [
            'ip_address' => '203.0.113.10',
            'ip_country' => 'GB',
        ],
    ], $overrides));
}

describe('FraudService score()', function (): void {
    it('Good: approves a clean order with no risk signals', function (): void {
        $score = $this->service->score(fraudServiceTestOrder());

        expect($score)->toBeInstanceOf(FraudScore::class)
            ->and($score->score)->toBe(0)
            ->and($score->signals)->toBe([])
            ->and($score->recommendation)->toBe('approve');
    });

    it('Bad: recommends review for velocity and geo mismatch signals', function (): void {
        Cache::put('fraud:orders:ip:203.0.113.20', 1, now()->addHour());

        $score = $this->service->score(fraudServiceTestOrder([
            'metadata' => [
                'ip_address' => '203.0.113.20',
                'ip_country' => 'US',
            ],
        ]));

        expect($score->recommendation)->toBe('review')
            ->and($score->score)->toBeGreaterThanOrEqual(50)
            ->and($score->signals)->toHaveKeys(['velocity_ip_exceeded', 'geo_country_mismatch']);
    });

    it('Ugly: clamps severe Stripe Radar and BIN signals at a block recommendation', function (): void {
        $score = $this->service->score(fraudServiceTestOrder([
            'metadata' => [
                'ip_address' => '203.0.113.30',
                'ip_country' => 'US',
                'card_bin_country' => 'CA',
                'stripe_radar' => [
                    'risk_level' => 'highest',
                    'risk_score' => 97,
                    'rule' => ['action' => 'block'],
                ],
            ],
        ]));

        expect($score->score)->toBe(100)
            ->and($score->recommendation)->toBe('block')
            ->and($score->signals)->toHaveKeys([
                'geo_country_mismatch',
                'card_bin_country_mismatch',
                'stripe_risk_highest',
                'stripe_risk_score',
                'stripe_rule_action',
            ]);
    });
});

describe('FraudService flag()', function (): void {
    it('Good: marks an order as pending fraud review', function (): void {
        $order = fraudServiceTestOrder();

        $this->service->flag($order, 'Velocity threshold exceeded');

        $order->refresh();
        expect($order->status)->toBe(FraudService::ORDER_STATUS_PENDING_REVIEW)
            ->and(data_get($order->metadata, 'fraud.review_status'))->toBe('pending')
            ->and(data_get($order->metadata, 'fraud.review_reason'))->toBe('Velocity threshold exceeded');
    });

    it('Bad: rejects a blank review reason without changing the order', function (): void {
        $order = fraudServiceTestOrder();

        $this->service->flag($order, " \n\t ");
    })->throws(InvalidArgumentException::class, 'Fraud reason is required.');

    it('Ugly: preserves existing metadata and truncates oversized reasons', function (): void {
        $order = fraudServiceTestOrder([
            'metadata' => [
                'ip_address' => '203.0.113.40',
                'ip_country' => 'GB',
                'checkout_reference' => 'abc123',
            ],
        ]);

        $this->service->flag($order, str_repeat('x', 700));

        $order->refresh();
        expect(data_get($order->metadata, 'checkout_reference'))->toBe('abc123')
            ->and(strlen(data_get($order->metadata, 'fraud.review_reason')))->toBe(500);
    });
});

describe('FraudService block()', function (): void {
    it('Good: rejects an unpaid order with fraud metadata', function (): void {
        $order = fraudServiceTestOrder();

        $this->service->block($order, 'Confirmed card testing');

        $order->refresh();
        expect($order->status)->toBe('failed')
            ->and(data_get($order->metadata, 'failure_reason'))->toBe('Confirmed card testing')
            ->and(data_get($order->metadata, 'fraud.review_status'))->toBe('blocked')
            ->and(data_get($order->metadata, 'fraud.block_reason'))->toBe('Confirmed card testing');
    });

    it('Bad: rejects a blank block reason', function (): void {
        $order = fraudServiceTestOrder();

        $this->service->block($order, '');
    })->throws(InvalidArgumentException::class, 'Fraud reason is required.');

    it('Ugly: removes a previously flagged order from the review queue', function (): void {
        $order = fraudServiceTestOrder();
        $this->service->flag($order, 'Manual review');

        $this->service->block($order->refresh(), 'Confirmed fraud');

        $order->refresh();
        expect($this->service->reviewQueue())->toHaveCount(0)
            ->and($order->status)->toBe('failed')
            ->and(data_get($order->metadata, 'fraud.review_status'))->toBe('blocked');
    });
});

describe('FraudService reviewQueue()', function (): void {
    it('Good: returns pending fraud review orders oldest first', function (): void {
        $newest = fraudServiceTestOrder(['order_number' => 'ORD-NEW']);
        $oldest = fraudServiceTestOrder(['order_number' => 'ORD-OLD']);

        $this->service->flag($newest, 'Second review');
        $newest->update(['created_at' => now()->addMinute()]);
        $this->service->flag($oldest, 'First review');
        $oldest->update(['created_at' => now()->subMinute()]);

        $queue = $this->service->reviewQueue();

        expect($queue)->toBeInstanceOf(Collection::class)
            ->and($queue->pluck('order_number')->all())->toBe(['ORD-OLD', 'ORD-NEW']);
    });

    it('Bad: excludes blocked and approved orders', function (): void {
        $blocked = fraudServiceTestOrder(['order_number' => 'ORD-BLOCKED']);
        $approved = fraudServiceTestOrder(['order_number' => 'ORD-APPROVED']);
        $pending = fraudServiceTestOrder(['order_number' => 'ORD-PENDING']);

        $this->service->block($blocked, 'Confirmed fraud');
        $this->service->flag($approved, 'Manual check');
        $this->service->approve($approved->refresh());
        $this->service->flag($pending, 'Manual check');

        expect($this->service->reviewQueue()->pluck('order_number')->all())->toBe(['ORD-PENDING']);
    });

    it('Ugly: excludes stale pending-review statuses without fraud metadata', function (): void {
        fraudServiceTestOrder([
            'order_number' => 'ORD-STALE',
            'status' => FraudService::ORDER_STATUS_PENDING_REVIEW,
            'metadata' => ['note' => 'legacy status only'],
        ]);

        expect($this->service->reviewQueue())->toHaveCount(0);
    });
});

describe('FraudService approve()', function (): void {
    it('Good: approves a flagged order and restores its prior status', function (): void {
        $order = fraudServiceTestOrder(['status' => 'processing']);
        $this->service->flag($order, 'Manual check');

        $this->service->approve($order->refresh());

        $order->refresh();
        expect($order->status)->toBe('processing')
            ->and(data_get($order->metadata, 'fraud.review_status'))->toBe('approved')
            ->and(data_get($order->metadata, 'fraud.approved_at'))->not->toBeNull();
    });

    it('Bad: refuses to approve an order that is not pending review', function (): void {
        $order = fraudServiceTestOrder();

        $this->service->approve($order);
    })->throws(RuntimeException::class, 'Only orders pending fraud review can be approved.');

    it('Ugly: removes the approved order from the review queue without dropping metadata', function (): void {
        $order = fraudServiceTestOrder([
            'metadata' => [
                'ip_address' => '203.0.113.50',
                'ip_country' => 'GB',
                'checkout_reference' => 'keep-me',
            ],
        ]);

        $this->service->flag($order, 'Manual check');
        $this->service->approve($order->refresh());

        $order->refresh();
        expect($this->service->reviewQueue())->toHaveCount(0)
            ->and(data_get($order->metadata, 'checkout_reference'))->toBe('keep-me')
            ->and(data_get($order->metadata, 'fraud.review_reason'))->toBe('Manual check');
    });
});
