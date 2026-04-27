<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Events;

use Core\Mod\Commerce\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SubscriptionUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Subscription $subscription,
        public ?string $previousStatus = null,
        public ?int $oldProductId = null,
        public ?int $newProductId = null,
    ) {
        $this->subscriptionId = (int) $subscription->id;
        $this->oldProductId ??= $subscription->getOriginal('product_id') ? (int) $subscription->getOriginal('product_id') : null;
        $this->newProductId ??= $subscription->product_id ? (int) $subscription->product_id : null;
    }

    public int $subscriptionId;
}
