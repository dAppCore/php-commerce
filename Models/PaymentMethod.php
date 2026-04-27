<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Models;

use Carbon\Carbon;
use Core\Tenant\Models\User;
use Core\Tenant\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PaymentMethod model representing saved payment methods.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $user_id
 * @property string $gateway
 * @property string $gateway_payment_method_id
 * @property string $gateway_customer_id
 * @property string $type
 * @property string|null $brand
 * @property string|null $last_four
 * @property int|null $exp_month
 * @property int|null $exp_year
 * @property bool $is_default
 * @property bool $is_active
 */
class PaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'user_id',
        'gateway',
        'gateway_payment_method_id',
        'gateway_customer_id',
        'type',
        'brand',
        'last_four',
        'exp_month',
        'exp_year',
        'is_default',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'exp_month' => 'integer',
        'exp_year' => 'integer',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'metadata' => 'array',
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

    // Helpers

    public function isCard(): bool
    {
        return $this->type === 'card';
    }

    public function isCrypto(): bool
    {
        return $this->type === 'crypto_wallet';
    }

    public function isBankAccount(): bool
    {
        return $this->type === 'bank_account';
    }

    public function isExpired(): bool
    {
        if (! $this->exp_month || ! $this->exp_year) {
            return false;
        }

        $expiry = Carbon::createFromDate($this->exp_year, $this->exp_month)->endOfMonth();

        return $expiry->isPast();
    }

    public function getDisplayName(): string
    {
        if ($this->isCard()) {
            return sprintf('%s **** %s', ucfirst($this->brand ?? 'Card'), $this->last_four);
        }

        if ($this->isCrypto()) {
            return 'Crypto Wallet';
        }

        return 'Bank Account';
    }

    // Actions

    public function setAsDefault(): void
    {
        // Remove default from other methods
        static::where('workspace_id', $this->workspace_id)
            ->where('id', '!=', $this->id)
            ->update(['is_default' => false]);

        $this->update(['is_default' => true]);
    }

    public function deactivate(): void
    {
        $this->update(['is_active' => false]);
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public function scopeForWorkspace($query, int $workspaceId)
    {
        return $query->where('workspace_id', $workspaceId);
    }
}
