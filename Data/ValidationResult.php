<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Data;

/**
 * Coupon validation result for the RFC CouponService API.
 */
readonly class ValidationResult
{
    public function __construct(
        public bool $valid,
        public ?string $reason,
        public float $discountAmount,
        public string $discountType,
        public ?Coupon $coupon = null,
    ) {}

    public static function valid(Coupon $coupon, float $discountAmount, string $discountType): self
    {
        return new self(
            valid: true,
            reason: null,
            discountAmount: round($discountAmount, 2),
            discountType: $discountType,
            coupon: $coupon,
        );
    }

    public static function invalid(
        string $reason,
        string $discountType = 'none',
        ?Coupon $coupon = null,
    ): self {
        return new self(
            valid: false,
            reason: $reason,
            discountAmount: 0.0,
            discountType: $discountType,
            coupon: $coupon,
        );
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function getMessage(): ?string
    {
        return $this->reason;
    }

    public function getCoupon(): ?Coupon
    {
        return $this->coupon;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'reason' => $this->reason,
            'discount_amount' => $this->discountAmount,
            'discount_type' => $this->discountType,
            'coupon' => $this->coupon?->toArray(),
        ];
    }

    public function __get(string $name): mixed
    {
        return match ($name) {
            'discount_amount' => $this->discountAmount,
            'discount_type' => $this->discountType,
            default => null,
        };
    }
}
