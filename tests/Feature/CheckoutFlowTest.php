<?php

use Core\Mod\Commerce\Models\Invoice;
use Core\Mod\Commerce\Models\Order;
use Core\Mod\Commerce\Models\Payment;
use Core\Mod\Commerce\Services\CommerceService;
use Core\Mod\Commerce\Services\PaymentGateway\PaymentGatewayContract;
use Core\Tenant\Models\Package;
use Core\Tenant\Models\User;
use Core\Tenant\Models\Workspace;
use Core\Tenant\Models\WorkspacePackage;
use Illuminate\Support\Facades\Cache;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();

    $this->user = User::factory()->create([
        'email' => 'test@example.com',
    ]);
    $this->workspace = Workspace::factory()->create([
        'billing_email' => 'billing@example.com',
        'billing_name' => 'Test Company',
        'billing_country' => 'GB',
    ]);
    $this->workspace->users()->attach($this->user->id, [
        'role' => 'owner',
        'is_default' => true,
    ]);

    // Use existing seeded packages or create test package
    $this->package = Package::where('code', 'creator')->first();
    if (! $this->package) {
        $this->package = Package::create([
            'name' => 'Creator',
            'code' => 'creator',
            'description' => 'For creators',
            'monthly_price' => 19.00,
            'yearly_price' => 190.00,
            'is_active' => true,
        ]);
    }

    $this->service = app(CommerceService::class);
});

describe('Order Creation', function () {
    it('creates an order for a package purchase', function () {
        $order = $this->service->createOrder(
            $this->workspace,
            $this->package,
            'monthly'
        );

        expect($order)->toBeInstanceOf(Order::class)
            ->and($order->orderable_type)->toBe(Workspace::class)
            ->and($order->orderable_id)->toBe($this->workspace->id)
            ->and($order->status)->toBe('pending')
            ->and($order->billing_cycle)->toBe('monthly')
            ->and($order->billing_email)->toBe('billing@example.com')
            ->and($order->billing_name)->toBe('Test Company')
            ->and($order->order_number)->toStartWith('ORD-');
    });

    it('creates order items for package', function () {
        $order = $this->service->createOrder(
            $this->workspace,
            $this->package,
            'monthly'
        );

        expect($order->items)->toHaveCount(1);

        $item = $order->items->first();
        expect($item->item_type)->toBe('package')
            ->and($item->item_id)->toBe($this->package->id)
            ->and($item->item_code)->toBe($this->package->code)
            ->and($item->billing_cycle)->toBe('monthly');
    });

    it('calculates tax correctly for UK customer', function () {
        $order = $this->service->createOrder(
            $this->workspace,
            $this->package,
            'monthly'
        );

        // UK VAT is 20%
        expect($order->tax_country)->toBe('GB')
            ->and($order->tax_rate)->toBe(20.00)
            ->and((float) $order->tax_amount)->toBe(round(19.00 * 0.20, 2));
    });

    it('calculates total correctly', function () {
        $order = $this->service->createOrder(
            $this->workspace,
            $this->package,
            'monthly'
        );

        $expectedTotal = 19.00 + (19.00 * 0.20); // subtotal + tax
        expect((float) $order->total)->toBe(round($expectedTotal, 2));
    });

    it('creates order with yearly billing cycle', function () {
        $order = $this->service->createOrder(
            $this->workspace,
            $this->package,
            'yearly'
        );

        expect($order->billing_cycle)->toBe('yearly')
            ->and((float) $order->subtotal)->toBe(190.00);
    });
});

describe('Checkout Session Creation', function () {
    it('creates checkout session with mocked gateway', function () {
        // Create a mock gateway
        $mockGateway = Mockery::mock(PaymentGatewayContract::class);
        $mockGateway->shouldReceive('createCustomer')
            ->andReturn('cust_mock_123');
        $mockGateway->shouldReceive('createCheckoutSession')
            ->andReturn([
                'session_id' => 'cs_mock_session_123',
                'checkout_url' => 'https://checkout.example.com/session/123',
            ]);

        app()->instance('commerce.gateway.btcpay', $mockGateway);

        $order = $this->service->createOrder(
            $this->workspace,
            $this->package,
            'monthly'
        );

        $result = $this->service->createCheckout(
            $order,
            'btcpay',
            'https://example.com/success',
            'https://example.com/cancel'
        );

        expect($result)->toHaveKeys(['order', 'session_id', 'checkout_url'])
            ->and($result['session_id'])->toBe('cs_mock_session_123')
            ->and($result['checkout_url'])->toContain('checkout.example.com')
            ->and($result['order']->status)->toBe('processing');
    });
});

