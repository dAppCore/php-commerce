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
 * Referral model for tracking referral relationships.
 *
 * Tracks the relationship between referrer and referee, including
 * attribution data and conversion status.
 *
 * @property int $id
 * @property int $referrer_id
 * @property int|null $referee_id
 * @property string $code
 * @property string $status
 * @property string|null $source_url
 * @property string|null $landing_page
 * @property string|null $utm_source
 * @property string|null $utm_medium
 * @property string|null $utm_campaign
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $tracking_id
 * @property Carbon|null $clicked_at
 * @property Carbon|null $signed_up_at
 * @property Carbon|null $first_purchase_at
 * @property Carbon|null $qualified_at
 * @property Carbon|null $disqualified_at
 * @property string|null $disqualification_reason
 * @property Carbon|null $matured_at
 */
class Referral extends Model
{
    use LogsActivity;

    protected $table = 'commerce_referrals';

    // Status constants
    public const STATUS_PENDING = 'pending'; // Link clicked, waiting for signup

    public const STATUS_CONVERTED = 'converted'; // User signed up

    public const STATUS_QUALIFIED = 'qualified'; // User made a purchase

    public const STATUS_DISQUALIFIED = 'disqualified'; // Referral invalidated

    protected $fillable = [
        'referrer_id',
        'referee_id',
        'code',
        'status',
        'source_url',
        'landing_page',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'ip_address',
        'user_agent',
        'tracking_id',
        'clicked_at',
        'signed_up_at',
        'first_purchase_at',
        'qualified_at',
        'disqualified_at',
        'disqualification_reason',
        'matured_at',
    ];

    protected $casts = [
        'clicked_at' => 'datetime',
        'signed_up_at' => 'datetime',
        'first_purchase_at' => 'datetime',
        'qualified_at' => 'datetime',
        'disqualified_at' => 'datetime',
        'matured_at' => 'datetime',
    ];

    // Relationships

    /**
     * The user who referred (affiliate).
     */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    /**
     * The user who was referred.
     */
    public function referee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referee_id');
    }

    /**
     * Commissions earned from this referral.
     */
    public function commissions(): HasMany
    {
        return $this->hasMany(ReferralCommission::class);
    }

    // Status helpers

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isConverted(): bool
    {
        return $this->status === self::STATUS_CONVERTED;
    }

    public function isQualified(): bool
    {
        return $this->status === self::STATUS_QUALIFIED;
    }

    public function isDisqualified(): bool
    {
        return $this->status === self::STATUS_DISQUALIFIED;
    }

    public function isActive(): bool
    {
        return ! $this->isDisqualified();
    }

    public function hasMatured(): bool
    {
        return $this->matured_at !== null;
    }

    // Actions

    /**
     * Mark as converted when referee signs up.
     */
    public function markConverted(User $referee): void
    {
        $this->update([
            'referee_id' => $referee->id,
            'status' => self::STATUS_CONVERTED,
            'signed_up_at' => now(),
        ]);
    }

    /**
     * Mark as qualified when referee makes first purchase.
     */
    public function markQualified(): void
    {
        $this->update([
            'status' => self::STATUS_QUALIFIED,
            'first_purchase_at' => $this->first_purchase_at ?? now(),
            'qualified_at' => now(),
        ]);
    }

    /**
     * Disqualify this referral.
     */
    public function disqualify(string $reason): void
    {
        $this->update([
            'status' => self::STATUS_DISQUALIFIED,
            'disqualified_at' => now(),
            'disqualification_reason' => $reason,
        ]);
    }

    /**
     * Mark as matured (commissions can be withdrawn).
     */
    public function markMatured(): void
    {
        $this->update(['matured_at' => now()]);
    }

    // Calculations

    /**
     * Get total commission amount from this referral.
     */
    public function getTotalCommissionAttribute(): float
    {
        return (float) $this->commissions()->sum('commission_amount');
    }

    /**
     * Get matured (withdrawable) commission amount.
     */
    public function getMaturedCommissionAttribute(): float
    {
        return (float) $this->commissions()
            ->where('status', ReferralCommission::STATUS_MATURED)
            ->sum('commission_amount');
    }

    /**
     * Get pending commission amount.
     */
    public function getPendingCommissionAttribute(): float
    {
        return (float) $this->commissions()
            ->where('status', ReferralCommission::STATUS_PENDING)
            ->sum('commission_amount');
    }

    // Scopes

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeConverted($query)
    {
        return $query->where('status', self::STATUS_CONVERTED);
    }

    public function scopeQualified($query)
    {
        return $query->where('status', self::STATUS_QUALIFIED);
    }

    public function scopeActive($query)
    {
        return $query->where('status', '!=', self::STATUS_DISQUALIFIED);
    }

    public function scopeForReferrer($query, int $userId)
    {
        return $query->where('referrer_id', $userId);
    }

    public function scopeForReferee($query, int $userId)
    {
        return $query->where('referee_id', $userId);
    }

    public function scopeWithCode($query, string $code)
    {
        return $query->where('code', $code);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'qualified_at', 'disqualified_at', 'matured_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Referral {$eventName}");
    }
}
