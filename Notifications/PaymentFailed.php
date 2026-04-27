<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Notifications;

use Core\Mod\Commerce\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentFailed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Subscription $subscription
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Payment failed - action required')
            ->greeting('We couldn\'t process your payment')
            ->line('We attempted to charge your payment method for your subscription renewal, but the payment was declined.')
            ->line('Please update your payment details to avoid service interruption.')
            ->action('Update Payment Method', route('hub.dashboard'))
            ->line('If you believe this is an error, please contact our support team.')
            ->line('We\'ll automatically retry the payment in a few days.')
            ->salutation('Host UK');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'subscription_id' => $this->subscription->id,
            'workspace_id' => $this->subscription->workspace_id,
        ];
    }
}
