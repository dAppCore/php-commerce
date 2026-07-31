<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\DTOs;

use Carbon\Carbon;

readonly class ProrationResult
{
    public function __construct(
        public float $creditAmount,
        public float $chargeAmount,
        public Carbon $effectiveDate,
    ) {
    }

    public function netAmount(): float
    {
        return round($this->chargeAmount - $this->creditAmount, 2);
    }

    /**
     * @return array{credit_amount: float, charge_amount: float, effective_date: string, net_amount: float}
     */
    public function toArray(): array
    {
        return [
            'credit_amount' => $this->creditAmount,
            'charge_amount' => $this->chargeAmount,
            'effective_date' => $this->effectiveDate->toIso8601String(),
            'net_amount' => $this->netAmount(),
        ];
    }
}
