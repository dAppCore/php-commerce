<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Models;

use Core\Mod\Commerce\Concerns\HasContentOverrides;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Commerce Product - Master catalog entry.
 *
 * Products are owned exclusively by M1 (Master) entities.
 * M2/M3 entities access products through ProductAssignment.
 *
 * @property int $id
 * @property string $sku
 * @property int $owner_entity_id
 * @property string $name
 * @property string|null $description
 * @property string|null $short_description
 * @property string|null $category
 * @property string|null $subcategory
 * @property array|null $tags
 * @property int $price
 * @property int|null $cost_price
 * @property int|null $rrp
 * @property string $currency
 * @property array|null $price_tiers
 * @property string $tax_class
 * @property bool $tax_inclusive
 * @property float|null $weight
 * @property float|null $length
 * @property float|null $width
 * @property float|null $height
 * @property bool $track_stock
 * @property int $stock_quantity
 * @property int $low_stock_threshold
 * @property string $stock_status
 * @property bool $allow_backorder
 * @property string $type
 * @property int|null $parent_id
 * @property array|null $variant_attributes
 * @property string|null $image_url
 * @property array|null $gallery_urls
 * @property string|null $slug
 * @property bool $is_active
 * @property bool $is_featured
 * @property bool $is_visible
 * @property array|null $metadata
 */
class Product extends Model
{
    use HasContentOverrides;
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    // Product types
    public const TYPE_SIMPLE = 'simple';

    public const TYPE_VARIABLE = 'variable';

    public const TYPE_BUNDLE = 'bundle';

    public const TYPE_VIRTUAL = 'virtual';

    public const TYPE_SUBSCRIPTION = 'subscription';

    // Stock statuses
    public const STOCK_IN_STOCK = 'in_stock';

    public const STOCK_LOW = 'low_stock';

    public const STOCK_OUT = 'out_of_stock';

    public const STOCK_BACKORDER = 'backorder';

    public const STOCK_DISCONTINUED = 'discontinued';

    // Tax classes
    public const TAX_STANDARD = 'standard';

    public const TAX_REDUCED = 'reduced';

    public const TAX_ZERO = 'zero';

    public const TAX_EXEMPT = 'exempt';

    protected $table = 'commerce_products';

    protected $fillable = [
        'sku',
        'owner_entity_id',
        'name',
        'description',
        'short_description',
        'category',
        'subcategory',
        'tags',
        'price',
        'cost_price',
        'rrp',
        'currency',
        'price_tiers',
        'tax_class',
        'tax_inclusive',
        'weight',
        'length',
        'width',
        'height',
        'track_stock',
        'stock_quantity',
        'low_stock_threshold',
        'stock_status',
        'allow_backorder',
        'type',
        'parent_id',
        'variant_attributes',
        'image_url',
        'gallery_urls',
        'slug',
        'meta_title',
        'meta_description',
        'is_active',
        'is_featured',
        'is_visible',
        'available_from',
        'available_until',
        'sort_order',
        'metadata',
    ];

    protected $casts = [
        'tags' => 'array',
        'price' => 'integer',
        'cost_price' => 'integer',
        'rrp' => 'integer',
        'price_tiers' => 'array',
        'tax_inclusive' => 'boolean',
        'weight' => 'decimal:3',
        'length' => 'decimal:2',
        'width' => 'decimal:2',
        'height' => 'decimal:2',
        'track_stock' => 'boolean',
        'stock_quantity' => 'integer',
        'low_stock_threshold' => 'integer',
        'allow_backorder' => 'boolean',
        'variant_attributes' => 'array',
        'gallery_urls' => 'array',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'is_visible' => 'boolean',
        'available_from' => 'datetime',
        'available_until' => 'datetime',
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];

    // Relationships

