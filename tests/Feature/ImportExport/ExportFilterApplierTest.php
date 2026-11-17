<?php

namespace Tests\Feature\ImportExport;

use App\Enums\FrontendResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Marvel\Exports\ExportFilterApplier;
use Marvel\Exports\Sheets\ProductsSheetExport;
use Marvel\Exports\Sheets\ProductVariantsSheetExport;
use Marvel\Exports\Sheets\BrandsSheetExport;
use Marvel\Exports\Sheets\CategoriesSheetExport;
use Marvel\Exports\Sheets\TagsSheetExport;
use Marvel\Exports\Sheets\FlashSalesSheetExport;
use Marvel\Exports\Sheets\SlidersSheetExport;
use Marvel\Exports\Sheets\ProductBarcodesSheetExport;
use Marvel\Exports\Sheets\ImagesSheetExport;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Tests\TestCase;

class ExportFilterApplierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
    }

    private function newQuery()
    {
        return DB::table('products')->whereNull('products.deleted_at');
    }

    private function applyFilters($query, array $filters): void
    {
        ExportFilterApplier::apply(
            $query,
            $filters,
            ['status', 'product_type', 'item_type', 'category_id', 'brand_id']
        );
    }

    public function test_invalid_item_type_filters_to_nothing_instead_of_applying_raw(): void
    {
        $query = $this->newQuery();
        $this->applyFilters($query, ['item_type' => 'SERVICE']);

        $sql = $query->toSql();

        $this->assertStringContainsString('1 = 0', $sql);
        $this->assertStringNotContainsString('item_type', $sql);
    }

    public function test_valid_item_type_is_applied(): void
    {
        $query = $this->newQuery();
        $this->applyFilters($query, ['item_type' => 'PHYSICAL']);

        $this->assertStringContainsString('item_type', $query->toSql());
    }

    public function test_category_filter_executes_against_the_database(): void
    {
        $category = Category::create(['name' => ['en' => 'Filter Cat ' . uniqid()], 'slug' => 'filter-cat-' . uniqid(), 'status' => 1]);
        $product = Product::create([
            'sku' => 'FILTER-CAT-001',
            'name' => ['en' => 'Filtered'],
            'slug' => 'filter-cat-prod-' . uniqid(),
            'price' => 10,
            'quantity' => 5,
            'stock_quantity' => 5,
            'product_type' => 'simple',
            'status' => true,
            'in_stock' => true,
        ]);
        $product->categories()->attach($category->id);

        $query = $this->newQuery();
        $this->applyFilters($query, ['category_id' => $category->id]);

        $this->assertSame(1, (clone $query)->count());
    }

    public function test_brand_filter_executes_against_the_database(): void
    {
        $brand = Brand::create(['name' => ['en' => 'Filter Brand'], 'slug' => 'filter-brand-' . uniqid(), 'status' => 1]);
        $product = Product::create([
            'sku' => 'FILTER-BRD-001',
            'name' => ['en' => 'Filtered'],
            'slug' => 'filter-brand-prod-' . uniqid(),
            'price' => 10,
            'quantity' => 5,
            'stock_quantity' => 5,
            'product_type' => 'simple',
            'status' => true,
            'in_stock' => true,
        ]);
        $product->brands()->attach($brand->id);

        $query = $this->newQuery();
        $this->applyFilters($query, ['brand_id' => $brand->id]);

        $this->assertSame(1, (clone $query)->count());
    }

    public function test_status_and_product_type_filters_execute(): void
    {
        $sku = 'FILTER-ST-' . uniqid();
        Product::create([
            'sku' => $sku,
            'name' => ['en' => 'Active'],
            'slug' => 'filter-st-' . uniqid(),
            'price' => 10,
            'quantity' => 5,
            'stock_quantity' => 5,
            'product_type' => 'simple',
            'status' => true,
            'in_stock' => true,
        ]);

        $query = $this->newQuery();
        $this->applyFilters($query, ['status' => 1, 'product_type' => 'simple']);

        $this->assertSame(1, (clone $query)->whereIn('sku', [$sku])->count());
    }

    public function test_products_sheet_query_executes_with_filters(): void
    {
        $category = Category::create(['name' => ['en' => 'Sheet Cat ' . uniqid()], 'slug' => 'sheet-cat-' . uniqid(), 'status' => 1]);
        $product = Product::create([
            'sku' => 'SHEET-PROD-001',
            'name' => ['en' => 'Sheet Prod'],
            'slug' => 'sheet-prod-' . uniqid(),
            'price' => 10,
            'quantity' => 5,
            'stock_quantity' => 5,
            'product_type' => 'simple',
            'status' => true,
            'in_stock' => true,
        ]);
        $product->categories()->attach($category->id);

        $sheet = new ProductsSheetExport([
            'status' => 1,
            'category_id' => $category->id,
        ]);

        $this->assertSame(1, $sheet->query()->whereIn('sku', [$product->sku])->count());

        $invalid = new ProductsSheetExport(['item_type' => 'SERVICE']);

        $this->assertSame(0, $invalid->query()->whereIn('sku', [$product->sku])->count());
    }

    public function test_variants_sheet_query_constrains_through_the_parent_product(): void
    {        $brand = Brand::create(['name' => ['en' => 'Variant Sheet Brand ' . uniqid()], 'slug' => 'variant-sheet-brand-' . uniqid(), 'status' => 1]);
        $product = Product::create([
            'sku' => 'SHEET-VAR-PARENT-001',
            'name' => ['en' => 'Variant Parent'],
            'slug' => 'sheet-var-parent-' . uniqid(),
            'price' => 10,
            'quantity' => 5,
            'stock_quantity' => 5,
            'product_type' => 'variable',
            'status' => true,
            'in_stock' => true,
        ]);
        $product->brands()->attach($brand->id);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'SHEET-VAR-001',
            'price' => 10,
            'stock_quantity' => 2,
            'in_stock' => true,
        ]);

        $sheet = new ProductVariantsSheetExport([
            'status' => 1,
            'brand_id' => $brand->id,
        ]);

        $this->assertSame(1, $sheet->query()->whereIn('sku', [$variant->sku])->count());

        $inactive = new ProductVariantsSheetExport(['status' => 0]);

        $this->assertSame(0, $inactive->query()->whereIn('sku', [$variant->sku])->count());

        $invalidItemType = new ProductVariantsSheetExport(['item_type' => 'SERVICE']);

        $this->assertSame(0, $invalidItemType->query()->whereIn('sku', [$variant->sku])->count());
    }

    /**
     * Every join-builder sheet declares the contract and relies on the
     * pivot whereExists mode; each must execute its filtered query against
     * the database without a fatal (the missing-DB-import bug class).
     *
     * @return array<string, array{0: class-string, 1: string}>
     */
    public static function joinSheetProvider(): array
    {
        return [
            'brands' => [BrandsSheetExport::class, 'BRANDS'],
            'categories' => [CategoriesSheetExport::class, 'CATEGORIES'],
            'tags' => [TagsSheetExport::class, 'TAGS'],
            'flash_sales' => [FlashSalesSheetExport::class, 'FLASH_SALES'],
            'sliders' => [SlidersSheetExport::class, 'SLIDERS'],
            'product_barcodes' => [ProductBarcodesSheetExport::class, 'PRODUCT_BARCODES'],
            'images' => [ImagesSheetExport::class, 'IMAGES'],
        ];
    }

    /**
     * @param  class-string  $sheetClass
     * @dataProvider joinSheetProvider
     */
    public function test_every_join_sheet_executes_the_declared_filters(string $sheetClass, string $sheetKey): void
    {
        $product = Product::create([
            'sku' => 'JOIN-' . strtoupper($sheetKey),
            'name' => ['en' => 'Join ' . $sheetKey],
            'slug' => 'join-' . $sheetKey . '-' . uniqid(),
            'price' => 10,
            'quantity' => 5,
            'stock_quantity' => 5,
            'product_type' => 'simple',
            'status' => true,
            'in_stock' => true,
        ]);

        $invalid = new $sheetClass(['item_type' => 'SERVICE']);

        $this->assertSame(0, $invalid->query()->count());

        $sheet = new $sheetClass(['status' => 1, 'product_type' => 'simple']);

        $count = $sheet->query()->count();

        $this->assertGreaterThanOrEqual(0, $count);
        $this->assertDatabaseHas('products', ['sku' => 'JOIN-' . strtoupper($sheetKey)]);
    }
}
