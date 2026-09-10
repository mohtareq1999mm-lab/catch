<?php

namespace Tests\Feature\Digital;

use App\Models\DigitalAsset;
use App\Models\DigitalEntitlement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderProduct;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Tests\TestCase;

class DigitalDownloadSecurityTest extends TestCase
{
    private User $owner;
    private User $attacker;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        Storage::fake('private');

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

        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('name')->nullable();
                $table->string('email')->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password')->nullable();
                $table->boolean('is_active')->default(true);
                $table->string('type')->default('customer');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('products')) {
            Schema::create('products', function (Blueprint $table) {
                $table->id();
                $table->string('sku')->nullable();
                $table->string('slug')->unique();
                $table->decimal('price', 10, 2)->default(0);
                $table->string('item_type', 16)->default('PHYSICAL');
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (!Schema::hasTable('orders')) {
            Schema::create('orders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('status')->default('completed');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('order_products')) {
            Schema::create('order_products', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
                $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
                $table->integer('product_quantity')->default(1);
                $table->decimal('product_price', 10, 2)->default(0);
                $table->decimal('product_total_price', 10, 2)->default(0);
                $table->boolean('is_gift')->default(false);
                $table->string('item_type', 16)->default('PHYSICAL');
                $table->timestamps();
            });
        }

        Schema::create('digital_assets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('type', 20)->default('FILE');
            $table->string('disk', 30)->default('private');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 20)->default('active');   // W6 parity
            $table->timestamps();
        });

        Schema::create('digital_entitlements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('order_product_id')->unique()->constrained('order_products')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->timestamp('delivered_at')->nullable();
            $table->unsignedInteger('download_limit')->default(5);
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('expires_at')->nullable();   // W3/W5 parity
            $table->timestamps();
        });

        // W5 parity — the customer index() reads allocations for license assets.
        Schema::create('digital_license_keys', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->text('encrypted_key');
            $table->string('status', 20)->default('available');
            $table->foreignId('allocated_entitlement_id')
                ->nullable()
                ->constrained('digital_entitlements')
                ->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('revealed_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('digital_asset_entitlement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('digital_entitlement_id')->constrained('digital_entitlements')->cascadeOnDelete();
            $table->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->timestamp('granted_at')->useCurrent();
        });

        Schema::create('digital_download_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entitlement_id')->constrained('digital_entitlements')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('digital_assets')->cascadeOnDelete();
            $table->string('ip_hash', 64)->nullable();
            $table->string('ua_hash', 64)->nullable();
            $table->timestamp('downloaded_at');
        });

        $this->owner = User::create([
            'name' => 'Owner',
            'email' => 'dl-owner-' . uniqid() . '@example.com',
            'email_verified_at' => now(),
            'password' => bcrypt('x'),
            'type' => 'customer',
        ]);

        $this->attacker = User::create([
            'name' => 'Attacker',
            'email' => 'dl-attack-' . uniqid() . '@example.com',
            'email_verified_at' => now(),
            'password' => bcrypt('x'),
            'type' => 'customer',
        ]);
    }

    /**
     * Build owner-owned entitlement + asset and write the real file to the
     * fake private disk.
     */
    private function makeDeliveredEntitlement(int $limit = 3): array
    {


        $product = Product::create([
            'slug' => 'sec-product-' . uniqid(),
            'item_type' => 'DIGITAL',
            'price' => 30.00,
        ]);

        Storage::disk('private')->put("digital-assets/{$product->id}/secret-file.pdf", '%PDF-1.4 secret-content');

        $asset = DigitalAsset::create([
            'product_id' => $product->id,
            'disk' => 'private',
            'path' => "digital-assets/{$product->id}/secret-file.pdf",
            'original_name' => '../../etc/../../passwd evil name!',
            'mime' => 'application/pdf',
            'size' => 32,
        ]);

        $order = Order::create(['user_id' => $this->owner->id]);
        $item = OrderProduct::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'item_type' => 'DIGITAL',
            'product_quantity' => 1,
        ]);

        $entitlement = DigitalEntitlement::create([
            'order_id' => $order->id,
            'order_product_id' => $item->id,
            'user_id' => $this->owner->id,
            'status' => DigitalEntitlement::STATUS_DELIVERED,
            'delivered_at' => now(),
            'download_limit' => $limit,
        ]);

        $entitlement->assets()->attach($asset->id);

        return [$entitlement, $asset];
    }

    private function signedDownloadUrl(DigitalEntitlement $e, DigitalAsset $a): string
    {
        return URL::temporarySignedRoute(
            'general.digital.download',
            now()->addMinutes(10),
            ['entitlement' => $e->uuid, 'asset' => $a->uuid]
        );
    }

    public function test_owner_can_download_via_valid_signed_url()
    {
        [$e, $a] = $this->makeDeliveredEntitlement();

        $response = $this->get($this->signedDownloadUrl($e, $a));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        // W7: delivery now uses BinaryFileResponse; capture streamed bytes
        // explicitly (getContent() is not populated for file responses).
        ob_start();
        try {
            $response->baseResponse->sendContent(false);
        } finally {
            $sent = ob_get_clean();
        }
        $this->assertStringContainsString(
            'secret-content',
            $sent
        );
    }

    public function test_download_count_increments_and_limit_is_enforced()
    {
        [$e, $a] = $this->makeDeliveredEntitlement(limit: 2);
        $url = fn () => $this->signedDownloadUrl($e, $a);

        $this->get($url())->assertOk();
        $this->get($url())->assertOk();

        // Third attempt exceeds limit = 2.
        $response = $this->get($url());
        $response->assertStatus(403);

        $this->assertSame(2, (int) $e->fresh()->download_count);
    }

    public function test_concurrent_attempts_cannot_exceed_the_limit()
    {
        [$e, $a] = $this->makeDeliveredEntitlement(limit: 1);

        // Simulate a race: both requests pass signature validation, but only
        // ONE may consume the final allowed slot.
        $results = [];
        for ($i = 0; $i < 2; $i++) {
            $resp = $this->get($this->signedDownloadUrl($e, $a));
            $results[] = $resp->getStatusCode();
            $e->refresh();
        }

        $ok = array_filter($results, fn ($s) => $s === 200);
        $blocked = array_filter($results, fn ($s) => $s === 403);

        $this->assertCount(1, $ok);
        $this->assertCount(1, $blocked);
        $this->assertSame(1, (int) $e->fresh()->download_count);
    }

    public function test_tampered_asset_uuid_returns_404()
    {
        [$e, $a] = $this->makeDeliveredEntitlement();

        $url = URL::temporarySignedRoute(
            'general.digital.download',
            now()->addMinutes(10),
            ['entitlement' => $e->uuid, 'asset' => 'not-a-real-uuid']
        );

        // Route constraint (whereUuid) rejects the malformed uuid before the
        // signature check — a 404 that reveals nothing about the entitlement.
        $this->get($url)->assertStatus(404);
    }

    public function test_expired_signature_is_rejected()
    {
        [$e, $a] = $this->makeDeliveredEntitlement();

        $url = URL::temporarySignedRoute(
            'general.digital.download',
            now()->subMinute(),
            ['entitlement' => $e->uuid, 'asset' => $a->uuid]
        );

        $this->get($url)->assertStatus(403);
    }

    public function test_unsigned_direct_access_is_rejected()
    {
        [$e, $a] = $this->makeDeliveredEntitlement();

        $this->get('/api/v1/general/digital/download/' . $e->uuid . '/' . $a->uuid)
            ->assertStatus(403);
    }

    public function test_revoked_entitlement_loses_access_even_with_fresh_signature()
    {
        [$e, $a] = $this->makeDeliveredEntitlement();

        app(\App\Services\Digital\DigitalFulfillmentService::class)->revoke($e);

        $this->get($this->signedDownloadUrl($e->fresh(), $a))->assertStatus(403);
    }

    public function test_pending_entitlement_cannot_download()
    {
        [$e, $a] = $this->makeDeliveredEntitlement();
        $e->update(['status' => DigitalEntitlement::STATUS_PENDING, 'delivered_at' => null]);

        $this->get($this->signedDownloadUrl($e->fresh(), $a))->assertStatus(403);
    }

    public function test_index_lists_only_own_entitlements()
    {
        [$e] = $this->makeDeliveredEntitlement();

        \Marvel\Database\Models\User::withoutEvents(fn () => null);

        $otherOrder = Order::create(['user_id' => $this->owner->id]);
        $otherItem = OrderProduct::create([
            'order_id' => $otherOrder->id,
            'product_id' => $e->orderItem->product_id,
            'item_type' => 'DIGITAL',
            'product_quantity' => 1,
        ]);
        DigitalEntitlement::create([
            'order_id' => $otherOrder->id,
            'order_product_id' => $otherItem->id,
            'user_id' => $this->owner->id,
            'status' => DigitalEntitlement::STATUS_DELIVERED,
        ]);

        $this->actingAs($this->attacker, 'sanctum')
            ->getJson('/api/v1/general/digital/downloads')
            ->assertOk();

        $payloadOwner = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/general/digital/downloads')
            ->json('data');

        $payloadAttacker = $this->actingAs($this->attacker, 'sanctum')
            ->getJson('/api/v1/general/digital/downloads')
            ->json('data');

        $this->assertCount(2, $payloadOwner);   // owner sees their own rows
        $this->assertCount(0, $payloadAttacker ?? []); // attacker sees nothing
    }

    // =========================================================================
    // BD1 OPTION B — LATE ASSET UPLOAD IS PRODUCT-SCOPED
    // =========================================================================

    public function test_asset_uploaded_after_fulfillment_is_granted_to_existing_customer()
    {
        [$e, $a] = $this->makeDeliveredEntitlement();

        // Admin uploads a NEW file AFTER delivery.
        $latePath = "digital-assets/{$e->orderItem->product_id}/late-edition.pdf";
        Storage::disk('private')->put($latePath, '%PDF-1.4 late edition');

        $late = DigitalAsset::create([
            'product_id' => $e->orderItem->product_id,
            'disk' => 'private',
            'path' => $latePath,
            'original_name' => 'Late Edition',
            'mime' => 'application/pdf',
            'size' => 20,
        ]);

        // Appears in the customer's download list without re-fulfillment.
        $payload = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/v1/general/digital/downloads')
            ->json('data');

        $entitlement = collect($payload)->firstWhere('uuid', $e->uuid);
        $this->assertNotNull($entitlement);
        $this->assertContains(
            $late->uuid,
            array_column($entitlement['assets'], 'uuid'),
            'late-uploaded asset must be product-scoped into the entitlement'
        );

        // And it is downloadable through the standard signed flow.
        $this->get($this->signedDownloadUrl($e->fresh(), $late))->assertOk();
    }

    public function test_stored_filename_never_leaks_in_response_headers()
    {
        [$e, $a] = $this->makeDeliveredEntitlement();

        $response = $this->get($this->signedDownloadUrl($e, $a));

        $contentDisposition = $response->headers->get('Content-Disposition') ?? '';
        $this->assertStringNotContainsString('..', $contentDisposition);
        $this->assertStringNotContainsString('/', $contentDisposition);
        $this->assertStringNotContainsString($a->path, $contentDisposition);
    }

    // =========================================================================
    // F2 — MISSING FILE MUST NOT CONSUME A DOWNLOAD CREDIT
    // =========================================================================

    public function test_missing_file_returns_404_and_preserves_credit()
    {
        [$e, $a] = $this->makeDeliveredEntitlement();

        // Simulate storage loss AFTER entitlement delivery.
        Storage::disk('private')->delete($a->path);

        $response = $this->get($this->signedDownloadUrl($e->fresh(), $a->fresh()));

        $response->assertStatus(404);

        $fresh = $e->fresh();
        $this->assertSame(0, (int) $fresh->download_count, 'missing file must not consume a credit');
        $this->assertSame(
            0,
            DB::table('digital_download_logs')->where('entitlement_id', $fresh->id)->count(),
            'no audit log for an unstreamed file'
        );
    }

    public function test_successful_download_increments_once_and_logs_once()
    {
        [$e, $a] = $this->makeDeliveredEntitlement();

        $this->get($this->signedDownloadUrl($e, $a))->assertOk();

        $fresh = $e->fresh();
        $this->assertSame(1, (int) $fresh->download_count);
        $this->assertSame(
            1,
            DB::table('digital_download_logs')->where('entitlement_id', $fresh->id)->count()
        );

        // Hashes are stored — never raw IP / user agent.
        $log = DB::table('digital_download_logs')->where('entitlement_id', $fresh->id)->first();
        $this->assertNotNull($log->ip_hash);
        $this->assertNotNull($log->ua_hash);
        $this->assertStringNotContainsString(request()->ip() ?? '', $log->ip_hash);
    }

    // =========================================================================
    // GATE D — THROTTLE RUNTIME VERIFICATION
    // =========================================================================

    public function test_throttle_returns_429_after_thirty_downloads_per_minute()
    {
        [$e, $a] = $this->makeDeliveredEntitlement(limit: 100);

        $url = $this->signedDownloadUrl($e, $a);
        $statuses = [];

        for ($i = 0; $i < 31; $i++) {
            $statuses[] = $this->get($url)->getStatusCode();
        }

        $first30 = array_slice($statuses, 0, 30);
        $this->assertEquals(array_fill(0, 30, 200), $first30, 'first 30 must stream');
        $this->assertSame(429, $statuses[30], 'request 31 must be rate-limited');
    }
}
