<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Data;

/**
 * A single option on a SKU.
 *
 * Example: ram~16gb*2 becomes SkuOption('ram', '16gb', 2)
 */
readonly class SkuOption
{
    public function __construct(
        public string $code,
        public string $value,
        public int $quantity = 1,
    ) {
    }

    /**
     * Build the string representation.
     */
    public function toString(): string
    {
        $str = "{$this->code}~{$this->value}";

        if ($this->quantity > 1) {
            $str .= "*{$this->quantity}";
        }

        return $str;
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
