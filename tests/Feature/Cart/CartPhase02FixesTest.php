<?php

namespace Tests\Feature\Cart;

use App\Notifications\UserAbandonedCartNotification;
use App\Services\General\CartInventoryService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Marvel\Enums\ProductType;
use Tests\TestCase;

/**
 * Phase 02 residual fixes (F-03–F-06). Real MySQL via RefreshDatabase, so
 * the UNIQUE(carts.user_id) backstop, row locks and transactions are
 * genuinely exercised. True multi-process parallelism is NOT executed here
 * (single PHP process); the concurrency-adjacent claims below are proven
 * deterministically and labelled as such.
 */
class CartPhase02FixesTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = '/api/v1';

    private User $user;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        RateLimiter::for('cart', fn () => Limit::none());

        $this->user = User::factory()->create();
        $this->product = Product::create([
            'name' => 'Phase02 Product',
            'slug' => 'phase02-product-' . Str::uuid(),
            'price' => 100.00,
            'product_type' => ProductType::SIMPLE,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 500,
        ]);
    }

    private function auth(User $user = null): void
    {
        Sanctum::actingAs($user ?? $this->user);
    }

    private function addItem(int $quantity, array $overrides = []): \Illuminate\Testing\TestResponse
    {
        $this->auth();

        return $this->postJson(self::PREFIX . '/cart', [
            'item' => array_merge([
                'product_id' => $this->product->id,
                'quantity' => $quantity,
                'shipping_method' => 'scheduled',
            ], $overrides),
        ]);
    }

    private function maxQty(): int
    {
        return CartInventoryService::maxItemQuantity();
    }

    // =========================================================================
    // F-03 — maximum cart-line quantity
    // =========================================================================

    public function test_add_at_maximum_quantity_succeeds(): void
    {
        $this->addItem($this->maxQty())->assertStatus(201);
        $this->assertEquals($this->maxQty(), Cart::where('user_id', $this->user->id)->first()->items->first()->quantity);
    }

    public function test_add_above_maximum_quantity_fails(): void
    {
        $this->addItem($this->maxQty() + 1)->assertStatus(422);
        $this->assertNull(Cart::where('user_id', $this->user->id)->first());
    }

    public function test_update_above_maximum_quantity_fails(): void
    {
        $this->addItem(1)->assertStatus(201);

        $this->auth();
        $this->putJson(self::PREFIX . '/cart/update-item', [
            'item' => [
                'product_id' => $this->product->id,
                'quantity' => $this->maxQty() + 1,
                'operation' => 'increment',
                'shipping_method' => 'SCHEDULED',
            ],
        ])->assertStatus(422);
    }

    public function test_service_rejects_cumulative_quantity_above_maximum(): void
    {
        // The bound applies to the RESULTING line quantity, not the delta.
        $this->addItem($this->maxQty() - 1)->assertStatus(201);

        $this->auth();
        $response = $this->postJson(self::PREFIX . '/cart', [
            'item' => ['product_id' => $this->product->id, 'quantity' => 2, 'shipping_method' => 'scheduled'],
        ]);

        $response->assertStatus(400);
        $this->assertEquals($this->maxQty() - 1, Cart::where('user_id', $this->user->id)->first()->items->first()->quantity);
    }

    public function test_service_layer_enforces_maximum_directly(): void
    {
        $cart = Cart::create(['user_id' => $this->user->id, 'status' => 'active']);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/exceed/i');
        app(CartInventoryService::class)->incrementItem($cart, $this->product, null, $this->maxQty() + 1);
    }

    public function test_bulk_add_above_maximum_fails(): void
    {
        $this->auth();
        $this->postJson(self::PREFIX . '/cart/bulk-items', [
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => $this->maxQty() + 1, 'shipping_method' => 'scheduled'],
            ],
        ])->assertStatus(422);
    }

    public function test_minimum_quantity_behavior_unchanged(): void
    {
        $this->addItem(0)->assertStatus(422);
        $this->assertNull(Cart::where('user_id', $this->user->id)->first());
    }

    // =========================================================================
    // F-04 — one cart per user
    // =========================================================================

    public function test_repeated_creation_returns_same_cart(): void
    {
        $this->addItem(1)->assertStatus(201);
        $firstId = Cart::where('user_id', $this->user->id)->first()->id;

        $this->addItem(2)->assertStatus(201);

        $carts = Cart::where('user_id', $this->user->id)->get();
        $this->assertCount(1, $carts);
        $this->assertEquals($firstId, $carts->first()->id);
        $this->assertEquals(3, $carts->first()->items->first()->quantity);
    }

    public function test_database_rejects_duplicate_cart_for_user(): void
    {
        Cart::create(['user_id' => $this->user->id, 'status' => 'active']);

        try {
            Cart::create(['user_id' => $this->user->id, 'status' => 'active']);
            $this->fail('Duplicate cart insert must violate UNIQUE(carts.user_id).');
        } catch (QueryException $e) {
            $this->assertEquals(1062, $e->errorInfo[1] ?? null);
        }

        $this->assertCount(1, Cart::where('user_id', $this->user->id)->get());
    }

    public function test_two_users_have_separate_carts(): void
    {
        $other = User::factory()->create();

        $this->addItem(1)->assertStatus(201);
        Sanctum::actingAs($other);
        $this->postJson(self::PREFIX . '/cart', [
            'item' => ['product_id' => $this->product->id, 'quantity' => 1, 'shipping_method' => 'scheduled'],
        ])->assertStatus(201);

        $this->assertCount(1, Cart::where('user_id', $this->user->id)->get());
        $this->assertCount(1, Cart::where('user_id', $other->id)->get());
        $this->assertNotEquals(
            Cart::where('user_id', $this->user->id)->first()->id,
            Cart::where('user_id', $other->id)->first()->id
        );
    }

    // =========================================================================
    // F-05 — abandoned-cart notification (atomic claim)
    // =========================================================================

    private function makeAbandonedCart(User $user, array $overrides = []): Cart
    {
        // NOTE: reminder_sent_at is intentionally NOT in Cart::$fillable, so
        // it must be assigned directly (same as the production command does)
        // — passing it via create() would silently discard it.
        $reminder = $overrides['reminder_sent_at'] ?? null;
        unset($overrides['reminder_sent_at']);

        $cart = Cart::create(array_merge([
            'user_id' => $user->id,
            'status' => 'active',
            'reserved_at' => now()->subHours(25),
            'expires_at' => now()->addDays(2),
        ], $overrides));

        if ($reminder !== null) {
            $cart->reminder_sent_at = $reminder;
            $cart->save();
        }

        return $cart;
    }

    public function test_eligible_cart_is_notified_once_and_stamped(): void
    {
        Notification::fake();
        $cart = $this->makeAbandonedCart($this->user);

        $this->artisan('cart:notify-abandoned')->assertSuccessful();

        Notification::assertSentTo($this->user, UserAbandonedCartNotification::class, 1);
        $this->assertNotNull($cart->refresh()->reminder_sent_at);
    }

    public function test_repeated_runs_do_not_duplicate_notification(): void
    {
        // Deterministic proxy for concurrent workers: the second run must
        // find nothing claimable because the first run stamped the row.
        Notification::fake();
        $this->makeAbandonedCart($this->user);

        $this->artisan('cart:notify-abandoned')->assertSuccessful();
        $this->artisan('cart:notify-abandoned')->assertSuccessful();

        Notification::assertSentTo($this->user, UserAbandonedCartNotification::class, 1);
    }

    public function test_already_reminded_cart_is_skipped(): void
    {
        Notification::fake();
        $this->makeAbandonedCart($this->user, ['reminder_sent_at' => now()->subHour()]);

        $this->artisan('cart:notify-abandoned')->assertSuccessful();

        Notification::assertNotSentTo($this->user, UserAbandonedCartNotification::class);
    }

    public function test_non_customer_cart_is_skipped(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $this->makeAbandonedCart($admin);

        $this->artisan('cart:notify-abandoned')->assertSuccessful();

        Notification::assertNotSentTo($admin, UserAbandonedCartNotification::class);
    }

    public function test_inactive_cart_is_skipped(): void
    {
        Notification::fake();
        $this->makeAbandonedCart($this->user, [
            'reserved_at' => now()->subHour(),
            'expires_at' => now()->addDays(3),
        ]);

        $this->artisan('cart:notify-abandoned')->assertSuccessful();

        Notification::assertNotSentTo($this->user, UserAbandonedCartNotification::class);
    }

    public function test_abandoned_notifier_is_scheduled_with_single_server_protection(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());
        $event = $events->first(fn ($e) => str_contains((string) $e->command, 'cart:notify-abandoned'));

        $this->assertNotNull($event, 'cart:notify-abandoned must be scheduled');
        $this->assertTrue($event->withoutOverlapping, 'scheduler must not overlap');
        $this->assertTrue($event->onOneServer, 'scheduler must run on one server');
    }

    // =========================================================================
    // F-06 — cart-ID existence oracle
    // =========================================================================

    public function test_owner_can_retrieve_cart(): void
    {
        $this->addItem(1)->assertStatus(201);
        $cart = Cart::where('user_id', $this->user->id)->first();

        $this->auth();
        $this->getJson(self::PREFIX . "/cart/{$cart->id}")
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    public function test_nonexistent_cart_returns_404(): void
    {
        $this->auth();
        $this->getJson(self::PREFIX . '/cart/999999')->assertStatus(404);
    }

    public function test_foreign_cart_returns_404_without_leaking_existence(): void
    {
        $this->addItem(1)->assertStatus(201);
        $cart = Cart::where('user_id', $this->user->id)->first();

        $other = User::factory()->create();
        Sanctum::actingAs($other);

        $response = $this->getJson(self::PREFIX . "/cart/{$cart->id}");
        $response->assertStatus(404);
        $response->assertJsonPath('status', false);
        $this->assertStringNotContainsString((string) $cart->id, $response->getContent());
    }
}
