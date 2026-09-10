<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\Product;

class BrandProductSeeder extends Seeder
{
    public function run(): void
    {
        $brandCategoryMap = [
            'Apple' => ['FAC', 'EYE', 'LIP'],
            'Samsung' => ['FAC', 'EYE', 'SKN'],
            'Sony' => ['EYE', 'ACC', 'TLS'],
            'LG' => ['SKN', 'ACC'],
            'Nike' => ['CHK', 'TLS', 'ACC'],
            'Adidas' => ['CHK', 'TLS', 'ACC'],
            'Puma' => ['LIP', 'CHK', 'TLS'],
            'Zara' => ['FAC', 'LIP', 'ACC'],
            'H&M' => ['FAC', 'LIP', 'CHK'],
            'IKEA' => ['ACC', 'TLS'],
            'Philips' => ['SKN', 'TLS', 'ACC'],
            'L\'Oréal' => ['FAC', 'EYE', 'LIP', 'SKN'],
            'Nivea' => ['SKN', 'LIP'],
            'Dove' => ['SKN', 'LIP'],
            'Pepsi' => ['SKN'],
            'Coca-Cola' => ['ACC'],
            'Nestlé' => ['SKN'],
            'Pantene' => ['SKN'],
            'Colgate' => ['SKN', 'LIP'],
            'Pampers' => ['SKN', 'ACC'],
        ];

        foreach ($brandCategoryMap as $brandName => $prefixes) {
            $brand = Brand::where('name->en', $brandName)->first();
            if (!$brand) {
                continue;
            }

            $productIds = [];
            foreach ($prefixes as $prefix) {
                $ids = Product::where('sku', 'LIKE', $prefix . '-%')->pluck('id')->toArray();
                $productIds = array_merge($productIds, $ids);
            }

            $productIds = array_unique($productIds);

            if (!empty($productIds)) {
                $brand->products()->syncWithoutDetaching($productIds);
            }
        }

        // Assign remaining unassigned brands to random products
        $allBrands = Brand::all();
        $allProductIds = Product::pluck('id');

        foreach ($allBrands as $brand) {
            if ($brand->products()->count() > 0) {
                continue;
            }

            $maxAttach = min(8, $allProductIds->count());
            $minAttach = min(3, $maxAttach);
            $attachCount = random_int($minAttach, $maxAttach);

            $selected = $allProductIds->random($attachCount)->all();
            $brand->products()->syncWithoutDetaching($selected);
        }
    }
}
