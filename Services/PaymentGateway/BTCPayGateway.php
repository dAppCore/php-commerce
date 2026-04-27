<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Services\PaymentGateway;

use Core\Mod\Commerce\Exceptions\WebhookPayloadValidationException;
use Core\Mod\Commerce\Models\Order;
use Core\Mod\Commerce\Models\Payment;
use Core\Mod\Commerce\Models\PaymentMethod;
use Core\Mod\Commerce\Models\Subscription;
use Core\Tenant\Models\Workspace;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * BTCPay Server payment gateway implementation.
 *
 * This is the primary payment gateway for Host UK, supporting
 * Bitcoin, Litecoin, and Monero payments.
 */
class BTCPayGateway implements PaymentGatewayContract
{
    protected string $baseUrl;

    protected string $storeId;

    protected string $apiKey;

    protected string $webhookSecret;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('commerce.gateways.btcpay.url') ?? '', '/');
        $this->storeId = config('commerce.gateways.btcpay.store_id') ?? '';
        $this->apiKey = config('commerce.gateways.btcpay.api_key') ?? '';
        $this->webhookSecret = config('commerce.gateways.btcpay.webhook_secret') ?? '';
    }

    public function getIdentifier(): string
    {
        return 'btcpay';
    }

    public function isEnabled(): bool
    {
        return config('commerce.gateways.btcpay.enabled', false)
            && $this->storeId
            && $this->apiKey;
    }

    // Customer Management

    public function createCustomer(Workspace $workspace): string
    {
        // BTCPay doesn't have a customer concept like Stripe
        // We generate a unique identifier for the workspace
        $customerId = 'btc_cus_'.Str::ulid();

        $workspace->update(['btcpay_customer_id' => $customerId]);

        return $customerId;
    }

    public function updateCustomer(Workspace $workspace): void
    {
        // BTCPay doesn't store customer details
        // No-op but could sync to external systems
    }

    // Checkout

    public function createCheckoutSession(Order $order, string $successUrl, string $cancelUrl): array
    {
        try {
            $response = $this->request('POST', "/api/v1/stores/{$this->storeId}/invoices", [
                'amount' => (string) $order->total,
                'currency' => $order->currency,
                'metadata' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'workspace_id' => $order->workspace_id,
                ],
                'checkout' => [
                    'redirectURL' => $successUrl,
                    'redirectAutomatically' => true,
                    'requiresRefundEmail' => true,
                ],
                'receipt' => [
                    'enabled' => true,
                    'showQr' => true,
                ],
            ]);

            if (empty($response['id'])) {
                Log::error('BTCPay checkout: Invalid response - missing invoice ID', [
                    'order_id' => $order->id,
                ]);
                throw new \RuntimeException('Invalid response from payment service.');
            }

            $invoiceId = $response['id'];
            $checkoutUrl = "{$this->baseUrl}/i/{$invoiceId}";

            // Store the BTCPay invoice ID in the order
            $order->update([
                'gateway_session_id' => $invoiceId,
            ]);

            return [
                'session_id' => $invoiceId,
                'checkout_url' => $checkoutUrl,
            ];
        } catch (\RuntimeException $e) {
            // Re-throw RuntimeExceptions (already logged/handled)
            throw $e;
        } catch (\Exception $e) {
            Log::error('BTCPay checkout failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Unable to create checkout session. Please try again or contact support.', 0, $e);
        }
    }

    public function getCheckoutSession(string $sessionId): array
    {
        $response = $this->request('GET', "/api/v1/stores/{$this->storeId}/invoices/{$sessionId}");

        return [
            'id' => $response['id'],
            'status' => $this->mapInvoiceStatus($response['status']),
            'amount' => $response['amount'],
            'currency' => $response['currency'],
            'paid_at' => $response['status'] === 'Settled' ? now() : null,
            'metadata' => $response['metadata'] ?? [],
            'raw' => $response,
        ];
    }

    // Payments

    public function charge(Workspace $workspace, int $amountCents, string $currency, array $metadata = []): Payment
    {
        // BTCPay requires creating an invoice - customer pays by visiting checkout
        // This creates a "pending" invoice that awaits payment
        $response = $this->request('POST', "/api/v1/stores/{$this->storeId}/invoices", [
            'amount' => (string) ($amountCents / 100),
            'currency' => $currency,
            'metadata' => array_merge($metadata, [
                'workspace_id' => $workspace->id,
            ]),
        ]);

        return Payment::create([
            'workspace_id' => $workspace->id,
            'gateway' => 'btcpay',
            'gateway_payment_id' => $response['id'],
            'amount' => $amountCents / 100,
            'currency' => $currency,
            'status' => 'pending',
            'gateway_response' => $response,
        ]);
    }

    public function chargePaymentMethod(PaymentMethod $paymentMethod, int $amountCents, string $currency, array $metadata = []): Payment
    {
        // BTCPay doesn't support automatic recurring charges like traditional payment processors.
        // Each payment requires customer action (visiting checkout URL and sending crypto).
        //
        // For subscription renewals, we create a pending invoice that requires manual payment.
        // The dunning system will notify the customer, but auto-retry won't work for crypto.
        //
        // This returns a 'pending' payment - the webhook will update it when payment arrives.
        return $this->charge($paymentMethod->workspace, $amountCents, $currency, $metadata);
    }

    // Subscriptions - BTCPay doesn't natively support subscriptions
    // We implement a manual recurring billing approach

    public function createSubscription(Workspace $workspace, string $priceId, array $options = []): Subscription
    {
        // BTCPay doesn't have native subscription support
        // We create a local subscription record and manage billing manually
        $subscription = Subscription::create([
            'workspace_id' => $workspace->id,
            'gateway' => 'btcpay',
            'gateway_subscription_id' => 'btcsub_'.Str::ulid(),
            'gateway_customer_id' => $workspace->btcpay_customer_id,
            'gateway_price_id' => $priceId,
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(), // Default to monthly
            'trial_ends_at' => isset($options['trial_days']) && $options['trial_days'] > 0
                ? now()->addDays($options['trial_days'])
                : null,
        ]);

        return $subscription;
    }

    public function updateSubscription(Subscription $subscription, array $options): Subscription
    {
        // Update local subscription record
        $updates = [];

        if (isset($options['price_id'])) {
            $updates['gateway_price_id'] = $options['price_id'];
        }

        if (! empty($updates)) {
            $subscription->update($updates);
        }

        return $subscription->fresh();
    }

    public function cancelSubscription(Subscription $subscription, bool $immediately = false): void
    {
        $subscription->cancel($immediately);
    }

    public function resumeSubscription(Subscription $subscription): void
    {
        $subscription->resume();
    }

    public function pauseSubscription(Subscription $subscription): void
    {
        $subscription->pause();
    }

    // Payment Methods - BTCPay doesn't support saved payment methods

    public function createSetupSession(Workspace $workspace, string $returnUrl): array
    {
        // BTCPay doesn't support saving payment methods
        // Return a no-op response
        return [
            'session_id' => null,
            'setup_url' => $returnUrl,
        ];
    }

    public function attachPaymentMethod(Workspace $workspace, string $gatewayPaymentMethodId): PaymentMethod
    {
        // Create a placeholder payment method for crypto
        return PaymentMethod::create([
            'workspace_id' => $workspace->id,
            'gateway' => 'btcpay',
            'gateway_payment_method_id' => $gatewayPaymentMethodId,
            'type' => 'crypto',
            'is_default' => true,
        ]);
    }

    public function detachPaymentMethod(PaymentMethod $paymentMethod): void
    {
        $paymentMethod->delete();
    }

    public function setDefaultPaymentMethod(PaymentMethod $paymentMethod): void
    {
        // Unset other defaults
        PaymentMethod::where('workspace_id', $paymentMethod->workspace_id)
            ->where('id', '!=', $paymentMethod->id)
            ->update(['is_default' => false]);

        $paymentMethod->update(['is_default' => true]);
    }

    // Refunds

    public function refund(Payment $payment, float $amount, ?string $reason = null): array
    {
        // BTCPay refunds require manual processing via the API
        try {
            $response = $this->request('POST', "/api/v1/stores/{$this->storeId}/invoices/{$payment->gateway_payment_id}/refund", [
                'refundVariant' => 'Custom',
                'customAmount' => $amount,
                'customCurrency' => $payment->currency,
                'description' => $reason ?? 'Refund requested',
            ]);

            return [
                'success' => true,
                'refund_id' => $response['id'] ?? null,
                'gateway_response' => $response,
            ];
        } catch (\Exception $e) {
            Log::warning('BTCPay refund creation failed', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    // Invoices

    public function getInvoice(string $gatewayInvoiceId): array
    {
        return $this->request('GET', "/api/v1/stores/{$this->storeId}/invoices/{$gatewayInvoiceId}");
    }

    public function getInvoicePdfUrl(string $gatewayInvoiceId): ?string
    {
        // BTCPay doesn't provide invoice PDFs - we generate our own
        return null;
    }

    // Webhooks

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        if (! $this->webhookSecret) {
            Log::warning('BTCPay webhook: No webhook secret configured');

            return false;
        }

        if (empty($signature)) {
            Log::warning('BTCPay webhook: Empty signature provided');

            return false;
        }

        // BTCPay may send signature with 'sha256=' prefix
        $providedSignature = $signature;
        if (str_starts_with($signature, 'sha256=')) {
            $providedSignature = substr($signature, 7);
        }

        $expectedSignature = hash_hmac('sha256', $payload, $this->webhookSecret);

        if (! hash_equals($expectedSignature, $providedSignature)) {
            Log::warning('BTCPay webhook: Signature mismatch');

            return false;
        }

        return true;
    }

    /**
     * Parse and validate a webhook event payload.
     *
     * @throws WebhookPayloadValidationException When the payload is malformed or invalid
     */
    public function parseWebhookEvent(string $payload): array
    {
        // Step 1: Validate JSON structure
        $data = json_decode($payload, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $error = json_last_error_msg();

            Log::warning('BTCPay webhook: Invalid JSON payload', [
                'error' => $error,
            ]);

            throw WebhookPayloadValidationException::invalidJson('btcpay', $error);
        }

        // Step 2: Validate payload is an array/object
        if (! is_array($data)) {
            Log::warning('BTCPay webhook: Payload must be an object', [
                'type' => gettype($data),
            ]);

            throw WebhookPayloadValidationException::invalidFieldTypes('btcpay', [
                'payload' => ['expected' => 'object', 'actual' => gettype($data)],
            ]);
        }

        // Step 3: Validate required fields exist
        $this->validateRequiredFields($data);

        // Step 4: Validate field types
        $this->validateFieldTypes($data);

        // Step 5: Validate field values
        $this->validateFieldValues($data);

        $type = $data['type'] ?? 'unknown';
        $invoiceId = $data['invoiceId'] ?? $data['id'] ?? null;

        return [
            'type' => $this->mapWebhookEventType($type),
            'id' => $invoiceId,
            'status' => $this->mapInvoiceStatus($data['status'] ?? $data['afterExpiration'] ?? 'unknown'),
            'metadata' => $data['metadata'] ?? [],
            'raw' => $data,
        ];
    }

    /**
     * Validate that required fields are present in the webhook payload.
     *
     * BTCPay webhook payloads must contain at minimum:
     * - type: The event type (e.g., InvoiceSettled)
     * - invoiceId OR id: The invoice identifier
     *
     * @param  array<string, mixed>  $data
     *
     * @throws WebhookPayloadValidationException
     */
    protected function validateRequiredFields(array $data): void
    {
        $missingFields = [];

        // 'type' is required for all webhook events
        if (! array_key_exists('type', $data) || $data['type'] === null) {
            $missingFields[] = 'type';
        }

        // Either 'invoiceId' or 'id' must be present
        $hasInvoiceId = array_key_exists('invoiceId', $data) && $data['invoiceId'] !== null;
        $hasId = array_key_exists('id', $data) && $data['id'] !== null;

        if (! $hasInvoiceId && ! $hasId) {
            $missingFields[] = 'invoiceId or id';
        }

        if (! empty($missingFields)) {
            Log::warning('BTCPay webhook: Missing required fields', [
                'missing_fields' => $missingFields,
            ]);

            throw WebhookPayloadValidationException::missingFields('btcpay', $missingFields);
        }
    }

    /**
     * Validate that field types match expected schema.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws WebhookPayloadValidationException
     */
    protected function validateFieldTypes(array $data): void
    {
        $typeErrors = [];

        // 'type' must be a string
        if (isset($data['type']) && ! is_string($data['type'])) {
            $typeErrors['type'] = [
                'expected' => 'string',
                'actual' => gettype($data['type']),
            ];
        }

        // 'invoiceId' must be a string if present
        if (isset($data['invoiceId']) && ! is_string($data['invoiceId'])) {
            $typeErrors['invoiceId'] = [
                'expected' => 'string',
                'actual' => gettype($data['invoiceId']),
            ];
        }

        // 'id' must be a string if present
        if (isset($data['id']) && ! is_string($data['id'])) {
            $typeErrors['id'] = [
                'expected' => 'string',
                'actual' => gettype($data['id']),
            ];
        }

        // 'status' must be a string if present
        if (isset($data['status']) && ! is_string($data['status'])) {
            $typeErrors['status'] = [
                'expected' => 'string',
                'actual' => gettype($data['status']),
            ];
        }

        // 'metadata' must be an array/object if present
        if (isset($data['metadata']) && ! is_array($data['metadata'])) {
            $typeErrors['metadata'] = [
                'expected' => 'object',
                'actual' => gettype($data['metadata']),
            ];
        }

        // 'amount' must be numeric if present (string or number)
        if (isset($data['amount'])) {
            if (! is_numeric($data['amount'])) {
                $typeErrors['amount'] = [
                    'expected' => 'numeric',
                    'actual' => gettype($data['amount']),
                ];
            }
        }

        // 'currency' must be a string if present
        if (isset($data['currency']) && ! is_string($data['currency'])) {
            $typeErrors['currency'] = [
                'expected' => 'string',
                'actual' => gettype($data['currency']),
            ];
        }

        if (! empty($typeErrors)) {
            Log::warning('BTCPay webhook: Invalid field types', [
                'type_errors' => $typeErrors,
            ]);

            throw WebhookPayloadValidationException::invalidFieldTypes('btcpay', $typeErrors);
        }
    }

    /**
     * Validate that field values are within acceptable bounds.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws WebhookPayloadValidationException
     */
    protected function validateFieldValues(array $data): void
    {
        $valueErrors = [];

        // 'type' must not be empty
        if (isset($data['type']) && trim($data['type']) === '') {
            $valueErrors['type'] = 'must not be empty';
        }

        // 'type' must be a reasonable length (protection against oversized payloads)
        if (isset($data['type']) && strlen($data['type']) > 100) {
            $valueErrors['type'] = 'exceeds maximum length of 100 characters';
        }

        // 'invoiceId' must not exceed reasonable length
        if (isset($data['invoiceId']) && strlen($data['invoiceId']) > 255) {
            $valueErrors['invoiceId'] = 'exceeds maximum length of 255 characters';
        }

        // 'id' must not exceed reasonable length
        if (isset($data['id']) && strlen($data['id']) > 255) {
            $valueErrors['id'] = 'exceeds maximum length of 255 characters';
        }

        // 'currency' must be a valid 3-letter code if present
        if (isset($data['currency'])) {
            $currency = strtoupper(trim($data['currency']));
            if (strlen($currency) !== 3 || ! ctype_alpha($currency)) {
                $valueErrors['currency'] = 'must be a valid 3-letter currency code';
            }
        }

        // 'amount' must be non-negative if present
        if (isset($data['amount']) && is_numeric($data['amount'])) {
            if ((float) $data['amount'] < 0) {
                $valueErrors['amount'] = 'must not be negative';
            }
        }

        // 'status' must not exceed reasonable length
        if (isset($data['status']) && strlen($data['status']) > 50) {
            $valueErrors['status'] = 'exceeds maximum length of 50 characters';
        }

        if (! empty($valueErrors)) {
            Log::warning('BTCPay webhook: Invalid field values', [
                'value_errors' => $valueErrors,
            ]);

            throw WebhookPayloadValidationException::invalidFieldValues('btcpay', $valueErrors);
        }
    }

    // Tax

    public function createTaxRate(string $name, float $percentage, string $country, bool $inclusive = false): string
    {
        // BTCPay doesn't have tax rate management - handled locally
        return 'local_'.Str::slug($name);
    }

    // Portal

    public function getPortalUrl(Workspace $workspace, string $returnUrl): ?string
    {
        // BTCPay doesn't have a customer portal
        return null;
    }

    // Helper Methods

    protected function request(string $method, string $endpoint, array $data = []): array
    {
        if (! $this->baseUrl || ! $this->apiKey) {
            throw new \RuntimeException('BTCPay is not configured. Please check BTCPAY_URL and BTCPAY_API_KEY.');
        }

        $url = $this->baseUrl.$endpoint;

        $http = Http::withHeaders([
            'Authorization' => "token {$this->apiKey}",
            'Content-Type' => 'application/json',
        ])->timeout(30);

        $response = match (strtoupper($method)) {
            'GET' => $http->get($url, $data),
            'POST' => $http->post($url, $data),
            'PUT' => $http->put($url, $data),
            'DELETE' => $http->delete($url, $data),
            default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
        };

        if ($response->failed()) {
            // Sanitise error logging - don't expose full response body which may contain sensitive data
            $errorMessage = $this->sanitiseErrorMessage($response);

            Log::error('BTCPay API request failed', [
                'method' => $method,
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'error' => $errorMessage,
            ]);

            throw new \RuntimeException("BTCPay API request failed ({$response->status()}): {$errorMessage}");
        }

        return $response->json() ?? [];
    }

    /**
     * Extract a safe error message from a failed response.
     */
    protected function sanitiseErrorMessage(Response $response): string
    {
        $json = $response->json();

        // BTCPay returns structured errors
        if (isset($json['message'])) {
            return $json['message'];
        }

        if (isset($json['error'])) {
            return is_string($json['error']) ? $json['error'] : 'Unknown error';
        }

        // Map common HTTP status codes
        return match ($response->status()) {
            400 => 'Bad request',
            401 => 'Unauthorised - check API key',
            403 => 'Forbidden - insufficient permissions',
            404 => 'Resource not found',
            422 => 'Validation failed',
            429 => 'Rate limited',
            500, 502, 503, 504 => 'Server error',
            default => 'Request failed',
        };
    }

    protected function mapInvoiceStatus(string $status): string
    {
        return match (strtolower($status)) {
            'new' => 'pending',
            'processing' => 'processing',
            'expired' => 'expired',
            'invalid' => 'failed',
            'settled' => 'succeeded',
            'complete', 'confirmed' => 'succeeded',
            default => 'pending',
        };
    }

    protected function mapWebhookEventType(string $type): string
    {
        return match ($type) {
            'InvoiceCreated' => 'invoice.created',
            'InvoiceReceivedPayment' => 'invoice.payment_received',
            'InvoiceProcessing' => 'invoice.processing',
            'InvoiceExpired' => 'invoice.expired',
            'InvoiceSettled' => 'invoice.paid',
            'InvoiceInvalid' => 'invoice.failed',
            'InvoicePaymentSettled' => 'payment.settled',
            default => $type,
        };
    }
}
