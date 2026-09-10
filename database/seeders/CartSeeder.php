<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;

class CartSeeder extends Seeder
{
    public function run()
    {
        $customer = User::where('email', 'test@g.com')->first();
        if (!$customer) {
            return;
        }

        $products = Product::inRandomOrder()->take(3)->get();

        if ($products->isEmpty()) {
            return;
        }

        Cart::where('user_id', $customer->id)->delete();

        $cart = Cart::create([
            'user_id' => $customer->id,
            'status' => 'active',
            'reserved_at' => now(),
            'expires_at' => now()->addDays(3),
            'total_price' => 0,
        ]);

        foreach ($products as $product) {
            $quantity = random_int(1, 2);
            $price = $product->getCurrentPrice();

            CartItem::create([
                'cart_id' => $cart->id,
                'product_id' => $product->id,
                'quantity' => $quantity,
                'reserved_quantity' => $quantity,
                'price' => $price,
                'total_price' => $price * $quantity,
                'shipping_method' => random_int(0, 1) ? 'FAST' : 'SCHEDULED',
            ]);
        }

        $cart->update([
            'total_price' => $cart->items()->sum('total_price'),
        ]);
    }
}
