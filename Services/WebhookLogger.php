<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Services;

use Core\Mod\Commerce\Models\Order;
use Core\Mod\Commerce\Models\Subscription;
use Core\Mod\Commerce\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Service for logging webhook events from payment gateways.
 *
 * Provides a consistent interface for recording, processing,
 * and tracking webhook events for audit and debugging.
 */
class WebhookLogger
{
    protected ?WebhookEvent $currentEvent = null;

    /**
     * Start logging a webhook event.
     *
     * Uses try-catch to handle duplicate entry constraint violations,
     * preventing TOCTOU race conditions when multiple identical webhooks arrive simultaneously.
     */
    public function start(
        string $gateway,
        string $eventType,
        string $payload,
        ?string $eventId = null,
        ?Request $request = null
    ): WebhookEvent {
        $headers = $request ? $this->extractRelevantHeaders($request, $gateway) : null;

        // If we have an event ID, use atomic check-and-insert
        if ($eventId) {
            return $this->startWithDeduplication($gateway, $eventType, $payload, $eventId, $headers);
        }

        // No event ID - just create the record
        $this->currentEvent = WebhookEvent::record(
            gateway: $gateway,
            eventType: $eventType,
            payload: $payload,
            eventId: $eventId,
            headers: $headers
        );

        Log::info('Webhook event received', [
            'id' => $this->currentEvent->id,
            'gateway' => $gateway,
            'event_type' => $eventType,
            'event_id' => $eventId,
        ]);

        return $this->currentEvent;
    }

    /**
     * Start logging with deduplication - handles race conditions atomically.
     */
    protected function startWithDeduplication(
        string $gateway,
        string $eventType,
        string $payload,
        string $eventId,
        ?array $headers
    ): WebhookEvent {
        try {
            // Attempt to insert - if duplicate constraint violation, fetch existing
            $this->currentEvent = WebhookEvent::record(
                gateway: $gateway,
                eventType: $eventType,
                payload: $payload,
                eventId: $eventId,
                headers: $headers
            );

            Log::info('Webhook event received', [
                'id' => $this->currentEvent->id,
                'gateway' => $gateway,
                'event_type' => $eventType,
                'event_id' => $eventId,
            ]);

            return $this->currentEvent;
        } catch (QueryException $e) {
            // Check for duplicate entry error (MySQL: 1062, PostgreSQL: 23505)
            if ($this->isDuplicateEntryException($e)) {
                Log::info('Webhook event already exists (duplicate)', [
                    'gateway' => $gateway,
                    'event_id' => $eventId,
                    'event_type' => $eventType,
                ]);

                // Fetch the existing event
                $existing = WebhookEvent::where('gateway', $gateway)
                    ->where('event_id', $eventId)
                    ->first();

                if ($existing) {
                    $this->currentEvent = $existing;

                    return $existing;
                }
            }

            // Re-throw if not a duplicate entry error
            throw $e;
        }
    }

    /**
     * Check if the exception is a duplicate entry constraint violation.
     */
    protected function isDuplicateEntryException(QueryException $e): bool
    {
        $code = $e->errorInfo[1] ?? null;

        // MySQL duplicate entry
        if ($code === 1062) {
            return true;
        }

        // PostgreSQL unique violation
        if ($code === 23505 || ($e->errorInfo[0] ?? null) === '23505') {
            return true;
        }

        // SQLite constraint violation (check message for UNIQUE)
        if ($code === 19 && str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
            return true;
        }

        return false;
    }

    /**
     * Start logging from parsed event data (after verification).
     */
    public function startFromParsedEvent(
        string $gateway,
        array $event,
        string $rawPayload,
        ?Request $request = null
    ): WebhookEvent {
        return $this->start(
            gateway: $gateway,
            eventType: $event['type'] ?? 'unknown',
            payload: $rawPayload,
            eventId: $event['id'] ?? null,
            request: $request
        );
    }

    /**
     * Mark the current event as successfully processed.
     */
    public function success(?Response $response = null): void
    {
        if (! $this->currentEvent) {
            return;
        }

        $statusCode = $response?->getStatusCode() ?? 200;
        $this->currentEvent->markProcessed($statusCode);

        Log::info('Webhook event processed successfully', [
            'id' => $this->currentEvent->id,
            'gateway' => $this->currentEvent->gateway,
            'event_type' => $this->currentEvent->event_type,
            'http_status' => $statusCode,
        ]);
    }

