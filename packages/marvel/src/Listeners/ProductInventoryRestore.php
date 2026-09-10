<?php

namespace Marvel\Listeners;

use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;

class ProductInventoryRestore implements ShouldQueue
{
    public $queue = 'meem-high';
    public function handle($event)
    {
        try {
            $orderItems = $event->order->orderItems;

            foreach ($orderItems as $item) {
                if ($item->is_gift) {
                    continue;
                }

                $product = Product::find($item->product_id);
                if ($product && !$product->is_rental) {
                    $product->stock_quantity = max(0, (int) $product->stock_quantity + (int) $item->product_quantity);
                    $product->sold_quantity = max(0, (int) $product->sold_quantity - (int) $item->product_quantity);
                    $product->save();
                }

                if ($item->product_variant_id) {
                    $variant = ProductVariant::find($item->product_variant_id);
                    if ($variant) {
                        $variant->stock_quantity = max(0, (int) $variant->stock_quantity + (int) $item->product_quantity);
                        $variant->sold_quantity = max(0, (int) $variant->sold_quantity - (int) $item->product_quantity);
                        $variant->save();
                    }
                }
            }
        } catch (Exception $th) {
            //
        }
    }
}
