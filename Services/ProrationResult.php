<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Services;

/**
 * Data transfer object for proration calculations.
 *
 * Contains all details of a proration calculation when upgrading
 * or downgrading a subscription mid-cycle.
 */
readonly class ProrationResult
{
    public function __construct(
        /** Days remaining in current period */
        public int $daysRemaining,

        /** Total days in current period */
        public int $totalPeriodDays,

        /** Percentage of period used (0.0 to 1.0) */
        public float $usedPercentage,

        /** Current plan's period price */
        public float $currentPlanPrice,

        /** New plan's period price */
        public float $newPlanPrice,

        /** Credit from unused current plan time */
        public float $creditAmount,

        /** Prorated cost for new plan remainder */
        public float $proratedNewPlanCost,

        /** Net amount: positive = customer pays, negative = credit */
        public float $netAmount,

        /** Currency code */
        public string $currency = 'GBP',
    ) {}

    /**
     * Check if customer needs to pay (upgrade).
     */
    public function requiresPayment(): bool
    {
        return $this->netAmount > 0;
    }

    /**
     * Check if this is a downgrade (customer gets credit).
     */
    public function isDowngrade(): bool
    {
        return $this->newPlanPrice < $this->currentPlanPrice;
    }

    /**
     * Check if this is an upgrade (new plan costs more).
     */
    public function isUpgrade(): bool
    {
        return $this->newPlanPrice > $this->currentPlanPrice;
    }

    /**
     * Check if plans are same price (lateral move).
     */
    public function isSamePrice(): bool
    {
        return abs($this->newPlanPrice - $this->currentPlanPrice) < 0.01;
    }

    /**
     * Get absolute credit amount (always positive).
     */
    public function getCreditBalance(): float
    {
        return $this->netAmount < 0 ? abs($this->netAmount) : 0;
    }

    /**
     * Get amount due (always positive or zero).
     */
    public function getAmountDue(): float
    {
        return max(0, $this->netAmount);
    }

    /**
     * Convert to array for storage or display.
     */
    public function toArray(): array
    {
        return [
            'days_remaining' => $this->daysRemaining,
            'total_period_days' => $this->totalPeriodDays,
            'used_percentage' => round($this->usedPercentage * 100, 2),
            'current_plan_price' => $this->currentPlanPrice,
            'new_plan_price' => $this->newPlanPrice,
            'credit_amount' => $this->creditAmount,
            'prorated_new_plan_cost' => $this->proratedNewPlanCost,
            'net_amount' => $this->netAmount,
            'currency' => $this->currency,
            'is_upgrade' => $this->isUpgrade(),
            'is_downgrade' => $this->isDowngrade(),
            'requires_payment' => $this->requiresPayment(),
        ];
    }
}