    /**
     * Mark the current event as failed.
     */
    public function fail(string $error, int $statusCode = 500): void
    {
        if (! $this->currentEvent) {
            return;
        }

        $this->currentEvent->markFailed($error, $statusCode);

        Log::error('Webhook event processing failed', [
            'id' => $this->currentEvent->id,
            'gateway' => $this->currentEvent->gateway,
            'event_type' => $this->currentEvent->event_type,
            'error' => $error,
            'http_status' => $statusCode,
        ]);
    }

    /**
     * Mark the current event as skipped.
     */
    public function skip(string $reason, int $statusCode = 200): void
    {
        if (! $this->currentEvent) {
            return;
        }

        $this->currentEvent->markSkipped($reason, $statusCode);

        Log::info('Webhook event skipped', [
            'id' => $this->currentEvent->id,
            'gateway' => $this->currentEvent->gateway,
            'event_type' => $this->currentEvent->event_type,
            'reason' => $reason,
        ]);
    }

    /**
     * Link current event to an order.
     */
    public function linkOrder(Order $order): void
    {
        if ($this->currentEvent) {
            $this->currentEvent->linkOrder($order);
        }
    }

    /**
     * Link current event to a subscription.
     */
    public function linkSubscription(Subscription $subscription): void
    {
        if ($this->currentEvent) {
            $this->currentEvent->linkSubscription($subscription);
        }
    }

    /**
     * Get the current event being processed.
     */
    public function getCurrentEvent(): ?WebhookEvent
    {
        return $this->currentEvent;
    }

    /**
     * Check if an event was already processed.
     */
    public function isDuplicate(string $gateway, string $eventId): bool
    {
        return WebhookEvent::hasBeenProcessed($gateway, $eventId);
    }

    /**
     * Extract relevant headers for logging.
     */
    protected function extractRelevantHeaders(Request $request, string $gateway): array
    {
        $headers = [];

        // Common headers
        $relevantHeaders = [
            'Content-Type',
            'User-Agent',
            'X-Forwarded-For',
            'X-Real-IP',
        ];

        // Gateway-specific headers (normalise to lowercase for comparison)
        $normalizedGateway = strtolower($gateway);
        if ($normalizedGateway === 'stripe') {
            $relevantHeaders[] = 'Stripe-Signature';
            $relevantHeaders[] = 'Stripe-Webhook-ID';
        } elseif ($normalizedGateway === 'btcpay') {
            $relevantHeaders[] = 'BTCPay-Sig';
            $relevantHeaders[] = 'BTCPay-Signature';
        }

        foreach ($relevantHeaders as $header) {
            $value = $request->header($header);
            if ($value) {
                // Mask sensitive parts of signatures
                if (str_contains(strtolower($header), 'signature') || str_contains(strtolower($header), 'sig')) {
                    $value = substr($value, 0, 20).'...';
                }
                $headers[$header] = $value;
            }
        }

        return $headers;
    }

    /**
     * Get statistics for webhook events.
     */
    public function getStats(string $gateway, int $days = 7): array
    {
        $query = WebhookEvent::forGateway($gateway)->recent($days);

        return [
            'total' => (clone $query)->count(),
            'processed' => (clone $query)->where('status', WebhookEvent::STATUS_PROCESSED)->count(),
            'failed' => (clone $query)->where('status', WebhookEvent::STATUS_FAILED)->count(),
            'skipped' => (clone $query)->where('status', WebhookEvent::STATUS_SKIPPED)->count(),
            'pending' => (clone $query)->where('status', WebhookEvent::STATUS_PENDING)->count(),
        ];
    }

    /**
     * Get recent failed events for debugging.
     */
    public function getRecentFailures(string $gateway, int $limit = 10): Collection
    {
        return WebhookEvent::forGateway($gateway)
            ->failed()
            ->orderBy('received_at', 'desc')
            ->limit($limit)
            ->get();
    }
}
