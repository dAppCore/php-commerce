<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Services\PaymentGateway;

use Carbon\Carbon;
use Core\Mod\Commerce\Models\Order;
use Core\Mod\Commerce\Models\Payment;
use Core\Mod\Commerce\Models\PaymentMethod;
use Core\Mod\Commerce\Models\Refund;
use Core\Mod\Commerce\Models\Subscription;
use Core\Tenant\Models\Workspace;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\CardException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\RateLimitException;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * Stripe payment gateway implementation.
 *
 * Secondary gateway - implemented but not exposed to users initially.
 */
class StripeGateway implements PaymentGatewayContract
{
    protected ?StripeClient $stripe = null;

    protected string $webhookSecret;

    public function __construct()
    {
        $secret = config('commerce.gateways.stripe.secret');
        if ($secret) {
            $this->stripe = new StripeClient($secret);
        }
        $this->webhookSecret = config('commerce.gateways.stripe.webhook_secret') ?? '';
    }

    /**
     * Get the Stripe client instance.
     *
     * @throws \RuntimeException If Stripe is not configured.
     */
    protected function getStripe(): StripeClient
    {
        if (! $this->stripe) {
            throw new \RuntimeException('Stripe is not configured. Please set STRIPE_SECRET in your environment.');
        }

        return $this->stripe;
    }

    public function getIdentifier(): string
    {
        return 'stripe';
    }

    public function isEnabled(): bool
    {
        return config('commerce.gateways.stripe.enabled', false)
            && $this->stripe !== null;
    }

    // Customer Management

    public function createCustomer(Workspace $workspace): string
    {
        $customer = $this->getStripe()->customers->create([
            'name' => $workspace->billing_name ?? $workspace->name,
            'email' => $workspace->billing_email,
            'address' => [
                'line1' => $workspace->billing_address_line1,
                'line2' => $workspace->billing_address_line2,
                'city' => $workspace->billing_city,
                'state' => $workspace->billing_state,
                'postal_code' => $workspace->billing_postal_code,
                'country' => $workspace->billing_country,
            ],
            'metadata' => [
                'workspace_id' => $workspace->id,
                'workspace_slug' => $workspace->slug,
            ],
        ]);

        $workspace->update(['stripe_customer_id' => $customer->id]);

        return $customer->id;
    }

    public function updateCustomer(Workspace $workspace): void
    {
        if (! $workspace->stripe_customer_id) {
            return;
        }

        $this->getStripe()->customers->update($workspace->stripe_customer_id, [
            'name' => $workspace->billing_name ?? $workspace->name,
            'email' => $workspace->billing_email,
            'address' => [
                'line1' => $workspace->billing_address_line1,
                'line2' => $workspace->billing_address_line2,
                'city' => $workspace->billing_city,
                'state' => $workspace->billing_state,
                'postal_code' => $workspace->billing_postal_code,
                'country' => $workspace->billing_country,
            ],
        ]);
    }

    // Checkout

