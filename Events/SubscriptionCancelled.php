<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Events;

use Core\Mod\Commerce\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SubscriptionCancelled
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Subscription $subscription,
        public bool $immediate = false,
        public string $reason = '',
    ) {
        $this->subscriptionId = (int) $subscription->id;
        $this->cancelledAt = $subscription->cancelled_at ?? now();
    }

    public int $subscriptionId;

    public \DateTimeInterface $cancelledAt;
}
