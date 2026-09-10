<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Banner;
use Marvel\Database\Models\Product;

class BannerProductSeeder extends Seeder
{
    public function run(): void
    {
        $bannerIds = Banner::pluck('id')->toArray();
        $productIds = Product::pluck('id')->toArray();

        if (empty($bannerIds) || empty($productIds)) {
            return;
        }

        $pivotData = [];
        foreach ($productIds as $productId) {
            $count = rand(1, min(2, count($bannerIds)));
            $selected = (array) array_rand(array_flip($bannerIds), $count);
            foreach ($selected as $bannerId) {
                $pivotData[] = [
                    'banner_id' => $bannerId,
                    'product_id' => $productId,
                ];
            }
        }

        foreach (array_chunk($pivotData, 100) as $chunk) {
            DB::table('banner_product')->insertOrIgnore($chunk);
        }
    }
}
