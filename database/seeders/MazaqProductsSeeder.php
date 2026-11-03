<?php

namespace Database\Seeders;

use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Models\ProductBarcode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Product;

/**
 * Seeder for Mazaq 150 (مذاق 150) Spices Catalog.
 *
 * Scope:
 * - Creates/ensures Category #5 exists (Mazaq Spices & Seasonings).
 * - Seeds 32 products with Arabic and English translatable names and descriptions.
 * - Associates piece and carton barcodes with unique integrity handling.
 * - Provisions dedicated WMS picking locations and initial available stock.
 *
 * Fully idempotent and production-defensive.
 */
class MazaqProductsSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('====================================================');
        $this->command?->info('Seeding Mazaq 150 (مذاق 150) Spices Dataset');
        $this->command?->info('====================================================');

        // 1. Ensure Category #5 exists
        $category = Category::firstOrCreate(
            ['id' => 5],
            [
                'name' => [
                    'ar' => 'بهارات وتوابل مذاق',
                    'en' => 'Mazaq Spices & Seasonings',
                ],
                'slug' => 'mazaq-spices-and-seasonings',
                'details' => [
                    'ar' => 'مجموعة بهارات وتوابل مذاق 150 جرام عالية الجودة',
                    'en' => 'High quality Mazaq 150g spices and seasonings collection',
                ],
                'status' => 1,
            ]
        );
        $this->command?->info("Target Category: [#{$category->id}] " . ($category->getTranslation('name', 'ar') ?? 'بهارات وتوابل مذاق'));

        // 2. Resolve default active warehouse for WMS placements
        $warehouse = Warehouse::where('is_default', true)->where('status', 'active')->first()
            ?? Warehouse::where('status', 'active')->first();

        // 3. Mazaq 150 dataset (32 items)
        $items = [
            ['sku' => '145401', 'name_ar' => 'بهارات بحريني ( مذاق 150 )', 'name_en' => 'Bahraini Spices ( Mazaq 150 )', 'piece' => '6287029972752', 'weight' => '150g', 'carton' => '6287029972769', 'pieces' => 24],
            ['sku' => '145402', 'name_ar' => 'بهارات الكبسة السعودية ( مذاق 150 )', 'name_en' => 'Saudi Kabsa Spices ( Mazaq 150 )', 'piece' => '6287029973063', 'weight' => '150g', 'carton' => '6287029973070', 'pieces' => 24],
            ['sku' => '145403', 'name_ar' => 'مسحوق الثوم ( مذاق 150 )', 'name_en' => 'Garlic Powder ( Mazaq 150 )', 'piece' => '6287029973025', 'weight' => '150g', 'carton' => '6287029973032', 'pieces' => 24],
            ['sku' => '145404', 'name_ar' => 'بهارات برياني ( مذاق 150 )', 'name_en' => 'Biryani Spices ( Mazaq 150 )', 'piece' => '6287029972790', 'weight' => '150g', 'carton' => '6287029973124', 'pieces' => 24],
            ['sku' => '145405', 'name_ar' => 'بهارات كاري ( مذاق 150 )', 'name_en' => 'Curry Powder ( Mazaq 150 )', 'piece' => '6287029972844', 'weight' => '150g', 'carton' => '6287029972851', 'pieces' => 24],
            ['sku' => '145406', 'name_ar' => 'مسحوق الكركم ( مذاق 150 )', 'name_en' => 'Turmeric Powder ( Mazaq 150 )', 'piece' => '6287029972929', 'weight' => '150g', 'carton' => '6287029972936', 'pieces' => 24],
            ['sku' => '145407', 'name_ar' => 'كمون مطحون ( مذاق 150 )', 'name_en' => 'Ground Cumin ( Mazaq 150 )', 'piece' => '6287029972967', 'weight' => '150g', 'carton' => '6287029972974', 'pieces' => 24],
            ['sku' => '145408', 'name_ar' => 'فلفل اسود مطحون ( مذاق 150 )', 'name_en' => 'Ground Black Pepper ( Mazaq 150 )', 'piece' => '6287029973001', 'weight' => '150g', 'carton' => '6287029973018', 'pieces' => 24],
            ['sku' => '145409', 'name_ar' => 'زنجبيل مطحون ( مذاق 150 )', 'name_en' => 'Ground Ginger ( Mazaq 150 )', 'piece' => '6287029972738', 'weight' => '150g', 'carton' => '6287029972745', 'pieces' => 24],
            ['sku' => '145410', 'name_ar' => 'بهارات اللحم ( مذاق 150 )', 'name_en' => 'Meat Spices ( Mazaq 150 )', 'piece' => '6287029973100', 'weight' => '150g', 'carton' => '6287029973117', 'pieces' => 24],
            ['sku' => '145411', 'name_ar' => 'بهارات الدجاج ( مذاق 150 )', 'name_en' => 'Chicken Spices ( Mazaq 150 )', 'piece' => '6287029972776', 'weight' => '150g', 'carton' => '6287029972783', 'pieces' => 24],
            ['sku' => '145412', 'name_ar' => 'ليمون اسود مطحون ( مذاق 150 )', 'name_en' => 'Ground Black Lemon ( Mazaq 150 )', 'piece' => '6287029973049', 'weight' => '150g', 'carton' => '6287029973056', 'pieces' => 24],
            ['sku' => '145413', 'name_ar' => 'كزبرة مطحون ( مذاق 150 )', 'name_en' => 'Ground Coriander ( Mazaq 150 )', 'piece' => '6287029972905', 'weight' => '150g', 'carton' => '6287029972912', 'pieces' => 24],
            ['sku' => '145414', 'name_ar' => 'بهارات السبعة ( مذاق 150 )', 'name_en' => 'Seven Spices ( Mazaq 150 )', 'piece' => '6287029972820', 'weight' => '150g', 'carton' => '6287029972837', 'pieces' => 24],
            ['sku' => '145415', 'name_ar' => 'توابل السلطة ( مذاق 150 )', 'name_en' => 'Salad Seasoning ( Mazaq 150 )', 'piece' => '6287029972882', 'weight' => '150g', 'carton' => '6287029972899', 'pieces' => 24],
            ['sku' => '145416', 'name_ar' => 'بهارات الشوي ( مذاق 150 )', 'name_en' => 'BBQ Seasoning ( Mazaq 150 )', 'piece' => '6287029972868', 'weight' => '150g', 'carton' => '6287029972875', 'pieces' => 24],
            ['sku' => '145417', 'name_ar' => 'بهارات بيضاء ( مذاق 150 )', 'name_en' => 'White Spices ( Mazaq 150 )', 'piece' => '6287029972806', 'weight' => '150g', 'carton' => '6287029972813', 'pieces' => 24],
            ['sku' => '145418', 'name_ar' => 'بهارات إيطالي ( مذاق 150 )', 'name_en' => 'Italian Herbs ( Mazaq 150 )', 'piece' => '6287029973087', 'weight' => '150g', 'carton' => '6287029973094', 'pieces' => 24],
            ['sku' => '145419', 'name_ar' => 'بهارات مضغوط ( مذاق 150 )', 'name_en' => 'Madghout Spices ( Mazaq 150 )', 'piece' => '6287029972691', 'weight' => '150g', 'carton' => '6287029972707', 'pieces' => 24],
            ['sku' => '145420', 'name_ar' => 'بهارات مسالا ( مذاق 150 )', 'name_en' => 'Garam Masala Spices ( Mazaq 150 )', 'piece' => '6287029972981', 'weight' => '150g', 'carton' => '6287029972998', 'pieces' => 24],
            ['sku' => '145421', 'name_ar' => 'كربونات الصوديوم ( مذاق 150 )', 'name_en' => 'Sodium Bicarbonate ( Mazaq 150 )', 'piece' => '6287029972714', 'weight' => '150g', 'carton' => '6287029972721', 'pieces' => 24],
            ['sku' => '145422', 'name_ar' => 'بهارات البروستد الحراق ( مذاق 150 )', 'name_en' => 'Spicy Broasted Seasoning ( Mazaq 150 )', 'piece' => '6287029972943', 'weight' => '150g', 'carton' => '6287029972950', 'pieces' => 24],
            ['sku' => '145423', 'name_ar' => 'سماق ( مذاق 150 )', 'name_en' => 'Sumac ( Mazaq 150 )', 'piece' => '6287029973131', 'weight' => '100g', 'carton' => '6287029973148', 'pieces' => 24],
            ['sku' => '145424', 'name_ar' => 'بهارات الشوربة ( مذاق 150 )', 'name_en' => 'Soup Spices ( Mazaq 150 )', 'piece' => '6287029973155', 'weight' => '150g', 'carton' => '6287029973162', 'pieces' => 24],
            ['sku' => '145425', 'name_ar' => 'حبة البركة ( مذاق 150 )', 'name_en' => 'Black Seed ( Mazaq 150 )', 'piece' => '6287029973230', 'weight' => '150g', 'carton' => '6287029973247', 'pieces' => 24],
            ['sku' => '145426', 'name_ar' => 'سمسم محمص ( مذاق 150 )', 'name_en' => 'Roasted Sesame ( Mazaq 150 )', 'piece' => '6287029973193', 'weight' => '150g', 'carton' => '6287029973209', 'pieces' => 24],
            ['sku' => '145427', 'name_ar' => 'زعتر ( مذاق 150 )', 'name_en' => 'Zaatar / Thyme ( Mazaq 150 )', 'piece' => '6287029973254', 'weight' => '150g', 'carton' => '6287029973261', 'pieces' => 24],
            ['sku' => '145428', 'name_ar' => 'بابريكا حلو مجروش ( مذاق 150 )', 'name_en' => 'Crushed Sweet Paprika ( Mazaq 150 )', 'piece' => '6287029973278', 'weight' => '150g', 'carton' => '6287029973285', 'pieces' => 24],
            ['sku' => '145429', 'name_ar' => 'بصل مجروش ( مذاق 150 )', 'name_en' => 'Minced Onion ( Mazaq 150 )', 'piece' => '6287029973292', 'weight' => '150g', 'carton' => '6287029973308', 'pieces' => 24],
            ['sku' => '145430', 'name_ar' => 'قرفة مطحون ( مذاق 150 )', 'name_en' => 'Ground Cinnamon ( Mazaq 150 )', 'piece' => '6287029973179', 'weight' => '150g', 'carton' => '6287029973186', 'pieces' => 24],
            ['sku' => '145431', 'name_ar' => 'مسحوق شطة حار ( مذاق 150 )', 'name_en' => 'Hot Chili Powder ( Mazaq 150 )', 'piece' => '6287029973216', 'weight' => '150g', 'carton' => '6287029973223', 'pieces' => 24],
            ['sku' => '145432', 'name_ar' => 'مسحوق بابريكا حلو ( مذاق 150 )', 'name_en' => 'Sweet Paprika Powder ( Mazaq 150 )', 'piece' => '6287029973315', 'weight' => '150g', 'carton' => '6287029973322', 'pieces' => 24],
        ];

        $createdCount = 0;
        $barcodesCount = 0;
        $locationsCount = 0;

        DB::transaction(function () use ($items, $category, $warehouse, &$createdCount, &$barcodesCount, &$locationsCount) {
            foreach ($items as $item) {
                // 1. Create or update product
                $product = Product::updateOrCreate(
                    ['sku' => $item['sku']],
                    [
                        'name' => [
                            'ar' => $item['name_ar'],
                            'en' => $item['name_en'],
                        ],
                        'description' => [
                            'ar' => $item['name_ar'],
                            'en' => $item['name_en'],
                        ],
                        'slug' => Str::slug($item['name_en'] . '-' . $item['sku']),
                        'price' => 12.00,
                        'quantity' => 100,
                        'stock_quantity' => 100,
                        'in_stock' => 1,
                        'status' => 1,
                        'product_type' => 'simple',
                        'item_type' => 'PHYSICAL',
                        'weight' => $item['weight'],
                    ]
                );
                $createdCount++;

                // 2. Attach Category #5
                $product->categories()->syncWithoutDetaching([$category->id]);

                // 3. Attach Barcodes (if specified) - Clean conflicting/stale barcode rows to prevent 1062 duplicates
                if (!empty($item['piece'])) {
                    ProductBarcode::where('product_id', $product->id)
                        ->orWhere('piece_barcode', $item['piece'])
                        ->when(!empty($item['carton']), fn ($q) => $q->orWhere('carton_barcode', $item['carton']))
                        ->delete();

                    ProductBarcode::create([
                        'product_id' => $product->id,
                        'piece_barcode' => $item['piece'],
                        'carton_barcode' => $item['carton'] ?? null,
                        'pieces_per_carton' => $item['pieces'] ?? 24,
                    ]);
                    $barcodesCount++;
                }

                // 4. Provision WMS Picking Location & Stock Placement (if warehouse exists)
                if ($warehouse) {
                    $locCode = 'PICK-' . $item['sku'];
                    $locBarcode = 'LOC-PICK-' . $item['sku'];

                    $location = Location::firstOrCreate(
                        [
                            'warehouse_id' => $warehouse->id,
                            'code' => $locCode,
                        ],
                        [
                            'barcode' => $locBarcode,
                            'name' => 'موقع التقاط - ' . $item['name_ar'],
                            'type' => Location::TYPE_PICKING,
                            'status' => Location::STATUS_ACTIVE,
                            'priority' => 15,
                        ]
                    );
                    $locationsCount++;

                    ProductLocation::updateOrCreate(
                        [
                            'warehouse_id' => $warehouse->id,
                            'product_id' => $product->id,
                            'location_id' => $location->id,
                            'product_variant_id' => null,
                        ],
                        [
                            'quantity' => 100.00,
                            'allocated_hint' => 0.00,
                        ]
                    );
                }
            }
        });

        $this->command?->info("✓ Successfully seeded {$createdCount} products.");
        $this->command?->info("✓ Attached to Category #{$category->id}.");
        $this->command?->info("✓ Barcodes recorded in product_barcodes: {$barcodesCount}.");
        if ($warehouse) {
            $this->command?->info("✓ WMS picking locations and stock placements created: {$locationsCount}.");
        }
        $this->command?->info('====================================================');
    }
}

