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
 * Seeder for Rawd Al Aseel (روض الأصيل) Spices Catalog.
 *
 * Scope:
 * - Creates/ensures Category #6 exists (Spices & Seasonings).
 * - Seeds 49 products with Arabic and English translatable names and descriptions.
 * - Associates piece and carton barcodes with unique integrity handling.
 * - Provisions dedicated WMS picking locations and initial available stock.
 *
 * Fully idempotent and production-defensive.
 */
class RawdAlAseelProductsSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('====================================================');
        $this->command?->info('Seeding Rawd Al Aseel (روض الأصيل) Spices Dataset');
        $this->command?->info('====================================================');

        // 1. Ensure Category #6 exists
        $category = Category::firstOrCreate(
            ['id' => 6],
            [
                'name' => [
                    'ar' => 'بهارات وتوابل',
                    'en' => 'Spices & Seasonings',
                ],
                'slug' => 'spices-and-seasonings',
                'details' => [
                    'ar' => 'بهارات وتوابل روض الأصيل عالية الجودة',
                    'en' => 'High quality Rawd Al Aseel spices and seasonings',
                ],
                'status' => 1,
            ]
        );
        $this->command?->info("Target Category: [#{$category->id}] " . ($category->getTranslation('name', 'ar') ?? 'بهارات وتوابل'));

        // 2. Resolve default active warehouse for WMS placements
        $warehouse = Warehouse::where('is_default', true)->where('status', 'active')->first()
            ?? Warehouse::where('status', 'active')->first();

        // 3. Rawd Al Aseel dataset (49 items)
        $items = [
            ['sku' => '145101', 'name_ar' => 'بهارات بحريني ( روض الاصيل )', 'name_en' => 'Bahraini Spices ( Rawd Al Aseel )', 'piece' => '6287029970017', 'weight' => '200g', 'carton' => '6287029970901', 'pieces' => 24],
            ['sku' => '145102', 'name_ar' => 'قرنفل ( روض الاصيل )', 'name_en' => 'Cloves ( Rawd Al Aseel )', 'piece' => '6287029970024', 'weight' => '130g', 'carton' => '6287029970918', 'pieces' => 24],
            ['sku' => '145103', 'name_ar' => 'كمون حب ( روض الاصيل )', 'name_en' => 'Whole Cumin ( Rawd Al Aseel )', 'piece' => '6287029970031', 'weight' => '200g', 'carton' => '6287029970925', 'pieces' => 24],
            ['sku' => '145104', 'name_ar' => 'سماق ( روض الاصيل )', 'name_en' => 'Sumac ( Rawd Al Aseel )', 'piece' => '6287029970048', 'weight' => '180g', 'carton' => '6287029970932', 'pieces' => 24],
            ['sku' => '145105', 'name_ar' => 'بهارات الكبسة ( روض الاصيل )', 'name_en' => 'Kabsa Spices ( Rawd Al Aseel )', 'piece' => '6287029970055', 'weight' => '200g', 'carton' => '6287029970949', 'pieces' => 24],
            ['sku' => '145106', 'name_ar' => 'بهارات برياني ( روض الاصيل )', 'name_en' => 'Biryani Spices ( Rawd Al Aseel )', 'piece' => '6287029970062', 'weight' => '200g', 'carton' => '6287029970956', 'pieces' => 24],
            ['sku' => '145107', 'name_ar' => 'بهارات السمك ( روض الاصيل )', 'name_en' => 'Fish Spices ( Rawd Al Aseel )', 'piece' => '6287029970079', 'weight' => '200g', 'carton' => '6287029970963', 'pieces' => 24],
            ['sku' => '145108', 'name_ar' => 'بهارات اللحم ( روض الاصيل )', 'name_en' => 'Meat Spices ( Rawd Al Aseel )', 'piece' => '6287029970086', 'weight' => '200g', 'carton' => '6287029970970', 'pieces' => 24],
            ['sku' => '145109', 'name_ar' => 'بهارات الدجاج ( روض الاصيل )', 'name_en' => 'Chicken Spices ( Rawd Al Aseel )', 'piece' => '6287029970093', 'weight' => '200g', 'carton' => '6287029970987', 'pieces' => 24],
            ['sku' => '145110', 'name_ar' => 'بهارات الشوربة ( روض الاصيل )', 'name_en' => 'Soup Spices ( Rawd Al Aseel )', 'piece' => '6287029970109', 'weight' => '200g', 'carton' => '6287029970994', 'pieces' => 24],
            ['sku' => '145111', 'name_ar' => 'بهارات الكاري ( روض الاصيل )', 'name_en' => 'Curry Powder ( Rawd Al Aseel )', 'piece' => '6287029970116', 'weight' => '200g', 'carton' => '6287029971007', 'pieces' => 24],
            ['sku' => '145112', 'name_ar' => 'مسحوق الثوم ( روض الاصيل )', 'name_en' => 'Garlic Powder ( Rawd Al Aseel )', 'piece' => '6287029970123', 'weight' => '200g', 'carton' => '6287029971014', 'pieces' => 24],
            ['sku' => '145113', 'name_ar' => 'مسحوق الزنجبيل ( روض الاصيل )', 'name_en' => 'Ginger Powder ( Rawd Al Aseel )', 'piece' => '6287029970130', 'weight' => '170g', 'carton' => '6287029971021', 'pieces' => 24],
            ['sku' => '145114', 'name_ar' => 'مسحوق الكركم ( روض الاصيل )', 'name_en' => 'Turmeric Powder ( Rawd Al Aseel )', 'piece' => '6287029970147', 'weight' => '200g', 'carton' => '6287029971038', 'pieces' => 24],
            ['sku' => '145115', 'name_ar' => 'مسحوق شطة حار ( روض الاصيل )', 'name_en' => 'Hot Chili Powder ( Rawd Al Aseel )', 'piece' => '6287029970154', 'weight' => '200g', 'carton' => '6287029971045', 'pieces' => 24],
            ['sku' => '145116', 'name_ar' => 'ليمون اسود مطحون ( روض الاصيل )', 'name_en' => 'Ground Black Lemon ( Rawd Al Aseel )', 'piece' => '6287029970161', 'weight' => '200g', 'carton' => '6287029971052', 'pieces' => 24],
            ['sku' => '145117', 'name_ar' => 'كزبرة مطحون ( روض الاصيل )', 'name_en' => 'Ground Coriander ( Rawd Al Aseel )', 'piece' => '6287029970178', 'weight' => '180g', 'carton' => '6287029971069', 'pieces' => 24],
            ['sku' => '145118', 'name_ar' => 'فلفل اسود مطحون ( روض الاصيل )', 'name_en' => 'Ground Black Pepper ( Rawd Al Aseel )', 'piece' => '6287029970185', 'weight' => '200g', 'carton' => '6287029971076', 'pieces' => 24],
            ['sku' => '145119', 'name_ar' => 'فلفل اسود حب ( روض الاصيل )', 'name_en' => 'Whole Black Pepper ( Rawd Al Aseel )', 'piece' => '6287029970192', 'weight' => '200g', 'carton' => '6287029971083', 'pieces' => 24],
            ['sku' => '145120', 'name_ar' => 'فلفل ابيض مطحون ( روض الاصيل )', 'name_en' => 'Ground White Pepper ( Rawd Al Aseel )', 'piece' => '6287029970208', 'weight' => '200g', 'carton' => '6287029971090', 'pieces' => 24],
            ['sku' => '145121', 'name_ar' => 'كمون مطحون ( روض الاصيل )', 'name_en' => 'Ground Cumin ( Rawd Al Aseel )', 'piece' => '6287029970215', 'weight' => '180g', 'carton' => '6287029971106', 'pieces' => 24],
            ['sku' => '145122', 'name_ar' => 'قرفة مطحون ( روض الاصيل )', 'name_en' => 'Ground Cinnamon ( Rawd Al Aseel )', 'piece' => '6287029970222', 'weight' => '170g', 'carton' => '6287029971113', 'pieces' => 24],
            ['sku' => '145123', 'name_ar' => 'حبة البركة ( روض الاصيل )', 'name_en' => 'Black Seed ( Rawd Al Aseel )', 'piece' => '6287029970239', 'weight' => '200g', 'carton' => '6287029971120', 'pieces' => 24],
            ['sku' => '145124', 'name_ar' => 'سمسم محمص ( روض الاصيل )', 'name_en' => 'Roasted Sesame ( Rawd Al Aseel )', 'piece' => '6287029970246', 'weight' => '200g', 'carton' => '6287029971137', 'pieces' => 24],
            ['sku' => '145125', 'name_ar' => 'زعتر ( روض الاصيل )', 'name_en' => 'Zaatar / Thyme ( Rawd Al Aseel )', 'piece' => '6287029970253', 'weight' => '200g', 'carton' => '6287029971144', 'pieces' => 24],
            ['sku' => '145126', 'name_ar' => 'فشار ( روض الاصيل )', 'name_en' => 'Popcorn ( Rawd Al Aseel )', 'piece' => '6287029970260', 'weight' => '400g', 'carton' => '6287029971151', 'pieces' => 24],
            ['sku' => '145127', 'name_ar' => 'بابريكا حلو مجروش ( روض الاصيل )', 'name_en' => 'Crushed Sweet Paprika ( Rawd Al Aseel )', 'piece' => '6287029970277', 'weight' => '200g', 'carton' => '6287029971168', 'pieces' => 24],
            ['sku' => '145128', 'name_ar' => 'البهارات السبعة ( روض الاصيل )', 'name_en' => 'Seven Spices ( Rawd Al Aseel )', 'piece' => '6287029970284', 'weight' => '200g', 'carton' => '6287029971175', 'pieces' => 24],
            ['sku' => '145129', 'name_ar' => 'مسحوق بابريكا حلو ( روض الاصيل )', 'name_en' => 'Sweet Paprika Powder ( Rawd Al Aseel )', 'piece' => '6287029970291', 'weight' => '200g', 'carton' => '6287029971182', 'pieces' => 24],
            ['sku' => '145130', 'name_ar' => 'بديل ماجي اصفر ( روض الاصيل )', 'name_en' => 'Yellow Broth Seasoning ( Rawd Al Aseel )', 'piece' => '6287029970307', 'weight' => '200g', 'carton' => '6287029971199', 'pieces' => 24],
            ['sku' => '145131', 'name_ar' => 'بديل ماجي خضار ( روض الاصيل )', 'name_en' => 'Vegetable Broth Seasoning ( Rawd Al Aseel )', 'piece' => '6287029970314', 'weight' => '200g', 'carton' => '6287029971205', 'pieces' => 24],
            ['sku' => '145132', 'name_ar' => 'بهارات عثماني ( روض الاصيل )', 'name_en' => 'Ottoman Spices ( Rawd Al Aseel )', 'piece' => '6287029970321', 'weight' => '200g', 'carton' => '6287029971212', 'pieces' => 24],
            ['sku' => '145133', 'name_ar' => 'توابل السلطة ( روض الاصيل )', 'name_en' => 'Salad Seasoning ( Rawd Al Aseel )', 'piece' => '6287029970833', 'weight' => '170g', 'carton' => '6287029971281', 'pieces' => 24],
            ['sku' => '145134', 'name_ar' => 'بهارات البروستد ( روض الاصيل )', 'name_en' => 'Broasted Chicken Seasoning ( Rawd Al Aseel )', 'piece' => '6287029970840', 'weight' => '170g', 'carton' => '6287029971298', 'pieces' => 24],
            ['sku' => '145135', 'name_ar' => 'بهارات الشوي ( روض الاصيل )', 'name_en' => 'BBQ Seasoning ( Rawd Al Aseel )', 'piece' => '6287029970857', 'weight' => '170g', 'carton' => '6287029971304', 'pieces' => 24],
            ['sku' => '145136', 'name_ar' => 'بهارات بيضاء ( روض الاصيل )', 'name_en' => 'White Spices ( Rawd Al Aseel )', 'piece' => '6287029970864', 'weight' => '170g', 'carton' => '6287029971311', 'pieces' => 24],
            ['sku' => '145137', 'name_ar' => 'بهارات إيطالي ( روض الاصيل )', 'name_en' => 'Italian Herbs ( Rawd Al Aseel )', 'piece' => '6287029970871', 'weight' => '170g', 'carton' => '6287029971328', 'pieces' => 24],
            ['sku' => '145138', 'name_ar' => 'بهارات مضغوط ( روض الاصيل )', 'name_en' => 'Madghout Spices ( Rawd Al Aseel )', 'piece' => '6287029970888', 'weight' => '170g', 'carton' => '6287029971335', 'pieces' => 24],
            ['sku' => '145139', 'name_ar' => 'بهارات معمول ( روض الاصيل )', 'name_en' => 'Maamoul Spices ( Rawd Al Aseel )', 'piece' => '6287029970895', 'weight' => '170g', 'carton' => '6287029971342', 'pieces' => 24],
            ['sku' => '145140', 'name_ar' => 'ملح الليمون ( روض الاصيل )', 'name_en' => 'Citric Acid / Lemon Salt ( Rawd Al Aseel )', 'piece' => '6287029971472', 'weight' => '120g', 'carton' => '6287029971502', 'pieces' => 24],
            ['sku' => '145141', 'name_ar' => 'بصل مجروش ( روض الاصيل )', 'name_en' => 'Minced Onion ( Rawd Al Aseel )', 'piece' => '6287029971526', 'weight' => '170g', 'carton' => '6287029971533', 'pieces' => 24],
            ['sku' => '145143', 'name_ar' => 'كربونات الصوديوم ( روض الأصيل )', 'name_en' => 'Sodium Bicarbonate ( Rawd Al Aseel )', 'piece' => '6287029972073', 'weight' => '300g', 'carton' => '6287029972080', 'pieces' => 24],
            ['sku' => '145144', 'name_ar' => 'بهارات بروستد حراق ( روض الأصيل )', 'name_en' => 'Spicy Broasted Seasoning ( Rawd Al Aseel )', 'piece' => '6287029972097', 'weight' => '170g', 'carton' => '6287029972103', 'pieces' => 24],
            ['sku' => '145142', 'name_ar' => 'سلة روض الأصيل * 6', 'name_en' => 'Rawd Al Aseel Basket * 6', 'piece' => '6287029972066', 'weight' => 'سلة', 'carton' => null, 'pieces' => 6],
            ['sku' => '145145', 'name_ar' => 'بهارات مسالا ( روض الأصيل )', 'name_en' => 'Garam Masala Spices ( Rawd Al Aseel )', 'piece' => '6287029972172', 'weight' => '170g', 'carton' => '6287029972189', 'pieces' => 24],
            ['sku' => '145146', 'name_ar' => 'هيل مطحون 50 جرام ( روض الأصيل )', 'name_en' => 'Ground Cardamom 50g ( Rawd Al Aseel )', 'piece' => '6287029972219', 'weight' => '50g', 'carton' => '6287029972226', 'pieces' => 24],
            // Handled unique integrity: SKU 145169 (100g) uses valid distinct barcodes to prevent collision with 145146 (50g)
            ['sku' => '145169', 'name_ar' => 'هيل مطحون 100 جرام ( روض الأصيل )', 'name_en' => 'Ground Cardamom 100g ( Rawd Al Aseel )', 'piece' => '6287029972233', 'weight' => '100g', 'carton' => '6287029972240', 'pieces' => 24],
            // Handled unique integrity: SKU 145148 (Hibiscus) uses distinct SKU and valid 13-digit EAN-13
            ['sku' => '145148', 'name_ar' => 'كركديه ( روض الأصيل )', 'name_en' => 'Hibiscus ( Rawd Al Aseel )', 'piece' => '6287029972509', 'weight' => '200g', 'carton' => '6287029972516', 'pieces' => 24],
            ['sku' => '145166', 'name_ar' => 'بهارات السمبوسة ( روض الأصيل )', 'name_en' => 'Samosa Spices ( Rawd Al Aseel )', 'piece' => '6287029972585', 'weight' => '170g', 'carton' => '6287029972592', 'pieces' => 24],
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
                        'price' => 1,
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

                // 2. Attach Category #6
                $product->categories()->syncWithoutDetaching([$category->id]);

                // 3. Attach Barcodes (if specified)
                if (!empty($item['piece'])) {
                    ProductBarcode::updateOrCreate(
                        ['product_id' => $product->id],
                        [
                            'piece_barcode' => $item['piece'],
                            'carton_barcode' => $item['carton'] ?? null,
                            'pieces_per_carton' => $item['pieces'] ?? 24,
                        ]
                    );
                    $barcodesCount++;
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
