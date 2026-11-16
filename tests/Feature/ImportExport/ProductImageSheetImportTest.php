<?php

namespace Tests\Feature\ImportExport;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\Import;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as Perm;
use Marvel\Jobs\ImportProductImagesJob;
use Marvel\Jobs\ImportProductsJob;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductImageSheetImportTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUCT_HEADERS = [
        'sku', 'name_en', 'name_ar', 'description_en', 'description_ar', 'price',
        'product_type', 'item_type', 'quantity', 'status', 'in_stock', 'has_discount',
        'discount_type', 'discount_amount', 'start_date', 'end_date', 'height', 'width',
        'length', 'weight',
    ];

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

    private function createWorkbook(): string
    {
        $book = new Spreadsheet();
        $products = $book->getActiveSheet()->setTitle('products');
        $products->fromArray(self::PRODUCT_HEADERS, null, 'A1');

        $productRows = [
            ['SKU-1', 'One', '', '', '', 10, 'simple', 'PHYSICAL', 5, 1, 1, 0, 'fixed_rate', '', '', '', '', '', '', ''],
            ['SKU-2', 'Two', '', '', '', 10, 'simple', 'PHYSICAL', 5, 1, 1, 0, 'fixed_rate', '', '', '', '', '', '', ''],
        ];
        foreach ($productRows as $index => $row) {
            $products->fromArray($row, null, 'A' . ($index + 2), true);
        }

        // Deliberately not A/B order: the reader must map by heading.
        $images = $book->createSheet()->setTitle('images');
        $images->fromArray(['images', 'product_sku', 'image'], null, 'A1');
        $images->fromArray(['', 'SKU-1', 'https://example.com/a.jpg'], null, 'A2', true);
        $images->fromArray(['https://example.com/b.jpg|https://example.com/c.jpg', 'SKU-2', ''], null, 'A3', true);

        $tmp = tempnam(sys_get_temp_dir(), 'imgimport');
        (new Xlsx($book))->save($tmp);
        $book->disconnectWorksheets();

        return $tmp;
    }

    public function test_async_image_dispatch_reads_header_mapped_sheet_and_pipe_form(): void
    {
        Storage::fake('public');
        Queue::fake();

        $user = $this->makeAdmin();
        $tmp = $this->createWorkbook();
        $path = 'imports/test_' . uniqid() . '.xlsx';
        Storage::disk('public')->put($path, file_get_contents($tmp));
        @unlink($tmp);

        $import = Import::create([
            'type' => 'product-import',
            'file_path' => $path,
            'file_name' => 'products.xlsx',
            'status' => 'pending',
            'total_rows' => 0,
            'created_by' => $user->id,
        ]);

        (new ImportProductsJob($import->id))->handle();
        $import->refresh();

        $this->assertSame('completed', $import->status);
        $this->assertSame(2, $import->total_rows);
        $this->assertSame(2, Product::whereIn('sku', ['SKU-1', 'SKU-2'])->count());

        $pushed = Queue::pushed(ImportProductImagesJob::class);
        $this->assertCount(1, $pushed);

        $property = new \ReflectionProperty($pushed->first(), 'imageRows');
        $property->setAccessible(true);

        $this->assertSame([
            ['product_sku' => 'SKU-1', 'image' => 'https://example.com/a.jpg', 'row' => 2],
            ['product_sku' => 'SKU-2', 'image' => 'https://example.com/b.jpg', 'row' => 3],
            ['product_sku' => 'SKU-2', 'image' => 'https://example.com/c.jpg', 'row' => 3],
        ], $property->getValue($pushed->first()));
    }
}
