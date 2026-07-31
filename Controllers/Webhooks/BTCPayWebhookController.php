<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Controllers\Webhooks;

use Core\Front\Controller;
use Core\Mod\Commerce\Exceptions\WebhookPayloadValidationException;
use Core\Mod\Commerce\Models\Order;
use Core\Mod\Commerce\Models\Payment;
use Core\Mod\Commerce\Models\WebhookEvent;
use Core\Mod\Commerce\Notifications\OrderConfirmation;
use Core\Mod\Commerce\Services\CommerceService;
use Core\Mod\Commerce\Services\PaymentGateway\BTCPayGateway;
use Core\Mod\Commerce\Services\WebhookLogger;
use Core\Mod\Commerce\Services\WebhookRateLimiter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Handle BTCPay Server webhooks.
 *
 * BTCPay sends webhooks for invoice state changes:
 * - InvoiceCreated: Invoice was created
 * - InvoiceReceivedPayment: Payment detected (0 confirmations)
 * - InvoiceProcessing: Payment processing (waiting for confirmations)
 * - InvoiceExpired: Invoice expired without full payment
 * - InvoiceSettled: Payment fully confirmed
 * - InvoiceInvalid: Payment was invalid/rejected
 */
class BTCPayWebhookController extends Controller
{
    public function __construct(
        protected BTCPayGateway $gateway,
        protected CommerceService $commerce,
        protected WebhookLogger $webhookLogger,
        protected WebhookRateLimiter $rateLimiter,
    ) {
    }

    public function handle(Request $request): Response
    {
        // Check IP-based rate limiting before processing
        if ($this->rateLimiter->tooManyAttempts($request, 'btcpay')) {
            $retryAfter = $this->rateLimiter->availableIn($request, 'btcpay');

            Log::warning('BTCPay webhook rate limit exceeded', [
                'ip' => $request->ip(),
                'retry_after' => $retryAfter,
            ]);

            return response('Too Many Requests', 429)
                ->header('Retry-After', (string) $retryAfter)
                ->header('X-RateLimit-Remaining', '0')
                ->header('X-RateLimit-Reset', (string) (time() + $retryAfter));
        }

        // Increment rate limit counter
        $this->rateLimiter->increment($request, 'btcpay');

        $payload = $request->getContent();
        $signature = $request->header('BTCPay-Sig');

        // Verify webhook signature
        if (! $this->gateway->verifyWebhookSignature($payload, $signature)) {
            Log::warning('BTCPay webhook signature verification failed');

            return response('Invalid signature', 401);
        }

        // Parse and validate the webhook payload
        try {
            $event = $this->gateway->parseWebhookEvent($payload);
        } catch (WebhookPayloadValidationException $e) {
            Log::warning('BTCPay webhook payload validation failed', [
                'error' => $e->getMessage(),
                'errors' => $e->getErrors(),
                'ip' => $request->ip(),
            ]);

            // Log the failed validation attempt for security auditing
            $this->webhookLogger->start(
                gateway: 'btcpay',
                eventType: 'validation_failed',
                payload: $payload,
                eventId: null,
                request: $request
            );
            $this->webhookLogger->fail($e->getMessage(), 400);

            return response('Invalid payload: '.$e->getMessage(), 400);
        }

        // Log the webhook event for audit trail (also handles deduplication via unique constraint)
        $webhookEvent = $this->webhookLogger->startFromParsedEvent('btcpay', $event, $payload, $request);

        // Idempotency check: if this event was already processed, return success without reprocessing
        if ($this->isAlreadyProcessed($webhookEvent, $event)) {
            Log::info('BTCPay webhook already processed (idempotency check)', [
                'type' => $event['type'],
                'id' => $event['id'],
            ]);

            return response('Already processed (duplicate)', 200);
        }

        Log::info('BTCPay webhook received', [
            'type' => $event['type'],
            'id' => $event['id'],
        ]);

        try {
            // Wrap all webhook processing in a transaction to ensure data integrity
            $response = DB::transaction(function () use ($event) {
                return match ($event['type']) {
                    'invoice.created' => $this->handleInvoiceCreated($event),
                    'invoice.payment_received' => $this->handlePaymentReceived($event),
                    'invoice.processing' => $this->handleProcessing($event),
                    'invoice.paid', 'payment.settled' => $this->handleSettled($event),
                    'invoice.expired' => $this->handleExpired($event),
                    'invoice.failed' => $this->handleFailed($event),
                    default => $this->handleUnknownEvent($event),
                };
            });

            $this->webhookLogger->success($response);

            return $response;
        } catch (\Exception $e) {
            Log::error('BTCPay webhook processing error', [
                'type' => $event['type'],
                'error' => $e->getMessage(),
            ]);

            $this->webhookLogger->fail($e->getMessage(), 500);

            return response('Processing error', 500);
        }
    }

