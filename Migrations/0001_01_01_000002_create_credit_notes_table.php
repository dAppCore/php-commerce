<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Credit Notes table for tracking credits issued to users.
     *
     * Credit notes can be:
     * - General credits (goodwill, promotional)
     * - Partial refunds issued as store credit instead of cash
     * - Applied to future orders to reduce payment amount
     */
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Source references (optional - not all credits come from orders/refunds)
            // Foreign keys added when orders/refunds tables exist
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('refund_id')->nullable();

            // Credit details
            $table->string('reference_number', 32)->unique();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('GBP');
            $table->string('reason');
            $table->text('description')->nullable();

            // Status: draft, issued, applied, partially_applied, void
            $table->string('status', 32)->default('draft');

            // Tracking
            $table->decimal('amount_used', 10, 2)->default(0);
            $table->unsignedBigInteger('applied_to_order_id')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();

            // Flexible storage
            $table->json('metadata')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['workspace_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index('reference_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_notes');
    }
};
