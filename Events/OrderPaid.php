<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Events;

use Core\Mod\Commerce\Models\Order;
use Core\Mod\Commerce\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event fired when an order is successfully paid.
 */
class OrderPaid
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Order $order,
        public Payment $payment
    ) {
        $this->orderId = (int) $order->id;
        $this->paymentId = (int) $payment->id;
        $this->amount = (float) $payment->amount;
    }

    public int $orderId;

    public int $paymentId;

    public float $amount;
}
