<?php

declare(strict_types=1);

use Core\Mod\Commerce\Models\Payment;
use Core\Mod\Commerce\Models\Refund;
use Core\Mod\Commerce\Notifications\RefundProcessed;
use Core\Mod\Commerce\Services\CommerceService;
use Core\Mod\Commerce\Services\PaymentGateway\PaymentGatewayContract;
use Core\Mod\Commerce\Services\RefundService;
use Core\Tenant\Models\User;
use Core\Tenant\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    Notification::fake();

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create();
    $this->workspace->users()->attach($this->user->id, [
        'role' => 'owner',
        'is_default' => true,
    ]);

    // Create a successful payment
    $this->payment = Payment::create([
        'workspace_id' => $this->workspace->id,
        'gateway' => 'stripe',
        'gateway_payment_id' => 'pi_test_123',
        'amount' => 100.00,
        'fee' => 0,
        'net_amount' => 100.00,
        'refunded_amount' => 0,
        'currency' => 'GBP',
        'status' => 'succeeded',
        'paid_at' => now(),
    ]);

    // Mock the gateway
    $mockGateway = Mockery::mock(PaymentGatewayContract::class);
    $mockGateway->shouldReceive('refund')->andReturn([
        'success' => true,
        'refund_id' => 're_test_123',
    ]);

    $mockCommerce = Mockery::mock(CommerceService::class);
    $mockCommerce->shouldReceive('getGateway')->andReturn($mockGateway);

    $this->service = new RefundService($mockCommerce);
});

afterEach(function () {
    Mockery::close();
});

