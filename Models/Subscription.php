<?php

namespace Core\Mod\Commerce\Models;

use Core\Mod\Commerce\Events\SubscriptionCreated;
use Core\Mod\Commerce\Events\SubscriptionUpdated;
use Core\Tenant\Models\Workspace;
use Core\Tenant\Models\WorkspacePackage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Subscription model for recurring billing state.
 *
 * Links gateway subscriptions (Stripe, BTCPay) to workspace packages.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $workspace_package_id
 * @property string $gateway
 * @property string $gateway_subscription_id
 * @property string $gateway_customer_id
 * @property string|null $gateway_price_id
 * @property string $status
 * @property \Carbon\Carbon $current_period_start
 * @property \Carbon\Carbon $current_period_end
 * @property \Carbon\Carbon|null $trial_ends_at
 * @property bool $cancel_at_period_end
 * @property \Carbon\Carbon|null $cancelled_at
 * @property \Carbon\Carbon|null $ended_at
 * @property array|null $metadata
 */
class Subscription extends Model
{
    use HasFactory;
    use LogsActivity;

    protected static function newFactory(): \Core\Mod\Commerce\Database\Factories\SubscriptionFactory
    {
        return \Core\Mod\Commerce\Database\Factories\SubscriptionFactory::new();
    }

    /**
     * The event map for the model.
     */
    protected $dispatchesEvents = [
        'created' => SubscriptionCreated::class,
        'updated' => SubscriptionUpdated::class,
    ];

    protected $fillable = [
        'workspace_id',
        'workspace_package_id',
        'gateway',
        'gateway_subscription_id',
        'gateway_customer_id',
        'gateway_price_id',
        'status',
        'billing_cycle',
        'current_period_start',
        'current_period_end',
        'trial_ends_at',
        'cancel_at_period_end',
        'cancelled_at',
        'cancellation_reason',
        'ended_at',
        'paused_at',
        'pause_count',
        'metadata',
    ];

    protected $casts = [
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'trial_ends_at' => 'datetime',
        'cancel_at_period_end' => 'boolean',
        'cancelled_at' => 'datetime',
        'ended_at' => 'datetime',
        'paused_at' => 'datetime',
        'pause_count' => 'integer',
        'metadata' => 'array',
    ];

    // Relationships

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function workspacePackage(): BelongsTo
    {
        return $this->belongsTo(WorkspacePackage::class);
    }

    public function usageRecords(): HasMany
    {
        return $this->hasMany(SubscriptionUsage::class);
    }

    public function usageEvents(): HasMany
    {
        return $this->hasMany(UsageEvent::class);
    }

    // Status helpers

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isTrialing(): bool
    {
        return $this->status === 'trialing';
    }

    public function isPastDue(): bool
    {
        return $this->status === 'past_due';
    }

    public function isPaused(): bool
    {
        return $this->status === 'paused';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isIncomplete(): bool
    {
        return $this->status === 'incomplete';
    }

    /**
     * Check if the subscription can be paused (hasn't exceeded max pause cycles).
     */
    public function canPause(): bool
    {
        if (! config('commerce.subscriptions.allow_pause', true)) {
            return false;
        }

        $maxPauseCycles = config('commerce.subscriptions.max_pause_cycles', 3);

        return ($this->pause_count ?? 0) < $maxPauseCycles;
    }

    /**
     * Get the number of remaining pause cycles.
     */
    public function remainingPauseCycles(): int
    {
        $maxPauseCycles = config('commerce.subscriptions.max_pause_cycles', 3);

        return max(0, $maxPauseCycles - ($this->pause_count ?? 0));
    }

    public function isValid(): bool
    {
        return in_array($this->status, ['active', 'trialing', 'past_due']);
    }

    public function onTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    public function onGracePeriod(): bool
    {
        return $this->cancel_at_period_end && $this->current_period_end->isFuture();
    }

    public function hasEnded(): bool
    {
        return $this->ended_at !== null;
    }

    // Period helpers

    public function daysUntilRenewal(): int
    {
        return max(0, now()->diffInDays($this->current_period_end, false));
    }

    public function isRenewingSoon(int $days = 7): bool
    {
        return $this->daysUntilRenewal() <= $days;
    }

    // Actions

    public function cancel(bool $immediately = false): void
    {
        if ($immediately) {
            $this->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'ended_at' => now(),
            ]);
        } else {
            $this->update([
                'cancel_at_period_end' => true,
                'cancelled_at' => now(),
            ]);
        }
    }

    public function resume(): void
    {
        $this->update([
            'cancel_at_period_end' => false,
            'cancelled_at' => null,
        ]);
    }

    public function pause(): void
    {
        $this->update(['status' => 'paused']);
    }

    public function markPastDue(): void
    {
        $this->update(['status' => 'past_due']);
    }

    public function renew(\Carbon\Carbon $periodStart, \Carbon\Carbon $periodEnd): void
    {
        $this->update([
            'status' => 'active',
            'current_period_start' => $periodStart,
            'current_period_end' => $periodEnd,
        ]);
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['active', 'trialing']);
    }

    public function scopeValid($query)
    {
        return $query->whereIn('status', ['active', 'trialing', 'past_due']);
    }

    public function scopeForWorkspace($query, int $workspaceId)
    {
        return $query->where('workspace_id', $workspaceId);
    }

    public function scopeForGateway($query, string $gateway)
    {
        return $query->where('gateway', $gateway);
    }

    public function scopeExpiringSoon($query, int $days = 7)
    {
        return $query->where('current_period_end', '<=', now()->addDays($days))
            ->where('current_period_end', '>', now());
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'cancel_at_period_end', 'cancelled_at', 'paused_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Subscription {$eventName}");
    }
}
