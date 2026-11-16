<?php

namespace Tests\Feature\ImportExport;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\Import;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as Perm;
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

    private function createWorkbook(array $imageCells): string
    {
        $book = new Spreadsheet();
        $products = $book->getActiveSheet()->setTitle('products');
        $products->fromArray(self::PRODUCT_HEADERS, null, 'A1');
        $products->fromArray(
            ['SKU-IMG', 'Images', '', '', '', 10, 'simple', 'PHYSICAL', 5, 1, 1, 0, 'fixed_rate', '', '', '', '', '', '', ''],
            null,
            'A2',
            true
        );

        // Deliberately not A/B order: the reader must map by heading.
        $images = $book->createSheet()->setTitle('images');
        $images->fromArray(['images', 'product_sku', 'image'], null, 'A1');

        foreach ($imageCells as $index => $cells) {
            $images->fromArray($cells, null, 'A' . ($index + 2), true);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'imgimport');
        (new Xlsx($book))->save($tmp);
        $book->disconnectWorksheets();

        return $tmp;
    }

    public function test_async_image_path_stores_every_image_from_both_cell_forms(): void
    {
        Storage::fake('public');

        $user = $this->makeAdmin();
        $first = $this->tempPng('a');
        $second = $this->tempPng('b');
        $third = $this->tempPng('c');

        try {
            $tmp = $this->createWorkbook([
                ['', 'SKU-IMG', $first],
                [$second . '|' . $third, 'SKU-IMG', ''],
            ]);
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
            $this->assertSame(1, $import->total_rows);

            $product = Product::where('sku', 'SKU-IMG')->firstOrFail();
            $this->assertCount(3, $product->getMedia('products'));
        } finally {
            @unlink($first);
            @unlink($second);
            @unlink($third);
        }
    }

    private function tempPng(string $marker): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'import_img_' . $marker . '_' . uniqid() . '.png';
        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2ZQAAAABJRU5ErkJggg=='
        ) . $marker);

        return $path;
    }
}
