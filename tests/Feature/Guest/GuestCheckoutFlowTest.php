<?php

namespace Tests\Feature\Guest;

use App\Models\GuestIdentity;
use App\Models\GuestIdempotencyKey;
use App\Services\Guest\GuestOrderTokenService;
use App\Services\Notifications\SMSService;
use App\Services\Order\OrderTransitionService;
use Illuminate\Mail\Transport\ArrayTransport;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Transaction;
use Marvel\Enums\ItemType;
use Mockery;
use Mockery\MockInterface;

class GuestCheckoutFlowTest extends GuestCheckoutTestCase
{
    /** Recorded send() attempts of the swapped SMSService fake. */
    private array $smsSends = [];

    private function seedCart(int $quantity = 2): void
    {
        $this->startGuestSession();
        $this->addToGuestCart([
            'product_id' => $this->product->id,
            'quantity' => $quantity,
            'shipping_method' => 'scheduled',
        ])->assertCreated();
    }

    /** @test */
    public function checkout_creates_a_real_order_without_a_registered_user(): void
    {
        $this->seedCart(2);

        $response = $this->submitGuestCheckout([], 'checkout-key-1');

        $response->assertCreated()->assertJsonPath('success', true);
        $response->assertJsonPath('data.idempotent_replay', false);

        $order = $this->currentGuestOrders()->firstOrFail();

        $this->assertNull($order->user_id, 'Guest orders must not fabricate a user');
        $this->assertNotNull($order->guest_id);
        $this->assertSame($this->currentGuestId(), (int) $order->guest_id);
        $this->assertSame('Guest Buyer', $order->name);
        $this->assertSame('+201000000001', $order->user_phone);
        $this->assertSame('guest.buyer@example.test', $order->user_email);
        $this->assertSame(Order::ORDER_STATUS_PENDING, $order->status);
        $this->assertSame(Order::PAYMENT_STATUS_PENDING, $order->payment_status);
        $this->assertSame('online', $order->payment_method);

        // Server-side authoritative totals: the subtotal comes from
        // ProductPricingService (2 x 100); shipping/tax come from the existing
        // configuration, never from the client.
        $this->assertEquals(200.0, (float) $order->price);
        $this->assertEquals(
            (float) $order->price
                + (float) $order->shipping_price
                + (float) ($order->product_tax_amount ?? 0)
                + (float) ($order->order_tax_amount ?? 0),
            (float) $order->total_price
        );

        // Raw credentials returned once, hashes stored only.
        $this->assertIsString($response->json('data.tracking_token'));
        $this->assertIsString($response->json('data.cancellation_token'));
        $this->assertNotSame($response->json('data.tracking_token'), $order->guest_tracking_token_hash);
        $this->assertSame(
            app(GuestOrderTokenService::class)->hash($response->json('data.tracking_token')),
            $order->guest_tracking_token_hash
        );

        // The ordered cart slice left the cart; the cart row survives.
        $this->assertSame(0, $this->guestItemCount());
        $this->assertNotNull($this->currentGuestCart());

        // Reservation is owned by the ORDER.
        $this->assertSame(2, (int) $this->product->refresh()->reserved_quantity);
        $this->assertSame(50, (int) $this->product->fresh()->stock_quantity);
    }

    /** @test */
    public function checkout_requires_a_guest_session(): void
    {
        $this->postJson(self::PREFIX . '/checkout', $this->checkoutPayload())
            ->assertStatus(401);
    }

    /** @test */
    public function checkout_without_a_cart_is_rejected_and_creates_nothing(): void
    {
        $this->startGuestSession();

        $this->submitGuestCheckout()
            ->assertStatus(400);

        $this->assertSame(0, $this->currentGuestOrders()->count());
    }

