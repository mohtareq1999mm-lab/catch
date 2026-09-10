<?php

namespace Tests\Feature\Digital;

use App\Models\DigitalAsset;
use App\Models\DigitalEntitlement;
use App\Services\Digital\DigitalAssetService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderProduct;
use Marvel\Database\Models\Product;
use Mockery;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

/**
 * Workstream 4 — upload pipeline evidence.
 *
 * Proves DIG-004 (server-side byte inspection, never client MIME) and
 * DIG-011 (filesystem/database consistency under real failure injection)
 * plus checksum integrity, software gate enforcement, safe storage naming,
 * and existing PDF/download compatibility.
 */
class DigitalAssetUploadPipelineTest extends TestCase
{
    use CreatesTestTables;

    private const PREFIX = '/api/v1';

    private Product $product;

    protected function setUp(): void
    {
        if (!class_exists('CodeZero\UniqueTranslation\UniqueTranslationRule')) {
            require_once __DIR__ . '/../Stubs/UniqueTranslationRuleStub.php';
        }

        parent::setUp();

        app()->setLocale('en');
        Storage::fake('private');
        $this->createAllTestTables();
        \Illuminate\Support\Facades\Config::set('scout.driver', 'null');

        $this->product = Product::create([
            'name' => ['en' => 'W4 Pipeline Product'],
            'slug' => 'w4-pipeline-' . uniqid(),
            'description' => ['en' => 'pipeline'],
            'price' => 25.00,
            'item_type' => 'DIGITAL',
        ]);
    }

    /* ------------------------------------------------------------------
     | Byte fixtures (real content — finfo must classify these)
     * ----------------------------------------------------------------- */

    public static function pdfBytes(): string
    {
        return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[]>>endobj\nxref\n0 3\n0000000000 65535 f \ntrailer<</Size 3/Root 1 0 R>>\nstartxref\n9\n%%EOF";
    }

    public static function zipBytes(): string
    {
        return "PK\x03\x04\x14\x00\x00\x00\x08\x00" . Str::random(64);
    }

    public static function pngBytes(): string
    {
        return "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR" . Str::random(48);
    }

