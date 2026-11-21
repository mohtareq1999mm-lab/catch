<?php

namespace Tests\Feature\ImportExport;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use App\Events\FileOperationEvent;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Import;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as Perm;
use Marvel\Jobs\FinalizeProductImportJob;
use Marvel\Jobs\ImportProductsJob;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProductImageSheetImportTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = '/api/v1';

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

    /**
     * #107 decision 1: SVG is rejected on product imports (stored SVG is
     * the latent stored-XSS vector if media is ever served inline). The
     * product posture is non-fatal: the row stays and the image error is
     * recorded.
     */
    public function test_svg_images_are_rejected_on_product_import(): void
    {
        Storage::fake('public');

        Http::fake([
            'https://example.com/logo.svg' => Http::response(
                '<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10"/></svg>',
                200,
                ['Content-Type' => 'image/svg+xml']
            ),
        ]);

        $user = $this->makeAdmin();

        $tmp = $this->createWorkbook([
            ['', 'SKU-IMG', 'https://example.com/logo.svg'],
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
        $this->assertNotEmpty($import->errors, 'The rejected SVG image must be recorded as an image error.');

        $product = Product::where('sku', 'SKU-IMG')->firstOrFail();
        $this->assertCount(0, $product->getMedia('products'));
    }

    /**
     * Stories 14/15/24: image failures are merged before the terminal write,
     * so the terminal broadcast never promises a report before the error
     * exists. The full pipeline is exercised: core product phase → image
     * batch → deferred finalizer.
     */
    public function test_image_errors_are_merged_before_the_terminal_write(): void
    {
        Storage::fake('public');

        $user = $this->makeAdmin();

        $tmp = $this->createWorkbook([
            ['', 'GHOST-SKU', 'https://example.com/missing.jpg'],
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

        config(['app.env' => 'production', 'shop.pusher.enabled' => true]);
        Event::fake([FileOperationEvent::class]);

        (new ImportProductsJob($import->id))->handle();

        $import->refresh();

        // Image failures are auxiliary: they do not inflate product counters,
        // but they must be in the terminal row and the report must be offered.
        $this->assertSame('completed', $import->status);
        $this->assertSame(1, $import->processed_rows);
        $this->assertSame(1, $import->success_rows);
        $this->assertSame(0, $import->failed_rows);
        $this->assertNotEmpty($import->errors);
        $this->assertSame('GHOST-SKU', $import->errors[0]['sku']);

        Event::assertDispatched(function (FileOperationEvent $event) use ($import) {
            return $event->eventName === FileOperationEvent::PRODUCT_IMPORT_COMPLETED
                && $event->userId === $import->created_by
                && $event->payload['status'] === 'completed'
                && $event->payload['download_available'] === true
                && $event->payload['has_errors'] === true;
        });
    }

    public function test_finalizer_writes_the_terminal_row_after_image_errors_are_persisted(): void
    {
        $user = $this->makeAdmin();

        $import = Import::create([
            'type' => 'product-import',
            'file_name' => 'products.xlsx',
            'status' => 'processing',
            'run_uuid' => 'batch-uuid',
            'total_rows' => 1,
            'processed_rows' => 1,
            'success_rows' => 1,
            'failed_rows' => 0,
            'errors' => [[
                'sheet' => 'images',
                'row' => 5,
                'sku' => 'GHOST-SKU',
                'error_message' => 'Product not found.',
            ]],
            'created_by' => $user->id,
        ]);

        config(['app.env' => 'production', 'shop.pusher.enabled' => true]);
        Event::fake([FileOperationEvent::class]);

        (new FinalizeProductImportJob($import->id, 'batch-uuid', 1, 0, 1))->handle();

        $import->refresh();

        $this->assertSame('completed', $import->status);
        $this->assertSame(1, $import->processed_rows);
        $this->assertSame(1, $import->success_rows);
        $this->assertSame(0, $import->failed_rows);
        $this->assertSame('GHOST-SKU', $import->errors[0]['sku']);

        Event::assertDispatchedTimes(FileOperationEvent::class, 1);
        Event::assertDispatched(function (FileOperationEvent $event) {
            return $event->eventName === FileOperationEvent::PRODUCT_IMPORT_COMPLETED
                && $event->payload['download_available'] === true
                && $event->payload['has_errors'] === true;
        });
    }

    public function test_finalizer_does_not_overwrite_a_cancelled_import(): void
    {
        $user = $this->makeAdmin();

        $import = Import::create([
            'type' => 'product-import',
            'file_name' => 'products.xlsx',
            'status' => 'cancelled',
            'total_rows' => 10,
            'processed_rows' => 4,
            'success_rows' => 3,
            'failed_rows' => 1,
            'errors' => [],
            'created_by' => $user->id,
        ]);

        config(['app.env' => 'production', 'shop.pusher.enabled' => true]);
        Event::fake([FileOperationEvent::class]);

        (new FinalizeProductImportJob($import->id, 'batch-uuid', 9, 0, 10))->handle();

        $import->refresh();

        $this->assertSame('cancelled', $import->status);
        $this->assertSame(4, $import->processed_rows);
        $this->assertSame(3, $import->success_rows);
        $this->assertSame(1, $import->failed_rows);

        Event::assertNotDispatched(fn (FileOperationEvent $event) => in_array($event->eventName, [
            FileOperationEvent::PRODUCT_IMPORT_COMPLETED,
            FileOperationEvent::PRODUCT_IMPORT_FAILED,
            FileOperationEvent::PRODUCT_IMPORT_CANCELLED,
        ], true));
    }

    /**
     * A retry stamps a new run_uuid on the row; a finalizer left over from a
     * previous attempt must never terminalize the new run — even when the
     * retried run produces identical counters.
     */
    public function test_finalizer_ignores_a_stale_run(): void
    {
        $user = $this->makeAdmin();

        $import = Import::create([
            'type' => 'product-import',
            'file_name' => 'products.xlsx',
            'status' => 'processing',
            'run_uuid' => 'run-two',
            'total_rows' => 1,
            'processed_rows' => 1,
            'success_rows' => 1,
            'failed_rows' => 0,
            'errors' => [],
            'created_by' => $user->id,
        ]);

        config(['app.env' => 'production', 'shop.pusher.enabled' => true]);
        Event::fake([FileOperationEvent::class]);

        (new FinalizeProductImportJob($import->id, 'run-one', 1, 0, 1))->handle();

        $import->refresh();

        $this->assertSame('processing', $import->status);
        $this->assertSame(1, $import->success_rows);
        $this->assertSame([], $import->errors ?? []);

        Event::assertNotDispatched(fn (FileOperationEvent $event) => in_array($event->eventName, [
            FileOperationEvent::PRODUCT_IMPORT_COMPLETED,
            FileOperationEvent::PRODUCT_IMPORT_FAILED,
        ], true));
    }

    /**
     * A chunk that exhausted its retries never merged; the terminal report
     * must say so instead of claiming completeness (stories 14/24).
     */
    public function test_finalizer_reports_image_chunks_that_failed_their_retries(): void
    {
        app()->setLocale('en');

        $user = $this->makeAdmin();

        $import = Import::create([
            'type' => 'product-import',
            'file_name' => 'products.xlsx',
            'status' => 'processing',
            'run_uuid' => 'batch-uuid',
            'total_rows' => 1,
            'processed_rows' => 1,
            'success_rows' => 1,
            'failed_rows' => 0,
            'errors' => [],
            'created_by' => $user->id,
        ]);

        config(['app.env' => 'production', 'shop.pusher.enabled' => true]);
        Event::fake([FileOperationEvent::class]);

        (new FinalizeProductImportJob($import->id, 'batch-uuid', 1, 0, 1, true))->handle();

        $import->refresh();

        $this->assertSame('completed', $import->status);
        $this->assertNotEmpty($import->errors);
        $this->assertSame('system', $import->errors[0]['sheet']);
        $this->assertStringContainsString('image', $import->errors[0]['error_message']);

        Event::assertDispatched(function (FileOperationEvent $event) {
            return $event->eventName === FileOperationEvent::PRODUCT_IMPORT_COMPLETED
                && $event->payload['download_available'] === true
                && $event->payload['has_errors'] === true;
        });
    }

    /**
     * The deferred finalizer may write `cancelled` before the cancel request
     * arrives; the endpoint must answer success instead of a 409.
     */
    public function test_cancel_endpoint_is_idempotent_when_the_finalizer_already_cancelled(): void
    {
        $user = $this->makeAdmin();

        $import = Import::create([
            'type' => 'product-import',
            'file_name' => 'products.xlsx',
            'status' => 'cancelled',
            'total_rows' => 1,
            'processed_rows' => 1,
            'success_rows' => 1,
            'failed_rows' => 0,
            'created_by' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $resp = $this->postJson(self::PREFIX . "/products/import/{$import->id}/cancel");

        $this->assertSame(200, $resp->getStatusCode(), $resp->getContent());
        $import->refresh();
        $this->assertSame('cancelled', $import->status);
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
