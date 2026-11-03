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
 * Seeder for Canned Legumes & Goods (بقوليات ومعلبات) Catalog.
 *
 * Scope:
 * - Creates/ensures Category #7 exists (Canned Goods & Legumes).
 * - Seeds 5 products with Arabic and English translatable names and descriptions.
 * - Associates piece and carton barcodes with unique integrity handling.
 * - Provisions dedicated WMS picking locations and initial available stock.
 *
 * Fully idempotent and production-defensive.
 */
class CannedLegumesProductsSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('====================================================');
        $this->command?->info('Seeding Canned Legumes (بقوليات ومعلبات) Dataset');
        $this->command?->info('====================================================');

        // 1. Ensure Category #7 exists
        $category = Category::firstOrCreate(
            ['id' => 7],
            [
                'name' => [
                    'ar' => 'بقوليات ومعلبات',
                    'en' => 'Canned Goods & Legumes',
                ],
                'slug' => 'canned-goods-and-legumes',
                'details' => [
                    'ar' => 'بقوليات ومعلبات غذائية عالية الجودة',
                    'en' => 'High quality canned legumes and goods',
                ],
                'status' => 1,
            ]
        );
        $this->command?->info("Target Category: [#{$category->id}] " . ($category->getTranslation('name', 'ar') ?? 'بقوليات ومعلبات'));

        // 2. Resolve default active warehouse for WMS placements
        $warehouse = Warehouse::where('is_default', true)->where('status', 'active')->first()
            ?? Warehouse::where('status', 'active')->first();

        // 3. Canned Legumes dataset (5 items)
        $items = [
            ['sku' => '145026', 'name_ar' => 'فاصوليا حمراء', 'name_en' => 'Red Kidney Beans', 'piece' => '6287029971366', 'weight' => '400g', 'carton' => '6287029971403', 'pieces' => 24],
            ['sku' => '145027', 'name_ar' => 'فول مدمس', 'name_en' => 'Fava Beans ( Foul Mudammas )', 'piece' => '6287029971373', 'weight' => '400g', 'carton' => '6287029971410', 'pieces' => 24],
            ['sku' => '145028', 'name_ar' => 'فاصوليا بيضاء', 'name_en' => 'White Beans', 'piece' => '6287029971380', 'weight' => '400g', 'carton' => '6287029971427', 'pieces' => 24],
            ['sku' => '145029', 'name_ar' => 'بازيلاء', 'name_en' => 'Green Peas', 'piece' => '6287029971397', 'weight' => '400g', 'carton' => '6287029971434', 'pieces' => 24],
            ['sku' => '145031', 'name_ar' => 'فاصولياء حمراء ( فاتح )', 'name_en' => 'Light Red Kidney Beans', 'piece' => '6287029972622', 'weight' => '400g', 'carton' => '6287029972639', 'pieces' => 24],
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
                        'price' => 6.00,
                        'quantity' => 100,
                        'stock_quantity' => 100,
                        'in_stock' => 1,
                        'status' => 1,
                        'product_type' => 'simple',
                        'item_type' => 'PHYSICAL',
                        'weight' => $item['weight'],
                        'pieces' => $item['pieces'],
                    ]
                );
                $createdCount++;

                // 2. Attach Category #7
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
