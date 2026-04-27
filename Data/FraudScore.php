<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Data;

use InvalidArgumentException;

/**
 * Order-level fraud score for manual review and blocking decisions.
 */
readonly class FraudScore
{
    public function __construct(
        public int $score,
        public array $signals,
        public string $recommendation,
    ) {
        if ($this->score < 0 || $this->score > 100) {
            throw new InvalidArgumentException('Fraud score must be between 0 and 100.');
        }

        if (! in_array($this->recommendation, ['approve', 'review', 'block'], true)) {
            throw new InvalidArgumentException('Fraud recommendation must be approve, review, or block.');
        }
    }

    /**
     * @return array{score: int, signals: array, recommendation: string}
     */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'signals' => $this->signals,
            'recommendation' => $this->recommendation,
        ];
    }
}
