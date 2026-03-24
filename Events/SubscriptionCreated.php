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
    ) {}
}
