<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Enums\ShipmentStatus;
use App\Enums\Shipping\ProviderEventExceptionType;
use App\Enums\Shipping\ShipmentExceptionSeverity;
use App\Enums\Shipping\ShipmentExceptionStatus;
use App\Models\Shipment;
use App\Models\Shipping\ShipmentException;
use App\Models\Shipping\ShippingWebhookEvent;
use App\Services\General\OrderService;
use App\Services\Shipment\CarrierCancellationService;
use App\Services\Shipment\ShippingProviderEventProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Transaction;
use Marvel\Database\Models\User;
use Tests\Support\Shipping\ConfirmingCancellationGateway;
use Tests\Support\Shipping\ConfiguredCarrierGateway;
use Tests\TestCase;

/**
 * #187: a local cancellation is applied only after reliable provider
 * confirmation. Unsupported or rejected cancellations retain state and become
 * exceptions. A delivered COD order never completes until the existing
 * manual COD completion authority has marked payment successful.
 */
class ProviderConfirmedCancellationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.connections.pusher.driver' => 'log']);

        ConfirmingCancellationGateway::$fail = false;
        ConfirmingCancellationGateway::$calls = 0;
        ConfirmingCancellationGateway::$lastExternalId = '';
    }

    /** @test */
    public function local_cancel_is_applied_after_provider_confirmation(): void
    {
        $shipment = $this->confirmedShipment();
        $this->configureCancelProvider();

        $cancelled = app(CarrierCancellationService::class)->cancel(
            $shipment->id,
            'customer asked',
            ['cancel_source' => 'admin_api', 'cancelled_by' => 1],
        );

        $this->assertSame(ShipmentStatus::CANCELLED->value, $cancelled->status);
        $this->assertSame(1, ConfirmingCancellationGateway::$calls);
        $this->assertSame('tracking-187', ConfirmingCancellationGateway::$lastExternalId);
        $this->assertSame(0, ShipmentException::count());
    }

    /** @test */
    public function unsupported_provider_cancellation_retains_state_and_creates_exception(): void
    {
        config([
            'shipping.providers.carrier_confirm' => [
                'adapter' => ConfiguredCarrierGateway::class,
                'mode' => 'sandbox',
                'service_level' => 'standard',
                'channels' => ['web'],
                'geographies' => ['EG'],
                'modes' => [
                    'sandbox' => [
                        'enabled' => true,
                        'base_url' => 'local://quote-only',
                        'credentials' => ['api_key' => 'local-test'],
                        'capability_verification' => [
                            'quote' => ['verified_at' => '2026-01-01T00:00:00Z', 'evidence' => 'test'],
                        ],
                        'polling' => [
                            'supported' => false,
                            'cursor_type' => null,
                            'pagination' => null,
                            'overlap_window_seconds' => 0,
                        ],
                        'status_map' => ['version' => 1, 'statuses' => []],
                    ],
                ],
            ],
        ]);

        $shipment = $this->confirmedShipment();

        try {
            app(CarrierCancellationService::class)->cancel($shipment->id, 'too late to print');
            $this->fail('Cancellation must require a verified provider cancellation capability.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('capability', $exception->getMessage());
        }

        $this->assertSame(ShipmentStatus::PICKED_UP->value, $shipment->fresh()->status);
        $exception = ShipmentException::query()->sole();
        $this->assertSame(ProviderEventExceptionType::CANCEL_UNSUPPORTED->value, $exception->type);
        $this->assertSame(ShipmentExceptionStatus::OPEN->value, $exception->status);
        $this->assertSame($shipment->id, (int) $exception->shipment_id);
    }

    /** @test */
    public function rejected_provider_cancellation_retains_state_and_creates_exception(): void
    {
        $this->configureCancelProvider();
        ConfirmingCancellationGateway::$fail = true;
        $shipment = $this->confirmedShipment();

        try {
            app(CarrierCancellationService::class)->cancel($shipment->id, 'carrier refused');
            $this->fail('A provider rejection must refuse local cancellation.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('provider rejected cancellation', $exception->getMessage());
        }

        $this->assertSame(ShipmentStatus::PICKED_UP->value, $shipment->fresh()->status);
        $exception = ShipmentException::query()->sole();
        $this->assertSame(ProviderEventExceptionType::CANCEL_REJECTED->value, $exception->type);
        $this->assertStringContainsString('provider rejected cancellation', (string) $exception->notes);
        $this->assertSame($shipment->id, (int) $exception->shipment_id);
    }

    /** @test */
    public function delivered_cod_unpaid_order_waits_for_manual_payment_completion(): void
    {
        config([
            'shipping.providers.cod_carrier.mode' => 'sandbox',
            'shipping.providers.cod_carrier.modes.sandbox.status_map' => [
                'version' => 3,
                'status_map_owner' => 'shipping-platform',
                'statuses' => [
                    'out_for_delivery' => ShipmentStatus::OUT_FOR_DELIVERY->value,
                    'delivered' => ShipmentStatus::DELIVERED->value,
                ],
            ],
        ]);

        [$shipment, $order] = $this->codShipment(ShipmentStatus::OUT_FOR_DELIVERY->value);
        $webhook = $this->createInboxRow($shipment, 'delivered', 'evt_cod_delivered_187');

        $event = app(ShippingProviderEventProcessor::class)->process($webhook);

        $this->assertNotNull($event->applied_at);
        $this->assertSame(ShipmentStatus::DELIVERED->value, $shipment->fresh()->status);
        $this->assertSame(Order::PAYMENT_STATUS_PENDING, $order->refresh()->payment_status);
        $this->assertSame(Order::ORDER_STATUS_PROCESSING, $order->refresh()->status);

        Transaction::create([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'payment_method' => 'cod',
            'status' => 'pending',
            'amount' => 100,
            'currency' => 'EGP',
        ]);
        app(OrderService::class)->markCodAsPaid($order->refresh(), 'cash confirmed at delivery');

        $this->assertSame(Order::PAYMENT_STATUS_SUCCESS, $order->refresh()->payment_status);
    }

    private function configureCancelProvider(): void
    {
        config([
            'shipping.providers.carrier_confirm' => [
                'adapter' => ConfirmingCancellationGateway::class,
                'mode' => 'sandbox',
                'service_level' => 'standard',
                'account_code' => 'carrier-confirm-test',
                'channels' => ['web'],
                'geographies' => ['EG'],
                'modes' => [
                    'sandbox' => [
                        'enabled' => true,
                        'base_url' => 'local://cancel-test',
                        'credentials' => ['api_key' => 'local-test'],
                        'capability_verification' => [
                            'cancel_shipment' => [
                                'verified_at' => '2026-01-01T00:00:00Z',
                                'evidence' => 'local cancellation fixture',
                            ],
                        ],
                        'polling' => [
                            'supported' => false,
                            'cursor_type' => null,
                            'pagination' => null,
                            'overlap_window_seconds' => 0,
                        ],
                        'queues' => [
                            'dispatch' => 'shipping.cancel.dispatch',
                            'webhook' => 'shipping.cancel.webhook',
                        ],
                        'webhook' => ['version' => 'v1'],
                        'status_map' => ['version' => 1, 'statuses' => []],
                    ],
                ],
            ],
        ]);
    }

    /** @return array{0:Shipment,1:Order} */
    private function codShipment(string $status, string $providerCode = 'cod_carrier'): array
    {
        $order = $this->order();
        $shipment = Shipment::create([
            'order_id' => $order->id,
            'status' => $status,
            'provider_code' => $providerCode,
            'provider_mode' => 'sandbox',
            'tracking_number' => 'tracking-187',
        ]);

        return [$shipment, $order];
    }

    private function confirmedShipment(): Shipment
    {
        return $this->codShipment(ShipmentStatus::PICKED_UP->value, 'carrier_confirm')[0];
    }

    private function order(): Order
    {
        $customer = User::factory()->create([
            'email' => 'carrier-cancel-'.Str::lower(Str::random(6)).'@example.test',
        ]);

        return Order::create([
            'user_id' => $customer->id,
            'name' => 'Cancellation Customer',
            'user_email' => $customer->email,
            'user_phone' => '01000000000',
            'status' => Order::ORDER_STATUS_PROCESSING,
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'fulfillment_status' => 'processing',
            'price' => 100,
            'total_price' => 100,
            'payment_method' => 'cod',
            'address' => ['city' => 'Cairo'],
        ]);
    }

    private function createInboxRow(Shipment $shipment, string $rawStatus, string $eventKey): ShippingWebhookEvent
    {
        return ShippingWebhookEvent::create([
            'provider_code' => $shipment->provider_code,
            'mode' => $shipment->provider_mode,
            'account_code' => 'test-account',
            'webhook_version' => 'v1',
            'event_key' => $eventKey,
            'event_type' => 'shipment.status_changed',
            'provider_shipment_ref' => 'carrier-ref-187',
            'shipment_id' => $shipment->id,
            'redacted_payload' => [
                'status' => $rawStatus,
                'event_type' => 'shipment.status_changed',
            ],
            'raw_status' => $rawStatus,
            'status' => 'received',
            'received_at' => now(),
            'occurred_at' => now(),
        ]);
    }
}