    public function createCheckoutSession(Order $order, string $successUrl, string $cancelUrl): array
    {
        try {
            $lineItems = $this->buildLineItems($order);

            // Ensure customer exists
            $customerId = $order->workspace->stripe_customer_id;
            if (! $customerId) {
                $customerId = $this->createCustomer($order->workspace);
            }

            $sessionParams = [
                'customer' => $customerId,
                'line_items' => $lineItems,
                'mode' => $this->hasRecurringItems($order) ? 'subscription' : 'payment',
                'success_url' => $successUrl.'?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $cancelUrl,
                'metadata' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'workspace_id' => $order->workspace_id,
                ],
                'automatic_tax' => ['enabled' => false], // We handle tax ourselves
                'allow_promotion_codes' => false, // We handle coupons ourselves
            ];

            // Add discount if applicable
            if ($order->discount_amount > 0 && $order->coupon) {
                $sessionParams['discounts'] = [['coupon' => $this->createOrderCoupon($order)]];
            }

            $session = $this->getStripe()->checkout->sessions->create($sessionParams);

            $order->update(['gateway_session_id' => $session->id]);

            return [
                'session_id' => $session->id,
                'checkout_url' => $session->url,
            ];
        } catch (CardException $e) {
            Log::warning('Stripe checkout failed: card error', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'code' => $e->getStripeCode(),
            ]);
            throw new \RuntimeException('Payment card error: '.$e->getMessage(), 0, $e);
        } catch (RateLimitException $e) {
            Log::error('Stripe checkout failed: rate limit', [
                'order_id' => $order->id,
            ]);
            throw new \RuntimeException('Payment service temporarily unavailable. Please try again.', 0, $e);
        } catch (InvalidRequestException $e) {
            Log::error('Stripe checkout failed: invalid request', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'param' => $e->getStripeParam(),
            ]);
            throw new \RuntimeException('Unable to create checkout session. Please contact support.', 0, $e);
        } catch (AuthenticationException $e) {
            Log::critical('Stripe authentication failed - check API keys', [
                'order_id' => $order->id,
            ]);
            throw new \RuntimeException('Payment service configuration error. Please contact support.', 0, $e);
        } catch (ApiConnectionException $e) {
            Log::error('Stripe checkout failed: connection error', [
                'order_id' => $order->id,
            ]);
            throw new \RuntimeException('Unable to connect to payment service. Please try again.', 0, $e);
        } catch (ApiErrorException $e) {
            Log::error('Stripe checkout failed: API error', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Payment service error. Please try again or contact support.', 0, $e);
        }
    }

    /**
     * Build line items array for Stripe checkout session.
     */
    protected function buildLineItems(Order $order): array
    {
        $lineItems = [];

        foreach ($order->items as $item) {
            $lineItem = [
                'price_data' => [
                    'currency' => strtolower($order->currency),
                    'product_data' => [
                        'name' => $item->name,
                    ],
                    'unit_amount' => (int) round($item->unit_price * 100),
                ],
                'quantity' => $item->quantity,
            ];

            // Only add description if present (Stripe rejects empty strings)
            if (! empty($item->description)) {
                $lineItem['price_data']['product_data']['description'] = $item->description;
            }

            // Add recurring config if applicable
            if ($item->billing_cycle) {
                $lineItem['price_data']['recurring'] = [
                    'interval' => $item->billing_cycle === 'yearly' ? 'year' : 'month',
                ];
            }

            $lineItems[] = $lineItem;
        }

        return $lineItems;
    }

    /**
     * Create a one-time Stripe coupon for an order discount.
     */
    protected function createOrderCoupon(Order $order): string
    {
        $stripeCoupon = $this->getStripe()->coupons->create([
            'amount_off' => (int) round($order->discount_amount * 100),
            'currency' => strtolower($order->currency),
            'duration' => 'once',
            'name' => $order->coupon->code,
        ]);

        return $stripeCoupon->id;
    }

    public function getCheckoutSession(string $sessionId): array
    {
        $session = $this->getStripe()->checkout->sessions->retrieve($sessionId, [
            'expand' => ['payment_intent', 'subscription'],
        ]);

        return [
            'id' => $session->id,
            'status' => $this->mapSessionStatus($session->status),
            'amount' => $session->amount_total / 100,
            'currency' => strtoupper($session->currency),
            'paid_at' => $session->payment_status === 'paid' ? now() : null,
            'subscription_id' => $session->subscription?->id,
            'payment_intent_id' => $session->payment_intent?->id,
            'metadata' => (array) $session->metadata,
            'raw' => $session,
        ];
    }

    // Payments

    public function charge(Workspace $workspace, int $amountCents, string $currency, array $metadata = []): Payment
    {
        $customerId = $workspace->stripe_customer_id;
        if (! $customerId) {
            $customerId = $this->createCustomer($workspace);
        }

        $paymentIntent = $this->getStripe()->paymentIntents->create([
            'amount' => $amountCents,
            'currency' => strtolower($currency),
            'customer' => $customerId,
            'metadata' => array_merge($metadata, ['workspace_id' => $workspace->id]),
            'automatic_payment_methods' => ['enabled' => true],
        ]);

        return Payment::create([
            'workspace_id' => $workspace->id,
            'gateway' => 'stripe',
            'gateway_payment_id' => $paymentIntent->id,
            'amount' => $amountCents / 100,
            'currency' => strtoupper($currency),
            'status' => $this->mapPaymentIntentStatus($paymentIntent->status),
            'gateway_response' => $paymentIntent->toArray(),
        ]);
    }

    public function chargePaymentMethod(PaymentMethod $paymentMethod, int $amountCents, string $currency, array $metadata = []): Payment
    {
        $workspace = $paymentMethod->workspace;

        $paymentIntent = $this->getStripe()->paymentIntents->create([
            'amount' => $amountCents,
            'currency' => strtolower($currency),
            'customer' => $workspace->stripe_customer_id,
            'payment_method' => $paymentMethod->gateway_payment_method_id,
            'off_session' => true,
            'confirm' => true,
            'metadata' => array_merge($metadata, ['workspace_id' => $workspace->id]),
        ]);

        return Payment::create([
            'workspace_id' => $workspace->id,
            'gateway' => 'stripe',
            'gateway_payment_id' => $paymentIntent->id,
            'payment_method_id' => $paymentMethod->id,
            'amount' => $amountCents / 100,
            'currency' => strtoupper($currency),
            'status' => $this->mapPaymentIntentStatus($paymentIntent->status),
            'gateway_response' => $paymentIntent->toArray(),
        ]);
    }

    // Subscriptions

    public function createSubscription(Workspace $workspace, string $priceId, array $options = []): Subscription
    {
        $customerId = $workspace->stripe_customer_id;
        if (! $customerId) {
            $customerId = $this->createCustomer($workspace);
        }

        $params = [
            'customer' => $customerId,
            'items' => [['price' => $priceId]],
            'metadata' => ['workspace_id' => $workspace->id],
        ];

        if (isset($options['trial_days']) && $options['trial_days'] > 0) {
            $params['trial_period_days'] = $options['trial_days'];
        }

        if (isset($options['coupon'])) {
            $params['coupon'] = $options['coupon'];
        }

        $stripeSubscription = $this->getStripe()->subscriptions->create($params);

        return Subscription::create([
            'workspace_id' => $workspace->id,
            'gateway' => 'stripe',
            'gateway_subscription_id' => $stripeSubscription->id,
            'gateway_customer_id' => $customerId,
            'gateway_price_id' => $priceId,
            'status' => $this->mapSubscriptionStatus($stripeSubscription->status),
            'current_period_start' => Carbon::createFromTimestamp($stripeSubscription->current_period_start),
            'current_period_end' => Carbon::createFromTimestamp($stripeSubscription->current_period_end),
            'trial_ends_at' => $stripeSubscription->trial_end
                ? Carbon::createFromTimestamp($stripeSubscription->trial_end)
                : null,
            'metadata' => ['stripe_subscription' => $stripeSubscription->toArray()],
        ]);
    }

    public function updateSubscription(Subscription $subscription, array $options): Subscription
    {
        $params = [];

        if (isset($options['price_id'])) {
            $params['items'] = [
                [
                    'id' => $this->getSubscriptionItemId($subscription),
                    'price' => $options['price_id'],
                ],
            ];
            $params['proration_behavior'] = ($options['prorate'] ?? true)
                ? 'create_prorations'
                : 'none';
        }

        if (isset($options['cancel_at_period_end'])) {
            $params['cancel_at_period_end'] = $options['cancel_at_period_end'];
        }

        $stripeSubscription = $this->getStripe()->subscriptions->update(
            $subscription->gateway_subscription_id,
            $params
        );

        $subscription->update([
            'gateway_price_id' => $options['price_id'] ?? $subscription->gateway_price_id,
            'status' => $this->mapSubscriptionStatus($stripeSubscription->status),
            'cancel_at_period_end' => $stripeSubscription->cancel_at_period_end,
            'current_period_start' => Carbon::createFromTimestamp($stripeSubscription->current_period_start),
            'current_period_end' => Carbon::createFromTimestamp($stripeSubscription->current_period_end),
        ]);

        return $subscription->fresh();
    }

    public function cancelSubscription(Subscription $subscription, bool $immediately = false): void
    {
        if ($immediately) {
            $this->getStripe()->subscriptions->cancel($subscription->gateway_subscription_id);
            $subscription->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'ended_at' => now(),
            ]);
        } else {
            $this->getStripe()->subscriptions->update($subscription->gateway_subscription_id, [
                'cancel_at_period_end' => true,
            ]);
            $subscription->update([
                'cancel_at_period_end' => true,
                'cancelled_at' => now(),
            ]);
        }
    }

    public function resumeSubscription(Subscription $subscription): void
    {
        $this->getStripe()->subscriptions->update($subscription->gateway_subscription_id, [
            'cancel_at_period_end' => false,
        ]);

        $subscription->resume();
    }

    public function pauseSubscription(Subscription $subscription): void
    {
        $this->getStripe()->subscriptions->update($subscription->gateway_subscription_id, [
            'pause_collection' => ['behavior' => 'void'],
        ]);

        $subscription->pause();
    }

    // Payment Methods

    public function createSetupSession(Workspace $workspace, string $returnUrl): array
    {
        $customerId = $workspace->stripe_customer_id;
        if (! $customerId) {
            $customerId = $this->createCustomer($workspace);
        }

        $session = $this->getStripe()->checkout->sessions->create([
            'customer' => $customerId,
            'mode' => 'setup',
            'success_url' => $returnUrl.'?setup_intent={SETUP_INTENT}',
            'cancel_url' => $returnUrl,
        ]);

        return [
            'session_id' => $session->id,
            'setup_url' => $session->url,
        ];
    }

    public function attachPaymentMethod(Workspace $workspace, string $gatewayPaymentMethodId): PaymentMethod
    {
        $stripePaymentMethod = $this->getStripe()->paymentMethods->attach($gatewayPaymentMethodId, [
            'customer' => $workspace->stripe_customer_id,
        ]);

        return PaymentMethod::create([
            'workspace_id' => $workspace->id,
            'gateway' => 'stripe',
            'gateway_payment_method_id' => $stripePaymentMethod->id,
            'type' => $stripePaymentMethod->type,
            'last_four' => $stripePaymentMethod->card?->last4,
            'brand' => $stripePaymentMethod->card?->brand,
            'exp_month' => $stripePaymentMethod->card?->exp_month,
            'exp_year' => $stripePaymentMethod->card?->exp_year,
            'is_default' => false,
        ]);
    }

    public function detachPaymentMethod(PaymentMethod $paymentMethod): void
    {
        $this->getStripe()->paymentMethods->detach($paymentMethod->gateway_payment_method_id);
        // Don't delete - the PaymentMethodService handles deactivation
    }

    public function setDefaultPaymentMethod(PaymentMethod $paymentMethod): void
    {
        $this->getStripe()->customers->update($paymentMethod->workspace->stripe_customer_id, [
            'invoice_settings' => [
                'default_payment_method' => $paymentMethod->gateway_payment_method_id,
            ],
        ]);

        // Update local records
        PaymentMethod::where('workspace_id', $paymentMethod->workspace_id)
            ->where('id', '!=', $paymentMethod->id)
            ->update(['is_default' => false]);

        $paymentMethod->update(['is_default' => true]);
    }

    // Refunds

    public function refund(Payment $payment, float $amount, ?string $reason = null): array
    {
        $amountCents = (int) round($amount * 100);

        try {
            $stripeRefund = $this->getStripe()->refunds->create([
                'payment_intent' => $payment->gateway_payment_id,
                'amount' => $amountCents,
                'reason' => $this->mapRefundReason($reason),
            ]);

            $refund = Refund::create([
                'payment_id' => $payment->id,
                'gateway_refund_id' => $stripeRefund->id,
                'amount' => $amount,
                'currency' => $payment->currency,
                'status' => $stripeRefund->status === 'succeeded' ? 'succeeded' : 'pending',
                'reason' => $reason,
                'gateway_response' => $stripeRefund->toArray(),
            ]);

            if ($stripeRefund->status === 'succeeded') {
                $refund->markAsSucceeded($stripeRefund->id);
            }

            return [
                'success' => true,
                'refund_id' => $stripeRefund->id,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    // Invoices

    public function getInvoice(string $gatewayInvoiceId): array
    {
        $invoice = $this->getStripe()->invoices->retrieve($gatewayInvoiceId);

        return $invoice->toArray();
    }

    public function getInvoicePdfUrl(string $gatewayInvoiceId): ?string
    {
        $invoice = $this->getStripe()->invoices->retrieve($gatewayInvoiceId);

        return $invoice->invoice_pdf;
    }

    // Webhooks

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        try {
            Webhook::constructEvent($payload, $signature, $this->webhookSecret);

            return true;
        } catch (\Exception $e) {
            Log::warning('Stripe webhook signature verification failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    public function parseWebhookEvent(string $payload): array
    {
        $event = json_decode($payload, true);

        return [
            'type' => $event['type'] ?? 'unknown',
            'id' => $event['data']['object']['id'] ?? null,
            'object_type' => $event['data']['object']['object'] ?? null,
            'metadata' => $event['data']['object']['metadata'] ?? [],
            'raw' => $event,
        ];
    }

    // Tax

    public function createTaxRate(string $name, float $percentage, string $country, bool $inclusive = false): string
    {
        $taxRate = $this->getStripe()->taxRates->create([
            'display_name' => $name,
            'percentage' => $percentage,
            'country' => $country,
            'inclusive' => $inclusive,
        ]);

        return $taxRate->id;
    }

    // Portal

    public function getPortalUrl(Workspace $workspace, string $returnUrl): ?string
    {
        if (! $workspace->stripe_customer_id) {
            return null;
        }

        $session = $this->getStripe()->billingPortal->sessions->create([
            'customer' => $workspace->stripe_customer_id,
            'return_url' => $returnUrl,
        ]);

        return $session->url;
    }

    // Helper Methods

    protected function hasRecurringItems(Order $order): bool
    {
        return $order->items->contains(fn ($item) => $item->billing_cycle !== null);
    }

    protected function getSubscriptionItemId(Subscription $subscription): string
    {
        $stripeSubscription = $this->getStripe()->subscriptions->retrieve($subscription->gateway_subscription_id);

        return $stripeSubscription->items->data[0]->id;
    }

    protected function mapSessionStatus(string $status): string
    {
        return match ($status) {
            'complete' => 'succeeded',
            'expired' => 'expired',
            'open' => 'pending',
            default => 'pending',
        };
    }

    protected function mapPaymentIntentStatus(string $status): string
    {
        return match ($status) {
            'succeeded' => 'succeeded',
            'processing' => 'processing',
            'requires_payment_method', 'requires_confirmation', 'requires_action' => 'pending',
            'canceled' => 'failed',
            default => 'pending',
        };
    }

    protected function mapSubscriptionStatus(string $status): string
    {
        return match ($status) {
            'active' => 'active',
            'trialing' => 'trialing',
            'past_due' => 'past_due',
            'paused' => 'paused',
            'canceled' => 'cancelled',
            'incomplete', 'incomplete_expired' => 'incomplete',
            default => 'active',
        };
    }

    protected function mapRefundReason(?string $reason): string
    {
        return match ($reason) {
            'duplicate' => 'duplicate',
            'fraudulent' => 'fraudulent',
            default => 'requested_by_customer',
        };
    }
}
