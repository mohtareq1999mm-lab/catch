<?php

declare(strict_types=1);

namespace Tests\Feature\ImportExport;

use App\Models\ProductBarcode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Import;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as Perm;
use Marvel\Exports\ProductsExport;
use Marvel\Jobs\ImportProductsJob;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductBarcodesImportExportTest extends TestCase
{
    use RefreshDatabase;

    private const GUARD = 'api';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (glob(storage_path('app/imports/*.json')) ?: [] as $signalFile) {
            @unlink($signalFile);
        }
    }

    private function makeAdmin(): User
    {
        foreach ([Perm::IMPORT_PRODUCT, Perm::EXPORT_PRODUCT, Perm::CREATE_PRODUCT, Perm::VIEW_PRODUCTS] as $perm) {
            Permission::findOrCreate($perm, self::GUARD);
        }

        $role = Role::create([
            'name' => 'role_' . uniqid(),
            'guard_name' => self::GUARD,
            'display_name' => 'Admin Role',
        ]);
        $role->givePermissionTo([Perm::IMPORT_PRODUCT, Perm::EXPORT_PRODUCT]);

        $user = User::create([
            'name' => 'Admin ' . uniqid(),
            'email' => uniqid() . '@test.local',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => 'admin',
        ]);
        $user->assignRole($role);
        $user->givePermissionTo([Perm::IMPORT_PRODUCT, Perm::EXPORT_PRODUCT]);

        return $user;
    }

    /**
     * Build a multi-sheet product workbook with optional `product_barcodes` sheet.
     *
     * @param array<int, array<string, mixed>> $productRows
     * @param array<int, array<string, mixed>>|null $barcodeRows null = omit sheet (legacy 8-sheet workbook)
     */
    private function createWorkbook(array $productRows, ?array $barcodeRows = []): string
    {
        $ss = new Spreadsheet();

        // Sheet 1: products
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('products');
        $productHeaders = [
            'sku', 'name_en', 'name_ar', 'description_en', 'description_ar',
            'price', 'product_type', 'item_type', 'quantity', 'status',
            'in_stock', 'has_discount', 'discount_type', 'discount_amount',
            'start_date', 'end_date', 'height', 'width', 'length', 'weight',
        ];
        $sheet->fromArray($productHeaders, null, 'A1');
        $rowIdx = 2;
        foreach ($productRows as $row) {
            $line = [];
            foreach ($productHeaders as $h) {
                $line[] = $row[$h] ?? '';
            }
            $sheet->fromArray($line, null, "A{$rowIdx}");
            $rowIdx++;
        }

        // Optional Sheet 2: product_barcodes
        if ($barcodeRows !== null) {
            $bcSheet = $ss->createSheet();
            $bcSheet->setTitle('product_barcodes');
            $bcHeaders = ['product_sku', 'piece_barcode', 'carton_barcode', 'pieces_per_carton'];
            $bcSheet->fromArray($bcHeaders, null, 'A1');
            $bcRowIdx = 2;
            foreach ($barcodeRows as $bcRow) {
                $bcSheet->setCellValueExplicit("A{$bcRowIdx}", (string) ($bcRow['product_sku'] ?? ''), DataType::TYPE_STRING);
                if (isset($bcRow['piece_barcode']) && $bcRow['piece_barcode'] !== '') {
                    $bcSheet->setCellValueExplicit("B{$bcRowIdx}", (string) $bcRow['piece_barcode'], DataType::TYPE_STRING);
                }
                if (isset($bcRow['carton_barcode']) && $bcRow['carton_barcode'] !== '') {
                    $bcSheet->setCellValueExplicit("C{$bcRowIdx}", (string) $bcRow['carton_barcode'], DataType::TYPE_STRING);
                }
                if (isset($bcRow['pieces_per_carton']) && $bcRow['pieces_per_carton'] !== '') {
                    $bcSheet->setCellValue("D{$bcRowIdx}", $bcRow['pieces_per_carton']);
                }
                $bcRowIdx++;
            }
        }

        // Remaining standard sheets
        $otherSheets = [
            'product_variants' => ['variant_sku', 'product_sku', 'price', 'sale_price', 'quantity', 'in_stock', 'height', 'width', 'length', 'weight', 'attributes'],
            'images' => ['product_sku', 'image'],
            'categories' => ['product_sku', 'category_slug'],
            'brands' => ['product_sku', 'brand_slug'],
            'flash_sales' => ['product_sku', 'flash_sale_slug'],
            'sliders' => ['product_sku', 'slider_slug'],
            'tags' => ['product_sku', 'tag_slug'],
        ];

        foreach ($otherSheets as $title => $headers) {
            $s = $ss->createSheet();
            $s->setTitle($title);
            $s->fromArray($headers, null, 'A1');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'bc_prod_') . '.xlsx';
        (new Xlsx($ss))->save($tmp);
        $ss->disconnectWorksheets();
        unset($ss);

        return $tmp;
    }

    private function runImportJob(User $user, string $tmpPath): Import
    {
        $storedPath = 'imports/test_' . uniqid() . '.xlsx';
        Storage::disk('public')->put($storedPath, file_get_contents($tmpPath));

        $import = Import::create([
            'type' => 'product-import',
            'file_path' => $storedPath,
            'file_name' => 'products.xlsx',
            'status' => 'pending',
            'total_rows' => 0,
            'created_by' => $user->id,
        ]);

        (new ImportProductsJob($import->id))->handle();

        return $import->fresh();
    }

    public function test_imports_barcodes_from_dedicated_sheet_for_new_and_existing_products(): void
    {
        Storage::fake('public');
        $user = $this->makeAdmin();

        $tmp = $this->createWorkbook(
            [
                ['sku' => 'BC-SKU-001', 'name_en' => 'Spices 150g', 'price' => 50, 'quantity' => 20, 'product_type' => 'simple', 'status' => 1, 'in_stock' => 1],
                ['sku' => 'BC-SKU-002', 'name_en' => 'Olive Oil', 'price' => 120, 'quantity' => 15, 'product_type' => 'simple', 'status' => 1, 'in_stock' => 1],
            ],
            [
                [
                    'product_sku' => 'BC-SKU-001',
                    'piece_barcode' => '0628702997275',
                    'carton_barcode' => '0628702997276',
                    'pieces_per_carton' => 24,
                ],
                [
                    'product_sku' => 'BC-SKU-002',
                    'piece_barcode' => '6287029970001',
                    'carton_barcode' => '',
                    'pieces_per_carton' => '',
                ],
            ]
        );

        $import = $this->runImportJob($user, $tmp);
        @unlink($tmp);

        $this->assertSame('completed', $import->status);
        $this->assertSame(2, $import->success_rows);
        $this->assertSame(0, $import->failed_rows);
        $this->assertEmpty($import->errors);

        $p1 = Product::where('sku', 'BC-SKU-001')->firstOrFail();
        $this->assertDatabaseHas('product_barcodes', [
            'product_id' => $p1->id,
            'piece_barcode' => '0628702997275',
            'carton_barcode' => '0628702997276',
            'pieces_per_carton' => 24,
        ]);

        $p2 = Product::where('sku', 'BC-SKU-002')->firstOrFail();
        $this->assertDatabaseHas('product_barcodes', [
            'product_id' => $p2->id,
            'piece_barcode' => '6287029970001',
            'carton_barcode' => null,
            'pieces_per_carton' => null,
        ]);
    }

    public function test_empty_cells_in_barcode_sheet_preserve_existing_values(): void
    {
        Storage::fake('public');
        $user = $this->makeAdmin();

        $product = Product::create([
            'sku' => 'BC-PRESERVE-01',
            'name' => ['en' => 'Preserve Product'],
            'slug' => 'preserve-product-' . uniqid(),
            'price' => 80,
            'product_type' => 'simple',
            'stock_quantity' => 10,
            'status' => 1,
            'in_stock' => 1,
        ]);
        ProductBarcode::create([
            'product_id' => $product->id,
            'piece_barcode' => '1111111111111',
            'carton_barcode' => '2222222222222',
            'pieces_per_carton' => 12,
        ]);

        // Re-import with only pieces_per_carton updated; piece_barcode and carton_barcode empty
        $tmp = $this->createWorkbook(
            [
                ['sku' => 'BC-PRESERVE-01', 'name_en' => 'Preserve Product', 'price' => 85, 'quantity' => 10, 'product_type' => 'simple', 'status' => 1, 'in_stock' => 1],
            ],
            [
                [
                    'product_sku' => 'BC-PRESERVE-01',
                    'piece_barcode' => '',
                    'carton_barcode' => '',
                    'pieces_per_carton' => 36,
                ],
            ]
        );

        $import = $this->runImportJob($user, $tmp);
        @unlink($tmp);

        $this->assertSame('completed', $import->status);
        $this->assertDatabaseHas('product_barcodes', [
            'product_id' => $product->id,
            'piece_barcode' => '1111111111111',
            'carton_barcode' => '2222222222222',
            'pieces_per_carton' => 36,
        ]);
    }

    public function test_records_barcode_sheet_validation_errors_without_inflating_product_counters(): void
    {
        Storage::fake('public');
        $user = $this->makeAdmin();

        $existing = Product::create([
            'sku' => 'BC-TAKEN-OWNER',
            'name' => ['en' => 'Taken Owner'],
            'slug' => 'taken-owner-' . uniqid(),
            'price' => 40,
            'product_type' => 'simple',
            'stock_quantity' => 5,
            'status' => 1,
            'in_stock' => 1,
        ]);
        ProductBarcode::create([
            'product_id' => $existing->id,
            'piece_barcode' => '9999999999991',
            'carton_barcode' => '9999999999992',
            'pieces_per_carton' => 6,
        ]);

        $tmp = $this->createWorkbook(
            [
                ['sku' => 'BC-ERR-01', 'name_en' => 'Prod 1', 'price' => 50, 'quantity' => 5, 'product_type' => 'simple', 'status' => 1, 'in_stock' => 1],
                ['sku' => 'BC-ERR-02', 'name_en' => 'Prod 2', 'price' => 60, 'quantity' => 5, 'product_type' => 'simple', 'status' => 1, 'in_stock' => 1],
                ['sku' => 'BC-ERR-03', 'name_en' => 'Prod 3', 'price' => 70, 'quantity' => 5, 'product_type' => 'simple', 'status' => 1, 'in_stock' => 1],
            ],
            [
                // Row 2: unknown SKU
                ['product_sku' => 'UNKNOWN-SKU-999', 'piece_barcode' => '123456', 'carton_barcode' => '', 'pieces_per_carton' => 10],
                // Row 3: piece_barcode == carton_barcode on same product
                ['product_sku' => 'BC-ERR-01', 'piece_barcode' => '5555555555', 'carton_barcode' => '5555555555', 'pieces_per_carton' => 10],
                // Row 4: barcode already taken by another product's carton_barcode
                ['product_sku' => 'BC-ERR-02', 'piece_barcode' => '9999999999992', 'carton_barcode' => '', 'pieces_per_carton' => 10],
                // Row 5: invalid pieces_per_carton (0)
                ['product_sku' => 'BC-ERR-03', 'piece_barcode' => '7777777777', 'carton_barcode' => '', 'pieces_per_carton' => 0],
            ]
        );

        $import = $this->runImportJob($user, $tmp);
        @unlink($tmp);

        // Product counters remain strictly tied to the 3 products rows
        $this->assertSame(3, $import->total_rows);
        $this->assertSame(3, $import->processed_rows);
        $this->assertSame(3, $import->success_rows);
        $this->assertSame(0, $import->failed_rows);

        // All 4 barcode errors are recorded with sheet = product_barcodes and exact row numbers
        $this->assertIsArray($import->errors);
        $this->assertCount(4, $import->errors);

        $this->assertSame('product_barcodes', $import->errors[0]['sheet']);
        $this->assertSame(2, $import->errors[0]['row']);
        $this->assertSame('UNKNOWN-SKU-999', $import->errors[0]['sku']);

        $this->assertSame('product_barcodes', $import->errors[1]['sheet']);
        $this->assertSame(3, $import->errors[1]['row']);
        $this->assertSame('BC-ERR-01', $import->errors[1]['sku']);

        $this->assertSame('product_barcodes', $import->errors[2]['sheet']);
        $this->assertSame(4, $import->errors[2]['row']);
        $this->assertSame('BC-ERR-02', $import->errors[2]['sku']);

        $this->assertSame('product_barcodes', $import->errors[3]['sheet']);
        $this->assertSame(5, $import->errors[3]['row']);
        $this->assertSame('BC-ERR-03', $import->errors[3]['sku']);
    }

    public function test_legacy_workbook_without_product_barcodes_sheet_imports_cleanly(): void
    {
        Storage::fake('public');
        $user = $this->makeAdmin();

        $tmp = $this->createWorkbook(
            [
                ['sku' => 'LEGACY-8SHEET-01', 'name_en' => 'Legacy Product', 'price' => 99, 'quantity' => 8, 'product_type' => 'simple', 'status' => 1, 'in_stock' => 1],
            ],
            null // Omit product_barcodes sheet completely
        );

        $import = $this->runImportJob($user, $tmp);
        @unlink($tmp);

        $this->assertSame('completed', $import->status);
        $this->assertSame(1, $import->success_rows);
        $this->assertSame(0, $import->failed_rows);
        $this->assertEmpty($import->errors);
        $this->assertDatabaseHas('products', ['sku' => 'LEGACY-8SHEET-01']);
    }

    public function test_exports_product_barcodes_sheet_with_filters_and_string_binding(): void
    {
        $catA = Category::create(['name' => ['en' => 'Cat A'], 'slug' => 'cat-a-' . uniqid(), 'status' => 1]);
        $brandA = Brand::create(['name' => ['en' => 'Brand A'], 'slug' => 'brand-a-' . uniqid(), 'status' => 1]);

        $p1 = Product::create([
            'sku' => 'EXP-BC-01',
            'name' => ['en' => 'Export 1'],
            'slug' => 'exp-bc-01-' . uniqid(),
            'price' => 100,
            'product_type' => 'simple',
            'item_type' => 'PHYSICAL',
            'stock_quantity' => 10,
            'status' => 1,
            'in_stock' => 1,
        ]);
        $p1->categories()->attach($catA->id);
        $p1->brands()->attach($brandA->id);
        ProductBarcode::create([
            'product_id' => $p1->id,
            'piece_barcode' => '0123456789012',
            'carton_barcode' => '0987654321098',
            'pieces_per_carton' => 18,
        ]);

        $p2 = Product::create([
            'sku' => 'EXP-BC-02',
            'name' => ['en' => 'Export 2'],
            'slug' => 'exp-bc-02-' . uniqid(),
            'price' => 200,
            'product_type' => 'simple',
            'item_type' => 'PHYSICAL',
            'stock_quantity' => 5,
            'status' => 0,
            'in_stock' => 1,
        ]);
        ProductBarcode::create([
            'product_id' => $p2->id,
            'piece_barcode' => '5555555555555',
            'carton_barcode' => null,
            'pieces_per_carton' => null,
        ]);

        $export = new ProductsExport([
            'status' => 1,
            'category_id' => $catA->id,
            'brand_id' => $brandA->id,
        ]);

        $tmpExport = 'exports/test_bc_export_' . uniqid() . '.xlsx';
        Storage::fake('local');
        Excel::store($export, $tmpExport, 'local');

        $fullPath = Storage::disk('local')->path($tmpExport);
        $reader = IOFactory::createReaderForFile($fullPath);
        $reader->setReadDataOnly(false);
        $ss = $reader->load($fullPath);

        $bcSheet = $ss->getSheetByName('product_barcodes');
        $this->assertNotNull($bcSheet);
        $this->assertSame(2, $bcSheet->getHighestDataRow());
        $this->assertSame('EXP-BC-01', (string) $bcSheet->getCell('A2')->getValue());
        $this->assertSame('0123456789012', (string) $bcSheet->getCell('B2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $bcSheet->getCell('B2')->getDataType());
        $this->assertSame('0987654321098', (string) $bcSheet->getCell('C2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $bcSheet->getCell('C2')->getDataType());
        $this->assertSame(18, (int) $bcSheet->getCell('D2')->getValue());

        $ss->disconnectWorksheets();
    }

    public function test_sample_file_imports_sample_product_and_barcodes_end_to_end(): void
    {
        Storage::fake('public');
        $user = $this->makeAdmin();

        $samplePath = config('marvel.import.samples.product');
        $this->assertFileExists($samplePath);

        $import = $this->runImportJob($user, $samplePath);

        $this->assertSame('completed', $import->status);
        $this->assertSame(3, $import->success_rows);
        $this->assertSame(0, $import->failed_rows);

        $sampleProduct = Product::where('sku', 'PRD-SAMPLE-001')->firstOrFail();
        $this->assertDatabaseHas('product_barcodes', [
            'product_id' => $sampleProduct->id,
            'piece_barcode' => '6287029972752',
            'carton_barcode' => '6287029972769',
            'pieces_per_carton' => 24,
        ]);
    }

    public function test_rejects_fractional_non_numeric_and_overlength_barcodes_and_excludes_soft_deleted_from_export(): void
    {
        Storage::fake('public');
        $user = $this->makeAdmin();

        $overlength = str_repeat('9', 65);

        $tmp = $this->createWorkbook(
            [
                ['sku' => 'BC-EDGE-01', 'name_en' => 'Edge 1', 'price' => 30, 'quantity' => 5, 'product_type' => 'simple', 'status' => 1, 'in_stock' => 1],
                ['sku' => 'BC-EDGE-02', 'name_en' => 'Edge 2', 'price' => 40, 'quantity' => 5, 'product_type' => 'simple', 'status' => 1, 'in_stock' => 1],
                ['sku' => 'BC-EDGE-03', 'name_en' => 'Edge 3', 'price' => 50, 'quantity' => 5, 'product_type' => 'simple', 'status' => 1, 'in_stock' => 1],
            ],
            [
                // Row 2: fractional pieces_per_carton (2.5)
                ['product_sku' => 'BC-EDGE-01', 'piece_barcode' => '6280000000001', 'carton_barcode' => '', 'pieces_per_carton' => '2.5'],
                // Row 3: overlength piece_barcode (>64 chars)
                ['product_sku' => 'BC-EDGE-02', 'piece_barcode' => $overlength, 'carton_barcode' => '', 'pieces_per_carton' => 12],
                // Row 4: valid row
                ['product_sku' => 'BC-EDGE-03', 'piece_barcode' => '6280000000003', 'carton_barcode' => '6280000000004', 'pieces_per_carton' => 10],
            ]
        );

        $import = $this->runImportJob($user, $tmp);
        @unlink($tmp);

        $this->assertCount(2, $import->errors);
        $this->assertSame('BC-EDGE-01', $import->errors[0]['sku']);
        $this->assertSame('BC-EDGE-02', $import->errors[1]['sku']);

        // Valid row persisted; soft-deleting the product excludes it from export
        $p3 = Product::where('sku', 'BC-EDGE-03')->firstOrFail();
        $this->assertDatabaseHas('product_barcodes', [
            'product_id' => $p3->id,
            'piece_barcode' => '6280000000003',
        ]);

        $p3->delete();

        $export = new ProductsExport([]);
        $tmpExport = 'exports/test_soft_del_' . uniqid() . '.xlsx';
        Storage::fake('local');
        Excel::store($export, $tmpExport, 'local');

        $fullPath = Storage::disk('local')->path($tmpExport);
        $ss = IOFactory::load($fullPath);
        $bcSheet = $ss->getSheetByName('product_barcodes');
        $this->assertNotNull($bcSheet);
        $this->assertSame(1, $bcSheet->getHighestDataRow(), 'Soft-deleted product must not be exported in product_barcodes sheet');
        $ss->disconnectWorksheets();
    }

    public function test_export_then_reimport_roundtrip_preserves_and_updates_barcodes(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $user = $this->makeAdmin();

        $product = Product::create([
            'sku' => 'ROUNDTRIP-01',
            'name' => ['en' => 'Roundtrip EN', 'ar' => 'منتج'],
            'description' => ['en' => 'Desc EN', 'ar' => 'وصف'],
            'slug' => 'roundtrip-01-' . uniqid(),
            'price' => 150,
            'product_type' => 'simple',
            'item_type' => 'PHYSICAL',
            'stock_quantity' => 30,
            'status' => 1,
            'in_stock' => 1,
        ]);
        ProductBarcode::create([
            'product_id' => $product->id,
            'piece_barcode' => '0011223344556',
            'carton_barcode' => '0099887766554',
            'pieces_per_carton' => 20,
        ]);

        // 1. Export to xlsx
        $exportRelPath = 'exports/roundtrip_' . uniqid() . '.xlsx';
        Excel::store(new ProductsExport([]), $exportRelPath, 'local');
        $exportedFile = Storage::disk('local')->path($exportRelPath);

        // 2. Modify pieces_per_carton in DB to 5, then re-import the exported workbook to restore 20
        ProductBarcode::where('product_id', $product->id)->update(['pieces_per_carton' => 5]);

        $import = $this->runImportJob($user, $exportedFile);

        $this->assertSame('completed', $import->status);
        $this->assertEmpty($import->errors);
        $this->assertDatabaseHas('product_barcodes', [
            'product_id' => $product->id,
            'piece_barcode' => '0011223344556',
            'carton_barcode' => '0099887766554',
            'pieces_per_carton' => 20,
        ]);
    }
}
