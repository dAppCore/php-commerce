<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SubscriptionUsage model - aggregated usage per subscription per billing period.
 *
 * @property int $id
 * @property int $subscription_id
 * @property int $meter_id
 * @property int $quantity
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string|null $stripe_usage_record_id
 * @property Carbon|null $synced_at
 * @property bool $billed
 * @property int|null $invoice_item_id
 * @property array|null $metadata
 */
class SubscriptionUsage extends Model
{
    protected $table = 'commerce_subscription_usage';

    protected $fillable = [
        'subscription_id',
        'meter_id',
        'quantity',
        'period_start',
        'period_end',
        'stripe_usage_record_id',
        'synced_at',
        'billed',
        'invoice_item_id',
        'metadata',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'synced_at' => 'datetime',
        'billed' => 'boolean',
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

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class);
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

    public function scopeInPeriod($query, Carbon $start, Carbon $end)
    {
        return $query->where('period_start', '>=', $start)
            ->where('period_end', '<=', $end);
    }

    public function scopeCurrentPeriod($query, Subscription $subscription)
    {
        return $query->where('period_start', '>=', $subscription->current_period_start)
            ->where('period_end', '<=', $subscription->current_period_end);
    }

    public function scopeUnbilled($query)
    {
        return $query->where('billed', false);
    }

    public function scopeUnsynced($query)
    {
        return $query->whereNull('synced_at');
    }

    // Helpers

    /**
     * Check if this usage record is in the current billing period.
     */
    public function isCurrentPeriod(): bool
    {
        $now = now();

        return $now->between($this->period_start, $this->period_end);
    }

    /**
     * Calculate the charge for this usage.
     */
    public function calculateCharge(): float
    {
        return $this->meter->calculateCharge($this->quantity);
    }

    /**
     * Add quantity to this usage record.
     */
    public function addQuantity(int $quantity): self
    {
        $this->increment('quantity', $quantity);

        return $this->fresh();
    }

    /**
     * Mark as synced with Stripe.
     */
    public function markSynced(?string $stripeUsageRecordId = null): void
    {
        $this->update([
            'synced_at' => now(),
            'stripe_usage_record_id' => $stripeUsageRecordId,
        ]);
    }

    /**
     * Mark as billed.
     */
    public function markBilled(?int $invoiceItemId = null): void
    {
        $this->update([
            'billed' => true,
            'invoice_item_id' => $invoiceItemId,
        ]);
    }

    /**
     * Get or create usage record for current period.
     */
    public static function getOrCreateForCurrentPeriod(
        Subscription $subscription,
        UsageMeter $meter
    ): self {
        $record = static::where('subscription_id', $subscription->id)
            ->where('meter_id', $meter->id)
            ->where('period_start', $subscription->current_period_start)
            ->first();

        if (! $record) {
            $record = static::create([
                'subscription_id' => $subscription->id,
                'meter_id' => $meter->id,
                'quantity' => 0,
                'period_start' => $subscription->current_period_start,
                'period_end' => $subscription->current_period_end,
            ]);
        }

        return $record;
    }
}
