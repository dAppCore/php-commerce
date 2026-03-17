<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Notifications;

use Core\Mod\Commerce\Models\Order;
use Core\Mod\Commerce\Services\CommerceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderConfirmation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Order $order
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $commerce = app(CommerceService::class);
        $items = $this->order->items;
        $firstItem = $items->first();

        return (new MailMessage)
            ->subject('Order confirmation - '.$this->order->order_number)
            ->greeting('Thank you for your order')
            ->line('Your order has been confirmed and your account has been activated.')
            ->line('**Order Details**')
            ->line('Order Number: '.$this->order->order_number)
            ->line('Plan: '.($firstItem?->name ?? 'Subscription'))
            ->line('Total: '.$commerce->formatMoney($this->order->total, $this->order->currency))
            ->action('View Dashboard', route('hub.dashboard'))
            ->line('If you have any questions, please contact our support team.')
            ->salutation('Host UK');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'total' => $this->order->total,
            'currency' => $this->order->currency,
        ];
    }
}
