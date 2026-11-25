<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\DTOs\CheckoutTotals;
use App\DTOs\Shipping\CarrierCodEvidence;
use App\DTOs\Shipping\CarrierMoney;
use App\DTOs\Shipping\CarrierReturnLink;
use App\Enums\Shipping\ProviderEventExceptionType;
use App\Enums\Shipping\ShipmentExceptionSeverity;
use App\Enums\Shipping\ShipmentExceptionStatus;
use App\Events\PaymentSucceeded;
use App\Exceptions\CodAmountEvidenceException;
use App\Models\Currency;
use App\Models\Fulfillment\ReturnItem;
use App\Models\Fulfillment\ReturnRequest;
use App\Models\Fulfillment\Warehouse;
use App\Models\GuestIdentity;
use App\Models\Settlement\ShipmentCodAdjustment;
use App\Models\Shipment;
use App\Models\Shipping\ShipmentDeliveryAttempt;
use App\Models\Shipping\ShipmentException;
use App\Models\Shipping\ShipmentPod;
use App\Models\Shipping\ShippingProviderEvent;
use App\Models\Shipping\ShippingWebhookEvent;
use App\Services\Checkout\OrderCreationService;
use App\Services\Currency\CurrencyService;
use App\Services\Fulfillment\GuestCarrierReturnService;
use App\Services\Shipment\CodCashEvidenceService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Transaction;
use Tests\Feature\Currency\CurrencyTestCase;

/**
 * #181: guest carrier returns are exceptions, not synthetic return requests.
 * Carrier cash evidence is recorded on delivery records only; completion stays
 * with the permission-protected manual COD path.
 */
class GuestReturnCodEvidenceTest extends CurrencyTestCase
{
    // -----------------------------------------------------------------
    // Guest carrier returns
    // -----------------------------------------------------------------

    public function test_guest_carrier_return_creates_exception_without_inventing_return_data(): void
    {
        $this->createCurrency('USD');
        $order = $this->guestOrder();
        $shipment = $this->shipmentFor($order);

        $usersBefore = \Marvel\Database\Models\User::query()->count();
        $returnsBefore = ReturnRequest::query()->count();
        $itemsBefore = ReturnItem::query()->count();
        $transactionsBefore = Transaction::query()->count();

        $link = $this->returnLink((int) $order->id, (int) $shipment->id, [
            // Malicious/irrelevant provider fields must never become domain data.
            'customer_id' => '999999',
            'quantity' => '7',
            'items' => 'sku-181',
        ]);

        $exception = app(GuestCarrierReturnService::class)->record(
            $shipment,
            $link,
            $this->providerEvent($shipment, 'return'),
        );

        $this->assertNotNull($exception);
        $this->assertSame(ProviderEventExceptionType::GUEST_CARRIER_RETURN->value, $exception->type);
        $this->assertSame(ShipmentExceptionStatus::OPEN->value, $exception->status);
        $this->assertSame(ShipmentExceptionSeverity::HIGH->value, $exception->severity);
        $this->assertSame($shipment->id, (int) $exception->shipment_id);

        $notes = (string) $exception->notes;
        $this->assertStringContainsString('no_suitable_return_request', $notes);
        $this->assertStringContainsString('RET-CARRIER-181', $notes);
        $this->assertStringNotContainsString('999999', $notes);
        $this->assertStringNotContainsString('sku-181', $notes);
        $this->assertStringNotContainsString('"quantity"', $notes);

        $this->assertSame($returnsBefore, ReturnRequest::query()->count());
        $this->assertSame(0, $returnsBefore);
        $this->assertSame($itemsBefore, ReturnItem::query()->count());
        $this->assertSame($usersBefore, \Marvel\Database\Models\User::query()->count());
        $this->assertSame($transactionsBefore, Transaction::query()->count());
        $this->assertSame('processing', $order->fresh()->status);
        $this->assertSame(Order::PAYMENT_STATUS_PENDING, $order->fresh()->payment_status);
    }

