<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Models;

use Carbon\Carbon;
use Core\Mod\Commerce\Database\Factories\InvoiceFactory;
use Core\Tenant\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Invoice model representing a billing document.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $order_id
 * @property string $invoice_number
 * @property string $status
 * @property string $currency
 * @property float $subtotal
 * @property float $tax_amount
 * @property float $discount_amount
 * @property float $total
 * @property float $amount_paid
 * @property float $amount_due
 * @property Carbon $issue_date
 * @property Carbon $due_date
 * @property Carbon|null $paid_at
 * @property string|null $billing_name
 * @property array|null $billing_address
 * @property string|null $tax_id
 * @property string|null $pdf_path
 */
class Invoice extends Model
{
    use HasFactory;

    protected static function newFactory(): InvoiceFactory
    {
        return InvoiceFactory::new();
    }

    protected $fillable = [
        'workspace_id',
        'order_id',
        'payment_id',
        'invoice_number',
        'status',
        'currency',
        'subtotal',
        'tax_amount',
        'tax_rate',
        'tax_country',
        'discount_amount',
        'total',
        'amount_paid',
        'amount_due',
        'issue_date',
        'due_date',
        'paid_at',
        'billing_name',
        'billing_email',
        'billing_address',
        'tax_id',
        'pdf_path',
        'auto_charge',
        'charge_attempts',
        'last_charge_attempt',
        'next_charge_attempt',
        'metadata',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'amount_due' => 'decimal:2',
        'issue_date' => 'date',
        'due_date' => 'date',
        'paid_at' => 'datetime',
        'billing_address' => 'array',
        'auto_charge' => 'boolean',
        'charge_attempts' => 'integer',
        'last_charge_attempt' => 'datetime',
        'next_charge_attempt' => 'datetime',
        'metadata' => 'array',
    ];

    // Accessors for compatibility

    /**
     * Get the issued_at attribute (alias for issue_date).
     */
    public function getIssuedAtAttribute(): ?Carbon
    {
        return $this->issue_date;
    }

    // Relationships

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    // Status helpers

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isSent(): bool
    {
        return $this->status === 'sent';
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isPending(): bool
    {
        return in_array($this->status, ['draft', 'sent', 'pending']);
    }

    public function isOverdue(): bool
    {
        return $this->status === 'overdue' ||
            ($this->isPending() && $this->due_date && $this->due_date->isPast());
    }

    public function isVoid(): bool
    {
        return $this->status === 'void';
    }

    // Actions

    public function markAsPaid(?Payment $payment = null): void
    {
        $data = [
            'status' => 'paid',
            'paid_at' => now(),
            'amount_paid' => $this->total,
            'amount_due' => 0,
        ];

        if ($payment) {
            $data['payment_id'] = $payment->id;
        }

        $this->update($data);
    }

    public function markAsVoid(): void
    {
        $this->update(['status' => 'void']);
    }

    public function send(): void
    {
        $this->update(['status' => 'sent']);
    }

    // Scopes

    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }

    public function scopeUnpaid($query)
    {
        return $query->whereIn('status', ['draft', 'sent', 'pending', 'overdue']);
    }

    public function scopePending($query)
    {
        return $query->whereIn('status', ['draft', 'sent', 'pending']);
    }

    public function scopeOverdue($query)
    {
        return $query->where(function ($q) {
            $q->where('status', 'overdue')
                ->orWhere(function ($q2) {
                    $q2->whereIn('status', ['draft', 'sent', 'pending'])
                        ->where('due_date', '<', now());
                });
        });
    }

    public function scopeForWorkspace($query, int $workspaceId)
    {
        return $query->where('workspace_id', $workspaceId);
    }

    // Invoice number generation

    public static function generateInvoiceNumber(): string
    {
        $prefix = config('commerce.billing.invoice_prefix', 'INV-');
        $year = now()->format('Y');

        // Get the last invoice number for this year
        $lastInvoice = static::where('invoice_number', 'like', "{$prefix}{$year}-%")
            ->orderByDesc('id')
            ->first();

        if ($lastInvoice) {
            $lastNumber = (int) substr($lastInvoice->invoice_number, -4);
            $nextNumber = $lastNumber + 1;
        } else {
            $nextNumber = config('commerce.billing.invoice_start_number', 1000);
        }

        return sprintf('%s%s-%04d', $prefix, $year, $nextNumber);
    }
}
