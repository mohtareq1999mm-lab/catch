<?php

declare(strict_types=1);

namespace Tests\Feature\ImportExport;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Exports\BrandsExport;
use Marvel\Exports\CategoriesExport;
use Marvel\Exports\ProductsExport;
use Marvel\Exports\Sheets\BrandsSheetExport;
use Marvel\Exports\Sheets\CategoriesSheetExport;
use Marvel\Exports\Sheets\FlashSalesSheetExport;
use Marvel\Exports\Sheets\ImagesSheetExport;
use Marvel\Exports\Sheets\ProductsSheetExport;
use Marvel\Exports\Sheets\ProductVariantsSheetExport;
use Marvel\Exports\Sheets\SlidersSheetExport;
use Marvel\Exports\Sheets\TagsSheetExport;
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
    public function test_products_sheet_has_20_headers(): void
    {
        $export = new ProductsSheetExport([]);
        $expected = ['sku','name_en','name_ar','description_en','description_ar','price','product_type','item_type','quantity','status','in_stock','has_discount','discount_type','discount_amount','start_date','end_date','height','width','length','weight'];
        $this->assertEquals($expected, $export->headings());
        $this->assertCount(20, $export->headings());
    }

    public function test_product_variants_sheet_has_9_headers(): void
    {
        $export = new ProductVariantsSheetExport([]);
        $expected = ['product_sku','price','sale_price','quantity','height','width','length','weight','attributes'];
        $this->assertEquals($expected, $export->headings());
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
        $this->assertCount(8, $sheets);
        $this->assertArrayHasKey('products', $sheets);
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
        $this->assertEquals('product_variants', (new ProductVariantsSheetExport([]))->title());
        $this->assertEquals('categories', (new CategoriesSheetExport([]))->title());
        $this->assertEquals('brands', (new BrandsSheetExport([]))->title());
        $this->assertEquals('flash_sales', (new FlashSalesSheetExport([]))->title());
        $this->assertEquals('sliders', (new SlidersSheetExport([]))->title());
        $this->assertEquals('tags', (new TagsSheetExport([]))->title());
    }

    public function test_no_extra_headers_in_products_sheet(): void
    {
        $export = new ProductsSheetExport([]);
        $headings = $export->headings();
        // Ensure no additional header beyond the 20
        $this->assertNotContains('pieces', $headings, 'pieces is optional importer field, not part of export contract');
        $this->assertNotContains('has_flash_sale', $headings);
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
    }

    public function test_product_sample_has_8_sheets(): void
    {
        $path = config('marvel.import.samples.product');
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $ss = $reader->load($path);
        $names = $ss->getSheetNames();
        $this->assertCount(8, $names);
        $expected = ['products','product_variants','images','categories','brands','flash_sales','sliders','tags'];
        foreach ($expected as $sheet) {
            $this->assertContains($sheet, $names, "Product sample must contain sheet {$sheet}");
        }
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
