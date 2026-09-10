<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Database\Models\User;
use Marvel\Database\Models\Wishlist;

class WishlistSeeder extends Seeder
{
    public function run()
    {
        $customers = User::whereIn('email', ['test@g.com', 'admin@demo.com'])->get();
        if ($customers->isEmpty()) {
            return;
        }

        $productIds = Product::inRandomOrder()->take(10)->pluck('id');

        foreach ($customers as $customer) {
            $wishlistProducts = $productIds->random(min(5, $productIds->count()));

            foreach ($wishlistProducts as $productId) {
                $variant = ProductVariant::where('product_id', $productId)->first();

                Wishlist::firstOrCreate([
                    'user_id' => $customer->id,
                    'product_id' => $productId,
                ], [
                    'product_variant_id' => $variant?->id,
                ]);
            }
        }
    }
}
