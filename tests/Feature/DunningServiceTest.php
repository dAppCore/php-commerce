<?php

use Carbon\Carbon;
use Core\Mod\Commerce\Models\Invoice;
use Core\Mod\Commerce\Models\Subscription;
use Core\Mod\Commerce\Notifications\AccountSuspended;
use Core\Mod\Commerce\Notifications\PaymentFailed;
use Core\Mod\Commerce\Notifications\SubscriptionCancelled;
use Core\Mod\Commerce\Notifications\SubscriptionPaused;
use Core\Mod\Commerce\Services\DunningService;
use Core\Tenant\Models\Package;
use Core\Tenant\Models\User;
use Core\Tenant\Models\Workspace;
use Core\Tenant\Models\WorkspacePackage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Notification::fake();

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create();
    $this->workspace->users()->attach($this->user->id, [
        'role' => 'owner',
        'is_default' => true,
    ]);

    // Use existing seeded package
    $this->package = Package::where('code', 'creator')->first();

    $this->workspacePackage = WorkspacePackage::create([
        'workspace_id' => $this->workspace->id,
        'package_id' => $this->package->id,
        'status' => 'active',
    ]);

    $this->subscription = Subscription::create([
        'workspace_id' => $this->workspace->id,
        'workspace_package_id' => $this->workspacePackage->id,
        'status' => 'active',
        'gateway' => 'btcpay',
        'billing_cycle' => 'monthly',
        'current_period_start' => now(),
        'current_period_end' => now()->addDays(30),
    ]);

    $this->service = app(DunningService::class);
});

