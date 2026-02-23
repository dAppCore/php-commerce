<?php

/**
 * UseCase: Commerce Admin CRUD (Basic Flow)
 *
 * Acceptance test for the Commerce admin panel.
 * Tests the happy path user journey for products, orders, and subscriptions.
 */

use Core\Tenant\Models\User;
use Core\Tenant\Models\Workspace;

describe('Commerce Admin Dashboard', function () {
    beforeEach(function () {
        // Create admin user with workspace
        $this->user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'is_admin' => true,
        ]);

        $this->workspace = Workspace::factory()->create();
        $this->workspace->users()->attach($this->user->id, [
            'role' => 'owner',
            'is_default' => true,
        ]);
    });

    it('can view the commerce dashboard with all sections', function () {
        // Login as admin
        $this->actingAs($this->user);

        $response = $this->get(route('hub.commerce.dashboard'));

        $response->assertOk()
            ->assertSee(__('commerce::commerce.dashboard.title'))
            ->assertSee(__('commerce::commerce.dashboard.subtitle'))
            ->assertSee(__('commerce::commerce.actions.view_orders'))
            ->assertSee(__('commerce::commerce.sections.quick_actions'))
            ->assertSee(__('commerce::commerce.sections.recent_orders'));
    });
});

describe('Commerce Product Management', function () {
    beforeEach(function () {
        $this->user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'is_admin' => true,
        ]);

        $this->workspace = Workspace::factory()->create();
        $this->workspace->users()->attach($this->user->id, [
            'role' => 'owner',
            'is_default' => true,
        ]);
    });

    it('can view the product catalog page', function () {
        $this->actingAs($this->user);

        $response = $this->get(route('hub.commerce.products'));

        $response->assertOk()
            ->assertSee(__('commerce::commerce.products.title'))
            ->assertSee(__('commerce::commerce.actions.add_product'));
    });

    it('can see product modal labels', function () {
        $this->actingAs($this->user);

        // Test that the translation keys exist and are correct
        expect(__('commerce::commerce.products.modal.create_title'))->toBe('Create Product');
        expect(__('commerce::commerce.products.modal.edit_title'))->toBe('Edit Product');
        expect(__('commerce::commerce.form.sku'))->toBe('SKU');
        expect(__('commerce::commerce.form.type'))->toBe('Type');
        expect(__('commerce::commerce.form.name'))->toBe('Name');
        expect(__('commerce::commerce.form.description'))->toBe('Description');
        expect(__('commerce::commerce.form.category'))->toBe('Category');
        expect(__('commerce::commerce.form.subcategory'))->toBe('Subcategory');
        expect(__('commerce::commerce.form.price'))->toBe('Price (pence)');
        expect(__('commerce::commerce.form.cost_price'))->toBe('Cost Price');
        expect(__('commerce::commerce.form.rrp'))->toBe('RRP');
        expect(__('commerce::commerce.form.stock_quantity'))->toBe('Stock Quantity');
        expect(__('commerce::commerce.form.low_stock_threshold'))->toBe('Low Stock Threshold');
        expect(__('commerce::commerce.form.tax_class'))->toBe('Tax Class');
    });
});

describe('Commerce Order Management', function () {
    beforeEach(function () {
        $this->user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'is_admin' => true,
        ]);

        $this->workspace = Workspace::factory()->create();
        $this->workspace->users()->attach($this->user->id, [
            'role' => 'owner',
            'is_default' => true,
        ]);
    });

    it('can view the orders page', function () {
        $this->actingAs($this->user);

        $response = $this->get(route('hub.commerce.orders'));

        $response->assertOk()
            ->assertSee(__('commerce::commerce.orders.title'))
            ->assertSee(__('commerce::commerce.orders.subtitle'))
            ->assertSee(__('commerce::commerce.orders.empty'));
    });

    it('has correct order detail modal labels', function () {
        expect(__('commerce::commerce.orders.detail.status'))->toBe('Status');
        expect(__('commerce::commerce.orders.detail.type'))->toBe('Type');
        expect(__('commerce::commerce.orders.detail.payment_gateway'))->toBe('Payment Gateway');
        expect(__('commerce::commerce.orders.detail.paid_at'))->toBe('Paid At');
        expect(__('commerce::commerce.orders.detail.customer'))->toBe('Customer');
        expect(__('commerce::commerce.orders.detail.items'))->toBe('Items');
        expect(__('commerce::commerce.orders.detail.subtotal'))->toBe('Subtotal');
        expect(__('commerce::commerce.orders.detail.discount'))->toBe('Discount');
        expect(__('commerce::commerce.orders.detail.tax'))->toBe('Tax');
        expect(__('commerce::commerce.orders.detail.total'))->toBe('Total');
    });
});

