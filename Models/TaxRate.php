<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * TaxRate model for VAT/GST/sales tax rates.
 *
 * Supports UK VAT, EU OSS, US state taxes, and Australian GST.
 *
 * @property int $id
 * @property string $country_code
 * @property string|null $state_code
 * @property string $name
 * @property string $type
 * @property float $rate
 * @property bool $is_digital_services
 * @property Carbon $effective_from
 * @property Carbon|null $effective_until
 * @property bool $is_active
 * @property string|null $stripe_tax_rate_id
 */
class TaxRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'country_code',
        'state_code',
        'name',
        'type',
        'rate',
        'is_digital_services',
        'effective_from',
        'effective_until',
        'is_active',
        'stripe_tax_rate_id',
    ];

    protected $casts = [
        'rate' => 'decimal:2',
        'is_digital_services' => 'boolean',
        'effective_from' => 'date',
        'effective_until' => 'date',
        'is_active' => 'boolean',
    ];

    // Type helpers

    public function isVat(): bool
    {
        return $this->type === 'vat';
    }

    public function isSalesTax(): bool
    {
        return $this->type === 'sales_tax';
    }

    public function isGst(): bool
    {
        return $this->type === 'gst';
    }

    // Validation

    public function isEffective(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $now = now()->toDateString();

        if ($this->effective_from > $now) {
            return false;
        }

        if ($this->effective_until && $this->effective_until < $now) {
            return false;
        }

        return true;
    }

    // Calculation

    public function calculateTax(float $amount): float
    {
        return round($amount * ($this->rate / 100), 2);
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeEffective($query)
    {
        $now = now()->toDateString();

        return $query->active()
            ->where('effective_from', '<=', $now)
            ->where(function ($q) use ($now) {
                $q->whereNull('effective_until')
                    ->orWhere('effective_until', '>=', $now);
            });
    }

    public function scopeForCountry($query, string $countryCode)
    {
        return $query->where('country_code', strtoupper($countryCode));
    }

    public function scopeForState($query, string $countryCode, string $stateCode)
    {
        return $query->where('country_code', strtoupper($countryCode))
            ->where('state_code', strtoupper($stateCode));
    }

    public function scopeDigitalServices($query)
    {
        return $query->where('is_digital_services', true);
    }

    // Static helpers

    public static function findForLocation(string $countryCode, ?string $stateCode = null): ?self
    {
        $query = static::effective()
            ->digitalServices()
            ->forCountry($countryCode);

        // Try state-specific first (for US)
        if ($stateCode) {
            $stateRate = (clone $query)->where('state_code', strtoupper($stateCode))->first();
            if ($stateRate) {
                return $stateRate;
            }
        }

        // Fall back to country-level
        return $query->whereNull('state_code')->first();
    }
}
