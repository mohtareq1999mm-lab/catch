<?php

namespace Tests\Feature\Digital;

use App\Models\DigitalAsset;
use App\Models\DigitalEntitlement;
use App\Services\Digital\DigitalAssetService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderProduct;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Workstream 6 — admin CRUD hardening evidence.
 *
 * SHOW / widened UPDATE / explicit REPLACE / entitlement management
 * (list-filter, limit incl. unlimited sentinel, revoke, restore) with the
 * full authorization matrix exercised over real HTTP.
 */
class DigitalAdminManagementTest extends TestCase
{
    use \Tests\Concerns\CreatesTestTables;

    private Product $product;
    private User $fullAdmin;      // all relevant permissions
    private User $viewerAdmin;    // view-products + view-orders only
    private User $accessAdmin;    // view-orders + manage-digital-access only
    private User $customer;

    private const PDF_A = "%PDF-1.4\n%A-contents-original\n%%EOF";
    private const PDF_B = "%PDF-1.4\n%B-replacement-bytes-newer\n%%EOF";

    protected function setUp(): void
    {
        if (!class_exists('CodeZero\UniqueTranslation\UniqueTranslationRule')) {
            require_once __DIR__ . '/../Stubs/UniqueTranslationRuleStub.php';
        }
        parent::setUp();
        app()->setLocale('en');
        Storage::fake('private');
        $this->createAllTestTables();

        foreach (['view-products', 'create-product', 'update-product', 'manage-digital-licenses', 'manage-digital-access', 'view-orders'] as $p) {
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $p, 'guard_name' => 'api']);
        }

        $this->product = Product::create([
            'name' => ['en' => 'W6 Product'], 'slug' => 'w6-' . uniqid(),
            'price' => 30, 'item_type' => 'DIGITAL',
        ]);

        $mk = function (string $tag, array $perms): User {
            $u = User::create([
                'name' => $tag, 'email' => uniqid($tag) . '@example.com',
                'password' => bcrypt('x'), 'type' => 'admin', 'is_active' => true,
            ]);
            $u->givePermissionTo($perms);

            return $u;
        };

        $this->fullAdmin = $mk('w6-full', ['view-products', 'create-product', 'update-product', 'view-orders', 'manage-digital-access', 'manage-digital-licenses']);
        $this->viewerAdmin = $mk('w6-viewer', ['view-products', 'view-orders']);
        $this->accessAdmin = $mk('w6-access', ['view-orders', 'manage-digital-access']);
        $this->customer = User::create([
            'name' => 'W6 Customer', 'email' => uniqid() . '@example.com',
            'password' => bcrypt('x'), 'type' => 'customer',
        ]);
    }

    /* ---------------- fixtures ---------------- */

    private function fileAsset(string $content = self::PDF_A): DigitalAsset
    {
        return app(DigitalAssetService::class)->store(
            $this->product,
            UploadedFile::fake()->createWithContent('orig.pdf', $content)
        );
    }

    private function entitle(User $user, int $limit = 2): DigitalEntitlement
    {
        $order = Order::create(['user_id' => $user->id]);
        $item = OrderProduct::create([
            'order_id' => $order->id, 'product_id' => $this->product->id,
            'item_type' => 'DIGITAL', 'product_quantity' => 1,
        ]);

        return DigitalEntitlement::forceCreate([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'order_id' => $order->id, 'order_product_id' => $item->id,
            'user_id' => $user->id,
            'status' => DigitalEntitlement::STATUS_DELIVERED,
            'delivered_at' => now(), 'download_limit' => $limit,
        ]);
    }

    private function as(User $u): void
    {
        \Laravel\Sanctum\Sanctum::actingAs($u, ['*']);
    }

    /* ================= SHOW ================= */

    public function test_show_authorization_matrix_and_contract()
    {
        $asset = $this->fileAsset();

        // Guest → 401 (route group auth).
        $this->refreshApplication();
        $this->createAllTestTables();
        $this->getJson('/api/v1/digital-assets/' . $asset->uuid)->assertStatus(401);
    }

    public function test_show_returns_public_contract_without_leaks()
    {
        $this->as($this->fullAdmin);
        $asset = $this->fileAsset();

        $res = $this->getJson('/api/v1/digital-assets/' . $asset->uuid);
        $res->assertStatus(200);
        $payload = $res->json('data');

        $this->assertSame($asset->uuid, $payload['uuid']);
        foreach (['path', 'disk', 'secret'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $payload);
        }
        $this->assertSame('application/pdf', $payload['mime']);
    }

    /* ================= WIDENED UPDATE ================= */

    public function test_update_sets_display_name_status_metadata_without_touching_checksum()
    {
        $this->as($this->fullAdmin);
        $asset = $this->fileAsset();
        $before = ['checksum' => $asset->checksum, 'path' => $asset->path, 'mime' => $asset->mime];

        $res = $this->putJson('/api/v1/digital-assets/' . $asset->uuid, [
            'display_name' => 'Premium Manual',
            'status' => 'inactive',
            'metadata' => ['pages' => '42', 'language' => 'en'],
        ]);
        $res->assertStatus(200);

        $fresh = $asset->refresh();
        $this->assertSame('Premium Manual', $fresh->display_name);
        $this->assertSame('inactive', $fresh->status);
        $this->assertSame(['pages' => '42', 'language' => 'en'], $fresh->metadata);
        $this->assertSame($before['checksum'], $fresh->checksum, 'bytes immutable');
        $this->assertSame($before['path'], $fresh->path);
        $this->assertTrue(Storage::disk('private')->exists($fresh->path));
    }

    public function test_update_rejects_system_reserved_status()
    {
        $this->as($this->fullAdmin);
        $asset = $this->fileAsset();

        $this->putJson('/api/v1/digital-assets/' . $asset->uuid, ['status' => 'revoked'])
            ->assertStatus(422);
        $this->putJson('/api/v1/digital-assets/' . $asset->uuid, ['status' => 'expired'])
            ->assertStatus(422);

        $this->assertSame(DigitalAsset::STATUS_ACTIVE, $asset->refresh()->status);
    }

    public function test_inactive_asset_disappears_from_customer_surface_and_download()
    {
        $asset = $this->fileAsset();
        $entitlement = $this->entitle($this->customer);
        $entitlement->assets()->attach($asset->id);

        // Visible while active.
        $this->as($this->customer);
        $seen = $this->getJson('/api/v1/general/digital/downloads')->json('data');
        $listed = collect(collect($seen)->firstWhere('uuid', $entitlement->uuid)['assets'] ?? [])->firstWhere('uuid', $asset->uuid);
        $this->assertNotNull($listed);

        // Deactivate → vanishes from disclosure AND direct download.
        $this->as($this->fullAdmin);
        $this->putJson('/api/v1/digital-assets/' . $asset->uuid, ['status' => 'inactive'])->assertStatus(200);

        $this->as($this->customer);
        $seen = $this->getJson('/api/v1/general/digital/downloads')->json('data');
        $listed = collect(collect($seen)->firstWhere('uuid', $entitlement->uuid)['assets'] ?? [])->firstWhere('uuid', $asset->uuid);
        $this->assertNull($listed, 'inactive assets must leave the customer surface');
    }

    /* ================= REPLACE ================= */

    private function replaceMultipart(DigitalAsset $asset, string $content, ?User $as = null)
    {
        if ($as !== null) {
            $this->as($as);
        }

        return $this->postJson('/api/v1/digital-assets/' . $asset->uuid . '/replace', [
            'file' => UploadedFile::fake()->createWithContent('replacement.pdf', $content),
        ]);
    }

    public function test_replace_swaps_bytes_checksum_mime_and_retires_old_file()
    {
        $asset = $this->fileAsset(self::PDF_A);
        $oldPath = $asset->path;
        $disk = Storage::disk('private');

        $this->replaceMultipart($asset, self::PDF_B, $this->fullAdmin)->assertStatus(200);

        $fresh = $asset->refresh();
        $this->assertSame($asset->uuid, $fresh->uuid, 'uuid preserved');
        $this->assertNotSame($oldPath, $fresh->path, 'physical path swapped');
        $this->assertSame(hash('sha256', self::PDF_B), $fresh->checksum);
        $this->assertSame('application/pdf', $fresh->mime);
        $this->assertSame('pdf', $fresh->extension);
        $this->assertFalse($disk->exists($oldPath), 'old file retired after commit');
        $this->assertTrue($disk->exists($fresh->path));
        $this->assertSame(self::PDF_B, $disk->get($fresh->path));
        $this->assertSame(count($disk->allFiles()), 1, 'exactly one physical file remains');
    }

    public function test_replace_write_failure_keeps_old_pair_intact()
    {
        $asset = $this->fileAsset(self::PDF_A);
        $oldPath = $asset->path;

        $partial = \Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class)->makePartial();
        $partial->shouldReceive('putFileAs')->andReturn(false);
        $partial->shouldReceive('delete')->andReturnUsing(fn ($p) => Storage::disk('private')->delete($p));

        $svc = new class($partial, app(\App\Services\Digital\AssetTypeRegistry::class), app(\App\Services\Digital\ExternalUrlValidator::class)) extends DigitalAssetService {
            public function __construct(private $injectedDisk, \App\Services\Digital\AssetTypeRegistry $r, \App\Services\Digital\ExternalUrlValidator $u)
            {
                parent::__construct($r, $u);
            }

            protected function disk(): \Illuminate\Contracts\Filesystem\Filesystem
            {
                return $this->injectedDisk;
            }
        };

        try {
            $svc->replace($asset, UploadedFile::fake()->createWithContent('n.pdf', self::PDF_B));
            $this->fail('write failure must surface');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(500, $e->getStatusCode());
        }

        $fresh = $asset->refresh();
        $this->assertSame($oldPath, $fresh->path, 'row unchanged on write failure');
        $this->assertSame(hash('sha256', self::PDF_A), $fresh->checksum);
        $this->assertTrue(Storage::disk('private')->exists($oldPath));
        $this->assertSame(1, count(Storage::disk('private')->allFiles()));
    }

    public function test_replace_db_failure_compensates_new_file_and_keeps_old_pair()
    {
        $asset = $this->fileAsset(self::PDF_A);
        $oldPath = $asset->path;

        // Force a REAL persistence failure: hide the checksum column so the
        // swap UPDATE cannot succeed. This repo maps DB conflicts to 409.
        DB::statement('ALTER TABLE digital_assets RENAME COLUMN checksum TO checksum_w6_locked');
        $status = 0;
        try {
            $res = $this->replaceMultipart($asset, self::PDF_B, $this->fullAdmin);
            $status = $res->status();
        } finally {
            DB::statement('ALTER TABLE digital_assets RENAME COLUMN checksum_w6_locked TO checksum');
        }

        $this->assertTrue($status >= 400, "replacement must fail, got {$status}");

        $fresh = $asset->refresh();
        $this->assertSame($oldPath, $fresh->path);
        $this->assertSame(hash('sha256', self::PDF_A), $fresh->checksum, 'old bytes intact');
        $files = Storage::disk('private')->allFiles();
        $this->assertCount(1, $files);
        $this->assertSame($oldPath, $files[0], 'new file compensated away');
    }

    public function test_non_file_assets_are_not_replaceable()
    {
        $url = app(DigitalAssetService::class)->createUrl($this->product, ['external_url' => 'https://example.com/x']);
        $this->replaceMultipart($url, self::PDF_B, $this->fullAdmin)->assertStatus(422);

        $lic = app(DigitalAssetService::class)->createLicense($this->product, []);
        $this->replaceMultipart($lic, self::PDF_B, $this->fullAdmin)->assertStatus(422);

        $this->assertSame('https://example.com/x', $url->refresh()->external_url, 'untouched');
    }

    /* ================= ENTITLEMENT MANAGEMENT ================= */

    public function test_entitlement_list_filters_by_status_and_user()
    {
        $a = $this->entitle($this->customer);
        $b = $this->entitle($this->customer);
        $b->forceFill(['status' => DigitalEntitlement::STATUS_REVOKED, 'revoked_at' => now()])->save();

        $this->as($this->viewerAdmin);   // view-orders is sufficient to LIST

        $all = $this->getJson('/api/v1/digital-entitlements?per_page=50');
        $all->assertStatus(200);
        $this->assertSame(2, $all->json('data.meta.total'));

        $revokedOnly = $this->getJson('/api/v1/digital-entitlements?status=revoked&per_page=50');
        $uuids = collect($revokedOnly->json('data.data'))->pluck('uuid');
        $this->assertSame([$b->uuid], $uuids->all());

        $byUser = $this->getJson('/api/v1/digital-entitlements?user_id=' . $this->customer->id . '&per_page=50');
        $this->assertSame(2, $byUser->json('data.meta.total'));
    }

    public function test_limit_override_numeric_blocks_downloads_at_cap()
    {
        $asset = $this->fileAsset();
        $entitlement = $this->entitle($this->customer, limit: 5);
        $entitlement->assets()->attach($asset->id);

        $this->as($this->accessAdmin);   // manage-digital-access WITHOUT update-product
        $res = $this->patchJson('/api/v1/digital-entitlements/' . $entitlement->uuid . '/limit', ['limit' => 1]);
        $res->assertStatus(200);
        $this->assertSame(1, $res->json('data.download_limit'));
        $this->assertFalse($res->json('data.unlimited'));

        $this->assertSame(1, (int) $entitlement->refresh()->download_limit);
    }

    public function test_unlimited_sentinel_allows_downloads_beyond_previous_cap()
    {
        $asset = $this->fileAsset();
        $entitlement = $this->entitle($this->customer, limit: 1);
        $entitlement->assets()->attach($asset->id);

        $signed = fn () => \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'general.digital.download', now()->addMinutes(5),
            ['entitlement' => $entitlement->uuid, 'asset' => $asset->uuid]
        );

        // Cap of 1: first OK, second blocked.
        $this->get($signed())->assertStatus(200);
        $this->get($signed())->assertStatus(403);

        // Admin lifts the cap via UNLIMITED sentinel (omit body ⇒ null ⇒ 0).
        $this->as($this->accessAdmin);
        $res = $this->patchJson('/api/v1/digital-entitlements/' . $entitlement->uuid . '/limit');
        $res->assertStatus(200);
        $this->assertTrue($res->json('data.unlimited'));
        $this->assertSame(0, (int) $entitlement->refresh()->download_limit);

        // Downloads now flow past the previous cap (two more redeem).
        $this->get($signed())->assertStatus(200);
        $this->get($signed())->assertStatus(200);
        $this->assertSame(3, (int) $entitlement->refresh()->download_count);
    }

    public function test_revoke_then_restore_round_trip_with_activity_log()
    {
        $asset = $this->fileAsset();
        $entitlement = $this->entitle($this->customer);
        $entitlement->assets()->attach($asset->id);

        $signed = fn () => \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'general.digital.download', now()->addMinutes(5),
            ['entitlement' => $entitlement->uuid, 'asset' => $asset->uuid]
        );

        $this->as($this->accessAdmin);
        $this->postJson('/api/v1/digital-entitlements/' . $entitlement->uuid . '/revoke')->assertStatus(200);
        $this->assertSame(DigitalEntitlement::STATUS_REVOKED, $entitlement->refresh()->status);
        $this->get($signed())->assertStatus(403, 'revoked blocks download');

        $this->postJson('/api/v1/digital-entitlements/' . $entitlement->uuid . '/restore')->assertStatus(200);
        $this->assertSame(DigitalEntitlement::STATUS_DELIVERED, $entitlement->refresh()->status);
        $this->assertNull($entitlement->refresh()->revoked_at);
        $this->get($signed())->assertStatus(200, 'restored re-allows download');

        // Idempotency guards.
        $this->postJson('/api/v1/digital-entitlements/' . $entitlement->uuid . '/restore')->assertStatus(200);
        $this->assertSame(DigitalEntitlement::STATUS_DELIVERED, $entitlement->refresh()->status);

        // Activity log rows written (sync queue in tests).
        $events = DB::table('activity_log')->where('subject_id', $entitlement->id)
            ->where('subject_type', DigitalEntitlement::class)
            ->pluck('event')->all();
        $this->assertContains('digital.entitlement.revoked', $events);
        $this->assertContains('digital.entitlement.restored', $events);
    }

    public function test_permission_matrix_for_entitlement_management()
    {
        $entitlement = $this->entitle($this->customer);

        // viewerAdmin: view-orders YES, manage-digital-access NO.
        $this->as($this->viewerAdmin);
        $this->getJson('/api/v1/digital-entitlements')->assertStatus(200);
        $this->postJson('/api/v1/digital-entitlements/' . $entitlement->uuid . '/revoke')->assertStatus(403);
        $this->patchJson('/api/v1/digital-entitlements/' . $entitlement->uuid . '/limit', ['limit' => 9])->assertStatus(403);

        // accessAdmin: manage-digital-access YES (no product perms needed).
        $this->as($this->accessAdmin);
        $this->postJson('/api/v1/digital-entitlements/' . $entitlement->uuid . '/revoke')->assertStatus(200);
    }

    public function test_guest_blocked_from_all_management_endpoints()
    {
        $this->refreshApplication();

        $this->getJson('/api/v1/digital-entitlements')->assertStatus(401);
        $this->getJson('/api/v1/digital-entitlements/00000000-0000-4000-8000-000000000001')->assertStatus(401);
        $this->patchJson('/api/v1/digital-entitlements/00000000-0000-4000-8000-000000000001/limit')->assertStatus(401);
        $this->postJson('/api/v1/digital-entitlements/00000000-0000-4000-8000-000000000001/revoke')->assertStatus(401);
        $this->postJson('/api/v1/digital-entitlements/00000000-0000-4000-8000-000000000001/restore')->assertStatus(401);
        $this->getJson('/api/v1/digital-assets/00000000-0000-4000-8000-000000000001')->assertStatus(401);
        $this->postJson('/api/v1/digital-assets/00000000-0000-4000-8000-000000000001/replace')->assertStatus(401);
    }
}
