<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Enums\SettlementMode;
use App\Exceptions\ProviderShipmentIdConflictException;
use App\Models\Currency;
use App\Models\Settlement\CodSettlementLine;
use App\Models\Settlement\CodSettlementStatement;
use App\Models\Shipment;
use App\Models\Shipping\ShippingProviderEvent;
use App\Services\Settlement\CodSettlementService;
use App\Services\Shipment\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Tests\TestCase;

/**
 * #156: every automatic match must be identity-scoped and unambiguous.
 */
class CodSettlementMatchingTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = '/api/v1/admin/cod-settlements/statements';
    private const PROVIDER = 'carrier_a';
    private const MODE = 'sandbox';
    private const ACCOUNT = 'acct';
    private const PERIOD_START = '2026-11-01';
    private const PERIOD_END = '2026-11-30';
    private const SHIPPED_AT = '2026-11-15 12:00:00';

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
        return Currency::firstOrCreate(['code' => $code], [
            'name' => ['en' => $code, 'ar' => $code],
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
            'order_number' => 'ORD-' . strtoupper(uniqid()),
        ], $overrides));
    }

    private function shipment(Order $order, array $overrides = []): Shipment
    {
        // created_at/updated_at are not fillable; forceFill keeps the
        // identity-period fixture honest instead of silently using "now".
        $shipment = new Shipment();
        $shipment->forceFill(array_merge([
            'order_id' => $order->id,
            'status' => 'pending',
            'currency' => $order->currency_code,
            'provider_code' => self::PROVIDER,
            'provider_mode' => self::MODE,
            'created_at' => self::SHIPPED_AT,
            'updated_at' => self::SHIPPED_AT,
        ], $overrides));
        $shipment->save();

        return $shipment;
    }

    private function providerEvent(
        Shipment $shipment,
        string $accountCode = self::ACCOUNT,
        string $providerCode = self::PROVIDER,
        string $mode = self::MODE,
    ): void {
        $webhookId = DB::table('shipping_webhook_events')->insertGetId([
            'provider_code' => $providerCode,
            'mode' => $mode,
            'account_code' => $accountCode,
            'webhook_version' => 'v1',
            'event_key' => 'evt-' . uniqid(),
            'redacted_payload' => '{}',
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        ShippingProviderEvent::create([
            'webhook_event_id' => $webhookId,
            'shipment_id' => $shipment->id,
            'provider_code' => $providerCode,
            'mode' => $mode,
            'account_code' => $accountCode,
            'provider_event_key' => 'key-' . uniqid(),
            'raw_status' => 'in_transit',
            'payload_reference' => 'reference-' . uniqid(),
            'payload_hash' => str_repeat('a', 64),
            'applied_at' => now(),
        ]);
    }

    private function financeUser(array $perms = [
        Permission::COD_SETTLEMENT_VIEW,
        Permission::COD_SETTLEMENT_MATCH,
        Permission::COD_SETTLEMENT_CLOSE,
    ]): User {
        $user = User::factory()->create(['type' => 'staff']);
        foreach ($perms as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    private function payload(array $lines, array $overrides = []): array
    {
        return array_merge([
            'provider_code' => self::PROVIDER,
            'mode' => SettlementMode::SANDBOX->value,
            'account_code' => self::ACCOUNT,
            'period_start' => self::PERIOD_START,
            'period_end' => self::PERIOD_END,
            'statement_key' => 'stmt-' . uniqid(),
            'currency_code' => 'EGP',
            'lines' => $lines,
        ], $overrides);
    }

    private function assertRejected(callable $call, string $field): void
    {
        try {
            $call();
            $this->fail("Expected rejection for {$field}.");
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString($field, $exception->getMessage());
        }
    }

    private function service(): CodSettlementService
    {
        return app(CodSettlementService::class);
    }

    /**
     * MySQL commits implicitly around DDL, so Laravel's in-memory
     * transaction counter stops matching the server. Reset it so the
     * begin/rollback calls below target a real transaction.
     */
    private function resyncTransactionCounter(): void
    {
        $connection = DB::connection();
        $property = new \ReflectionProperty($connection, 'transactions');
        $property->setAccessible(true);
        $property->setValue($connection, 0);
    }

    public function test_matching_precedence_is_scoped_to_full_settlement_identity(): void
    {
        $this->currency('EGP');
        $orderA = $this->order();
        $orderB = $this->order();
        $orderC = $this->order();
        $this->shipment($orderA, ['tracking_number' => 'AWB-1', 'provider_shipment_id' => 'PS-OTHER']);
        $this->shipment($orderB, ['tracking_number' => 'AWB-B', 'provider_shipment_id' => 'PS-1']);
        $this->shipment($orderC, ['tracking_number' => 'AWB-C']);

        $first = $this->service()->createStatement($this->payload([[
            'awb' => 'AWB-1',
            'provider_shipment_id' => 'PS-1',
            'order_number' => $orderC->order_number,
            'expected' => '10.00',
            'received' => '10.00',
        ]]));
        $this->assertSame($orderA->id, (int) $first->lines->first()->order_id);
        $this->assertSame('awb', $first->lines->first()->match_method);

        $second = $this->service()->createStatement($this->payload([[
            'provider_shipment_id' => 'PS-1',
            'order_number' => $orderC->order_number,
            'expected' => '10.00',
            'received' => '10.00',
        ]]));
        $this->assertSame($orderB->id, (int) $second->lines->first()->order_id);
        $this->assertSame('provider_shipment_id', $second->lines->first()->match_method);

        $third = $this->service()->createStatement($this->payload([[
            'order_number' => $orderC->order_number,
            'expected' => '10.00',
            'received' => '10.00',
        ]]));
        $this->assertSame($orderC->id, (int) $third->lines->first()->order_id);
        $this->assertSame('order_number', $third->lines->first()->match_method);
    }

    public function test_duplicate_awb_lines_and_split_shipments_fail_closed(): void
    {
        $this->currency('EGP');
        $order = $this->order();
        $this->shipment($order, ['tracking_number' => 'AWB-AMBIG-LINES']);

        $lines = [];
        for ($i = 0; $i < 2; $i++) {
            $lines[] = [
                'line_key' => 'amb-line-' . $i,
                'awb' => 'AWB-AMBIG-LINES',
                'expected' => '10.00',
                'received' => '10.00',
            ];
        }
        $this->assertRejected(
            fn () => $this->service()->createStatement($this->payload($lines)),
            'awb_ambiguous',
        );

        $splitOrder = $this->order();
        $this->shipment($splitOrder, ['tracking_number' => 'AWB-SPLIT-1']);
        $this->shipment($splitOrder, ['tracking_number' => 'AWB-SPLIT-2']);
        $this->assertRejected(fn () => $this->service()->createStatement($this->payload([[
            'order_number' => $splitOrder->order_number, 'expected' => '10.00', 'received' => '10.00',
        ]])), 'order_number_ambiguous');
        $this->assertRejected(fn () => $this->service()->createStatement($this->payload([[
            'order_id' => $splitOrder->id, 'expected' => '10.00', 'received' => '10.00',
        ]])), 'order_id_ambiguous');
    }

    public function test_duplicate_order_numbers_fail_closed(): void
    {
        $this->currency('EGP');
        $number = 'ORD-DUPLICATE';
        $first = $this->order('EGP', ['order_number' => $number]);
        $second = $this->order('EGP', ['order_number' => $number]);
        $this->shipment($first, ['tracking_number' => 'AWB-DUP-1']);
        $this->shipment($second, ['tracking_number' => 'AWB-DUP-2']);

        $this->assertRejected(fn () => $this->service()->createStatement($this->payload([[
            'order_number' => $number, 'expected' => '10.00', 'received' => '10.00',
        ]])), 'order_number_ambiguous');
    }

    public function test_multiple_settlement_lines_cannot_share_the_same_reference(): void
    {
        $this->currency('EGP');
        $order = $this->order();
        $this->shipment($order, ['tracking_number' => 'AWB-LINES']);

        foreach ([
            ['order_number' => $order->order_number],
            ['awb' => 'AWB-LINES'],
            ['order_id' => $order->id],
        ] as $reference) {
            $lines = [];
            for ($i = 0; $i < 2; $i++) {
                $lines[] = $reference + [
                    'line_key' => 'line-' . uniqid(),
                    'expected' => '10.00',
                    'received' => '10.00',
                ];
            }
            $this->assertRejected(
                fn () => $this->service()->createStatement($this->payload($lines)),
                array_key_first($reference) . '_ambiguous',
            );
        }
    }

    public function test_matching_ignores_shipments_outside_provider_mode_period_currency_and_account_scope(): void
    {
        $this->currency('EGP');
        $this->currency('USD');
        $outOfScopeShipments = [
            ['tracking_number' => 'AWB-SCOPE-PROVIDER', 'provider_code' => 'carrier_b'],
            ['tracking_number' => 'AWB-SCOPE-MODE', 'provider_mode' => 'production'],
            ['tracking_number' => 'AWB-SCOPE-PERIOD', 'created_at' => '2026-10-15 12:00:00'],
        ];
        foreach ($outOfScopeShipments as $overrides) {
            $this->shipment($this->order(), $overrides);
            $outOfScope = $this->service()->createStatement($this->payload([[
                'awb' => $overrides['tracking_number'], 'expected' => '10.00', 'received' => '10.00',
            ]]));
            $this->assertNull($outOfScope->lines->first()->order_id);
            $this->assertSame('unmatched', $outOfScope->lines->first()->match_status);
        }

        $wrongCurrencyOrder = $this->order('USD');
        $this->shipment($wrongCurrencyOrder, ['tracking_number' => 'AWB-SCOPE-CURRENCY']);
        $wrongCurrency = $this->service()->createStatement($this->payload([[
            'awb' => 'AWB-SCOPE-CURRENCY', 'expected' => '10.00', 'received' => '10.00',
        ]]));
        $this->assertNull($wrongCurrency->lines->first()->order_id);

        $wrongAccountShipment = $this->shipment($this->order(), ['tracking_number' => 'AWB-SCOPE-ACCOUNT']);
        $this->providerEvent($wrongAccountShipment, 'other-account');
        $wrongAccount = $this->service()->createStatement($this->payload([[
            'awb' => 'AWB-SCOPE-ACCOUNT', 'expected' => '10.00', 'received' => '10.00',
        ]]));
        $this->assertNull($wrongAccount->lines->first()->order_id);

        $inScopeOrder = $this->order();
        $inScopeShipment = $this->shipment($inScopeOrder, ['tracking_number' => 'AWB-SCOPE-IN']);
        $this->providerEvent($inScopeShipment);
        $inScope = $this->service()->createStatement($this->payload([[
            'awb' => 'AWB-SCOPE-IN', 'expected' => '10.00', 'received' => '10.00',
        ]]));
        $this->assertSame($inScopeOrder->id, (int) $inScope->lines->first()->order_id);
    }

    public function test_duplicate_provider_shipment_ids_fail_closed_even_without_a_database_backstop(): void
    {
        Schema::table('shipments', function ($table) {
            $table->dropUnique('uq_ship_provider_shipment_identity');
        });
        // MySQL commits implicitly around DDL, leaving Laravel's transaction
        // counter stale. Resync it before owning fixtures in a fresh
        // transaction, and again after restoring the index so RefreshDatabase
        // can roll its own transaction back at teardown.
        $this->resyncTransactionCounter();
        DB::beginTransaction();
        try {
            $this->currency('EGP');
            $firstOrder = $this->order();
            $secondOrder = $this->order();
            $this->shipment($firstOrder, ['provider_shipment_id' => 'PS-SHARED']);
            $this->shipment($secondOrder, ['provider_shipment_id' => 'PS-SHARED']);
            $this->assertRejected(fn () => $this->service()->createStatement($this->payload([[
                'provider_shipment_id' => 'PS-SHARED', 'expected' => '10.00', 'received' => '10.00',
            ]])), 'provider_shipment_id_ambiguous');
        } finally {
            DB::rollBack();
            Schema::table('shipments', function ($table) {
                $table->unique(
                    ['provider_code', 'provider_mode', 'provider_shipment_id'],
                    'uq_ship_provider_shipment_identity',
                );
            });
            if (DB::transactionLevel() === 0) {
                DB::beginTransaction();
            }
        }
    }

    public function test_canonical_shipment_creation_persists_and_protects_provider_shipment_ids(): void
    {
        $this->currency('EGP');
        $firstOrder = $this->order();
        $secondOrder = $this->order();
        $service = app(ShipmentService::class);

        $withId = $service->create([
            'order_id' => $firstOrder->id,
            'provider_shipment_id' => 'PS-CANONICAL',
        ]);
        $this->assertSame('PS-CANONICAL', $withId->fresh()->provider_shipment_id);

        $withoutId = $service->create(['order_id' => $secondOrder->id]);
        $this->assertNull($withoutId->fresh()->provider_shipment_id);

        try {
            $service->create([
                'order_id' => $secondOrder->id,
                'provider_shipment_id' => 'PS-CANONICAL',
            ]);
            $this->fail('Duplicate carrier shipment ids must be refused.');
        } catch (ProviderShipmentIdConflictException $exception) {
            $this->assertSame(422, $exception->statusCode());
        }

        $this->assertSame(1, Shipment::query()->where('provider_shipment_id', 'PS-CANONICAL')->count());
    }

    public function test_manual_match_is_scoped_and_preserves_previous_match_identity_in_audit(): void
    {
        $this->currency('EGP');
        $firstOrder = $this->order();
        $secondOrder = $this->order();
        $this->shipment($firstOrder, ['tracking_number' => 'AWB-FIRST']);
        $this->shipment($secondOrder, ['tracking_number' => 'AWB-SECOND']);

        $statement = $this->service()->createStatement($this->payload([[
            'awb' => 'AWB-FIRST', 'expected' => '10.00', 'received' => '10.00',
        ]]));
        $line = $statement->lines->first();
        $finance = $this->financeUser();

        $res = $this->actingAs($finance, 'sanctum')->postJson(
            self::PREFIX . "/{$statement->id}/lines/{$line->id}/match",
            ['order_id' => $secondOrder->id, 'reason' => 'Carrier corrected the AWB'],
        );
        $res->assertOk()
            ->assertJsonPath('data.order_id', $secondOrder->id)
            ->assertJsonPath('data.match_method', 'manual');

        $this->assertDatabaseHas('cod_settlement_match_audits', [
            'statement_id' => $statement->id,
            'line_id' => $line->id,
            'actor_id' => $finance->id,
            'previous_order_id' => $firstOrder->id,
            'previous_match_status' => 'matched',
            'previous_match_method' => 'awb',
            'new_match_status' => 'matched',
            'match_method' => 'manual',
        ]);

        $outOfScopeOrder = $this->order('EGP', ['order_number' => 'ORD-OUT-OF-SCOPE']);
        $this->shipment($outOfScopeOrder, [
            'tracking_number' => 'AWB-OUT', 'provider_mode' => 'production',
        ]);
        $this->assertRejected(fn () => $this->service()->manualMatch(
            $statement->id,
            $line->id,
            $outOfScopeOrder->id,
            $finance->id,
            'Bad scope',
        ), 'order_id_scope');
    }

    public function test_close_checks_statement_variance_and_orphan_matched_orders(): void
    {
        $this->currency('EGP');
        $order = $this->order();
        $shipment = $this->shipment($order, ['tracking_number' => 'AWB-CLOSE']);
        $finance = $this->financeUser();

        $statement = $this->service()->createStatement($this->payload([[
            'awb' => 'AWB-CLOSE', 'expected' => '10.00', 'received' => '10.00',
        ]]));
        CodSettlementStatement::whereKey($statement->id)->update(['variance_minor' => 1]);
        $this->actingAs($finance, 'sanctum')
            ->postJson(self::PREFIX . "/{$statement->id}/close", [])
            ->assertStatus(422);

        CodSettlementStatement::whereKey($statement->id)->update(['variance_minor' => 0]);
        Shipment::whereKey($shipment->id)->delete();
        Order::withoutGlobalScopes()->whereKey($order->id)->delete();
        $this->actingAs($finance, 'sanctum')
            ->postJson(self::PREFIX . "/{$statement->id}/close", [])
            ->assertStatus(422);
        $this->assertSame('open', CodSettlementStatement::find($statement->id)->status);
    }

    public function test_close_succeeds_for_a_balanced_scoped_statement(): void
    {
        $this->currency('EGP');
        $order = $this->order();
        $this->shipment($order, ['tracking_number' => 'AWB-OK']);
        $finance = $this->financeUser();

        $statement = $this->service()->createStatement($this->payload([[
            'awb' => 'AWB-OK', 'expected' => '10.00', 'received' => '10.00',
        ]]));

        $this->actingAs($finance, 'sanctum')
            ->postJson(self::PREFIX . "/{$statement->id}/close", ['reason' => 'Balanced'])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');

        $this->assertDatabaseHas('cod_settlement_statements', [
            'id' => $statement->id,
            'status' => 'closed',
            'closed_by' => $finance->id,
        ]);
    }

    public function test_close_preserves_explicit_order_id_evidence_without_a_shipment(): void
    {
        $this->currency('EGP');
        $order = $this->order();
        $finance = $this->financeUser();

        $statement = $this->service()->createStatement($this->payload([[
            'order_id' => $order->id,
            'expected' => '10.00',
            'received' => '10.00',
        ]]));

        $this->actingAs($finance, 'sanctum')
            ->postJson(self::PREFIX . "/{$statement->id}/close", ['reason' => 'Balanced'])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');
    }

    public function test_finance_permissions_are_required_for_match_and_close(): void
    {
        $this->currency('EGP');
        $order = $this->order();
        $this->shipment($order, ['tracking_number' => 'AWB-PERM']);
        $statement = $this->service()->createStatement($this->payload([[
            'awb' => 'AWB-PERM', 'expected' => '10.00', 'received' => '10.00',
        ]]));
        $line = $statement->lines->first();

        $shipmentMutator = User::factory()->create(['type' => 'staff']);
        foreach ([Permission::WMS_SHIPMENTS_CREATE, Permission::WMS_SHIPMENTS_DELIVER] as $permission) {
            SpatiePermission::firstOrCreate(['name' => $permission, 'guard_name' => 'api']);
            $shipmentMutator->givePermissionTo($permission);
        }
        $this->actingAs($shipmentMutator, 'sanctum')
            ->postJson(self::PREFIX . "/{$statement->id}/close", ['reason' => 'x'])
            ->assertStatus(403);
        $this->actingAs($shipmentMutator, 'sanctum')
            ->postJson(self::PREFIX . "/{$statement->id}/lines/{$line->id}/match", [
                'order_id' => $order->id, 'reason' => 'x',
            ])->assertStatus(403);

        $financeOnly = $this->financeUser();
        $this->assertFalse($financeOnly->can(Permission::WMS_SHIPMENTS_MANUAL_CORRECT));
        $this->assertFalse($financeOnly->can(Permission::ORDERS_SHIPMENT_UPDATE_STATUS));
    }
}
