<?php

declare(strict_types=1);

use Carbon\Carbon;
use Core\Mod\Commerce\Data\DunningSchedule;
use Core\Mod\Commerce\Data\PaymentResult;
use Core\Mod\Commerce\Models\Invoice;
use Core\Mod\Commerce\Models\Payment;
use Core\Mod\Commerce\Models\Subscription;
use Core\Mod\Commerce\Notifications\AccountSuspended;
use Core\Mod\Commerce\Services\CommerceService;
use Core\Mod\Commerce\Services\DunningService;
use Core\Mod\Commerce\Services\SubscriptionService;
use Core\Tenant\Models\User;
use Core\Tenant\Models\Workspace;
use Core\Tenant\Services\EntitlementService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::dropIfExists('payments');
    Schema::dropIfExists('invoices');
    Schema::dropIfExists('subscriptions');
    Schema::dropIfExists('user_workspace');
    Schema::dropIfExists('users');
    Schema::dropIfExists('workspaces');

    Schema::create('workspaces', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('slug')->unique();
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password')->nullable();
        $table->timestamps();
    });

    Schema::create('user_workspace', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->unsignedBigInteger('workspace_id');
        $table->string('role');
        $table->boolean('is_default')->default(false);
        $table->unsignedBigInteger('team_id')->nullable();
        $table->json('custom_permissions')->nullable();
        $table->timestamp('joined_at')->nullable();
        $table->unsignedBigInteger('invited_by')->nullable();
        $table->timestamps();
    });

    Schema::create('subscriptions', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('workspace_id')->nullable();
        $table->unsignedBigInteger('workspace_package_id')->nullable();
        $table->string('gateway')->default('stripe');
        $table->string('gateway_subscription_id')->nullable();
        $table->string('gateway_customer_id')->nullable();
        $table->string('gateway_price_id')->nullable();
        $table->string('status')->default('active');
        $table->string('billing_cycle')->default('monthly');
        $table->timestamp('current_period_start')->nullable();
        $table->timestamp('current_period_end')->nullable();
        $table->timestamp('trial_ends_at')->nullable();
        $table->boolean('cancel_at_period_end')->default(false);
        $table->timestamp('cancelled_at')->nullable();
        $table->string('cancellation_reason')->nullable();
        $table->timestamp('ended_at')->nullable();
        $table->timestamp('paused_at')->nullable();
        $table->unsignedInteger('pause_count')->default(0);
        $table->json('metadata')->nullable();
        $table->timestamps();
    });

    Schema::create('invoices', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('workspace_id')->nullable();
        $table->unsignedBigInteger('order_id')->nullable();
        $table->unsignedBigInteger('payment_id')->nullable();
        $table->string('invoice_number')->unique();
        $table->string('status')->default('sent');
        $table->string('currency', 3)->default('GBP');
        $table->decimal('subtotal', 10, 2)->default(0);
        $table->decimal('tax_amount', 10, 2)->default(0);
        $table->decimal('discount_amount', 10, 2)->default(0);
        $table->decimal('total', 10, 2)->default(0);
        $table->decimal('amount_paid', 10, 2)->default(0);
        $table->decimal('amount_due', 10, 2)->default(0);
        $table->date('issue_date')->nullable();
        $table->date('due_date')->nullable();
        $table->timestamp('paid_at')->nullable();
        $table->boolean('auto_charge')->default(true);
        $table->unsignedInteger('charge_attempts')->default(0);
        $table->timestamp('last_charge_attempt')->nullable();
        $table->timestamp('next_charge_attempt')->nullable();
        $table->json('metadata')->nullable();
        $table->timestamps();
    });

    Schema::create('payments', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('workspace_id')->nullable();
        $table->unsignedBigInteger('invoice_id')->nullable();
        $table->string('gateway')->default('stripe');
        $table->string('currency', 3)->default('GBP');
        $table->decimal('amount', 10, 2)->default(0);
        $table->decimal('fee', 10, 2)->default(0);
        $table->decimal('net_amount', 10, 2)->default(0);
        $table->string('status')->default('pending');
        $table->string('failure_reason')->nullable();
        $table->timestamp('paid_at')->nullable();
        $table->timestamps();
    });

    Subscription::unsetEventDispatcher();
    Invoice::unsetEventDispatcher();
    Payment::unsetEventDispatcher();

    config([
        'commerce.dunning.retry_days' => [1, 3, 7],
        'commerce.dunning.suspend_after_days' => 14,
        'commerce.dunning.send_notifications' => true,
    ]);

    $this->commerce = Mockery::mock(CommerceService::class);
    $this->subscriptions = Mockery::mock(SubscriptionService::class);
    $this->entitlements = Mockery::mock(EntitlementService::class);
    $this->service = new DunningService(
        $this->commerce,
        $this->subscriptions,
        $this->entitlements,
    );
});

