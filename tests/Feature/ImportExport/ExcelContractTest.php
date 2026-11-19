<?php

declare(strict_types=1);

namespace Tests\Feature\ImportExport;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Exports\BrandsExport;
use Marvel\Exports\CategoriesExport;
use Marvel\Exports\ProductsExport;
use Marvel\Exports\Sheets\BrandsSheetExport;
use Marvel\Exports\Sheets\CategoriesSheetExport;
use Marvel\Exports\Sheets\FlashSalesSheetExport;
use Marvel\Exports\Sheets\ImagesSheetExport;
use Marvel\Exports\Sheets\ProductBarcodesSheetExport;
use Marvel\Exports\Sheets\ProductsSheetExport;
use Marvel\Exports\Sheets\ProductVariantsSheetExport;
use Marvel\Exports\Sheets\SlidersSheetExport;
use Marvel\Exports\Sheets\TagsSheetExport;
use Marvel\Workbook\ProductWorkbookSchema;
use Tests\TestCase;

class ExcelContractTest extends TestCase
{
    use RefreshDatabase;

    // Brand sheet - 7 headers exact order
    public function test_brand_export_has_exact_sheet_name_and_headers(): void
    {
        $export = new BrandsExport();
        // BrandsExport is FromCollection with single sheet, headings defined
        $this->assertEquals(
            ['name_en', 'name_ar', 'details_en', 'details_ar', 'status', 'image_desktop_url', 'image_mobile_url'],
            $export->headings(),
            'Brand export headings must match machine contract exactly'
        );
    }

    public function test_brand_export_headers_exact_order_no_extra(): void
    {
        $export = new BrandsExport();
        $headings = $export->headings();
        $this->assertCount(7, $headings);
        $this->assertEquals('name_en', $headings[0]);
        $this->assertEquals('image_mobile_url', $headings[6]);
    }

    // Category export - 9 headers
    public function test_category_export_has_exact_headers(): void
    {
        $export = new CategoriesExport();
        $expected = ['name_en', 'name_ar', 'details_en', 'details_ar', 'parent_name_en', 'status', 'is_featured', 'image_desktop_url', 'image_mobile_url'];
        $this->assertEquals($expected, $export->headings());
    }

    public function test_category_export_headers_count(): void
    {
        $export = new CategoriesExport();
        $this->assertCount(9, $export->headings());
    }

    // Product sheets - 8 sheets
    public function test_products_sheet_has_the_full_contract_headers(): void
    {
        $export = new ProductsSheetExport([]);
        $expected = [
            'sku', 'name_en', 'name_ar', 'description_en', 'description_ar', 'price',
            'product_type', 'item_type', 'quantity', 'status', 'in_stock', 'has_discount',
            'discount_type', 'discount_amount', 'start_date', 'end_date', 'height', 'width',
            'length', 'weight', 'tax_enabled', 'tax_rate', 'pieces', 'has_flash_sale',
        ];

        $this->assertSame($expected, $export->headings());
        $this->assertCount(24, $export->headings());
        $this->assertSame('products', $export->title());
    }

    public function test_product_barcodes_sheet_has_4_headers(): void
    {
        $export = new ProductBarcodesSheetExport([]);
        $expected = ['product_sku', 'piece_barcode', 'carton_barcode', 'pieces_per_carton'];
        $this->assertEquals($expected, $export->headings());
        $this->assertCount(4, $export->headings());
    }

    public function test_product_variants_sheet_has_the_full_contract_headers(): void
    {
        $export = new ProductVariantsSheetExport([]);
        $expected = [
            'variant_sku', 'product_sku', 'price', 'sale_price', 'quantity', 'in_stock',
            'height', 'width', 'length', 'weight', 'attributes',
        ];

        $this->assertSame($expected, $export->headings());
        $this->assertCount(11, $export->headings());
    }

    /**
     * Every export sheet declares its supported product filters in the
     * workbook schema; the applier applies exactly those.
     */
    public function test_every_export_sheet_declares_the_product_filter_contract(): void
    {
        foreach (ProductWorkbookSchema::keys() as $sheet) {
            $filters = ProductWorkbookSchema::exportFilters($sheet);

            $this->assertContains('status', $filters, "Sheet {$sheet} must declare the status filter");
            $this->assertContains('product_type', $filters, "Sheet {$sheet} must declare the product_type filter");
            $this->assertContains('item_type', $filters, "Sheet {$sheet} must declare the item_type filter");
            $this->assertContains('category_id', $filters, "Sheet {$sheet} must declare the category_id filter");
            $this->assertContains('brand_id', $filters, "Sheet {$sheet} must declare the brand_id filter");
        }
    }

    /**
     * Every export sheet excludes soft-deleted products: the images sheet is a
     * raw DB::table join, so the exclusion must be explicit there.
     */
    public function test_images_export_excludes_soft_deleted_products(): void
    {
        $sheet = new \ReflectionClass(ImagesSheetExport::class);
        $source = file_get_contents($sheet->getFileName());

        $this->assertStringContainsString("whereNull('products.deleted_at')", $source);
    }

