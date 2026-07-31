<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Payment methods table for storing saved payment methods (cards, bank accounts, etc).
     */
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Gateway identifiers
            $table->string('gateway', 32); // stripe, btcpay, etc.
            $table->string('gateway_payment_method_id')->nullable(); // pm_xxx for Stripe
            $table->string('gateway_customer_id')->nullable(); // cus_xxx for Stripe

            // Payment method details
            $table->string('type', 32)->default('card'); // card, bank_account, crypto_wallet
            $table->string('brand', 32)->nullable(); // visa, mastercard, amex, etc.
            $table->string('last_four', 4)->nullable(); // Last 4 digits of card
            $table->unsignedTinyInteger('exp_month')->nullable(); // 1-12
            $table->unsignedSmallInteger('exp_year')->nullable(); // 2024, 2025, etc.

            // Status
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);

            // Metadata
            $table->json('metadata')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['workspace_id', 'is_active', 'is_default']);
            $table->index(['gateway', 'gateway_payment_method_id']);
            $table->unique(['workspace_id', 'gateway', 'gateway_payment_method_id'], 'payment_method_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
