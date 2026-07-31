<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Data;

/**
 * Fraud assessment result.
 *
 * Contains risk level, signals, and recommended actions based on
 * fraud detection analysis from Stripe Radar and internal checks.
 */
class FraudAssessment
{
    public function __construct(
        public readonly string $riskLevel,
        public readonly array $signals,
        public readonly string $source,
        public readonly ?int $stripeRiskScore = null,
        public readonly bool $shouldBlock = false,
        public readonly bool $shouldReview = false,
    ) {
    }

    /**
     * Create a not-assessed result (fraud detection disabled).
     */
    public static function notAssessed(): self
    {
        return new self(
            riskLevel: 'not_assessed',
            signals: [],
            source: 'none',
            shouldBlock: false,
            shouldReview: false
        );
    }

    /**
     * Check if this is a high-risk assessment.
     */
    public function isHighRisk(): bool
    {
        return $this->riskLevel === 'highest' || $this->riskLevel === 'elevated';
    }

    /**
     * Check if fraud detection was performed.
     */
    public function wasAssessed(): bool
    {
        return $this->riskLevel !== 'not_assessed';
    }

    /**
     * Get a human-readable risk description.
     */
    public function getRiskDescription(): string
    {
        return match ($this->riskLevel) {
            'highest' => 'Very High Risk - Payment appears fraudulent',
            'elevated' => 'Elevated Risk - Payment requires review',
            'normal' => 'Normal Risk - Payment appears legitimate',
            'not_assessed' => 'Not Assessed - Fraud detection disabled',
            default => 'Unknown Risk Level',
        };
    }

    /**
     * Convert to array for storage/logging.
     */
    public function toArray(): array
    {
        return [
            'risk_level' => $this->riskLevel,
            'signals' => $this->signals,
            'source' => $this->source,
            'stripe_risk_score' => $this->stripeRiskScore,
            'should_block' => $this->shouldBlock,
            'should_review' => $this->shouldReview,
        ];
    }
}
