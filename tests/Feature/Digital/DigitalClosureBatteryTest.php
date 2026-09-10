<?php

namespace Tests\Feature\Digital;

use App\Models\DigitalAsset;
use App\Models\DigitalEntitlement;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderProduct;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * WORKSTREAM 8 — PRODUCTION CLOSURE BATTERY.
 *
 * Consolidated end-to-end lifecycle + security negatives + performance
 * evidence that were previously distributed across workstream suites.
 * Complements (never duplicates) the dedicated W4/W5/W6/W7 suites.
 */
class DigitalClosureBatteryTest extends TestCase
{
    use \Tests\Concerns\CreatesTestTables;

    private Product $product;
    private User $admin;
    private User $customer;

    protected function setUp(): void
    {
        if (!class_exists('CodeZero\UniqueTranslation\UniqueTranslationRule')) {
            require_once __DIR__ . '/../Stubs/UniqueTranslationRuleStub.php';
        }
        parent::setUp();
        app()->setLocale('en');
        Storage::fake('private');
        $this->createAllTestTables();

        foreach (['view-products', 'create-product', 'update-product', 'view-orders', 'manage-digital-access', 'manage-digital-licenses'] as $p) {
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $p, 'guard_name' => 'api']);
        }

        $this->product = Product::create([
            'name' => ['en' => 'Closure Product'], 'slug' => 'closure-' . uniqid(),
            'price' => 60, 'item_type' => 'DIGITAL',
        ]);
        $this->admin = User::create([
            'name' => 'Closure Admin', 'email' => uniqid() . '@example.com',
            'password' => bcrypt('x'), 'type' => 'admin', 'is_active' => true,
        ]);
        $this->admin->givePermissionTo(['view-products', 'create-product', 'update-product', 'view-orders', 'manage-digital-access']);
        $this->customer = User::create([
            'name' => 'Closure Cust', 'email' => uniqid() . '@example.com',
            'password' => bcrypt('x'), 'type' => 'customer',
        ]);
    }

    private function pdf(int $kb = 32): \Illuminate\Http\UploadedFile
    {
        return \Illuminate\Http\UploadedFile::fake()->createWithContent(
            'closure.pdf',
            "%PDF-1.4\n" . str_repeat('C', $kb * 1024 - 16) . "\n%%EOF"
        );
    }

    private function entitle(User $u, int $limit = 5): DigitalEntitlement
    {
        $order = Order::create(['user_id' => $u->id]);
        $item = OrderProduct::create([
            'order_id' => $order->id, 'product_id' => $this->product->id,
            'item_type' => 'DIGITAL', 'product_quantity' => 1,
        ]);

        return DigitalEntitlement::forceCreate([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'order_id' => $order->id, 'order_product_id' => $item->id,
            'user_id' => $u->id,
            'status' => DigitalEntitlement::STATUS_DELIVERED,
            'delivered_at' => now(), 'download_limit' => $limit,
        ]);
    }

    private function asset(): DigitalAsset
    {
        return app(\App\Services\Digital\DigitalAssetService::class)->store($this->product, $this->pdf());
    }

    /* ================================================================
     | NEGATIVE BATTERY GAPS (HTTP boundary)
     * ================================================================ */

    public function test_neg_traversal_filename_is_sanitized_metadata_only()
    {
        \Laravel\Sanctum\Sanctum::actingAs($this->admin, ['*']);

        $res = $this->postJson('/api/v1/products/' . $this->product->id . '/digital-assets', [
            'file' => $this->pdf(),
            'original_name' => '../../../etc/passwd with spaces!.pdf',
        ])->assertStatus(201);

        $asset = DigitalAsset::latest('id')->first();
        $this->assertStringNotContainsString('..', $asset->path, 'traversal must never reach storage path');
        $this->assertMatchesRegularExpression('#^digital-assets/\d+/[0-9a-f\-]{36}\.pdf$#', $asset->path);
        $this->assertCount(1, Storage::disk('private')->allFiles());
    }

    public function test_neg_oversize_upload_rejected_http_boundary()
    {
        config(['digital.max_upload_kb' => 64]);
        \Laravel\Sanctum\Sanctum::actingAs($this->admin, ['*']);

        $big = \Illuminate\Http\UploadedFile::fake()->createWithContent(
            'big.pdf',
            "%PDF-1.4\n" . str_repeat('B', 128 * 1024) . "\n%%EOF"
        );

        $this->postJson('/api/v1/products/' . $this->product->id . '/digital-assets', ['file' => $big])
            ->assertStatus(422);

        $this->assertSame(0, DB::table('digital_assets')->where('product_id', $this->product->id)->count());
        $this->assertCount(0, Storage::disk('private')->allFiles(), 'oversized must leave no file');
        config(['digital.max_upload_kb' => 20480]);
    }

    public function test_neg_malformed_pdf_bytes_rejected_by_content_truth()
    {
        \Laravel\Sanctum\Sanctum::actingAs($this->admin, ['*']);
        $garbage = \Illuminate\Http\UploadedFile::fake()->createWithContent('fake.pdf', 'definitely-not-portable-document-format');

        $this->postJson('/api/v1/products/' . $this->product->id . '/digital-assets', ['file' => $garbage])
            ->assertStatus(422);
        $this->assertSame(0, DB::table('digital_assets')->count());
    }

    public function test_neg_executables_rejected_end_to_end()
    {
        config(['digital.allow_software_assets' => true]);
        \Laravel\Sanctum\Sanctum::actingAs($this->admin, ['*']);

        $exe = \Illuminate\Http\UploadedFile::fake()->createWithContent('tool.exe', "MZ\x90\x00" . str_repeat("\x00", 200));
        $this->postJson('/api/v1/products/' . $this->product->id . '/digital-assets', ['file' => $exe])
            ->assertStatus(422);

        config(['digital.allow_software_assets' => false]);
        $this->assertSame(0, DB::table('digital_assets')->count());
    }

    public function test_neg_deleted_asset_download_returns_404_preserving_credit()
    {
        $asset = $this->asset();
        $entitlement = $this->entitle($this->customer);
        $entitlement->assets()->attach($asset->id);

        // Delete via the admin path (row + physical file).
        \Laravel\Sanctum\Sanctum::actingAs($this->admin, ['*']);
        $this->deleteJson('/api/v1/digital-assets/' . $asset->uuid)->assertStatus(200);

        $signed = URL::temporarySignedRoute('general.digital.download', now()->addMinutes(5), [
            'entitlement' => $entitlement->uuid, 'asset' => $asset->uuid,
        ]);

        $this->get($signed)->assertStatus(404);
        $this->assertSame(0, (int) $entitlement->refresh()->download_count, 'credit preserved on deleted asset');
    }

    public function test_neg_no_public_storage_urls_ever_emitted()
    {
        $asset = $this->asset();
        $entitlement = $this->entitle($this->customer);
        $entitlement->assets()->attach($asset->id);

        \Laravel\Sanctum\Sanctum::actingAs($this->customer, ['*']);
        $payload = $this->getJson('/api/v1/general/digital/downloads')->getContent();

        $this->assertStringNotContainsString('/storage/', $payload);
        $this->assertStringNotContainsString('digital-assets/', substr($payload, 0, strpos($payload, '"download_url"') ?: strlen($payload)) ?: '', 'path fragments must not leak outside signed URLs');
        $this->assertStringContainsString('signature=', $payload, 'download access must be signature-gated');
    }

    /* ================================================================
     | CONSOLIDATED CUSTOMER LIFECYCLE E2E + NOTIFICATION PROOF
     * ================================================================ */

    public function test_e2e_purchase_fulfillment_notification_download_limit_expiry_restore()
    {
        // Notification recipients are UserType::USER accounts (W1 contract).
        $recipient = User::create([
            'name' => 'Closure User', 'email' => uniqid() . '@example.com',
            'password' => bcrypt('x'), 'type' => 'user',
        ]);

        $asset = $this->asset();

        $entitlement = $this->entitle($recipient, limit: 2);

        // Fulfill through the REAL event pipeline.
        $order = $entitlement->order()->first();
        fwrite(STDERR, "\nE2E-DIAG order={$order->id} user={$order->user_id} recipient={$recipient->id} entUser={$entitlement->user_id}\n");
        \Illuminate\Support\Facades\Event::dispatch(new \App\Events\PaymentSucceeded($order->fresh()));
        foreach (DatabaseNotification::query()->get(["id","notifiable_type","notifiable_id","type"]) as $n) { fwrite(STDERR, "NOTIF ".json_encode($n)."\n"); }

        $entitlement = $entitlement->fresh();
        $this->assertSame(DigitalEntitlement::STATUS_DELIVERED, $entitlement->status);

        // Delivery notification persisted for the recipient (database channel).
        $notification = DatabaseNotification::query()
            ->where('notifiable_type', \Marvel\Database\Models\User::class)
            ->where('notifiable_id', $recipient->id)
            ->where('type', 'digital.products_available')
            ->first();
        $this->assertNotNull($notification, 'delivery-available notification must be persisted');

        // Download twice -> cap reached.
        $signed = fn () => URL::temporarySignedRoute('general.digital.download', now()->addMinutes(5), [
            'entitlement' => $entitlement->uuid, 'asset' => $asset->uuid,
        ]);
        \Laravel\Sanctum\Sanctum::actingAs($recipient, ['*']);
        $this->get($signed())->assertStatus(200);
        $this->get($signed())->assertStatus(200);
        $this->get($signed())->assertStatus(403);

        // Admin lifts cap to unlimited.
        \Laravel\Sanctum\Sanctum::actingAs($this->admin, ['*']);
        $this->patchJson('/api/v1/digital-entitlements/' . $entitlement->uuid . '/limit')
            ->assertStatus(200)
            ->assertJsonPath('data.unlimited', true);

        \Laravel\Sanctum\Sanctum::actingAs($recipient, ['*']);
        $this->get($signed())->assertStatus(200);
        $this->assertSame(3, (int) $entitlement->refresh()->download_count);

        // Revoke blocks; restore re-allows.
        \Laravel\Sanctum\Sanctum::actingAs($this->admin, ['*']);
        $this->postJson('/api/v1/digital-entitlements/' . $entitlement->uuid . '/revoke')->assertStatus(200);
        \Laravel\Sanctum\Sanctum::actingAs($recipient, ['*']);
        $this->get($signed())->assertStatus(403);

        \Laravel\Sanctum\Sanctum::actingAs($this->admin, ['*']);
        $this->postJson('/api/v1/digital-entitlements/' . $entitlement->uuid . '/restore')->assertStatus(200);
        \Laravel\Sanctum\Sanctum::actingAs($recipient, ['*']);
        $this->get($signed())->assertStatus(200);
    }
    /* ================================================================
     | PERFORMANCE EVIDENCE
     * ================================================================ */

    public function test_entitlement_listing_query_count_is_bounded_not_n_plus_one()
    {
        // Multiple customers × multiple entitlements/assets.
        foreach (range(1, 4) as $i) {
            $u = User::create([
                'name' => 'P' . $i, 'email' => uniqid() . '@example.com',
                'password' => bcrypt('x'), 'type' => 'customer',
            ]);
            foreach (range(1, 2) as $j) {
                $e = $this->entitle($u);
                $e->assets()->attach($this->asset()->id);
            }
        }

        \Laravel\Sanctum\Sanctum::actingAs($this->customer, ['*']);
        DB::enableQueryLog();
        $this->getJson('/api/v1/general/digital/downloads')->assertStatus(200);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Constant ceiling regardless of data volume: entitlements + orderItems
        // + assets(eager via currentAssets fallback per entitlement is bounded
        // by the product-scoped query) + license keys + user.
        $this->assertLessThanOrEqual(12, $queries, "listing must stay O(1)-ish, got {$queries} queries");
    }

    /* ================================================================
     | TRANSLATION LOCK (all digital runtime keys × 3 locales)
     * ================================================================ */

    public function test_all_digital_translation_keys_resolve_in_en_ar_de()
    {
        $keys = [
            'ERROR.ITEM_TYPE_IMMUTABLE_ORDERED', 'ERROR.ITEM_TYPE_IMMUTABLE_ASSETS',
            'ERROR.DIGITAL_ASSET_INVALID_FILE', 'ERROR.DIGITAL_ASSET_INVALID_MIME',
            'ERROR.DIGITAL_ASSET_MIME_MISMATCH', 'ERROR.DIGITAL_ASSET_SOFTWARE_DISABLED',
            'ERROR.DIGITAL_ASSET_TOO_LARGE', 'ERROR.DIGITAL_ASSET_UPLOAD_FAILED',
            'ERROR.DIGITAL_ASSET_NOT_REPLACEABLE', 'ERROR.DIGITAL_ASSET_INVALID_URL',
            'ERROR.DIGITAL_ASSET_URL_BLOCKED', 'ERROR.DIGITAL_ASSET_URL_UNRESOLVABLE',
            'ERROR.DIGITAL_ENTITLEMENT_NOT_ACCESSIBLE', 'ERROR.DIGITAL_DOWNLOAD_LIMIT_REACHED',
            'ERROR.DIGITAL_LICENSE_NOT_ALLOCATED', 'ERROR.DIGITAL_LICENSE_ALREADY_REVEALED',
            'ERROR.DIGITAL_ACCESS_SECRET_REQUIRED', 'ERROR.DIGITAL_LICENSE_POOL_ONLY',
            'ERROR.DIGITAL_NOT_REFUNDABLE_AFTER_DELIVERY',
        ];

        foreach (['en', 'ar', 'de'] as $locale) {
        foreach ($keys as $key) {
            $line = __('message.' . $key, [], $locale);
                $this->assertStringNotContainsString($key, (string) $line, "$locale raw key: $key");
                $this->assertNotSame('', trim((string) $line));
                if ($locale === 'ar') {
                    $this->assertMatchesRegularExpression('/[\x{0600}-\x{06FF}]/u', (string) $line, "ar glyphs: $key");
                }
            }
        }
    }

    /* ================================================================
     | PERMISSION CHAIN AUDIT (enum→DB→labels→middleware effect)
     * ================================================================ */

    public function test_digital_permissions_exist_in_db_with_labels_and_enforce()
    {
        foreach (['manage-digital-access', 'manage-digital-licenses'] as $perm) {
            $row = DB::table('permissions')->where('name', $perm)->where('guard_name', 'api')->first();
            $this->assertNotNull($row, "$perm must exist in DB");
            $label = __('permissions.' . str_replace('-', '_', $perm));
            $this->assertStringNotContainsString($perm, (string) $label, "$perm label must be human text");
        }

        // Middleware effect: viewer-only admin cannot mutate entitlements.
        $viewer = User::create([
            'name' => 'Viewer', 'email' => uniqid() . '@example.com',
            'password' => bcrypt('x'), 'type' => 'admin', 'is_active' => true,
        ]);
        $viewer->givePermissionTo(['view-orders']);
        $e = $this->entitle($this->customer);

        \Laravel\Sanctum\Sanctum::actingAs($viewer, ['*']);
        $this->postJson('/api/v1/digital-entitlements/' . $e->uuid . '/revoke')->assertStatus(403);
    }
}
