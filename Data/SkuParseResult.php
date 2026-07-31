<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Data;

use Illuminate\Support\Collection;

/**
 * Result of parsing a compound SKU string.
 *
 * Contains a mix of ParsedItem (single items) and BundleItem (pipe-grouped items).
 *
 * Example input: "LAPTOP-ram~16gb|MOUSE,HDMI-length~2m"
 * Results in: [BundleItem(LAPTOP, MOUSE), ParsedItem(HDMI)]
 */
readonly class SkuParseResult
{
    /**
     * @param  array<ParsedItem|BundleItem>  $items
     */
    public function __construct(
        public array $items,
    ) {
    }

    /**
     * Get all items as a collection.
     *
     * @return Collection<ParsedItem|BundleItem>
     */
    public function collect(): Collection
    {
        return collect($this->items);
    }

    /**
     * Get only single items (not bundles).
     *
     * @return Collection<ParsedItem>
     */
    public function singles(): Collection
    {
        return $this->collect()->filter(
            fn ($item) => $item instanceof ParsedItem
        );
    }

    /**
     * Get only bundles.
     *
     * @return Collection<BundleItem>
     */
    public function bundles(): Collection
    {
        return $this->collect()->filter(
            fn ($item) => $item instanceof BundleItem
        );
    }

    /**
     * Check if result contains any bundles.
     */
    public function hasBundles(): bool
    {
        return $this->bundles()->isNotEmpty();
    }

    /**
     * Get all base SKUs (flattened from items and bundles).
     */
    public function getAllBaseSkus(): array
    {
        $skus = [];

        foreach ($this->items as $item) {
            if ($item instanceof BundleItem) {
                $skus = array_merge($skus, $item->getBaseSkus());
            } else {
                $skus[] = $item->baseSku;
            }
        }

        return $skus;
    }

    /**
     * Get all bundle hashes (for discount lookup).
     */
    public function getBundleHashes(): array
    {
        return $this->bundles()
            ->map(fn (BundleItem $bundle) => $bundle->hash)
            ->values()
            ->all();
    }

    /**
     * Total count of line items (bundles count as 1).
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Total count of individual products (bundle items expanded).
     */
    public function productCount(): int
    {
        $count = 0;

        foreach ($this->items as $item) {
            if ($item instanceof BundleItem) {
                $count += $item->count();
            } else {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Rebuild the compound SKU string.
     */
    public function toString(): string
    {
        return implode(',', array_map(
            fn ($item) => $item->toString(),
            $this->items
        ));
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
