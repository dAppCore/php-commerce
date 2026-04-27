<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Notifications;

use Core\Mod\Commerce\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionCancelled extends Notification implements ShouldQueue
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
            ->subject('Subscription cancelled')
            ->greeting('Your subscription has ended')
            ->line('Your subscription has been cancelled and your account has been downgraded.')
            ->line('You can continue using free features, but premium features are no longer available.')
            ->line('We\'d love to have you back. You can resubscribe at any time to restore full access.')
            ->action('View Plans', route('pricing'))
            ->line('Thank you for being a Host UK customer.')
            ->salutation('Host UK');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'subscription_id' => $this->subscription->id,
            'workspace_id' => $this->subscription->workspace_id,
            'cancelled_at' => $this->subscription->cancelled_at,
        ];
    }
}
