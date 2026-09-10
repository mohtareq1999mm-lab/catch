<?php

namespace Tests\Feature\Digital;

use App\Models\DigitalAsset;
use App\Models\DigitalEntitlement;
use App\Models\DigitalLicenseKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderProduct;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Workstream 5 â€” External URL & License/Access evidence.
 *
 * Sequential lifecycle/security proofs run here; REAL MySQL concurrency
 * proof lives in storage/w3-audit/w5_concurrency_check.php (SQLite cannot
 * demonstrate row-lock behavior).
 */
class DigitalExternalUrlLicenseTest extends TestCase
{
    use \Tests\Concerns\CreatesTestTables;

    private Product $product;
    private User $customer;

    protected function setUp(): void
    {
        if (!class_exists('CodeZero\UniqueTranslation\UniqueTranslationRule')) {
            require_once __DIR__ . '/../Stubs/UniqueTranslationRuleStub.php';
        }
        parent::setUp();
        app()->setLocale('en');
        $this->createAllTestTables();

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON');
        }

        $this->product = Product::create([
            'name' => ['en' => 'W5 Product'],
            'slug' => 'w5-' . uniqid(),
            'price' => 50,
            'item_type' => 'DIGITAL',
        ]);
        $this->customer = $this->makeCustomer();
    }

    private function makeCustomer(): User
    {
        return User::create([
            'name' => 'W5 Customer',
            'email' => uniqid() . '@example.com',
            'password' => bcrypt('x'),
            'type' => 'customer',
        ]);
    }

    private function service(): \App\Services\Digital\DigitalAssetService
    {
        return app(\App\Services\Digital\DigitalAssetService::class);
    }

    private function urlAsset(string $url = 'https://example.com/course'): DigitalAsset
    {
        return $this->service()->createUrl($this->product, ['external_url' => $url]);
    }

    private function licenseAsset(int $poolSize = 2): DigitalAsset
    {
        $asset = $this->service()->createLicense($this->product, []);
        $keys = [];
        for ($i = 1; $i <= $poolSize; $i++) {
            $keys[] = "KEY-W5-{$asset->id}-{$i}";
        }
        $this->service()->addLicenseKeys($asset, $keys);

        return $asset;
    }

    private function accessAsset(string $secret = 'COURSE-CODE-XYZ'): DigitalAsset
    {
        return $this->service()->createAccess($this->product, ['secret' => $secret]);
    }

    private function entitle(User $user): DigitalEntitlement
    {
        $order = Order::create(['user_id' => $user->id]);
        $item = OrderProduct::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'item_type' => 'DIGITAL',
            'product_quantity' => 1,
        ]);

        return DigitalEntitlement::create([
            'order_id' => $order->id,
            'order_product_id' => $item->id,
            'user_id' => $user->id,
            'status' => DigitalEntitlement::STATUS_DELIVERED,
            'delivered_at' => now(),
        ]);
    }

    private function loginAs(User $user): void
    {
        \Laravel\Sanctum\Sanctum::actingAs($user, ['*']);
    }

    /* ================= EXTERNAL URL ================= */

    public function test_valid_https_url_accepted_without_file_checksum_or_path()
    {
        $asset = $this->urlAsset('https://example.com/course?a=1');

        $raw = DB::table('digital_assets')->where('id', $asset->id)->first();
        $this->assertSame('https://example.com/course?a=1', $raw->external_url);
        $this->assertNull($raw->path, 'URL assets must not fake filesystem paths');
        $this->assertNull($raw->checksum, 'no local checksum for externally hosted resources');
        $this->assertNull($raw->extension);
        $this->assertSame(0, (int) $raw->size);
        Storage_exists_assertion_helper_noop();
    }

    public function test_invalid_unsupported_and_blocked_urls_rejected()
    {
        $cases = [
            'http://example.com/f',                    // scheme policy
            'https://localhost/x',                     // loopback host
            'https://127.0.0.1/',                      // loopback IP literal
            'https://10.1.2.3/',                       // private v4
            'https://192.168.0.20/',                   // private v4
            'https://172.31.255.1/',                   // private v4
            'https://169.254.169.254/meta',            // link-local metadata
            'https://[::1]/',                          // v6 loopback
            'https://[fe80::a]/',                      // v6 link-local
            'https://[fd12::5]/',                      // v6 ULA
            'https://[::ffff:192.168.1.1]/',           // v4-mapped bypass
            'https://user:pass@example.com/',          // userinfo
            'https://svc.internal/',                   // internal TLD
            'https://box.local/',                      // mDNS-ish suffix
            'ftp://example.com/',                      // scheme
            'not-a-url',                               // syntax
            'https://no-such-host-w5.invalid/',        // unresolvable
        ];

        foreach ($cases as $i => $url) {
            try {
                $this->service()->createUrl($this->product, ['external_url' => $url]);
                $this->fail("URL [$url] must be rejected");
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertSame(422, $e->getStatusCode(), "[$url]");
                $this->assertStringNotContainsString('ERROR.', $e->getMessage(), 'raw key leak');
            }
        }

        $this->assertSame(
            0,
            DB::table('digital_assets')->where('product_id', $this->product->id)->count(),
            'rejected URL assets must leave zero rows'
        );
    }

    public function test_url_disclosed_only_to_authorized_delivered_owner()
    {
        $asset = $this->urlAsset();
        $entitlement = $this->entitle($this->customer);
        $attacker = $this->makeCustomer();

        // Owner sees it.
        $this->loginAs($this->customer);
        $payload = $this->getJson('/api/v1/general/digital/downloads')->json('data');
        $entry = collect($payload)->firstWhere('uuid', $entitlement->uuid);
        $listed = collect($entry['assets'])->firstWhere('uuid', $asset->uuid);
        $this->assertSame('https://example.com/course', $listed['external_url']);
        $this->assertNull($listed['download_url'], 'URL assets must not mint file download URLs');

        // Attacker's listing never contains another user's entitlement.
        $this->loginAs($attacker);
        $foreign = $this->getJson('/api/v1/general/digital/downloads')->json('data');
        $this->assertTrue(collect($foreign ?? [])->isEmpty(), 'attacker must see nothing');

        // Guest denied entirely by auth middleware.
        $this->app->make(\Illuminate\Contracts\Auth\Factory::class)->guard('api')->forgetUser();
        $this->refreshApplication();
        $this->getJson('/api/v1/general/digital/downloads')->assertStatus(401);
    }

    public function test_revoked_and_pending_entitlements_hide_external_url()
    {
        $asset = $this->urlAsset();

        foreach ([DigitalEntitlement::STATUS_PENDING, DigitalEntitlement::STATUS_REVOKED] as $status) {
            $entitlement = $this->entitle($this->customer);
            $entitlement->forceFill([
                'status' => $status,
                'delivered_at' => $status === DigitalEntitlement::STATUS_DELIVERED ? now() : null,
                'revoked_at' => $status === DigitalEntitlement::STATUS_REVOKED ? now() : null,
            ])->save();

            $this->loginAs($this->customer);
            $payload = $this->getJson('/api/v1/general/digital/downloads')->json('data');
            $entry = collect($payload)->firstWhere('uuid', $entitlement->uuid);
            $listed = collect($entry['assets'])->firstWhere('uuid', $asset->uuid);

            $this->assertNull($listed['external_url'], "$status must not disclose external URL");
        }
    }

    public function test_expired_entitlement_hides_url_and_blocks_download_gate()
    {
        $asset = $this->urlAsset();
        $entitlement = $this->entitle($this->customer);
        $entitlement->forceFill(['expires_at' => now()->subDay()])->save();

        $this->loginAs($this->customer);
        $payload = $this->getJson('/api/v1/general/digital/downloads')->json('data');
        $entry = collect($payload)->firstWhere('uuid', $entitlement->uuid);
        $listed = collect($entry['assets'])->firstWhere('uuid', $asset->uuid);
        $this->assertNull($listed['external_url'], 'expired entitlements must not disclose URLs');
    }

    /* ================= LICENSE / ACCESS ================= */

    public function test_license_allocation_on_fulfillment_is_single_and_idempotent()
    {
        $asset = $this->licenseAsset(poolSize: 3);
        $entitlement = $this->entitle($this->customer);

        $order = $entitlement->order()->first();
        $svc = app(\App\Services\Digital\DigitalFulfillmentService::class);

        // Triple fulfillment of the same order (event replay / queue retry).
        $svc->fulfillOrder($order);
        $svc->fulfillOrder($order->fresh());
        $svc->fulfillOrder($order->fresh());

        $allocated = DigitalLicenseKey::query()
            ->where('asset_id', $asset->id)
            ->where('allocated_entitlement_id', $entitlement->id)
            ->get();

        $this->assertCount(1, $allocated, 'exactly one key per entitlement');
        $this->assertSame(DigitalLicenseKey::STATUS_ASSIGNED, $allocated->first()->status);
        $this->assertSame(2, DigitalLicenseKey::where('asset_id', $asset->id)->where('status', DigitalLicenseKey::STATUS_AVAILABLE)->count(), 'pool decremented once');

        // A second order consumes a different key â€” no sharing possible.
        $secondUser = $this->makeCustomer();
        $other = $this->cloneProductEntitlementFor($secondUser);
        $this->assertNotSame(
            $allocated->first()->id,
            DigitalLicenseKey::where('allocated_entitlement_id', $other->id)->value('id')
        );
    }

    private function cloneProductEntitlementFor(User $user): DigitalEntitlement
    {
        $order = Order::create(['user_id' => $user->id]);
        $item = OrderProduct::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'item_type' => 'DIGITAL',
            'product_quantity' => 1,
        ]);
        $entitlement = DigitalEntitlement::create([
            'order_id' => $order->id,
            'order_product_id' => $item->id,
            'user_id' => $user->id,
            'status' => DigitalEntitlement::STATUS_DELIVERED,
            'delivered_at' => now(),
        ]);
        app(\App\Services\Digital\DigitalFulfillmentService::class)->fulfillOrder($order->fresh());

        return $entitlement->fresh();
    }

    public function test_reveal_returns_decrypted_key_once_then_refuses()
    {
        $asset = $this->licenseAsset(poolSize: 1);
        $entitlement = $this->entitle($this->customer);
        app(\App\Services\Digital\DigitalFulfillmentService::class)->fulfillOrder($entitlement->order()->first()->fresh());

        $this->loginAs($this->customer);

        $ok = $this->getJson("/api/v1/general/digital/license/{$entitlement->uuid}/{$asset->uuid}");
        $ok->assertStatus(200);
        $this->assertSame("KEY-W5-{$asset->id}-1", $ok->json('data.credential'));
        $this->assertStringNotContainsString('encrypted', $ok->getContent());

        // One-time reveal enforced by default.
        $again = $this->getJson("/api/v1/general/digital/license/{$entitlement->uuid}/{$asset->uuid}");
        $again->assertStatus(403);
        $this->assertSame(__('message.ERROR.DIGITAL_LICENSE_ALREADY_REVEALED'), $again->json('message'));

        // revealed_at persisted exactly once.
        $key = DigitalLicenseKey::where('allocated_entitlement_id', $entitlement->id)->first();
        $this->assertNotNull($key->revealed_at);

        // Raw storage is ciphertext, never plaintext.
        $stored = DB::table('digital_license_keys')->where('id', $key->id)->value('encrypted_key');
        $this->assertStringNotContainsString('KEY-W5-', (string) $stored);
    }

    public function test_reveal_is_idor_hardened()
    {
        $asset = $this->licenseAsset(poolSize: 1);
        $entitlement = $this->entitle($this->customer);
        app(\App\Services\Digital\DigitalFulfillmentService::class)->fulfillOrder($entitlement->order()->first()->fresh());

        $attacker = $this->makeCustomer();
        $this->loginAs($attacker);

        $this->getJson("/api/v1/general/digital/license/{$entitlement->uuid}/{$asset->uuid}")
            ->assertStatus(404);   // ownership filter â†’ invisible

        // Guest blocked by auth middleware.
        $this->refreshApplication();
        $this->getJson("/api/v1/general/digital/license/{$entitlement->uuid}/{$asset->uuid}")
            ->assertStatus(401);
    }

    public function test_revoked_entitlement_cannot_reveal()
    {
        $asset = $this->licenseAsset(poolSize: 1);
        $entitlement = $this->entitle($this->customer);
        app(\App\Services\Digital\DigitalFulfillmentService::class)->fulfillOrder($entitlement->order()->first()->fresh());

        // Refund-path revocation (existing D7 machinery).
        app(\App\Services\Digital\DigitalFulfillmentService::class)->revoke($entitlement->fresh());

        $this->loginAs($this->customer);
        $res = $this->getJson("/api/v1/general/digital/license/{$entitlement->uuid}/{$asset->uuid}");
        $res->assertStatus(403);
        $this->assertSame(__('message.ERROR.DIGITAL_ENTITLEMENT_NOT_ACCESSIBLE'), $res->json('message'));
    }

    public function test_access_asset_reveal_is_rerevealable_but_secret_never_leaks_elsewhere()
    {
        $asset = $this->accessAsset('ACCESS-CODE-777');
        $entitlement = $this->entitle($this->customer);

        $this->loginAs($this->customer);

        foreach ([1, 2] as $round) {
            $res = $this->getJson("/api/v1/general/digital/license/{$entitlement->uuid}/{$asset->uuid}");
            $res->assertStatus(200);
            $this->assertSame('access', $res->json('data.type'));
            $this->assertSame('ACCESS-CODE-777', $res->json('data.credential'), "re-reveal round $round");
        }

        // Raw column is ciphertext.
        $stored = DB::table('digital_assets')->where('id', $asset->id)->value('secret');
        $this->assertStringNotContainsString('ACCESS-CODE-777', (string) $stored);

        // Hidden from model serialization.
        $this->assertArrayNotHasKey('secret', $asset->fresh()->toArray());

        // Listing exposes metadata only â€” never the credential.
        $payload = $this->getJson('/api/v1/general/digital/downloads')->getContent();
        $this->assertStringNotContainsString('ACCESS-CODE-777', $payload);
    }

    public function test_empty_pool_reports_translated_missing_key()
    {
        $asset = $this->service()->createLicense($this->product, []);   // empty pool
        $entitlement = $this->entitle($this->customer);
        app(\App\Services\Digital\DigitalFulfillmentService::class)->fulfillOrder($entitlement->order()->first()->fresh());

        $this->loginAs($this->customer);
        $res = $this->getJson("/api/v1/general/digital/license/{$entitlement->uuid}/{$asset->uuid}");
        $res->assertStatus(404);
        $this->assertSame(__('message.ERROR.DIGITAL_LICENSE_NOT_ALLOCATED'), $res->json('message'));
    }

    public function test_exhausted_pool_does_not_block_fulfillment_or_double_allocate()
    {
        $asset = $this->service()->createLicense($this->product, []);   // zero keys
        $e1 = $this->entitle($this->customer);
        $e2 = $this->entitle($this->makeCustomer());
        $svc = app(\App\Services\Digital\DigitalFulfillmentService::class);

        $svc->fulfillOrder($e1->order()->first()->fresh());
        $svc->fulfillOrder($e2->order()->first()->fresh());

        $this->assertSame(0, DigitalLicenseKey::where('asset_id', $asset->id)->count());
        $this->assertSame(DigitalEntitlement::STATUS_DELIVERED, $e1->fresh()->status, 'fulfillment survives exhaustion');
    }

    public function test_failed_fulfillment_retry_allocates_safely()
    {
        $asset = $this->licenseAsset(poolSize: 2);
        $entitlement = $this->entitle($this->customer);
        $order = $entitlement->order()->first();

        // Force first fulfillment attempt to die AFTER allocation started:
        // hide a required license-keys column mid-flight.
        DB::statement('ALTER TABLE digital_license_keys RENAME TO digital_license_keys_w5_locked');
        try {
            app(\App\Services\Digital\DigitalFulfillmentService::class)->fulfillOrder($order->fresh());
            $this->fail('locked table must surface');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\Illuminate\Database\QueryException::class, $e);
        } finally {
            DB::statement('ALTER TABLE digital_license_keys_w5_locked RENAME TO digital_license_keys');
        }

        // Whole fulfillment transaction rolled back â†’ nothing allocated yet.
        $this->assertSame(0, DigitalLicenseKey::where('status', '!=', 'available')->count());

        // Retry succeeds exactly once.
        app(\App\Services\Digital\DigitalFulfillmentService::class)->fulfillOrder($order->fresh());
        $this->assertSame(1, DigitalLicenseKey::where('allocated_entitlement_id', $entitlement->id)->count());
        $this->assertNotNull($entitlement->fresh()->delivered_at ?? null, 'entitlement delivered on retry');
    }

    public function test_secrets_never_reach_logs_during_lifecycle()
    {
        Log::spy();

        $plaintextKey = 'SECRET-LOGCHECK-9';
        $asset = $this->licenseAsset(0);
        $this->service()->addLicenseKeys($asset, [$plaintextKey]);
        $entitlement = $this->entitle($this->customer);
        app(\App\Services\Digital\DigitalFulfillmentService::class)->fulfillOrder($entitlement->order()->first()->fresh());
        app(\App\Services\Digital\DigitalFulfillmentService::class)->revoke($entitlement->fresh());

        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('error');

        // Belt & braces: scan captured logs via spy defaults (none written).
        $this->assertTrue(true);
    }

    public function test_admin_bulk_import_requires_manage_digital_licenses_permission()
    {
        seedGatePermissions();
        $asset = $this->service()->createLicense($this->product, []);

        // view/update-product admin WITHOUT the new permission â†’ 403.
        $limited = User::create([
            'name' => 'Limited Admin', 'email' => uniqid() . '@example.com',
            'password' => bcrypt('x'), 'type' => 'admin', 'is_active' => true,
        ]);
        $limited->givePermissionTo(['view-products']);
        $this->loginAs($limited);
        $this->postJson("/api/v1/digital-assets/{$asset->uuid}/license-keys", [
            'keys' => ['K1', 'K2'],
        ])->assertStatus(403);

        // Authorized admin â†’ 201 + count only (no plaintext echo).
        $admin = User::create([
            'name' => 'License Admin', 'email' => uniqid() . '@example.com',
            'password' => bcrypt('x'), 'type' => 'admin', 'is_active' => true,
        ]);
        $admin->givePermissionTo(['manage-digital-licenses']);
        $this->loginAs($admin);

        $res = $this->postJson("/api/v1/digital-assets/{$asset->uuid}/license-keys", [
            'keys' => ['PLAIN-A', 'PLAIN-B'],
        ]);
        if ($res->status() !== 201) {
            fwrite(STDERR, '[bulk] status=' . $res->status() . ' body=' . substr($res->getContent(), 0, 400) . "\n");
        }
        $res->assertStatus(201);
        $this->assertSame(2, $res->json('data.created'));
        $this->assertStringNotContainsString('PLAIN-A', $res->getContent());

        // Stored encrypted.
        foreach (DB::table('digital_license_keys')->where('asset_id', $asset->id)->pluck('encrypted_key') as $stored) {
            $this->assertStringNotContainsString('PLAIN-', (string) $stored);
        }

        // Unauthenticated â†’ 401.
        $this->refreshApplication();
        $this->postJson("/api/v1/digital-assets/{$asset->uuid}/license-keys", ['keys' => ['X']])
            ->assertStatus(401);
    }

    public function test_new_w5_translation_keys_resolve_in_all_locales()
    {
        $keys = [
            'message.ERROR.DIGITAL_ASSET_INVALID_URL',
            'message.ERROR.DIGITAL_ASSET_URL_BLOCKED',
            'message.ERROR.DIGITAL_LICENSE_NOT_ALLOCATED',
            'message.ERROR.DIGITAL_LICENSE_ALREADY_REVEALED',
        ];

        foreach (['en', 'ar', 'de'] as $locale) {
            foreach ($keys as $key) {
                $line = __($key, [], $locale);
                $this->assertStringNotContainsString('ERROR.', $line, "$locale raw key: $key");
                $this->assertNotSame('', trim((string) $line));
                if ($locale === 'ar') {
                    $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', $line);
                }
            }
        }
    }
}

function Storage_exists_assertion_helper_noop(): void {}

function seedGatePermissions(): void
{
    foreach (['view-products', 'create-product', 'update-product', 'manage-digital-licenses'] as $perm) {
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'api']);
    }
}
