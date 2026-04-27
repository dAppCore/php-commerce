<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Contracts;

use Core\Mod\Commerce\Models\Order;
use Core\Mod\Commerce\Models\Payment;
use Core\Mod\Commerce\Models\PaymentMethod;
use Core\Mod\Commerce\Models\Refund;
use Illuminate\Http\Request;

interface PaymentGatewayContract
{
    /**
     * Create a payment intent or checkout session.
     *
     * @return array<string, mixed>
     */
    public function createSession(Order $order, PaymentMethod $paymentMethod): array;

    /**
     * Confirm a gateway payment against a local payment record.
     *
     * @param  array<string, mixed>  $gatewayData
     */
    public function confirmPayment(Payment $payment, array $gatewayData): Payment;

    public function refund(Payment $payment, float $amount, string $reason): Refund;

    public function validateWebhookSignature(Request $request): bool;

    /**
     * Parse the request payload into a normalised gateway event.
     *
     * @return array<string, mixed>
     */
    public function parseWebhookEvent(Request $request): array;
}
