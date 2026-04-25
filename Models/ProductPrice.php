<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Product price in a specific currency.
 *
 * Allows products to have explicit prices in multiple currencies,
 * with fallback to auto-conversion from the base price.
 *
 * @property int $id
 * @property int $product_id
 * @property string $currency
 * @property int $amount Price in smallest unit (cents/pence)
 * @property bool $is_manual Whether this is a manual override
 * @property float|null $exchange_rate_used Rate used for auto-conversion
 */
class ProductPrice extends Model
{
    protected $table = 'commerce_product_prices';

    protected $fillable = [
        'product_id',
        'currency',
        'amount',
        'billing_cycle',
        'is_manual',
        'exchange_rate_used',
    ];

    protected $casts = [
        'amount' => 'integer',
        'is_manual' => 'boolean',
        'exchange_rate_used' => 'decimal:8',
    ];

    /**
     * Get the product.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the formatted price.
     */
    public function getFormattedAttribute(): string
    {
        return $this->format();
    }

    /**
     * Format the price for display.
     */
    public function format(): string
    {
        $config = config("commerce.currencies.supported.{$this->currency}", []);
        $symbol = $config['symbol'] ?? $this->currency;
        $position = $config['symbol_position'] ?? 'before';
        $decimals = $config['decimal_places'] ?? 2;
        $thousandsSep = $config['thousands_separator'] ?? ',';
        $decimalSep = $config['decimal_separator'] ?? '.';

        $value = number_format(
            $this->amount / 100,
            $decimals,
            $decimalSep,
            $thousandsSep
        );

        return $position === 'before'
            ? "{$symbol}{$value}"
            : "{$value}{$symbol}";
    }

    /**
     * Get price as decimal (not cents).
     */
    public function getDecimalAmount(): float
    {
        return $this->amount / 100;
    }

    /**
     * Set price from decimal amount.
     */
    public function setDecimalAmount(float $amount): self
    {
        $this->amount = (int) round($amount * 100);

        return $this;
    }

    /**
     * Get or create a price for a product in a currency.
     *
     * If no explicit price exists and auto-convert is enabled,
     * creates an auto-converted price.
     */
    public static function getOrCreate(Product $product, string $currency): ?self
    {
        $currency = strtoupper($currency);

        // Check for existing price
        $price = static::where('product_id', $product->id)
            ->where('currency', $currency)
            ->first();

        if ($price) {
            return $price;
        }

        // Check if auto-conversion is enabled
        if (! config('commerce.currencies.auto_convert', true)) {
            return null;
        }

        // Get base price and convert
        $baseCurrency = $product->currency ?? config('commerce.currencies.base', 'GBP');

        if ($baseCurrency === $currency) {
            // Create with base price
            return static::create([
                'product_id' => $product->id,
                'currency' => $currency,
                'amount' => $product->price,
                'is_manual' => false,
                'exchange_rate_used' => 1.0,
            ]);
        }

        $rate = ExchangeRate::getRate($baseCurrency, $currency);

        if ($rate === null) {
            return null;
        }

        $convertedAmount = (int) round($product->price * $rate);

        return static::create([
            'product_id' => $product->id,
            'currency' => $currency,
            'amount' => $convertedAmount,
            'is_manual' => false,
            'exchange_rate_used' => $rate,
        ]);
    }

    /**
     * Update all auto-converted prices for a product.
     */
    public static function refreshAutoConverted(Product $product): void
    {
        $baseCurrency = $product->currency ?? config('commerce.currencies.base', 'GBP');
        $supportedCurrencies = array_keys(config('commerce.currencies.supported', []));

        foreach ($supportedCurrencies as $currency) {
            if ($currency === $baseCurrency) {
                continue;
            }

            $existing = static::where('product_id', $product->id)
                ->where('currency', $currency)
                ->first();

            // Skip manual prices
            if ($existing && $existing->is_manual) {
                continue;
            }

            $rate = ExchangeRate::getRate($baseCurrency, $currency);

            if ($rate === null) {
                continue;
            }

            $convertedAmount = (int) round($product->price * $rate);

            static::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'currency' => $currency,
                ],
                [
                    'amount' => $convertedAmount,
                    'is_manual' => false,
                    'exchange_rate_used' => $rate,
                ]
            );
        }
    }

    /**
     * Scope for manual prices only.
     */
    public function scopeManual($query)
    {
        return $query->where('is_manual', true);
    }

    /**
     * Scope for auto-converted prices.
     */
    public function scopeAutoConverted($query)
    {
        return $query->where('is_manual', false);
    }

    /**
     * Scope for a specific currency.
     */
    public function scopeForCurrency($query, string $currency)
    {
        return $query->where('currency', strtoupper($currency));
    }
}
