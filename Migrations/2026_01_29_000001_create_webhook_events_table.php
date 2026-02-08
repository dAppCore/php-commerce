<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Webhook Events table for tracking incoming payment gateway webhooks.
     *
     * This provides:
     * - Audit trail of all webhook events
     * - Idempotency via unique constraint on (gateway, event_id)
     * - Replay attack protection
     */
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();

            // Gateway identification
            $table->string('gateway', 32); // stripe, btcpay, etc.
            $table->string('event_id', 255)->nullable(); // Gateway's unique event ID
            $table->string('event_type', 64); // checkout.session.completed, invoice.paid, etc.

            // Payload storage
            $table->mediumText('payload'); // Raw webhook payload
            $table->json('headers')->nullable(); // Relevant headers (sanitised)

            // Processing status
            $table->string('status', 32)->default('pending'); // pending, processed, failed, skipped
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('http_status_code')->nullable();

            // Related entities (for linking/audit)
            // No FK constraint — orders/subscriptions tables are created by the billing module
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->unsignedBigInteger('subscription_id')->nullable()->index();

            // Timestamps
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            // Idempotency: prevent duplicate processing of same event
            // The unique constraint ensures only one record per gateway+event_id
            $table->unique(['gateway', 'event_id'], 'webhook_events_idempotency');

            // Query indexes
            $table->index(['gateway', 'status', 'received_at']);
            $table->index(['gateway', 'event_type']);
            $table->index(['status', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
