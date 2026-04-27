<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\DTOs;

readonly class SkuParseResult
{
    /**
     * @param  array<int, SkuOption>  $options
     */
    public function __construct(
        public string $baseSku,
        public array $options,
        public string $entityPrefix,
        public bool $valid,
    ) {}

    /**
     * @return array{base_sku: string, options: array<int, array{key: string, value: string, position: int}>, entity_prefix: string, valid: bool}
     */
    public function toArray(): array
    {
        return [
            'base_sku' => $this->baseSku,
            'options' => array_map(
                fn (SkuOption $option): array => $option->toArray(),
                $this->options
            ),
            'entity_prefix' => $this->entityPrefix,
            'valid' => $this->valid,
        ];
    }
}
