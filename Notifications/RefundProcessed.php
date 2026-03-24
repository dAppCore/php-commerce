<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Notifications;

use Core\Mod\Commerce\Models\Refund;
use Core\Mod\Commerce\Services\CommerceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RefundProcessed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Refund $refund
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $commerce = app(CommerceService::class);
        $amount = $commerce->formatMoney($this->refund->amount, $this->refund->currency);

        return (new MailMessage)
            ->subject('Refund processed - '.$amount)
            ->greeting('Your refund has been processed')
            ->line('We have processed a refund of '.$amount.' to your original payment method.')
            ->line('**Refund details:**')
            ->line('Amount: '.$amount)
            ->line('Reason: '.$this->refund->getReasonLabel())
            ->line('Depending on your payment method and bank, the refund may take 5-10 business days to appear in your account.')
            ->action('View Billing', route('hub.billing.index'))
            ->line('If you have any questions about this refund, please contact our support team.')
            ->salutation('Host UK');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'refund_id' => $this->refund->id,
            'payment_id' => $this->refund->payment_id,
            'amount' => $this->refund->amount,
            'currency' => $this->refund->currency,
            'reason' => $this->refund->reason,
        ];
    }
}
