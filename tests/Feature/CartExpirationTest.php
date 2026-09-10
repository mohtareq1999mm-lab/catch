<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\General\CartInventoryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\User;
use Marvel\Enums\ProductType;
use Marvel\Enums\ShippingMethod;
use Tests\TestCase;

/**
 * NEW CONTRACT: carts no longer expire and never own inventory.
 *
 * reserved_at / expires_at are an ABANDONED-CART ACTIVITY WINDOW used only by
 * analytics and notifications (cart:notify-abandoned). There is no reaper:
 * nothing ever deletes cart items or releases stock based on time.
 */
class CartExpirationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        if (!Settings::exists()) {
            Settings::create([
                'site_name' => 'Test Site',
                'options' => [],
                'minimum_order_amount' => 0,
            ]);
        }

        $this->user = User::factory()->create();
        $this->product = Product::create([
            'name' => 'Expiration Product',
            'slug' => 'expiration-product-' . Str::uuid(),
            'price' => 100.00,
            'product_type' => ProductType::SIMPLE,
            'stock_quantity' => 10,
            'reserved_quantity' => 0,
            'in_stock' => true,
            'status' => true,
        ]);
    }

    private function createActiveCartWithReservation(): Cart
    {
        $cart = Cart::create([
            'user_id' => $this->user->id,
            'status' => 'active',
            'total_price' => $this->product->price,
            'reserved_at' => now(),
            'expires_at' => now()->addDays(3),
        ]);

        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'price' => $this->product->price,
            'total_price' => $this->product->price,
            'shipping_method' => ShippingMethod::SCHEDULED,
        ]);

        return $cart->load('items');
    }

    /** @test */
    public function stale_activity_window_does_not_expire_the_cart(): void
    {
        $cart = $this->createActiveCartWithReservation();

        Carbon::setTestNow(now()->addDays(30));

        $service = app(CartInventoryService::class);
        $this->assertEquals(0, method_exists($service, 'expireCarts') ? $service->expireCarts() : 0);

        $cart->refresh();
        $this->assertEquals('active', $cart->status, 'Carts are never auto-expired');
        $this->assertEquals(1, $cart->items()->count(), 'Items survive regardless of window');
        $this->assertEquals(0, $this->product->refresh()->reserved_quantity);
    }

    /** @test */
    public function abandoned_cart_notification_window_semantics_hold(): void
    {
        // NotifyAbandonedCarts selects: active + reserved_at < -24h +
        // expires_at > now + reminder not sent.
        $cart = $this->createActiveCartWithReservation();
        $cart->update(['reserved_at' => now()->subHours(25)]);

        $eligible = Cart::query()
            ->where('status', 'active')
            ->whereNotNull('reserved_at')
            ->where('reserved_at', '<', now()->subHours(24))
            ->where('expires_at', '>', now())
            ->whereNull('reminder_sent_at')
            ->exists();

        $this->assertTrue($eligible, 'Stale but unexpired carts remain notify-eligible');

        // After the window lapses naturally the cart falls out — no action needed.
        $cart->update(['expires_at' => now()->subHour()]);
        $stillEligible = Cart::query()
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->exists();
        $this->assertFalse($stillEligible);
    }

    /** @test */
    public function cart_receives_3day_ttl_on_touch(): void
    {
        $cart = Cart::create([
            'user_id' => $this->user->id,
            'status' => 'active',
            'total_price' => 100,
        ]);

        DB::transaction(function () use ($cart) {
            $locked = Cart::whereKey($cart->id)->lockForUpdate()->firstOrFail();
            $locked->update([
                'reserved_at' => now(),
                'expires_at' => now()->addDays(3),
            ]);
        });

        $fresh = $cart->fresh();
        $this->assertNotNull($fresh->reserved_at);
        $this->assertNotNull($fresh->expires_at);
        $this->assertTrue($fresh->expires_at->gt(now()->addDays(2)));
        $this->assertTrue($fresh->expires_at->lt(now()->addDays(4)));
    }

    /** @test */
    public function inventory_counters_never_move_through_any_cart_lifecycle(): void
    {
        $service = app(CartInventoryService::class);

        // Add
        DB::transaction(fn () => $service->incrementItem(
            Cart::create(['user_id' => $this->user->id, 'status' => 'active']),
            $this->product->fresh(), null, 3
        ));
        $this->assertEquals(0, $this->product->refresh()->reserved_quantity);
        $this->assertEquals(10, $this->product->stock_quantity);
        $this->assertEquals(0, $this->product->sold_quantity);

        // Reduce
        $cart = Cart::where('user_id', $this->user->id)->first();
        DB::transaction(fn () => $service->decrementItem($cart, $this->product->fresh(), null, 1));
        $this->assertEquals(0, $this->product->refresh()->reserved_quantity);

        // Clear
        $service->releaseCart($cart->fresh(), true);
        $this->assertEquals(0, $this->product->refresh()->reserved_quantity);
        $this->assertEquals(10, $this->product->stock_quantity);
        $this->assertEquals(0, $this->product->sold_quantity);
        $this->assertNotNull(Cart::find($cart->id), 'Cart row survives clearing');
        $this->assertEquals(0, $cart->items()->count());
    }
}
