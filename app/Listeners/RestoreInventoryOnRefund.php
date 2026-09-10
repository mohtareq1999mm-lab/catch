<?php

namespace App\Listeners;

use App\Events\RefundApproved;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;

class RestoreInventoryOnRefund implements ShouldQueue
{
    public $afterCommit = true;

    public $queue = 'meem-medium';

    public function handle(RefundApproved $event)
    {
        try {
            $order = $event->refund->order;
            if (!$order || $order->status === 'cancelled') {
                return;
            }

            DB::transaction(function () use ($order) {
                $updated = Order::whereKey($order->id)
                    ->whereNull('inventory_restored_at')
                    ->lockForUpdate()
                    ->update(['inventory_restored_at' => now()]);
                if ($updated === 0) {
                    return;
                }

                $orderItems = $order->orderItems;
                foreach ($orderItems as $item) {
                    if ($item->is_gift) {
                        continue;
                    }

                    if ($item->product_variant_id) {
                        $variant = ProductVariant::lockForUpdate()->find($item->product_variant_id);
                        if ($variant) {
                            $variant->stock_quantity = max(0, (int) $variant->stock_quantity + (int) $item->product_quantity);
                            $variant->sold_quantity = max(0, (int) $variant->sold_quantity - (int) $item->product_quantity);
                            $variant->save();
                        }
                    } else {
                        $product = Product::lockForUpdate()->find($item->product_id);
                        if ($product && !$product->is_rental) {
                            $product->stock_quantity = max(0, (int) $product->stock_quantity + (int) $item->product_quantity);
                            $product->sold_quantity = max(0, (int) $product->sold_quantity - (int) $item->product_quantity);
                            $product->save();
                        }
                    }
                }
            });
        } catch (Exception $th) {
            \Log::error('Error restoring inventory on refund: ' . $th->getMessage());
            throw $th;
        }
    }
}
