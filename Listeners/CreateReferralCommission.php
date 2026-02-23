<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Listeners;

use Core\Mod\Commerce\Events\OrderPaid;
use Core\Mod\Commerce\Services\ReferralService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Creates referral commission when an order is paid.
 *
 * Checks if the order's user was referred and creates a commission
 * record that will mature after the refund/chargeback period.
 */
class CreateReferralCommission implements ShouldQueue
{
    public function __construct(
        protected ReferralService $referralService
    ) {}

    /**
     * Handle the order paid event.
     */
    public function handle(OrderPaid $event): void
    {
        $order = $event->order;

        // Skip if no user on order
        if (! $order->user) {
            return;
        }

        // Skip free orders
        if ($order->total <= 0) {
            return;
        }

        try {
            $commission = $this->referralService->createCommissionForOrder($order);

            if ($commission) {
                Log::info('Referral commission created for order', [
                    'order_id' => $order->id,
                    'commission_id' => $commission->id,
                    'amount' => $commission->commission_amount,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to create referral commission', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
