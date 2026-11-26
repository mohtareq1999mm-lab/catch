<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Enums\CodStatementAcceptanceStatus;
use App\Enums\SettlementMode;
use App\Models\Currency;
use App\Models\Settlement\CodSettlementStatement;
use App\Services\Settlement\CodSettlementService;
use App\Support\FinanceAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Role;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * #182: settlement acceptance is explicit finance evidence exposed through a
 * finance-only HTTP surface. Acceptance only changes statement evidence (and
 * its audit trail); it never creates an adjustment or mutates order, payment,
 * shipment or inventory state.
 */
class SettlementAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = '/api/v1/admin/cod-settlements/statements';

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (FinanceAccess::permissions() as $name) {
            SpatiePermission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }
    }

    public function test_new_statement_is_pending_and_only_finance_can_explicitly_accept_it(): void
    {
        $this->currency();
        $order = $this->createOrder();
        $statement = $this->createStatement($order);
        $this->assertSame(CodStatementAcceptanceStatus::PENDING->value, $statement->acceptance_status);
        $this->assertNull($statement->reviewed_at);
        $this->assertNull($statement->reviewed_by);

        $before = $this->snapshot([
            'orders', 'shipments', 'payment_intents', 'payment_gateways',
            'cod_settlement_lines', 'shipment_cod_adjustments',
        ]);

        $this->postJson(self::PREFIX.'/'.$statement->id.'/accept', [])->assertStatus(401);

        $outsider = $this->user('staff');
        $this->actingAs($outsider, 'sanctum')
            ->postJson(self::PREFIX.'/'.$statement->id.'/accept', [])
            ->assertStatus(403);

        $finance = $this->financeUser();
        $response = $this->actingAs($finance, 'sanctum')
            ->postJson(self::PREFIX.'/'.$statement->id.'/accept', ['reason' => 'finance verified 182'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', __('cod_settlement.accepted'))
            ->assertJsonPath('data.acceptance_status', CodStatementAcceptanceStatus::ACCEPTED->value)
            ->assertJsonPath('data.reviewed_by', $finance->id);
        $this->assertNotNull($response->json('data.reviewed_at'));

        $this->assertSame($before, $this->snapshot([
            'orders', 'shipments', 'payment_intents', 'payment_gateways',
            'cod_settlement_lines', 'shipment_cod_adjustments',
        ]));
        $this->assertDatabaseHas('cod_settlement_statements', [
            'id' => $statement->id,
            'acceptance_status' => CodStatementAcceptanceStatus::ACCEPTED->value,
            'reviewed_by' => $finance->id,
            'review_reason' => 'finance verified 182',
        ]);
    }

    public function test_rejection_is_explicit_and_preserves_the_prior_accepted_statement(): void
    {
        $this->currency();
        $order = $this->createOrder();
        $original = $this->createStatement($order, ['statement_key' => 'stmt_original_182']);
        $correction = $this->createStatement($order, [
            'statement_key' => 'stmt_correction_182',
            'statement_type' => 'corrected',
            'supersedes_statement_id' => $original->id,
        ]);
        $actor = $this->financeUser();
        $service = app(CodSettlementService::class);

        $service->acceptStatement($original->id, $actor->id, 'finance accepted original');

        $this->actingAs($actor, 'sanctum')
            ->postJson(self::PREFIX.'/'.$correction->id.'/reject', ['reason' => 'correction rejected 182'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', __('cod_settlement.rejected'))
            ->assertJsonPath('data.acceptance_status', CodStatementAcceptanceStatus::REJECTED->value);

        $this->assertSame(
            CodStatementAcceptanceStatus::ACCEPTED->value,
            $original->fresh()->acceptance_status,
            'Rejecting a correction must not mutate the prior accepted statement.'
        );
        $this->assertSame(
            CodStatementAcceptanceStatus::REJECTED->value,
            $correction->fresh()->acceptance_status,
        );
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

    /** @param list<string> $tables */
    private function snapshot(array $tables): array
    {
        $snapshot = [];
        foreach ($tables as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        return $snapshot;
    }

    private function financeUser(): User
    {
        $financeRole = Role::firstOrCreate(
            ['name' => FinanceAccess::ROLE, 'guard_name' => 'api'],
            ['display_name' => ['en' => 'Finance', 'ar' => 'مالية']],
        );
        $financeRole->syncPermissions(FinanceAccess::permissions());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = $this->user('staff');
        $user->assignRole(FinanceAccess::ROLE);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function user(string $type): User
    {
        return User::factory()->create(['type' => $type]);
    }

    private function createOrder(): Order
    {
        $customer = $this->user('customer');

        return Order::create([
            'order_number' => 'ORD-ACCEPT-182-'.Str::upper(Str::random(6)),
            'user_id' => $customer->id,
            'name' => 'Acceptance Test Customer',
            'user_email' => $customer->email,
            'user_phone' => '01000000000',
            'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'fulfillment_status' => 'pending',
            'price' => 1000,
            'total_price' => 1000,
            'currency_code' => 'EGP',
            'base_currency_code' => 'EGP',
            'payment_method' => 'cod',
            'address' => ['city' => 'Cairo'],
        ]);
    }

    /** @param array<string,mixed> $overrides */
    private function createStatement(Order $order, array $overrides = []): CodSettlementStatement
    {
        return app(CodSettlementService::class)->createStatement(array_merge([
            'provider_code' => 'carrier_a',
            'mode' => SettlementMode::SANDBOX->value,
            'account_code' => 'account-01',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'statement_key' => 'stmt-'.Str::random(12),
            'lines' => [[
                'order_id' => $order->id,
                'expected' => '100.00',
                'received' => '100.00',
            ]],
        ], $overrides));
    }
}
