<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\DTOs;

readonly class CouponValidationResult
{
    public function __construct(
        public bool $valid,
        public ?string $reason,
        public float $discountAmount,
        public string $discountType,
    ) {}

    /**
     * @return array{valid: bool, reason: string|null, discount_amount: float, discount_type: string}
     */
    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'reason' => $this->reason,
            'discount_amount' => $this->discountAmount,
            'discount_type' => $this->discountType,
        ];
    }
}
