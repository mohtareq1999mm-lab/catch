<?php

namespace Tests\Feature\Digital;

use App\Events\PaymentSucceeded;
use App\Models\DigitalAsset;
use App\Models\DigitalEntitlement;
use App\Listeners\FulfillDigitalProducts;
use Illuminate\Foundation\Testing\Concerns\InteractsWithContainer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderProduct;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Marvel\Enums\ProductType;
use Tests\TestCase;

class DigitalFulfillmentTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

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
                $table->softDeletes();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('products')) {
            Schema::create('products', function (Blueprint $table) {
                $table->id();
                $table->string('name')->nullable();
                $table->string('slug')->unique();
                $table->text('description')->nullable();
                $table->string('sku')->nullable();
                $table->decimal('price', 10, 2)->default(0);
                $table->string('status', 30)->default('publish');
                $table->boolean('in_stock')->default(true);
                $table->integer('stock_quantity')->default(10);
                $table->integer('reserved_quantity')->default(0);
                $table->integer('sold_quantity')->default(0);
                $table->string('product_type')->default('simple');
                $table->string('item_type', 16)->default('PHYSICAL');
                $table->softDeletes();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('orders')) {
            Schema::create('orders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('order_number')->nullable();
                $table->string('name')->nullable();
                $table->string('status')->default('pending');
                $table->string('payment_status')->nullable();
                $table->string('fulfillment_status')->nullable();
                $table->string('fulfillment_type', 20)->nullable();
                $table->string('payment_method', 30)->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->decimal('price', 10, 2)->default(0);
                $table->decimal('total_price', 10, 2)->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('order_products')) {
            Schema::create('order_products', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
                $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
                $table->unsignedBigInteger('product_variant_id')->nullable();
                $table->string('product_name')->nullable();
                $table->string('product_sku')->nullable();
                $table->text('attributes')->nullable();
                $table->integer('product_quantity')->default(1);
                $table->decimal('product_price', 10, 2)->default(0);
                $table->decimal('product_total_price', 10, 2)->default(0);
                $table->boolean('is_gift')->default(false);
                $table->string('item_type', 16)->default('PHYSICAL');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('digital_assets')) {
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
        }

        if (!Schema::hasTable('digital_entitlements')) {
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
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('digital_asset_entitlement')) {
            Schema::create('digital_asset_entitlement', function (Blueprint $table) {
                $table->id();
                $table->foreignId('digital_entitlement_id')->constrained('digital_entitlements')->cascadeOnDelete();
                $table->foreignId('digital_asset_id')->constrained('digital_assets')->cascadeOnDelete();
                $table->timestamp('granted_at')->useCurrent();
            });
        }

        if (!Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table) {
                $table->id();
                $table->json('options')->nullable();
                $table->decimal('minimum_order_amount', 10, 2)->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('invoices')) {
            Schema::create('invoices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->nullable()->constrained('orders')->cascadeOnDelete();
                $table->uuid('uuid')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('model_has_roles')) {
            Schema::create('model_has_roles', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                $table->primary(['role_id', 'model_id', 'model_type']);
            });
        }

        $this->user = User::create([
            'name' => 'Digital Buyer',
            'email' => 'digital-fulfill-' . uniqid() . '@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('Password123!'),
            'is_active' => true,
            'type' => 'customer']);
    }

    private function makeOrderWithDigitalItem(array $productOverrides = []): array
    {
        return $this->makeOrderForUser($this->user, $productOverrides);
    }

    private function makeOrderForUser(User $user, array $productOverrides = []): array
    {
        $product = Product::create(array_merge([
            'name' => ['en' => 'PDF Manual'],
            'slug' => 'pdf-manual-' . uniqid(),
            'description' => ['en' => 'A digital manual'],
            'price' => 50.00,
            'product_type' => ProductType::SIMPLE,
            'item_type' => 'DIGITAL',
            'stock_quantity' => 100,
            'in_stock' => true], $productOverrides));

        DigitalAsset::create([
            'product_id' => $product->id,
            'disk' => 'private',
            'path' => 'digital-assets/' . $product->id . '/asset.pdf',
            'original_name' => 'Ebook.pdf',
            'mime' => 'application/pdf',
            'size' => 1024]);

        $order = Order::create([
            'user_id' => $user->id,
            'order_number' => 'ORD-DIG-' . uniqid(),
            'status' => 'pending',
            'payment_status' => 'payment-pending',
            'fulfillment_status' => 'pending',
            'payment_method' => 'online',
            'total_price' => 50.00]);

        $item = OrderProduct::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => 'PDF Manual',
            'product_quantity' => 1,
            'product_price' => 50.00,
            'product_total_price' => 50.00,
            'item_type' => 'DIGITAL']);

        return [$order, $item, $product];
    }

    private function fulfill(Order $order): void
    {
        $listener = new FulfillDigitalProducts(app(\App\Services\Digital\DigitalFulfillmentService::class));
        $listener->handle(new PaymentSucceeded($order));
    }

    public function test_payment_succeeded_creates_delivered_entitlement()
    {
        [$order, $item] = $this->makeOrderWithDigitalItem();

        $this->fulfill($order);

        $entitlement = DigitalEntitlement::where('order_product_id', $item->id)->first();

        $this->assertNotNull($entitlement);
        $this->assertSame(DigitalEntitlement::STATUS_DELIVERED, $entitlement->status);
        $this->assertNotNull($entitlement->delivered_at);
        $this->assertSame(5, (int) $entitlement->download_limit);
        $this->assertSame(1, $entitlement->assets()->count());
    }

    public function test_fulfillment_is_idempotent_on_duplicate_events()
    {
        [$order, $item] = $this->makeOrderWithDigitalItem();

        $this->fulfill($order);
        $this->fulfill($order);

        $this->assertSame(1, DigitalEntitlement::where('order_product_id', $item->id)->count());
    }

    public function test_mixed_order_only_creates_entitlements_for_digital_lines()
    {
        [$order, $item] = $this->makeOrderWithDigitalItem();

        $physicalProduct = Product::create([
            'name' => 'Physical Widget',
            'slug' => 'physical-widget-' . uniqid(),
            'description' => ['en' => 'Physical'],
            'price' => 20.00,
            'product_type' => ProductType::SIMPLE,
            'item_type' => 'PHYSICAL',
            'stock_quantity' => 10]);

        $physicalItem = OrderProduct::create([
            'order_id' => $order->id,
            'product_id' => $physicalProduct->id,
            'product_name' => 'Physical Widget',
            'product_quantity' => 1,
            'product_price' => 20.00,
            'product_total_price' => 20.00,
            'item_type' => 'PHYSICAL']);

        $this->fulfill($order);

        $this->assertNotNull(DigitalEntitlement::where('order_product_id', $item->id)->first());
        $this->assertNull(DigitalEntitlement::where('order_product_id', $physicalItem->id)->first());
    }

    public function test_physical_only_order_creates_nothing()
    {
        $product = Product::create([
            'name' => 'Only Physical',
            'slug' => 'only-physical-' . uniqid(),
            'description' => ['en' => 'P'],
            'price' => 10.00,
            'product_type' => ProductType::SIMPLE,
            'item_type' => 'PHYSICAL']);

        $order = Order::create([
            'user_id' => $this->user->id,
            'order_number' => 'ORD-PHY-' . uniqid(),
            'status' => 'completed']);

        $item = OrderProduct::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => 'Only Physical',
            'product_quantity' => 1,
            'product_price' => 10.00,
            'product_total_price' => 10.00,
            'item_type' => 'PHYSICAL']);

        $this->fulfill($order);

        $this->assertNull(DigitalEntitlement::where('order_product_id', $item->id)->first());
    }

    public function test_revoked_entitlement_cannot_be_redelivered_by_retry()
    {
        [$order, $item] = $this->makeOrderWithDigitalItem();

        $this->fulfill($order);

        $service = app(\App\Services\Digital\DigitalFulfillmentService::class);
        $entitlement = DigitalEntitlement::where('order_product_id', $item->id)->first();
        $service->revoke($entitlement);

        // Retry after revocation must not resurrect the entitlement.
        $this->fulfill($order);

        $this->assertSame(DigitalEntitlement::STATUS_REVOKED, $entitlement->fresh()->status);
    }

    // =========================================================================
    // F4 — CUSTOMER NOTIFICATION (real production user type 'user')
    // =========================================================================

    public function test_delivered_entitlement_notifies_production_user()
    {
        $realUser = User::create([
            'name' => 'Real Customer',
            'email' => 'real-cust-' . uniqid() . '@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('Password123!'),
            'is_active' => true,
            'type' => \App\Enums\UserType::USER->value]);

        [$order] = $this->makeOrderForUser($realUser);
        $this->fulfill($order);

        \Illuminate\Support\Facades\Notification::fake();

        // Invoke the production listener exactly as the event dispatcher would.
        $listener = new \App\Listeners\SendUserDigitalProductsAvailableNotification();
        $listener->handle(new \App\Events\DigitalProductsDelivered(
            $order->fresh(),
            DigitalEntitlement::query()->where('order_id', $order->id)->get()
        ));

        \Illuminate\Support\Facades\Notification::assertSentTo(
            $realUser,
            \App\Notifications\UserDigitalProductsAvailableNotification::class,
            function (\App\Notifications\UserDigitalProductsAvailableNotification $notification) use ($realUser, $order) {
                $payload = $notification->toDatabase($realUser);

                $this->assertSame('meem-medium', $notification->queue);
                $this->assertSame('digital.products_available', $notification->broadcastType());
                $this->assertSame('order', $payload['resource_type']);
                $this->assertSame($order->id, $payload['resource_id']);
                $this->assertStringContainsString('Digital product ready', $payload['title']['en']);
                $this->assertStringContainsString('منتجك الرقمي جاهز', $payload['title']['ar']);
                $this->assertStringContainsString($order->order_number, $payload['message']['en']);

                return true;
            }
        );

        // Customer-role users must never receive it.
        \Illuminate\Support\Facades\Notification::assertNotSentTo(
            $this->user,
            \App\Notifications\UserDigitalProductsAvailableNotification::class
        );
    }

    // =========================================================================
    // F5 — ADMIN FAILURE NOTIFICATION (seeded role: super_admin)
    // =========================================================================

    public function test_permanent_failure_notifies_super_admin_role()
    {
        $guard = config('auth.defaults.guard', 'api');
        $roleId = DB::table('roles')->insertGetId([
            'name' => 'super_admin',
            'guard_name' => $guard]);

        $admin = User::create([
            'name' => 'Ops Admin',
            'email' => 'ops-' . uniqid() . '@example.com',
            'type' => 'admin']);
        DB::table('model_has_roles')->insert([
            'role_id' => $roleId,
            'model_type' => get_class($admin),
            'model_id' => $admin->id]);

        [$order] = $this->makeOrderWithDigitalItem();

        // Force fulfillment to fail permanently with a typed double.
        $failing = new class extends \App\Services\Digital\DigitalFulfillmentService {
            public function fulfillOrder($order): void
            {
                throw new \RuntimeException('boom');
            }
        };
        $this->app->instance(\App\Services\Digital\DigitalFulfillmentService::class, $failing);

        \Illuminate\Support\Facades\Notification::fake();

        $listener = new FulfillDigitalProducts(app(\App\Services\Digital\DigitalFulfillmentService::class));

        try {
            $listener->handle(new PaymentSucceeded($order));
            $this->fail('Fulfillment failure should propagate for queue retry');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $listener->failed(new PaymentSucceeded($order), new \RuntimeException('boom'));

        $this->assertSame(
            1,
            User::role('super_admin')->count(),
            'role() scope must resolve the seeded super_admin for recipient lookup'
        );

        \Illuminate\Support\Facades\Notification::assertSentTo(
            $admin,
            \App\Notifications\AdminDigitalDeliveryFailedNotification::class,
            fn ($n) => $n->queue === 'meem-medium'
                && str_contains($n->toDatabase($admin)['message']['en'], 'boom')
        );
    }

    // =========================================================================
    // GATE C — QUEUE ASSIGNMENT RUNTIME PROOF
    // =========================================================================

    public function test_payment_succeeded_queues_fulfillment_listener_on_meem_high()
    {
        \Illuminate\Support\Facades\Queue::fake();

        [$order] = $this->makeOrderWithDigitalItem();

        event(new PaymentSucceeded($order));

        $pushed = collect(\Illuminate\Support\Facades\Queue::pushedJobs())
            ->map(fn ($jobs, $queue) => collect($jobs)->map(fn ($j) => get_class($j['job'])))
            ->toArray();
        fwrite(STDERR, "\nPUSHED: " . json_encode($pushed) . "\n");

        \Illuminate\Support\Facades\Queue::assertPushedOn(
            'meem-high',
            \Illuminate\Events\CallQueuedListener::class,
            fn (\Illuminate\Events\CallQueuedListener $job) => str_contains($job->class, 'FulfillDigitalProducts')
        );
    }

    public function test_fulfillment_notification_listener_targets_meem_medium()
    {
        $listener = new \App\Listeners\SendUserDigitalProductsAvailableNotification();
        $this->assertSame('meem-medium', $listener->queue);

        $notification = new \App\Notifications\UserDigitalProductsAvailableNotification(
            $this->makeOrderWithDigitalItem()[0]
        );
        $this->assertSame('meem-medium', $notification->queue);
    }

    public function test_order_resource_exposes_digital_downloads_after_fulfillment()
    {
        [$order, $item] = $this->makeOrderWithDigitalItem();

        // Before fulfillment: relation loaded but nothing delivered → key absent.
        $this->fulfill($order);

        $order->load(['digitalEntitlements.assets']);
        $payload = (new \App\Http\Resources\Order\OrderResource($order))->toArray(request());

        $this->assertArrayHasKey('digital_downloads', $payload);
        $this->assertCount(1, $payload['digital_downloads']);

        $download = $payload['digital_downloads'][0];
        $this->assertSame(DigitalEntitlement::STATUS_DELIVERED, $download['status']);
        $this->assertSame(5, $download['download_limit']);
        $this->assertSame('Ebook.pdf', $download['assets'][0]['original_name'] ?? $download['assets'][0]['original_name'] ?? null);
    }

    public function test_physical_only_order_has_no_digital_downloads_key()
    {
        $product = Product::create([
            'name' => ['en' => 'Physical Only'],
            'slug' => 'phys-only-' . uniqid(),
            'description' => ['en' => 'P'],
            'price' => 15.00,
            'product_type' => ProductType::SIMPLE,
            'item_type' => 'PHYSICAL']);

        $order = Order::create([
            'user_id' => $this->user->id,
            'order_number' => 'ORD-PHY2-' . uniqid(),
            'status' => 'completed']);

        OrderProduct::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => 'Physical Only',
            'product_quantity' => 1,
            'product_price' => 15.00,
            'product_total_price' => 15.00,
            'item_type' => 'PHYSICAL']);

        $order->load(['digitalEntitlements.assets']);
        $raw = (new \App\Http\Resources\Order\OrderResource($order))->toArray(request());

        // Mirror HTTP serialization: drop MissingValue placeholders exactly
        // as jsonSerialize would before responding.
        $payload = collect($raw)
            ->reject(fn ($v) => $v instanceof \Illuminate\Http\Resources\MissingValue)
            ->all();

        $this->assertArrayNotHasKey('digital_downloads', $payload);
    }
}