afterEach(function (): void {
    Carbon::setTestNow();
    Mockery::close();

    Schema::dropIfExists('payments');
    Schema::dropIfExists('invoices');
    Schema::dropIfExists('subscriptions');
    Schema::dropIfExists('user_workspace');
    Schema::dropIfExists('users');
    Schema::dropIfExists('workspaces');
});

function dunningServiceTestWorkspace(bool $withOwner = true): Workspace
{
    $workspaceId = DB::table('workspaces')->insertGetId([
        'name' => 'Dunning Test Workspace',
        'slug' => 'dunning-test-'.uniqid(),
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    if ($withOwner) {
        $userId = DB::table('users')->insertGetId([
            'name' => 'Dunning Owner',
            'email' => 'dunning-'.uniqid().'@example.test',
            'password' => 'secret',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('user_workspace')->insert([
            'user_id' => $userId,
            'workspace_id' => $workspaceId,
            'role' => 'owner',
            'is_default' => true,
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return Workspace::query()->findOrFail($workspaceId);
}

function dunningServiceTestSubscription(array $overrides = [], ?Workspace $workspace = null): Subscription
{
    if (! array_key_exists('workspace_id', $overrides)) {
        $workspace ??= dunningServiceTestWorkspace();
        $overrides['workspace_id'] = $workspace->id;
    }

    return Subscription::forceCreate(array_merge([
        'workspace_package_id' => null,
        'status' => 'active',
        'gateway' => 'stripe',
        'billing_cycle' => 'monthly',
        'current_period_start' => now(),
        'current_period_end' => now()->addDays(30),
        'metadata' => null,
    ], $overrides));
}

function dunningServiceTestInvoice(array $overrides = [], ?Workspace $workspace = null): Invoice
{
    if (! array_key_exists('workspace_id', $overrides)) {
        $workspace ??= dunningServiceTestWorkspace();
        $overrides['workspace_id'] = $workspace->id;
    }

    return Invoice::forceCreate(array_merge([
        'invoice_number' => 'INV-DUN-'.uniqid(),
        'status' => 'overdue',
        'currency' => 'GBP',
        'subtotal' => 20.00,
        'total' => 20.00,
        'amount_due' => 20.00,
        'issue_date' => now(),
        'due_date' => now()->subDay(),
        'auto_charge' => true,
        'charge_attempts' => 0,
    ], $overrides));
}

describe('DunningService schedule()', function (): void {
    it('Good: stores retry dates and marks an active subscription past due', function (): void {
        Carbon::setTestNow('2026-01-01 09:00:00');
        $subscription = dunningServiceTestSubscription();

        $schedule = $this->service->schedule($subscription);

        expect($schedule)->toBeInstanceOf(DunningSchedule::class)
            ->and(array_map(fn (Carbon $date): string => $date->toDateString(), $schedule->retryDates))
            ->toBe(['2026-01-02', '2026-01-04', '2026-01-08'])
            ->and($schedule->suspensionDate->toDateString())->toBe('2026-01-15')
            ->and($subscription->fresh()->status)->toBe('past_due')
            ->and(data_get($subscription->fresh()->metadata, 'dunning.stage'))->toBe('scheduled');
    });

    it('Bad: rejects ended subscriptions', function (): void {
        $subscription = dunningServiceTestSubscription(['status' => 'cancelled']);

        $this->service->schedule($subscription);
    })->throws(InvalidArgumentException::class);

    it('Ugly: preserves unrelated subscription metadata when scheduling', function (): void {
        $subscription = dunningServiceTestSubscription([
            'metadata' => ['customer_note' => 'preserve'],
        ]);

        $this->service->schedule($subscription);

        expect($subscription->fresh()->metadata['customer_note'])->toBe('preserve')
            ->and(data_get($subscription->fresh()->metadata, 'dunning.retry_dates'))->toHaveCount(3);
    });
});

describe('DunningService retry()', function (): void {
    it('Good: records a successful payment retry and clears dunning', function (): void {
        $workspace = dunningServiceTestWorkspace();
        $subscription = dunningServiceTestSubscription([
            'status' => 'past_due',
            'metadata' => ['dunning' => ['stage' => 'scheduled']],
        ], $workspace);
        $invoice = dunningServiceTestInvoice([
            'next_charge_attempt' => now()->subMinute(),
        ], $workspace);

        $this->commerce
            ->shouldReceive('retryInvoicePayment')
            ->once()
            ->andReturnUsing(function (Invoice $invoice): bool {
                $payment = Payment::forceCreate([
                    'workspace_id' => $invoice->workspace_id,
                    'invoice_id' => $invoice->id,
                    'gateway' => 'stripe',
                    'currency' => 'GBP',
                    'amount' => 20.00,
                    'net_amount' => 20.00,
                    'status' => 'succeeded',
                    'paid_at' => now(),
                ]);

                $invoice->markAsPaid($payment);

                return true;
            });

        $result = $this->service->retry($invoice);

        expect($result)->toBeInstanceOf(PaymentResult::class)
            ->and($result->successful)->toBeTrue()
            ->and($result->attempts)->toBe(1)
            ->and($invoice->fresh()->status)->toBe('paid')
            ->and($invoice->fresh()->next_charge_attempt)->toBeNull()
            ->and(data_get($subscription->fresh()->metadata, 'dunning'))->toBeNull();
    });

    it('Bad: refuses invoices that are not configured for automatic charging', function (): void {
        $invoice = dunningServiceTestInvoice(['auto_charge' => false]);
        $this->commerce->shouldNotReceive('retryInvoicePayment');

        $result = $this->service->retry($invoice);

        expect($result->successful)->toBeFalse()
            ->and($result->reason)->toBe('Invoice is not configured for automatic charging.')
            ->and($invoice->fresh()->charge_attempts)->toBe(0);
    });

    it('Ugly: captures gateway exceptions and schedules the next retry', function (): void {
        Carbon::setTestNow('2026-01-01 09:00:00');
        $workspace = dunningServiceTestWorkspace();
        dunningServiceTestSubscription(['status' => 'past_due'], $workspace);
        $invoice = dunningServiceTestInvoice([], $workspace);

        $this->commerce
            ->shouldReceive('retryInvoicePayment')
            ->once()
            ->andThrow(new RuntimeException('gateway offline'));

        $result = $this->service->retry($invoice);

        expect($result->successful)->toBeFalse()
            ->and($result->reason)->toBe('gateway offline')
            ->and($result->attempts)->toBe(1)
            ->and($result->nextRetryAt?->toDateString())->toBe('2026-01-04')
            ->and($invoice->fresh()->next_charge_attempt?->toDateString())->toBe('2026-01-04');
    });
});

describe('DunningService suspend()', function (): void {
    it('Good: marks the subscription suspended and suspends workspace entitlements', function (): void {
        Notification::fake();
        Event::fake();
        $workspace = dunningServiceTestWorkspace();
        $subscription = dunningServiceTestSubscription(['status' => 'past_due'], $workspace);

        $this->entitlements
            ->shouldReceive('suspendWorkspace')
            ->once()
            ->with(Mockery::type(Workspace::class), 'dunning');

        $this->service->suspend($subscription);

        expect($subscription->fresh()->status)->toBe('suspended')
            ->and($subscription->fresh()->paused_at)->not->toBeNull()
            ->and(data_get($subscription->fresh()->metadata, 'dunning.stage'))->toBe('suspended');

        Event::assertDispatched('commerce.dunning.notified');
    });

    it('Bad: rejects ended subscriptions', function (): void {
        $subscription = dunningServiceTestSubscription(['status' => 'expired']);

        $this->service->suspend($subscription);
    })->throws(InvalidArgumentException::class);

    it('Ugly: refuses to suspend a subscription with no workspace', function (): void {
        $subscription = dunningServiceTestSubscription(['workspace_id' => null]);
        $this->entitlements->shouldNotReceive('suspendWorkspace');

        $this->service->suspend($subscription);
    })->throws(InvalidArgumentException::class);
});

describe('DunningService notify()', function (): void {
    it('Good: sends the notification mapped to the requested dunning stage', function (): void {
        Notification::fake();
        Event::fake();
        $workspace = dunningServiceTestWorkspace();
        $subscription = dunningServiceTestSubscription([], $workspace);
        $owner = User::query()->findOrFail($workspace->owner()->id);

        $this->service->notify($subscription, 'suspended');

        Notification::assertSentTo($owner, AccountSuspended::class);
        Event::assertDispatched('commerce.dunning.notified');
    });

    it('Bad: rejects unknown stages', function (): void {
        $subscription = dunningServiceTestSubscription();

        $this->service->notify($subscription, 'mystery');
    })->throws(InvalidArgumentException::class);

    it('Ugly: dispatches the stage event even when no owner can receive email', function (): void {
        Notification::fake();
        Event::fake();
        $workspace = dunningServiceTestWorkspace(withOwner: false);
        $subscription = dunningServiceTestSubscription([], $workspace);

        $this->service->notify($subscription, 'failed');

        Event::assertDispatched('commerce.dunning.notified');
        Notification::assertNothingSent();
    });
});

describe('DunningService recover()', function (): void {
    it('Good: clears dunning metadata, retry dates, and workspace suspension', function (): void {
        $workspace = dunningServiceTestWorkspace();
        $subscription = dunningServiceTestSubscription([
            'status' => 'suspended',
            'paused_at' => now()->subDays(2),
            'metadata' => ['dunning' => ['stage' => 'suspended']],
        ], $workspace);
        $invoice = dunningServiceTestInvoice([
            'next_charge_attempt' => now()->addDay(),
        ], $workspace);

        $this->entitlements
            ->shouldReceive('reactivateWorkspace')
            ->once()
            ->with(Mockery::type(Workspace::class), 'dunning_recovery');

        $this->service->recover($subscription);

        expect($subscription->fresh()->status)->toBe('active')
            ->and($subscription->fresh()->paused_at)->toBeNull()
            ->and(data_get($subscription->fresh()->metadata, 'dunning'))->toBeNull()
            ->and($invoice->fresh()->next_charge_attempt)->toBeNull();
    });

    it('Bad: does not reactivate an ended subscription', function (): void {
        $subscription = dunningServiceTestSubscription([
            'status' => 'cancelled',
            'metadata' => ['dunning' => ['stage' => 'scheduled']],
        ]);
        $this->entitlements->shouldNotReceive('reactivateWorkspace');

        $this->service->recover($subscription);

        expect($subscription->fresh()->status)->toBe('cancelled')
            ->and(data_get($subscription->fresh()->metadata, 'dunning'))->toBeNull();
    });

    it('Ugly: tolerates missing workspace and missing dunning metadata', function (): void {
        $subscription = dunningServiceTestSubscription([
            'workspace_id' => null,
            'status' => 'past_due',
            'metadata' => null,
        ]);
        $this->entitlements->shouldNotReceive('reactivateWorkspace');

        $this->service->recover($subscription);

        expect($subscription->fresh()->status)->toBe('active')
            ->and($subscription->fresh()->metadata)->toBe([]);
    });
});
