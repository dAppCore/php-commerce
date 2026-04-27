<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Commerce Inventory - Stock level at a warehouse.
 *
 * @property int $id
 * @property int $product_id
 * @property int $warehouse_id
 * @property int $quantity
 * @property int $reserved_quantity
 * @property int $incoming_quantity
 * @property int|null $low_stock_threshold
 * @property string|null $bin_location
 * @property string|null $zone
 * @property Carbon|null $last_counted_at
 * @property Carbon|null $last_restocked_at
 * @property int|null $unit_cost
 * @property array|null $metadata
 */
class Inventory extends Model
{
    protected $table = 'commerce_inventory';

    protected $fillable = [
        'product_id',
        'warehouse_id',
        'quantity',
        'reserved_quantity',
        'incoming_quantity',
        'low_stock_threshold',
        'bin_location',
        'zone',
        'last_counted_at',
        'last_restocked_at',
        'unit_cost',
        'metadata',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'reserved_quantity' => 'integer',
        'incoming_quantity' => 'integer',
        'low_stock_threshold' => 'integer',
        'unit_cost' => 'integer',
        'last_counted_at' => 'datetime',
        'last_restocked_at' => 'datetime',
        'metadata' => 'array',
    ];

    // Relationships

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'inventory_id');
    }

    // Quantity helpers

    /**
     * Get available quantity (not reserved).
     */
    public function getAvailableQuantity(): int
    {
        return max(0, $this->quantity - $this->reserved_quantity);
    }

    /**
     * Get total expected quantity (including incoming).
     */
    public function getTotalExpectedQuantity(): int
    {
        return $this->quantity + $this->incoming_quantity;
    }

    /**
     * Check if low on stock.
     */
    public function isLowStock(): bool
    {
        $threshold = $this->low_stock_threshold
            ?? $this->product?->low_stock_threshold
            ?? 5;

        return $this->getAvailableQuantity() <= $threshold;
    }

    /**
     * Check if out of stock.
     */
    public function isOutOfStock(): bool
    {
        return $this->getAvailableQuantity() <= 0;
    }

    // Stock operations

    /**
     * Reserve stock for an order.
     */
    public function reserve(int $quantity): bool
    {
        if ($this->getAvailableQuantity() < $quantity) {
            return false;
        }

        $this->increment('reserved_quantity', $quantity);

        return true;
    }

    /**
     * Release reserved stock.
     */
    public function release(int $quantity): void
    {
        $this->decrement('reserved_quantity', min($quantity, $this->reserved_quantity));
    }

    /**
     * Fulfill reserved stock (convert to sale).
     */
    public function fulfill(int $quantity): bool
    {
        if ($this->reserved_quantity < $quantity) {
            return false;
        }

        $this->decrement('quantity', $quantity);
        $this->decrement('reserved_quantity', $quantity);

        return true;
    }

    /**
     * Add stock.
     */
    public function addStock(int $quantity): void
    {
        $this->increment('quantity', $quantity);
        $this->last_restocked_at = now();
        $this->save();
    }

    /**
     * Remove stock.
     */
    public function removeStock(int $quantity): bool
    {
        if ($this->getAvailableQuantity() < $quantity) {
            return false;
        }

        $this->decrement('quantity', $quantity);

        return true;
    }

    /**
     * Set stock count (for physical count).
     */
    public function setCount(int $quantity): int
    {
        $difference = $quantity - $this->quantity;
        $this->quantity = $quantity;
        $this->last_counted_at = now();
        $this->save();

        return $difference;
    }

    // Scopes

    public function scopeLowStock($query)
    {
        // Uses a subquery to compare against threshold
        return $query->whereRaw('(quantity - reserved_quantity) <= COALESCE(low_stock_threshold, 5)');
    }

    public function scopeOutOfStock($query)
    {
        return $query->whereRaw('(quantity - reserved_quantity) <= 0');
    }

    public function scopeInStock($query)
    {
        return $query->whereRaw('(quantity - reserved_quantity) > 0');
    }

    public function scopeForWarehouse($query, int $warehouseId)
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    public function scopeForProduct($query, int $productId)
    {
        return $query->where('product_id', $productId);
    }
}
