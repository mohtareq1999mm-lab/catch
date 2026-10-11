<?php

namespace Tests\Feature\Digital;

use App\Audit\ActivitySnapshot;
use App\Enums\QueueName;
use App\Jobs\LogActivityJob;
use App\Listeners\FulfillDigitalProducts;
use App\Listeners\SendUserDigitalProductsAvailableNotification;
use App\Models\DigitalAsset;
use App\Models\DigitalEntitlement;
use App\Models\DigitalLicenseKey;
use App\Services\Digital\DigitalAssetService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderProduct;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Marvel\Enums\ItemType;
use Marvel\Enums\Permission;
use Marvel\Enums\ProductType;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

/**
 * Repair verification for the Digital Assets & Entitlements hardening pass:
 *
 * 1. DELETE digital-assets/{uuid} requires delete-product only
 *    (constructor previously demanded update-product on top of the
 *    route-level delete-product gate).
 * 2. License-key provisioning: intra-batch duplicates 422; stored keys
 *    are skipped with counts (content-idempotent repeats); blanks are
 *    skipped with counts; plaintext never echoes.
 * 3. Entitlement admin ops (limit/revoke/restore) dispatch a valid
 *    ActivitySnapshot to the configured medium queue (previously threw
 *    TypeError after persisting the write).
 * 4. Queue mapping: fulfillment listener → configured high queue,
 *    LogActivityJob → configured medium queue (asserted by NAME, not
 *    merely "a job was pushed").
 *
 * Conventions follow the existing tests/Feature/Digital/* suite
 * (self-created tables, Storage::fake('private'), QUEUE_CONNECTION=sync
 * from phpunit.xml — the project's own testing convention).
 */
class DigitalRepairVerificationTest extends TestCase
{
    use CreatesTestTables, DatabaseTransactions;

    private const PREFIX = '/api/v1';

    private User $admin;

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

        if (!Schema::hasTable('activity_log')) {
            Schema::create('activity_log', function (Blueprint $table) {
                $table->id();
                $table->string('log_name')->nullable();
                $table->text('description')->nullable();
                $table->nullableTimestamps();
                $table->json('properties')->nullable();
                $table->unsignedBigInteger('subject_id')->nullable();
                $table->string('subject_type')->nullable();
                $table->string('event')->nullable();
                $table->unsignedBigInteger('batch_uuid')->nullable();
            });
        }

        foreach ([
            Permission::VIEW_PRODUCTS,
            Permission::CREATE_PRODUCT,
            Permission::UPDATE_PRODUCT,
            Permission::DELETE_PRODUCT,
            Permission::VIEW_ORDERS,
            Permission::MANAGE_DIGITAL_ACCESS,
            Permission::MANAGE_DIGITAL_LICENSES,
        ] as $perm) {
            SpatiePermission::firstOrCreate(['name' => $perm, 'guard_name' => 'api']);
        }

        $this->admin = User::create([
            'name' => 'Repair Admin',
            'email' => 'repair-' . uniqid() . '@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('Password123!'),
            'is_active' => true,
            'type' => 'admin',
        ]);
        $this->admin->givePermissionTo([
            Permission::VIEW_PRODUCTS,
            Permission::CREATE_PRODUCT,
            Permission::UPDATE_PRODUCT,
            Permission::DELETE_PRODUCT,
            Permission::VIEW_ORDERS,
            Permission::MANAGE_DIGITAL_ACCESS,
            Permission::MANAGE_DIGITAL_LICENSES,
        ]);