describe('Commerce Subscription Management', function () {
    beforeEach(function () {
        $this->user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'is_admin' => true,
        ]);

        $this->workspace = Workspace::factory()->create();
        $this->workspace->users()->attach($this->user->id, [
            'role' => 'owner',
            'is_default' => true,
        ]);
    });

    it('can view the subscriptions page', function () {
        $this->actingAs($this->user);

        $response = $this->get(route('hub.commerce.subscriptions'));

        $response->assertOk()
            ->assertSee(__('commerce::commerce.subscriptions.title'))
            ->assertSee(__('commerce::commerce.subscriptions.subtitle'))
            ->assertSee(__('commerce::commerce.subscriptions.empty'));
    });

    it('has correct subscription modal labels', function () {
        expect(__('commerce::commerce.subscriptions.detail.title'))->toBe('Subscription Details');
        expect(__('commerce::commerce.subscriptions.detail.status'))->toBe('Status');
        expect(__('commerce::commerce.subscriptions.detail.gateway'))->toBe('Gateway');
        expect(__('commerce::commerce.subscriptions.detail.billing_cycle'))->toBe('Billing Cycle');
        expect(__('commerce::commerce.subscriptions.detail.workspace'))->toBe('Workspace');
        expect(__('commerce::commerce.subscriptions.detail.package'))->toBe('Package');
        expect(__('commerce::commerce.subscriptions.detail.current_period'))->toBe('Current Period');
        expect(__('commerce::commerce.subscriptions.update_status.title'))->toBe('Update Subscription Status');
        expect(__('commerce::commerce.subscriptions.extend.title'))->toBe('Extend Subscription Period');
    });
});

describe('Commerce Coupon Management', function () {
    beforeEach(function () {
        $this->user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'is_admin' => true,
        ]);

        $this->workspace = Workspace::factory()->create();
        $this->workspace->users()->attach($this->user->id, [
            'role' => 'owner',
            'is_default' => true,
        ]);
    });

    it('can view the coupons page', function () {
        $this->actingAs($this->user);

        $response = $this->get(route('hub.commerce.coupons'));

        $response->assertOk()
            ->assertSee(__('commerce::commerce.coupons.title'))
            ->assertSee(__('commerce::commerce.coupons.subtitle'))
            ->assertSee(__('commerce::commerce.actions.new_coupon'))
            ->assertSee(__('commerce::commerce.coupons.empty'));
    });

    it('has correct coupon form labels', function () {
        expect(__('commerce::commerce.coupons.modal.create_title'))->toBe('Create Coupon');
        expect(__('commerce::commerce.coupons.modal.edit_title'))->toBe('Edit Coupon');
        expect(__('commerce::commerce.coupons.form.code'))->toBe('Code');
        expect(__('commerce::commerce.coupons.form.name'))->toBe('Name');
        expect(__('commerce::commerce.coupons.form.description'))->toBe('Description (optional)');
        expect(__('commerce::commerce.coupons.form.discount_type'))->toBe('Discount Type');
        expect(__('commerce::commerce.coupons.form.percentage'))->toBe('Percentage (%)');
        expect(__('commerce::commerce.coupons.form.fixed_amount'))->toBe('Fixed amount (GBP)');
    });
});