    public function test_export_maps_align_with_the_schema_headings(): void
    {
        $product = new Product([
            'name' => ['en' => 'Sample'],
            'slug' => 'sample',
            'price' => 10,
        ]);
        $this->assertCount(
            count(ProductWorkbookSchema::headings(ProductWorkbookSchema::PRODUCTS)),
            (new ProductsSheetExport([]))->map($product)
        );

        $variant = new ProductVariant(['sku' => 'V-1', 'price' => 10]);
        $variant->setRelation('product', new Product(['sku' => 'P-1']));
        $variant->setRelation('attributeProducts', collect());
        $this->assertCount(
            count(ProductWorkbookSchema::headings(ProductWorkbookSchema::PRODUCT_VARIANTS)),
            (new ProductVariantsSheetExport([]))->map($variant)
        );

        $barcodeRow = (object) [
            'product_sku' => 'P-1',
            'piece_barcode' => '1',
            'carton_barcode' => '2',
            'pieces_per_carton' => 3,
        ];
        $this->assertCount(
            count(ProductWorkbookSchema::headings(ProductWorkbookSchema::PRODUCT_BARCODES)),
            (new ProductBarcodesSheetExport([]))->map($barcodeRow)
        );

        $taxonomyRow = (object) [
            'product_sku' => 'P-1',
            'category_slug' => 'x',
            'brand_slug' => 'x',
            'flash_sale_slug' => 'x',
            'slider_slug' => 'x',
            'tag_slug' => 'x',
        ];

        foreach ([
            CategoriesSheetExport::class => ProductWorkbookSchema::CATEGORIES,
            BrandsSheetExport::class => ProductWorkbookSchema::BRANDS,
            FlashSalesSheetExport::class => ProductWorkbookSchema::FLASH_SALES,
            SlidersSheetExport::class => ProductWorkbookSchema::SLIDERS,
            TagsSheetExport::class => ProductWorkbookSchema::TAGS,
        ] as $exportClass => $schemaKey) {
            $this->assertCount(
                count(ProductWorkbookSchema::headings($schemaKey)),
                (new $exportClass([]))->map($taxonomyRow)
            );
        }

        $imageRow = (object) ['product_sku' => 'P-1', 'file_name' => '', 'disk' => ''];
        $this->assertCount(
            count(ProductWorkbookSchema::headings(ProductWorkbookSchema::IMAGES)),
            (new ImagesSheetExport([]))->map($imageRow)
        );
    }

    public function test_images_sheet_has_2_headers(): void
    {
        $export = new ImagesSheetExport([]);
        $this->assertEquals(['product_sku','image'], $export->headings());
    }

    public function test_categories_sheet_has_2_headers(): void
    {
        $export = new CategoriesSheetExport([]);
        $this->assertEquals(['product_sku','category_slug'], $export->headings());
    }

    public function test_brands_sheet_has_2_headers(): void
    {
        $export = new BrandsSheetExport([]);
        $this->assertEquals(['product_sku','brand_slug'], $export->headings());
    }

    public function test_flash_sales_sheet_has_2_headers(): void
    {
        $export = new FlashSalesSheetExport([]);
        $this->assertEquals(['product_sku','flash_sale_slug'], $export->headings());
    }

    public function test_sliders_sheet_has_2_headers(): void
    {
        $export = new SlidersSheetExport([]);
        $this->assertEquals(['product_sku','slider_slug'], $export->headings());
    }

    public function test_tags_sheet_has_2_headers(): void
    {
        $export = new TagsSheetExport([]);
        $this->assertEquals(['product_sku','tag_slug'], $export->headings());
    }

    public function test_products_export_has_8_sheets_with_exact_names(): void
    {
        $export = new ProductsExport([]);
        $sheets = $export->sheets();
        $this->assertCount(9, $sheets);
        $this->assertArrayHasKey('products', $sheets);
        $this->assertArrayHasKey('product_barcodes', $sheets);
        $this->assertArrayHasKey('product_variants', $sheets);
        $this->assertArrayHasKey('images', $sheets);
        $this->assertArrayHasKey('categories', $sheets);
        $this->assertArrayHasKey('brands', $sheets);
        $this->assertArrayHasKey('flash_sales', $sheets);
        $this->assertArrayHasKey('sliders', $sheets);
        $this->assertArrayHasKey('tags', $sheets);
    }

    public function test_products_sheet_title(): void
    {
        $this->assertEquals('products', (new ProductsSheetExport([]))->title());
    }

    public function test_images_sheet_title(): void
    {
        $this->assertEquals('images', (new ImagesSheetExport([]))->title());
    }

