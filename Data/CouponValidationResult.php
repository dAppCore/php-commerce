<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Data;

use Core\Mod\Commerce\Models\Coupon;

/**
 * Coupon validation result.
 */
class CouponValidationResult
{
    public function __construct(
        public readonly bool $isValid,
        public readonly ?Coupon $coupon,
        public readonly ?string $error,
    ) {}

    public static function valid(Coupon $coupon): self
    {
        return new self(true, $coupon, null);
    }

    public static function invalid(string $error): self
    {
        return new self(false, null, $error);
    }

    public function isValid(): bool
    {
        return $this->isValid;
    }

    public function getDiscount(float $amount): float
    {
        if (! $this->isValid || ! $this->coupon) {
            return 0;
        }

        return $this->coupon->calculateDiscount($amount);
    }
}