    /** @test */
    public function repeated_submission_with_the_same_idempotency_key_returns_the_same_order(): void
    {
        $this->seedCart(1);

        $first = $this->submitGuestCheckout([], 'idem-abc');
        $first->assertCreated();
        $orderId = $first->json('data.order.id');
        $guestId = $this->currentGuestId();

        $this->assertSame(1, $this->currentGuestOrders()->count());

        // Simulate a client retry (same browser, same key).
        $second = $this->submitGuestCheckout([], 'idem-abc');
        $second->assertCreated();

        $this->assertSame($orderId, $second->json('data.order.id'));
        $this->assertTrue($second->json('data.idempotent_replay'));
        $this->assertNull($second->json('data.tracking_token'));

        $this->assertSame(1, $this->currentGuestOrders()->count());
        $this->assertSame(
            1,
            GuestIdempotencyKey::query()
                ->where('guest_id', $guestId)
                ->where('scope', 'checkout')
                ->count()
        );
    }

    /** @test */
    public function a_second_checkout_reuses_the_pending_guest_order_instead_of_duplicating(): void
    {
        $this->seedCart(1);
        $this->submitGuestCheckout([], 'first-key')->assertCreated();

        // Customer changes quantity and retries with a different key.
        $this->addToGuestCart([
            'product_id' => $this->product->id,
            'quantity' => 1,
            'shipping_method' => 'scheduled',
        ])->assertCreated();

        $this->submitGuestCheckout([], 'second-key')->assertCreated();

        $this->assertSame(
            1,
            $this->currentGuestOrders()->where('status', Order::ORDER_STATUS_PENDING)->count()
        );
        $this->assertSame(1, $this->currentGuestOrders()->count());
    }

