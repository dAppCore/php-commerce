<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Models;

use Core\Tenant\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Refund model for tracking payment refunds.
 *
 * @property int $id
 * @property int $payment_id
 * @property string|null $gateway_refund_id
 * @property float $amount
 * @property string $currency
 * @property string $status
 * @property string|null $reason
 * @property string|null $notes
 * @property int|null $initiated_by
 * @property array|null $gateway_response
 */
class Refund extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'payment_id',
        'gateway_refund_id',
        'amount',
        'currency',
        'status',
        'reason',
        'notes',
        'initiated_by',
        'gateway_response',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'gateway_response' => 'array',
    ];

    // Relationships

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function creditNote(): HasOne
    {
        return $this->hasOne(CreditNote::class);
    }

    // Status helpers

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isSucceeded(): bool
    {
        return $this->status === 'succeeded';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    // Actions

    public function markAsSucceeded(?string $gatewayRefundId = null): void
    {
        $this->update([
            'status' => 'succeeded',
            'gateway_refund_id' => $gatewayRefundId ?? $this->gateway_refund_id,
        ]);

        // Update payment refunded amount
        $this->payment->recordRefund($this->amount);
    }

    public function markAsFailed(?array $response = null): void
    {
        $this->update([
            'status' => 'failed',
            'gateway_response' => $response,
        ]);
    }

    public function cancel(): void
    {
        $this->update(['status' => 'cancelled']);
    }

    // Scopes

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeSucceeded($query)
    {
        return $query->where('status', 'succeeded');
    }

    // Reason helpers

    public function getReasonLabel(): string
    {
        return match ($this->reason) {
            'duplicate' => 'Duplicate payment',
            'fraudulent' => 'Fraudulent transaction',
            'requested_by_customer' => 'Customer request',
            'other' => 'Other',
            default => 'Unknown',
        };
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'amount', 'reason'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Refund {$eventName}");
    }
}
