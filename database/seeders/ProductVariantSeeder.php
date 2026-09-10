<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Marvel\Database\Models\Attribute;
use Marvel\Database\Models\AttributeProduct;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Enums\ProductType;
use Marvel\Services\Pricing\ProductPricingService;

class ProductVariantSeeder extends Seeder
{
    public function run(): void
    {
        $pricingService = app(ProductPricingService::class);
        $attributes = Attribute::with('values')->get();

        $colorValues = $this->valuesForAttribute($attributes, 'Color');
        $sizeValues = $this->valuesForAttribute($attributes, 'Size');

        $products = Product::where('product_type', ProductType::VARIABLE)
            ->where('stock_quantity', '>', 0)
            ->inRandomOrder()
            ->with('flash_sales')
            ->get();

        if ($products->isEmpty()) {
            $this->command?->warn('No variable-type products found. Skipping variant creation.');
            return;
        }

        $existingSkus = ProductVariant::pluck('sku')->filter()->values()->all();

        foreach ($products as $index => $product) {
            $basePrice = (float) $product->price;
            $productStock = (int) $product->stock_quantity;
            $flashSale = $product->has_flash_sale ? $pricingService->resolveActiveFlashSale($product) : null;

            $colorIdx = $colorValues->isNotEmpty() ? $index % $colorValues->count() : null;
            $sizeIdx = $sizeValues->isNotEmpty() ? $index % $sizeValues->count() : null;

            $colorCount = $colorValues->count();
            $sizeCount = $sizeValues->count();

            $combinations = [];
            for ($c = 0; $c < min(2, $colorCount); $c++) {
                for ($s = 0; $s < min(2, $sizeCount); $s++) {
                    $colorVal = $colorValues->get(($colorIdx + $c) % $colorCount);
                    $sizeVal = $sizeValues->get(($sizeIdx + $s) % $sizeCount);
                    $comboKey = ($colorVal?->id ?? '0') . '-' . ($sizeVal?->id ?? '0');

                    // Skip duplicate combinations
                    if (isset($combinations[$comboKey])) continue;

                    $combinations[$comboKey] = [
                        'price' => round($basePrice * (1 + $c * 0.1 + $s * 0.05), 2),
                        'stock_quantity' => max(2, (int) ($productStock * 0.25)),
                        'attributes' => array_values(array_filter([$colorVal, $sizeVal])),
                    ];
                }
            }

            $totalVariantStock = 0;
            foreach ($combinations as &$combo) {
                $totalVariantStock += $combo['stock_quantity'];
            }
            // Normalize stock so sum equals original product stock
            if ($totalVariantStock > 0) {
                foreach ($combinations as &$combo) {
                    $combo['stock_quantity'] = max(1, (int) round($combo['stock_quantity'] / $totalVariantStock * $productStock));
                }
            }
            unset($combo);

            $skuBase = $product->sku ? explode('-', $product->sku)[0] : 'VAR';

            foreach ($combinations as $combo) {
                $sku = $skuBase . '-VAR-' . strtoupper(Str::random(6));
                while (in_array($sku, $existingSkus)) {
                    $sku = $skuBase . '-VAR-' . strtoupper(Str::random(6));
                }
                $existingSkus[] = $sku;

                $variant = ProductVariant::create([
                    'sku' => $sku,
                    'price' => $combo['price'],
                    'sale_price' => $pricingService->calculateVariantSalePrice($product, $combo, $flashSale),
                    'stock_quantity' => $combo['stock_quantity'],
                    'quantity' => $combo['stock_quantity'],
                    'reserved_quantity' => 0,
                    'sold_quantity' => 0,
                    'height' => $product->height,
                    'width' => $product->width,
                    'length' => $product->length,
                    'weight' => $product->weight,
                    'product_id' => $product->id,
                    'in_stock' => true,
                ]);

                foreach ($combo['attributes'] as $attributeValue) {
                    AttributeProduct::create([
                        'product_variant_id' => $variant->id,
                        'attribute_value_id' => $attributeValue->id,
                    ]);
                }
            }

            if ($product->product_type !== ProductType::VARIABLE) {
                $product->update(['product_type' => ProductType::VARIABLE]);
            }
        }

        $this->command?->info('ProductVariantSeeder completed. Created variants for ' . $products->count() . ' products.');
    }

    private function valuesForAttribute($attributes, string $attributeName)
    {
        $attribute = $attributes->first(function ($item) use ($attributeName) {
            return strtolower((string) $item->getTranslation('name', 'en')) === strtolower($attributeName);
        });

        return $attribute ? collect($attribute->values) : collect();
    }
}
