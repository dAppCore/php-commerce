<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Models;

use Carbon\Carbon;
use Core\Tenant\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Inventory Movement - Tracks all stock changes.
 *
 * Provides audit trail for inventory operations.
 *
 * @property int $id
 * @property int|null $inventory_id
 * @property int $product_id
 * @property int $warehouse_id
 * @property string $type
 * @property int $quantity
 * @property int $balance_after
 * @property string|null $reference
 * @property string|null $notes
 * @property int|null $user_id
 * @property int|null $unit_cost
 * @property Carbon $created_at
 */
class InventoryMovement extends Model
{
    public $timestamps = false;

    protected $table = 'commerce_inventory_movements';

    // Movement types
    public const TYPE_PURCHASE = 'purchase';

    public const TYPE_SALE = 'sale';

    public const TYPE_TRANSFER_IN = 'transfer_in';

    public const TYPE_TRANSFER_OUT = 'transfer_out';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_RETURN = 'return';

    public const TYPE_DAMAGED = 'damaged';

    public const TYPE_RESERVED = 'reserved';

    public const TYPE_RELEASED = 'released';

    public const TYPE_COUNT = 'count';

    protected $fillable = [
        'inventory_id',
        'product_id',
        'warehouse_id',
        'type',
        'quantity',
        'balance_after',
        'reference',
        'notes',
        'user_id',
        'unit_cost',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'balance_after' => 'integer',
        'unit_cost' => 'integer',
        'created_at' => 'datetime',
    ];

    // Relationships

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Helpers

    /**
     * Check if this is an inbound movement.
     */
    public function isInbound(): bool
    {
        return $this->quantity > 0;
    }

    /**
     * Check if this is an outbound movement.
     */
    public function isOutbound(): bool
    {
        return $this->quantity < 0;
    }

    /**
     * Get absolute quantity.
     */
    public function getAbsoluteQuantity(): int
    {
        return abs($this->quantity);
    }

    /**
     * Get human-readable type.
     */
    public function getTypeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_PURCHASE => 'Purchase',
            self::TYPE_SALE => 'Sale',
            self::TYPE_TRANSFER_IN => 'Transfer In',
            self::TYPE_TRANSFER_OUT => 'Transfer Out',
            self::TYPE_ADJUSTMENT => 'Adjustment',
            self::TYPE_RETURN => 'Return',
            self::TYPE_DAMAGED => 'Damaged',
            self::TYPE_RESERVED => 'Reserved',
            self::TYPE_RELEASED => 'Released',
            self::TYPE_COUNT => 'Stock Count',
            default => ucfirst($this->type),
        };
    }

    // Factory methods

    /**
     * Record a movement.
     */
    public static function record(
        Inventory $inventory,
        string $type,
        int $quantity,
        ?string $reference = null,
        ?string $notes = null,
        ?int $userId = null,
        ?int $unitCost = null
    ): self {
        return static::create([
            'inventory_id' => $inventory->id,
            'product_id' => $inventory->product_id,
            'warehouse_id' => $inventory->warehouse_id,
            'type' => $type,
            'quantity' => $quantity,
            'balance_after' => $inventory->quantity,
            'reference' => $reference,
            'notes' => $notes,
            'user_id' => $userId ?? auth()->id(),
            'unit_cost' => $unitCost,
            'created_at' => now(),
        ]);
    }

    // Scopes

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeInbound($query)
    {
        return $query->where('quantity', '>', 0);
    }

    public function scopeOutbound($query)
    {
        return $query->where('quantity', '<', 0);
    }

    public function scopeForProduct($query, int $productId)
    {
        return $query->where('product_id', $productId);
    }

    public function scopeForWarehouse($query, int $warehouseId)
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    public function scopeWithReference($query, string $reference)
    {
        return $query->where('reference', $reference);
    }

    // Boot

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $movement) {
            if (! $movement->created_at) {
                $movement->created_at = now();
            }
        });
    }
}
