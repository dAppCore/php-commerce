<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Services;

use Core\Mod\Commerce\Contracts\PaymentGatewayContract;
use Core\Mod\Commerce\Models\Order;
use Core\Mod\Commerce\Models\Payment;
use Core\Mod\Commerce\Models\PaymentMethod;
use Core\Mod\Commerce\Models\Refund;
use Core\Mod\Commerce\Services\PaymentGateway\StripeGateway as LegacyStripeGateway;
use Illuminate\Http\Request;

class StripeGateway implements PaymentGatewayContract
{
    public function __construct(
        protected ?LegacyStripeGateway $gateway = null,
    ) {
        $this->gateway ??= new LegacyStripeGateway;
    }

    /**
     * @return array<string, mixed>
     */
    public function createSession(Order $order, PaymentMethod $paymentMethod): array
    {
        $successUrl = url('/checkout/success?order='.$order->order_number);
        $cancelUrl = url('/checkout/cancel?order='.$order->order_number);

        return $this->gateway->createCheckoutSession($order, $successUrl, $cancelUrl);
    }

    /**
     * @param  array<string, mixed>  $gatewayData
     */
    public function confirmPayment(Payment $payment, array $gatewayData): Payment
    {
        $payment->update([
            'gateway_payment_id' => $gatewayData['payment_intent'] ?? $gatewayData['id'] ?? $payment->gateway_payment_id,
            'gateway_customer_id' => $gatewayData['customer'] ?? $payment->gateway_customer_id,
            'status' => 'succeeded',
            'paid_at' => now(),
            'gateway_response' => $gatewayData,
        ]);

        return $payment->fresh();
    }

    public function refund(Payment $payment, float $amount, string $reason): Refund
    {
        $refund = Refund::create([
            'payment_id' => $payment->id,
            'amount' => $amount,
            'currency' => $payment->currency,
            'status' => 'pending',
            'reason' => $reason,
        ]);

        if (! $this->gateway->isEnabled()) {
            return $refund;
        }

        $result = $this->gateway->refund($payment, $amount, $reason);

        if (($result['success'] ?? false) === true) {
            $refund->markAsSucceeded($result['refund_id'] ?? null);
        } else {
            $refund->markAsFailed($result);
        }

        return $refund->fresh();
    }

    public function validateWebhookSignature(Request $request): bool
    {
        return $this->gateway->verifyWebhookSignature(
            $request->getContent(),
            (string) $request->header('Stripe-Signature', '')
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function parseWebhookEvent(Request $request): array
    {
        $event = $this->gateway->parseWebhookEvent($request->getContent());

        return [
            'type' => $event['type'] ?? 'unknown',
            'id' => $event['raw']['id'] ?? $event['id'] ?? null,
            'data' => $event['raw']['data']['object'] ?? [],
            'raw' => $event['raw'] ?? $event,
        ];
    }
}
