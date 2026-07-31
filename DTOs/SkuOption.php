<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\DTOs;

readonly class SkuOption
{
    public function __construct(
        public string $key,
        public string $value,
        public int $position,
    ) {
    }

    /**
     * @return array{key: string, value: string, position: int}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'value' => $this->value,
            'position' => $this->position,
        ];
    }
}
