<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * InvoiceItem model representing a line item on an invoice.
 *
 * @property int $id
 * @property int $invoice_id
 * @property int|null $order_item_id
 * @property string $description
 * @property int $quantity
 * @property float $unit_price
 * @property float $line_total
 * @property bool $taxable
 * @property float $tax_rate
 * @property float $tax_amount
 * @property array|null $metadata
 */
class InvoiceItem extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'invoice_id',
        'order_item_id',
        'description',
        'quantity',
        'unit_price',
        'line_total',
        'taxable',
        'tax_rate',
        'tax_amount',
        'metadata',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
        'taxable' => 'boolean',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    // Relationships

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    // Helpers

    public function calculateTax(float $rate): void
    {
        $this->tax_rate = $rate;
        $this->tax_amount = $this->taxable
            ? round($this->line_total * ($rate / 100), 2)
            : 0;
    }
}
