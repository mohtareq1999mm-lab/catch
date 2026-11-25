<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Models\Currency;
use App\Models\Fulfillment\Fulfillment;
use App\Models\OrderTrackingEvent;
use App\Models\Shipment;
use App\Models\Warehouse;
use App\Services\Shipment\ProviderAssignmentService;
use App\Services\Shipment\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProviderOverrideAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'shipping.default_provider' => 'internal',
            'shipping.providers.internal' => [
                'adapter' => \App\Services\Shipment\Providers\NullCarrierGateway::class,
                'mode' => 'sandbox',
                'account_code' => 'internal-test',
                'service_level' => 'standard',
                'channels' => ['web'],
                'geographies' => ['EG'],
                'modes' => [
                    'sandbox' => ['enabled' => true, 'credentials' => ['api_key' => 'sandbox-key'], 'status_map' => ['version' => 'v1', 'statuses' => []]],
                    'production' => ['enabled' => false, 'credentials' => [], 'status_map' => ['version' => 'v1', 'statuses' => []]],
                ],
            ],
            'shipping.providers.alternate' => [
                'adapter' => \App\Services\Shipment\Providers\NullCarrierGateway::class,
                'mode' => 'sandbox',
                'account_code' => 'alternate-test',
                'service_level' => 'express',
                'channels' => ['web'],
                'geographies' => ['EG'],
                'modes' => [
                    'sandbox' => ['enabled' => true, 'credentials' => ['api_key' => 'sandbox-key'], 'status_map' => ['version' => 'v2', 'statuses' => []]],
                    'production' => ['enabled' => false, 'credentials' => [], 'status_map' => ['version' => 'v2', 'statuses' => []]],
                ],
            ],
        ]);

        $override = PermissionModel::firstOrCreate(['name' => Permission::SHIPPING_PROVIDER_OVERRIDE, 'guard_name' => 'api']);
        $view = PermissionModel::firstOrCreate(['name' => Permission::VIEW_SHIPMENT, 'guard_name' => 'api']);
        $manage = PermissionModel::firstOrCreate(['name' => Permission::MANAGE_WAREHOUSE, 'guard_name' => 'api']);
        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'api']);
        $adminRole->givePermissionTo([$override, $view, $manage]);
        $this->admin = User::factory()->create(['type' => 'admin', 'is_active' => true]);
        $this->admin->assignRole($adminRole);
    }

    private function order(): Order
    {
        Currency::create(['code' => 'EGP', 'name' => 'EGP', 'symbol' => 'E', 'decimal_places' => 2, 'is_active' => true]);
        $customer = User::factory()->create(['type' => 'customer']);
        return Order::create([
            'user_id' => $customer->id,
            'name' => 'O',
            'user_email' => $customer->email,
            'user_phone' => '01000000000',
            'status' => 'completed',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'fulfillment_status' => 'pending',
            'price' => 100,
            'total_price' => 100,
            'currency_code' => 'EGP',
            'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery',
            'payment_method' => 'online',
            'address' => ['city' => 'Cairo'],
        ]);
    }

    private function shipment(): Shipment
    {
        return app(ShipmentService::class)->create(['order_id' => $this->order()->id]);
    }

    public function test_override_records_non_customer_visible_timeline_event_with_actor_and_notes(): void
    {
        Sanctum::actingAs($this->admin);
        $shipment = $this->shipment();

        $response = $this->postJson('/api/v1/admin/shipments/' . $shipment->id . '/provider-override', [
            'provider' => 'alternate',
            'reason' => 'carrier outage - reroute',
        ]);
        $response->assertOk();

        $event = OrderTrackingEvent::query()
            ->where('order_id', $shipment->order_id)
            ->where('event_type', 'shipment.provider_overridden')
            ->latest('id')
            ->first();

        $this->assertNotNull($event, 'Override should create a timeline event');
        $this->assertFalse((bool) $event->customer_visible);
        // This admin has override authority but none of the legacy order-status
        // permissions: actor type must come from the authority boundary.
        $this->assertSame('admin', $event->actor_type);
        $this->assertSame('admin', $event->source);
        $this->assertSame($this->admin->id, (int) $event->actor_id);
        $this->assertSame($this->admin->name, $event->actor_name);
        $this->assertNull($event->old_status);
        $this->assertNull($event->new_status);
        $this->assertSame('carrier outage - reroute', $event->admin_notes);
        $this->assertSame('tracking.shipment.provider_overridden', $event->customer_label_key);
        $this->assertSame('tracking.shipment.provider_overridden_description', $event->customer_description_key);
        $this->assertSame($shipment->id, (int) $event->metadata['shipment_id']);
        $this->assertSame('internal', $event->metadata['old']['provider_code']);
        $this->assertSame('alternate', $event->metadata['new']['provider_code']);

        $activity = Activity::query()->where('event', 'provider_override')->latest('id')->firstOrFail();
        $this->assertSame($this->admin->id, (int) $activity->causer_id);
        $this->assertSame(get_class($this->admin), $activity->causer_type);
        $this->assertSame('human', $activity->properties['context']['actor']['type']);
        $this->assertSame($this->admin->id, (int) $activity->properties['context']['actor']['user_id']);
    }

    public function test_override_translation_keys_exist_in_english_and_arabic(): void
    {
        $this->assertSame('Carrier Reassigned', __('tracking.shipment.provider_overridden', [], 'en'));
        $this->assertSame(
            'The carrier handling your shipment was changed by the store.',
            __('tracking.shipment.provider_overridden_description', [], 'en')
        );
        $this->assertSame('تغيير شركة الشحن', __('tracking.shipment.provider_overridden', [], 'ar'));
        $this->assertSame(
            'قام المتجر بتغيير شركة الشحن المسؤولة عن طردك.',
            __('tracking.shipment.provider_overridden_description', [], 'ar')
        );
    }

    public function test_service_override_without_actor_records_system_actor(): void
    {
        $shipment = $this->shipment();

        app(ProviderAssignmentService::class)->override($shipment, 'alternate', 'automated reroute');

        $event = OrderTrackingEvent::query()
            ->where('order_id', $shipment->order_id)
            ->where('event_type', 'shipment.provider_overridden')
            ->firstOrFail();

        $this->assertSame('system', $event->actor_type);
        $this->assertSame('system', $event->source);
        $this->assertNull($event->actor_id);
        $this->assertSame('System', $event->actor_name);
    }

    public function test_override_never_dispatches_status_change_event(): void
    {
        Sanctum::actingAs($this->admin);
        $shipment = $this->shipment();

        Event::fake([\App\Events\Shipment\ShipmentStatusChanged::class]);

        $this->postJson('/api/v1/admin/shipments/' . $shipment->id . '/provider-override', [
            'provider' => 'alternate',
            'reason' => 'capacity',
        ])->assertOk();

        Event::assertNotDispatched(\App\Events\Shipment\ShipmentStatusChanged::class);
    }
}
