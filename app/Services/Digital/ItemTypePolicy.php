<?php

namespace App\Services\Digital;

use App\Models\DigitalAsset;
use Marvel\Database\Models\OrderProduct;
use Marvel\Database\Models\Product;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ItemTypePolicy
{
    /**
     * D5 — item_type immutability.
     * A product's item_type may never change once it has commercial history:
     * order items or digital assets pin the classification permanently.
     */
    public function assertChangeable(Product $product, ?string $newItemType): void
    {
        if ($newItemType === null || $newItemType === $product->item_type) {
            return;
        }

        $hasOrderItems = OrderProduct::where('product_id', $product->id)->exists();
        if ($hasOrderItems) {
            throw new HttpException(422, __('message.ERROR.ITEM_TYPE_IMMUTABLE_ORDERED'));
        }

        $hasAssets = DigitalAsset::where('product_id', $product->id)->exists();
        if ($hasAssets) {
            throw new HttpException(422, __('message.ERROR.ITEM_TYPE_IMMUTABLE_ASSETS'));
        }
    }
}