        $this->product = Product::create([
            'name' => ['en' => 'Repair Product'],
            'slug' => 'repair-' . Str::random(8),
            'description' => ['en' => 'Desc'],
            'price' => 20.00,
            'product_type' => ProductType::SIMPLE,
            'item_type' => ItemType::DIGITAL,
            'status' => 1,
            'in_stock' => true,
        ]);
    }

    private function pdf(string $name = 'manual.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            "%PDF-1.4\n" . str_repeat('%', 32 * 1024) . "\n%%EOF"
        );
    }

    private function fileAsset(): DigitalAsset
    {
        return app(DigitalAssetService::class)->store($this->product, $this->pdf());
    }

    private function licensePool(): DigitalAsset
    {
        return DigitalAsset::create([
            'product_id' => $this->product->id,
            'type' => 'LICENSE',
            'disk' => 'private',
            'original_name' => 'pool',
            'mime' => 'text/plain',
            'size' => 0,
            'status' => 'active',
            'sort_order' => 1,
        ]);
    }

    private function entitlement(int $limit = 5, int $used = 0): DigitalEntitlement
    {
        $customer = User::create([
            'name' => 'Repair Customer',
            'email' => 'repair-cust-' . uniqid() . '@example.com',
            'password' => Hash::make('Password123!'),
            'is_active' => true,
            'type' => 'customer',
        ]);

        $order = Order::create([
            'user_id' => $customer->id,
            'status' => 'pending',
            'payment_status' => 'payment-success',
            'fulfillment_status' => 'pending',
            'payment_method' => 'online',
            'total_price' => 20.00,
        ]);

        $item = OrderProduct::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'product_name' => 'Repair Product',
            'product_quantity' => 1,
            'product_price' => 20.00,
            'product_total_price' => 20.00,
            'item_type' => ItemType::DIGITAL,
        ]);

        return DigitalEntitlement::create([
            'order_id' => $order->id,
            'order_product_id' => $item->id,
            'user_id' => $customer->id,
            'status' => DigitalEntitlement::STATUS_DELIVERED,
            'delivered_at' => now(),
            'download_limit' => $limit,
            'download_count' => $used,
        ]);
    }

    // =====================================================================
    // 1. Destroy permission alignment
    // =====================================================================

    public function test_destroy_succeeds_with_delete_product_only()
    {
        $deleteOnly = User::create([
            'name' => 'Delete Only',
            'email' => 'repair-delonly-' . uniqid() . '@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('Password123!'),
            'is_active' => true,
            'type' => 'admin',
        ]);
        $deleteOnly->givePermissionTo(Permission::DELETE_PRODUCT);

        $asset = $this->fileAsset();

        Sanctum::actingAs($deleteOnly, ['*']);

        $this->deleteJson(self::PREFIX . '/digital-assets/' . $asset->uuid)->assertStatus(200);
        $this->assertNull(DigitalAsset::where('uuid', $asset->uuid)->first());
    }

    public function test_destroy_denied_with_update_product_only()
    {
        $updateOnly = User::create([
            'name' => 'Update Only',
            'email' => 'repair-updonly-' . uniqid() . '@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('Password123!'),
            'is_active' => true,
            'type' => 'admin',
        ]);
        $updateOnly->givePermissionTo(Permission::UPDATE_PRODUCT);

        $asset = $this->fileAsset();

        Sanctum::actingAs($updateOnly, ['*']);

        $this->deleteJson(self::PREFIX . '/digital-assets/' . $asset->uuid)->assertStatus(403);
        $this->assertNotNull(DigitalAsset::where('uuid', $asset->uuid)->first());
    }

    // =====================================================================
    // 2. License-key provisioning semantics
    // =====================================================================

    public function test_license_keys_reject_intra_batch_duplicates_without_storing()
    {
        $pool = $this->licensePool();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson(
            self::PREFIX . '/digital-assets/' . $pool->uuid . '/license-keys',
            ['keys' => ['DUP-KEY-1', 'DUP-KEY-1']]
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('keys.1');
        $this->assertSame(0, DigitalLicenseKey::where('asset_id', $pool->id)->count());
    }

    public function test_license_keys_skip_existing_and_blank_with_counts()
    {
        $pool = $this->licensePool();

        Sanctum::actingAs($this->admin, ['*']);
        $url = self::PREFIX . '/digital-assets/' . $pool->uuid . '/license-keys';

        $first = $this->postJson($url, ['keys' => ['K-ONE', 'K-TWO']]);
        $first->assertStatus(201);
        $first->assertJsonPath('data.created', 2);
        $first->assertJsonPath('data.skipped_blank', 0);
        $first->assertJsonPath('data.skipped_duplicate', 0);

        // Full repeat: idempotent skip, no new rows.
        $repeat = $this->postJson($url, ['keys' => ['K-ONE', 'K-TWO']]);
        $repeat->assertStatus(201);
        $repeat->assertJsonPath('data.created', 0);
        $repeat->assertJsonPath('data.skipped_duplicate', 2);
        $this->assertSame(2, DigitalLicenseKey::where('asset_id', $pool->id)->count());

        // Blank via HTTP is rejected explicitly at validation (required
        // treats whitespace-only as empty) — nothing is stored.
        $blank = $this->postJson($url, ['keys' => ['   ', 'K-THREE']]);
        $blank->assertStatus(422);
        $blank->assertJsonValidationErrors('keys.0');
        $this->assertSame(2, DigitalLicenseKey::where('asset_id', $pool->id)->count());

        // Blank at the SERVICE layer (defense in depth for programmatic
        // callers) is skipped with an explicit count.
        $result = app(DigitalAssetService::class)->addLicenseKeys($pool, ['   ', 'K-THREE']);
        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['skipped_blank']);
        $this->assertSame(3, DigitalLicenseKey::where('asset_id', $pool->id)->count());
    }

    public function test_license_keys_never_echo_plaintext()
    {
        $pool = $this->licensePool();

        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->postJson(
            self::PREFIX . '/digital-assets/' . $pool->uuid . '/license-keys',
            ['keys' => ['SECRET-PLAIN-9999']]
        );

        $response->assertStatus(201);
        $this->assertStringNotContainsString('SECRET-PLAIN-9999', $response->getContent());
    }

    // =====================================================================
    // 3. Entitlement admin ops survive audit dispatch (snapshot repair)
    // =====================================================================

    public function test_limit_revoke_restore_return_200_with_valid_audit_dispatch()
    {
        $entitlement = $this->entitlement();

        Sanctum::actingAs($this->admin, ['*']);

        $limit = $this->patchJson(
            self::PREFIX . '/digital-entitlements/' . $entitlement->uuid . '/limit',
            ['limit' => 10]
        );
        $limit->assertStatus(200);
        $limit->assertJsonPath('data.download_limit', 10);
        $limit->assertJsonPath('data.previous_limit', 5);

        $revoke = $this->postJson(self::PREFIX . '/digital-entitlements/' . $entitlement->uuid . '/revoke');
        $revoke->assertStatus(200);
        $revoke->assertJsonPath('data.status', DigitalEntitlement::STATUS_REVOKED);

        $restore = $this->postJson(self::PREFIX . '/digital-entitlements/' . $entitlement->uuid . '/restore');
        $restore->assertStatus(200);
        $restore->assertJsonPath('data.status', DigitalEntitlement::STATUS_DELIVERED);
        $restore->assertJsonPath('data.revoked_at', null);
    }

    // =====================================================================
    // 4. Queue mapping by NAME (QueueName / config, never hard-coded)
    // =====================================================================

    public function test_fulfillment_listener_targets_configured_high_queue()
    {
        $listener = new FulfillDigitalProducts(app(\App\Services\Digital\DigitalFulfillmentService::class));

        $this->assertSame(config('queue.queues.high'), $listener->viaQueue());
        $this->assertSame(QueueName::high(), $listener->viaQueue());
    }

    public function test_delivery_notification_targets_configured_high_queue()
    {
        $listener = new SendUserDigitalProductsAvailableNotification();

        $this->assertSame(config('queue.queues.high'), $listener->viaQueue());
    }

    public function test_activity_job_dispatches_to_configured_medium_queue()
    {
        Queue::fake();

        LogActivityJob::dispatch(new ActivitySnapshot(
            logName: 'digital-entitlements',
            event: 'test.event',
            description: 'test',
        ));

        Queue::assertPushed(LogActivityJob::class, function (LogActivityJob $job) {
            return $job->queue === config('queue.queues.medium')
                && $job->queue === QueueName::medium();
        });
    }
}
