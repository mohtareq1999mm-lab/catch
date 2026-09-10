<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Slider;

class SliderProductSeeder extends Seeder
{
    public function run(): void
    {
        $sliderIds = Slider::pluck('id')->toArray();
        $productIds = Product::pluck('id')->toArray();

        if (empty($sliderIds) || empty($productIds)) {
            return;
        }

        $pivotData = [];
        foreach ($productIds as $productId) {
            $count = rand(1, min(2, count($sliderIds)));
            $selected = (array) array_rand(array_flip($sliderIds), $count);
            foreach ($selected as $sliderId) {
                $pivotData[] = [
                    'slider_id' => $sliderId,
                    'product_id' => $productId,
                ];
            }
        }

        foreach (array_chunk($pivotData, 100) as $chunk) {
            DB::table('slider_product')->insertOrIgnore($chunk);
        }
    }
}
