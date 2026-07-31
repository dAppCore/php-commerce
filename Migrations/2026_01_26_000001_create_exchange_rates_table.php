<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Exchange rates table for multi-currency support.
     */
    public function up(): void
    {
        Schema::create('commerce_exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->string('base_currency', 3);
            $table->string('target_currency', 3);
            $table->decimal('rate', 16, 8);
            $table->string('source', 32)->default('ecb'); // ecb, stripe, openexchangerates, manual
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['base_currency', 'target_currency', 'source'], 'exchange_rate_unique');
            $table->index(['base_currency', 'target_currency']);
            $table->index('fetched_at');
        });

        // Product prices in multiple currencies
        Schema::create('commerce_product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('commerce_products')->cascadeOnDelete();
            $table->string('currency', 3);
            $table->integer('amount'); // Price in smallest unit (cents/pence)
            $table->boolean('is_manual')->default(false); // Manual override vs auto-converted
            $table->decimal('exchange_rate_used', 16, 8)->nullable(); // Rate used for auto-conversion
            $table->timestamps();

            $table->unique(['product_id', 'currency']);
            $table->index(['currency', 'is_manual']);
        });

        // Add currency fields to orders table (if it exists)
        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'display_currency')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('display_currency', 3)->after('currency')->nullable();
                $table->decimal('exchange_rate_used', 16, 8)->after('display_currency')->nullable();
                $table->decimal('base_currency_total', 12, 2)->after('exchange_rate_used')->nullable();
            });
        }

        // Add currency fields to invoices table (if it exists)
        if (Schema::hasTable('invoices') && ! Schema::hasColumn('invoices', 'display_currency')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->string('display_currency', 3)->after('currency')->nullable();
                $table->decimal('exchange_rate_used', 16, 8)->after('display_currency')->nullable();
                $table->decimal('base_currency_total', 12, 2)->after('exchange_rate_used')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('invoices') && Schema::hasColumn('invoices', 'display_currency')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropColumn(['display_currency', 'exchange_rate_used', 'base_currency_total']);
            });
        }

        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'display_currency')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn(['display_currency', 'exchange_rate_used', 'base_currency_total']);
            });
        }

        Schema::dropIfExists('commerce_product_prices');
        Schema::dropIfExists('commerce_exchange_rates');
    }
};