describe('Order Fulfilment', function () {
    it('fulfils order and provisions entitlements', function () {
        $order = $this->service->createOrder(
            $this->workspace,
            $this->package,
            'monthly'
        );

        // Create a payment
        $payment = Payment::create([
            'workspace_id' => $this->workspace->id,
            'order_id' => $order->id,
            'gateway' => 'btcpay',
            'gateway_payment_id' => 'pay_mock_123',
            'amount' => $order->total,
            'currency' => 'GBP',
            'status' => 'succeeded',
            'paid_at' => now(),
        ]);

        // Fulfil the order
        $this->service->fulfillOrder($order, $payment);

        $order->refresh();

        expect($order->status)->toBe('paid')
            ->and($order->paid_at)->not->toBeNull();

        // Check that workspace package was provisioned
        $workspacePackage = WorkspacePackage::where('workspace_id', $this->workspace->id)
            ->where('package_id', $this->package->id)
            ->first();

        expect($workspacePackage)->not->toBeNull()
            ->and($workspacePackage->status)->toBe('active');
    });

    it('creates invoice on fulfilment', function () {
        $order = $this->service->createOrder(
            $this->workspace,
            $this->package,
            'monthly'
        );

        $payment = Payment::create([
            'workspace_id' => $this->workspace->id,
            'order_id' => $order->id,
            'gateway' => 'btcpay',
            'gateway_payment_id' => 'pay_mock_456',
            'amount' => $order->total,
            'currency' => 'GBP',
            'status' => 'succeeded',
            'paid_at' => now(),
        ]);

        $this->service->fulfillOrder($order, $payment);

        $invoice = Invoice::where('order_id', $order->id)->first();

        expect($invoice)->not->toBeNull()
            ->and($invoice->invoice_number)->toStartWith('INV-')
            ->and((float) $invoice->total)->toBe((float) $order->total)
            ->and($invoice->status)->toBe('paid');
    });

    it('fails order with reason', function () {
        $order = $this->service->createOrder(
            $this->workspace,
            $this->package,
            'monthly'
        );

        $this->service->failOrder($order, 'Payment declined');

        $order->refresh();

        expect($order->status)->toBe('failed')
            ->and($order->metadata['failure_reason'])->toBe('Payment declined');
    });
});

describe('End-to-End Checkout Flow', function () {
    it('completes full checkout flow: cart to paid order', function () {
        // Step 1: Create order (simulates adding to cart and proceeding)
        $order = $this->service->createOrder(
            $this->workspace,
            $this->package,
            'monthly'
        );

        expect($order->status)->toBe('pending');

        // Step 2: Simulate checkout session creation (mocked gateway)
        $mockGateway = Mockery::mock(PaymentGatewayContract::class);
        $mockGateway->shouldReceive('createCustomer')
            ->andReturn('cust_e2e_123');
        $mockGateway->shouldReceive('createCheckoutSession')
            ->andReturn([
                'session_id' => 'cs_e2e_session',
                'checkout_url' => 'https://pay.example.com/checkout',
            ]);
        app()->instance('commerce.gateway.btcpay', $mockGateway);

        $checkout = $this->service->createCheckout($order, 'btcpay');
        $order->refresh();

        expect($order->status)->toBe('processing')
            ->and($order->gateway_session_id)->toBe('cs_e2e_session');

        // Step 3: Simulate payment completion (webhook would call this)
        $payment = Payment::create([
            'workspace_id' => $this->workspace->id,
            'order_id' => $order->id,
            'gateway' => 'btcpay',
            'gateway_payment_id' => 'pay_e2e_completed',
            'amount' => $order->total,
            'currency' => 'GBP',
            'status' => 'succeeded',
            'paid_at' => now(),
        ]);

        $this->service->fulfillOrder($order, $payment);

        // Step 4: Verify final state
        $order->refresh();
        $this->workspace->refresh();

        expect($order->status)->toBe('paid')
            ->and($order->paid_at)->not->toBeNull();

        // Verify invoice created
        $invoice = Invoice::where('order_id', $order->id)->first();
        expect($invoice)->not->toBeNull()
            ->and($invoice->status)->toBe('paid');

        // Verify entitlements provisioned
        $workspacePackage = $this->workspace->workspacePackages()
            ->where('package_id', $this->package->id)
            ->where('status', 'active')
            ->first();

        expect($workspacePackage)->not->toBeNull();
    });

    it('handles checkout cancellation', function () {
        $order = $this->service->createOrder(
            $this->workspace,
            $this->package,
            'monthly'
        );

        // Simulate user cancelling checkout
        $order->cancel();

        expect($order->status)->toBe('cancelled');

        // No entitlements should be provisioned
        $workspacePackage = $this->workspace->workspacePackages()
            ->where('package_id', $this->package->id)
            ->first();

        expect($workspacePackage)->toBeNull();
    });

    it('handles checkout expiry', function () {
        $order = $this->service->createOrder(
            $this->workspace,
            $this->package,
            'monthly'
        );

        // Simulate payment expiry (BTCPay invoice expired)
        $order->markAsFailed('Payment expired');

        expect($order->status)->toBe('failed')
            ->and($order->metadata['failure_reason'])->toBe('Payment expired');

        // No entitlements should be provisioned
        $workspacePackage = $this->workspace->workspacePackages()
            ->where('package_id', $this->package->id)
            ->first();

        expect($workspacePackage)->toBeNull();
    });
});

afterEach(function () {
    Mockery::close();
});
