<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Models;

use Carbon\Carbon;
use Core\Tenant\Models\User;
use Core\Tenant\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Credit Note model for tracking credits issued to users.
 *
 * Credit notes can be issued as:
 * - General credits (goodwill, promotional)
 * - Partial refunds as store credit
 * - Applied to orders to reduce payment amount
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $user_id
 * @property int|null $order_id
 * @property int|null $refund_id
 * @property string $reference_number
 * @property float $amount
 * @property string $currency
 * @property string $reason
 * @property string|null $description
 * @property string $status
 * @property float $amount_used
 * @property int|null $applied_to_order_id
 * @property Carbon|null $issued_at
 * @property Carbon|null $applied_at
 * @property Carbon|null $voided_at
 * @property int|null $issued_by
 * @property int|null $voided_by
 * @property array|null $metadata
 */
class CreditNote extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'workspace_id',
        'user_id',
        'order_id',
        'refund_id',
        'reference_number',
        'amount',
        'currency',
        'reason',
        'description',
        'status',
        'amount_used',
        'applied_to_order_id',
        'issued_at',
        'applied_at',
        'voided_at',
        'issued_by',
        'voided_by',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'amount_used' => 'decimal:2',
        'metadata' => 'array',
        'issued_at' => 'datetime',
        'applied_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    // Relationships

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function appliedToOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'applied_to_order_id');
    }

    public function issuedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function voidedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    // Status helpers

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isIssued(): bool
    {
        return $this->status === 'issued';
    }

    public function isApplied(): bool
    {
        return $this->status === 'applied';
    }

    public function isPartiallyApplied(): bool
    {
        return $this->status === 'partially_applied';
    }

    public function isVoid(): bool
    {
        return $this->status === 'void';
    }

    public function isUsable(): bool
    {
        return in_array($this->status, ['issued', 'partially_applied']);
    }

    // Amount helpers

    public function getRemainingAmount(): float
    {
        return max(0, $this->amount - $this->amount_used);
    }

    public function isFullyUsed(): bool
    {
        return $this->amount_used >= $this->amount;
    }

    // Actions

    public function issue(?User $issuedBy = null): void
    {
        $this->update([
            'status' => 'issued',
            'issued_at' => now(),
            'issued_by' => $issuedBy?->id,
        ]);
    }

    public function recordUsage(float $amount, ?Order $order = null): void
    {
        $newUsed = $this->amount_used + $amount;
        $status = $newUsed >= $this->amount ? 'applied' : 'partially_applied';

        $this->update([
            'amount_used' => $newUsed,
            'status' => $status,
            'applied_to_order_id' => $order?->id ?? $this->applied_to_order_id,
            'applied_at' => $status === 'applied' ? now() : $this->applied_at,
        ]);
    }

    public function void(?User $voidedBy = null): void
    {
        $this->update([
            'status' => 'void',
            'voided_at' => now(),
            'voided_by' => $voidedBy?->id,
        ]);
    }

    // Scopes

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeIssued($query)
    {
        return $query->where('status', 'issued');
    }

    public function scopeUsable($query)
    {
        return $query->whereIn('status', ['issued', 'partially_applied']);
    }

    public function scopeForWorkspace($query, int $workspaceId)
    {
        return $query->where('workspace_id', $workspaceId);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    // Reference number generation

    public static function generateReferenceNumber(): string
    {
        $prefix = 'CN';
        $date = now()->format('Ymd');
        $random = strtoupper(substr(md5(uniqid()), 0, 4));

        return "{$prefix}-{$date}-{$random}";
    }

    // Reason helpers

    public static function reasons(): array
    {
        return [
            'partial_refund' => 'Partial refund as store credit',
            'goodwill' => 'Goodwill gesture',
            'service_issue' => 'Service issue compensation',
            'promotional' => 'Promotional credit',
            'billing_adjustment' => 'Billing adjustment',
            'cancellation' => 'Subscription cancellation credit',
            'other' => 'Other',
        ];
    }

    public function getReasonLabel(): string
    {
        return self::reasons()[$this->reason] ?? ucfirst(str_replace('_', ' ', $this->reason));
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'amount', 'amount_used', 'reason'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName) => "Credit note {$eventName}");
    }
}
