<?php

namespace Tests\Feature;

use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Models\ProductBarcode;
use Database\Seeders\MazaqProductsSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Product;
use Tests\TestCase;

class MazaqSeederTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Test that MazaqProductsSeeder runs cleanly, creates Category #5,
     * seeds all 32 products, links barcodes, creates WMS locations, and is idempotent.
     */
    public function test_mazaq_seeder_seeds_catalog_barcodes_and_locations(): void
    {
        // 1. Ensure warehouse exists for WMS location provisioning
        Warehouse::firstOrCreate(
            ['code' => 'WH-MAIN'],
            [
                'name' => 'Main Distribution Center',
                'status' => 'active',
                'is_default' => true,
            ]
        );

        // 2. Run the seeder
        $this->seed(MazaqProductsSeeder::class);

        // 3. Verify Category #5
        $category = Category::find(5);
        $this->assertNotNull($category, 'Category #5 must exist');
        $this->assertEquals('بهارات وتوابل مذاق', $category->getTranslation('name', 'ar'));
        $this->assertEquals('Mazaq Spices & Seasonings', $category->getTranslation('name', 'en'));

        // 4. Verify 32 products exist and are attached to Category #5
        $products = $category->products()->get();
        $this->assertCount(32, $products, 'Category #5 must contain exactly 32 products');

        // 5. Verify translation, barcode, and WMS placement integrity for each product
        foreach ($products as $product) {
            // Check bilingual name
            $nameAr = $product->getTranslation('name', 'ar');
            $nameEn = $product->getTranslation('name', 'en');
            $this->assertNotEmpty($nameAr, "Product SKU {$product->sku} must have Arabic name");
            $this->assertNotEmpty($nameEn, "Product SKU {$product->sku} must have English name");

            // Check bilingual description
            $descAr = $product->getTranslation('description', 'ar');
            $descEn = $product->getTranslation('description', 'en');
            $this->assertNotEmpty($descAr, "Product SKU {$product->sku} must have Arabic description");
            $this->assertNotEmpty($descEn, "Product SKU {$product->sku} must have English description");
            $this->assertEquals($nameAr, $descAr, "Product SKU {$product->sku} Arabic description matches Arabic name");
            $this->assertEquals($nameEn, $descEn, "Product SKU {$product->sku} English description matches English name");

            // Check barcode row in product_barcodes
            $barcode = ProductBarcode::where('product_id', $product->id)->first();
            $this->assertNotNull($barcode, "Product SKU {$product->sku} must have ProductBarcode row");
            $this->assertNotEmpty($barcode->piece_barcode, "Product SKU {$product->sku} must have piece_barcode");
            $this->assertEquals(24, $barcode->pieces_per_carton, "Product SKU {$product->sku} has 24 pieces per carton");
            $this->assertNotNull($barcode->carton_barcode, "Product SKU {$product->sku} must have carton_barcode");
            $this->assertNotEquals($barcode->piece_barcode, $barcode->carton_barcode, "Piece and carton barcodes cannot be identical for SKU {$product->sku}");

            // Check WMS Location and ProductLocation
            $expectedLocCode = 'PICK-' . $product->sku;
            $location = Location::where('code', $expectedLocCode)->first();
            $this->assertNotNull($location, "WMS picking location {$expectedLocCode} must exist");
            $this->assertEquals(Location::TYPE_PICKING, $location->type);

            $placement = ProductLocation::where('product_id', $product->id)
                ->where('location_id', $location->id)
                ->first();
            $this->assertNotNull($placement, "ProductLocation placement for SKU {$product->sku} must exist");
            $this->assertGreaterThanOrEqual(100.00, (float) $placement->quantity);
        }

        // 6. Test Idempotency: re-running seeder does not throw or create duplicates
        $this->seed(MazaqProductsSeeder::class);
        $this->assertCount(32, $category->products()->get(), 'Product count in Category #5 remains 32 after re-seeding');
    }
}