    public function test_linked_return_request_prevents_a_second_guest_return_exception(): void
    {
        $this->createCurrency('USD');
        $order = $this->guestOrder();
        $shipment = $this->shipmentFor($order);

        $user = $this->createCustomer();
        ReturnRequest::create([
            'return_number' => 'RET-181-LINKED-' . uniqid(),
            'order_id' => $order->id,
            'warehouse_id' => Warehouse::create([
                'code' => 'WH-181-' . uniqid(),
                'name' => 'Return warehouse',
                'status' => 'active',
                'is_default' => false,
            ])->id,
            'customer_id' => $user->id,
            'status' => 'pending',
            'shipment_id' => $shipment->id,
            'requested_by' => $user->id,
            'requested_at' => now(),
        ]);
        $orderItem = $order->orderItems()->firstOrFail();
        ReturnItem::create([
            'return_request_id' => ReturnRequest::query()->firstOrFail()->id,
            'order_item_id' => $orderItem->id,
            'product_id' => $orderItem->product_id,
            'quantity_returned' => 1,
            'quantity_approved' => 1,
            'quantity_restocked' => 0,
            'condition' => 'good',
        ]);

        $result = app(GuestCarrierReturnService::class)->record(
            $shipment,
            $this->returnLink((int) $order->id, (int) $shipment->id),
            $this->providerEvent($shipment, 'linked'),
        );

        $this->assertNull($result);
        $this->assertSame(0, ShipmentException::query()->count());
        $this->assertSame(1, ReturnRequest::query()->count());
    }

    public function test_mismatched_guest_return_identity_is_rejected_without_side_effects(): void
    {
        $this->createCurrency('USD');
        $order = $this->guestOrder();
        $shipment = $this->shipmentFor($order);

        $link = new CarrierReturnLink(
            'internal',
            'sandbox',
            [
                'order_id' => (string) ((int) $order->id + 999),
                'return_reference' => 'RET-CARRIER-181-BAD',
            ],
            null,
            'webhook:181-bad',
        );

        try {
            app(GuestCarrierReturnService::class)->record(
                $shipment,
                $link,
                $this->providerEvent($shipment, 'bad'),
            );
            $this->fail('Mismatched carrier return identity must be rejected.');
        } catch (\InvalidArgumentException) {
            $this->assertSame(0, ShipmentException::query()->count());
            $this->assertSame(0, ReturnRequest::query()->count());
        }
    }

    // -----------------------------------------------------------------
    // COD cash evidence
    // -----------------------------------------------------------------

    public function test_cash_evidence_is_recorded_on_attempt_and_pod_without_changing_payment_or_cod_snapshot(): void
    {
        $this->createCurrency('USD');
        $order = $this->guestOrder();
        $shipment = $this->shipmentFor($order);
        $attempt = $this->attemptFor($shipment);
        $pod = $this->podFor($shipment, $attempt);

        $service = app(CodCashEvidenceService::class);
        $evidence = $this->codEvidence((int) $order->id, (int) $shipment->id, new CarrierMoney(10000, 'USD'));

        $recordedAttempt = $service->recordOnAttempt($attempt, $evidence);
        $recordedPod = $service->recordOnPod($pod, $evidence);

        $attemptEvidence = $recordedAttempt->metadata[CodCashEvidenceService::METADATA_KEY];
        $podEvidence = $recordedPod->metadata[CodCashEvidenceService::METADATA_KEY];

        $this->assertSame(10000, $attemptEvidence['amount_minor']);
        $this->assertSame('USD', $attemptEvidence['currency_code']);
        $this->assertSame('order_cod_snapshot', $attemptEvidence['validated_against']);
        $this->assertSame('webhook:181-cash', $attemptEvidence['redacted_payload_reference']);
        $this->assertSame($attemptEvidence, $podEvidence);
        $this->assertArrayNotHasKey('cod_snapshot', $attemptEvidence);
        $this->assertArrayNotHasKey('cod_snapshot', $podEvidence);

        $this->assertSame(0, ShipmentCodAdjustment::query()->count());
        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame('processing', $order->fresh()->status);
        $this->assertSame(Order::PAYMENT_STATUS_PENDING, $order->fresh()->payment_status);
        $this->assertSame('out_for_delivery', $shipment->fresh()->status);
    }

