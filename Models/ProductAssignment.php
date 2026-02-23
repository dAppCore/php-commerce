<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Models;

use Core\Mod\Commerce\Concerns\HasContentOverrides;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Product Assignment - Links products to M2/M3 entities.
 *
 * Allows entities to sell products with optional overrides
 * for price, content, and visibility.
 *
 * @property int $id
 * @property int $entity_id
 * @property int $product_id
 * @property string|null $sku_suffix
 * @property int|null $price_override
 * @property array|null $price_tier_overrides
 * @property float|null $margin_percent
 * @property int|null $fixed_margin
 * @property string|null $name_override
 * @property string|null $description_override
 * @property string|null $image_override
 * @property bool $is_active
 * @property bool $is_featured
 * @property int $sort_order
 * @property int|null $allocated_stock
 * @property bool $can_discount
 * @property int|null $min_price
 * @property int|null $max_price
 * @property array|null $metadata
 */
class ProductAssignment extends Model
{
    use HasContentOverrides;

    protected $table = 'commerce_product_assignments';

    protected $fillable = [
        'entity_id',
        'product_id',
        'sku_suffix',
        'price_override',
        'price_tier_overrides',
        'margin_percent',
        'fixed_margin',
        'name_override',
        'description_override',
        'image_override',
        'is_active',
        'is_featured',
        'sort_order',
        'allocated_stock',
        'can_discount',
        'min_price',
        'max_price',
        'metadata',
    ];

    protected $casts = [
        'price_override' => 'integer',
        'price_tier_overrides' => 'array',
        'margin_percent' => 'decimal:2',
        'fixed_margin' => 'integer',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'sort_order' => 'integer',
        'allocated_stock' => 'integer',
        'can_discount' => 'boolean',
        'min_price' => 'integer',
        'max_price' => 'integer',
        'metadata' => 'array',
    ];

    // Relationships

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // Effective value getters (use override if set, else fall back to product)

    /**
     * Get effective price for this assignment.
     */
    public function getEffectivePrice(): int
    {
        return $this->price_override ?? $this->product->price;
    }

    /**
     * Get effective name.
     */
    public function getEffectiveName(): string
    {
        return $this->name_override ?? $this->product->name;
    }

    /**
     * Get effective description.
     */
    public function getEffectiveDescription(): ?string
    {
        return $this->description_override ?? $this->product->description;
    }

    /**
     * Get effective image URL.
     */
    public function getEffectiveImage(): ?string
    {
        return $this->image_override ?? $this->product->image_url;
    }

    /**
     * Get effective tier price.
     */
    public function getEffectiveTierPrice(string $tier): ?int
    {
        if ($this->price_tier_overrides && isset($this->price_tier_overrides[$tier])) {
            return $this->price_tier_overrides[$tier];
        }

        return $this->product->getTierPrice($tier);
    }

    // SKU helpers

    /**
     * Build full SKU for this entity's product.
     * Format: OWNER-ENTITY-BASEKU or OWNER-ENTITY-SUFFIX
     */
    public function getFullSku(): string
    {
        $baseSku = $this->sku_suffix ?? $this->product->sku;

        return $this->entity->buildSku($baseSku);
    }

    /**
     * Get SKU without entity prefix (just the product part).
     */
    public function getBaseSku(): string
    {
        return $this->sku_suffix ?? $this->product->sku;
    }

    // Price validation

    /**
     * Check if a price is within allowed range.
     */
    public function isPriceAllowed(int $price): bool
    {
        if ($this->min_price !== null && $price < $this->min_price) {
            return false;
        }

        if ($this->max_price !== null && $price > $this->max_price) {
            return false;
        }

        return true;
    }

    /**
     * Clamp price to allowed range.
     */
    public function clampPrice(int $price): int
    {
        if ($this->min_price !== null && $price < $this->min_price) {
            return $this->min_price;
        }

        if ($this->max_price !== null && $price > $this->max_price) {
            return $this->max_price;
        }

        return $price;
    }

    // Margin calculation

    /**
     * Calculate entity's margin on this product.
     */
    public function calculateMargin(?int $salePrice = null): int
    {
        $salePrice ??= $this->getEffectivePrice();
        $basePrice = $this->product->price;

        if ($this->fixed_margin !== null) {
            return $this->fixed_margin;
        }

        if ($this->margin_percent !== null) {
            return (int) round($salePrice * ($this->margin_percent / 100));
        }

        // Default: difference between sale and base price
        return $salePrice - $basePrice;
    }

    // Stock helpers

    /**
     * Get available stock for this entity.
     */
    public function getAvailableStock(): int
    {
        // If entity has allocated stock, use that
        if ($this->allocated_stock !== null) {
            return $this->allocated_stock;
        }

        // Otherwise use master product stock
        return $this->product->stock_quantity;
    }

    /**
     * Check if product is available for this entity.
     */
    public function isAvailable(): bool
    {
        return $this->is_active && $this->product->isAvailable();
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopeForEntity($query, int $entityId)
    {
        return $query->where('entity_id', $entityId);
    }

    public function scopeForProduct($query, int $productId)
    {
        return $query->where('product_id', $productId);
    }

    public function scopeWithActiveProducts($query)
    {
        return $query->whereHas('product', fn ($q) => $q->active()->visible());
    }
}
