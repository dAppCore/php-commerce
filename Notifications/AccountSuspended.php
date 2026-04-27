<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Notifications;

use Core\Mod\Commerce\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccountSuspended extends Notification implements ShouldQueue
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
        $cancelDays = config('commerce.dunning.cancel_after_days', 30) - config('commerce.dunning.suspend_after_days', 14);

        return (new MailMessage)
            ->subject('Account suspended - immediate action required')
            ->greeting('Your account has been suspended')
            ->line('Due to repeated payment failures, your account access has been temporarily suspended.')
            ->line('Your data is safe. To restore access, please update your payment method and clear your outstanding balance.')
            ->line('If payment is not received within '.$cancelDays.' days, your subscription will be cancelled and your account downgraded.')
            ->action('Restore Account', route('hub.billing.index'))
            ->line('Need help? Contact our support team and we\'ll work with you to resolve this.')
            ->salutation('Host UK');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'subscription_id' => $this->subscription->id,
            'workspace_id' => $this->subscription->workspace_id,
            'suspended_at' => now()->toISOString(),
        ];
    }
}
