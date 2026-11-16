<?php

declare(strict_types=1);

namespace Tests\Feature\ImportExport;

use App\Support\ProductTypeSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Import;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as Perm;
use Marvel\Jobs\ImportProductsJob;
use Marvel\Services\Import\ProductImportService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductTypeIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = [
        'sku', 'name_en', 'name_ar', 'description_en', 'description_ar', 'price',
        'product_type', 'item_type', 'quantity', 'status', 'in_stock', 'has_discount',
        'discount_type', 'discount_amount', 'start_date', 'end_date', 'height', 'width',
        'length', 'weight',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
    }

    private function makeAdmin(): User
    {
        Permission::findOrCreate(Perm::IMPORT_PRODUCT, 'api');
        $role = Role::create(['name' => 'r' . uniqid(), 'guard_name' => 'api', 'display_name' => 'r']);
        $role->givePermissionTo(Perm::IMPORT_PRODUCT);

        $user = User::create([
            'name' => 'u' . uniqid(),
            'email' => uniqid() . '@test.local',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => 'admin',
        ]);
        $user->assignRole($role);
        $user->givePermissionTo(Perm::IMPORT_PRODUCT);

        return $user;
    }

    private function createWorkbook(array $productRows, array $categoryRows = []): string
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle('products');
        $sheet->fromArray(self::HEADERS, null, 'A1');

        $rowNumber = 2;
        foreach ($productRows as $row) {
            $line = [];
            foreach (self::HEADERS as $header) {
                $line[] = $row[$header] ?? '';
            }
            $sheet->fromArray($line, null, "A{$rowNumber}", true);
            $rowNumber++;
        }

        $book->createSheet()->setTitle('product_variants')
            ->fromArray(['product_sku', 'price', 'sale_price', 'quantity', 'height', 'width', 'length', 'weight', 'attributes'], null, 'A1');
        $book->createSheet()->setTitle('images')
            ->fromArray(['product_sku', 'image'], null, 'A1');

        $categories = $book->createSheet()->setTitle('categories');
        $categories->fromArray(['product_sku', 'category_slug'], null, 'A1');
        $categoryRow = 2;
        foreach ($categoryRows as $row) {
            $categories->fromArray([$row['product_sku'] ?? '', $row['category_slug'] ?? ''], null, "A{$categoryRow}");
            $categoryRow++;
        }

        $book->createSheet()->setTitle('brands')->fromArray(['product_sku', 'brand_slug'], null, 'A1');
        $book->createSheet()->setTitle('flash_sales')->fromArray(['product_sku', 'flash_sale_slug'], null, 'A1');
        $book->createSheet()->setTitle('sliders')->fromArray(['product_sku', 'slider_slug'], null, 'A1');
        $book->createSheet()->setTitle('tags')->fromArray(['product_sku', 'tag_slug'], null, 'A1');

        $tmp = tempnam(sys_get_temp_dir(), 'pti');
        (new Xlsx($book))->save($tmp);
        $book->disconnectWorksheets();

        return $tmp;
    }

    private function runImport(User $user, string $tmpPath, int $totalRows): Import
    {
        $path = 'imports/test_' . uniqid() . '.xlsx';
        Storage::disk('public')->put($path, file_get_contents($tmpPath));

        $import = Import::create([
            'type' => 'product-import',
            'file_path' => $path,
            'file_name' => 'products.xlsx',
            'status' => 'pending',
            'total_rows' => $totalRows,
            'created_by' => $user->id,
        ]);

        (new ImportProductsJob($import->id))->handle();
        @unlink($tmpPath);

        return $import->refresh();
    }

    public function test_product_type_is_persisted_and_normalized(): void
    {
        Storage::fake('public');
        $user = $this->makeAdmin();

        $tmp = $this->createWorkbook([
            ['sku' => 'PT-SIMPLE', 'name_en' => 'Simple', 'price' => 10, 'quantity' => 5, 'product_type' => 'simple', 'status' => 1, 'in_stock' => 1],
            ['sku' => 'PT-PADDED', 'name_en' => 'Padded', 'price' => 10, 'quantity' => 5, 'product_type' => ' Simple ', 'status' => 1, 'in_stock' => 'TRUE'],
        ]);

        $import = $this->runImport($user, $tmp, 2);

        $this->assertSame('completed', $import->status);
        $this->assertSame(2, $import->success_rows);
        $this->assertSame('simple', Product::where('sku', 'PT-SIMPLE')->value('product_type'));
        $this->assertSame('simple', Product::where('sku', 'PT-PADDED')->value('product_type'));
        $this->assertSame(1, (int) Product::where('sku', 'PT-PADDED')->value('in_stock'));
    }

    public function test_unknown_category_slug_is_reported_and_known_ones_still_sync(): void
    {
        Storage::fake('public');
        $user = $this->makeAdmin();
        $category = Category::create(['name' => ['en' => 'Known'], 'slug' => 'known-' . uniqid(), 'status' => 1]);

        $tmp = $this->createWorkbook(
            [['sku' => 'PT-CAT', 'name_en' => 'Categorised', 'price' => 10, 'quantity' => 5, 'product_type' => 'simple', 'status' => 1, 'in_stock' => 1]],
            [
                ['product_sku' => 'PT-CAT', 'category_slug' => $category->slug],
                ['product_sku' => 'PT-CAT', 'category_slug' => 'missing-category'],
            ]
        );

        $import = $this->runImport($user, $tmp, 1);

        $this->assertSame('completed', $import->status);
        $this->assertSame(0, $import->failed_rows);
        $this->assertSame([$category->id], Product::where('sku', 'PT-CAT')->firstOrFail()->categories()->pluck('categories.id')->all());
        $this->assertStringContainsString('missing-category', collect($import->errors)->pluck('error_message')->implode(' '));
        $this->assertStringContainsString('Unknown category slug', collect($import->errors)->pluck('error_message')->implode(' '));
    }

    public function test_in_stock_false_with_positive_quantity_is_imported_as_written_and_reported(): void
    {
        Storage::fake('public');
        $user = $this->makeAdmin();

        $tmp = $this->createWorkbook([
            ['sku' => 'PT-OOS', 'name_en' => 'Out of stock', 'price' => 10, 'quantity' => 100, 'product_type' => 'simple', 'status' => 1, 'in_stock' => 0],
        ]);

        $import = $this->runImport($user, $tmp, 1);

        $this->assertSame('completed', $import->status);
        $this->assertSame(1, $import->success_rows);
        $this->assertSame(0, (int) Product::where('sku', 'PT-OOS')->value('in_stock'));
        $this->assertStringContainsString('in_stock=0 while quantity>0', collect($import->errors)->pluck('error_message')->implode(' '));
    }

    public function test_failed_rows_report_real_excel_rows_across_chunks(): void
    {
        Storage::fake('public');
        $user = $this->makeAdmin();

        $products = [];
        for ($i = 1; $i <= 1004; $i++) {
            $products[] = ['sku' => "PT-CHUNK-{$i}", 'name_en' => "Chunk {$i}", 'price' => 10, 'quantity' => 5, 'product_type' => 'simple', 'status' => 1, 'in_stock' => 1];
        }
        // Excel row 1005 => the 1004th product row, inside the second chunk.
        $products[1003]['item_type'] = 'INVALID';

        $tmp = $this->createWorkbook($products);
        $import = $this->runImport($user, $tmp, 1004);

        $this->assertSame('completed_with_errors', $import->status);
        $this->assertEqualsCanonicalizing([1005], array_column($import->errors, 'row'));
    }

    public function test_import_fails_fast_when_the_enum_cannot_store_a_required_value(): void
    {
        Storage::fake('public');
        $user = $this->makeAdmin();
        DB::statement("ALTER TABLE `products` MODIFY `product_type` ENUM('simple') NOT NULL DEFAULT 'simple'");

        try {
            $tmp = $this->createWorkbook([
                ['sku' => 'PT-SCHEMA', 'name_en' => 'Schema', 'price' => 10, 'quantity' => 5, 'product_type' => 'simple', 'status' => 1, 'in_stock' => 1],
            ]);

            $import = $this->runImport($user, $tmp, 1);

            $this->assertSame('failed', $import->status);
            $this->assertStringContainsString('missing value', collect($import->errors)->pluck('error_message')->implode(' '));
            $this->assertDatabaseMissing('products', ['sku' => 'PT-SCHEMA']);
        } finally {
            DB::statement("ALTER TABLE `products` MODIFY `product_type` ENUM('simple','variable') NOT NULL DEFAULT 'simple'");
        }
    }

    public function test_in_stock_false_without_quantity_uses_the_persisted_quantity(): void
    {
        Storage::fake('public');
        $user = $this->makeAdmin();

        Product::create([
            'sku' => 'PT-EXISTING',
            'name' => ['en' => 'Existing'],
            'slug' => 'pt-existing-' . uniqid(),
            'price' => 10,
            'quantity' => 50,
            'stock_quantity' => 50,
            'product_type' => 'simple',
            'status' => true,
            'in_stock' => true,
        ]);

        // quantity is intentionally absent: the contradiction must be detected
        // against the quantity already stored on the product.
        $tmp = $this->createWorkbook([
            ['sku' => 'PT-EXISTING', 'name_en' => 'Existing', 'price' => 10, 'product_type' => 'simple', 'status' => 1, 'in_stock' => 0],
        ]);

        $import = $this->runImport($user, $tmp, 1);

        $this->assertSame('completed', $import->status);
        $this->assertSame(0, (int) Product::where('sku', 'PT-EXISTING')->value('in_stock'));
        $this->assertStringContainsString('in_stock=0 while quantity>0', collect($import->errors)->pluck('error_message')->implode(' '));
    }

    public function test_persisted_product_type_guard_rejects_silently_empty_enum_value(): void
    {
        $service = new class extends ProductImportService {
            public function guard(Product $product, ?string $expected): void
            {
                $this->assertPersistedProductType($product, $expected);
            }
        };

        $product = Product::create([
            'sku' => 'PT-GUARD',
            'name' => ['en' => 'Guard'],
            'slug' => 'pt-guard-' . uniqid(),
            'price' => 10,
            'quantity' => 5,
            'stock_quantity' => 5,
            'product_type' => 'simple',
            'status' => true,
            'in_stock' => true,
        ]);

        DB::table('products')->where('id', $product->id)->update(['product_type' => '']);

        try {
            $service->guard($product->fresh(), 'simple');
            $this->fail('An empty ENUM value must fail the row instead of being accepted.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('product_type could not be stored', $e->getMessage());
        }
    }

    public function test_schema_repair_widens_a_narrow_enum(): void
    {
        DB::statement("ALTER TABLE `products` MODIFY `product_type` ENUM('simple') NOT NULL DEFAULT 'simple'");

        try {
            $schema = new ProductTypeSchema();

            $this->assertNotNull($schema->incompatibilityReason());
            $this->assertTrue($schema->repair());
            $this->assertNull($schema->incompatibilityReason());

            $product = Product::create([
                'sku' => 'PT-WIDEN',
                'name' => ['en' => 'Widen'],
                'slug' => 'pt-widen-' . uniqid(),
                'price' => 10,
                'quantity' => 5,
                'stock_quantity' => 5,
                'product_type' => 'variable',
                'status' => true,
                'in_stock' => true,
            ]);

            $this->assertSame('variable', DB::table('products')->where('id', $product->id)->value('product_type'));
        } finally {
            DB::statement("ALTER TABLE `products` MODIFY `product_type` ENUM('simple','variable') NOT NULL DEFAULT 'simple'");
        }
    }

    public function test_schema_repair_restores_empty_product_type_and_reports_compatibility(): void
    {
        $product = Product::create([
            'sku' => 'PT-REPAIR',
            'name' => ['en' => 'Repair'],
            'slug' => 'pt-repair-' . uniqid(),
            'price' => 10,
            'quantity' => 5,
            'stock_quantity' => 5,
            'product_type' => 'simple',
            'status' => true,
            'in_stock' => true,
        ]);

        DB::table('products')->where('id', $product->id)->update(['product_type' => '']);

        $variableProduct = Product::create([
            'sku' => 'PT-REPAIR-VARIABLE',
            'name' => ['en' => 'Repair Variable'],
            'slug' => 'pt-repair-variable-' . uniqid(),
            'price' => 10,
            'quantity' => 5,
            'stock_quantity' => 5,
            'product_type' => 'variable',
            'status' => true,
            'in_stock' => true,
        ]);
        ProductVariant::create([
            'product_id' => $variableProduct->id,
            'sku' => 'PT-REPAIR-VARIANT',
            'price' => 10,
            'quantity' => 5,
            'stock_quantity' => 5,
            'in_stock' => true,
        ]);
        DB::table('products')->where('id', $variableProduct->id)->update(['product_type' => '']);

        $schema = new ProductTypeSchema();

        $this->assertTrue($schema->repair());
        $this->assertSame('simple', DB::table('products')->where('id', $product->id)->value('product_type'));
        $this->assertSame('variable', DB::table('products')->where('id', $variableProduct->id)->value('product_type'));
        $this->assertNull($schema->incompatibilityReason());
    }
}
