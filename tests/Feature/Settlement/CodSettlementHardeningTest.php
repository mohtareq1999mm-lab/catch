<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Enums\SettlementMode;
use App\Models\Currency;
use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Models\Settlement\CodSettlementMatchAudit;
use App\Models\Shipment;
use App\Models\Shipping\ShippingProviderEvent;
use App\Services\Fulfillment\FulfillmentService;
use App\Services\Fulfillment\FulfillmentTransition;
use App\Services\Settlement\CodSettlementService;
use App\Services\Shipment\CarrierGatewayFactory;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Role;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission;
use Tests\TestCase;

/**
 * #156 review hardening: role separation and durable financial evidence.
 */
class CodSettlementHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const COD_PERMISSIONS = [
        Permission::COD_SETTLEMENT_VIEW,
        Permission::COD_SETTLEMENT_MATCH,
        Permission::COD_SETTLEMENT_CLOSE,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function currency(): Currency
    {
        return Currency::firstOrCreate(['code' => 'EGP'], [
            'name' => ['en' => 'EGP', 'ar' => 'EGP'],
            'symbol' => ['en' => 'EGP', 'ar' => 'EGP'],
            'country_name' => ['en' => 'Test', 'ar' => 'Test'],
            'numeric_code' => '818', 'decimal_places' => 2, 'is_active' => true, 'sort_order' => 0,
        ]);
    }

    private function order(): Order
    {
        $customer = User::factory()->create(['type' => 'customer']);

        return Order::create([
            'user_id' => $customer->id,
            'name' => 'C', 'user_email' => $customer->email, 'user_phone' => '01000000000',
            'status' => 'pending', 'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'fulfillment_status' => 'pending', 'price' => 100, 'total_price' => 100,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'payment_method' => 'cod', 'address' => ['city' => 'Cairo'],
            'order_number' => 'ORD-' . strtoupper(uniqid()),
        ]);
    }

    private function statementPayload(): array
    {
        return [
            'provider_code' => 'carrier_a',
            'mode' => SettlementMode::SANDBOX->value,
            'account_code' => 'acct',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'statement_key' => 'stmt-' . uniqid(),
            'currency_code' => 'EGP',
            'lines' => [[
                'awb' => 'AWB-EVIDENCE',
                'expected' => '10.00',
                'received' => '10.00',
            ]],
        ];
    }

    public function test_seeded_finance_role_is_the_only_holder_of_cod_permissions(): void
    {
        $this->seed(PermissionSeeder::class);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $finance = Role::findByName('finance', 'api');
        $financePermissions = $finance->permissions->pluck('name')->sort()->values()->all();
        $expected = collect(self::COD_PERMISSIONS)->sort()->values()->all();
        $this->assertSame($expected, $financePermissions);

        $superAdmin = Role::findByName('super_admin', 'api');
        foreach (self::COD_PERMISSIONS as $permission) {
            $this->assertFalse($superAdmin->hasPermissionTo($permission, 'api'));
        }

        foreach (User::where('type', 'admin')->get() as $admin) {
            foreach (self::COD_PERMISSIONS as $permission) {
                $this->assertFalse($admin->hasPermissionTo($permission, 'api'));
            }
        }
    }

    public function test_audit_evidence_survives_actor_deletion_and_blocks_evidence_deletion(): void
    {
        $this->currency();
        $order = $this->order();
        Shipment::create([
            'order_id' => $order->id,
            'status' => 'pending',
            'currency' => 'EGP',
            'provider_code' => 'carrier_a',
            'provider_mode' => 'sandbox',
            'tracking_number' => 'AWB-EVIDENCE',
            'created_at' => '2026-11-15 12:00:00',
            'updated_at' => '2026-11-15 12:00:00',
        ]);

        $statement = app(CodSettlementService::class)->createStatement($this->statementPayload());
        $line = $statement->lines->first();
        $actor = User::factory()->create(['type' => 'staff', 'name' => 'Finance Auditor']);
        app(CodSettlementService::class)->manualMatch(
            $statement->id,
            $line->id,
            $order->id,
            $actor->id,
            'Verified carrier statement',
        );

        $audit = CodSettlementMatchAudit::query()->firstOrFail();
        $this->assertSame('Finance Auditor', $audit->actor_name);

        // User is soft-deletable; the FK contract is about a real row
        // deletion, so force it to prove nullOnDelete keeps the evidence.
        User::withoutGlobalScopes()->whereKey($actor->id)->forceDelete();
        $audit->refresh();
        $this->assertNull($audit->actor_id);
        $this->assertSame('Finance Auditor', $audit->actor_name);

        try {
            $statement->delete();
            $this->fail('A statement with audit evidence must not be deletable.');
        } catch (QueryException) {
            // Restrict is intentional: financial evidence outlives its header.
        }

        try {
            $line->delete();
            $this->fail('A line with audit evidence must not be deletable.');
        } catch (QueryException) {
            // Restrict is intentional: financial evidence outlives its line.
        }

        $this->assertDatabaseHas('cod_settlement_match_audits', ['id' => $audit->id]);
    }

    public function test_nullable_order_id_rollback_is_safe_and_matched_nulls_are_rejected(): void
    {
        $currency = $this->currency();
        $order = $this->order();
        Shipment::create([
            'order_id' => $order->id,
            'status' => 'pending',
            'currency' => 'EGP',
            'provider_code' => 'carrier_a',
            'provider_mode' => 'sandbox',
            'tracking_number' => 'AWB-EVIDENCE',
            'created_at' => '2026-11-15 12:00:00',
            'updated_at' => '2026-11-15 12:00:00',
        ]);

        $service = app(CodSettlementService::class);
        $unmatched = $service->createStatement(array_merge($this->statementPayload(), [
            'lines' => [[
                'awb' => 'UNKNOWN-AWB', 'expected' => '10.00', 'received' => '10.00',
            ]],
        ]));

        // #162 scope uniqueness means a second statement must use another
        // account. Add matching provider evidence for that account.
        $webhookId = DB::table('shipping_webhook_events')->insertGetId([
            'provider_code' => 'carrier_a',
            'mode' => 'sandbox',
            'account_code' => 'acct-matched',
            'webhook_version' => 'v1',
            'event_key' => 'evt-evidence-'.uniqid(),
            'redacted_payload' => '{}',
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        ShippingProviderEvent::create([
            'webhook_event_id' => $webhookId,
            'shipment_id' => Shipment::where('tracking_number', 'AWB-EVIDENCE')->value('id'),
            'provider_code' => 'carrier_a',
            'mode' => 'sandbox',
            'account_code' => 'acct-matched',
            'provider_event_key' => 'key-evidence-'.uniqid(),
            'raw_status' => 'in_transit',
            'payload_reference' => 'ref-evidence-'.uniqid(),
            'payload_hash' => str_repeat('a', 64),
            'applied_at' => now(),
        ]);

        $matched = $service->createStatement(array_merge($this->statementPayload(), ['account_code' => 'acct-matched']));
        $this->assertNull($unmatched->lines->first()->order_id);
        $this->assertNotNull($matched->lines->first()->order_id);

        $migration = require database_path('migrations/2026_11_24_000003_make_cod_settlement_line_order_nullable.php');
        try {
            $migration->down();

            $nullable = DB::select(
                'SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() '
                . 'AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['cod_settlement_lines', 'order_id'],
            );
            $this->assertSame('YES', $nullable[0]->IS_NULLABLE);

            try {
                DB::table('cod_settlement_lines')->whereKey($matched->lines->first()->id)->update(['order_id' => null]);
                $this->fail('A matched line cannot lose its order identity.');
            } catch (QueryException) {
                // CHECK (order_id IS NOT NULL OR match_status = 'unmatched').
            }
        } finally {
            // The migration's DDL commits MySQL's test transaction, so remove
            // this scenario's rows explicitly before the next test starts.
            DB::table('cod_settlement_statements')->whereIn('id', [$unmatched->id, $matched->id])->delete();
            DB::table('shipments')->where('tracking_number', 'AWB-EVIDENCE')->delete();
            DB::table('orders')->where('id', $order->id)->delete();
            DB::table('users')->where('id', $order->user_id)->delete();
            DB::table('currencies')->where('id', $currency->id)->delete();
        }
    }

    public function test_carrier_gateway_persists_provider_shipment_id_through_canonical_creation(): void
    {
        config(['broadcasting.connections.pusher.driver' => 'log']);
        $warehouse = Warehouse::create([
            'code' => 'WH-156', 'name' => 'Settlement', 'status' => 'active', 'is_default' => true,
        ]);
        $location = Location::create([
            'warehouse_id' => $warehouse->id, 'code' => 'WH-156-01',
            'barcode' => 'LOC-156-01', 'name' => 'Bin', 'type' => 'picking',
            'status' => 'active', 'priority' => 1,
        ]);
        $product = Product::create([
            'name' => 'Settlement product', 'slug' => 'p-156-' . uniqid(), 'sku' => 'SKU-156-' . uniqid(),
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 10, 'reserved_quantity' => 0,
        ]);
        ProductLocation::create([
            'product_id' => $product->id, 'location_id' => $location->id,
            'warehouse_id' => $warehouse->id, 'quantity' => 10, 'allocated_hint' => 0,
        ]);

        $customer = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $customer->id, 'name' => 'O', 'user_email' => $customer->email,
            'user_phone' => '010', 'status' => 'completed',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'fulfillment_status' => 'pending',
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 100, 'total_price' => 100,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery', 'payment_method' => 'cod',
            'address' => ['city' => 'Cairo'],
        ]);
        $order->orderItems()->create([
            'product_id' => $product->id, 'product_name' => $product->name,
            'product_sku' => $product->sku, 'product_quantity' => 1,
            'product_price' => 100, 'product_total_price' => 100,
        ]);

        /** @var Fulfillment $fulfillment */
        $fulfillment = app(FulfillmentService::class)
            ->releaseForOrder($order->refresh(), $warehouse->id, 'fulfillment-156');
        $transitions = app(FulfillmentTransition::class);
        foreach (['picking', 'picked', 'packing', 'ready_to_ship'] as $state) {
            $transitions->transition($fulfillment, $state);
        }

        $gateway = app(CarrierGatewayFactory::class)->make('internal');
        $result = $gateway->createShipment([
            'fulfillment_id' => $fulfillment->id,
            'provider_shipment_id' => 'PS-GATEWAY',
            'idempotency_key' => 'gateway-156',
        ]);
        $shipment = Shipment::findOrFail((int) $result->externalIds['shipment_id']);

        $this->assertSame('PS-GATEWAY', $shipment->provider_shipment_id);
        $this->assertSame('internal', $shipment->provider_code);
        $this->assertSame('internal', $shipment->provider_mode);
    }
}
