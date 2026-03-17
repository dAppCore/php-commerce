<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Notifications;

use Core\Mod\Commerce\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentRetry extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Invoice $invoice,
        public int $attemptNumber,
        public int $maxAttempts
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $remainingAttempts = $this->maxAttempts - $this->attemptNumber;

        return (new MailMessage)
            ->subject('Payment retry scheduled - action required')
            ->greeting('Payment attempt '.$this->attemptNumber.' failed')
            ->line('We attempted to charge your payment method for invoice '.$this->invoice->invoice_number.', but the payment was declined.')
            ->line('We will automatically retry the payment in a few days.')
            ->when($remainingAttempts > 0, function ($message) use ($remainingAttempts) {
                return $message->line('You have '.$remainingAttempts.' automatic retry attempts remaining.');
            })
            ->when($remainingAttempts === 0, function ($message) {
                return $message->line('This was our final automatic retry. Please update your payment method to avoid service interruption.');
            })
            ->action('Update Payment Method', route('hub.billing.payment-methods'))
            ->line('If you believe this is an error, please contact our support team.')
            ->salutation('Host UK');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'invoice_id' => $this->invoice->id,
            'invoice_number' => $this->invoice->invoice_number,
            'attempt_number' => $this->attemptNumber,
            'max_attempts' => $this->maxAttempts,
        ];
    }
}