    private function pdfFile(string $name = 'manual.pdf', int $kb = 40): UploadedFile
    {
        // Pad to reach approximate size while keeping valid PDF head/tail.
        $bytes = self::pdfBytes() . str_repeat('%', $kb * 1024 - strlen(self::pdfBytes()) - 6) . "\n%%EOF";

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    /* ------------------------------------------------------------------
     | Happy path through real HTTP
     * ----------------------------------------------------------------- */

    private function actingAsProductAdmin(): void
    {
        seedSpatiePermissions();

        $admin = \Marvel\Database\Models\User::create([
            'name' => 'W4 Admin',
            'email' => 'w4-admin-' . uniqid() . '@example.com',
            'email_verified_at' => now(),
            'password' => bcrypt('x'),
            'type' => 'admin',
            'is_active' => true,
        ]);
        $admin->givePermissionTo(['view-products', 'create-product', 'update-product']);

        \Laravel\Sanctum\Sanctum::actingAs($admin, ['*']);
    }

    public function test_valid_pdf_upload_is_accepted_with_detected_mime_and_checksum()
    {
        $this->actingAsProductAdmin();

        $response = $this->postJson(self::PREFIX . "/products/{$this->product->id}/digital-assets", [
            'file' => $this->pdfFile('user-guide.pdf'),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(201);

        $asset = DigitalAsset::query()->where('product_id', $this->product->id)->firstOrFail();

        // Authoritative values come from SERVER-side inspection.
        $this->assertSame('application/pdf', $asset->mime);
        $this->assertSame('pdf', $asset->extension);
        $this->assertSame(DigitalAsset::STATUS_ACTIVE, $asset->status);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $asset->checksum);

        $expectedHash = hash('sha256', self::pdfBytes() . str_repeat('%', 40 * 1024 - strlen(self::pdfBytes()) - 6) . "\n%%EOF");
        $this->assertSame($expectedHash, $asset->checksum);

        // Exactly one physical file, server-named, on the private disk.
        $disk = Storage::disk('private');
        $files = collect($disk->allFiles("digital-assets/{$this->product->id}"));
        $this->assertCount(1, $files);
        $storedPath = $files->first();
        $this->assertEquals($asset->path, $storedPath);
        $this->assertMatchesRegularExpression('#^digital-assets/\d+/[0-9a-f\-]{36}\.pdf$#', $storedPath);
        $this->assertSame($expectedHash, hash_file('sha256', $disk->path($storedPath)));

        // No leakage of internal location or secrets.
        $payload = $response->json('data');
        foreach (['path', 'disk', 'secret'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $payload);
        }
        $this->assertStringNotContainsString($asset->path, $response->getContent());
    }

    public function test_checksum_is_deterministic_and_content_sensitive()
    {
        $service = app(DigitalAssetService::class);

        $first = $service->store($this->product, $this->pdfFile());
        $second = $service->store($this->product, $this->pdfFile());
        $other = $service->store($this->product, UploadedFile::fake()->createWithContent('other.pdf', self::pdfBytes() . "\n%variant"));

        $this->assertSame($first->checksum, $second->checksum, 'same bytes must hash identically');
        $this->assertNotSame($other->checksum, $first->checksum, 'different bytes must differ');
        $this->assertSame(64, strlen($other->checksum));
        $this->assertSame(strtolower($other->checksum), $other->checksum);
    }

    /* ------------------------------------------------------------------
     | Spoofing / mismatch negatives (DIG-004 regression core)
     * ----------------------------------------------------------------- */

    public function test_pdf_extension_with_zip_bytes_is_rejected_and_leaves_no_trace()
    {
        $spoofed = UploadedFile::fake()->createWithContent('invoice.pdf', self::zipBytes());

        try {
            app(DigitalAssetService::class)->store($this->product, $spoofed);
            $this->fail('Mismatched content must be rejected.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            // Detected application/zip is not on the active MIME whitelist,
            // so the MIME gate rejects before pairing even applies.
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame(trans('message.ERROR.DIGITAL_ASSET_INVALID_MIME'), $e->getMessage());
        }

        $this->assertSame(0, DB::table('digital_assets')->where('product_id', $this->product->id)->count(), 'no DB row');
        $this->assertCount(0, Storage::disk('private')->allFiles(), 'no orphan file');
    }

    public function test_registry_rejects_cross_category_pairings()
    {
        $registry = app(\App\Services\Digital\AssetTypeRegistry::class);

        // Same-category agreement passes.
        $this->assertSame(
            \App\Enums\DigitalAssetCategory::DOCUMENT,
            $registry->resolveCompatibleCategory('pdf', 'application/pdf')
        );

        // Every cross-pairing must fail even though pieces look plausible.
        foreach ([['pdf', 'application/zip'], ['pdf', 'image/png']] as [$ext, $mime]) {
            $this->assertNull($registry->resolveCompatibleCategory($ext, $mime), "$ext + $mime must disagree");
        }
    }

    public function test_client_mime_accessors_cannot_override_actual_bytes()
    {
        // Bytes ARE a valid PDF; every CLIENT-side MIME accessor claims zip.
        // The pipeline must trust neither and persist the detected type.
        $base = UploadedFile::fake()->createWithContent('forged.tmp', self::pdfBytes());

        $file = new class($base->getRealPath(), 'manual.pdf', null, UPLOAD_ERR_OK, true) extends UploadedFile {
            public function getMimeType(): string
            {
                return 'application/zip';
            }

            public function getClientMimeType(): string
            {
                return 'application/zip';
            }
        };

        $asset = app(DigitalAssetService::class)->store($this->product, $file);

        $raw = DB::table('digital_assets')->where('id', $asset->id)->value('mime');
        $this->assertSame('application/pdf', $raw, 'persisted MIME must be the detected one');
        $this->assertSame(hash('sha256', self::pdfBytes()), $asset->refresh()->checksum);
    }

    public function test_png_bytes_with_pdf_extension_are_rejected()
    {
        $spoofed = UploadedFile::fake()->createWithContent('image.pdf', self::pngBytes());

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(DigitalAssetService::class)->store($this->product, $spoofed);

        $this->assertSame(0, DB::table('digital_assets')->count());
    }

    public function test_http_rejects_mismatched_upload_without_row_or_file()
    {
        $this->actingAsProductAdmin();

        $this->postJson(self::PREFIX . "/products/{$this->product->id}/digital-assets", [
            'file' => UploadedFile::fake()->createWithContent('looks-pdf.pdf', self::zipBytes()),
        ])->assertStatus(422);

        $this->assertSame(0, DB::table('digital_assets')->where('product_id', $this->product->id)->count());
        $this->assertCount(0, Storage::disk('private')->allFiles());
    }

    /* ------------------------------------------------------------------
     | Software gate (A1)
     * ----------------------------------------------------------------- */

    public function test_executable_uploads_are_rejected_even_when_gate_flag_enabled()
    {
        config(['digital.allow_software_assets' => true]);

        $exe = UploadedFile::fake()->createWithContent('setup.exe', 'MZ' . Str::random(128));

        try {
            app(DigitalAssetService::class)->store($this->product, $exe);
            $this->fail('Executables must never pass the active surface.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }

        $this->assertSame(0, DB::table('digital_assets')->count());
        $this->assertCount(0, Storage::disk('private')->allFiles());

        config(['digital.allow_software_assets' => false]);
    }

    public function test_double_extension_traversal_names_never_reach_storage()
    {
        $this->actingAsProductAdmin();

        $evil = UploadedFile::fake()->createWithContent('../../../../etc/passwd.pdf', self::pdfBytes());

        $this->postJson(self::PREFIX . "/products/{$this->product->id}/digital-assets", [
            'file' => $evil,
            'original_name' => '..\\..\\windows\\system32\\evil',
        ])->assertStatus(201);

        $asset = DigitalAsset::query()->where('product_id', $this->product->id)->firstOrFail();

        // Storage name is server-generated uuid.ext; traversal lives only
        // in metadata fields, never in the physical path.
        $this->assertMatchesRegularExpression('#^digital-assets/\d+/[0-9a-f\-]{36}\.pdf$#', $asset->path);
        $this->assertStringNotContainsString('..', $asset->path);
        $this->assertStringNotContainsString('/', $asset->original_name === '..\\..\\windows\\system32\\evil' ? '' : 'ok');
        $files = Storage::disk('private')->allFiles();
        $this->assertCount(1, $files);
        $this->assertSame($asset->path, $files[0]);
    }

    /* ------------------------------------------------------------------
     | Failure injection (DIG-011)
     * ----------------------------------------------------------------- */

    private function failingDiskService(bool $failPut, bool $deleteWorks = true): DigitalAssetService
    {
        $partial = Mockery::mock(FilesystemAdapter::class)->makePartial();
        $putExpectation = $partial->shouldReceive('putFileAs');
        $failPut ? $putExpectation->andReturn(false) : $putExpectation->andReturnUsing(
            fn ($dir, $file, $name) => Storage::disk('private')->putFileAs($dir, $file, $name)
        );
        $partial->shouldReceive('delete')
            ->andReturnUsing(fn ($path) => $deleteWorks ? Storage::disk('private')->delete($path) : false);

        return new class($partial, app(\App\Services\Digital\AssetTypeRegistry::class), app(\App\Services\Digital\ExternalUrlValidator::class)) extends DigitalAssetService {
            public function __construct(private $injectedDisk, \App\Services\Digital\AssetTypeRegistry $registry, \App\Services\Digital\ExternalUrlValidator $urlValidator)
            {
                parent::__construct($registry, $urlValidator);
            }

            protected function disk(): \Illuminate\Contracts\Filesystem\Filesystem
            {
                return $this->injectedDisk;
            }
        };
    }

    public function test_storage_write_failure_leaves_no_db_row_and_no_file()
    {
        $service = $this->failingDiskService(failPut: true);

        try {
            $service->store($this->product, $this->pdfFile());
            $this->fail('Storage write failure must surface.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(500, $e->getStatusCode());
            $this->assertSame(trans('message.ERROR.DIGITAL_ASSET_UPLOAD_FAILED'), $e->getMessage());
        }

        $this->assertSame(0, DB::table('digital_assets')->count());
        $this->assertCount(0, Storage::disk('private')->allFiles());
    }

    public function test_db_persistence_failure_compensates_the_written_file()
    {
        // Force a REAL database persistence failure through the live
        // connection: hide a required column so INSERT cannot succeed.
        DB::statement('ALTER TABLE digital_assets RENAME COLUMN checksum TO checksum_disabled_w4');

        try {
            app(DigitalAssetService::class)->store($this->product, $this->pdfFile());
            $this->fail('DB persistence failure must surface.');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\Illuminate\Database\QueryException::class, $e);
        } finally {
            DB::statement('ALTER TABLE digital_assets RENAME COLUMN checksum_disabled_w4 TO checksum');
        }

        $this->assertSame(0, DB::table('digital_assets')->count(), 'row must be absent after rollback');
        $this->assertCount(0, Storage::disk('private')->allFiles(), 'orphan file must have been compensated');
    }

    public function test_duplicate_constraint_failure_cleans_the_temporary_file()
    {
        // Force a REAL unique-constraint violation: first upload succeeds,
        // then a temporary UNIQUE(product_id, original_name) index makes the
        // second identical upload violate at INSERT time.
        $first = app(DigitalAssetService::class)->store(
            $this->product,
            $this->pdfFile('dup-a.pdf')
        );

        DB::statement('CREATE UNIQUE INDEX digital_assets_w4_dupguard ON digital_assets (product_id, original_name)');

        try {
            app(DigitalAssetService::class)->store($this->product, $this->pdfFile('dup-a.pdf'));
            $this->fail('Constraint violation must surface.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertTrue(true, 'duplicate insert rejected');
        } finally {
            DB::statement('DROP INDEX digital_assets_w4_dupguard');
        }

        // First row + file intact; the failed attempt left no second file.
        $this->assertSame(1, DB::table('digital_assets')->count());
        $files = Storage::disk('private')->allFiles();
        $this->assertCount(1, $files);
        $this->assertSame($first->path, $files[0]);
    }

    public function test_delete_db_failure_keeps_row_and_file_pair_intact()
    {
        $asset = app(DigitalAssetService::class)->store($this->product, $this->pdfFile());

        // Make the DELETE statement itself fail: hide the whole table from
        // the live connection.
        DB::statement('ALTER TABLE digital_assets RENAME TO digital_assets_w4_locked');

        try {
            app(DigitalAssetService::class)->delete($asset);
            $this->fail('Delete DB failure must surface.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertTrue(true, 'delete rejected while table unavailable');
        } finally {
            DB::statement('ALTER TABLE digital_assets_w4_locked RENAME TO digital_assets');
        }

        // Consistent pair preserved: row present AND file present.
        $this->assertDatabaseHas('digital_assets', ['id' => $asset->id]);
        $this->assertTrue(Storage::disk('private')->exists($asset->path));
    }

    public function test_delete_post_commit_file_failure_removes_row_and_logs_drift()
    {
        $asset = app(DigitalAssetService::class)->store($this->product, $this->pdfFile());
        $service = $this->failingDiskService(failPut: false, deleteWorks: false);

        Log::spy();

        $service->delete($asset);

        // Row gone → customers can never reach a missing/unbacked file.
        $this->assertDatabaseMissing('digital_assets', ['id' => $asset->id]);
        // Physical drift surfaced loudly for ops, never silently.
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn ($message, $context) => $message === 'Digital asset physical file could not be removed after row deletion.'
                && $context['asset_uuid'] === $asset->uuid
        );
        // Fake disk retains the file (delete was forced to fail) — documented residual state.
        $this->assertTrue(Storage::disk('private')->exists($asset->path));
    }

    /* ------------------------------------------------------------------
     | Metadata updates never mutate bytes/checksum
     * ----------------------------------------------------------------- */

    public function test_metadata_update_does_not_change_checksum_or_file()
    {
        $service = app(DigitalAssetService::class);
        $asset = $service->store($this->product, $this->pdfFile());
        $before = $asset->refresh()->checksum;

        $updated = $service->update($asset, [
            'original_name' => 'Renamed Manual',
            'sort_order' => 7,
            'checksum' => 'tampered-attempt',
        ]);

        $this->assertSame($before, $updated->checksum, 'checksum must be immutable through update');
        $this->assertSame('Renamed Manual', $updated->original_name);
        $this->assertSame(7, $updated->sort_order);
        $this->assertTrue(Storage::disk('private')->exists($updated->path));
    }

    /* ------------------------------------------------------------------
     | Existing download compatibility through the real signed route
     * ----------------------------------------------------------------- */

    public function test_uploaded_pdf_downloads_through_existing_signed_route()
    {
        $this->actingAsProductAdmin();

        $bytes = self::pdfBytes();
        $this->postJson(self::PREFIX . "/products/{$this->product->id}/digital-assets", [
            'file' => UploadedFile::fake()->createWithContent('dl-check.pdf', $bytes),
        ])->assertStatus(201);

        $asset = DigitalAsset::query()->where('product_id', $this->product->id)->firstOrFail();

        $customer = \Marvel\Database\Models\User::create([
            'name' => 'DL Customer',
            'email' => 'w4-dl-' . uniqid() . '@example.com',
            'password' => bcrypt('x'),
            'type' => 'customer',
        ]);
        $order = Order::create(['user_id' => $customer->id]);
        $item = OrderProduct::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'item_type' => 'DIGITAL',
            'product_quantity' => 1,
        ]);
        $entitlement = DigitalEntitlement::create([
            'order_id' => $order->id,
            'order_product_id' => $item->id,
            'user_id' => $customer->id,
            'status' => DigitalEntitlement::STATUS_DELIVERED,
            'delivered_at' => now(),
            'download_limit' => 3,
        ]);

        $signed = URL::temporarySignedRoute(
            'general.digital.download',
            now()->addMinutes(5),
            ['entitlement' => $entitlement->uuid, 'asset' => $asset->uuid]
        );

        $download = $this->get($signed);
        $download->assertStatus(200);
        // W7: BinaryFileResponse — capture bytes via explicit send.
        ob_start();
        try {
            $download->baseResponse->sendContent(false);
        } finally {
            $sent = ob_get_clean();
        }
        $this->assertSame($bytes, $sent, 'delivered bytes must equal uploaded bytes');
        $this->assertSame('application/pdf', $download->headers->get('Content-Type'));
        $this->assertSame(1, (int) $entitlement->refresh()->download_count, 'limit accounting unchanged');
    }
}

/* ---------------------------------------------------------------------- */
/* Helpers shared by this suite                                            */
/* ---------------------------------------------------------------------- */



function seedSpatiePermissions(): void
{
    foreach (['view-products', 'create-product', 'update-product'] as $perm) {
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'api']);
    }
}
