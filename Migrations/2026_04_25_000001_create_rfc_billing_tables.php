<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            Schema::create('orders', function (Blueprint $table): void {
                $table->id();
                $table->nullableMorphs('orderable');
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('order_number')->unique();
                $table->string('status')->default('pending')->index();
                $table->string('type')->nullable();
                $table->string('billing_cycle')->nullable();
                $table->string('currency', 3)->default('GBP');
                $table->string('display_currency', 3)->nullable();
                $table->decimal('exchange_rate_used', 16, 8)->nullable();
                $table->decimal('base_currency_total', 12, 2)->nullable();
                $table->decimal('subtotal', 12, 2)->default(0);
                $table->decimal('tax_amount', 12, 2)->default(0);
                $table->decimal('discount_amount', 12, 2)->default(0);
                $table->decimal('total', 12, 2)->default(0);
                $table->string('payment_method')->nullable();
                $table->unsignedBigInteger('payment_method_id')->nullable()->index();
                $table->string('payment_gateway')->nullable();
                $table->string('gateway')->nullable();
                $table->string('gateway_order_id')->nullable();
                $table->string('gateway_session_id')->nullable();
                $table->unsignedBigInteger('coupon_id')->nullable()->index();
                $table->string('billing_name')->nullable();
                $table->string('billing_email')->nullable();
                $table->decimal('tax_rate', 8, 4)->nullable();
                $table->string('tax_country', 2)->nullable();
                $table->json('billing_address')->nullable();
                $table->json('metadata')->nullable();
                $table->string('idempotency_key')->nullable()->unique();
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('order_items')) {
            Schema::create('order_items', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('order_id')->index();
                $table->unsignedBigInteger('product_id')->nullable()->index();
                $table->string('item_type')->nullable();
                $table->unsignedBigInteger('item_id')->nullable();
                $table->string('item_code')->nullable();
                $table->string('description');
                $table->unsignedInteger('quantity')->default(1);
                $table->decimal('unit_price', 12, 2)->default(0);
                $table->decimal('line_total', 12, 2)->default(0);
                $table->decimal('total', 12, 2)->default(0);
                $table->string('billing_cycle')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('invoices')) {
            Schema::create('invoices', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('workspace_id')->nullable()->index();
                $table->unsignedBigInteger('order_id')->nullable()->index();
                $table->unsignedBigInteger('payment_id')->nullable()->index();
                $table->string('invoice_number')->unique();
                $table->string('number')->nullable()->index();
                $table->string('status')->default('pending')->index();
                $table->string('currency', 3)->default('GBP');
                $table->string('display_currency', 3)->nullable();
                $table->decimal('exchange_rate_used', 16, 8)->nullable();
                $table->decimal('base_currency_total', 12, 2)->nullable();
                $table->decimal('subtotal', 12, 2)->default(0);
                $table->decimal('tax_amount', 12, 2)->default(0);
                $table->decimal('tax_rate', 8, 4)->nullable();
                $table->string('tax_country', 2)->nullable();
                $table->decimal('discount_amount', 12, 2)->default(0);
                $table->decimal('total', 12, 2)->default(0);
                $table->decimal('amount_paid', 12, 2)->default(0);
                $table->decimal('amount_due', 12, 2)->default(0);
                $table->date('issue_date')->nullable();
                $table->date('due_date')->nullable();
                $table->timestamp('due_at')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->string('billing_name')->nullable();
                $table->string('billing_email')->nullable();
                $table->json('billing_address')->nullable();
                $table->string('tax_id')->nullable();
                $table->string('pdf_path')->nullable();
                $table->boolean('auto_charge')->default(true);
                $table->unsignedInteger('charge_attempts')->default(0);
                $table->timestamp('last_charge_attempt')->nullable();
                $table->timestamp('next_charge_attempt')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('invoice_items')) {
            Schema::create('invoice_items', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('invoice_id')->index();
                $table->unsignedBigInteger('order_item_id')->nullable()->index();
                $table->string('description');
                $table->unsignedInteger('quantity')->default(1);
                $table->decimal('unit_price', 12, 2)->default(0);
                $table->decimal('line_total', 12, 2)->default(0);
                $table->decimal('total', 12, 2)->default(0);
                $table->boolean('taxable')->default(true);
                $table->decimal('tax_rate', 8, 4)->default(0);
                $table->decimal('tax_amount', 12, 2)->default(0);
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('payments')) {
            Schema::create('payments', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('workspace_id')->nullable()->index();
                $table->unsignedBigInteger('invoice_id')->nullable()->index();
                $table->unsignedBigInteger('order_id')->nullable()->index();
                $table->unsignedBigInteger('payment_method_id')->nullable()->index();
                $table->string('gateway', 32)->index();
                $table->string('gateway_payment_id')->nullable()->index();
                $table->string('gateway_customer_id')->nullable();
                $table->string('gateway_id')->nullable()->index();
                $table->string('currency', 3)->default('GBP');
                $table->decimal('amount', 12, 2)->default(0);
                $table->decimal('fee', 12, 2)->default(0);
                $table->decimal('net_amount', 12, 2)->default(0);
                $table->string('status')->default('pending')->index();
                $table->string('failure_reason')->nullable();
                $table->string('payment_method_type')->nullable();
                $table->string('payment_method_last4')->nullable();
                $table->string('payment_method_brand')->nullable();
                $table->json('gateway_response')->nullable();
                $table->decimal('refunded_amount', 12, 2)->default(0);
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('subscriptions')) {
            Schema::create('subscriptions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('workspace_id')->nullable()->index();
                $table->unsignedBigInteger('workspace_package_id')->nullable()->index();
                $table->unsignedBigInteger('product_id')->nullable()->index();
                $table->string('gateway')->default('btcpay');
                $table->string('gateway_subscription_id')->nullable()->index();
                $table->string('gateway_customer_id')->nullable();
                $table->string('gateway_price_id')->nullable();
                $table->string('status')->default('active')->index();
                $table->string('billing_cycle')->default('monthly');
                $table->timestamp('current_period_start')->nullable();
                $table->timestamp('current_period_end')->nullable()->index();
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
        }

        if (! Schema::hasTable('refunds')) {
            Schema::create('refunds', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('payment_id')->index();
                $table->string('gateway_refund_id')->nullable()->index();
                $table->decimal('amount', 12, 2);
                $table->string('currency', 3)->default('GBP');
                $table->string('status')->default('pending')->index();
                $table->string('reason')->nullable();
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('initiated_by')->nullable()->index();
                $table->json('gateway_response')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('coupons')) {
            Schema::create('coupons', function (Blueprint $table): void {
                $table->id();
                $table->string('code')->unique();
                $table->string('name')->nullable();
                $table->text('description')->nullable();
                $table->string('type')->default('percent');
                $table->decimal('value', 12, 2)->default(0);
                $table->decimal('min_amount', 12, 2)->nullable();
                $table->decimal('max_discount', 12, 2)->nullable();
                $table->string('applies_to')->default('all');
                $table->json('package_ids')->nullable();
                $table->unsignedInteger('max_uses')->nullable();
                $table->unsignedInteger('max_uses_per_workspace')->default(1);
                $table->unsignedInteger('used_count')->default(0);
                $table->string('duration')->default('once');
                $table->unsignedInteger('duration_months')->nullable();
                $table->timestamp('valid_from')->nullable();
                $table->timestamp('valid_until')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->boolean('is_active')->default(true);
                $table->string('stripe_coupon_id')->nullable();
                $table->string('btcpay_coupon_id')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('coupon_usages')) {
            Schema::create('coupon_usages', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('coupon_id')->index();
                $table->unsignedBigInteger('workspace_id')->nullable()->index();
                $table->unsignedBigInteger('order_id')->nullable()->index();
                $table->decimal('discount_amount', 12, 2)->default(0);
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('tax_rates')) {
            Schema::create('tax_rates', function (Blueprint $table): void {
                $table->id();
                $table->string('country_code', 2)->index();
                $table->string('country', 2)->nullable()->index();
                $table->string('state_code')->nullable();
                $table->string('region')->nullable();
                $table->string('name');
                $table->string('type')->default('vat');
                $table->decimal('rate', 8, 4);
                $table->boolean('is_digital_services')->default(true);
                $table->date('effective_from');
                $table->date('effective_until')->nullable();
                $table->boolean('is_active')->default(true);
                $table->string('stripe_tax_rate_id')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('commerce_inventory_movements')) {
            Schema::create('commerce_inventory_movements', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('inventory_id')->nullable()->index();
                $table->unsignedBigInteger('product_id')->index();
                $table->unsignedBigInteger('warehouse_id')->index();
                $table->string('type')->index();
                $table->integer('quantity');
                $table->integer('balance_after')->default(0);
                $table->string('reference')->nullable()->index();
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->integer('unit_cost')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_inventory_movements');
        Schema::dropIfExists('tax_rates');
        Schema::dropIfExists('coupon_usages');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
