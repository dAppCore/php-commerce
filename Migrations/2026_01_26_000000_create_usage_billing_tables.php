<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Usage-based billing tables for metered billing support.
     *
     * Integrates with Stripe metered billing API for usage tracking
     * and invoice line item generation.
     */
    public function up(): void
    {
        // 1. Usage Meters - defines what can be metered
        Schema::create('commerce_usage_meters', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();

            // Stripe meter configuration
            $table->string('stripe_meter_id')->nullable();
            $table->string('stripe_price_id')->nullable();

            // Pricing configuration
            $table->string('aggregation_type', 32)->default('sum'); // sum, max, last_value
            $table->decimal('unit_price', 10, 4)->default(0);
            $table->string('currency', 3)->default('GBP');
            $table->string('unit_label', 32)->default('units'); // e.g., 'API calls', 'GB', 'emails'

            // Tiers for graduated pricing (optional)
            $table->json('pricing_tiers')->nullable();

            // Feature code link (optional - for entitlement integration)
            $table->string('feature_code')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('stripe_meter_id');
            $table->index('feature_code');
        });

        // 2. Subscription Usage Records - tracks usage per subscription per meter
        Schema::create('commerce_subscription_usage', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('subscription_id');
            $table->foreignId('meter_id')->constrained('commerce_usage_meters')->cascadeOnDelete();

            // Usage tracking
            $table->unsignedBigInteger('quantity')->default(0);
            $table->timestamp('period_start');
            $table->timestamp('period_end');

            // Stripe sync tracking
            $table->string('stripe_usage_record_id')->nullable();
            $table->timestamp('synced_at')->nullable();

            // Billing status
            $table->boolean('billed')->default(false);
            $table->unsignedBigInteger('invoice_item_id')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['subscription_id', 'meter_id', 'period_start'], 'sub_meter_period_unique');
            $table->index(['subscription_id', 'period_start', 'period_end'], 'sub_usage_period_idx');
            $table->index(['billed', 'period_end'], 'sub_usage_billed_idx');

            // Add foreign key if subscriptions table exists
            if (Schema::hasTable('subscriptions')) {
                $table->foreign('subscription_id')
                    ->references('id')
                    ->on('subscriptions')
                    ->cascadeOnDelete();
            }
        });

        // 3. Usage Events - individual usage events before aggregation
        Schema::create('commerce_usage_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('subscription_id');
            $table->foreignId('meter_id')->constrained('commerce_usage_meters')->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();

            // Event details
            $table->unsignedBigInteger('quantity')->default(1);
            $table->timestamp('event_at');
            $table->string('idempotency_key', 64)->nullable();

            // Optional context
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64)->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->unique('idempotency_key');
            $table->index(['subscription_id', 'meter_id', 'event_at']);
            $table->index(['workspace_id', 'meter_id', 'event_at']);
            $table->index('event_at');

            // Add foreign key if subscriptions table exists
            if (Schema::hasTable('subscriptions')) {
                $table->foreign('subscription_id')
                    ->references('id')
                    ->on('subscriptions')
                    ->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_usage_events');
        Schema::dropIfExists('commerce_subscription_usage');
        Schema::dropIfExists('commerce_usage_meters');
    }
};
