<?php

declare(strict_types=1);

namespace App\Services\General\PromotionEngine\DTOs;

final class GiftItem implements \ArrayAccess
{
    public function __construct(
        public readonly int $productId,
        public readonly ?int $productVariantId,
        public readonly ?array $productVariant,
        public readonly string $productName,
        public readonly string $productSku,
        public readonly ?string $productImage,
        public readonly int $quantity,
        /** price in cents (integer) */
        public readonly int $priceCents,
        public readonly bool $isGift = true,
    ) {}

    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'product_variant_id' => $this->productVariantId,
            'product_variant' => $this->productVariant,
            'product_name' => $this->productName,
            'product_sku' => $this->productSku,
            'product_image' => $this->productImage,
            'quantity' => $this->quantity,
            'price_cents' => $this->priceCents,
            'is_gift' => $this->isGift,
        ];
    }

    public function offsetExists($offset): bool
    {
        return array_key_exists($offset, $this->toArray());
    }

    public function offsetGet($offset): mixed
    {
        $arr = $this->toArray();
        return $arr[$offset] ?? null;
    }

    public function offsetSet($offset, $value): void
    {
        throw new \LogicException('GiftItem is immutable.');
    }

    public function offsetUnset($offset): void
    {
        throw new \LogicException('GiftItem is immutable.');
    }
}
