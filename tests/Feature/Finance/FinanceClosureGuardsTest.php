<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\SettlementMode;
use App\Enums\ShipmentStatus;
use App\Enums\Shipping\ProviderEventExceptionType;
use App\Enums\Shipping\ShipmentExceptionSeverity;
use App\Enums\Shipping\ShipmentExceptionStatus;
use App\Exceptions\CodSettlementVarianceException;
use App\Models\Currency;
use App\Models\Finance\FinanceAccessGrant;
use App\Models\Shipment;
use App\Models\Shipping\ShipmentException;
use App\Models\Shipping\ShippingWebhookEvent;
use App\Models\Settlement\CodSettlementStatement;
use App\Services\Finance\FinanceBreakGlassService;
use App\Services\Settlement\CodSettlementService;
use App\Services\Settlement\SettlementOrderMatcher;
use App\Services\Shipment\ShipmentService;
use App\Services\Shipment\ShippingProviderEventProcessor;
use App\Support\FinanceAccess;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Role;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Shipping\RecordingShipmentService;
use Tests\TestCase;

/**
 * #191: finance authority is explicit, settlement matching fails closed on
 * ambiguity, closure is a controlled finance decision, and unmappable carrier
 * statuses become owned exceptions with a real deadline.
 */
class FinanceClosureGuardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (FinanceAccess::permissions() as $permission) {
            SpatiePermission::firstOrCreate(['name' => $permission, 'guard_name' => 'api']);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_broad_seeding_revokes_legacy_finance_grants_and_keeps_dedicated_role(): void
    {
        $this->seed(PermissionSeeder::class);

        $financeRole = Role::where('name', FinanceAccess::ROLE)->where('guard_name', 'api')->firstOrFail();
        $superAdminRole = Role::where('name', 'super_admin')->where('guard_name', 'api')->firstOrFail();
        $admin = User::where('email', 'admin@demo.com')->firstOrFail();

        $financePermissionIds = SpatiePermission::query()
            ->whereIn('name', FinanceAccess::permissions())
            ->pluck('id')
            ->all();

        // Simulate the legacy broad-grant rows before running the revoke migration.
        foreach ($financePermissionIds as $permissionId) {
            DB::table('role_has_permissions')->insertOrIgnore([
                'permission_id' => $permissionId,
                'role_id' => $superAdminRole->id,
            ]);
            DB::table('model_has_permissions')->insertOrIgnore([
                'permission_id' => $permissionId,
                'model_type' => $admin->getMorphClass(),
                'model_id' => $admin->id,
            ]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $migration = require database_path('migrations/2026_11_24_000000_revoke_broad_finance_grants.php');
        $migration->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($financePermissionIds as $permissionId) {
            $this->assertDatabaseMissing('role_has_permissions', [
                'permission_id' => $permissionId,
                'role_id' => $superAdminRole->id,
            ]);
            $this->assertDatabaseMissing('model_has_permissions', [
                'permission_id' => $permissionId,
                'model_type' => $admin->getMorphClass(),
                'model_id' => $admin->id,
            ]);
            $this->assertDatabaseHas('role_has_permissions', [
                'permission_id' => $permissionId,
                'role_id' => $financeRole->id,
            ]);
        }

        $broadNames = FinanceAccess::broadlyGrantablePermissions()->pluck('name')->all();
        $this->assertSame([], array_values(array_intersect($broadNames, FinanceAccess::permissions())));
        $this->assertFalse(Gate::forUser($admin->fresh())->allows(Permission::COD_SETTLEMENT_CLOSE));
    }

    public function test_break_glass_access_requires_explicit_actor_reason_and_window_then_is_audited(): void
    {
        $this->setClock('2026-11-25 10:00:00');

        $actor = $this->user('admin');
        $target = $this->user('staff');
        $permission = Permission::COD_SETTLEMENT_CLOSE;
        $service = app(FinanceBreakGlassService::class);

        $this->assertFalse(Gate::forUser($target)->allows($permission));

        foreach ([
            fn () => $service->grant($target, $permission, CarbonImmutable::now()->addHour(), 0, 'incident 191'),
            fn () => $service->grant($target, $permission, CarbonImmutable::now()->addHour(), $actor->id, '   '),
            fn () => $service->grant($target, $permission, CarbonImmutable::now()->addHours(9), $actor->id, 'incident 191'),
        ] as $invalidGrant) {
            try {
                $invalidGrant();
                $this->fail('Invalid break-glass grant must be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
        }

        $this->assertSame(0, FinanceAccessGrant::count());
        $this->assertSame(0, Activity::where('event', 'finance.break_glass_granted')->count());

        $grant = $service->grant(
            $target,
            $permission,
            CarbonImmutable::now()->addHours(2),
            $actor->id,
            'incident 191 reconciliation',
        );

        $this->assertDatabaseHas('finance_access_grants', [
            'id' => $grant->id,
            'user_id' => $target->id,
            'permission' => $permission,
            'reason' => 'incident 191 reconciliation',
            'granted_by' => $actor->id,
            'revoked_at' => null,
        ]);
        $this->assertTrue(Gate::forUser($target)->allows($permission));

        $grantActivity = Activity::where('event', 'finance.break_glass_granted')->firstOrFail();
        $this->assertSame($actor->id, (int) $grantActivity->causer_id);
        $this->assertSame($permission, $grantActivity->properties['new']['permission']);
        $this->assertSame('incident 191 reconciliation', $grantActivity->properties['context']['reason']);

        try {
            $service->grant($target, $permission, CarbonImmutable::now()->addHour(), $actor->id, 'duplicate');
            $this->fail('A second active grant for the same permission must be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame(__('finance.already_active'), $exception->getMessage());
        }
        $this->assertSame(1, FinanceAccessGrant::count());
        $this->assertSame(1, Activity::where('event', 'finance.break_glass_granted')->count());

        // The grant expires even if the Spatie pivot has not been cleaned up.
        $this->setClock('2026-11-25 12:01:00');
        $this->assertFalse(Gate::forUser($target)->allows($permission));

        $this->setClock('2026-11-25 10:00:00');
        $service->revoke($grant, $actor->id, 'incident 191 resolved');

        $this->assertNotNull($grant->fresh()->revoked_at);
        $this->assertFalse(Gate::forUser($target)->allows($permission));

        $revokeActivity = Activity::where('event', 'finance.break_glass_revoked')->firstOrFail();
        $this->assertSame($actor->id, (int) $revokeActivity->causer_id);
        $this->assertSame('incident 191 resolved', $revokeActivity->properties['context']['reason']);
    }

    public function test_ambiguous_order_number_is_blocked_and_manual_match_is_audited(): void
    {
        $this->currency();
        $first = $this->order('ORD-AMBIG-191');
        $second = $this->order('ORD-AMBIG-191');
        $this->shipment($first, 'carrier_a', 'sandbox');
        $this->shipment($second, 'carrier_a', 'sandbox');
        $actor = $this->user('staff');

        $payload = $this->statementPayload([[
            'order_number' => 'ORD-AMBIG-191',
            'expected' => '10.00',
            'received' => '9.00',
        ]]);

        try {
            app(CodSettlementService::class)->createStatement($payload);
            $this->fail('Ambiguous order-number matching must not be automatic.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('order_number_ambiguous', $exception->getMessage());
        }
        $this->assertSame(0, CodSettlementStatement::count());

        $matched = app(SettlementOrderMatcher::class)->matchManually(
            'ORD-AMBIG-191',
            $second->id,
            $actor->id,
            'carrier statement 191 verified',
            ['currency_code' => 'EGP'],
        );

        $this->assertSame($second->id, $matched->id);
        $matchActivity = Activity::where('event', 'settlement.order_match_manual')->firstOrFail();
        $this->assertSame($actor->id, (int) $matchActivity->causer_id);
        $this->assertSame($second->id, $matchActivity->properties['new']['order_id']);
        $this->assertSame('carrier statement 191 verified', $matchActivity->properties['reason']);

        $payload['statement_key'] = 'stmt-'.Str::random(12);
        $payload['lines'] = [[
            'order_id' => $second->id,
            'expected' => '10.00',
            'received' => '9.00',
        ]];
        $statement = app(CodSettlementService::class)->createStatement($payload);

        $this->assertDatabaseHas('cod_settlement_lines', [
            'statement_id' => $statement->id,
            'order_id' => $second->id,
        ]);
    }

    public function test_non_zero_variance_blocks_closure_until_approved_threshold_and_reference_exist(): void
    {
        $this->currency();
        $order = $this->order('ORD-CLOSE-191');
        $actor = $this->user('staff');
        $statement = app(CodSettlementService::class)->createStatement($this->statementPayload([[
            'order_id' => $order->id,
            'expected' => '100.50',
            'received' => '100.00',
        ]]));

        $this->assertSame(-50, (int) $statement->variance_minor);

        try {
            app(CodSettlementService::class)->closeStatement($statement->id, $actor->id, 'FIN-191');
            $this->fail('Variance above the default zero tolerance must block closure.');
        } catch (CodSettlementVarianceException $exception) {
            $this->assertSame(
                __('cod_settlement.closure_variance_blocked', [
                    'variance' => 50,
                    'tolerance' => 0,
                    'currency' => 'EGP',
                ]),
                $exception->getMessage(),
            );
        }
        $this->assertNull($statement->fresh()->metadata['closure'] ?? null);
        $this->assertSame(0, Activity::where('event', 'settlement.closed')->count());

        config(['finance.settlement.variance_tolerance_minor.EGP' => 49]);
        try {
            app(CodSettlementService::class)->closeStatement($statement->id, $actor->id, 'FIN-191');
            $this->fail('Variance above an approved threshold must still block closure.');
        } catch (CodSettlementVarianceException $exception) {
            $this->assertStringContainsString('49', $exception->getMessage());
        }

        config(['finance.settlement.variance_tolerance_minor.EGP' => 50]);
        try {
            app(CodSettlementService::class)->closeStatement($statement->id, $actor->id);
            $this->fail('An approved threshold still requires an approval reference.');
        } catch (CodSettlementVarianceException $exception) {
            $this->assertSame(__('cod_settlement.closure_approval_reference_required'), $exception->getMessage());
        }
        $this->assertSame(0, Activity::where('event', 'settlement.closed')->count());

        $closed = app(CodSettlementService::class)->closeStatement($statement->id, $actor->id, 'FIN-APPROVAL-191');

        $this->assertSame(50, $closed->metadata['closure']['approved_variance_tolerance_minor']);
        $this->assertSame(50, $closed->metadata['closure']['max_absolute_variance_minor']);
        $this->assertSame('FIN-APPROVAL-191', $closed->metadata['closure']['approval_reference']);
        $this->assertSame($actor->id, (int) $closed->closed_by);

        $closureActivity = Activity::where('event', 'settlement.closed')->firstOrFail();
        $this->assertSame($actor->id, (int) $closureActivity->causer_id);
        $this->assertSame('FIN-APPROVAL-191', $closureActivity->properties['reason']);

        // Closure is idempotent on the statement lifecycle status: a second
        // call returns the already-closed statement without a new audit event.
        $reopened = app(CodSettlementService::class)->closeStatement($closed->id, $actor->id, 'FIN-APPROVAL-191');
        $this->assertSame($closed->id, $reopened->id);
        $this->assertSame(1, Activity::where('event', 'settlement.closed')->count());
    }

    public function test_unknown_provider_status_creates_owned_exception_with_business_day_sla(): void
    {
        $this->setClock('2026-11-25 10:00:00');

        config([
            'shipping.providers.test_carrier.mode' => 'sandbox',
            'shipping.providers.test_carrier.modes.sandbox.status_map' => [
                'version' => 7,
                'status_map_owner' => 'shipping-platform',
                'statuses' => [
                    'label_created' => ShipmentStatus::LABEL_CREATED->value,
                ],
            ],
            'shipping.exceptions.owner_team' => 'carrier-ops-191',
            'shipping.exceptions.sla_business_days' => 2,
        ]);

        $shipment = $this->shipment($this->order('ORD-UNKNOWN-191'));
        $webhook = $this->webhook($shipment, 'carrier_invents_state_191', 'evt_unknown_191');
        $recorder = app(RecordingShipmentService::class);
        app()->instance(ShipmentService::class, $recorder);

        $event = app(ShippingProviderEventProcessor::class)->process($webhook);

        $this->assertSame(ProviderEventExceptionType::UNKNOWN_STATUS->value, $event->exception_classification);
        $this->assertNull($event->applied_at);
        $this->assertSame([], $recorder->calls);

        $exception = ShipmentException::where('provider_event_id', $event->id)->firstOrFail();
        $this->assertSame(ProviderEventExceptionType::UNKNOWN_STATUS->value, $exception->type);
        $this->assertSame(ShipmentExceptionSeverity::HIGH->value, $exception->severity);
        $this->assertSame(ShipmentExceptionStatus::OPEN->value, $exception->status);
        $this->assertSame('carrier-ops-191', $exception->owner_team);
        $this->assertSame('2026-11-27 10:00:00', $exception->sla_due_at->toDateTimeString());
        $this->assertSame(ShipmentStatus::PENDING->value, $shipment->fresh()->status);
    }

    private function setClock(string $at): void
    {
        Carbon::setTestNow(Carbon::parse($at));
        CarbonImmutable::setTestNow(CarbonImmutable::parse($at));
    }

    private function user(string $type): User
    {
        return User::factory()->create(['type' => $type]);
    }

    private function currency(string $code = 'EGP', int $decimalPlaces = 2): Currency
    {
        return Currency::create([
            'code' => $code,
            'name' => ['en' => $code, 'ar' => $code],
            'symbol' => ['en' => $code, 'ar' => $code],
            'country_name' => ['en' => 'Test', 'ar' => 'اختبار'],
            'numeric_code' => '840',
            'decimal_places' => $decimalPlaces,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    private function order(string $orderNumber, string $currencyCode = 'EGP'): Order
    {
        $customer = User::factory()->create(['type' => 'customer']);

        return Order::create([
            'order_number' => $orderNumber,
            'user_id' => $customer->id,
            'name' => 'Finance Guard Customer',
            'user_email' => $customer->email,
            'user_phone' => '01000000000',
            'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'fulfillment_status' => 'pending',
            'price' => 1000,
            'total_price' => 1000,
            'currency_code' => $currencyCode,
            'base_currency_code' => 'EGP',
            'payment_method' => 'cod',
            'address' => ['city' => 'Cairo'],
        ]);
    }

    private function statementPayload(array $lines, array $overrides = []): array
    {
        return array_merge([
            'provider_code' => 'carrier_a',
            'mode' => SettlementMode::SANDBOX->value,
            'account_code' => 'account-01',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'statement_key' => 'stmt-'.Str::random(12),
            'lines' => $lines,
        ], $overrides);
    }

    private function shipment(
        Order $order,
        string $providerCode = 'test_carrier',
        string $providerMode = 'sandbox',
    ): Shipment {
        return Shipment::create([
            'order_id' => $order->id,
            'status' => 'pending',
            'provider_code' => $providerCode,
            'provider_mode' => $providerMode,
        ]);
    }

    private function webhook(Shipment $shipment, string $rawStatus, string $eventKey): ShippingWebhookEvent
    {
        return ShippingWebhookEvent::create([
            'provider_code' => 'test_carrier',
            'mode' => 'sandbox',
            'account_code' => 'test-account',
            'webhook_version' => 'v1',
            'event_key' => $eventKey,
            'event_type' => 'shipment.status_changed',
            'provider_shipment_ref' => 'carrier-ref-191',
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
