<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Services;

use Core\Mod\Commerce\Contracts\PaymentGatewayContract;
use Core\Mod\Commerce\Jobs\ProcessWebhookEvent;
use Core\Mod\Commerce\Models\WebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WebhookService
{
    public function gateway(string $gateway): PaymentGatewayContract
    {
        return app("commerce.rfc_gateway.{$gateway}");
    }

    public function dispatch(string $gateway, Request $request): ?WebhookEvent
    {
        return DB::transaction(function () use ($gateway, $request): ?WebhookEvent {
            $paymentGateway = $this->gateway($gateway);

            if (! $paymentGateway->validateWebhookSignature($request)) {
                Log::warning('Webhook signature rejected', [
                    'gateway' => $gateway,
                    'ip' => $request->ip(),
                ]);

                return null;
            }

            $event = $paymentGateway->parseWebhookEvent($request);
            $eventId = $event['id'] ?? null;
            $eventType = (string) ($event['type'] ?? 'unknown');

            if (is_string($eventId) && $this->exists($gateway, $eventId)) {
                return null;
            }

            try {
                $webhookEvent = WebhookEvent::record(
                    gateway: $gateway,
                    eventType: $eventType,
                    payload: $request->getContent(),
                    eventId: is_string($eventId) ? $eventId : null,
                    headers: $this->headers($request, $gateway),
                );
            } catch (QueryException $e) {
                if ($this->isDuplicate($e)) {
                    return null;
                }

                throw $e;
            }

            ProcessWebhookEvent::dispatch($webhookEvent->id)->afterCommit();

            return $webhookEvent;
        });
    }

    protected function exists(string $gateway, string $eventId): bool
    {
        return WebhookEvent::query()
            ->where('gateway', $gateway)
            ->where('event_id', $eventId)
            ->exists();
    }

    /**
     * @return array<string, string>
     */
    protected function headers(Request $request, string $gateway): array
    {
        $headers = [
            'Content-Type' => (string) $request->header('Content-Type', ''),
            'User-Agent' => (string) $request->header('User-Agent', ''),
            'X-Forwarded-For' => (string) $request->header('X-Forwarded-For', ''),
        ];

        if ($gateway === 'stripe') {
            $headers['Stripe-Signature'] = (string) $request->header('Stripe-Signature', '');
        }

        if ($gateway === 'btcpay') {
            $headers['BTCPay-Sig'] = (string) $request->header('BTCPay-Sig', '');
            $headers['BTCPay-Signature'] = (string) $request->header('BTCPay-Signature', '');
        }

        return array_filter($headers, fn (string $value): bool => $value !== '');
    }

    protected function isDuplicate(QueryException $e): bool
    {
        $driverCode = $e->errorInfo[0] ?? null;
        $vendorCode = $e->errorInfo[1] ?? null;
        $message = $e->getMessage();

        return $vendorCode === 1062
            || $vendorCode === 19
            || $driverCode === '23505'
            || str_contains($message, 'webhook_events_idempotency')
            || str_contains($message, 'UNIQUE constraint failed');
    }
}