    public function ownerEntity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'owner_entity_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ProductAssignment::class, 'product_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class, 'product_id');
    }

    // Type helpers

    public function isSimple(): bool
    {
        return $this->type === self::TYPE_SIMPLE;
    }

    public function isVariable(): bool
    {
        return $this->type === self::TYPE_VARIABLE;
    }

    public function isBundle(): bool
    {
        return $this->type === self::TYPE_BUNDLE;
    }

    public function isVirtual(): bool
    {
        return $this->type === self::TYPE_VIRTUAL;
    }

    public function isSubscription(): bool
    {
        return $this->type === self::TYPE_SUBSCRIPTION;
    }

    public function isVariant(): bool
    {
        return $this->parent_id !== null;
    }

    // Stock helpers

    public function isInStock(): bool
    {
        if (! $this->track_stock) {
            return true;
        }

        return $this->stock_quantity > 0 || $this->allow_backorder;
    }

    public function isLowStock(): bool
    {
        return $this->track_stock && $this->stock_quantity <= $this->low_stock_threshold;
    }

    public function updateStockStatus(): self
    {
        if (! $this->track_stock) {
            $this->stock_status = self::STOCK_IN_STOCK;
        } elseif ($this->stock_quantity <= 0) {
            $this->stock_status = $this->allow_backorder ? self::STOCK_BACKORDER : self::STOCK_OUT;
        } elseif ($this->stock_quantity <= $this->low_stock_threshold) {
            $this->stock_status = self::STOCK_LOW;
        } else {
            $this->stock_status = self::STOCK_IN_STOCK;
        }

        return $this;
    }

    public function adjustStock(int $quantity, string $reason = ''): self
    {
        $this->stock_quantity += $quantity;
        $this->updateStockStatus();
        $this->save();

        return $this;
    }

    // Price helpers

    /**
     * Get formatted price.
     */
    public function getFormattedPriceAttribute(): string
    {
        return $this->formatPrice($this->price);
    }

    /**
     * Get price for a specific tier.
     */
    public function getTierPrice(string $tier): ?int
    {
        return $this->price_tiers[$tier] ?? null;
    }

    /**
     * Format a price value.
     */
    public function formatPrice(int $amount, ?string $currency = null): string
    {
        $currency = $currency ?? $this->currency;
        $currencyService = app(\Core\Mod\Commerce\Services\CurrencyService::class);

        return $currencyService->format($amount, $currency, isCents: true);
    }

    /**
     * Get price in a specific currency.
     *
     * Returns explicit price if set, otherwise auto-converts from base price.
     */
    public function getPriceInCurrency(string $currency): ?int
    {
        $currency = strtoupper($currency);

        // Check for explicit price
        $price = $this->prices()->where('currency', $currency)->first();

        if ($price) {
            return $price->amount;
        }

        // Auto-convert if enabled
        if (! config('commerce.currencies.auto_convert', true)) {
            return null;
        }

        // Same currency as base
        if ($currency === $this->currency) {
            return $this->price;
        }

        // Convert from base currency
        $rate = ExchangeRate::getRate($this->currency, $currency);

        if ($rate === null) {
            return null;
        }

        return (int) round($this->price * $rate);
    }

    /**
     * Get formatted price in a specific currency.
     */
    public function getFormattedPriceInCurrency(string $currency): ?string
    {
        $amount = $this->getPriceInCurrency($currency);

        if ($amount === null) {
            return null;
        }

        return $this->formatPrice($amount, $currency);
    }

    /**
     * Set an explicit price for a currency.
     */
    public function setPriceForCurrency(string $currency, int $amount): ProductPrice
    {
        return $this->prices()->updateOrCreate(
            ['currency' => strtoupper($currency)],
            [
                'amount' => $amount,
                'is_manual' => true,
                'exchange_rate_used' => null,
            ]
        );
    }

    /**
     * Remove explicit price for a currency (will fall back to conversion).
     */
    public function removePriceForCurrency(string $currency): bool
    {
        return $this->prices()
            ->where('currency', strtoupper($currency))
            ->delete() > 0;
    }

    /**
     * Refresh all auto-converted prices from exchange rates.
     */
    public function refreshConvertedPrices(): void
    {
        ProductPrice::refreshAutoConverted($this);
    }

    /**
     * Calculate margin percentage.
     */
    public function getMarginPercentAttribute(): ?float
    {
        if (! $this->cost_price || $this->cost_price === 0) {
            return null;
        }

        return round((($this->price - $this->cost_price) / $this->price) * 100, 2);
    }

    // SKU helpers

    /**
     * Build full SKU with entity lineage.
     */
    public function buildFullSku(Entity $entity): string
    {
        return $entity->buildSku($this->sku);
    }

    /**
     * Generate a unique SKU.
     */
    public static function generateSku(string $prefix = ''): string
    {
        $random = strtoupper(Str::random(8));

        return $prefix ? "{$prefix}-{$random}" : $random;
    }

    // Availability helpers

    public function isAvailable(): bool
    {
        if (! $this->is_active || ! $this->is_visible) {
            return false;
        }

        $now = now();

        if ($this->available_from && $now->lt($this->available_from)) {
            return false;
        }

        if ($this->available_until && $now->gt($this->available_until)) {
            return false;
        }

        return true;
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeVisible($query)
    {
        return $query->where('is_visible', true);
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopeInStock($query)
    {
        return $query->where(function ($q) {
            $q->where('track_stock', false)
                ->orWhere('stock_quantity', '>', 0)
                ->orWhere('allow_backorder', true);
        });
    }

    public function scopeForOwner($query, int $entityId)
    {
        return $query->where('owner_entity_id', $entityId);
    }

    public function scopeInCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeParentsOnly($query)
    {
        return $query->whereNull('parent_id');
    }

    // Content override support

    /**
     * Get the fields that can be overridden by M2/M3 entities.
     */
    public function getOverrideableFields(): array
    {
        return [
            'name',
            'description',
            'short_description',
            'image_url',
            'gallery_urls',
            'meta_title',
            'meta_description',
            'slug',
        ];
    }

    // Boot

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $product) {
            // Generate slug if not set
            if (! $product->slug) {
                $product->slug = Str::slug($product->name);
            }

            // Uppercase SKU
            $product->sku = strtoupper($product->sku);
        });

        static::saving(function (self $product) {
            // Update stock status
            $product->updateStockStatus();
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'sku', 'price', 'is_active', 'stock_status'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Product {$eventName}");
    }
}
