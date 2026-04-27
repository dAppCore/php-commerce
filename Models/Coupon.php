<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Models;

use Carbon\Carbon;
use Core\Mod\Commerce\Contracts\Orderable;
use Core\Mod\Commerce\Database\Factories\CouponFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Coupon model for discount codes.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string $type
 * @property float $value
 * @property float|null $min_amount
 * @property float|null $max_discount
 * @property string $applies_to
 * @property array|null $package_ids
 * @property int|null $max_uses
 * @property int $max_uses_per_workspace
 * @property int $used_count
 * @property string $duration
 * @property int|null $duration_months
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_until
 * @property bool $is_active
 */
class Coupon extends Model
{
    use HasFactory;
    use LogsActivity;

    protected static function newFactory(): CouponFactory
    {
        return CouponFactory::new();
    }

    protected $fillable = [
        'code',
        'name',
        'description',
        'type',
        'value',
        'min_amount',
        'max_discount',
        'applies_to',
        'package_ids',
        'max_uses',
        'max_uses_per_workspace',
        'used_count',
        'duration',
        'duration_months',
        'valid_from',
        'valid_until',
        'is_active',
        'stripe_coupon_id',
        'btcpay_coupon_id',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'min_amount' => 'decimal:2',
        'max_discount' => 'decimal:2',
        'package_ids' => 'array',
        'max_uses' => 'integer',
        'max_uses_per_workspace' => 'integer',
        'used_count' => 'integer',
        'duration_months' => 'integer',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'is_active' => 'boolean',
    ];

    // Relationships

    public function usages(): HasMany
    {
        return $this->hasMany(CouponUsage::class);
    }

    // Type helpers

    public function isPercentage(): bool
    {
        return $this->type === 'percentage';
    }

    public function isFixedAmount(): bool
    {
        return $this->type === 'fixed_amount';
    }

    // Duration helpers

    public function isOnce(): bool
    {
        return $this->duration === 'once';
    }

    public function isRepeating(): bool
    {
        return $this->duration === 'repeating';
    }

    public function isForever(): bool
    {
        return $this->duration === 'forever';
    }

    // Validation

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

        if ($this->max_uses && $this->used_count >= $this->max_uses) {
            return false;
        }

        return true;
    }

    public function canBeUsedByWorkspace(int $workspaceId): bool
    {
        if (! $this->isValid()) {
            return false;
        }

        $workspaceUsageCount = $this->usages()
            ->where('workspace_id', $workspaceId)
            ->count();

        return $workspaceUsageCount < $this->max_uses_per_workspace;
    }

    /**
     * Check if an Orderable entity can use this coupon.
     *
     * Uses the order's orderable relationship to check usage limits.
     */
    public function canBeUsedByOrderable(Orderable&Model $orderable): bool
    {
        if (! $this->isValid()) {
            return false;
        }

        // Check usage via orders linked to this orderable
        $usageCount = $this->usages()
            ->whereHas('order', function ($query) use ($orderable) {
                $query->where('orderable_type', get_class($orderable))
                    ->where('orderable_id', $orderable->id);
            })
            ->count();

        return $usageCount < $this->max_uses_per_workspace;
    }

    /**
     * Check if coupon has reached its maximum usage limit.
     */
    public function hasReachedMaxUses(): bool
    {
        if ($this->max_uses === null) {
            return false;
        }

        return $this->used_count >= $this->max_uses;
    }

    /**
     * Check if coupon is restricted to a specific package.
     *
     * Returns true if the package is in the allowed list.
     * Returns false if no restrictions (applies to all) or package not in list.
     */
    public function isRestrictedToPackage(string $packageCode): bool
    {
        if (empty($this->package_ids)) {
            return false;
        }

        return in_array($packageCode, $this->package_ids);
    }

    public function appliesToPackage(int $packageId): bool
    {
        if ($this->applies_to === 'all') {
            return true;
        }

        if ($this->applies_to !== 'packages') {
            return false;
        }

        return in_array($packageId, $this->package_ids ?? []);
    }

    // Calculation

    public function calculateDiscount(float $amount): float
    {
        if ($this->min_amount && $amount < $this->min_amount) {
            return 0;
        }

        if ($this->isPercentage()) {
            $discount = $amount * ($this->value / 100);
        } else {
            $discount = $this->value;
        }

        // Cap at max_discount if set
        if ($this->max_discount && $discount > $this->max_discount) {
            $discount = $this->max_discount;
        }

        // Cap at order amount
        return min($discount, $amount);
    }

    // Actions

    public function incrementUsage(): void
    {
        $this->increment('used_count');
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
                    ->orWhereRaw('used_count < max_uses');
            });
    }

    public function scopeByCode($query, string $code)
    {
        return $query->where('code', strtoupper($code));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'name', 'is_active', 'value', 'type'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Coupon {$eventName}");
    }
}