describe('RefundService', function () {
    describe('refund() method', function () {
        it('processes a partial refund', function () {
            $refund = $this->service->refund(
                $this->payment,
                50.00,
                'requested_by_customer',
                'Customer changed mind'
            );

            expect($refund)->toBeInstanceOf(Refund::class)
                ->and((float) $refund->amount)->toBe(50.00)
                ->and($refund->status)->toBe('succeeded')
                ->and($refund->reason)->toBe('requested_by_customer')
                ->and($refund->notes)->toBe('Customer changed mind');
        });

        it('sends notification on successful refund', function () {
            $this->service->refund($this->payment, 50.00);

            Notification::assertSentTo(
                $this->user,
                RefundProcessed::class
            );
        });

        it('records who initiated the refund', function () {
            $admin = User::factory()->create();

            $refund = $this->service->refund(
                $this->payment,
                50.00,
                initiatedBy: $admin
            );

            expect($refund->initiated_by)->toBe($admin->id);
        });

        it('throws exception for refund exceeding available amount', function () {
            expect(fn () => $this->service->refund($this->payment, 150.00))
                ->toThrow(InvalidArgumentException::class, 'exceeds maximum refundable');
        });

        it('throws exception for zero or negative amount', function () {
            expect(fn () => $this->service->refund($this->payment, 0))
                ->toThrow(InvalidArgumentException::class, 'greater than zero');

            expect(fn () => $this->service->refund($this->payment, -50.00))
                ->toThrow(InvalidArgumentException::class, 'greater than zero');
        });

        it('throws exception for non-succeeded payments', function () {
            $pendingPayment = Payment::create([
                'workspace_id' => $this->workspace->id,
                'gateway' => 'stripe',
                'gateway_payment_id' => 'pi_pending_123',
                'amount' => 100.00,
                'currency' => 'GBP',
                'status' => 'pending',
            ]);

            expect(fn () => $this->service->refund($pendingPayment, 50.00))
                ->toThrow(InvalidArgumentException::class, 'only refund successful payments');
        });

        it('allows multiple partial refunds up to full amount', function () {
            // First refund
            $refund1 = $this->service->refund($this->payment, 30.00);
            $this->payment->refresh();

            // Second refund
            $refund2 = $this->service->refund($this->payment, 40.00);
            $this->payment->refresh();

            // Third refund for remaining
            $refund3 = $this->service->refund($this->payment, 30.00);

            expect((float) $refund1->amount)->toBe(30.00)
                ->and((float) $refund2->amount)->toBe(40.00)
                ->and((float) $refund3->amount)->toBe(30.00);
        });
    });

    describe('refundFull() method', function () {
        it('refunds the full payment amount', function () {
            $refund = $this->service->refundFull($this->payment);

            expect((float) $refund->amount)->toBe(100.00);
        });

        it('refunds remaining amount after partial refund', function () {
            // Partial refund first
            $this->service->refund($this->payment, 40.00);
            $this->payment->refresh();

            // Full refund of remainder
            $refund = $this->service->refundFull($this->payment);

            expect((float) $refund->amount)->toBe(60.00);
        });
    });

    describe('canRefund() method', function () {
        it('returns true for refundable payment', function () {
            expect($this->service->canRefund($this->payment))->toBeTrue();
        });

        it('returns false for pending payment', function () {
            $this->payment->update(['status' => 'pending']);

            expect($this->service->canRefund($this->payment))->toBeFalse();
        });

        it('returns false for fully refunded payment', function () {
            $this->payment->update(['refunded_amount' => 100.00, 'status' => 'refunded']);

            expect($this->service->canRefund($this->payment))->toBeFalse();
        });

        it('returns false for payment outside refund window', function () {
            // Force update created_at directly to bypass timestamp protection
            Payment::withoutTimestamps(function () {
                $this->payment->created_at = now()->subDays(200);
                $this->payment->save();
            });
            $this->payment->refresh();

            expect($this->service->canRefund($this->payment))->toBeFalse();
        });
    });

    describe('getMaxRefundableAmount() method', function () {
        it('returns full amount for unrefunded payment', function () {
            expect($this->service->getMaxRefundableAmount($this->payment))->toBe(100.00);
        });

        it('returns remaining amount after partial refund', function () {
            $this->payment->update(['refunded_amount' => 40.00]);
            $this->payment->refresh();

            expect($this->service->getMaxRefundableAmount($this->payment))->toBe(60.00);
        });

        it('returns zero for fully refunded payment', function () {
            $this->payment->update(['refunded_amount' => 100.00]);
            $this->payment->refresh();

            expect($this->service->getMaxRefundableAmount($this->payment))->toBe(0.00);
        });
    });

    describe('getRefundsForPayment() method', function () {
        it('returns all refunds for a payment', function () {
            // Create some refunds directly
            Refund::create([
                'payment_id' => $this->payment->id,
                'amount' => 25.00,
                'currency' => 'GBP',
                'status' => 'succeeded',
                'reason' => 'requested_by_customer',
            ]);

            Refund::create([
                'payment_id' => $this->payment->id,
                'amount' => 25.00,
                'currency' => 'GBP',
                'status' => 'succeeded',
                'reason' => 'duplicate',
            ]);

            $refunds = $this->service->getRefundsForPayment($this->payment);

            expect($refunds)->toHaveCount(2);
        });
    });
});

describe('Refund model', function () {
    it('marks refund as succeeded', function () {
        $refund = Refund::create([
            'payment_id' => $this->payment->id,
            'amount' => 50.00,
            'currency' => 'GBP',
            'status' => 'pending',
            'reason' => 'requested_by_customer',
        ]);

        $refund->markAsSucceeded('re_test_456');

        expect($refund->status)->toBe('succeeded')
            ->and($refund->gateway_refund_id)->toBe('re_test_456');

        // Check payment refunded_amount was updated
        $this->payment->refresh();
        expect((float) $this->payment->refunded_amount)->toBe(50.00);
    });

    it('marks refund as failed', function () {
        $refund = Refund::create([
            'payment_id' => $this->payment->id,
            'amount' => 50.00,
            'currency' => 'GBP',
            'status' => 'pending',
            'reason' => 'requested_by_customer',
        ]);

        $refund->markAsFailed(['error' => 'Insufficient funds']);

        expect($refund->status)->toBe('failed')
            ->and($refund->gateway_response)->toMatchArray(['error' => 'Insufficient funds']);
    });

    it('gets human-readable reason label', function () {
        $refund = Refund::create([
            'payment_id' => $this->payment->id,
            'amount' => 50.00,
            'currency' => 'GBP',
            'status' => 'succeeded',
            'reason' => 'requested_by_customer',
        ]);

        expect($refund->getReasonLabel())->toBe('Customer request');
    });
});
