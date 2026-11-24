<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\DTOs\Shipping\CarrierWebhookEvent;
use App\Enums\Shipping\ProviderEventExceptionType;
use App\Models\Shipment;
use App\Models\Shipping\ShippingProviderEvent;
use App\Models\Shipping\ShippingWebhookEvent;
use App\Services\Shipment\ShippingProviderEventProcessor;
use App\Services\Shipment\ShippingWebhookInbox;
use App\Services\Shipment\ShippingWebhookMatchCorrectionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ShippingEventMatchingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'shipping.providers.null.mode' => 'sandbox',
            'shipping.providers.null.modes.sandbox.status_map' => [
                'version' => 1,
                'status_map_owner' => 'test',
                'statuses' => ['in_transit' => 'in_transit'],
            ],
        ]);
    }

    public function test_matching_resolves_awb_first(): void
    {
        [$shipmentA, $shipmentB] = $this->createTwoShipments();
        $shipmentA->update(['tracking_number' => 'AWB-1']);
        $shipmentB->update(['tracking_number' => 'AWB-2', 'provider_code' => 'null', 'provider_mode' => 'sandbox']);

        $event = $this->carrierEvent([
            'providerShipmentRef' => 'AWB-2',
            'rawStatus' => 'in_transit',
            'redactedPayload' => ['awb' => 'AWB-1', 'tracking_number' => 'AWB-2'],
        ]);

        $row = app(ShippingWebhookInbox::class)->store($event);

        $this->assertSame($shipmentA->id, $row->shipment_id);
    }

    public function test_matching_uses_provider_shipment_id_when_awb_not_matched(): void
    {
        [$shipmentA, $shipmentB] = $this->createTwoShipments();
        $shipmentA->update(['tracking_number' => 'OTHER']);
        $shipmentB->update(['tracking_number' => 'AWB-2']);

        $event = $this->carrierEvent([
            'providerShipmentRef' => 'AWB-2',
            'rawStatus' => 'in_transit',
            'redactedPayload' => ['tracking_number' => 'AWB-2'],
        ]);

        $row = app(ShippingWebhookInbox::class)->store($event);

        $this->assertSame($shipmentB->id, $row->shipment_id);
    }

    public function test_matching_uses_unique_order_number_when_awb_and_provider_id_unmatched(): void
    {
        [$shipment] = $this->createTwoShipments();
        $order = $shipment->order;
        $order->order_number = 'ORD-UNIQUE-161';
        $order->save();

        $event = $this->carrierEvent([
            'providerShipmentRef' => 'NOPE',
            'rawStatus' => 'in_transit',
            'redactedPayload' => ['order_number' => 'ORD-UNIQUE-161'],
        ]);

        $row = app(ShippingWebhookInbox::class)->store($event);

        $this->assertSame($shipment->id, $row->shipment_id);
    }

    public function test_ambiguous_order_number_match_remains_unmatched_for_manual_resolution(): void
    {
        [$s1, $s2] = $this->createTwoShipments();
        $s1->order->forceFill(['order_number' => 'ORD-DUP'])->save();
        $s2->order->forceFill(['order_number' => 'ORD-DUP'])->save();

        $event = $this->carrierEvent([
            'providerShipmentRef' => 'NOPE',
            'rawStatus' => 'in_transit',
            'redactedPayload' => ['order_number' => 'ORD-DUP'],
        ]);

        $row = app(ShippingWebhookInbox::class)->store($event);

        $this->assertNull($row->shipment_id);
        $this->assertSame('ambiguous', $row->match_status ?? null);
    }

    public function test_conflicting_awb_aliases_are_ambiguous_instead_of_silently_first_wins(): void
    {
        [$shipment] = $this->createTwoShipments();
        $shipment->update(['tracking_number' => 'AWB-FIRST']);

        $event = $this->carrierEvent([
            'redactedPayload' => ['awb' => 'AWB-FIRST', 'awb_number' => 'AWB-SECOND'],
        ]);

        $row = app(ShippingWebhookInbox::class)->store($event);

        $this->assertNull($row->shipment_id);
        $this->assertSame('ambiguous', $row->match_status);
        $this->assertStringContainsString('conflicting awb identity', (string) $row->match_notes);
    }

    public function test_manual_correction_links_unmatched_event_and_replay_stays_idempotent(): void
    {
        [$shipment] = $this->createTwoShipments();
        $event = $this->carrierEvent([
            'providerShipmentRef' => 'NOPE',
            'redactedPayload' => ['order_number' => 'ORD-MISSING-161'],
        ]);
        $row = app(ShippingWebhookInbox::class)->store($event);
        $processor = app(ShippingProviderEventProcessor::class);
        $processed = $processor->process($row);
        $this->assertNull($processed->shipment_id);

        $manager = User::create([
            'name' => 'Match Corrector',
            'email' => 'match-'.Str::lower(Str::random(8)).'@example.test',
            'password' => bcrypt('password'),
        ]);
        foreach (['update-shipment', 'manage-warehouse'] as $permission) {
            $manager->givePermissionTo(Permission::findOrCreate($permission, 'api'));
        }

        $corrected = app(ShippingWebhookMatchCorrectionService::class)->attachShipment(
            $row,
            $shipment,
            $manager,
            'operator confirmed order number',
        );

        $this->assertSame($shipment->id, $corrected->shipment_id);
        $this->assertSame('matched_manual', $corrected->match_status);
        $this->assertStringContainsString('no unique awb/provider shipment id/order number match', (string) $corrected->match_notes);
        $this->assertStringContainsString('operator confirmed order number', (string) $corrected->match_notes);
        $this->assertSame($shipment->id, $processed->fresh()->shipment_id);

        $replayed = $processor->process($corrected);
        $this->assertSame($processed->id, $replayed->id);
        $this->assertSame(1, ShippingProviderEvent::query()->where('webhook_event_id', $row->id)->count());
    }

    public function test_unmatched_event_remains_in_inbox_for_manual_resolution(): void
    {
        $event = $this->carrierEvent([
            'providerShipmentRef' => 'NOPE',
            'rawStatus' => 'in_transit',
            'redactedPayload' => ['order_number' => 'ORD-MISSING'],
        ]);

        $row = app(ShippingWebhookInbox::class)->store($event);
        $processed = app(ShippingProviderEventProcessor::class)->process($row);

        $this->assertNull($row->fresh()->shipment_id);
        $this->assertSame(ProviderEventExceptionType::UNMATCHED_SHIPMENT->value, $processed->exception_classification);
    }

    private function createTwoShipments(): array
    {
        $u1 = User::create(['name'=>'U1','email'=>Str::random(8).'@e.test','password'=>bcrypt('p')]);
        $u2 = User::create(['name'=>'U2','email'=>Str::random(8).'@e.test','password'=>bcrypt('p')]);
        $o1 = Order::create(['user_id'=>$u1->id,'name'=>'C','user_phone'=>'1','user_email'=>$u1->email,'status'=>'pending','payment_status'=>Order::PAYMENT_STATUS_PENDING,'fulfillment_status'=>'pending','price'=>1,'total_price'=>1,'payment_method'=>'cod','address'=>['city'=>'C']]);
        $o2 = Order::create(['user_id'=>$u2->id,'name'=>'C','user_phone'=>'1','user_email'=>$u2->email,'status'=>'pending','payment_status'=>Order::PAYMENT_STATUS_PENDING,'fulfillment_status'=>'pending','price'=>1,'total_price'=>1,'payment_method'=>'cod','address'=>['city'=>'C']]);
        $s1 = Shipment::create(['order_id'=>$o1->id,'status'=>'pending']);
        $s2 = Shipment::create(['order_id'=>$o2->id,'status'=>'pending']);
        return [$s1, $s2];
    }

    private function carrierEvent(array $overrides = []): CarrierWebhookEvent
    {
        $payload = $overrides['redactedPayload'] ?? [];
        return new CarrierWebhookEvent(
            providerCode: 'null',
            mode: 'sandbox',
            externalIds: ['event' => 'evt-'.Str::random(6)],
            amount: null,
            redactedPayloadReference: 'redacted:ref',
            eventKey: 'evt-'.Str::random(8),
            eventType: 'shipment.status_changed',
            webhookVersion: 'v1',
            accountCode: 'null-local',
            providerShipmentRef: $overrides['providerShipmentRef'] ?? null,
            occurredAt: CarbonImmutable::now(),
            redactedPayload: $payload,
            shipmentUuid: $overrides['shipmentUuid'] ?? null,
            rawStatus: $overrides['rawStatus'] ?? 'in_transit',
        );
    }
}
