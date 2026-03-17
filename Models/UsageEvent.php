<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Models;

use Carbon\Carbon;
use Core\Tenant\Models\User;
use Core\Tenant\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * UsageEvent model - individual usage event before aggregation.
 *
 * @property int $id
 * @property int $subscription_id
 * @property int $meter_id
 * @property int $workspace_id
 * @property int $quantity
 * @property Carbon $event_at
 * @property string|null $idempotency_key
 * @property int|null $user_id
 * @property string|null $action
 * @property array|null $metadata
 */
class UsageEvent extends Model
{
    protected $table = 'commerce_usage_events';

    protected $fillable = [
        'subscription_id',
        'meter_id',
        'workspace_id',
        'quantity',
        'event_at',
        'idempotency_key',
        'user_id',
        'action',
        'metadata',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'event_at' => 'datetime',
        'metadata' => 'array',
    ];

    // Relationships

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function meter(): BelongsTo
    {
        return $this->belongsTo(UsageMeter::class, 'meter_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Scopes

    public function scopeForSubscription($query, int $subscriptionId)
    {
        return $query->where('subscription_id', $subscriptionId);
    }

    public function scopeForMeter($query, int $meterId)
    {
        return $query->where('meter_id', $meterId);
    }

    public function scopeForWorkspace($query, int $workspaceId)
    {
        return $query->where('workspace_id', $workspaceId);
    }

    public function scopeSince($query, $date)
    {
        return $query->where('event_at', '>=', $date);
    }

    public function scopeBetween($query, $start, $end)
    {
        return $query->whereBetween('event_at', [$start, $end]);
    }

    // Helpers

    /**
     * Generate a unique idempotency key.
     */
    public static function generateIdempotencyKey(): string
    {
        return Str::uuid()->toString();
    }

    /**
     * Check if an event with this idempotency key already exists.
     */
    public static function existsByIdempotencyKey(string $key): bool
    {
        return static::where('idempotency_key', $key)->exists();
    }

    /**
     * Create event with idempotency protection.
     *
     * Returns null if duplicate idempotency key.
     */
    public static function createWithIdempotency(array $attributes): ?self
    {
        $key = $attributes['idempotency_key'] ?? null;

        if ($key && static::existsByIdempotencyKey($key)) {
            return null;
        }

        return static::create($attributes);
    }

    /**
     * Get total quantity for a subscription + meter in a period.
     */
    public static function getTotalQuantity(
        int $subscriptionId,
        int $meterId,
        $periodStart,
        $periodEnd
    ): int {
        return (int) static::where('subscription_id', $subscriptionId)
            ->where('meter_id', $meterId)
            ->whereBetween('event_at', [$periodStart, $periodEnd])
            ->sum('quantity');
    }
}
