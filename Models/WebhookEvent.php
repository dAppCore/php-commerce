<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Webhook Event - Audit trail for incoming payment webhooks.
 *
 * @property int $id
 * @property string $gateway
 * @property string|null $event_id
 * @property string $event_type
 * @property string $payload
 * @property array|null $headers
 * @property string $status
 * @property string|null $error_message
 * @property int|null $http_status_code
 * @property int|null $order_id
 * @property int|null $subscription_id
 * @property Carbon $received_at
 * @property Carbon|null $processed_at
 */
class WebhookEvent extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $table = 'webhook_events';

    protected $fillable = [
        'gateway',
        'event_id',
        'event_type',
        'payload',
        'headers',
        'status',
        'error_message',
        'http_status_code',
        'order_id',
        'subscription_id',
        'received_at',
        'processed_at',
    ];

    protected $casts = [
        'headers' => 'array',
        'http_status_code' => 'integer',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    /**
     * Headers that contain sensitive data and should be redacted.
     */
    protected const SENSITIVE_HEADERS = [
        'stripe-signature',
        'authorization',
        'api-key',
        'x-api-key',
        'btcpay-sig',
        'btcpay-signature',
        'x-webhook-secret',
        'x-auth-token',
    ];

    /**
     * Mutator to redact sensitive headers before storing.
     *
     * @param  array<string, string>|null  $value
     */
    protected function setHeadersAttribute(?array $value): void
    {
        if ($value === null) {
            $this->attributes['headers'] = null;

            return;
        }

        $redacted = [];
        foreach ($value as $key => $headerValue) {
            $lowerKey = strtolower($key);

            // Check if this is a sensitive header
            $isSensitive = false;
            foreach (self::SENSITIVE_HEADERS as $sensitiveHeader) {
                if ($lowerKey === $sensitiveHeader || str_contains($lowerKey, 'signature') || str_contains($lowerKey, 'secret')) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive && $headerValue) {
                // Keep a truncated version for debugging (first 20 chars)
                $redacted[$key] = substr($headerValue, 0, 20).'...[REDACTED]';
            } else {
                $redacted[$key] = $headerValue;
            }
        }

        $this->attributes['headers'] = json_encode($redacted);
    }

    // Relationships

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    // Status helpers

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isProcessed(): bool
    {
        return $this->status === self::STATUS_PROCESSED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isSkipped(): bool
    {
        return $this->status === self::STATUS_SKIPPED;
    }

    // Actions

    /**
     * Mark as successfully processed.
     */
    public function markProcessed(int $httpStatusCode = 200): self
    {
        $this->update([
            'status' => self::STATUS_PROCESSED,
            'http_status_code' => $httpStatusCode,
            'processed_at' => now(),
        ]);

        return $this;
    }

    /**
     * Mark as failed with error message.
     */
    public function markFailed(string $error, int $httpStatusCode = 500): self
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $error,
            'http_status_code' => $httpStatusCode,
            'processed_at' => now(),
        ]);

        return $this;
    }

    /**
     * Mark as skipped (e.g., duplicate or unhandled event type).
     */
    public function markSkipped(string $reason, int $httpStatusCode = 200): self
    {
        $this->update([
            'status' => self::STATUS_SKIPPED,
            'error_message' => $reason,
            'http_status_code' => $httpStatusCode,
            'processed_at' => now(),
        ]);

        return $this;
    }

    /**
     * Link to an order.
     */
    public function linkOrder(Order $order): self
    {
        $this->update(['order_id' => $order->id]);

        return $this;
    }

    /**
     * Link to a subscription.
     */
    public function linkSubscription(Subscription $subscription): self
    {
        $this->update(['subscription_id' => $subscription->id]);

        return $this;
    }

    /**
     * Get decoded payload.
     */
    public function getDecodedPayload(): array
    {
        return json_decode($this->payload, true) ?? [];
    }

    // Factory methods

    /**
     * Create a webhook event record.
     */
    public static function record(
        string $gateway,
        string $eventType,
        string $payload,
        ?string $eventId = null,
        ?array $headers = null
    ): self {
        return static::create([
            'gateway' => $gateway,
            'event_type' => $eventType,
            'event_id' => $eventId,
            'payload' => $payload,
            'headers' => $headers,
            'status' => self::STATUS_PENDING,
            'received_at' => now(),
        ]);
    }

    /**
     * Check if an event has already been processed (deduplication).
     */
    public static function hasBeenProcessed(string $gateway, string $eventId): bool
    {
        return static::where('gateway', $gateway)
            ->where('event_id', $eventId)
            ->whereIn('status', [self::STATUS_PROCESSED, self::STATUS_SKIPPED])
            ->exists();
    }

    // Scopes

    public function scopeForGateway($query, string $gateway)
    {
        return $query->where('gateway', $gateway);
    }

    public function scopeOfType($query, string $eventType)
    {
        return $query->where('event_type', $eventType);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('received_at', '>=', now()->subDays($days));
    }
}
