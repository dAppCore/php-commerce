<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Services\PaymentGateway;

use Core\Mod\Commerce\Models\Order;
use Core\Mod\Commerce\Models\Payment;
use Core\Mod\Commerce\Models\PaymentMethod;
use Core\Mod\Commerce\Models\Refund;
use Core\Mod\Commerce\Models\Subscription;
use Core\Tenant\Models\Workspace;

/**
 * Contract for payment gateway implementations.
 *
 * Implemented by BTCPayGateway (primary) and StripeGateway (secondary).
 */
interface PaymentGatewayContract
{
    /**
     * Get the gateway identifier.
     */
    public function getIdentifier(): string;

    /**
     * Check if the gateway is enabled.
     */
    public function isEnabled(): bool;

    // Customer Management

    /**
     * Create or retrieve a customer in the gateway.
     */
    public function createCustomer(Workspace $workspace): string;

    /**
     * Update customer details in the gateway.
     */
    public function updateCustomer(Workspace $workspace): void;

    // Checkout

    /**
     * Create a checkout session for an order.
     *
     * @return array{session_id: string, checkout_url: string}
     */
    public function createCheckoutSession(Order $order, string $successUrl, string $cancelUrl): array;

    /**
     * Retrieve checkout session status.
     */
    public function getCheckoutSession(string $sessionId): array;

    // Payments

    /**
     * Create a one-time payment charge.
     */
    public function charge(Workspace $workspace, int $amountCents, string $currency, array $metadata = []): Payment;

    /**
     * Charge using a saved payment method.
     */
    public function chargePaymentMethod(PaymentMethod $paymentMethod, int $amountCents, string $currency, array $metadata = []): Payment;

    // Subscriptions

    /**
     * Create a subscription.
     */
    public function createSubscription(Workspace $workspace, string $priceId, array $options = []): Subscription;

    /**
     * Update a subscription (change plan, quantity, etc.).
     */
    public function updateSubscription(Subscription $subscription, array $options): Subscription;

    /**
     * Cancel a subscription.
     */
    public function cancelSubscription(Subscription $subscription, bool $immediately = false): void;

    /**
     * Resume a cancelled subscription (if still in grace period).
     */
    public function resumeSubscription(Subscription $subscription): void;

    /**
     * Pause a subscription.
     */
    public function pauseSubscription(Subscription $subscription): void;

    // Payment Methods

    /**
     * Create a setup session for adding a payment method.
     *
     * @return array{session_id: string, setup_url: string}
     */
    public function createSetupSession(Workspace $workspace, string $returnUrl): array;

    /**
     * Attach a payment method to a customer.
     */
    public function attachPaymentMethod(Workspace $workspace, string $gatewayPaymentMethodId): PaymentMethod;

    /**
     * Detach/delete a payment method.
     */
    public function detachPaymentMethod(PaymentMethod $paymentMethod): void;

    /**
     * Set a payment method as default.
     */
    public function setDefaultPaymentMethod(PaymentMethod $paymentMethod): void;

    // Refunds

    /**
     * Process a refund through the gateway.
     *
     * @return array{success: bool, refund_id?: string, error?: string}
     */
    public function refund(Payment $payment, float $amount, ?string $reason = null): array;

    // Invoices

    /**
     * Retrieve an invoice from the gateway.
     */
    public function getInvoice(string $gatewayInvoiceId): array;

    /**
     * Get invoice PDF URL.
     */
    public function getInvoicePdfUrl(string $gatewayInvoiceId): ?string;

    // Webhooks

    /**
     * Verify webhook signature.
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool;

    /**
     * Parse webhook event.
     */
    public function parseWebhookEvent(string $payload): array;

    // Tax

    /**
     * Create a tax rate in the gateway.
     */
    public function createTaxRate(string $name, float $percentage, string $country, bool $inclusive = false): string;

    // Portal

    /**
     * Get customer portal URL (if supported).
     */
    public function getPortalUrl(Workspace $workspace, string $returnUrl): ?string;
}
