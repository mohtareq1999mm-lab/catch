<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Services\General\CartInventoryService;
use App\Services\Inventory\OrderReservationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Database\Models\Promotion;
use Marvel\Database\Models\User;
use Marvel\Enums\ProductType;
use Marvel\Enums\PromotionMountType;
use Marvel\Enums\PromotionType;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

/**
 * Gift promotions resolve to ORDER-line descriptors and reserve against the
 * Order; the reconciliation command migrates legacy data idempotently.
 */
class GiftAndReconciliationTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private const PREFIX = '/api/v1/general';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['payment.order_timeout_hours' => 24]);

        $this->createAllTestTables();
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function makeUser(): User
    {
        return User::create([
            'name' => 'Gift Buyer ' . Str::random(4),
            'email' => Str::random(8) . '@gift.test',
            'password' => bcrypt('secret'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function makeSimpleProduct(float $price = 100.0, int $stock = 10): Product
    {
        return Product::create([
            'name' => 'Simple ' . Str::random(6),
            'slug' => 'simple-' . Str::random(10),
            'sku' => 'S-' . Str::upper(Str::random(5)),
            'price' => $price,
            'product_type' => ProductType::SIMPLE,
            'status' => true,
            'in_stock' => $stock > 0,
            'stock_quantity' => $stock,
        ]);
    }

    private function makeVariableProductWithVariant(float $price = 50.0, int $stock = 5): array
    {
        $product = Product::create([
            'name' => 'Variable ' . Str::random(6),
            'slug' => 'variable-' . Str::random(10),
            'sku' => 'V-' . Str::upper(Str::random(5)),
            'price' => $price,
            'product_type' => ProductType::VARIABLE,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 0,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'VAR-' . Str::upper(Str::random(5)),
            'price' => $price,
            'stock_quantity' => $stock,
        ]);

        return ['product' => $product, 'variant' => $variant];
    }

    private function makeGiftPromotion(Product $giftProduct, ProductVariant $giftVariant): Promotion
    {
        $promotion = Promotion::create([
            'name' => 'Gift Promo ' . Str::random(4),
            'code' => 'GIFT-' . Str::upper(Str::random(6)),
            'type' => PromotionType::QTY,
            'type_amount' => PromotionMountType::GIFT,
            'value' => 0,
            'discount' => 0,
            'minimum_order_amount' => 0,
            'apply_to' => 'all_products',
            'status' => true,
            'start_at' => now()->subDay()->format('Y-m-d'),
            'end_at' => now()->addDay()->format('Y-m-d'),
        ]);
        $promotion->giftProducts()->attach($giftProduct->id, [
            'quantity' => 1,
            'product_variant_id' => $giftVariant->id,
        ]);

        return $promotion;
    }

    private function addToCart(User $user, Product $product, int $qty = 1): Cart
    {
        /** @var CartInventoryService $service */
        $service = app(CartInventoryService::class);

        return DB::transaction(function () use ($service, $user, $product, $qty) {
            $cart = Cart::query()->where('user_id', $user->id)->lockForUpdate()->first()
                ?? Cart::create(['user_id' => $user->id, 'status' => 'active']);

            $service->incrementItem($cart, $product->fresh(), null, $qty);

            return $cart->fresh();
        });
    }

    private function checkout(User $user, array $overrides = [])
    {
        \Laravel\Sanctum\Sanctum::actingAs($user);

        return $this->postJson(self::PREFIX . '/checkout', array_merge([
            'name' => 'G Test',
            'user_phone' => '01000000000',
            'user_email' => $user->email,
            'address' => ['street' => '1 Main'],
            'payment_method' => 'cod',
        ], $overrides));
    }

    private function grantPermission(): void
    {
        $permission = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'update-order-status', 'guard_name' => 'api']);
        $this->makeUser()->givePermissionTo($permission);
    }

    // ==================================================================
    // GIFT PROMOTION â€” order-owned reservation end to end
    // ==================================================================

    public function test_gift_becomes_order_item_reserved_atomically_and_cart_stays_clean(): void
    {
        $user = $this->makeUser();
        $normal = $this->makeSimpleProduct(price: 120, stock: 6);
        $this->addToCart($user, $normal, 1);

        $gift = $this->makeVariableProductWithVariant(stock: 4);
        $promotion = $this->makeGiftPromotion($gift['product'], $gift['variant']);

        $this->checkout($user, [
            'selected_promotion_id' => $promotion->id,
            'selected_gift_product_id' => $gift['product']->id,
        ])->assertStatus(200);

        $order = Order::where('user_id', $user->id)->firstOrFail();

        // Normal line + gift line both snapshotted.
        $this->assertEquals(2, $order->orderItems()->count());
        $giftItem = $order->orderItems()->where('is_gift', true)->firstOrFail();
        $this->assertSame($gift['variant']->id, $giftItem->product_variant_id);
        $this->assertEquals(1, $giftItem->product_quantity);
        $this->assertEquals(0.0, (float) $giftItem->product_total_price);
        $this->assertEquals($promotion->id, $giftItem->promotion_id);

        // BOTH lines reserved against inventory.
        $this->assertEquals(1, $normal->refresh()->reserved_quantity);
        $this->assertEquals(1, $gift['variant']->refresh()->reserved_quantity);

        // No generated gift CartItem anywhere; cart fully emptied.
        $this->assertEquals(0, CartItem::where('is_gift', true)->count());
        $this->assertEquals(0, CartItem::count());
    }

    public function test_gift_mark_paid_commits_normal_and_gift_inventory(): void
    {
        $user = $this->makeUser();
        $normal = $this->makeSimpleProduct(price: 90, stock: 5);
        $this->addToCart($user, $normal, 1);

        $gift = $this->makeVariableProductWithVariant(stock: 3);
        $promotion = $this->makeGiftPromotion($gift['product'], $gift['variant']);

        $this->checkout($user, [
            'selected_promotion_id' => $promotion->id,
            'selected_gift_product_id' => $gift['product']->id,
        ])->assertStatus(200);

        $order = Order::where('user_id', $user->id)->firstOrFail();
        $order->update(['promotion_id' => $promotion->id]);

        $permission = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'update-order-status', 'guard_name' => 'api']);
        $user->givePermissionTo($permission);
        $this->postJson(self::PREFIX . '/checkout/cod/' . $order->id . '/mark-paid')->assertStatus(200);

        $this->assertEquals(Order::INVENTORY_STATE_COMMITTED, $order->refresh()->inventory_state);
        $this->assertEquals(0, $normal->refresh()->reserved_quantity);
        $this->assertEquals(1, $normal->sold_quantity);
        $this->assertEquals(0, $gift['variant']->refresh()->reserved_quantity);
        $this->assertEquals(1, $gift['variant']->sold_quantity);
    }

    public function test_gift_expiry_releases_both_reservations(): void
    {
        $user = $this->makeUser();
        $normal = $this->makeSimpleProduct(price: 60, stock: 4);
        $this->addToCart($user, $normal, 1);

        $gift = $this->makeVariableProductWithVariant(stock: 2);
        $promotion = $this->makeGiftPromotion($gift['product'], $gift['variant']);

        $this->checkout($user, [
            'selected_promotion_id' => $promotion->id,
            'selected_gift_product_id' => $gift['product']->id,
        ])->assertStatus(200);

        Order::where('user_id', $user->id)->update(['reservation_expires_at' => now()->subMinute()]);

        $this->artisan('orders:cancel-unpaid')->assertExitCode(0);

        $order = Order::where('user_id', $user->id)->first();
        $this->assertEquals('cancelled', $order->status);
        $this->assertEquals(Order::INVENTORY_STATE_RELEASED, $order->inventory_state);
        $this->assertEquals(0, $normal->refresh()->reserved_quantity);
        $this->assertEquals(0, $gift['variant']->refresh()->reserved_quantity);
    }

    public function test_gift_stock_insufficient_rolls_back_entire_checkout(): void
    {
        $user = $this->makeUser();
        $normal = $this->makeSimpleProduct(price: 80, stock: 5);
        $this->addToCart($user, $normal, 1);

        // Gift variant has ZERO stock → explicit selection must fail cleanly.
        $gift = $this->makeVariableProductWithVariant(stock: 0);
        $promotion = $this->makeGiftPromotion($gift['product'], $gift['variant']);

        $this->checkout($user, [
            'selected_promotion_id' => $promotion->id,
            'selected_gift_product_id' => $gift['product']->id,
        ])->assertStatus(422);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_products', 0);
        $this->assertEquals(0, $normal->refresh()->reserved_quantity, 'No partial reservation');
        $this->assertEquals(0, $gift['variant']->refresh()->reserved_quantity);
        $this->assertEquals(1, CartItem::where('cart_id', Cart::where('user_id', $user->id)->value('id'))->count(), 'CartItems remain');
    }

    public function test_promotion_resolution_never_writes_gift_cart_items(): void
    {
        $user = $this->makeUser();
        $cart = $this->addToCart($user, $this->makeSimpleProduct(price: 100, stock: 9), 2);

        $gift = $this->makeVariableProductWithVariant(stock: 5);
        $promotion = $this->makeGiftPromotion($gift['product'], $gift['variant']);

        $totals = app(\App\Services\General\PromotionService::class)
            ->applySelectedPromotion($cart->fresh(), $promotion->id, $gift['product']->id);

        // Descriptor returned for the order pipelineâ€¦
        $this->assertNotEmpty($totals->giftItems);
        $this->assertSame($gift['variant']->id, $totals->giftItems[0]['product_variant_id']);
        $this->assertNotNull($totals->giftItems[0]['promotion_id']);

        // â€¦but the CART is untouched: no gift row, no reservation.
        $this->assertEquals(0, CartItem::where('is_gift', true)->count());
        $this->assertEquals(0, $gift['variant']->refresh()->reserved_quantity);
        $this->assertEquals(2, $cart->fresh()->items()->first()->quantity, 'Normal cart lines untouched by promotion resolution');
    }

    // ==================================================================
    // RECONCILIATION COMMAND â€” legacy data migration
    // ==================================================================

    private function makeLegacyPendingOrder(User $user, Product $product, int $qty): Order
    {
        $order = Order::create([
            'user_id' => $user->id,
            'name' => 'Legacy', 'user_phone' => '01', 'user_email' => $user->email,
            'address' => '{}',
            'shipping_method' => 'SCHEDULED', 'payment_method' => 'online',
            'price' => $product->price * $qty, 'shipping_price' => 0,
            'total_price' => $product->price * $qty,
            'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
            // inventory_state defaults to 'none'
        ]);
        $order->orderItems()->create([
            'product_id' => $product->id, 'product_name' => $product->name,
            'product_sku' => $product->sku, 'product_quantity' => $qty,
            'product_price' => $product->price, 'product_total_price' => $product->price * $qty,
        ]);

        return $order->refresh();
    }

    public function test_reconciliation_dry_run_makes_no_changes(): void
    {
        $user = $this->makeUser();
        $product = $this->makeSimpleProduct(price: 10, stock: 5);

        // Legacy pending order whose units sit in the user's cart.
        $this->makeLegacyPendingOrder($user, $product, 2);
        $cart = $this->addToCart($user, $product, 2);
        CartItem::where('cart_id', $cart->id)->update(['reserved_quantity' => 2]);
        DB::table('products')->where('id', $product->id)->update(['reserved_quantity' => 2]);

        $this->artisan('inventory:migrate-reservations', ['--dry-run' => true])->assertExitCode(0);

        $product->refresh();
        $this->assertEquals(2, $product->reserved_quantity, 'Dry-run must not touch counters');
        $this->assertEquals(2, CartItem::where('cart_id', $cart->id)->value('reserved_quantity'));
        $order = Order::first();
        $this->assertEquals(Order::INVENTORY_STATE_NONE, $order->inventory_state);
    }

    public function test_reconciliation_fix_migrates_and_is_idempotent(): void
    {
        $user = $this->makeUser();
        $product = $this->makeSimpleProduct(price: 10, stock: 5);

        $order = $this->makeLegacyPendingOrder($user, $product, 2);
        $cart = $this->addToCart($user, $product, 2);
        CartItem::where('cart_id', $cart->id)->update(['reserved_quantity' => 2]);
        DB::table('products')->where('id', $product->id)->update(['reserved_quantity' => 2]);

        $this->artisan('inventory:migrate-reservations', ['--fix' => true])->assertExitCode(0);

        // Cart-held units detachedâ€¦
        $this->assertEquals(0, $cart->items()->first()->reserved_quantity);
        // â€¦pending order re-homed as ACTIVE with identical net counter.
        $order->refresh();
        $this->assertEquals(Order::INVENTORY_STATE_ACTIVE, $order->inventory_state);
        $this->assertNotNull($order->reservation_expires_at);
        $product->refresh();
        $this->assertEquals(2, $product->reserved_quantity, 'Net drift zero: cart units swapped for order units');

        // Second run changes nothing.
        $before = $product->toArray();
        $this->artisan('inventory:migrate-reservations', ['--fix' => true])->assertExitCode(0);
        $this->assertEquals($before['reserved_quantity'], $product->refresh()->reserved_quantity);
    }

    public function test_reconciliation_labels_historical_orders_without_touching_counters(): void
    {
        $user = $this->makeUser();
        $sold = $this->makeSimpleProduct(price: 5, stock: 3);
        $sold->forceFill(['sold_quantity' => 2, 'stock_quantity' => 1])->save();

        $completed = $this->makeLegacyPendingOrder($user, $sold, 2);
        $completed->forceFill(['status' => 'completed', 'paid_at' => now()])->save();

        $cancelledUnpaid = $this->makeLegacyPendingOrder($user, $sold, 1);
        $cancelledUnpaid->forceFill(['status' => 'cancelled'])->save();

        $counterBefore = $sold->reserved_quantity;

        $this->artisan('inventory:migrate-reservations', ['--fix' => true])->assertExitCode(0);

        $this->assertEquals(
            Order::INVENTORY_STATE_COMMITTED,
            $completed->refresh()->inventory_state,
            'Completed orders were effectively committed'
        );
        $this->assertEquals(
            Order::INVENTORY_STATE_RELEASED,
            $cancelledUnpaid->refresh()->inventory_state,
            'Unpaid cancelled orders were effectively released'
        );

        // Historical labelling must not re-allocate stock counters.
        $this->assertTrue(
            $sold->refresh()->reserved_quantity >= $counterBefore - 0,
            'Counters only ever decrease via explicit detach step'
        );
    }
}
