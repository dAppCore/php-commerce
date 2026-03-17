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
 * ReferralCode model for tracking referral/affiliate codes.
 *
 * Codes can be user-specific (from their namespace), campaign codes,
 * or custom promotional codes with special commission rates.
 *
 * @property int $id
 * @property string $code
 * @property int|null $user_id
 * @property string $type
 * @property float|null $commission_rate
 * @property int $cookie_days
 * @property int|null $max_uses
 * @property int $uses_count
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_until
 * @property bool $is_active
 * @property string|null $campaign_name
 * @property array|null $metadata
 */
class ReferralCode extends Model
{
    use LogsActivity;

    protected $table = 'commerce_referral_codes';

    // Code types
    public const TYPE_USER = 'user'; // Auto-generated from user namespace

    public const TYPE_CAMPAIGN = 'campaign'; // Marketing campaign codes

    public const TYPE_CUSTOM = 'custom'; // Custom promotional codes

    // Default attribution cookie duration (days)
    public const DEFAULT_COOKIE_DAYS = 90;

    protected $fillable = [
        'code',
        'user_id',
        'type',
        'commission_rate',
        'cookie_days',
        'max_uses',
        'uses_count',
        'valid_from',
        'valid_until',
        'is_active',
        'campaign_name',
        'metadata',
    ];

    protected $casts = [
        'commission_rate' => 'decimal:2',
        'cookie_days' => 'integer',
        'max_uses' => 'integer',
        'uses_count' => 'integer',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Validation

    /**
     * Check if code is currently valid for use.
     */
    public function isValid(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->valid_from && $this->valid_from->isFuture()) {
            return false;
        }

        if ($this->valid_until && $this->valid_until->isPast()) {
            return false;
        }

        if ($this->max_uses && $this->uses_count >= $this->max_uses) {
            return false;
        }

        return true;
    }

    /**
     * Check if code has reached max uses.
     */
    public function hasReachedMaxUses(): bool
    {
        if ($this->max_uses === null) {
            return false;
        }

        return $this->uses_count >= $this->max_uses;
    }

    // Getters

    /**
     * Get effective commission rate (own or default).
     */
    public function getEffectiveCommissionRate(): float
    {
        return $this->commission_rate ?? ReferralCommission::DEFAULT_COMMISSION_RATE;
    }

    /**
     * Get effective cookie duration in days.
     */
    public function getEffectiveCookieDays(): int
    {
        return $this->cookie_days ?? self::DEFAULT_COOKIE_DAYS;
    }

    // Actions

    /**
     * Increment usage count.
     */
    public function incrementUsage(): void
    {
        $this->increment('uses_count');
    }

    /**
     * Activate code.
     */
    public function activate(): void
    {
        $this->update(['is_active' => true]);
    }

    /**
     * Deactivate code.
     */
    public function deactivate(): void
    {
        $this->update(['is_active' => false]);
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeValid($query)
    {
        return $query->active()
            ->where(function ($q) {
                $q->whereNull('valid_from')
                    ->orWhere('valid_from', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', now());
            })
            ->where(function ($q) {
                $q->whereNull('max_uses')
                    ->orWhereRaw('uses_count < max_uses');
            });
    }

    public function scopeByCode($query, string $code)
    {
        return $query->where('code', $code);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeCampaign($query)
    {
        return $query->where('type', self::TYPE_CAMPAIGN);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'is_active', 'commission_rate', 'max_uses'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Referral code {$eventName}");
    }
}