    public function test_all_sheet_titles_match_expected(): void
    {
        $this->assertEquals('product_barcodes', (new ProductBarcodesSheetExport([]))->title());
        $this->assertEquals('product_variants', (new ProductVariantsSheetExport([]))->title());
        $this->assertEquals('categories', (new CategoriesSheetExport([]))->title());
        $this->assertEquals('brands', (new BrandsSheetExport([]))->title());
        $this->assertEquals('flash_sales', (new FlashSalesSheetExport([]))->title());
        $this->assertEquals('sliders', (new SlidersSheetExport([]))->title());
        $this->assertEquals('tags', (new TagsSheetExport([]))->title());
    }

    public function test_products_sheet_includes_optional_import_columns(): void
    {
        $headings = (new ProductsSheetExport([]))->headings();

        $this->assertContains('pieces', $headings);
        $this->assertContains('has_flash_sale', $headings);
        $this->assertContains('tax_enabled', $headings);
        $this->assertContains('tax_rate', $headings);
    }

    public function test_sample_files_have_correct_headers(): void
    {
        // Directly check the sample files on disk have expected headers
        $brandSample = config('marvel.import.samples.brand');
        $this->assertTrue(is_file($brandSample), 'Brand sample file must exist at configured path');
        $productSample = config('marvel.import.samples.product');
        $this->assertTrue(is_file($productSample), 'Product sample must exist');
        $categorySample = config('marvel.import.samples.category');
        $this->assertTrue(is_file($categorySample), 'Category sample must exist');

        // Parse brand sample headers via PhpSpreadsheet
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($brandSample);
        $reader->setReadDataOnly(true);
        $ss = $reader->load($brandSample);
        $sheet = $ss->getSheetByName('brands') ?? $ss->getActiveSheet();
        $headerRow = $sheet->rangeToArray('A1:G1', null, true, true, true);
        $headers = array_values($headerRow[1]);
        $headers = array_map(fn($v) => trim((string)$v), $headers);
        $this->assertEquals(['name_en','name_ar','details_en','details_ar','status','image_desktop_url','image_mobile_url'], $headers);
        $ss->disconnectWorksheets();

        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($productSample);
        $reader->setReadDataOnly(true);
        $productBook = $reader->load($productSample);

        $sampleSheets = [
            'products' => ProductWorkbookSchema::PRODUCTS,
            'product_barcodes' => ProductWorkbookSchema::PRODUCT_BARCODES,
            'product_variants' => ProductWorkbookSchema::PRODUCT_VARIANTS,
            'images' => ProductWorkbookSchema::IMAGES,
            'categories' => ProductWorkbookSchema::CATEGORIES,
            'brands' => ProductWorkbookSchema::BRANDS,
            'flash_sales' => ProductWorkbookSchema::FLASH_SALES,
            'sliders' => ProductWorkbookSchema::SLIDERS,
            'tags' => ProductWorkbookSchema::TAGS,
        ];

        foreach ($sampleSheets as $sheetName => $schemaKey) {
            $sheet = $productBook->getSheetByName($sheetName);
            $this->assertNotNull($sheet, "Product sample is missing the {$sheetName} sheet");

            $headers = array_values($sheet->rangeToArray('A1:' . $sheet->getHighestDataColumn() . '1')[0]);
            $headers = array_map(fn ($value) => trim((string) $value), $headers);

            $this->assertSame(
                ProductWorkbookSchema::headings($schemaKey),
                $headers,
                "Sample sheet {$sheetName} drift from the workbook schema"
            );
        }

        $productBook->disconnectWorksheets();
    }

    public function test_product_sample_has_8_sheets(): void
    {
        $path = config('marvel.import.samples.product');
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $ss = $reader->load($path);
        $names = $ss->getSheetNames();
        $this->assertCount(9, $names);
        $expected = ['products','product_barcodes','product_variants','images','categories','brands','flash_sales','sliders','tags'];
        foreach ($expected as $sheet) {
            $this->assertContains($sheet, $names, "Product sample must contain sheet {$sheet}");
        }
        $bcSheet = $ss->getSheetByName('product_barcodes');
        $this->assertNotNull($bcSheet);
        $headerRow = $bcSheet->rangeToArray('A1:D1', null, true, true, true);
        $headers = array_map(fn($v) => trim((string) $v), array_values($headerRow[1]));
        $this->assertEquals(['product_sku', 'piece_barcode', 'carton_barcode', 'pieces_per_carton'], $headers);
        $ss->disconnectWorksheets();
    }

    public function test_category_sample_headers(): void
    {
        $path = config('marvel.import.samples.category');
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $ss = $reader->load($path);
        $sheet = $ss->getActiveSheet();
        // The sample uses Sheet1, not categories, but header row should still be correct contract
        $headerRow = $sheet->rangeToArray('A1:I1', null, true, true, true);
        $headers = array_values($headerRow[1]);
        $headers = array_map(fn($v) => trim((string)$v), $headers);
        $this->assertEquals(['name_en','name_ar','details_en','details_ar','parent_name_en','status','is_featured','image_desktop_url','image_mobile_url'], $headers);
        $ss->disconnectWorksheets();
    }
}
