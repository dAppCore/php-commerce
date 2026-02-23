<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Controllers\Webhooks;

use Carbon\Carbon;
use Core\Front\Controller;
use Core\Mod\Commerce\Models\Order;
use Core\Mod\Commerce\Models\Payment;
use Core\Mod\Commerce\Models\PaymentMethod;
use Core\Mod\Commerce\Models\Subscription;
use Core\Mod\Commerce\Models\WebhookEvent;
use Core\Mod\Commerce\Notifications\OrderConfirmation;
use Core\Mod\Commerce\Notifications\PaymentFailed;
use Core\Mod\Commerce\Notifications\SubscriptionCancelled;
use Core\Mod\Commerce\Services\CommerceService;
use Core\Mod\Commerce\Services\FraudService;
use Core\Mod\Commerce\Services\InvoiceService;
use Core\Mod\Commerce\Services\PaymentGateway\StripeGateway;
use Core\Mod\Commerce\Services\WebhookLogger;
use Core\Mod\Commerce\Services\WebhookRateLimiter;
use Core\Tenant\Models\Workspace;
use Core\Tenant\Services\EntitlementService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Handle Stripe webhooks.
 *
 * Key events:
 * - checkout.session.completed: One-time payment or subscription started
 * - invoice.paid: Subscription renewal successful
 * - invoice.payment_failed: Payment failed
 * - customer.subscription.updated: Plan change, pause, etc.
 * - customer.subscription.deleted: Subscription cancelled
 * - payment_method.attached/detached: Card updates
 */
class StripeWebhookController extends Controller
{
    public function __construct(
        protected StripeGateway $gateway,
        protected CommerceService $commerce,
        protected InvoiceService $invoiceService,
        protected EntitlementService $entitlements,
        protected WebhookLogger $webhookLogger,
        protected FraudService $fraudService,
        protected WebhookRateLimiter $rateLimiter,
    ) {}

