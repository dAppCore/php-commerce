<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Models;

use Carbon\Carbon;
use Core\Tenant\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * ReferralCommission model for tracking commission earnings.
 *
 * Each commission is linked to an order and tracks its maturation
 * and payout status.
 *
 * @property int $id
 * @property int $referral_id
 * @property int $referrer_id
 * @property int|null $order_id
 * @property int|null $invoice_id
 * @property float $order_amount
 * @property float $commission_rate
 * @property float $commission_amount
 * @property string $currency
 * @property string $status
 * @property Carbon|null $matures_at
 * @property Carbon|null $matured_at
 * @property int|null $payout_id
 * @property Carbon|null $paid_at
 * @property string|null $notes
 */
class ReferralCommission extends Model
{
    use LogsActivity;

    protected $table = 'commerce_referral_commissions';

    // Status constants
    public const STATUS_PENDING = 'pending'; // Waiting to mature

    public const STATUS_MATURED = 'matured'; // Can be withdrawn

    public const STATUS_PAID = 'paid'; // Included in a payout

    public const STATUS_CANCELLED = 'cancelled'; // Refunded/chargedback

    // Default commission rate (percentage)
    public const DEFAULT_COMMISSION_RATE = 10.00;

    // Maturation periods (days after order)
    public const MATURATION_CRYPTO = 14; // Crypto: 14 days (refund period)

    public const MATURATION_CARD = 90; // Card: 90 days (chargeback period)

    protected $fillable = [
        'referral_id',
        'referrer_id',
        'order_id',
        'invoice_id',
        'order_amount',
        'commission_rate',
        'commission_amount',
        'currency',
        'status',
        'matures_at',
        'matured_at',
        'payout_id',
        'paid_at',
        'notes',
    ];

    protected $casts = [
        'order_amount' => 'decimal:2',
        'commission_rate' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'matures_at' => 'datetime',
        'matured_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    // Relationships

    public function referral(): BelongsTo
    {
        return $this->belongsTo(Referral::class);
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(ReferralPayout::class);
    }

    // Status helpers

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isMatured(): bool
    {
        return $this->status === self::STATUS_MATURED;
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function canMature(): bool
    {
        return $this->isPending() && $this->matures_at && $this->matures_at->isPast();
    }

    // Actions

    /**
     * Mark commission as matured.
     */
    public function markMatured(): void
    {
        $this->update([
            'status' => self::STATUS_MATURED,
            'matured_at' => now(),
        ]);
    }

    /**
     * Mark commission as paid (included in payout).
     */
    public function markPaid(ReferralPayout $payout): void
    {
        $this->update([
            'status' => self::STATUS_PAID,
            'payout_id' => $payout->id,
            'paid_at' => now(),
        ]);
    }

    /**
     * Cancel commission (refund/chargeback).
     */
    public function cancel(?string $reason = null): void
    {
        $this->update([
            'status' => self::STATUS_CANCELLED,
            'notes' => $reason,
        ]);
    }

    // Static factory

    /**
     * Calculate commission for an order.
     */
    public static function calculateForOrder(
        Referral $referral,
        Order $order,
        ?float $commissionRate = null
    ): array {
        $commissionRate = $commissionRate ?? self::DEFAULT_COMMISSION_RATE;

        // Calculate commission on net order amount (after discount, before tax)
        $netAmount = $order->subtotal - $order->discount_amount;
        $commissionAmount = round($netAmount * ($commissionRate / 100), 2);

        // Determine maturation date based on payment method
        $gateway = $order->gateway ?? 'stripe';
        $maturationDays = in_array($gateway, ['btcpay', 'bitcoin', 'crypto'])
            ? self::MATURATION_CRYPTO
            : self::MATURATION_CARD;

        return [
            'referral_id' => $referral->id,
            'referrer_id' => $referral->referrer_id,
            'order_id' => $order->id,
            'invoice_id' => $order->invoice?->id,
            'order_amount' => $netAmount,
            'commission_rate' => $commissionRate,
            'commission_amount' => $commissionAmount,
            'currency' => $order->currency,
            'status' => self::STATUS_PENDING,
            'matures_at' => now()->addDays($maturationDays),
        ];
    }

    // Scopes

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeMatured($query)
    {
        return $query->where('status', self::STATUS_MATURED);
    }

    public function scopePaid($query)
    {
        return $query->where('status', self::STATUS_PAID);
    }

    public function scopeWithdrawable($query)
    {
        return $query->where('status', self::STATUS_MATURED);
    }

    public function scopeReadyToMature($query)
    {
        return $query->pending()->where('matures_at', '<=', now());
    }

    public function scopeForReferrer($query, int $userId)
    {
        return $query->where('referrer_id', $userId);
    }

    public function scopeUnpaid($query)
    {
        return $query->whereNull('payout_id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'matured_at', 'payout_id', 'paid_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Commission {$eventName}");
    }
}
