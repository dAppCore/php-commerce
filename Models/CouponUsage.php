<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Models;

use Carbon\Carbon;
use Core\Tenant\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CouponUsage model for tracking coupon redemptions.
 *
 * @property int $id
 * @property int $coupon_id
 * @property int $workspace_id
 * @property int $order_id
 * @property float $discount_amount
 * @property Carbon $created_at
 */
class CouponUsage extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'coupon_id',
        'workspace_id',
        'order_id',
        'discount_amount',
    ];

    protected $casts = [
        'discount_amount' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    // Relationships

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    // Boot

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($usage) {
            $usage->created_at = now();
        });
    }
}
