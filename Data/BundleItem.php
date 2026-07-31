<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Data;

/**
 * A bundle of items (pipe-separated in SKU string).
 *
 * Example: LAPTOP-ram~16gb|MOUSE|PAD becomes BundleItem([LAPTOP, MOUSE, PAD items], hash)
 *
 * The hash is computed from sorted base SKUs (stripping options) for discount lookup.
 */
readonly class BundleItem
{
    /**
     * @param  array<ParsedItem>  $items
     */
    public function __construct(
        public array $items,
        public string $hash,
    ) {
    }

    /**
     * Build the string representation (pipe-separated).
     */
    public function toString(): string
    {
        return implode('|', array_map(
            fn (ParsedItem $item) => $item->toString(),
            $this->items
        ));
    }

    /**
     * Get just the base SKUs (for display/debugging).
     */
    public function getBaseSkus(): array
    {
        return array_map(
            fn (ParsedItem $item) => $item->baseSku,
            $this->items
        );
    }

    /**
     * Get the sorted base SKU string (what the hash is computed from).
     */
    public function getBaseSkuString(): string
    {
        $skus = $this->getBaseSkus();
        sort($skus);

        return implode('|', $skus);
    }

    /**
     * Check if bundle contains a specific base SKU.
     */
    public function containsSku(string $baseSku): bool
    {
        return in_array(strtoupper($baseSku), array_map('strtoupper', $this->getBaseSkus()), true);
    }

    /**
     * Count of items in bundle.
     */
    public function count(): int
    {
        return count($this->items);
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
