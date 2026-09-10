<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\Product;

class CouponProductSeeder extends Seeder
{
    public function run(): void
    {
        $couponIds = Coupon::pluck('id')->toArray();
        $productIds = Product::pluck('id')->toArray();

        if (empty($couponIds) || empty($productIds)) {
            return;
        }

        $pivotData = [];
        foreach ($couponIds as $couponId) {
            $productSubset = (array) array_rand(array_flip($productIds), min(rand(5, 30), count($productIds)));
            foreach ($productSubset as $productId) {
                $pivotData[] = [
                    'coupon_id' => $couponId,
                    'product_id' => $productId,
                ];
            }
        }

        foreach (array_chunk($pivotData, 100) as $chunk) {
            DB::table('coupon_product')->insertOrIgnore($chunk);
        }
    }
}