describe('DunningService', function () {
    describe('handlePaymentFailure()', function () {
        it('marks invoice as overdue and schedules retry', function () {
            $invoice = Invoice::create([
                'workspace_id' => $this->workspace->id,
                'invoice_number' => 'INV-2025-0001',
                'status' => 'sent',
                'auto_charge' => true,
                'subtotal' => 19.00,
                'total' => 19.00,
                'amount_due' => 19.00,
                'currency' => 'GBP',
                'issue_date' => now(),
                'due_date' => now()->addDays(14),
            ]);

            $this->service->handlePaymentFailure($invoice, $this->subscription);

            $invoice->refresh();
            expect($invoice->status)->toBe('overdue')
                ->and($invoice->charge_attempts)->toBe(1)
                ->and($invoice->next_charge_attempt)->not->toBeNull();
        });

        it('marks subscription as past due', function () {
            $invoice = Invoice::create([
                'workspace_id' => $this->workspace->id,
                'invoice_number' => 'INV-2025-0002',
                'status' => 'sent',
                'auto_charge' => true,
                'subtotal' => 19.00,
                'total' => 19.00,
                'amount_due' => 19.00,
                'currency' => 'GBP',
                'issue_date' => now(),
                'due_date' => now()->addDays(14),
            ]);

            $this->service->handlePaymentFailure($invoice, $this->subscription);

            $this->subscription->refresh();
            expect($this->subscription->status)->toBe('past_due');
        });

        it('sends payment failed notification', function () {
            $invoice = Invoice::create([
                'workspace_id' => $this->workspace->id,
                'invoice_number' => 'INV-2025-0003',
                'status' => 'sent',
                'auto_charge' => true,
                'subtotal' => 19.00,
                'total' => 19.00,
                'amount_due' => 19.00,
                'currency' => 'GBP',
                'issue_date' => now(),
                'due_date' => now()->addDays(14),
            ]);

            $this->service->handlePaymentFailure($invoice, $this->subscription);

            Notification::assertSentTo($this->user, PaymentFailed::class);
        });
    });

    describe('handlePaymentRecovery()', function () {
        it('clears dunning state from invoice', function () {
            $invoice = Invoice::create([
                'workspace_id' => $this->workspace->id,
                'invoice_number' => 'INV-2025-0004',
                'status' => 'overdue',
                'auto_charge' => true,
                'subtotal' => 19.00,
                'total' => 19.00,
                'amount_due' => 19.00,
                'currency' => 'GBP',
                'issue_date' => now(),
                'due_date' => now()->subDays(7),
                'charge_attempts' => 2,
                'next_charge_attempt' => now()->addDays(3),
            ]);

            $this->service->handlePaymentRecovery($invoice, $this->subscription);

            $invoice->refresh();
            expect($invoice->next_charge_attempt)->toBeNull();
        });

        it('unpauses subscription if paused', function () {
            $this->subscription->update([
                'status' => 'paused',
                'paused_at' => now()->subDays(5),
            ]);

            $invoice = Invoice::create([
                'workspace_id' => $this->workspace->id,
                'invoice_number' => 'INV-2025-0005',
                'status' => 'overdue',
                'auto_charge' => true,
                'subtotal' => 19.00,
                'total' => 19.00,
                'amount_due' => 19.00,
                'currency' => 'GBP',
                'issue_date' => now(),
                'due_date' => now()->subDays(7),
            ]);

            $this->service->handlePaymentRecovery($invoice, $this->subscription);

            $this->subscription->refresh();
            expect($this->subscription->status)->toBe('active')
                ->and($this->subscription->paused_at)->toBeNull();
        });

        it('reactivates past due subscription', function () {
            $this->subscription->update(['status' => 'past_due']);

            $invoice = Invoice::create([
                'workspace_id' => $this->workspace->id,
                'invoice_number' => 'INV-2025-0006',
                'status' => 'overdue',
                'auto_charge' => true,
                'subtotal' => 19.00,
                'total' => 19.00,
                'amount_due' => 19.00,
                'currency' => 'GBP',
                'issue_date' => now(),
                'due_date' => now()->subDays(7),
            ]);

            $this->service->handlePaymentRecovery($invoice, $this->subscription);

            $this->subscription->refresh();
            expect($this->subscription->status)->toBe('active');
        });
    });

    describe('calculateNextRetry()', function () {
        it('schedules first retry after 1 day', function () {
            Carbon::setTestNow('2025-01-15 12:00:00');

            $nextRetry = $this->service->calculateNextRetry(0);

            expect($nextRetry->toDateString())->toBe('2025-01-16');

            Carbon::setTestNow();
        });

        it('schedules second retry after 3 days', function () {
            Carbon::setTestNow('2025-01-15 12:00:00');

            $nextRetry = $this->service->calculateNextRetry(1);

            expect($nextRetry->toDateString())->toBe('2025-01-18');

            Carbon::setTestNow();
        });

        it('schedules third retry after 7 days', function () {
            Carbon::setTestNow('2025-01-15 12:00:00');

            $nextRetry = $this->service->calculateNextRetry(2);

            expect($nextRetry->toDateString())->toBe('2025-01-22');

            Carbon::setTestNow();
        });

        it('returns null after max retries', function () {
            $nextRetry = $this->service->calculateNextRetry(3);

            expect($nextRetry)->toBeNull();
        });
    });

    describe('getInvoicesDueForRetry()', function () {
        it('returns invoices with scheduled retry in the past', function () {
            Invoice::create([
                'workspace_id' => $this->workspace->id,
                'invoice_number' => 'INV-2025-0007',
                'status' => 'overdue',
                'auto_charge' => true,
                'subtotal' => 19.00,
                'total' => 19.00,
                'amount_due' => 19.00,
                'currency' => 'GBP',
                'issue_date' => now(),
                'due_date' => now()->subDays(7),
                'next_charge_attempt' => now()->subHour(),
            ]);

            $invoices = $this->service->getInvoicesDueForRetry();

            expect($invoices)->toHaveCount(1);
        });

        it('does not return invoices with future retry date', function () {
            Invoice::create([
                'workspace_id' => $this->workspace->id,
                'invoice_number' => 'INV-2025-0008',
                'status' => 'overdue',
                'auto_charge' => true,
                'subtotal' => 19.00,
                'total' => 19.00,
                'amount_due' => 19.00,
                'currency' => 'GBP',
                'issue_date' => now(),
                'due_date' => now()->subDays(7),
                'next_charge_attempt' => now()->addHour(),
            ]);

            $invoices = $this->service->getInvoicesDueForRetry();

            expect($invoices)->toHaveCount(0);
        });

        it('does not return paid invoices', function () {
            Invoice::create([
                'workspace_id' => $this->workspace->id,
                'invoice_number' => 'INV-2025-0009',
                'status' => 'paid',
                'auto_charge' => true,
                'subtotal' => 19.00,
                'total' => 19.00,
                'amount_due' => 0,
                'amount_paid' => 19.00,
                'currency' => 'GBP',
                'issue_date' => now(),
                'due_date' => now()->subDays(7),
                'next_charge_attempt' => now()->subHour(),
            ]);

            $invoices = $this->service->getInvoicesDueForRetry();

            expect($invoices)->toHaveCount(0);
        });
    });

    describe('getSubscriptionsForPause()', function () {
        it('returns past due subscriptions after retry period exhausted', function () {
            // Subscription is past due
            $this->subscription->update(['status' => 'past_due']);

            // Invoice has exhausted retries (last attempt > sum of retry days)
            Invoice::create([
                'workspace_id' => $this->workspace->id,
                'invoice_number' => 'INV-2025-0010',
                'status' => 'overdue',
                'auto_charge' => true,
                'subtotal' => 19.00,
                'total' => 19.00,
                'amount_due' => 19.00,
                'currency' => 'GBP',
                'issue_date' => now()->subDays(20),
                'due_date' => now()->subDays(20),
                'charge_attempts' => 3,
                'last_charge_attempt' => now()->subDays(15), // More than 11 days (1+3+7) + 1
                'next_charge_attempt' => null, // No more retries
            ]);

            $subscriptions = $this->service->getSubscriptionsForPause();

            expect($subscriptions)->toHaveCount(1)
                ->and($subscriptions->first()->id)->toBe($this->subscription->id);
        });

        it('does not return already paused subscriptions', function () {
            $this->subscription->update([
                'status' => 'paused',
                'paused_at' => now()->subDays(5),
            ]);

            Invoice::create([
                'workspace_id' => $this->workspace->id,
                'invoice_number' => 'INV-2025-0011',
                'status' => 'overdue',
                'auto_charge' => true,
                'subtotal' => 19.00,
                'total' => 19.00,
                'amount_due' => 19.00,
                'currency' => 'GBP',
                'issue_date' => now()->subDays(20),
                'due_date' => now()->subDays(20),
                'last_charge_attempt' => now()->subDays(15),
                'next_charge_attempt' => null,
            ]);

            $subscriptions = $this->service->getSubscriptionsForPause();

            expect($subscriptions)->toHaveCount(0);
        });
    });

    describe('pauseSubscription()', function () {
        it('pauses subscription and sends notification', function () {
            $this->service->pauseSubscription($this->subscription);

            $this->subscription->refresh();
            expect($this->subscription->status)->toBe('paused')
                ->and($this->subscription->paused_at)->not->toBeNull();

            Notification::assertSentTo($this->user, SubscriptionPaused::class);
        });
    });

    describe('getSubscriptionsForSuspension()', function () {
        it('returns paused subscriptions after suspend threshold', function () {
            $this->subscription->update([
                'status' => 'paused',
                'paused_at' => now()->subDays(15), // More than 14 days
            ]);

            $subscriptions = $this->service->getSubscriptionsForSuspension();

            expect($subscriptions)->toHaveCount(1);
        });

        it('does not return recently paused subscriptions', function () {
            $this->subscription->update([
                'status' => 'paused',
                'paused_at' => now()->subDays(5), // Less than 14 days
            ]);

            $subscriptions = $this->service->getSubscriptionsForSuspension();

            expect($subscriptions)->toHaveCount(0);
        });
    });

    describe('suspendWorkspace()', function () {
        it('suspends workspace and sends notification', function () {
            $this->subscription->update([
                'status' => 'paused',
                'paused_at' => now()->subDays(15),
            ]);

            $this->service->suspendWorkspace($this->subscription);

            // Verify entitlement service was called (workspace package should be suspended)
            $this->workspacePackage->refresh();
            expect($this->workspacePackage->status)->toBe('suspended');

            Notification::assertSentTo($this->user, AccountSuspended::class);
        });
    });

    describe('getSubscriptionsForCancellation()', function () {
        it('returns paused subscriptions after cancel threshold', function () {
            $this->subscription->update([
                'status' => 'paused',
                'paused_at' => now()->subDays(31), // More than 30 days
            ]);

            $subscriptions = $this->service->getSubscriptionsForCancellation();

            expect($subscriptions)->toHaveCount(1);
        });

        it('does not return recently paused subscriptions', function () {
            $this->subscription->update([
                'status' => 'paused',
                'paused_at' => now()->subDays(20), // Less than 30 days
            ]);

            $subscriptions = $this->service->getSubscriptionsForCancellation();

            expect($subscriptions)->toHaveCount(0);
        });
    });

    describe('cancelSubscription()', function () {
        it('cancels and expires subscription', function () {
            $this->subscription->update([
                'status' => 'paused',
                'paused_at' => now()->subDays(31),
            ]);

            $this->service->cancelSubscription($this->subscription);

            $this->subscription->refresh();
            expect($this->subscription->status)->toBe('expired')
                ->and($this->subscription->cancelled_at)->not->toBeNull()
                ->and($this->subscription->cancellation_reason)->toBe('Non-payment')
                ->and($this->subscription->ended_at)->not->toBeNull();
        });

        it('sends cancellation notification', function () {
            $this->subscription->update([
                'status' => 'paused',
                'paused_at' => now()->subDays(31),
            ]);

            $this->service->cancelSubscription($this->subscription);

            Notification::assertSentTo($this->user, SubscriptionCancelled::class);
        });
    });

    describe('calculateInitialRetry()', function () {
        it('respects initial_grace_hours config', function () {
            Carbon::setTestNow('2025-01-15 12:00:00');
            config(['commerce.dunning.initial_grace_hours' => 48]);

            $nextRetry = $this->service->calculateInitialRetry();

            // 48 hours = 2 days from now
            expect($nextRetry->toDateString())->toBe('2025-01-17');

            Carbon::setTestNow();
            config(['commerce.dunning.initial_grace_hours' => 24]); // Reset
        });

        it('uses retry_days if longer than grace period', function () {
            Carbon::setTestNow('2025-01-15 12:00:00');
            config(['commerce.dunning.initial_grace_hours' => 12]); // 0.5 days
            config(['commerce.dunning.retry_days' => [2, 4, 7]]); // First retry at 2 days

            $nextRetry = $this->service->calculateInitialRetry();

            // Should use 2 days (retry_days[0]) since it's longer than 12 hours
            expect($nextRetry->toDateString())->toBe('2025-01-17');

            Carbon::setTestNow();
            config(['commerce.dunning.initial_grace_hours' => 24]); // Reset
            config(['commerce.dunning.retry_days' => [1, 3, 7]]); // Reset
        });
    });

    describe('getDunningStatus()', function () {
        it('returns none status when no overdue invoices', function () {
            $status = $this->service->getDunningStatus($this->subscription);

            expect($status['stage'])->toBe('none')
                ->and($status['days_overdue'])->toBe(0)
                ->and($status['next_action'])->toBe('none');
        });

        it('returns retry status for active subscription with overdue invoice', function () {
            Invoice::create([
                'workspace_id' => $this->workspace->id,
                'invoice_number' => 'INV-2025-0012',
                'status' => 'overdue',
                'auto_charge' => true,
                'subtotal' => 19.00,
                'total' => 19.00,
                'amount_due' => 19.00,
                'currency' => 'GBP',
                'issue_date' => now()->subDays(10),
                'due_date' => now()->subDays(5),
                'next_charge_attempt' => now()->addDays(2),
            ]);

            $status = $this->service->getDunningStatus($this->subscription);

            expect($status['stage'])->toBe('retry')
                ->and($status['days_overdue'])->toBe(5)
                ->and($status['next_action'])->toBe('retry');
        });

        it('returns paused status for paused subscription', function () {
            $this->subscription->update([
                'status' => 'paused',
                'paused_at' => now()->subDays(5),
            ]);

            Invoice::create([
                'workspace_id' => $this->workspace->id,
                'invoice_number' => 'INV-2025-0013',
                'status' => 'overdue',
                'auto_charge' => true,
                'subtotal' => 19.00,
                'total' => 19.00,
                'amount_due' => 19.00,
                'currency' => 'GBP',
                'issue_date' => now()->subDays(15),
                'due_date' => now()->subDays(10),
            ]);

            $status = $this->service->getDunningStatus($this->subscription);

            expect($status['stage'])->toBe('paused')
                ->and($status['next_action'])->toBe('suspend');
        });

        it('returns suspended status for long-paused subscription', function () {
            $this->subscription->update([
                'status' => 'paused',
                'paused_at' => now()->subDays(20), // More than 14 days
            ]);

            Invoice::create([
                'workspace_id' => $this->workspace->id,
                'invoice_number' => 'INV-2025-0014',
                'status' => 'overdue',
                'auto_charge' => true,
                'subtotal' => 19.00,
                'total' => 19.00,
                'amount_due' => 19.00,
                'currency' => 'GBP',
                'issue_date' => now()->subDays(25),
                'due_date' => now()->subDays(20),
            ]);

            $status = $this->service->getDunningStatus($this->subscription);

            expect($status['stage'])->toBe('suspended')
                ->and($status['next_action'])->toBe('cancel');
        });
    });
});
