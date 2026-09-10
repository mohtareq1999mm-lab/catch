<?php

namespace Tests\Feature\Digital;

use App\Models\DigitalAsset;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Marvel\Enums\ItemType;
use Marvel\Enums\Permission;
use Marvel\Enums\ProductType;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class DigitalAssetAdminTest extends TestCase
{
    use CreatesTestTables;

    private const PREFIX = '/api/v1';

    private User $admin;
    private User $viewOnly;

    protected function setUp(): void
    {
        if (!class_exists('CodeZero\UniqueTranslation\UniqueTranslationRule')) {
            require_once __DIR__ . '/../Stubs/UniqueTranslationRuleStub.php';
        }

        parent::setUp();

        app()->setLocale('en');
        Storage::fake('private');

        $this->createAllTestTables();

        foreach ([Permission::VIEW_PRODUCTS, Permission::CREATE_PRODUCT, Permission::UPDATE_PRODUCT] as $perm) {
            SpatiePermission::firstOrCreate(['name' => $perm, 'guard_name' => 'api']);
        }

        $this->admin = User::create([
            'name' => 'Asset Admin',
            'email' => 'asset-admin-' . uniqid() . '@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('Password123!'),
            'is_active' => true,
            'type' => 'admin',
        ]);
        $this->admin->givePermissionTo([Permission::VIEW_PRODUCTS, Permission::CREATE_PRODUCT, Permission::UPDATE_PRODUCT]);

        $this->viewOnly = User::create([
            'name' => 'View Only',
            'email' => 'asset-view-' . uniqid() . '@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('Password123!'),
            'is_active' => true,
            'type' => 'admin',
        ]);
        $this->viewOnly->givePermissionTo(Permission::VIEW_PRODUCTS);
    }

    private function makeProduct(string $itemType = ItemType::DIGITAL): Product
    {
        return Product::create([
            'name' => ['en' => 'Digital Asset Product'],
            'slug' => 'asset-product-' . Str::random(8),
            'description' => ['en' => 'Desc'],
            'price' => 20.00,
            'product_type' => ProductType::SIMPLE,
            'item_type' => $itemType,
            'status' => 1,
            'in_stock' => true,
        ]);
    }

    private function pdfUpload(int $kb = 100): UploadedFile
    {
        // Real PDF-shaped bytes: the W4 pipeline inspects actual content
        // (DIG-004), so client-labeled dummy bytes no longer qualify.
        $head = "%PDF-1.4\n";
        $tail = "\n%%EOF";
        $bytes = $head . str_repeat('%', max(0, $kb * 1024 - strlen($head) - strlen($tail))) . $tail;

        return UploadedFile::fake()->createWithContent('manual.pdf', $bytes);
    }

    public function test_unauthenticated_upload_is_rejected()
    {
        $product = $this->makeProduct();

        $this->postJson(self::PREFIX . "/products/{$product->id}/digital-assets", [
            'file' => $this->pdfUpload(),
        ])->assertStatus(401);
    }

    public function test_view_only_admin_cannot_upload()
    {
        $product = $this->makeProduct();
        Sanctum::actingAs($this->viewOnly, ['*']);

        $this->postJson(self::PREFIX . "/products/{$product->id}/digital-assets", [
            'file' => $this->pdfUpload(),
        ])->assertStatus(403);
    }

    public function test_authorized_admin_uploads_pdf_to_digital_product()
    {
        Storage::fake('private');

        $product = $this->makeProduct(ItemType::DIGITAL);
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson(self::PREFIX . "/products/{$product->id}/digital-assets", [
            'file' => $this->pdfUpload(),
            'original_name' => 'User Manual',
        ]);

        $response->assertStatus(201);

        // Row persisted and bound to the product.
        $this->assertDatabaseHas('digital_assets', [
            'product_id' => $product->id,
            'original_name' => 'User Manual',
            'mime' => 'application/pdf',
        ]);

        // File lives on the PRIVATE disk only.
        $asset = DigitalAsset::where('product_id', $product->id)->first();
        $this->assertSame('private', $asset->disk);
        $this->assertTrue(Storage::disk('private')->exists($asset->path));
        $this->assertStringStartsWith("digital-assets/{$product->id}/", $asset->path);

        // Randomized stored name; original name is metadata only.
        $this->assertNotSame('manual.pdf', basename($asset->path));

        // No storage path leaks through the API response.
        $this->assertStringNotContainsString($asset->path, $response->getContent());
    }

    public function test_invalid_mime_is_rejected()
    {
        $product = $this->makeProduct(ItemType::DIGITAL);
        Sanctum::actingAs($this->admin, ['*']);

        $notPdf = UploadedFile::fake()->create('notes.txt', 50, 'text/plain');

        $this->postJson(self::PREFIX . "/products/{$product->id}/digital-assets", [
            'file' => $notPdf,
        ])->assertStatus(422);
    }

    public function test_oversized_file_is_rejected()
    {
        config(['digital.max_upload_kb' => 10]);

        $product = $this->makeProduct(ItemType::DIGITAL);
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson(self::PREFIX . "/products/{$product->id}/digital-assets", [
            'file' => $this->pdfUpload(64),
        ])->assertStatus(422);

        $this->assertSame(0, DigitalAsset::where('product_id', $product->id)->count());
    }

    public function test_physical_product_rejects_asset_upload()
    {
        $physical = $this->makeProduct(ItemType::PHYSICAL);
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson(self::PREFIX . "/products/{$physical->id}/digital-assets", [
            'file' => $this->pdfUpload(),
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, DigitalAsset::where('product_id', $physical->id)->count());
    }

    public function test_metadata_update_and_delete_remove_row_and_file()
    {
        Storage::fake('private');

        $product = $this->makeProduct(ItemType::DIGITAL);
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson(self::PREFIX . "/products/{$product->id}/digital-assets", [
            'file' => $this->pdfUpload(),
        ])->assertStatus(201);

        $asset = DigitalAsset::where('product_id', $product->id)->first();
        $this->assertTrue(Storage::disk('private')->exists($asset->path));

        $this->putJson(self::PREFIX . "/digital-assets/{$asset->uuid}", [
            'original_name' => 'Renamed Guide',
            'sort_order' => 3,
        ])->assertStatus(200);

        $fresh = $asset->fresh();
        $this->assertSame('Renamed Guide', $fresh->original_name);
        $this->assertSame(3, (int) $fresh->sort_order);

        $this->deleteJson(self::PREFIX . "/digital-assets/{$asset->uuid}")->assertStatus(200);

        $this->assertDatabaseMissing('digital_assets', ['id' => $asset->id]);
        $this->assertFalse(Storage::disk('private')->exists($fresh->path));
    }
}
