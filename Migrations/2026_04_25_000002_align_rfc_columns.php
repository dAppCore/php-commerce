<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        $this->addColumn('subscriptions', 'product_id', fn (Blueprint $table) => $table->unsignedBigInteger('product_id')->nullable()->index());
        $this->addColumn('orders', 'payment_method_id', fn (Blueprint $table) => $table->unsignedBigInteger('payment_method_id')->nullable()->index());
        $this->addColumn('orders', 'gateway', fn (Blueprint $table) => $table->string('gateway')->nullable()->index());
        $this->addColumn('orders', 'gateway_session_id', fn (Blueprint $table) => $table->string('gateway_session_id')->nullable()->index());
        $this->addColumn('credit_notes', 'invoice_id', fn (Blueprint $table) => $table->unsignedBigInteger('invoice_id')->nullable()->index());
        $this->addColumn('permission_matrix', 'target_entity_id', fn (Blueprint $table) => $table->unsignedBigInteger('target_entity_id')->nullable()->index());
        $this->addColumn('permission_matrix', 'permissions', fn (Blueprint $table) => $table->json('permissions')->nullable());
        $this->addColumn('permission_requests', 'from_entity_id', fn (Blueprint $table) => $table->unsignedBigInteger('from_entity_id')->nullable()->index());
        $this->addColumn('permission_requests', 'to_entity_id', fn (Blueprint $table) => $table->unsignedBigInteger('to_entity_id')->nullable()->index());
        $this->addColumn('permission_requests', 'permissions', fn (Blueprint $table) => $table->json('permissions')->nullable());
        $this->addColumn('commerce_product_prices', 'billing_cycle', fn (Blueprint $table) => $table->string('billing_cycle')->nullable()->index());
        $this->addColumn('commerce_product_assignments', 'entity_type', fn (Blueprint $table) => $table->string('entity_type')->nullable()->index());
        $this->addColumn('commerce_bundle_hashes', 'product_ids', fn (Blueprint $table) => $table->json('product_ids')->nullable());
        $this->addColumn('commerce_warehouses', 'location', fn (Blueprint $table) => $table->string('location')->nullable());

        if (Schema::hasTable('commerce_products')) {
            $this->addColumn('commerce_products', 'owner_entity_id', fn (Blueprint $table) => $table->unsignedBigInteger('owner_entity_id')->nullable()->index());
            $this->addColumn('commerce_products', 'price', fn (Blueprint $table) => $table->integer('price')->default(0));
            $this->addColumn('commerce_products', 'is_active', fn (Blueprint $table) => $table->boolean('is_active')->default(true)->index());
            $this->addColumn('commerce_products', 'slug', fn (Blueprint $table) => $table->string('slug')->nullable()->index());
        }
    }

    public function down(): void
    {
        $this->dropColumn('commerce_products', 'slug');
        $this->dropColumn('commerce_products', 'is_active');
        $this->dropColumn('commerce_products', 'price');
        $this->dropColumn('commerce_products', 'owner_entity_id');
        $this->dropColumn('commerce_warehouses', 'location');
        $this->dropColumn('commerce_bundle_hashes', 'product_ids');
        $this->dropColumn('commerce_product_assignments', 'entity_type');
        $this->dropColumn('commerce_product_prices', 'billing_cycle');
        $this->dropColumn('permission_requests', 'permissions');
        $this->dropColumn('permission_requests', 'to_entity_id');
        $this->dropColumn('permission_requests', 'from_entity_id');
        $this->dropColumn('permission_matrix', 'permissions');
        $this->dropColumn('permission_matrix', 'target_entity_id');
        $this->dropColumn('credit_notes', 'invoice_id');
        $this->dropColumn('orders', 'gateway_session_id');
        $this->dropColumn('orders', 'gateway');
        $this->dropColumn('orders', 'payment_method_id');
        $this->dropColumn('subscriptions', 'product_id');
    }

    protected function addColumn(string $table, string $column, Closure $callback): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, $callback);
    }

    protected function dropColumn(string $table, string $column): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, fn (Blueprint $table) => $table->dropColumn($column));
    }
};
