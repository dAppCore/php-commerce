<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\DTOs;

readonly class ParsedItem
{
    public function __construct(
        public string $segment,
        public string $type,
        public string $value,
    ) {}

    /**
     * @return array{segment: string, type: string, value: string}
     */
    public function toArray(): array
    {
        return [
            'segment' => $this->segment,
            'type' => $this->type,
            'value' => $this->value,
        ];
    }
}
