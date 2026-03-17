<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Exchange rate for currency conversion.
 *
 * @property int $id
 * @property string $base_currency
 * @property string $target_currency
 * @property float $rate
 * @property string $source
 * @property Carbon $fetched_at
 */
class ExchangeRate extends Model
{
    protected $table = 'commerce_exchange_rates';

    protected $fillable = [
        'base_currency',
        'target_currency',
        'rate',
        'source',
        'fetched_at',
    ];

    protected $casts = [
        'rate' => 'decimal:8',
        'fetched_at' => 'datetime',
    ];

    /**
     * Get the exchange rate between two currencies.
     */
    public static function getRate(string $from, string $to, ?string $source = null): ?float
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        // Same currency = 1:1
        if ($from === $to) {
            return 1.0;
        }

        $cacheKey = "exchange_rate:{$from}:{$to}";
        if ($source) {
            $cacheKey .= ":{$source}";
        }

        return Cache::remember($cacheKey, config('commerce.currencies.exchange_rates.cache_ttl', 60) * 60, function () use ($from, $to, $source) {
            $query = static::query()
                ->where('base_currency', $from)
                ->where('target_currency', $to)
                ->orderByDesc('fetched_at');

            if ($source) {
                $query->where('source', $source);
            }

            $rate = $query->first();

            if ($rate) {
                return (float) $rate->rate;
            }

            // Try inverse rate
            $inverseQuery = static::query()
                ->where('base_currency', $to)
                ->where('target_currency', $from)
                ->orderByDesc('fetched_at');

            if ($source) {
                $inverseQuery->where('source', $source);
            }

            $inverseRate = $inverseQuery->first();

            if ($inverseRate && $inverseRate->rate > 0) {
                return 1.0 / (float) $inverseRate->rate;
            }

            // Fall back to fixed rates from config
            $fixedRates = config('commerce.currencies.exchange_rates.fixed', []);
            $directKey = "{$from}_{$to}";
            $inverseKey = "{$to}_{$from}";

            if (isset($fixedRates[$directKey])) {
                return (float) $fixedRates[$directKey];
            }

            if (isset($fixedRates[$inverseKey]) && $fixedRates[$inverseKey] > 0) {
                return 1.0 / (float) $fixedRates[$inverseKey];
            }

            return null;
        });
    }

    /**
     * Convert an amount between currencies.
     */
    public static function convert(float $amount, string $from, string $to, ?string $source = null): ?float
    {
        $rate = static::getRate($from, $to, $source);

        if ($rate === null) {
            return null;
        }

        return $amount * $rate;
    }

    /**
     * Convert an integer amount (cents/pence) between currencies.
     */
    public static function convertCents(int $amount, string $from, string $to, ?string $source = null): ?int
    {
        $rate = static::getRate($from, $to, $source);

        if ($rate === null) {
            return null;
        }

        return (int) round($amount * $rate);
    }

    /**
     * Store or update an exchange rate.
     */
    public static function storeRate(string $from, string $to, float $rate, string $source = 'manual'): self
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        $exchangeRate = static::updateOrCreate(
            [
                'base_currency' => $from,
                'target_currency' => $to,
                'source' => $source,
            ],
            [
                'rate' => $rate,
                'fetched_at' => now(),
            ]
        );

        // Clear cache
        Cache::forget("exchange_rate:{$from}:{$to}");
        Cache::forget("exchange_rate:{$from}:{$to}:{$source}");

        return $exchangeRate;
    }

    /**
     * Get all current rates from a base currency.
     *
     * @return array<string, float>
     */
    public static function getRatesFrom(string $baseCurrency, ?string $source = null): array
    {
        $baseCurrency = strtoupper($baseCurrency);

        $query = static::query()
            ->where('base_currency', $baseCurrency)
            ->orderByDesc('fetched_at');

        if ($source) {
            $query->where('source', $source);
        }

        $rates = $query->get()
            ->unique('target_currency')
            ->pluck('rate', 'target_currency')
            ->toArray();

        return array_map('floatval', $rates);
    }

    /**
     * Scope for rates from a specific source.
     */
    public function scopeFromSource($query, string $source)
    {
        return $query->where('source', $source);
    }

    /**
     * Scope for current rates (most recent).
     */
    public function scopeCurrent($query)
    {
        return $query->orderByDesc('fetched_at');
    }

    /**
     * Scope for rates fetched within a time window.
     */
    public function scopeFresh($query, int $minutes = 60)
    {
        return $query->where('fetched_at', '>=', now()->subMinutes($minutes));
    }

    /**
     * Check if rates need refreshing.
     */
    public static function needsRefresh(?string $source = null): bool
    {
        $cacheTtl = config('commerce.currencies.exchange_rates.cache_ttl', 60);

        $query = static::query()
            ->where('fetched_at', '>=', now()->subMinutes($cacheTtl));

        if ($source) {
            $query->where('source', $source);
        }

        return ! $query->exists();
    }
}
