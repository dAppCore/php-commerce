<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Services;

use Core\Mod\Commerce\Events\SubscriptionCancelled;
use Core\Mod\Commerce\Models\Subscription;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SubscriptionStateMachine
{
    /**
     * @var array<string, array<int, string>>
     */
    private const TRANSITIONS = [
        'active' => ['suspended', 'cancelled'],
        'suspended' => ['active', 'cancelled'],
        'cancelled' => ['expired'],
        'expired' => [],
    ];

    /**
     * @return array<int, string>
     */
    public function allowedTransitions(string $status): array
    {
        return self::TRANSITIONS[$status] ?? [];
    }

    public function canTransition(Subscription $subscription, string $to): bool
    {
        return in_array($to, $this->allowedTransitions((string) $subscription->status), true);
    }

    public function transition(Subscription $subscription, string $to, string $reason = ''): Subscription
    {
        if (! $this->canTransition($subscription, $to)) {
            throw new InvalidArgumentException(
                "Cannot transition subscription {$subscription->id} from {$subscription->status} to {$to}."
            );
        }

        return DB::transaction(function () use ($subscription, $to, $reason): Subscription {
            $updates = [
                'status' => $to,
                'metadata' => array_merge($subscription->metadata ?? [], [
                    'state_machine' => [
                        'from' => $subscription->status,
                        'to' => $to,
                        'reason' => $reason,
                        'changed_at' => now()->toIso8601String(),
                    ],
                ]),
            ];

            if ($to === 'suspended') {
                $updates['paused_at'] = $subscription->paused_at ?? now();
            }

            if ($to === 'cancelled') {
                $updates['cancelled_at'] = now();
                $updates['cancellation_reason'] = $reason;
            }

            if ($to === 'expired') {
                $updates['ended_at'] = now();
            }

            $subscription->update($updates);

            if ($to === 'cancelled') {
                event(new SubscriptionCancelled($subscription->fresh(), true));
            }

            return $subscription->fresh();
        });
    }

    public function suspend(Subscription $subscription, string $reason = 'failed_payment'): Subscription
    {
        return $this->transition($subscription, 'suspended', $reason);
    }

    public function reactivate(Subscription $subscription, string $reason = 'payment_recovered'): Subscription
    {
        return $this->transition($subscription, 'active', $reason);
    }

    public function cancel(Subscription $subscription, string $reason = ''): Subscription
    {
        return $this->transition($subscription, 'cancelled', $reason);
    }

    public function expire(Subscription $subscription, string $reason = 'period_ended'): Subscription
    {
        return $this->transition($subscription, 'expired', $reason);
    }
}
