<?php

namespace Core\Mod\Commerce\Models;

use Core\Mod\Commerce\Contracts\Orderable;
use Core\Tenant\Models\User;
use Core\Tenant\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Order model representing a checkout transaction.
 *
 * @property int $id
 * @property string|null $orderable_type
 * @property int|null $orderable_id
 * @property int $user_id
 * @property string $order_number
 * @property string $status
 * @property string $type
 * @property string $currency
 * @property string|null $display_currency Customer-facing currency
 * @property float|null $exchange_rate_used Exchange rate at time of order
 * @property float|null $base_currency_total Total in base currency for reporting
 * @property float $subtotal
 * @property float $tax_amount
 * @property float $discount_amount
 * @property float $total
 * @property string|null $payment_method
 * @property string|null $payment_gateway
 * @property string|null $gateway_order_id
 * @property int|null $coupon_id
 * @property array|null $billing_address
 * @property array|null $metadata
 * @property \Carbon\Carbon|null $paid_at
 * @property-read Orderable|null $orderable
 */
class Order extends Model
{
    use HasFactory;
    use LogsActivity;

    protected static function newFactory(): \Core\Mod\Commerce\Database\Factories\OrderFactory
    {
        return \Core\Mod\Commerce\Database\Factories\OrderFactory::new();
    }

    protected $fillable = [
        'orderable_type',
        'orderable_id',
        'user_id',
        'order_number',
        'status',
        'type',
        'billing_cycle',
        'currency',
        'display_currency',
        'exchange_rate_used',
        'base_currency_total',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'total',
        'payment_method',
        'payment_gateway',
        'gateway_order_id',
        'coupon_id',
        'billing_name',
        'billing_email',
        'tax_rate',
        'tax_country',
        'billing_address',
        'metadata',
        'idempotency_key',
        'paid_at',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'exchange_rate_used' => 'decimal:8',
        'base_currency_total' => 'decimal:2',
        'billing_address' => 'array',
        'metadata' => 'array',
        'paid_at' => 'datetime',
    ];

    // Relationships

    /**
     * The orderable entity (User or Workspace).
     */
    public function orderable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'invoice_id', 'id')
            ->whereHas('invoice', fn ($q) => $q->where('order_id', $this->id));
    }

    /**
     * Credit notes that originated from this order.
     */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    /**
     * Credit notes that were applied to this order.
     */
    public function appliedCreditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class, 'applied_to_order_id');
    }

    // Status helpers

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isRefunded(): bool
    {
        return $this->status === 'refunded';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    // Actions

    public function markAsPaid(): void
    {
        $this->update([
            'status' => 'paid',
            'paid_at' => now(),
        ]);
    }

    public function markAsFailed(?string $reason = null): void
    {
        $this->update([
            'status' => 'failed',
            'metadata' => array_merge($this->metadata ?? [], [
                'failure_reason' => $reason,
                'failed_at' => now()->toIso8601String(),
            ]),
        ]);
    }

    public function cancel(): void
    {
        $this->update(['status' => 'cancelled']);
    }

    // Scopes

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    public function scopeForWorkspace($query, int $workspaceId)
    {
        return $query->where('orderable_type', Workspace::class)
            ->where('orderable_id', $workspaceId);
    }

    // Workspace resolution

    /**
     * Get the workspace ID for this order.
     *
     * Handles polymorphic orderables: if the orderable is a Workspace,
     * returns its ID directly. If it's a User, returns their default
     * workspace ID.
     */
    public function getWorkspaceIdAttribute(): ?int
    {
        if ($this->orderable_type === Workspace::class) {
            return $this->orderable_id;
        }

        if ($this->orderable_type === User::class) {
            $user = $this->orderable;

            return $user?->defaultHostWorkspace()?->id;
        }

        return null;
    }

    /**
     * Get the workspace for this order.
     *
     * Returns the workspace directly if orderable is Workspace,
     * or the user's default workspace if orderable is User.
     */
    public function getResolvedWorkspace(): ?Workspace
    {
        if ($this->orderable_type === Workspace::class) {
            return $this->orderable;
        }

        if ($this->orderable_type === User::class) {
            return $this->orderable?->defaultHostWorkspace();
        }

        return null;
    }

    // Order number generation

    public static function generateOrderNumber(): string
    {
        $prefix = 'ORD';
        $date = now()->format('Ymd');
        $random = strtoupper(substr(md5(uniqid()), 0, 6));

        return "{$prefix}-{$date}-{$random}";
    }

    // Currency helpers

    /**
     * Get the display currency (customer-facing).
     */
    public function getDisplayCurrencyAttribute($value): string
    {
        return $value ?? $this->currency ?? config('commerce.currency', 'GBP');
    }

    /**
     * Get formatted total in display currency.
     */
    public function getFormattedTotalAttribute(): string
    {
        $currencyService = app(\Core\Mod\Commerce\Services\CurrencyService::class);

        return $currencyService->format($this->total, $this->display_currency);
    }

    /**
     * Get formatted subtotal in display currency.
     */
    public function getFormattedSubtotalAttribute(): string
    {
        $currencyService = app(\Core\Mod\Commerce\Services\CurrencyService::class);

        return $currencyService->format($this->subtotal, $this->display_currency);
    }

    /**
     * Get formatted tax amount in display currency.
     */
    public function getFormattedTaxAmountAttribute(): string
    {
        $currencyService = app(\Core\Mod\Commerce\Services\CurrencyService::class);

        return $currencyService->format($this->tax_amount, $this->display_currency);
    }

    /**
     * Get formatted discount amount in display currency.
     */
    public function getFormattedDiscountAmountAttribute(): string
    {
        $currencyService = app(\Core\Mod\Commerce\Services\CurrencyService::class);

        return $currencyService->format($this->discount_amount, $this->display_currency);
    }

    /**
     * Convert an amount from display currency to base currency.
     */
    public function toBaseCurrency(float $amount): float
    {
        if ($this->exchange_rate_used && $this->exchange_rate_used > 0) {
            return $amount / $this->exchange_rate_used;
        }

        $baseCurrency = config('commerce.currencies.base', 'GBP');

        if ($this->display_currency === $baseCurrency) {
            return $amount;
        }

        return \Core\Mod\Commerce\Models\ExchangeRate::convert(
            $amount,
            $this->display_currency,
            $baseCurrency
        ) ?? $amount;
    }

    /**
     * Convert an amount from base currency to display currency.
     */
    public function toDisplayCurrency(float $amount): float
    {
        if ($this->exchange_rate_used) {
            return $amount * $this->exchange_rate_used;
        }

        $baseCurrency = config('commerce.currencies.base', 'GBP');

        if ($this->display_currency === $baseCurrency) {
            return $amount;
        }

        return \Core\Mod\Commerce\Models\ExchangeRate::convert(
            $amount,
            $baseCurrency,
            $this->display_currency
        ) ?? $amount;
    }

    /**
     * Check if order uses a different display currency than base.
     */
    public function hasMultiCurrency(): bool
    {
        $baseCurrency = config('commerce.currencies.base', 'GBP');

        return $this->display_currency !== $baseCurrency;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'paid_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Order {$eventName}");
    }
}
