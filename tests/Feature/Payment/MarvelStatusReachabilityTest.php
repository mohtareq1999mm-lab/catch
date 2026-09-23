<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\CheckoutTotals;
use App\Models\Currency;
use App\Services\Checkout\OrderCreationService;
use App\Services\Currency\CurrencyService;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Order;
use Tests\Feature\Currency\CurrencyTestCase;

/**
 * SHOULD-FIX (d): Marvel PATCH /api/v1/orders/{id}/status reachability proof.
 *
 * RESULT: BLOCKED. The F-1 authority gate in OrderService::changeOrderStatus()
 * now rejects completed-transitions on UNPAID orders when the authenticated
 * actor lacks `payments.mark_paid` (422, order untouched). An admin holding
 * ONLY `update-order-status` can no longer financially complete an order via
 * the legacy path; paid orders and non-completed transitions are unaffected.
 */
class MarvelStatusReachabilityTest extends CurrencyTestCase
{
    /** @test */
    public function marvel_patch_reaches_completed_payment_success_without_mark_paid(): void
    {
        $this->seedCurrencyData();
        app(CurrencyService::class)->setBaseCurrency(Currency::query()->where('code', 'KWD')->firstOrFail());
        app(CurrencyService::class)->setCatalogCurrency(Currency::query()->where('code', 'KWD')->firstOrFail());

        $customer = $this->createCustomer();
        $cart = Cart::create(['user_id' => $customer->id, 'status' => 'active', 'total_price' => 50.0]);
        $order = app(OrderCreationService::class)->createOrder(
            orderData: ['user_id' => $customer->id, 'name' => 'Gap', 'user_phone' => '01000000000', 'user_email' => $customer->email, 'address' => 'x'],
            cart: $cart,
            checkoutTotals: new CheckoutTotals(50.0, 0, 0, 50.0),
            shippingPrice: 0,
        );

        $this->assertSame('pending', $order->fresh()->status);

        // Deliberately WITHOUT payments.mark_paid: must be rejected, order kept.
        $admin = $this->createUserWithPermissions(['update-order-status'], 'admin');
        Sanctum::actingAs($admin);

        $response = $this->patchJson('/api/v1/orders/' . $order->id . '/status', ['status' => 'completed']);

        $response->assertStatus(422);

        $fresh = $order->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertNotSame(Order::PAYMENT_STATUS_SUCCESS, $fresh->payment_status);
    }
}
