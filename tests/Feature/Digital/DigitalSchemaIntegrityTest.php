<?php

namespace Tests\Feature\Digital;

use App\Models\DigitalAsset;
use App\Models\DigitalEntitlement;
use App\Models\DigitalLicenseKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderProduct;
use Marvel\Database\Models\Product;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

/**
 * Workstream 3 — SCHEMA CAPABILITY evidence.
 *
 * Proves the database can represent the target architecture (URL assets,
 * license pools, encrypted-at-rest secrets, expiration) through direct
 * state transitions ONLY. NO business behavior lives here: no delivery,
 * no reveal service, no SSRF validation, no enforcement logic.
 */
class DigitalSchemaIntegrityTest extends TestCase
{
    use CreatesTestTables;

    private Product $product;

    protected function setUp(): void
    {
        if (!class_exists('CodeZero\UniqueTranslation\UniqueTranslationRule')) {
            require_once __DIR__ . '/../Stubs/UniqueTranslationRuleStub.php';
        }

        parent::setUp();

        app()->setLocale('en');
        $this->createAllTestTables();

        if (DB::getDriverName() === 'sqlite') {
            // Cascades and FK rejections are part of the contract under test.
            DB::statement('PRAGMA foreign_keys = ON');
        }

        $this->product = Product::create([
            'name' => ['en' => 'W3 Schema Product'],
            'slug' => 'w3-schema-' . uniqid(),
            'description' => ['en' => 'schema'],
            'price' => 15.00,
            'item_type' => 'DIGITAL',
        ]);
    }

    private function fileAsset(array $overrides = []): DigitalAsset
    {
        return DigitalAsset::create(array_merge([
            'product_id' => $this->product->id,
            'type' => 'FILE',
            'disk' => 'private',
            'path' => "digital-assets/{$this->product->id}/file.pdf",
            'original_name' => 'file',
            'mime' => 'application/pdf',
            'size' => 100,
        ], $overrides));
    }

    /* ------------------------------------------------------------------
     | Schema matrix & defaults
     * ----------------------------------------------------------------- */

    public function test_digital_assets_target_columns_exist_with_correct_semantics()
    {
        $columns = Schema::getColumnListing('digital_assets');

        foreach (['display_name', 'extension', 'checksum', 'status', 'metadata', 'external_url', 'secret', 'expires_at'] as $c) {
            $this->assertContains($c, $columns, "{$c} column missing");
        }
    }

    public function test_new_rows_default_to_active_file_status()
    {
        $asset = $this->fileAsset();

        // Prove the DATABASE default: the inserted row (not just the
        // in-memory model) carries status='active'.
        $this->assertSame('active', $asset->refresh()->status);
        $this->assertSame('FILE', $asset->type);
        $this->assertNull($asset->expires_at);
        $this->assertNull($asset->checksum);
    }

    public function test_legacy_style_pdf_row_still_representable()
    {
        // Old-schema shape: exactly the columns the MVP pipeline wrote.
        DB::table('digital_assets')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'product_id' => $this->product->id,
            'type' => 'FILE',
            'disk' => 'private',
            'path' => "digital-assets/{$this->product->id}/legacy.pdf",
            'original_name' => 'Legacy Manual',
            'mime' => 'application/pdf',
            'size' => 4096,
            'sort_order' => 0,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('digital_assets')->where('product_id', $this->product->id)->first();

        $this->assertSame('Legacy Manual', $row->original_name);
        $this->assertSame('application/pdf', $row->mime);
        $this->assertSame(4096, (int) $row->size);
        $this->assertNull($row->external_url);
        $this->assertNull($row->secret);
    }

    /* ------------------------------------------------------------------
     | URL-type representation (no fetch / no delivery — schema only)
     * ----------------------------------------------------------------- */

    public function test_url_asset_persists_with_null_path_and_external_url()
    {
        $asset = $this->fileAsset([
            'type' => 'URL',
            'path' => null,
            'disk' => 'private',
            'mime' => 'text/html',
            'size' => 0,
            'original_name' => 'Course Portal',
            'external_url' => 'https://example.com/resource',
        ]);

        $raw = DB::table('digital_assets')->where('id', $asset->id)->first();

        $this->assertNull($raw->path);
        $this->assertSame('https://example.com/resource', $raw->external_url);
        $this->assertSame('URL', $raw->type);
    }

