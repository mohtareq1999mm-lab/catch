<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Enums\SettlementMode;
use App\Events\OrderStatusChanged;
use App\Events\PaymentSucceeded;
use App\Exceptions\CodSettlementPrecisionException;
use App\Models\Currency;
use App\Models\Settlement\CodSettlementStatement;
use App\Services\Settlement\CodSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Tests\TestCase;

/**
 * #135: COD settlement is financial/shipping evidence only.
 *
 * Every assertion here protects the same boundary: evidence rows may be
 * written and read, but no payment, order, shipment, fulfillment, inventory,
 * lifecycle event, or queued job may be touched as a consequence.
 */
class CodSettlementTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = '/api/v1/admin/cod-settlements/statements';

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([Permission::COD_SETTLEMENT_VIEW] as $name) {
            SpatiePermission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }
    }

    private function currency(string $code, int $decimalPlaces = 2): Currency
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

    private function order(string $currencyCode = 'EGP'): Order
    {
        $customer = User::factory()->create(['type' => 'customer']);

        return Order::create([
            'user_id' => $customer->id,
            'name' => 'Settlement Customer',
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

    private function statementPayload(Order $order, array $overrides = []): array
    {
        return array_merge([
            'provider_code' => 'carrier_a',
            'mode' => SettlementMode::SANDBOX->value,
            'account_code' => 'account-01',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'statement_key' => 'stmt-' . uniqid(),
            'lines' => [
                [
                    'order_id' => $order->id,
                    'expected' => '100.50',
                    'received' => '100.00',
                ],
            ],
        ], $overrides);
    }

    private function userWithPermission(bool $withPermission): User
    {
        $user = User::factory()->create(['type' => 'staff']);

        if ($withPermission) {
            $user->givePermissionTo(Permission::COD_SETTLEMENT_VIEW);
        }

        return $user;
    }

    /**
     * Snapshot every table the evidence path is forbidden to mutate.
     */
    private function lifecycleSnapshot(): array
    {
        $snapshot = [];

        foreach (['orders', 'shipments', 'fulfillments', 'product_locations', 'inventory_operations'] as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $snapshot;
    }

    public function test_statement_can_be_created_and_read_without_mutating_lifecycle_state(): void
    {
        $this->currency('EGP');
        $order = $this->order();
        $before = $this->lifecycleSnapshot();

        Event::fake([OrderStatusChanged::class, PaymentSucceeded::class]);
        Bus::fake();
        Queue::fake();

        $statement = app(CodSettlementService::class)->createStatement($this->statementPayload($order));

        Event::assertNotDispatched(OrderStatusChanged::class);
        Event::assertNotDispatched(PaymentSucceeded::class);
        Bus::assertNothingDispatched();
        Queue::assertNothingPushed();
        $this->assertSame($before, $this->lifecycleSnapshot());

        $this->assertDatabaseHas('cod_settlement_statements', [
            'id' => $statement->id,
            'currency_code' => 'EGP',
            'decimal_places' => 2,
            'expected_minor' => 10050,
            'received_minor' => 10000,
            'variance_minor' => -50,
        ]);
        $this->assertDatabaseHas('cod_settlement_lines', [
            'statement_id' => $statement->id,
            'order_id' => $order->id,
            'currency_code' => 'EGP',
            'decimal_places' => 2,
            'expected_minor' => 10050,
            'received_minor' => 10000,
            'variance_minor' => -50,
        ]);

        $actor = $this->userWithPermission(true);
        $index = $this->actingAs($actor, 'sanctum')->getJson(self::PREFIX);
        $index->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', __('cod_settlement.fetched_many'))
            ->assertJsonPath('data.0.id', $statement->id);
        $this->assertSame($before, $this->lifecycleSnapshot());

        $show = $this->getJson(self::PREFIX . '/' . $statement->id);
        $show->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', __('cod_settlement.fetched_one'))
            ->assertJsonPath('data.id', $statement->id)
            ->assertJsonPath('data.lines.0.order_id', $order->id)
            ->assertJsonPath('data.currency_code', 'EGP');
    }

    public function test_read_endpoints_are_finance_permission_gated_and_have_no_write_path(): void
    {
        $this->currency('EGP');
        $order = $this->order();
        $statement = app(CodSettlementService::class)->createStatement($this->statementPayload($order));

        $this->getJson(self::PREFIX)->assertStatus(401);
        $this->getJson(self::PREFIX . '/' . $statement->id)->assertStatus(401);

        $outsider = $this->userWithPermission(false);
        $this->actingAs($outsider, 'sanctum')->getJson(self::PREFIX)->assertStatus(403);
        $this->getJson(self::PREFIX . '/' . $statement->id)->assertStatus(403);
        $this->postJson(self::PREFIX, [])->assertStatus(405);
        $this->postJson(self::PREFIX . '/' . $statement->id . '/mark-paid', [])->assertStatus(404);

        $finance = $this->userWithPermission(true);
        $this->actingAs($finance, 'sanctum')->getJson(self::PREFIX)->assertOk();
        $this->getJson(self::PREFIX . '/' . $statement->id)->assertOk();
    }

    public function test_records_carry_provider_mode_account_period_and_currency_identity(): void
    {
        $this->currency('EGP');
        $usd = $this->currency('USD');
        $order = $this->order($usd->code);

        $payload = $this->statementPayload($order, [
            'provider_code' => 'carrier_b',
            'mode' => SettlementMode::PRODUCTION->value,
            'account_code' => 'merchant-77',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
        ]);

        $statement = app(CodSettlementService::class)->createStatement($payload);

        $this->assertDatabaseHas('cod_settlement_statements', [
            'id' => $statement->id,
            'provider_code' => 'carrier_b',
            'mode' => SettlementMode::PRODUCTION->value,
            'account_code' => 'merchant-77',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'currency_code' => 'USD',
        ]);
        $this->assertDatabaseHas('cod_settlement_lines', [
            'statement_id' => $statement->id,
            'provider_code' => 'carrier_b',
            'mode' => SettlementMode::PRODUCTION->value,
            'account_code' => 'merchant-77',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'currency_code' => 'USD',
        ]);

        $finance = $this->userWithPermission(true);
        $this->actingAs($finance, 'sanctum')->getJson(self::PREFIX . '?' . http_build_query([
            'provider_code' => 'carrier_b',
            'mode' => SettlementMode::PRODUCTION->value,
            'account_code' => 'merchant-77',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'currency_code' => 'USD',
        ]))->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $statement->id);

        $this->getJson(self::PREFIX . '?currency_code=usd')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $statement->id);

        $this->getJson(self::PREFIX . '?provider_code=carrier_a')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_precision_fail_closed_when_currency_metadata_is_missing_or_insufficient(): void
    {
        $unknownCurrencyOrder = $this->order('ZZZ');

        try {
            app(CodSettlementService::class)->createStatement($this->statementPayload($unknownCurrencyOrder));
            $this->fail('Missing currency precision must fail closed.');
        } catch (CodSettlementPrecisionException $e) {
            $this->assertSame(__('cod_settlement.precision_unavailable'), $e->getMessage());
        }

        $this->assertSame(0, CodSettlementStatement::query()->count());
        $this->assertSame(0, DB::table('cod_settlement_lines')->count());

        $kwd = $this->currency('KWD', 3);
        $kwdOrder = $this->order($kwd->code);

        try {
            app(CodSettlementService::class)->createStatement($this->statementPayload($kwdOrder, [
                'lines' => [[
                    'order_id' => $kwdOrder->id,
                    'expected' => '1.2345',
                    'received' => '1.000',
                ]],
            ]));
            $this->fail('Amounts with more fraction digits than the currency supports must fail closed.');
        } catch (CodSettlementPrecisionException $e) {
            $this->assertSame(__('cod_settlement.precision_unavailable'), $e->getMessage());
        }

        $this->assertSame(0, CodSettlementStatement::query()->count());
        $this->assertSame(0, DB::table('cod_settlement_lines')->count());

        $statement = app(CodSettlementService::class)->createStatement($this->statementPayload($kwdOrder, [
            'lines' => [[
                'order_id' => $kwdOrder->id,
                'expected' => '1.235',
                'received' => '1.000',
            ]],
        ]));

        $this->assertDatabaseHas('cod_settlement_lines', [
            'statement_id' => $statement->id,
            'currency_code' => 'KWD',
            'decimal_places' => 3,
            'expected_minor' => 1235,
            'received_minor' => 1000,
            'variance_minor' => -235,
        ]);
    }

    public function test_creating_evidence_never_marks_order_paid_or_converts_currency(): void
    {
        $this->currency('EGP');
        $usd = $this->currency('USD');
        $order = $this->order($usd->code);

        // A stored rate exists, but the settlement must never consult it.
        DB::table('currency_rates')->insert([
            'currency_id' => $usd->id,
            'exchange_rate' => '48.5000000000',
            'effective_date' => now()->toDateString(),
        ]);

        $beforeOrder = $order->fresh()->only([
            'status', 'payment_status', 'fulfillment_status', 'currency_code', 'base_currency_code',
        ]);

        $statement = app(CodSettlementService::class)->createStatement($this->statementPayload($order));

        $this->assertSame('USD', $statement->currency_code);
        $this->assertSame($beforeOrder, $order->fresh()->only(array_keys($beforeOrder)));
        $this->assertSame(Order::PAYMENT_STATUS_PENDING, $order->fresh()->payment_status);
        $this->assertDatabaseCount('currency_rates', 1);
    }

    public function test_invalid_statement_identity_and_amounts_are_rejected(): void
    {
        $this->currency('EGP');
        $order = $this->order();
        $service = app(CodSettlementService::class);

        foreach ([
            $this->statementPayload($order, ['provider_code' => '']),
            $this->statementPayload($order, ['mode' => 'live']),
            $this->statementPayload($order, ['account_code' => '']),
            $this->statementPayload($order, ['period_start' => '2026-11-30', 'period_end' => '2026-11-01']),
            $this->statementPayload($order, ['lines' => [[
                'order_id' => $order->id,
                'expected' => 'not-money',
                'received' => '1.00',
            ]]]),
        ] as $invalidPayload) {
            try {
                $service->createStatement($invalidPayload);
                $this->fail('Invalid settlement payload must be rejected.');
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }

        $this->assertSame(0, CodSettlementStatement::query()->count());
        $this->assertSame(0, DB::table('cod_settlement_lines')->count());
    }
}