    /**
     * Check if the webhook event has already been processed.
     *
     * This provides idempotency protection against replay attacks and
     * duplicate webhook deliveries from the payment gateway.
     */
    protected function isAlreadyProcessed(WebhookEvent $webhookEvent, array $event): bool
    {
        // If no event ID, we can't deduplicate
        if (empty($event['id'])) {
            return false;
        }

        if (! $webhookEvent->wasRecentlyCreated) {
            return true;
        }

        // If the webhook event we just created has a different ID than the one
        // that already existed in the database, it means this is a duplicate
        $existingEvent = WebhookEvent::where('gateway', 'btcpay')
            ->where('event_id', $event['id'])
            ->where('id', '!=', $webhookEvent->id)
            ->whereIn('status', [WebhookEvent::STATUS_PROCESSED, WebhookEvent::STATUS_SKIPPED])
            ->first();

        if ($existingEvent) {
            $this->webhookLogger->skip('Duplicate event (already processed)');

            return true;
        }

        // Also check if the current event was already processed (fetched from DB due to duplicate insert)
        if ($webhookEvent->isProcessed() || $webhookEvent->isSkipped()) {
            return true;
        }

        return false;
    }

    protected function handleUnknownEvent(array $event): Response
    {
        $this->webhookLogger->skip('Unhandled event type: '.$event['type']);

        return response('Unhandled event type', 200);
    }

    protected function handleInvoiceCreated(array $event): Response
    {
        // Invoice created - no action needed
        return response('OK', 200);
    }

    protected function handlePaymentReceived(array $event): Response
    {
        // Payment detected but not confirmed
        $order = $this->findOrderByInvoiceId($event['id']);

        if ($order) {
            // Update order status to show payment is incoming
            $order->update(['status' => 'processing']);
        }

        return response('OK', 200);
    }

    protected function handleProcessing(array $event): Response
    {
        // Payment is being processed (waiting for confirmations)
        $order = $this->findOrderByInvoiceId($event['id']);

        if ($order) {
            $order->update(['status' => 'processing']);
        }

        return response('OK', 200);
    }

    protected function handleSettled(array $event): Response
    {
        // Payment fully confirmed - fulfil the order
        $order = $this->findOrderByInvoiceId($event['id']);

        if (! $order) {
            Log::warning('BTCPay webhook: Order not found', ['invoice_id' => $event['id']]);

            return response('Order not found', 200);
        }

        // Link webhook event to order for audit trail
        $this->webhookLogger->linkOrder($order);

        // Skip if already paid
        if ($order->isPaid()) {
            return response('Already processed', 200);
        }

        // Get invoice details from BTCPay
        $invoiceData = $this->gateway->getCheckoutSession($event['id']);

        // SECURITY: Verify the paid amount matches the order total
        $amountVerification = $this->verifyPaymentAmount($order, $invoiceData);
        if (! $amountVerification['valid']) {
            Log::warning('BTCPay webhook: Payment amount verification failed', [
                'order_id' => $order->id,
                'order_total' => $order->total,
                'paid_amount' => $amountVerification['paid_amount'],
                'currency' => $order->currency,
                'discrepancy' => $amountVerification['discrepancy'],
                'reason' => $amountVerification['reason'],
            ]);

            // Handle underpayment - mark order as failed with details
            if ($amountVerification['reason'] === 'underpaid') {
                $order->markAsFailed(sprintf(
                    'Underpaid: received %s %s, expected %s %s',
                    $amountVerification['paid_amount'],
                    $order->currency,
                    $order->total,
                    $order->currency
                ));

                // Create a partial payment record for audit trail
                Payment::create([
                    'workspace_id' => $order->workspace_id,
                    'order_id' => $order->id,
                    'invoice_id' => null,
                    'gateway' => 'btcpay',
                    'gateway_payment_id' => $event['id'],
                    'amount' => $amountVerification['paid_amount'],
                    'currency' => $order->currency,
                    'status' => 'underpaid',
                    'paid_at' => now(),
                    'gateway_response' => $invoiceData['raw'] ?? [],
                ]);

                return response('Underpaid - order not fulfilled', 200);
            }

            // Currency mismatch - this is a serious issue
            if ($amountVerification['reason'] === 'currency_mismatch') {
                $order->markAsFailed(sprintf(
                    'Currency mismatch: received %s, expected %s',
                    $amountVerification['received_currency'],
                    $order->currency
                ));

                return response('Currency mismatch - order not fulfilled', 200);
            }
        }

        // Create payment record with the verified amount
        $payment = Payment::create([
            'workspace_id' => $order->workspace_id,
            'order_id' => $order->id,
            'invoice_id' => null, // Will be set by fulfillOrder
            'gateway' => 'btcpay',
            'gateway_payment_id' => $event['id'],
            'amount' => $amountVerification['paid_amount'],
            'currency' => $order->currency,
            'status' => 'succeeded',
            'paid_at' => now(),
            'gateway_response' => $invoiceData['raw'] ?? [],
        ]);

        // Log overpayment for manual review but still fulfil
        if ($amountVerification['reason'] === 'overpaid') {
            Log::info('BTCPay webhook: Overpayment received', [
                'order_id' => $order->id,
                'order_total' => $order->total,
                'paid_amount' => $amountVerification['paid_amount'],
                'overpayment' => $amountVerification['discrepancy'],
            ]);
        }

        // Fulfil the order (provisions entitlements, creates invoice)
        $this->commerce->fulfillOrder($order, $payment);

        // Send confirmation email
        $this->sendOrderConfirmation($order);

        Log::info('BTCPay order fulfilled', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'payment_id' => $payment->id,
        ]);