    /* ------------------------------------------------------------------
     | License key pool lifecycle (state transitions only)
     * ----------------------------------------------------------------- */

    private function licenseAsset(): DigitalAsset
    {
        return $this->fileAsset([
            'type' => 'LICENSE',
            'path' => null,
            'mime' => 'text/plain',
            'size' => 0,
            'original_name' => 'License Pool',
        ]);
    }

    private function entitlement(): DigitalEntitlement
    {
        $order = Order::create(['user_id' => $this->customer()->id]);
        $item = OrderProduct::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'item_type' => 'DIGITAL',
            'product_quantity' => 1,
        ]);

        return DigitalEntitlement::create([
            'order_id' => $order->id,
            'order_product_id' => $item->id,
            'user_id' => $this->customer()->id,
            'status' => DigitalEntitlement::STATUS_DELIVERED,
            'delivered_at' => now(),
        ]);
    }

    private $customerInstance = null;

    private function customer()
    {
        if ($this->customerInstance === null) {
            $this->customerInstance = \Marvel\Database\Models\User::create([
                'name' => 'W3 Customer',
                'email' => 'w3-customer-' . uniqid() . '@example.com',
                'password' => bcrypt('x'),
                'type' => 'customer',
            ]);
        }

        return $this->customerInstance;
    }

    public function test_license_key_pool_lifecycle_transitions_are_representable()
    {
        $key = DigitalLicenseKey::create([
            'asset_id' => $this->licenseAsset()->id,
            'encrypted_key' => 'SERIAL-2026-W3-0001',
        ]);

        $this->assertSame(DigitalLicenseKey::STATUS_AVAILABLE, $key->status);
        $this->assertNotNull($key->uuid);

        // available → assigned
        $key->forceFill([
            'status' => DigitalLicenseKey::STATUS_ASSIGNED,
            'allocated_entitlement_id' => $this->entitlement()->id,
            'assigned_at' => now(),
        ])->save();
        $this->assertDatabaseHas('digital_license_keys', [
            'id' => $key->id,
            'status' => 'assigned',
        ]);
        $this->assertNotNull($key->refresh()->assigned_at);

        // assigned → consumed (one-time reveal/consumption tracking fields)
        $key->forceFill([
            'status' => DigitalLicenseKey::STATUS_CONSUMED,
            'revealed_at' => now(),
            'consumed_at' => now(),
        ])->save();
        $this->assertDatabaseHas('digital_license_keys', [
            'id' => $key->id,
            'status' => 'consumed',
        ]);

        // consumed → revoked
        $key->forceFill([
            'status' => DigitalLicenseKey::STATUS_REVOKED,
            'revoked_at' => now(),
        ])->save();
        $this->assertDatabaseHas('digital_license_keys', [
            'id' => $key->id,
            'status' => 'revoked',
        ]);
        $this->assertNotNull($key->refresh()->revealed_at);
        $this->assertNotNull($key->refresh()->consumed_at);
        $this->assertNotNull($key->refresh()->revoked_at);
    }

    public function test_license_keys_are_encrypted_at_rest_and_never_serialized()
    {
        $plaintext = 'ACTIVATION-CODE-DO-NOT-LEAK';

        $key = DigitalLicenseKey::create([
            'asset_id' => $this->licenseAsset()->id,
            'encrypted_key' => $plaintext,
        ]);

        // Raw stored value must NOT be plaintext.
        $raw = DB::table('digital_license_keys')->where('id', $key->id)->value('encrypted_key');
        $this->assertNotNull($raw);
        $this->assertStringNotContainsString($plaintext, (string) $raw);

        // Cast round-trip restores the exact secret.
        $this->assertSame($plaintext, $key->refresh()->encrypted_key);

        // Never serialized through the model defaults.
        $this->assertArrayNotHasKey('encrypted_key', $key->toArray());
    }

    public function test_asset_secret_uses_encrypted_cast()
    {
        $plaintext = 'ACCESS-TOKEN-SECRET-VALUE';

        $asset = $this->fileAsset(['type' => 'ACCESS', 'secret' => $plaintext]);

        $raw = DB::table('digital_assets')->where('id', $asset->id)->value('secret');
        $this->assertStringNotContainsString($plaintext, (string) $raw);
        $this->assertSame($plaintext, $asset->refresh()->secret);

        // Secret must be hidden from serialization like path/disk.
        $this->assertArrayNotHasKey('secret', $asset->toArray());
        $this->assertArrayNotHasKey('path', $asset->toArray());
    }

    public function test_duplicate_license_key_uuid_is_rejected()
    {
        $assetId = $this->licenseAsset()->id;
        $uuid = (string) \Illuminate\Support\Str::uuid();

        DB::table('digital_license_keys')->insert([
            'uuid' => $uuid,
            'asset_id' => $assetId,
            'encrypted_key' => 'enc-a',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('digital_license_keys')->insert([
            'uuid' => $uuid,
            'asset_id' => $assetId,
            'encrypted_key' => 'enc-b',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_license_key_rejects_unknown_asset()
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('digital_license_keys')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'asset_id' => 999999,
            'encrypted_key' => 'enc-x',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /* ------------------------------------------------------------------
     | Entitlement expiration representation
     * ----------------------------------------------------------------- */

    public function test_entitlement_accepts_future_and_past_expiration_timestamps()
    {
        $future = $this->entitlement();
        $future->forceFill(['expires_at' => now()->addDays(30)])->save();

        $past = $this->entitlement();
        $past->forceFill(['expires_at' => now()->subDays(30)])->save();

        $this->assertTrue($future->refresh()->expires_at->isFuture());
        $this->assertTrue($past->refresh()->expires_at->isPast());

        // NULL expiry must remain representable (current behavior unchanged).
        $openEnded = $this->entitlement();
        $this->assertNull($openEnded->refresh()->expires_at);
    }

    /* ------------------------------------------------------------------
     | Cascade behavior
     * ----------------------------------------------------------------- */

    public function test_deleting_asset_cascades_to_its_license_keys()
    {
        $asset = $this->licenseAsset();
        $otherAsset = $this->licenseAsset();

        foreach ([$asset, $otherAsset] as $a) {
            DigitalLicenseKey::create(['asset_id' => $a->id, 'encrypted_key' => 'k-' . $a->id]);
        }

        $asset->delete();

        $remaining = DigitalLicenseKey::query()->pluck('asset_id');
        $this->assertFalse($remaining->contains($asset->id), 'deleted asset keys must cascade');
        $this->assertTrue($remaining->contains($otherAsset->id), 'unrelated keys must survive');
    }

    public function test_deleting_entitlement_sets_allocation_null_but_keeps_the_key()
    {
        $entitlement = $this->entitlement();
        $asset = $this->licenseAsset();

        $key = DigitalLicenseKey::create([
            'asset_id' => $asset->id,
            'encrypted_key' => 'pool-key',
            'status' => DigitalLicenseKey::STATUS_ASSIGNED,
            'allocated_entitlement_id' => $entitlement->id,
            'assigned_at' => now(),
        ]);

        $entitlement->delete();
        $key->refresh();

        $this->assertNull($key->allocated_entitlement_id, 'allocation pointer must clear');
        $this->assertSame(DigitalLicenseKey::STATUS_ASSIGNED, $key->status, 'inventory row itself must survive');
    }

    public function test_existing_pivot_and_product_cascades_remain_intact()
    {
        $asset = $this->fileAsset();
        $entitlement = $this->entitlement();
        $entitlement->assets()->attach($asset->id);

        $this->assertDatabaseHas('digital_asset_entitlement', [
            'digital_entitlement_id' => $entitlement->id,
            'digital_asset_id' => $asset->id,
        ]);

        // Product deletion cascades assets AND their pivot rows. Products
        // soft-delete, so a physical cascade requires forceDelete.
        $this->product->forceDelete();

        $this->assertDatabaseMissing('digital_assets', ['id' => $asset->id]);
        $this->assertDatabaseMissing('digital_asset_entitlement', ['digital_asset_id' => $asset->id]);

        // Documented pre-existing contract: entitlements hang off
        // order_products (UNIQUE FK), which cascade from the product.
        // Physical product removal therefore cascades the whole line:
        // products → order_products → digital_entitlements.
        $this->assertDatabaseMissing('digital_entitlements', ['id' => $entitlement->id]);
    }
}
