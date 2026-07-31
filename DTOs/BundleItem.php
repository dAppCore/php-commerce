<?php

declare(strict_types=1);

namespace Core\Mod\Commerce\DTOs;

readonly class BundleItem
{
    public function __construct(
        public int $productId,
        public int $quantity,
        public ?float $priceOverride = null,
    ) {
    }

    /**
     * @return array{product_id: int, quantity: int, price_override: float|null}
     */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'quantity' => $this->quantity,
            'price_override' => $this->priceOverride,
        ];
    }
}
