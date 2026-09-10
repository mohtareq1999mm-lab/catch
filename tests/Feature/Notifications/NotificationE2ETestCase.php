<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Contact;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponAssignment;
use Marvel\Database\Models\FlashSale;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Promotion;
use Marvel\Database\Models\Refund;
use Marvel\Database\Models\Review;
use Marvel\Database\Models\User;
use Tests\Concerns\CreatesTestTables;
use Tests\Stubs\RecordingPusher;
use Tests\TestCase;

/**
 * Shared scaffolding for the real-pipeline notification E2E suite.
 *
 * The suite dispatches real domain events so the REAL registered listeners,
 * REAL queue listeners (sync driver), REAL Notification channels (database +
 * broadcast) and the REAL PusherBroadcaster all execute. The only component
 * that is swapped is the underlying Pusher HTTP client, which is replaced by
 * RecordingPusher so we can assert on the exact channels/events/payloads that
 * the broadcaster produces without performing external network calls.
 */
abstract class NotificationE2ETestCase extends TestCase
{
    use DatabaseTransactions;
    use CreatesTestTables;

    protected const API_PREFIX = '/api/v1';

    protected ?RecordingPusher $pusher = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('users')) {
            $this->createAllTestTables();
            $this->createNotificationE2ETables();
        }

        if (config('database.default') === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON;');
        }

        $this->beginDatabaseTransaction();

        $this->setupBroadcastRecorder();
    }

    /**
     * Tables required by the notification domain that the shared concern does
     * not yet provide.
     */
    protected function createNotificationE2ETables(): void
    {
        if (!Schema::hasTable('refunds')) {
            Schema::create('refunds', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->unsignedBigInteger('order_id')->nullable();
                $table->decimal('amount', 10, 2)->nullable();
                $table->string('status')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('contacts')) {
            Schema::create('contacts', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('email');
                $table->string('subject')->nullable();
                $table->text('message')->nullable();
                $table->boolean('is_read')->default(false);
                $table->boolean('is_replay')->default(false);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // RatingRemoved (registered for RefundApproved) filters reviews by order_id.
        if (Schema::hasTable('reviews') && !Schema::hasColumn('reviews', 'order_id')) {
            Schema::table('reviews', function (Blueprint $table) {
                $table->unsignedBigInteger('order_id')->nullable();
            });
        }

        // Console commands (promotions:notify-ending-soon,
        // flash-sales:notify-ending-soon, cart:notify-abandoned) rely on these
        // deduplication columns.
        if (Schema::hasTable('promotions') && !Schema::hasColumn('promotions', 'ending_soon_notified_at')) {
            Schema::table('promotions', function (Blueprint $table) {
                $table->timestamp('ending_soon_notified_at')->nullable();
            });
        }

        // flash_sales in the shared test schema lacks the discount columns the
        // production table and the FlashSaleObserver rely on.
        if (Schema::hasTable('flash_sales') && !Schema::hasColumn('flash_sales', 'type')) {
            Schema::table('flash_sales', function (Blueprint $table) {
                $table->string('type')->default('fixed');
            });
        }

        if (Schema::hasTable('flash_sales') && !Schema::hasColumn('flash_sales', 'discount')) {
            Schema::table('flash_sales', function (Blueprint $table) {
                $table->decimal('discount', 10, 2)->nullable();
            });
        }

        if (Schema::hasTable('flash_sales') && !Schema::hasColumn('flash_sales', 'ending_soon_notified_at')) {
            Schema::table('flash_sales', function (Blueprint $table) {
                $table->timestamp('ending_soon_notified_at')->nullable();
            });
        }

        if (Schema::hasTable('carts') && !Schema::hasColumn('carts', 'reminder_sent_at')) {
            Schema::table('carts', function (Blueprint $table) {
                $table->timestamp('reminder_sent_at')->nullable();
            });
        }
    }

    /**
     * Swap the real Pusher client behind the real PusherBroadcaster with the
     * recording double. The broadcaster instance is a shared singleton, so all
     * subsequent broadcast traffic in the process is recorded.
     */
    protected function setupBroadcastRecorder(): void
    {
        try {
            $broadcaster = Broadcast::driver();
            if ($broadcaster instanceof PusherBroadcaster) {
                $this->pusher = new RecordingPusher();
                $broadcaster->setPusher($this->pusher);
            }
        } catch (\Throwable $e) {
            $this->pusher = null;
        }
    }

    // ==================== FACTORIES ====================

    protected function createUser(string $type = 'user', array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => ucfirst($type) . ' User',
            'email' => $type . '-' . Str::random(6) . '@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => $type,
            'phone_number' => ($type === 'admin' ? '02' : '01') . rand(100000000, 999999999)], $attributes));
    }

    protected function createProduct(array $attributes = []): Product
    {
        $id = DB::table('products')->insertGetId(array_merge([
            'name' => 'Test Product',
            'slug' => 'test-product-' . Str::random(8),
            'price' => 100.00,
            'status' => 'publish',
            'in_stock' => true,
            'stock_quantity' => 10,
            'reserved_quantity' => 0,
            'sold_quantity' => 0,
            'has_discount' => false,
            'discount_type' => null,
            'discount_amount' => null,
            'discount_status' => null,
            'price_after_discount' => null,
            'price_after_flash_sale' => null], $attributes));

        return Product::find($id);
    }

    protected function createOrder(User $user, array $attributes = []): Order
    {
        return Order::withoutEvents(fn () => Order::create(array_merge([
            'user_id' => $user->id,
            'status' => 'pending',
            'payment_status' => 'pending',
            'total_price' => 100.00,
            'price' => 100.00], $attributes)));
    }

    protected function createCoupon(array $attributes = []): Coupon
    {
        return Coupon::withoutEvents(fn () => Coupon::create(array_merge([
            'code' => 'CPN' . strtoupper(Str::random(6)),
            'name' => 'Test Coupon',
            'slug' => 'test-coupon-' . Str::random(8),
            'discount_type' => 'fixed',
            'discount' => 10,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
            'status' => true,
            'used' => 0], $attributes)));
    }

    protected function createCouponAssignment(Coupon $coupon, User $user, array $attributes = []): CouponAssignment
    {
        return CouponAssignment::create(array_merge([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'max_uses' => 1,
            'used' => 0], $attributes));
    }

    protected function createWishlist(User $user, Product $product): void
    {
        DB::table('wishlists')->insert([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'created_at' => now(),
            'updated_at' => now()]);
    }

    protected function createCart(User $user, array $attributes = []): Cart
    {
        $id = DB::table('carts')->insertGetId(array_merge([
            'user_id' => $user->id,
            'status' => 'active',
            'total_price' => 0,
            'reserved_at' => now()->subHours(25),
            'expires_at' => now()->addHour(),
            'created_at' => now(),
            'updated_at' => now()], $attributes));

        return Cart::find($id);
    }

    protected function createPromotion(array $attributes = []): Promotion
    {
        $id = DB::table('promotions')->insertGetId(array_merge([
            'name' => 'Test Promotion',
            'slug' => 'test-promotion-' . Str::random(8),
            'code' => 'PRM' . strtoupper(Str::random(6)),
            'type' => 'fixed',
            'type_amount' => 'fixed',
            'value' => 10,
            'discount' => 10,
            'status' => true,
            'apply_to' => 'specific_products',
            'minimum_order_amount' => 0,
            'usage' => 0], $attributes));

        return Promotion::find($id);
    }

    protected function createFlashSale(array $attributes = []): FlashSale
    {
        $id = DB::table('flash_sales')->insertGetId(array_merge([
            'title' => 'Test Flash Sale',
            'slug' => 'test-flash-sale-' . Str::random(8),
            'status' => true,
            'type' => 'fixed',
            'discount' => 10,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'created_at' => now(),
            'updated_at' => now()], $attributes));

        return FlashSale::find($id);
    }

    protected function attachPromotionProduct(Promotion $promotion, Product $product): void
    {
        DB::table('promotion_product')->insert([
            'promotion_id' => $promotion->id,
            'product_id' => $product->id]);
    }

    protected function attachFlashSaleProduct(FlashSale $flashSale, Product $product): void
    {
        DB::table('flash_sale_products')->insert([
            'flash_sale_id' => $flashSale->id,
            'product_id' => $product->id]);
    }

    protected function createReview(User $user, Product $product, bool $approved = true, array $attributes = []): Review
    {
        $id = DB::table('reviews')->insertGetId(array_merge([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'rating' => 5,
            'comment' => 'Great product',
            'approved' => $approved,
            'created_at' => now(),
            'updated_at' => now()], $attributes));

        return Review::find($id);
    }

    protected function createRefund(User $user, Order $order, array $attributes = []): Refund
    {
        return Refund::withoutEvents(fn () => Refund::create(array_merge([
            'customer_id' => $user->id,
            'order_id' => $order->id,
            'amount' => 50.00,
            'status' => 'approved'], $attributes)));
    }

    protected function createContact(array $attributes = []): Contact
    {
        return Contact::create(array_merge([
            'name' => 'Contact Person',
            'email' => 'contact-' . Str::random(6) . '@example.com',
            'subject' => 'Support Request',
            'message' => 'I need help.',
            'is_read' => false,
            'is_replay' => false], $attributes));
    }

    /**
     * Legacy Marvel adminList() previously used Spatie permission scope for
     * the super_admin identifier (requiring a permission row). Now it uses
     * the role scope — ensure the role exists for the api guard.
     */
    protected function ensureSuperAdminPermission(): void
    {
        if (!\Spatie\Permission\Models\Role::query()
            ->where('name', 'super_admin')
            ->where('guard_name', 'api')
            ->exists()) {
            \Spatie\Permission\Models\Role::create([
                'name' => 'super_admin',
                'guard_name' => 'api']);
        }
        // Clean up legacy permission if it exists — super_admin is a role, not a permission.
        \Spatie\Permission\Models\Permission::query()
            ->where('name', 'super_admin')
            ->where('guard_name', 'api')
            ->delete();
    }

    // ==================== ASSERTIONS ====================

    protected function assertDatabaseNotification(
        User $user,
        string $type,
        ?callable $dataAssertion = null
    ): DatabaseNotification {
        $notification = DatabaseNotification::query()
            ->where('notifiable_id', $user->id)
            ->where('type', $type)
            ->latest()
            ->first();

        $this->assertNotNull($notification, "No {$type} notification was stored for user {$user->id}.");

        if ($dataAssertion) {
            $dataAssertion($notification);
        }

        return $notification;
    }

    protected function assertNoDatabaseNotification(User $user, string $type): void
    {
        $this->assertNull(
            DatabaseNotification::query()
                ->where('notifiable_id', $user->id)
                ->where('type', $type)
                ->latest()
                ->first(),
            "A {$type} notification was unexpectedly stored for user {$user->id}."
        );
    }

    /**
     * Assert the broadcaster produced exactly one broadcast to the given
     * private channel carrying the given notification broadcast type.
     */
    protected function assertBroadcastTo(string $channel, string $event): array
    {
        $this->assertNotNull($this->pusher, 'Broadcast recording is not enabled.');

        $matches = array_values(array_filter(
            $this->pusher->broadcasts,
            fn (array $broadcast) => in_array($channel, $broadcast['channels'], true)
                && $broadcast['event'] === $event
        ));

        $this->assertNotEmpty($matches, "No broadcast to {$channel} with event {$event} was recorded.");

        return $matches[0];
    }

    protected function assertNoBroadcastTo(string $channel, string $event): void
    {
        $this->assertNotNull($this->pusher, 'Broadcast recording is not enabled.');

        foreach ($this->pusher->broadcasts as $broadcast) {
            $this->assertFalse(
                in_array($channel, $broadcast['channels'], true) && $broadcast['event'] === $event,
                "Unexpected broadcast to {$channel} with event {$event} was recorded."
            );
        }
    }

    protected function recordedBroadcasts(): array
    {
        return $this->pusher?->broadcasts ?? [];
    }

    protected function resetBroadcastRecordings(): void
    {
        $this->pusher?->reset();
    }
}
