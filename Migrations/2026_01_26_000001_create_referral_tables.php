<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Referral tracking tables for affiliate/referral programme.
     *
     * Tracks:
     * - Referral relationships (referrer -> referee)
     * - Referral codes (user-specific or campaign codes)
     * - Conversions (when referrals convert to paid customers)
     * - Commissions (earnings from referrals)
     * - Payouts (commission withdrawals)
     */
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        // 1. Referrals - tracks individual referral relationships
        Schema::create('commerce_referrals', function (Blueprint $table) {
            $table->id();

            // Referrer (the user who shared the code)
            $table->foreignId('referrer_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Referee (the user who signed up via referral)
            $table->foreignId('referee_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // The code used (either user's namespace or a campaign code)
            $table->string('code', 64)->index();

            // Status: pending (clicked), converted (signed up), qualified (made purchase), disqualified
            $table->string('status', 32)->default('pending');

            // Attribution tracking
            $table->string('source_url', 2048)->nullable();
            $table->string('landing_page', 2048)->nullable();
            $table->string('utm_source', 128)->nullable();
            $table->string('utm_medium', 128)->nullable();
            $table->string('utm_campaign', 128)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            // Cookie/session tracking
            $table->string('tracking_id', 64)->nullable()->unique();

            // Conversion timestamps
            $table->timestamp('clicked_at')->nullable();
            $table->timestamp('signed_up_at')->nullable();
            $table->timestamp('first_purchase_at')->nullable();
            $table->timestamp('qualified_at')->nullable();
            $table->timestamp('disqualified_at')->nullable();
            $table->string('disqualification_reason', 255)->nullable();

            // Maturation (when commission becomes withdrawable)
            $table->timestamp('matured_at')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['referrer_id', 'status']);
            $table->index(['referee_id', 'status']);
            $table->index(['code', 'status']);
            $table->index(['status', 'created_at']);
        });

        // 2. Referral Payouts - tracks commission withdrawals (created first as commissions reference it)
        Schema::create('commerce_referral_payouts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('payout_number', 32)->unique();

            // Payout method: btc, account_credit
            $table->string('method', 32);

            // For BTC payouts
            $table->string('btc_address', 128)->nullable();
            $table->string('btc_txid', 128)->nullable();

            // Amount
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('GBP');

            // For BTC: actual BTC amount at time of payout
            $table->decimal('btc_amount', 18, 8)->nullable();
            $table->decimal('btc_rate', 18, 8)->nullable();

            // Status: requested, processing, completed, failed, cancelled
            $table->string('status', 32)->default('requested');

            $table->timestamp('requested_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->text('notes')->nullable();
            $table->text('failure_reason')->nullable();

            // Admin who processed
            $table->foreignId('processed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // Indexes
            $table->index(['user_id', 'status']);
            $table->index(['status', 'requested_at']);
        });

        // 3. Referral Commissions - tracks earnings from each referral order
        Schema::create('commerce_referral_commissions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('referral_id')
                ->constrained('commerce_referrals')
                ->cascadeOnDelete();

            $table->foreignId('referrer_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // NOTE: `orders` and `invoices` tables are not created by any
            // migration in the current codebase. MariaDB silently accepted
            // FKs to nonexistent tables (FK checks disabled during the
            // migration), but Postgres rejects them. Keeping the columns
            // as plain nullable bigint so the schema deploys on both DBs;
            // FK constraints can be added in a follow-up migration once
            // the orders/invoices tables actually exist.
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable();

            // Commission calculation
            $table->decimal('order_amount', 10, 2); // Net order amount (after tax/discounts)
            $table->decimal('commission_rate', 5, 2)->default(10.00); // Percentage (10.00 = 10%)
            $table->decimal('commission_amount', 10, 2); // Calculated commission
            $table->string('currency', 3)->default('GBP');

            // Status: pending, matured, paid, cancelled
            $table->string('status', 32)->default('pending');

            // Maturation - commission becomes withdrawable after refund/chargeback period
            $table->timestamp('matures_at')->nullable();
            $table->timestamp('matured_at')->nullable();

            // Payout tracking
            $table->foreignId('payout_id')
                ->nullable()
                ->constrained('commerce_referral_payouts')
                ->nullOnDelete();
            $table->timestamp('paid_at')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['referrer_id', 'status']);
            $table->index(['referral_id', 'status']);
            $table->index(['status', 'matures_at']);
            $table->index(['payout_id']);
        });

        // 4. Referral Codes - for campaign/custom codes (beyond user namespaces)
        Schema::create('commerce_referral_codes', function (Blueprint $table) {
            $table->id();

            $table->string('code', 64)->unique();

            // Owner - can be null for system/campaign codes
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Code type: user (auto-generated from namespace), campaign, custom
            $table->string('type', 32)->default('custom');

            // Custom commission rate (null = use default)
            $table->decimal('commission_rate', 5, 2)->nullable();

            // Attribution cookie duration (days)
            $table->integer('cookie_days')->default(90);

            // Limits
            $table->integer('max_uses')->nullable();
            $table->integer('uses_count')->default(0);

            // Validity
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->boolean('is_active')->default(true);

            // Metadata for campaign tracking
            $table->string('campaign_name', 128)->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['user_id', 'is_active']);
            $table->index(['type', 'is_active']);
        });

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('commerce_referral_codes');
        Schema::dropIfExists('commerce_referral_commissions');
        Schema::dropIfExists('commerce_referral_payouts');
        Schema::dropIfExists('commerce_referrals');
        Schema::enableForeignKeyConstraints();
    }
};
