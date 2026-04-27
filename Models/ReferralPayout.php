<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Models;

use Carbon\Carbon;
use Core\Tenant\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * ReferralPayout model for tracking commission withdrawals.
 *
 * Supports BTC payouts and account credit application.
 *
 * @property int $id
 * @property int $user_id
 * @property string $payout_number
 * @property string $method
 * @property string|null $btc_address
 * @property string|null $btc_txid
 * @property float $amount
 * @property string $currency
 * @property float|null $btc_amount
 * @property float|null $btc_rate
 * @property string $status
 * @property Carbon|null $requested_at
 * @property Carbon|null $processed_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $failed_at
 * @property string|null $notes
 * @property string|null $failure_reason
 * @property int|null $processed_by
 */
class ReferralPayout extends Model
{
    use LogsActivity;

    protected $table = 'commerce_referral_payouts';

    // Status constants
    public const STATUS_REQUESTED = 'requested';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    // Payout methods
    public const METHOD_BTC = 'btc';

    public const METHOD_ACCOUNT_CREDIT = 'account_credit';

    // Minimum payout amounts (in GBP)
    public const MINIMUM_BTC_PAYOUT = 10.00;

    public const MINIMUM_CREDIT_PAYOUT = 0.01; // No minimum for account credit

    protected $fillable = [
        'user_id',
        'payout_number',
        'method',
        'btc_address',
        'btc_txid',
        'amount',
        'currency',
        'btc_amount',
        'btc_rate',
        'status',
        'requested_at',
        'processed_at',
        'completed_at',
        'failed_at',
        'notes',
        'failure_reason',
        'processed_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'btc_amount' => 'decimal:8',
        'btc_rate' => 'decimal:8',
        'requested_at' => 'datetime',
        'processed_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(ReferralCommission::class, 'payout_id');
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    // Status helpers

    public function isRequested(): bool
    {
        return $this->status === self::STATUS_REQUESTED;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isPending(): bool
    {
        return in_array($this->status, [self::STATUS_REQUESTED, self::STATUS_PROCESSING]);
    }

    // Method helpers

    public function isBtcPayout(): bool
    {
        return $this->method === self::METHOD_BTC;
    }

    public function isAccountCredit(): bool
    {
        return $this->method === self::METHOD_ACCOUNT_CREDIT;
    }

    // Actions

    /**
     * Mark as processing.
     */
    public function markProcessing(User $admin): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSING,
            'processed_at' => now(),
            'processed_by' => $admin->id,
        ]);
    }

    /**
     * Mark as completed.
     */
    public function markCompleted(?string $btcTxid = null, ?float $btcAmount = null, ?float $btcRate = null): void
    {
        $updates = [
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
        ];

        if ($btcTxid) {
            $updates['btc_txid'] = $btcTxid;
        }
        if ($btcAmount) {
            $updates['btc_amount'] = $btcAmount;
            $updates['btc_rate'] = $btcRate;
        }

        $this->update($updates);

        // Mark all commissions as paid
        $this->commissions()->update([
            'status' => ReferralCommission::STATUS_PAID,
            'paid_at' => now(),
        ]);
    }

    /**
     * Mark as failed.
     */
    public function markFailed(string $reason): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'failed_at' => now(),
            'failure_reason' => $reason,
        ]);

        // Return commissions to matured status
        $this->commissions()->update([
            'status' => ReferralCommission::STATUS_MATURED,
            'payout_id' => null,
        ]);
    }

    /**
     * Cancel payout request.
     */
    public function cancel(?string $reason = null): void
    {
        $this->update([
            'status' => self::STATUS_CANCELLED,
            'notes' => $reason ?? $this->notes,
        ]);

        // Return commissions to matured status
        $this->commissions()->update([
            'status' => ReferralCommission::STATUS_MATURED,
            'payout_id' => null,
        ]);
    }

    // Static helpers

    /**
     * Generate a unique payout number.
     */
    public static function generatePayoutNumber(): string
    {
        $prefix = 'PAY';
        $date = now()->format('Ymd');
        $random = strtoupper(substr(md5(uniqid()), 0, 6));

        return "{$prefix}-{$date}-{$random}";
    }

    /**
     * Get minimum payout amount for a method.
     */
    public static function getMinimumPayout(string $method): float
    {
        return match ($method) {
            self::METHOD_BTC => self::MINIMUM_BTC_PAYOUT,
            self::METHOD_ACCOUNT_CREDIT => self::MINIMUM_CREDIT_PAYOUT,
            default => self::MINIMUM_BTC_PAYOUT,
        };
    }

    // Scopes

    public function scopeRequested($query)
    {
        return $query->where('status', self::STATUS_REQUESTED);
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', self::STATUS_PROCESSING);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopePending($query)
    {
        return $query->whereIn('status', [self::STATUS_REQUESTED, self::STATUS_PROCESSING]);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByMethod($query, string $method)
    {
        return $query->where('method', $method);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'processed_at', 'completed_at', 'failed_at', 'btc_txid'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Payout {$eventName}");
    }
}
