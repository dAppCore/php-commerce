<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Services;

use Core\Mod\Commerce\Data\ParsedItem;
use Core\Mod\Commerce\Data\SkuOption;
use Core\Mod\Commerce\Data\SkuParseResult;

/**
 * Build compound SKU strings from structured data.
 *
 * The inverse of SkuParserService - takes cart/order data and produces
 * the compound SKU string that encodes everything.
 *
 * One barcode = complete fulfillment knowledge.
 */
class SkuBuilderService
{
    /**
     * Build compound SKU from line items.
     *
     * @param  array<array{base_sku: string, options?: array, bundle_group?: string|int}>  $lineItems
     */
    public function build(array|string $lineItems, ?string $baseSku = null, array $options = []): string
    {
        if (is_string($lineItems)) {
            $prefix = strtoupper(trim($lineItems, '-'));
            $sku = strtoupper((string) $baseSku);

            foreach ($options as $key => $value) {
                if (is_array($value)) {
                    $key = $value['key'] ?? $value['code'] ?? $key;
                    $value = $value['value'] ?? '';
                }

                if ($value !== '') {
                    $sku .= '-'.strtoupper((string) $key).strtoupper((string) $value);
                }
            }

            return $prefix === '' ? $sku : "{$prefix}-{$sku}";
        }

        if (empty($lineItems)) {
            return '';
        }

        // Group items by bundle_group (null = standalone)
        $groups = [];
        $standalone = [];

        foreach ($lineItems as $item) {
            $bundleGroup = $item['bundle_group'] ?? null;

            if ($bundleGroup !== null) {
                $groups[$bundleGroup][] = $item;
            } else {
                $standalone[] = $item;
            }
        }

        $skuParts = [];

        // Build bundles (pipe-separated)
        foreach ($groups as $groupItems) {
            $bundleParts = [];
            foreach ($groupItems as $item) {
                $bundleParts[] = $this->buildItemSku($item);
            }
            $skuParts[] = implode('|', $bundleParts);
        }

        // Build standalone items
        foreach ($standalone as $item) {
            $skuParts[] = $this->buildItemSku($item);
        }

        // Comma-separate all parts
        return implode(',', $skuParts);
    }

    /**
     * Build SKU for a single item with options.
     *
     * @param  array{base_sku: string, options?: array}  $item
     */
    public function buildItemSku(array $item): string
    {
        $sku = strtoupper($item['base_sku']);

        foreach ($item['options'] ?? [] as $option) {
            $code = strtolower($option['code'] ?? $option[0] ?? '');
            $value = $option['value'] ?? $option[1] ?? '';
            $quantity = $option['quantity'] ?? $option[2] ?? 1;

            if ($code && $value) {
                $sku .= "-{$code}~{$value}";

                if ($quantity > 1) {
                    $sku .= "*{$quantity}";
                }
            }
        }

        return $sku;
    }

    /**
     * Build from ParsedItem objects.
     *
     * @param  array<ParsedItem>  $items
     */
    public function buildFromParsedItems(array $items, bool $asBundle = false): string
    {
        $skuParts = array_map(
            fn (ParsedItem $item) => $item->toString(),
            $items
        );

        return implode($asBundle ? '|' : ',', $skuParts);
    }

    /**
     * Build from SkuParseResult (round-trip).
     */
    public function buildFromResult(SkuParseResult $result): string
    {
        return $result->toString();
    }

    /**
     * Generate bundle hash for discount creation.
     *
     * @param  array<string>  $baseSkus  Base SKUs without options
     */
    public function generateBundleHash(array $baseSkus): string
    {
        $sorted = collect($baseSkus)
            ->map(fn (string $sku) => strtoupper($sku))
            ->sort()
            ->implode('|');

        return hash('sha256', $sorted);
    }

    /**
     * Add entity lineage prefix to a base SKU.
     *
     * @param  string  $baseSku  The product SKU
     * @param  array<string>  $entityCodes  Entity codes in order [M1, M2, M3...]
     */
    public function addLineage(string $baseSku, array $entityCodes): string
    {
        if (empty($entityCodes)) {
            return strtoupper($baseSku);
        }

        $prefix = implode('-', array_map('strtoupper', $entityCodes));

        return $prefix.'-'.strtoupper($baseSku);
    }

    /**
     * Build a complete compound SKU with entity lineage.
     *
     * @param  array<string>  $entityCodes  Entity codes [M1, M2, ...]
     * @param  array<array{base_sku: string, options?: array, bundle_group?: string|int}>  $lineItems
     */
    public function buildWithLineage(array $entityCodes, array $lineItems): string
    {
        // Add lineage to each item's base SKU
        $prefixedItems = array_map(function (array $item) use ($entityCodes) {
            $item['base_sku'] = $this->addLineage($item['base_sku'], $entityCodes);

            return $item;
        }, $lineItems);

        return $this->build($prefixedItems);
    }

    /**
     * Create a new option.
     */
    public function option(string $code, string $value, int $quantity = 1): SkuOption
    {
        return new SkuOption($code, $value, $quantity);
    }

    /**
     * Create a new parsed item.
     *
     * @param  array<SkuOption>  $options
     */
    public function item(string $baseSku, array $options = []): ParsedItem
    {
        return new ParsedItem($baseSku, $options);
    }
}
