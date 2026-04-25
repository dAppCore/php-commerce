<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bundle discount lookup by hash.
 *
 * When a compound SKU contains pipe-separated items (a bundle), we:
 * 1. Strip the options to get base SKUs
 * 2. Sort and hash them
 * 3. Look up the hash to find any applicable discount
 *
 * This allows "LAPTOP|MOUSE|PAD" to automatically trigger a bundle deal
 * regardless of what options (ram~16gb, color~black) the customer chose.
 */
class BundleHash extends Model
{
    protected $table = 'commerce_bundle_hashes';

    protected $fillable = [
        'hash',
        'product_ids',
        'base_skus',
        'coupon_code',
        'fixed_price',
        'discount_percent',
        'discount_amount',
        'entity_id',
        'assignment_id',
        'name',
        'description',
        'min_quantity',
        'max_uses',
        'valid_from',
        'valid_until',
        'active',
    ];

    protected $casts = [
        'fixed_price' => 'decimal:2',
        'product_ids' => 'array',
        'discount_percent' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'min_quantity' => 'integer',
        'max_uses' => 'integer',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'active' => 'boolean',
    ];

    // Relationships

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ProductAssignment::class, 'assignment_id');
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeForEntity($query, Entity|int $entity)
    {
        $entityId = $entity instanceof Entity ? $entity->id : $entity;

        return $query->where('entity_id', $entityId);
    }

    public function scopeValid($query)
    {
        return $query
            ->where(function ($q) {
                $q->whereNull('valid_from')
                    ->orWhere('valid_from', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', now());
            });
    }

    public function scopeByHash($query, string $hash)
    {
        return $query->where('hash', $hash);
    }

    // Lookup methods

    /**
     * Find bundle discount by hash for an entity.
     */
    public static function findByHash(string $hash, Entity|int $entity): ?self
    {
        return static::byHash($hash)
            ->forEntity($entity)
            ->active()
            ->valid()
            ->first();
    }

    /**
     * Find bundle discount with entity hierarchy fallback.
     *
     * Checks entity first, then walks up to parent entities.
     */
    public static function findWithHierarchy(string $hash, Entity $entity): ?self
    {
        // Check this entity first
        $bundle = static::findByHash($hash, $entity);

        if ($bundle) {
            return $bundle;
        }

        // Walk up the hierarchy
        $parent = $entity->parent;

        while ($parent) {
            $bundle = static::findByHash($hash, $parent);

            if ($bundle) {
                return $bundle;
            }

            $parent = $parent->parent;
        }

        return null;
    }

    // Discount calculation

    /**
     * Check if this bundle discount is currently valid.
     */
    public function isValid(): bool
    {
        if (! $this->active) {
            return false;
        }

        if ($this->valid_from && $this->valid_from->isFuture()) {
            return false;
        }

        if ($this->valid_until && $this->valid_until->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Calculate discount for a given subtotal.
     */
    public function calculateDiscount(float $subtotal): float
    {
        if ($this->fixed_price !== null) {
            // Fixed price means discount is difference from subtotal
            return max(0, $subtotal - (float) $this->fixed_price);
        }

        if ($this->discount_amount !== null) {
            return min($subtotal, (float) $this->discount_amount);
        }

        if ($this->discount_percent !== null) {
            return $subtotal * ((float) $this->discount_percent / 100);
        }

        return 0;
    }

    /**
     * Get the final price after bundle discount.
     */
    public function getFinalPrice(float $subtotal): float
    {
        if ($this->fixed_price !== null) {
            return (float) $this->fixed_price;
        }

        return $subtotal - $this->calculateDiscount($subtotal);
    }

    // Factory methods

    /**
     * Create a bundle hash from base SKUs.
     *
     * @param  array<string>  $baseSkus
     */
    public static function createFromSkus(array $baseSkus, Entity|int $entity, array $attributes = []): self
    {
        $sorted = collect($baseSkus)
            ->map(fn (string $sku) => strtoupper($sku))
            ->sort()
            ->values();

        $hash = hash('sha256', $sorted->implode('|'));
        $entityId = $entity instanceof Entity ? $entity->id : $entity;

        return static::create(array_merge([
            'hash' => $hash,
            'base_skus' => $sorted->implode('|'),
            'entity_id' => $entityId,
        ], $attributes));
    }
}
