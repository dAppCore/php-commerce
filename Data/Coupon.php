<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Data;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Core\Mod\Commerce\Models\Coupon as CouponModel;

/**
 * Persisted coupon data used by the RFC CouponService API.
 */
readonly class Coupon
{
    public function __construct(
        public int $id,
        public string $code,
        public string $type,
        public float $value,
        public ?int $maxUses,
        public ?CarbonImmutable $expiresAt,
        public bool $active,
        public int $usedCount,
    ) {}

    public static function fromModel(CouponModel $coupon): self
    {
        return new self(
            id: (int) $coupon->id,
            code: (string) $coupon->code,
            type: in_array((string) $coupon->type, ['percent', 'percentage'], true) ? 'percent' : 'fixed',
            value: (float) $coupon->value,
            maxUses: $coupon->max_uses === null ? null : (int) $coupon->max_uses,
            expiresAt: self::immutableDate($coupon->valid_until),
            active: (bool) $coupon->is_active,
            usedCount: (int) $coupon->used_count,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'type' => $this->type,
            'value' => $this->value,
            'max_uses' => $this->maxUses,
            'expires_at' => $this->expiresAt?->toIso8601String(),
            'active' => $this->active,
            'used_count' => $this->usedCount,
        ];
    }

    public function isExpired(): bool
    {
        return $this->expiresAt?->isPast() ?? false;
    }

    public function __get(string $name): mixed
    {
        return match ($name) {
            'max_uses' => $this->maxUses,
            'expires_at' => $this->expiresAt,
            'is_active' => $this->active,
            'used_count' => $this->usedCount,
            default => null,
        };
    }

    private static function immutableDate(mixed $value): ?CarbonImmutable
    {
        if (! $value instanceof CarbonInterface) {
            return null;
        }

        return CarbonImmutable::instance($value->toDateTime());
    }
}
