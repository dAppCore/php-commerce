<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\Data;

/**
 * A parsed SKU item with its options.
 *
 * Example: LAPTOP-ram~16gb-ssd~512gb becomes ParsedItem('LAPTOP', [ram, ssd options])
 */
readonly class ParsedItem
{
    /**
     * @param  array<SkuOption>  $options
     */
    public function __construct(
        public string $baseSku,
        public array $options = [],
    ) {
    }

    /**
     * Build the string representation.
     */
    public function toString(): string
    {
        if (empty($this->options)) {
            return $this->baseSku;
        }

        $optionStrings = array_map(
            fn (SkuOption $opt) => $opt->toString(),
            $this->options
        );

        return $this->baseSku.'-'.implode('-', $optionStrings);
    }

    /**
     * Get option by code.
     */
    public function getOption(string $code): ?SkuOption
    {
        foreach ($this->options as $option) {
            if (strtolower($option->code) === strtolower($code)) {
                return $option;
            }
        }

        return null;
    }

    /**
     * Check if item has a specific option.
     */
    public function hasOption(string $code): bool
    {
        return $this->getOption($code) !== null;
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
