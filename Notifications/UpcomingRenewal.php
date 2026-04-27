<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Notifications;

use Core\Mod\Commerce\Models\Subscription;
use Core\Mod\Commerce\Services\CommerceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UpcomingRenewal extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Subscription $subscription,
        public float $amount,
        public string $currency = 'GBP'
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $commerce = app(CommerceService::class);
        $packageName = $this->subscription->workspacePackage?->package?->name ?? 'Subscription';
        $renewalDate = $this->subscription->current_period_end?->format('j F Y');

        return (new MailMessage)
            ->subject('Upcoming renewal - '.$packageName)
            ->greeting('Your subscription renews soon')
            ->line('Your '.$packageName.' subscription will automatically renew on '.$renewalDate.'.')
            ->line('**Renewal amount:** '.$commerce->formatMoney($this->amount, $this->currency))
            ->line('No action is required. Your payment method on file will be charged automatically.')
            ->action('Manage Subscription', route('hub.billing.subscription'))
            ->line('Want to make changes? You can upgrade, downgrade, or cancel your subscription at any time before the renewal date.')
            ->salutation('Host UK');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'subscription_id' => $this->subscription->id,
            'workspace_id' => $this->subscription->workspace_id,
            'renewal_date' => $this->subscription->current_period_end?->toISOString(),
            'amount' => $this->amount,
            'currency' => $this->currency,
        ];
    }
}
