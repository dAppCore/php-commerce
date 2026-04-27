<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Events;

use Core\Mod\Commerce\Models\Subscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SubscriptionCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Subscription $subscription
    ) {
        $this->subscriptionId = (int) $subscription->id;
        $this->workspaceId = (int) $subscription->workspace_id;
        $this->productId = $subscription->product_id ? (int) $subscription->product_id : null;
    }

    public int $subscriptionId;

    public int $workspaceId;

    public ?int $productId;
}
