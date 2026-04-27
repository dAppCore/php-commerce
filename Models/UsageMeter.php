<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * UsageMeter model - defines a metered billing product.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string|null $stripe_meter_id
 * @property string|null $stripe_price_id
 * @property string $aggregation_type
 * @property float $unit_price
 * @property string $currency
 * @property string $unit_label
 * @property array|null $pricing_tiers
 * @property string|null $feature_code
 * @property bool $is_active
 */
class UsageMeter extends Model
{
    protected $table = 'commerce_usage_meters';

    protected $fillable = [
        'code',
        'name',
        'description',
        'stripe_meter_id',
        'stripe_price_id',
        'aggregation_type',
        'unit_price',
        'currency',
        'unit_label',
        'pricing_tiers',
        'feature_code',
        'is_active',
    ];

    protected $casts = [
        'unit_price' => 'decimal:4',
        'pricing_tiers' => 'array',
        'is_active' => 'boolean',
    ];

    // Aggregation types
    public const AGGREGATION_SUM = 'sum';

    public const AGGREGATION_MAX = 'max';

    public const AGGREGATION_LAST = 'last_value';

    // Relationships

    public function subscriptionUsage(): HasMany
    {
        return $this->hasMany(SubscriptionUsage::class, 'meter_id');
    }

    public function usageEvents(): HasMany
    {
        return $this->hasMany(UsageEvent::class, 'meter_id');
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCode($query, string $code)
    {
        return $query->where('code', $code);
    }

    public function scopeForFeature($query, string $featureCode)
    {
        return $query->where('feature_code', $featureCode);
    }

    // Helpers

    /**
     * Check if this meter has tiered pricing.
     */
    public function hasTieredPricing(): bool
    {
        return ! empty($this->pricing_tiers);
    }

    /**
     * Calculate charge for a given quantity.
     */
    public function calculateCharge(int $quantity): float
    {
        if ($this->hasTieredPricing()) {
            return $this->calculateTieredCharge($quantity);
        }

        return round($quantity * $this->unit_price, 2);
    }

    /**
     * Calculate charge using tiered pricing.
     *
     * Tiers format:
     * [
     *     ['up_to' => 100, 'unit_price' => 0.10],
     *     ['up_to' => 1000, 'unit_price' => 0.05],
     *     ['up_to' => null, 'unit_price' => 0.01], // unlimited
     * ]
     */
    protected function calculateTieredCharge(int $quantity): float
    {
        $tiers = $this->pricing_tiers ?? [];
        $remaining = $quantity;
        $total = 0.0;
        $previousLimit = 0;

        foreach ($tiers as $tier) {
            $upTo = $tier['up_to'] ?? PHP_INT_MAX;
            $tierQuantity = min($remaining, $upTo - $previousLimit);

            if ($tierQuantity <= 0) {
                break;
            }

            $total += $tierQuantity * ($tier['unit_price'] ?? 0);
            $remaining -= $tierQuantity;
            $previousLimit = $upTo;

            if ($remaining <= 0) {
                break;
            }
        }

        return round($total, 2);
    }

    /**
     * Get pricing description for display.
     */
    public function getPricingDescription(): string
    {
        if ($this->hasTieredPricing()) {
            return 'Tiered pricing';
        }

        $symbol = match ($this->currency) {
            'GBP' => '£',
            'USD' => '$',
            'EUR' => '€',
            default => $this->currency.' ',
        };

        return "{$symbol}{$this->unit_price} per {$this->unit_label}";
    }

    /**
     * Find meter by code.
     */
    public static function findByCode(string $code): ?self
    {
        return static::where('code', $code)->first();
    }
}
