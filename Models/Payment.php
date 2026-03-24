<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Models;

use Core\Mod\Commerce\Database\Factories\PaymentFactory;
use Core\Tenant\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Payment model representing money received.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $invoice_id
 * @property string $gateway
 * @property string|null $gateway_payment_id
 * @property string|null $gateway_customer_id
 * @property string $currency
 * @property float $amount
 * @property float $fee
 * @property float $net_amount
 * @property string $status
 * @property string|null $failure_reason
 * @property string|null $payment_method_type
 * @property string|null $payment_method_last4
 * @property string|null $payment_method_brand
 * @property array|null $gateway_response
 * @property float $refunded_amount
 */
class Payment extends Model
{
    use HasFactory;

    protected static function newFactory(): PaymentFactory
    {
        return PaymentFactory::new();
    }

    protected $fillable = [
        'workspace_id',
        'invoice_id',
        'order_id',
        'gateway',
        'gateway_payment_id',
        'gateway_customer_id',
        'currency',
        'amount',
        'fee',
        'net_amount',
        'status',
        'failure_reason',
        'payment_method_type',
        'payment_method_last4',
        'payment_method_brand',
        'gateway_response',
        'refunded_amount',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'fee' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'refunded_amount' => 'decimal:2',
        'gateway_response' => 'array',
        'paid_at' => 'datetime',
    ];

    // Relationships

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    // Status helpers

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    public function isSucceeded(): bool
    {
        return $this->status === 'succeeded';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isRefunded(): bool
    {
        return $this->status === 'refunded';
    }

    public function isPartiallyRefunded(): bool
    {
        return $this->status === 'partially_refunded';
    }

    public function canRefund(): bool
    {
        return $this->isSucceeded() || $this->isPartiallyRefunded();
    }

    public function isFullyRefunded(): bool
    {
        return $this->refunded_amount >= $this->amount;
    }

    public function getRefundableAmount(): float
    {
        return $this->amount - $this->refunded_amount;
    }

    // Actions

    public function markAsSucceeded(): void
    {
        $this->update(['status' => 'succeeded']);
    }

    public function markAsFailed(?string $reason = null): void
    {
        $this->update([
            'status' => 'failed',
            'failure_reason' => $reason,
        ]);
    }

    public function recordRefund(float $amount): void
    {
        $newRefundedAmount = $this->refunded_amount + $amount;
        $status = $newRefundedAmount >= $this->amount
            ? 'refunded'
            : 'partially_refunded';

        $this->update([
            'refunded_amount' => $newRefundedAmount,
            'status' => $status,
        ]);
    }

    // Scopes

    public function scopeSucceeded($query)
    {
        return $query->where('status', 'succeeded');
    }

    public function scopeForWorkspace($query, int $workspaceId)
    {
        return $query->where('workspace_id', $workspaceId);
    }

    public function scopeForGateway($query, string $gateway)
    {
        return $query->where('gateway', $gateway);
    }
}
