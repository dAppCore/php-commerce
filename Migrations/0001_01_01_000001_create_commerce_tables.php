<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Commerce module tables.
     *
     * Entity Hierarchy (M1 → M2 → M3):
     * - M1: Master Company (source of truth, owns product catalog)
     * - M2: Facades/Storefronts (select from M1, can override content)
     * - M3: Dropshippers (full inheritance, no management responsibility)
     */
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        // 1. Commerce Entities
        Schema::create('commerce_entities', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('type');

            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('commerce_entities')
                ->nullOnDelete();
            $table->string('path')->index();
            $table->integer('depth')->default(0);

            $table->foreignId('workspace_id')
                ->nullable()
                ->constrained('workspaces')
                ->nullOnDelete();

            $table->json('settings')->nullable();
            $table->string('domain')->nullable();
            $table->string('currency', 3)->default('GBP');
            $table->string('timezone')->default('Europe/London');

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'is_active']);
            $table->index(['workspace_id', 'is_active']);
        });

        // 2. Permission Matrix
        Schema::create('permission_matrix', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('commerce_entities')->cascadeOnDelete();
            $table->string('key');
            $table->string('scope')->nullable();
            $table->boolean('allowed')->default(false);
            $table->boolean('locked')->default(false);
            $table->string('source');
            $table->foreignId('set_by_entity_id')
                ->nullable()
                ->constrained('commerce_entities')
                ->nullOnDelete();
            $table->timestamp('trained_at')->nullable();
            $table->string('trained_route')->nullable();
            $table->timestamps();

            $table->unique(['entity_id', 'key', 'scope'], 'permission_matrix_unique');
            $table->index(['key', 'scope']);
            $table->index(['entity_id', 'allowed']);
        });

        // 3. Permission Requests
        Schema::create('permission_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('commerce_entities')->cascadeOnDelete();
            $table->string('method');
            $table->string('route');
            $table->string('action');
            $table->string('scope')->nullable();
            $table->json('request_data')->nullable();
            $table->string('user_agent')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status');
            $table->boolean('was_trained')->default(false);
            $table->timestamp('trained_at')->nullable();
            $table->timestamps();

            $table->index(['entity_id', 'action', 'status']);
            $table->index(['status', 'created_at']);
            $table->index(['action', 'was_trained']);
        });

        // 4. Commerce Products
        Schema::create('commerce_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('commerce_entities')->cascadeOnDelete();
            $table->string('sku', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type', 32)->default('simple');
            $table->string('status', 32)->default('active');
            $table->decimal('base_price', 10, 2)->nullable();
            $table->string('currency', 3)->default('GBP');
            $table->decimal('cost_price', 10, 2)->nullable();
            $table->decimal('weight', 8, 3)->nullable();
            $table->string('weight_unit', 8)->default('kg');
            $table->json('dimensions')->nullable();
            $table->json('attributes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['entity_id', 'status']);
            $table->index(['type', 'status']);
        });

        // 5. Commerce Product Assignments
        Schema::create('commerce_product_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('commerce_products')->cascadeOnDelete();
            $table->foreignId('entity_id')->constrained('commerce_entities')->cascadeOnDelete();
            $table->boolean('is_visible')->default(true);
            $table->decimal('price_override', 10, 2)->nullable();
            $table->json('content_overrides')->nullable();
            $table->json('inventory_override')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'entity_id']);
            $table->index(['entity_id', 'is_visible']);
        });

        // 6. Commerce Warehouses
        Schema::create('commerce_warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('commerce_entities')->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('region')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country', 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['entity_id', 'code']);
            $table->index(['entity_id', 'is_active']);
        });

        // 7. Commerce Inventory
        Schema::create('commerce_inventory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('commerce_products')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('commerce_warehouses')->cascadeOnDelete();
            $table->integer('quantity')->default(0);
            $table->integer('reserved')->default(0);
            $table->integer('low_stock_threshold')->nullable();
            $table->boolean('track_inventory')->default(true);
            $table->boolean('allow_backorder')->default(false);
            $table->timestamp('last_counted_at')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'warehouse_id']);
            $table->index(['warehouse_id', 'quantity']);
        });

        // 8. Commerce Content Overrides
        Schema::create('commerce_content_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('commerce_products')->cascadeOnDelete();
            $table->foreignId('entity_id')->constrained('commerce_entities')->cascadeOnDelete();
            $table->string('field');
            $table->text('value')->nullable();
            $table->string('source', 32)->default('manual');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'entity_id', 'field'], 'content_override_unique');
            $table->index(['entity_id', 'field']);
        });

        // 9. Commerce Bundle Hashes
        Schema::create('commerce_bundle_hashes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('commerce_entities')->cascadeOnDelete();
            $table->string('bundle_type', 64);
            $table->string('hash', 64);
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique(['entity_id', 'bundle_type']);
        });

        // 10. Webhook Events (Commerce)
        Schema::create('commerce_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('commerce_entities')->cascadeOnDelete();
            $table->string('event_type', 64);
            $table->string('idempotency_key', 64)->unique();
            $table->json('payload');
            $table->string('status', 32)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['entity_id', 'event_type', 'status']);
            $table->index(['status', 'created_at']);
        });

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('commerce_webhook_events');
        Schema::dropIfExists('commerce_bundle_hashes');
        Schema::dropIfExists('commerce_content_overrides');
        Schema::dropIfExists('commerce_inventory');
        Schema::dropIfExists('commerce_warehouses');
        Schema::dropIfExists('commerce_product_assignments');
        Schema::dropIfExists('commerce_products');
        Schema::dropIfExists('permission_requests');
        Schema::dropIfExists('permission_matrix');
        Schema::dropIfExists('commerce_entities');
        Schema::enableForeignKeyConstraints();
    }
};