    /**
     * @return array<string,array{0:callable,1:class-string,2:?string}>
     */
    public static function invalidEvidenceProvider(): array
    {
        return [
            'missing amount' => [
                fn (self $test, int $orderId, int $shipmentId): CarrierCodEvidence => $test->codEvidence($orderId, $shipmentId, null),
                CodAmountEvidenceException::class,
                'cod_amount.invalid_amount',
            ],
            'zero amount' => [
                fn (self $test, int $orderId, int $shipmentId): CarrierCodEvidence => $test->codEvidence($orderId, $shipmentId, new CarrierMoney(0, 'USD')),
                CodAmountEvidenceException::class,
                'cod_amount.invalid_amount',
            ],
            'wrong currency' => [
                fn (self $test, int $orderId, int $shipmentId): CarrierCodEvidence => $test->codEvidence($orderId, $shipmentId, new CarrierMoney(10000, 'EGP')),
                CodAmountEvidenceException::class,
                'cod_amount.mixed_currency',
            ],
            'wrong amount' => [
                fn (self $test, int $orderId, int $shipmentId): CarrierCodEvidence => $test->codEvidence($orderId, $shipmentId, new CarrierMoney(9999, 'USD')),
                CodAmountEvidenceException::class,
                'cod_amount.inconsistent_evidence',
            ],
            'wrong order identity' => [
                fn (self $test, int $orderId, int $shipmentId): CarrierCodEvidence => $test->codEvidence(
                    $orderId,
                    $shipmentId,
                    new CarrierMoney(10000, 'USD'),
                    ['order_id' => (string) ($orderId + 999), 'shipment_id' => (string) $shipmentId],
                ),
                \InvalidArgumentException::class,
                null,
            ],
            'missing order identity' => [
                fn (self $test, int $orderId, int $shipmentId): CarrierCodEvidence => $test->codEvidence(
                    $orderId,
                    $shipmentId,
                    new CarrierMoney(10000, 'USD'),
                    ['reference' => 'no-order-identity'],
                ),
                \InvalidArgumentException::class,
                null,
            ],
        ];
    }

    /** @dataProvider invalidEvidenceProvider */
    public function test_invalid_cash_evidence_fails_closed_without_mutation(
        callable $factory,
        string $exceptionClass,
        ?string $messageKey,
    ): void {
        $this->createCurrency('USD');
        $order = $this->guestOrder();
        $shipment = $this->shipmentFor($order);
        $attempt = $this->attemptFor($shipment);
        $metadataBefore = $attempt->fresh()->metadata;

        $evidence = $factory($this, (int) $order->id, (int) $shipment->id);
        $thrown = null;
        try {
            app(CodCashEvidenceService::class)->recordOnAttempt($attempt, $evidence);
        } catch (\Throwable $exception) {
            $thrown = $exception;
        }

        $this->assertInstanceOf($exceptionClass, $thrown);
        if ($messageKey !== null) {
            $this->assertSame(__($messageKey), $thrown->getMessage());
        }

        $this->assertSame($metadataBefore, $attempt->fresh()->metadata);
        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(0, ShipmentCodAdjustment::query()->count());
        $this->assertSame('processing', $order->fresh()->status);
        $this->assertSame(Order::PAYMENT_STATUS_PENDING, $order->fresh()->payment_status);
    }