        return response('OK', 200);
    }

    /**
     * Verify that the payment amount from BTCPay matches the order total.
     *
     * This is a critical security check to prevent attacks where an attacker
     * pays less than the required amount but the order is still fulfilled.
     *
     * @return array{valid: bool, paid_amount: float, discrepancy: float, reason: string|null, received_currency: string|null}
     */
    protected function verifyPaymentAmount(Order $order, array $invoiceData): array
    {
        $orderTotal = (float) $order->total;
        $orderCurrency = strtoupper($order->currency);

        // Extract paid amount from BTCPay response
        // BTCPay uses 'amount' field for the invoice amount
        $paidAmount = isset($invoiceData['amount']) ? (float) $invoiceData['amount'] : 0.0;
        $paidCurrency = isset($invoiceData['currency']) ? strtoupper($invoiceData['currency']) : null;

        // Also check raw response for additional payment data
        $rawData = $invoiceData['raw'] ?? [];
        if (isset($rawData['amount'])) {
            $paidAmount = (float) $rawData['amount'];
        }
        if (isset($rawData['currency'])) {
            $paidCurrency = strtoupper($rawData['currency']);
        }

        // Check currency matches
        if ($paidCurrency && $paidCurrency !== $orderCurrency) {
            return [
                'valid' => false,
                'paid_amount' => $paidAmount,
                'discrepancy' => 0,
                'reason' => 'currency_mismatch',
                'received_currency' => $paidCurrency,
            ];
        }

        // Calculate discrepancy
        $discrepancy = $paidAmount - $orderTotal;

        // Allow a small tolerance for floating point precision (0.01 currency units)
        $tolerance = 0.01;

        if ($paidAmount < ($orderTotal - $tolerance)) {
            return [
                'valid' => false,
                'paid_amount' => $paidAmount,
                'discrepancy' => $discrepancy,
                'reason' => 'underpaid',
                'received_currency' => $paidCurrency,
            ];
        }

        if ($paidAmount > ($orderTotal + $tolerance)) {
            // Overpayment is valid but logged for manual review
            return [
                'valid' => true,
                'paid_amount' => $paidAmount,
                'discrepancy' => $discrepancy,
                'reason' => 'overpaid',
                'received_currency' => $paidCurrency,
            ];
        }

        return [
            'valid' => true,
            'paid_amount' => $paidAmount,
            'discrepancy' => 0,
            'reason' => null,
            'received_currency' => $paidCurrency,
        ];
    }

    protected function handleExpired(array $event): Response
    {
        // Invoice expired - mark order as failed
        $order = $this->findOrderByInvoiceId($event['id']);

        if ($order && ! $order->isPaid()) {
            $order->markAsFailed('Payment expired');
        }

        return response('OK', 200);
    }

    protected function handleFailed(array $event): Response
    {
        // Payment invalid/rejected
        $order = $this->findOrderByInvoiceId($event['id']);

        if ($order && ! $order->isPaid()) {
            $order->markAsFailed('Payment rejected');
        }

        return response('OK', 200);
    }

    protected function findOrderByInvoiceId(string $invoiceId): ?Order
    {
        return Order::where('gateway', 'btcpay')
            ->where('gateway_session_id', $invoiceId)
            ->first();
    }

    protected function sendOrderConfirmation(Order $order): void
    {
        if (! config('commerce.notifications.order_confirmation', true)) {
            return;
        }

        // Use resolved workspace to handle both Workspace and User orderables
        $workspace = $order->getResolvedWorkspace();
        $owner = $workspace?->owner();
        if ($owner) {
            $owner->notify(new OrderConfirmation($order));
        }
    }
}