    public function handle(Request $request): Response
    {
        // Check IP-based rate limiting before processing
        if ($this->rateLimiter->tooManyAttempts($request, 'stripe')) {
            $retryAfter = $this->rateLimiter->availableIn($request, 'stripe');

            Log::warning('Stripe webhook rate limit exceeded', [
                'ip' => $request->ip(),
                'retry_after' => $retryAfter,
            ]);

            return response('Too Many Requests', 429)
                ->header('Retry-After', (string) $retryAfter)
                ->header('X-RateLimit-Remaining', '0')
                ->header('X-RateLimit-Reset', (string) (time() + $retryAfter));
        }

        // Increment rate limit counter
        $this->rateLimiter->increment($request, 'stripe');

        $payload = $request->getContent();
        $signature = $request->header('Stripe-Signature');

        // Verify webhook signature
        if (! $this->gateway->verifyWebhookSignature($payload, $signature)) {
            Log::warning('Stripe webhook signature verification failed');

            return response('Invalid signature', 401);
        }

        $event = $this->gateway->parseWebhookEvent($payload);

        // Log the webhook event for audit trail (also handles deduplication via unique constraint)
        $webhookEvent = $this->webhookLogger->startFromParsedEvent('stripe', $event, $payload, $request);

        // Idempotency check: if this event was already processed, return success without reprocessing
        if ($this->isAlreadyProcessed($webhookEvent, $event)) {
            Log::info('Stripe webhook already processed (idempotency check)', [
                'type' => $event['type'],
                'id' => $event['id'],
            ]);

            return response('Already processed (duplicate)', 200);
        }

        Log::info('Stripe webhook received', [
            'type' => $event['type'],
            'id' => $event['id'],
        ]);

        try {
            // Wrap all webhook processing in a transaction to ensure data integrity
            $response = DB::transaction(function () use ($event) {
                return match ($event['type']) {
                    'checkout.session.completed' => $this->handleCheckoutCompleted($event),
                    'invoice.paid' => $this->handleInvoicePaid($event),
                    'invoice.payment_failed' => $this->handleInvoicePaymentFailed($event),
                    'customer.subscription.created' => $this->handleSubscriptionCreated($event),
                    'customer.subscription.updated' => $this->handleSubscriptionUpdated($event),
                    'customer.subscription.deleted' => $this->handleSubscriptionDeleted($event),
                    'payment_method.attached' => $this->handlePaymentMethodAttached($event),
                    'payment_method.detached' => $this->handlePaymentMethodDetached($event),
                    'payment_method.updated' => $this->handlePaymentMethodUpdated($event),
                    'setup_intent.succeeded' => $this->handleSetupIntentSucceeded($event),
                    'charge.succeeded' => $this->handleChargeSucceeded($event),
                    'payment_intent.succeeded' => $this->handlePaymentIntentSucceeded($event),
                    default => $this->handleUnknownEvent($event),
                };
            });

            $this->webhookLogger->success($response);

            return $response;
        } catch (\Exception $e) {
            Log::error('Stripe webhook processing error', [
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
     * duplicate webhook deliveries from Stripe.
     */
    protected function isAlreadyProcessed(WebhookEvent $webhookEvent, array $event): bool
    {
        // If no event ID, we can't deduplicate
        if (empty($event['id'])) {
            return false;
        }

        // If the webhook event we just created has a different ID than the one
        // that already existed in the database, it means this is a duplicate
        $existingEvent = WebhookEvent::where('gateway', 'stripe')
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

    protected function handleCheckoutCompleted(array $event): Response
    {
        $session = $event['raw']['data']['object'];
        $orderId = $session['metadata']['order_id'] ?? null;

        if (! $orderId) {
            Log::warning('Stripe checkout.session.completed: No order_id in metadata');

            return response('No order_id', 200);
        }

        $order = Order::find($orderId);

        if (! $order) {
            Log::warning('Stripe checkout: Order not found', ['order_id' => $orderId]);

            return response('Order not found', 200);
        }

        // Link webhook event to order for audit trail
        $this->webhookLogger->linkOrder($order);

        // Skip if already paid
        if ($order->isPaid()) {
            return response('Already processed', 200);
        }

        // Create payment record
        $payment = Payment::create([
            'workspace_id' => $order->workspace_id,
            'order_id' => $order->id,
            'gateway' => 'stripe',
            'gateway_payment_id' => $session['payment_intent'] ?? $session['id'],
            'amount' => ($session['amount_total'] ?? 0) / 100,
            'currency' => strtoupper($session['currency'] ?? 'GBP'),
            'status' => 'succeeded',
            'paid_at' => now(),
            'gateway_response' => $session,
        ]);

        // Handle subscription if present
        if (! empty($session['subscription'])) {
            $this->createOrUpdateSubscriptionFromSession($order, $session);
        }

        // Fulfil the order
        $this->commerce->fulfillOrder($order, $payment);

        // Send confirmation
        $this->sendOrderConfirmation($order);

        Log::info('Stripe order fulfilled', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
        ]);

        return response('OK', 200);
    }

    protected function handleInvoicePaid(array $event): Response
    {
        $invoice = $event['raw']['data']['object'];
        $subscriptionId = $invoice['subscription'] ?? null;

        if (! $subscriptionId) {
            // One-time invoice, not subscription
            return response('OK', 200);
        }

        $subscription = Subscription::where('gateway', 'stripe')
            ->where('gateway_subscription_id', $subscriptionId)
            ->first();

        if (! $subscription) {
            Log::warning('Stripe invoice.paid: Subscription not found', ['subscription_id' => $subscriptionId]);

            return response('Subscription not found', 200);
        }

        // Link webhook event to subscription for audit trail
        $this->webhookLogger->linkSubscription($subscription);

        // Update subscription period
        $subscription->renew(
            Carbon::createFromTimestamp($invoice['period_start']),
            Carbon::createFromTimestamp($invoice['period_end'])
        );

        // Create payment record
        $payment = Payment::create([
            'workspace_id' => $subscription->workspace_id,
            'gateway' => 'stripe',
            'gateway_payment_id' => $invoice['payment_intent'] ?? $invoice['id'],
            'amount' => ($invoice['amount_paid'] ?? 0) / 100,
            'currency' => strtoupper($invoice['currency'] ?? 'GBP'),
            'status' => 'succeeded',
            'paid_at' => now(),
            'gateway_response' => $invoice,
        ]);

        // Create local invoice
        $this->invoiceService->createForRenewal(
            $subscription->workspace,
            $payment->amount,
            'Subscription renewal',
            $payment
        );

        Log::info('Stripe subscription renewed', [
            'subscription_id' => $subscription->id,
            'payment_id' => $payment->id,
        ]);

        return response('OK', 200);
    }

    protected function handleInvoicePaymentFailed(array $event): Response
    {
        $invoice = $event['raw']['data']['object'];
        $subscriptionId = $invoice['subscription'] ?? null;

        if (! $subscriptionId) {
            return response('OK', 200);
        }

        $subscription = Subscription::where('gateway', 'stripe')
            ->where('gateway_subscription_id', $subscriptionId)
            ->first();

        if ($subscription) {
            $subscription->markPastDue();

            // Send notification
            $owner = $subscription->workspace->owner();
            if ($owner && config('commerce.notifications.payment_failed', true)) {
                $owner->notify(new PaymentFailed($subscription));
            }
        }

        return response('OK', 200);
    }

    protected function handleSubscriptionCreated(array $event): Response
    {
        // Usually handled by checkout.session.completed
        // This is a fallback for direct API subscription creation
        return response('OK', 200);
    }

    protected function handleSubscriptionUpdated(array $event): Response
    {
        $stripeSubscription = $event['raw']['data']['object'];

        $subscription = Subscription::where('gateway', 'stripe')
            ->where('gateway_subscription_id', $stripeSubscription['id'])
            ->first();

        if (! $subscription) {
            return response('Subscription not found', 200);
        }

        $subscription->update([
            'status' => $this->mapStripeStatus($stripeSubscription['status']),
            'cancel_at_period_end' => $stripeSubscription['cancel_at_period_end'] ?? false,
            'current_period_start' => Carbon::createFromTimestamp($stripeSubscription['current_period_start']),
            'current_period_end' => Carbon::createFromTimestamp($stripeSubscription['current_period_end']),
        ]);

        return response('OK', 200);
    }

    protected function handleSubscriptionDeleted(array $event): Response
    {
        $stripeSubscription = $event['raw']['data']['object'];

        $subscription = Subscription::where('gateway', 'stripe')
            ->where('gateway_subscription_id', $stripeSubscription['id'])
            ->first();

        if ($subscription) {
            $subscription->update([
                'status' => 'cancelled',
                'ended_at' => now(),
            ]);

            // Revoke entitlements
            $workspacePackage = $subscription->workspacePackage;
            if ($workspacePackage) {
                $this->entitlements->revokePackage(
                    $subscription->workspace,
                    $workspacePackage->package->code
                );
            }

            // Send notification
            $owner = $subscription->workspace->owner();
            if ($owner && config('commerce.notifications.subscription_cancelled', true)) {
                $owner->notify(new SubscriptionCancelled($subscription));
            }
        }

        return response('OK', 200);
    }

    protected function handlePaymentMethodAttached(array $event): Response
    {
        $stripePaymentMethod = $event['raw']['data']['object'];
        $customerId = $stripePaymentMethod['customer'] ?? null;

        if (! $customerId) {
            return response('OK', 200);
        }

        $workspace = Workspace::where('stripe_customer_id', $customerId)->first();

        if (! $workspace) {
            return response('Workspace not found', 200);
        }

        // Check if payment method already exists
        $exists = PaymentMethod::where('gateway', 'stripe')
            ->where('gateway_payment_method_id', $stripePaymentMethod['id'])
            ->exists();

        if (! $exists) {
            PaymentMethod::create([
                'workspace_id' => $workspace->id,
                'gateway' => 'stripe',
                'gateway_payment_method_id' => $stripePaymentMethod['id'],
                'type' => $stripePaymentMethod['type'] ?? 'card',
                'last_four' => $stripePaymentMethod['card']['last4'] ?? null,
                'brand' => $stripePaymentMethod['card']['brand'] ?? null,
                'exp_month' => $stripePaymentMethod['card']['exp_month'] ?? null,
                'exp_year' => $stripePaymentMethod['card']['exp_year'] ?? null,
                'is_default' => false,
            ]);
        }

        return response('OK', 200);
    }

    protected function handlePaymentMethodDetached(array $event): Response
    {
        $stripePaymentMethod = $event['raw']['data']['object'];

        // Soft-delete by marking as inactive (don't hard delete for audit trail)
        PaymentMethod::where('gateway', 'stripe')
            ->where('gateway_payment_method_id', $stripePaymentMethod['id'])
            ->update(['is_active' => false]);

        return response('OK', 200);
    }

    /**
     * Handle payment method updates (e.g., card expiry update from card networks).
     */
    protected function handlePaymentMethodUpdated(array $event): Response
    {
        $stripePaymentMethod = $event['raw']['data']['object'];

        $paymentMethod = PaymentMethod::where('gateway', 'stripe')
            ->where('gateway_payment_method_id', $stripePaymentMethod['id'])
            ->first();

        if ($paymentMethod) {
            $card = $stripePaymentMethod['card'] ?? [];
            $paymentMethod->update([
                'brand' => $card['brand'] ?? $paymentMethod->brand,
                'last_four' => $card['last4'] ?? $paymentMethod->last_four,
                'exp_month' => $card['exp_month'] ?? $paymentMethod->exp_month,
                'exp_year' => $card['exp_year'] ?? $paymentMethod->exp_year,
            ]);
        }

        return response('OK', 200);
    }

    /**
     * Handle setup intent success (new payment method added via hosted setup page).
     */
    protected function handleSetupIntentSucceeded(array $event): Response
    {
        $setupIntent = $event['raw']['data']['object'];
        $customerId = $setupIntent['customer'] ?? null;
        $paymentMethodId = $setupIntent['payment_method'] ?? null;

        if (! $customerId || ! $paymentMethodId) {
            return response('OK', 200);
        }

        $workspace = Workspace::where('stripe_customer_id', $customerId)->first();

        if (! $workspace) {
            Log::warning('Stripe setup_intent.succeeded: Workspace not found', ['customer_id' => $customerId]);

            return response('Workspace not found', 200);
        }

        // The payment_method.attached webhook should handle creating the record
        // But we can also ensure it exists here as a fallback
        $exists = PaymentMethod::where('gateway', 'stripe')
            ->where('gateway_payment_method_id', $paymentMethodId)
            ->exists();

        if (! $exists) {
            // Fetch payment method details from Stripe
            try {
                $this->gateway->attachPaymentMethod($workspace, $paymentMethodId);

                Log::info('Payment method created from setup_intent', [
                    'workspace_id' => $workspace->id,
                    'payment_method_id' => $paymentMethodId,
                ]);
            } catch (\Exception $e) {
                Log::warning('Failed to attach payment method from setup_intent', [
                    'workspace_id' => $workspace->id,
                    'payment_method_id' => $paymentMethodId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response('OK', 200);
    }

    protected function createOrUpdateSubscriptionFromSession(Order $order, array $session): void
    {
        $stripeSubscriptionId = $session['subscription'];

        // Check if subscription already exists
        $subscription = Subscription::where('gateway_subscription_id', $stripeSubscriptionId)->first();

        if ($subscription) {
            return;
        }

        // Get subscription details from Stripe
        $stripeSubscription = $this->gateway->getInvoice($stripeSubscriptionId);

        // Find workspace package from order items
        $packageItem = $order->items->firstWhere('type', 'package');
        $workspace = $order->getResolvedWorkspace();
        $workspacePackage = ($packageItem?->package && $workspace)
            ? $workspace->workspacePackages()
                ->where('package_id', $packageItem->package_id)
                ->first()
            : null;

        Subscription::create([
            'workspace_id' => $order->workspace_id,
            'workspace_package_id' => $workspacePackage?->id,
            'gateway' => 'stripe',
            'gateway_subscription_id' => $stripeSubscriptionId,
            'gateway_customer_id' => $session['customer'],
            'gateway_price_id' => $stripeSubscription['items']['data'][0]['price']['id'] ?? null,
            'status' => $this->mapStripeStatus($stripeSubscription['status'] ?? 'active'),
            'current_period_start' => Carbon::createFromTimestamp($stripeSubscription['current_period_start'] ?? time()),
            'current_period_end' => Carbon::createFromTimestamp($stripeSubscription['current_period_end'] ?? time() + 2592000),
        ]);
    }

    protected function mapStripeStatus(string $status): string
    {
        return match ($status) {
            'active' => 'active',
            'trialing' => 'trialing',
            'past_due' => 'past_due',
            'paused' => 'paused',
            'canceled', 'cancelled' => 'cancelled',
            'incomplete', 'incomplete_expired' => 'incomplete',
            default => 'active',
        };
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

    /**
     * Handle charge.succeeded event for Stripe Radar fraud scoring.
     *
     * Processes the Radar outcome from successful charges to update payment
     * records with fraud assessment data.
     */
    protected function handleChargeSucceeded(array $event): Response
    {
        $charge = $event['raw']['data']['object'];
        $paymentIntentId = $charge['payment_intent'] ?? null;

        // Extract Stripe Radar outcome if present
        $outcome = $charge['outcome'] ?? null;
        if (! $outcome) {
            return response('OK (no Radar data)', 200);
        }

        // Find the payment record
        $payment = null;
        if ($paymentIntentId) {
            $payment = Payment::where('gateway', 'stripe')
                ->where('gateway_payment_id', $paymentIntentId)
                ->first();
        }

        if (! $payment) {
            // Try finding by charge ID
            $payment = Payment::where('gateway', 'stripe')
                ->where('gateway_payment_id', $charge['id'])
                ->first();
        }

        if ($payment) {
            // Process Stripe Radar outcome and store on payment
            $this->fraudService->processStripeRadarOutcome($payment, $outcome);

            Log::info('Stripe Radar outcome processed', [
                'payment_id' => $payment->id,
                'risk_level' => $outcome['risk_level'] ?? 'unknown',
                'risk_score' => $outcome['risk_score'] ?? null,
            ]);
        }

        return response('OK', 200);
    }

    /**
     * Handle payment_intent.succeeded event for Stripe Radar fraud scoring.
     *
     * This is an alternative entry point for Radar data when charge events
     * are not received.
     */
    protected function handlePaymentIntentSucceeded(array $event): Response
    {
        $paymentIntent = $event['raw']['data']['object'];

        // Check for charges array (contains Radar outcome)
        $charges = $paymentIntent['charges']['data'] ?? [];
        if (empty($charges)) {
            return response('OK (no charges)', 200);
        }

        // Process the first charge's Radar outcome
        $charge = $charges[0];
        $outcome = $charge['outcome'] ?? null;
        if (! $outcome) {
            return response('OK (no Radar data)', 200);
        }

        // Find the payment record
        $payment = Payment::where('gateway', 'stripe')
            ->where('gateway_payment_id', $paymentIntent['id'])
            ->first();

        if ($payment) {
            // Process Stripe Radar outcome
            $this->fraudService->processStripeRadarOutcome($payment, $outcome);

            Log::info('Stripe Radar outcome processed (from payment_intent)', [
                'payment_id' => $payment->id,
                'risk_level' => $outcome['risk_level'] ?? 'unknown',
            ]);
        }

        return response('OK', 200);
    }
}