    public function test_cash_evidence_alone_does_not_complete_order_and_manual_mark_paid_still_authoritative(): void
    {
        Event::fake([PaymentSucceeded::class]);
        Queue::fake();

        $order = $this->registeredPendingCodOrder(50.0);
        $shipment = $this->shipmentFor($order);
        $attempt = $this->attemptFor($shipment);

        app(CodCashEvidenceService::class)->recordOnAttempt(
            $attempt,
            $this->codEvidence((int) $order->id, (int) $shipment->id, new CarrierMoney(50000, 'KWD')),
        );

        Event::assertNotDispatched(PaymentSucceeded::class);
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame(Order::PAYMENT_STATUS_PENDING, $order->fresh()->payment_status);
        $this->assertSame('pending', $order->transactions()->latest()->first()->status);

        $admin = $this->createUserWithPermissions(['payments.mark_paid'], 'admin');
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/general/checkout/cod/' . $order->id . '/mark-paid', [
            'reason' => 'Cash collected on delivery',
        ])->assertStatus(200);

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('paid', $order->transactions()->latest()->first()->status);
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function guestOrder(): Order
    {
        $guest = GuestIdentity::create([
            'token_hash' => str_repeat('a', 64),
            'expires_at' => now()->addDay(),
        ]);
        $product = Product::create([
            'name' => 'Guest Return Product',
            'slug' => 'guest-return-181-' . Str::random(8),
            'price' => 100,
            'product_type' => \Marvel\Enums\ProductType::SIMPLE,
            'status' => true,
            'in_stock' => true,
            'quantity' => 1,
            'stock_quantity' => 1,
        ]);

        $order = Order::create([
            'user_id' => null,
            'guest_id' => $guest->id,
            'name' => 'Guest Return Customer',
            'user_email' => 'guest-181-' . uniqid() . '@example.test',
            'user_phone' => '01000000000',
            'status' => 'processing',
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'fulfillment_status' => 'pending',
            'price' => 100,
            'total_price' => 100,
            'currency_code' => 'USD',
            'base_currency_code' => 'USD',
            'payment_method' => 'cod',
            'address' => ['city' => 'Cairo'],
        ]);
        $order->orderItems()->create([
            'product_id' => $product->id,
            'product_name' => 'Guest Return Product',
            'product_sku' => 'SKU-181-' . Str::random(8),
            'product_quantity' => 1,
            'product_price' => 100,
            'product_total_price' => 100,
        ]);

        return $order->refresh();
    }

    private function registeredPendingCodOrder(float $subtotal): Order
    {
        $this->seedCurrencyData();
        $kwd = Currency::query()->where('code', 'KWD')->firstOrFail();
        app(CurrencyService::class)->setBaseCurrency($kwd);
        app(CurrencyService::class)->setCatalogCurrency($kwd);

        $customer = $this->createCustomer();
        $cart = Cart::create([
            'user_id' => $customer->id,
            'status' => 'active',
            'total_price' => $subtotal,
        ]);

        $order = app(OrderCreationService::class)->createOrder(
            orderData: [
                'user_id' => $customer->id,
                'name' => 'COD Evidence Customer',
                'user_phone' => '01000000000',
                'user_email' => $customer->email,
                'address' => '123 Evidence Street',
            ],
            cart: $cart,
            checkoutTotals: new CheckoutTotals($subtotal, 0, 0, $subtotal),
            shippingPrice: 0,
        );

        $order->update(['payment_method' => 'cod']);
        $order->transactions()->create([
            'user_id' => $order->user_id,
            'payment_method' => 'cod',
            'status' => 'pending',
            'amount' => $subtotal,
            'currency' => 'KWD',
            'uuid' => (string) Str::uuid(),
        ]);

        return $order->fresh();
    }

    private function shipmentFor(Order $order): Shipment
    {
        return Shipment::create([
            'order_id' => $order->id,
            'status' => 'out_for_delivery',
            'provider_code' => 'internal',
            'provider_mode' => 'sandbox',
        ]);
    }

    private function providerEvent(Shipment $shipment, string $kind): ShippingProviderEvent
    {
        $key = 'evt-181-' . $kind . '-' . uniqid();
        $webhook = ShippingWebhookEvent::create([
            'provider_code' => 'internal',
            'mode' => 'sandbox',
            'account_code' => 'account-181',
            'webhook_version' => '1',
            'event_key' => $key,
            'event_type' => 'shipment.return',
            'provider_shipment_ref' => 'carrier-181-' . $shipment->id,
            'shipment_id' => $shipment->id,
            'redacted_payload' => [],
            'received_at' => now(),
            'occurred_at' => now(),
        ]);

        return ShippingProviderEvent::create([
            'webhook_event_id' => $webhook->id,
            'shipment_id' => $shipment->id,
            'provider_code' => 'internal',
            'mode' => 'sandbox',
            'account_code' => 'account-181',
            'provider_event_key' => $key,
            'raw_status' => 'returned',
            'mapped_status' => 'returned',
            'payload_reference' => 'redacted:181-' . $kind,
            'payload_hash' => str_repeat('b', 64),
            'occurred_at' => now(),
            'received_at' => now(),
        ]);
    }

    private function attemptFor(Shipment $shipment): ShipmentDeliveryAttempt
    {
        return ShipmentDeliveryAttempt::create([
            'shipment_id' => $shipment->id,
            'attempt_number' => 1,
            'status' => 'delivered',
            'attempted_at' => now(),
        ]);
    }

    private function podFor(Shipment $shipment, ShipmentDeliveryAttempt $attempt): ShipmentPod
    {
        return ShipmentPod::create([
            'shipment_id' => $shipment->id,
            'shipment_delivery_attempt_id' => $attempt->id,
            'status' => 'successful',
            'recipient_name' => 'Guest Recipient',
            'captured_at' => now(),
            'file_reference' => 'pods/181-' . uniqid() . '.jpg',
        ]);
    }

    /** @param array<string,string> $extra */
    private function returnLink(int $orderId, int $shipmentId, array $extra = []): CarrierReturnLink
    {
        return new CarrierReturnLink(
            'internal',
            'sandbox',
            array_merge([
                'return_reference' => 'RET-CARRIER-181',
                'order_id' => (string) $orderId,
                'shipment_id' => (string) $shipmentId,
            ], $extra),
            null,
            'webhook:181-return',
        );
    }

    /** @param array<string,string>|null $externalIds */
    private function codEvidence(
        int $orderId,
        int $shipmentId,
        ?CarrierMoney $amount,
        ?array $externalIds = null,
    ): CarrierCodEvidence {
        return new CarrierCodEvidence(
            'internal',
            'sandbox',
            $externalIds ?? [
                'order_id' => (string) $orderId,
                'shipment_id' => (string) $shipmentId,
            ],
            $amount,
            'webhook:181-cash',
        );
    }
}
