<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Models;

use Core\Tenant\Models\Package;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OrderItem model representing a line item in an order.
 *
 * @property int $id
 * @property int $order_id
 * @property string $item_type
 * @property int|null $item_id
 * @property string|null $item_code
 * @property string $description
 * @property int $quantity
 * @property float $unit_price
 * @property float $line_total
 * @property string $billing_cycle
 * @property array|null $metadata
 */
class OrderItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'item_type',
        'item_id',
        'item_code',
        'description',
        'quantity',
        'unit_price',
        'line_total',
        'billing_cycle',
        'metadata',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    // Relationships

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'item_id')
            ->where('item_type', 'package');
    }

    // Helpers

    public function isPackage(): bool
    {
        return $this->item_type === 'package';
    }

    public function isAddon(): bool
    {
        return $this->item_type === 'addon';
    }

    public function isBoost(): bool
    {
        return $this->item_type === 'boost';
    }

    public function isMonthly(): bool
    {
        return $this->billing_cycle === 'monthly';
    }

    public function isYearly(): bool
    {
        return $this->billing_cycle === 'yearly';
    }

    public function isOneTime(): bool
    {
        return $this->billing_cycle === 'onetime';
    }
}
