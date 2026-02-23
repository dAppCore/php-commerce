<?php

namespace Core\Mod\Commerce\Notifications;

use Core\Mod\Commerce\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SubscriptionPaused extends Notification implements ShouldQueue
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
        $suspendDays = config('commerce.dunning.suspend_after_days', 14);

        return (new MailMessage)
            ->subject('Subscription paused - payment required')
            ->greeting('Your subscription has been paused')
            ->line('We were unable to process your payment after multiple attempts. Your subscription has been paused to prevent further charge attempts.')
            ->line('Your account is still accessible, but some features may be limited.')
            ->line('To resume your subscription, please update your payment method and pay the outstanding balance.')
            ->line("If payment is not received within {$suspendDays} days, your account will be suspended.")
            ->action('Update Payment Method', route('hub.billing.payment-methods'))
            ->line('Need help? Our support team is here to assist you.')
            ->salutation('Host UK');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'subscription_id' => $this->subscription->id,
            'workspace_id' => $this->subscription->workspace_id,
            'paused_at' => now()->toISOString(),
        ];
    }
}
