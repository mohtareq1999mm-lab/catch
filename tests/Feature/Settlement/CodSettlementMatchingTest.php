<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Enums\SettlementMode;
use App\Models\Currency;
use App\Models\Settlement\CodSettlementLine;
use App\Models\Settlement\CodSettlementStatement;
use App\Models\Shipment;
use App\Services\Settlement\CodSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Tests\TestCase;

class CodSettlementMatchingTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = '/api/v1/admin/cod-settlements/statements';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        foreach ([
            Permission::COD_SETTLEMENT_VIEW,
            Permission::COD_SETTLEMENT_MATCH,
            Permission::COD_SETTLEMENT_CLOSE,
        ] as $name) {
            SpatiePermission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }
    }

    private function currency(string $code, int $dp = 2): Currency
    {
        return Currency::create([
            'code' => $code, 'name' => ['en' => $code, 'ar' => $code],
            'symbol' => ['en' => $code, 'ar' => $code],
            'country_name' => ['en' => 'T', 'ar' => 'T'],
            'numeric_code' => '840', 'decimal_places' => $dp, 'is_active' => true, 'sort_order' => 0,
        ]);
    }

    private function order(string $currency = 'EGP', array $overrides = []): Order
    {
        $customer = User::factory()->create(['type' => 'customer']);
        return Order::create(array_merge([
            'user_id' => $customer->id,
            'name' => 'C', 'user_email' => $customer->email, 'user_phone' => '01000000000',
            'status' => 'pending', 'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'fulfillment_status' => 'pending', 'price' => 100, 'total_price' => 100,
            'currency_code' => $currency, 'base_currency_code' => 'EGP',
            'payment_method' => 'cod', 'address' => ['city' => 'Cairo'],
        ], $overrides));
    }

    private function shipment(Order $order, array $overrides = []): Shipment
    {
        return Shipment::create(array_merge([
            'order_id' => $order->id,
            'status' => 'pending',
            'currency' => $order->currency_code,
        ], $overrides));
    }

    private function financeUser(array $perms = [Permission::COD_SETTLEMENT_VIEW, Permission::COD_SETTLEMENT_MATCH, Permission::COD_SETTLEMENT_CLOSE]): User
    {
        $u = User::factory()->create(['type' => 'staff']);
        foreach ($perms as $p) { $u->givePermissionTo($p); }
        return $u;
    }

    private function payload(array $lines, array $overrides = []): array
    {
        return array_merge([
            'provider_code' => 'carrier_a', 'mode' => SettlementMode::SANDBOX->value,
            'account_code' => 'acct', 'period_start' => '2026-11-01', 'period_end' => '2026-11-30',
            'statement_key' => 'stmt-' . uniqid(), 'lines' => $lines,
        ], $overrides);
    }

    public function test_matching_precedence_awb_first_then_provider_shipment_id_then_unambiguous_order_number(): void
    {
        $this->currency('EGP');
        $orderA = $this->order();
        $orderB = $this->order();
        $orderC = $this->order();
        // AWB points to A, provider shipment id points to B, order number points to C - AWB wins
        $this->shipment($orderA, ['tracking_number' => 'AWB-1', 'provider_shipment_id' => 'PS-OTHER']);
        $this->shipment($orderB, ['tracking_number' => 'AWB-B', 'provider_shipment_id' => 'PS-1']);
        $this->shipment($orderC, ['tracking_number' => 'AWB-C']);

        $svc = app(CodSettlementService::class);
        $stmt = $svc->createStatement($this->payload([
            ['awb' => 'AWB-1', 'provider_shipment_id' => 'PS-1', 'order_number' => $orderC->order_number, 'expected' => '10.00', 'received' => '10.00'],
        ]));

        $line = $stmt->lines->first();
        $this->assertSame($orderA->id, (int) $line->order_id);
        $this->assertSame('awb', $line->match_method);

        // provider shipment ID wins when no AWB match
        $stmt2 = $svc->createStatement($this->payload([
            ['provider_shipment_id' => 'PS-1', 'order_number' => $orderC->order_number, 'expected' => '10.00', 'received' => '10.00'],
        ], ['statement_key' => 'stmt-' . uniqid()]));
        $this->assertSame($orderB->id, (int) $stmt2->lines->first()->order_id);
        $this->assertSame('provider_shipment_id', $stmt2->lines->first()->match_method);

        // order number only when unambiguous
        $stmt3 = $svc->createStatement($this->payload([
            ['order_number' => $orderC->order_number, 'expected' => '10.00', 'received' => '10.00'],
        ], ['statement_key' => 'stmt-' . uniqid()]));
        $this->assertSame($orderC->id, (int) $stmt3->lines->first()->order_id);
        $this->assertSame('order_number', $stmt3->lines->first()->match_method);
    }

    public function test_order_number_match_must_be_unambiguous(): void
    {
        $this->currency('EGP');
        $order = $this->order();
        // create another order with same displayed number impossible by system, but ambiguity guard: if not found exactly one, fail.
        $svc = app(CodSettlementService::class);
        $this->expectException(\InvalidArgumentException::class);
        $svc->createStatement($this->payload([
            ['order_number' => 'DOES-NOT-EXIST', 'expected' => '10.00', 'received' => '10.00'],
        ]));
    }

    public function test_manual_match_requires_actor_and_reason_and_is_audited(): void
    {
        $this->currency('EGP');
        $order = $this->order();
        $shipment = $this->shipment($order, ['tracking_number' => 'AWB-M1']);
        $stmt = app(CodSettlementService::class)->createStatement($this->payload([
            ['awb' => 'UNKNOWN-AWB', 'expected' => '10.00', 'received' => '10.00'],
        ], ['currency_code' => 'EGP']));
        $line = $stmt->lines->first();
        $this->assertNull($line->order_id);
        $this->assertSame('unmatched', $line->match_status);

        $finance = $this->financeUser();
        $res = $this->actingAs($finance, 'sanctum')->postJson(self::PREFIX . "/{$stmt->id}/lines/{$line->id}/match", [
            'order_id' => $order->id,
            'reason' => 'Carrier used legacy AWB',
        ]);
        $res->assertOk();

        $line->refresh();
        $this->assertSame($order->id, (int) $line->order_id);
        $this->assertSame('manual', $line->match_method);
        $this->assertSame($finance->id, (int) $line->matched_by);
        $this->assertSame('Carrier used legacy AWB', $line->match_reason);

        $this->assertDatabaseHas('cod_settlement_match_audits', [
            'statement_id' => $stmt->id,
            'line_id' => $line->id,
            'actor_id' => $finance->id,
            'reason' => 'Carrier used legacy AWB',
            'order_id' => $order->id,
        ]);

        // missing reason rejected
        $stmt2 = app(CodSettlementService::class)->createStatement($this->payload([
            ['awb' => 'UNKNOWN-AWB2', 'expected' => '10.00', 'received' => '10.00'],
        ], ['statement_key' => 'stmt-' . uniqid(), 'currency_code' => 'EGP']));
        $line2 = $stmt2->lines->first();
        $bad = $this->actingAs($finance, 'sanctum')->postJson(self::PREFIX . "/{$stmt2->id}/lines/{$line2->id}/match", [
            'order_id' => $order->id,
        ]);
        $bad->assertStatus(422);
    }

    public function test_statement_cannot_close_while_unmatched_lines_or_unresolved_variances_remain(): void
    {
        $this->currency('EGP');
        $order = $this->order();
        $this->shipment($order, ['tracking_number' => 'AWB-CLOSE']);
        $finance = $this->financeUser();

        $stmtUnmatched = app(CodSettlementService::class)->createStatement($this->payload([
            ['awb' => 'UNKNOWN', 'expected' => '10.00', 'received' => '10.00'],
        ], ['currency_code' => 'EGP']));
        $r1 = $this->actingAs($finance, 'sanctum')->postJson(self::PREFIX . "/{$stmtUnmatched->id}/close", []);
        $r1->assertStatus(422);

        $stmtVariance = app(CodSettlementService::class)->createStatement($this->payload([
            ['awb' => 'AWB-CLOSE', 'expected' => '10.00', 'received' => '9.00'],
        ], ['statement_key' => 'stmt-' . uniqid()]));
        $r2 = $this->actingAs($finance, 'sanctum')->postJson(self::PREFIX . "/{$stmtVariance->id}/close", []);
        $r2->assertStatus(422);

        $stmtOk = app(CodSettlementService::class)->createStatement($this->payload([
            ['awb' => 'AWB-CLOSE', 'expected' => '10.00', 'received' => '10.00'],
        ], ['statement_key' => 'stmt-' . uniqid()]));
        $ok = $this->actingAs($finance, 'sanctum')->postJson(self::PREFIX . "/{$stmtOk->id}/close", ['reason' => 'Balanced']);
        $ok->assertOk();
        $this->assertDatabaseHas('cod_settlement_statements', [
            'id' => $stmtOk->id,
            'status' => 'closed',
            'closed_by' => $finance->id,
        ]);
    }

    public function test_finance_role_permissions_separate_from_shipment_mutation_permissions(): void
    {
        $this->currency('EGP');
        $order = $this->order();
        $this->shipment($order, ['tracking_number' => 'AWB-PERM']);
        $stmt = app(CodSettlementService::class)->createStatement($this->payload([
            ['awb' => 'AWB-PERM', 'expected' => '10.00', 'received' => '10.00'],
        ]));

        $shipmentMutator = User::factory()->create(['type' => 'staff']);
        foreach ([Permission::WMS_SHIPMENTS_CREATE, Permission::WMS_SHIPMENTS_DELIVER, Permission::WMS_SHIPMENTS_MANUAL_CORRECT] as $p) {
            SpatiePermission::firstOrCreate(['name' => $p, 'guard_name' => 'api']);
            $shipmentMutator->givePermissionTo($p);
        }
        SpatiePermission::firstOrCreate(['name' => Permission::COD_SETTLEMENT_VIEW, 'guard_name' => 'api']);
        $shipmentMutator->givePermissionTo(Permission::COD_SETTLEMENT_VIEW);

        $this->actingAs($shipmentMutator, 'sanctum')
            ->postJson(self::PREFIX . "/{$stmt->id}/close", ['reason' => 'x'])
            ->assertStatus(403);

        $line = $stmt->lines->first();
        $this->actingAs($shipmentMutator, 'sanctum')
            ->postJson(self::PREFIX . "/{$stmt->id}/lines/{$line->id}/match", ['order_id' => $order->id, 'reason' => 'x'])
            ->assertStatus(403);

        $financeOnly = $this->financeUser([Permission::COD_SETTLEMENT_VIEW, Permission::COD_SETTLEMENT_MATCH, Permission::COD_SETTLEMENT_CLOSE]);
        $this->assertFalse($financeOnly->can(Permission::WMS_SHIPMENTS_MANUAL_CORRECT));
        $this->assertFalse($financeOnly->can(Permission::ORDERS_SHIPMENT_UPDATE_STATUS));
    }
}