    /** @test */
    public function a_digital_line_cannot_be_checked_out_even_if_present_in_the_cart(): void
    {
        $this->startGuestSession();
        $this->addToGuestCart([
            'product_id' => $this->product->id,
            'quantity' => 1,
            'shipping_method' => 'scheduled',
        ])->assertCreated();

        // Inject a digital line bypassing the cart guard (defense in depth).
        $digital = $this->makeProduct([
            'item_type' => ItemType::DIGITAL,
            'name' => 'Digital ' . uniqid(),
            'slug' => 'digital-' . uniqid(),
        ]);

        $cart = $this->currentGuestCart();
        $this->assertNotNull($cart);

        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $digital->id,
            'quantity' => 1,
            'price' => 50,
            'total_price' => 50,
            'shipping_method' => 'SCHEDULED',
        ]);

        $this->submitGuestCheckout([], 'digital-key')
            ->assertStatus(422);

        $this->assertSame(0, $this->currentGuestOrders()->count());
    }

    /** @test */
    public function a_line_that_became_unavailable_after_add_blocks_checkout(): void
    {
        $this->startGuestSession();
        $this->addToGuestCart([
            'product_id' => $this->product->id,
            'quantity' => 1,
            'shipping_method' => 'scheduled',
        ])->assertCreated();

        $this->product->update([
            'in_stock' => false,
            'stock_quantity' => 0,
            'reserved_quantity' => 0,
        ]);

        $response = $this->submitGuestCheckout([], 'unavailable-after-add-key');
        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', __('guest_checkout.product_unavailable'));

        $this->assertSame(0, $this->currentGuestOrders()->count());
    }

    /** @test */
    public function cod_orders_are_created_unpaid_and_commit_inventory_through_the_canonical_path(): void
    {
        $this->seedCart(1);

        $response = $this->submitGuestCheckout([
            'payment_method' => 'cod',
        ], 'cod-key');

        $response->assertCreated();

        $order = $this->currentGuestOrders()->firstOrFail();
        $this->assertSame('cod', $order->payment_method);
        $this->assertSame(Order::PAYMENT_STATUS_PENDING, $order->payment_status);
        $this->assertNotSame(Order::PAYMENT_STATUS_SUCCESS, $order->payment_status);
        $this->assertSame(Order::INVENTORY_STATE_COMMITTED, $order->inventory_state);

        // No fake gateway transaction exists at order creation.
        $this->assertSame(0, Transaction::query()->where('order_id', $order->id)->count());
    }

    /** @test */
    public function prune_protects_a_cart_whose_guest_has_a_pending_order(): void
    {
        $this->seedCart(1);
        $this->submitGuestCheckout([], 'prune-key')->assertCreated();

        $cart = $this->currentGuestCart();
        $this->assertNotNull($cart);
        $cart->forceFill(['guest_expires_at' => now()->subDay()])->save();

        $this->artisan('guest:prune-carts')->assertSuccessful();

        $this->assertNotNull(Cart::query()->find($cart->id), 'Pending-order cart must survive cleanup');

        // Cancel the order: the cart becomes eligible.
        $order = $this->currentGuestOrders()->firstOrFail();
        app(OrderTransitionService::class)->cancelByStaff($order, 'test');

        $this->artisan('guest:prune-carts')->assertSuccessful();

        $this->assertNull(Cart::query()->find($cart->id));
    }

    /** @test */
    public function expired_guest_cart_without_orders_is_pruned_idempotently(): void
    {
        $this->seedCart(1);

        $cart = $this->currentGuestCart();
        $this->assertNotNull($cart);
        $cart->forceFill(['guest_expires_at' => now()->subDay()])->save();

        $this->artisan('guest:prune-carts')->assertSuccessful();
        $this->assertNull(Cart::query()->find($cart->id));

        // Re-run: no error, nothing else deleted.
        $this->artisan('guest:prune-carts')->assertSuccessful();
    }

    /** @test */
    public function authenticated_carts_are_never_pruned(): void
    {
        $user = \Marvel\Database\Models\User::create([
            'name' => 'Registered Prune',
            'email' => 'prune-' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
        ]);

        $cart = Cart::create([
            'user_id' => $user->id,
            'status' => 'active',
            'guest_expires_at' => now()->subDays(30),
        ]);

        $this->artisan('guest:prune-carts')->assertSuccessful();

        $this->assertNotNull(Cart::query()->find($cart->id));
    }

    /** @test */
    public function checkout_schedules_the_tracking_link_delivery_for_the_terminating_phase(): void
    {
        $this->trackingLinkConfig(true);
        $this->fakeGuestSmsDelivery();

        $this->seedCart(1);

        $response = $this->submitGuestCheckout([], 'delivery-key-' . uniqid());

        $response->assertCreated()->assertJsonPath('success', true);

        $trackingToken = $response->json('data.tracking_token');
        $cancellationToken = $response->json('data.cancellation_token');
        $orderNumber = $response->json('data.order.order_number');
        $orderId = $response->json('data.order.id');

        $this->assertIsString($trackingToken);
        $this->assertIsString($cancellationToken, 'The checkout fixture must carry a real cancellation token');

        // The test kernel runs Application::terminate() after the response
        // (MakesHttpRequests::call), exactly like the production kernel, so
        // the scheduled delivery has already executed once here. The
        // "deferred, not inline" wiring is pinned at the unit seam:
        // GuestTrackingLinkNotifierTest::test_notify_after_response_defers_
        // delivery_to_the_terminating_phase.

        // SMS channel: exactly one send, to the order phone, tracking link only.
        $this->assertCount(1, $this->smsSends, 'The scheduled delivery must execute at terminate');
        $this->assertSame('+201000000001', $this->smsSends[0]['to']);
        $this->assertStringContainsString(
            'https://front.example.test/track-order?token=' . $trackingToken,
            $this->smsSends[0]['message']
        );
        $this->assertStringContainsString($orderNumber, $this->smsSends[0]['message']);
        $this->assertSame($orderId, $this->smsSends[0]['metadata']['order_id']);
        $this->assertSame('guest_tracking_link', $this->smsSends[0]['metadata']['type']);
        $this->assertTrackingOnlyDelivery($cancellationToken, $this->smsSends[0]);

        // Email channel: one raw message to the order address with the same link.
        $transport = $this->mailTransport();
        $this->assertCount(1, $transport->messages());
        $email = $transport->messages()->last()->getOriginalMessage();
        $this->assertSame('guest.buyer@example.test', $email->getTo()[0]->getAddress());
        $this->assertStringContainsString($orderNumber, (string) $email->getSubject());
        $this->assertStringContainsString(
            'https://front.example.test/track-order?token=' . $trackingToken,
            (string) $email->getTextBody()
        );
        $this->assertStringNotContainsString($cancellationToken, (string) $email->getTextBody());
    }

    /** @test */
    public function checkout_with_the_tracking_link_disabled_completes_and_schedules_no_delivery(): void
    {
        $this->trackingLinkConfig(false);
        $this->fakeGuestSmsDelivery(0);

        $this->seedCart(1);

        $response = $this->submitGuestCheckout([], 'disabled-delivery-key-' . uniqid());

        // The toggle stops delivery only: checkout still completes and the
        // order still receives its credentials.
        $response->assertCreated()->assertJsonPath('success', true);
        $this->assertIsString($response->json('data.tracking_token'));
        $this->assertIsString($response->json('data.cancellation_token'));
        $this->assertSame(1, $this->currentGuestOrders()->count());

        $this->app->terminate();

        $this->assertSame([], $this->smsSends, 'The disabled toggle must schedule no SMS delivery');
        $this->assertSame(
            0,
            $this->mailTransport()->messages()->count(),
            'The disabled toggle must schedule no email delivery'
        );
    }

    /**
     * Pin the tracking-link delivery configuration for the test.
     */
    private function trackingLinkConfig(bool $enabled): void
    {
        config(['guest_checkout.tracking_link' => [
            'enabled' => $enabled,
            'base_url' => 'https://front.example.test',
            'path' => '/track-order',
        ]]);
    }

    /**
     * Swap the container SMSService binding for a Mockery fake that records
     * send() attempts into $this->smsSends. The notifier captures the fake
     * when it is resolved during the checkout request, so the swap must
     * happen before checkout. $times === 0 makes any delivery attempt fail
     * the test immediately.
     */
    private function fakeGuestSmsDelivery(int $times = 1): MockInterface
    {
        $this->smsSends = [];

        $sms = Mockery::mock(SMSService::class);
        $sms->shouldReceive('formatPhone')->andReturn('+201000000001');
        $sms->shouldReceive('validatePhone')->andReturn(true);

        $expectation = $sms->shouldReceive('send')->andReturnUsing(function (string $to, string $message, array $metadata = []) {
            $this->smsSends[] = ['to' => $to, 'message' => $message, 'metadata' => $metadata];

            return ['success' => true, 'message_id' => 'test_simulated', 'response' => ['status' => 'simulated']];
        });

        $times === 0 ? $expectation->never() : $expectation->times($times);

        $this->app->instance(SMSService::class, $sms);

        return $sms;
    }

    /**
     * The real array mailer transport (MAIL_MAILER=array in the test config).
     */
    private function mailTransport(): ArrayTransport
    {
        $transport = app('mailer')->getSymfonyTransport();

        $this->assertInstanceOf(ArrayTransport::class, $transport, 'MAIL_MAILER must be the array transport for these tests');

        return $transport;
    }

    /**
     * The security claim "tracking-token-only": the delivery record must
     * never contain the cancellation token in any form (raw or hash) — and
     * therefore never a cancellation link.
     */
    private function assertTrackingOnlyDelivery(string $cancellationToken, array $sendRecord, string $label = 'The tracking delivery'): void
    {
        $serialized = (string) json_encode($sendRecord);

        $this->assertStringNotContainsString($cancellationToken, $serialized, "{$label} leaked the cancellation token");
        $this->assertStringNotContainsString(
            app(GuestOrderTokenService::class)->hash($cancellationToken),
            $serialized,
            "{$label} leaked the cancellation token hash"
        );
    }
}
