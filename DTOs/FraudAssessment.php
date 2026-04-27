<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\DTOs;

readonly class FraudAssessment
{
    /**
     * @param  array<int, string>  $reasons
     */
    public function __construct(
        public int $score,
        public string $riskLevel,
        public array $reasons,
        public bool $block,
    ) {}

    /**
     * @return array{score: int, risk_level: string, reasons: array<int, string>, block: bool}
     */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'risk_level' => $this->riskLevel,
            'reasons' => $this->reasons,
            'block' => $this->block,
        ];
    }
}
