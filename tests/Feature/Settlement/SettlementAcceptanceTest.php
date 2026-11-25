<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Enums\Settlement\SettlementAcceptanceStatus;
use App\Enums\Settlement\SettlementStatementRole;
use App\Enums\SettlementMode;
use App\Exceptions\SettlementClosureBlockedException;
use App\Models\Currency;
use App\Models\Settlement\CodSettlementStatement;
use App\Services\Settlement\CodSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Tests\TestCase;

/**
 * #182: settlement acceptance is explicit finance evidence with a deterministic
 * effective set. Ambiguity blocks closure; acceptance never creates an
 * adjustment or mutates order/payment/shipment/financial state.
 */
class SettlementAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = '/api/v1/admin/cod-settlements/statements';

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([Permission::COD_SETTLEMENT_VIEW, Permission::COD_SETTLEMENT_ACCEPT] as $name) {
            SpatiePermission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }
    }

    public function test_new_statement_is_pending_and_only_finance_can_explicitly_accept_it(): void
    {
        $order = $this->createOrder();
        $statement = $this->createStatement($order);
        $this->assertSame(SettlementAcceptanceStatus::PENDING, $statement->acceptance_status);
        $this->assertNull($statement->accepted_at);
        $this->assertNull($statement->accepted_by);

        $before = $this->snapshot([
            'orders', 'shipments', 'payment_intents', 'payment_gateways',
            'cod_settlement_lines', 'shipment_cod_adjustments',
        ]);

        $this->postJson(self::PREFIX.'/'.$statement->id.'/accept', [])->assertStatus(401);
        $outsider = $this->financeUser(false);
        $this->actingAs($outsider, 'sanctum')
            ->postJson(self::PREFIX.'/'.$statement->id.'/accept', [])
            ->assertStatus(403);

        $finance = $this->financeUser(true);
        $response = $this->actingAs($finance, 'sanctum')
            ->postJson(self::PREFIX.'/'.$statement->id.'/accept', [])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', __('cod_settlement.accepted'))
            ->assertJsonPath('data.acceptance_status', SettlementAcceptanceStatus::ACCEPTED->value)
            ->assertJsonPath('data.accepted_by', $finance->id)
            ->assertJsonPath('data.statement_role', SettlementStatementRole::CORRECTION->value);
        $this->assertNotNull($response->json('data.accepted_at'));

        $this->assertSame($before, $this->snapshot([
            'orders', 'shipments', 'payment_intents', 'payment_gateways',
            'cod_settlement_lines', 'shipment_cod_adjustments',
        ]));
        $this->assertDatabaseHas('cod_settlement_statements', [
            'id' => $statement->id,
            'acceptance_status' => SettlementAcceptanceStatus::ACCEPTED->value,
            'accepted_by' => $finance->id,
            'supersedes_statement_id' => null,
        ]);
    }

    public function test_accepted_correction_supersedes_prior_statement_and_rejected_correction_preserves_it(): void
    {
        $order = $this->createOrder();
        $original = $this->createStatement($order, ['statement_key' => 'stmt_original_182']);
        $correction = $this->createStatement($order, ['statement_key' => 'stmt_correction_182']);
        $rejected = $this->createStatement($order, ['statement_key' => 'stmt_rejected_182']);
        $actor = $this->financeUser(true);
        $service = app(CodSettlementService::class);
        $scope = $service->scopeOf($original);

        $service->acceptStatement($original->id, $actor->id);
        $service->acceptStatement($correction->id, $actor->id, $original->id);
        $service->rejectStatement($rejected->id, $actor->id);

        $this->assertSame(
            SettlementAcceptanceStatus::SUPERSEDED,
            $original->fresh()->acceptance_status,
        );
        $this->assertSame(
            SettlementAcceptanceStatus::ACCEPTED,
            $correction->fresh()->acceptance_status,
        );
        $this->assertSame(
            SettlementAcceptanceStatus::REJECTED,
            $rejected->fresh()->acceptance_status,
        );
        $this->assertSame([$correction->id], $service->effectiveSet($scope)->pluck('id')->all());
        $service->assertClosureAllowed($scope);
    }

    public function test_cross_scope_and_invalid_supersession_fail_closed(): void
    {
        $order = $this->createOrder();
        $original = $this->createStatement($order, ['statement_key' => 'stmt_scope_a_182']);
        $otherScope = $this->createStatement($order, [
            'statement_key' => 'stmt_scope_b_182',
            'account_code' => 'other-account',
        ]);
        $pending = $this->createStatement($order, ['statement_key' => 'stmt_scope_c_182']);
        $pendingTarget = $this->createStatement($order, ['statement_key' => 'stmt_scope_d_182']);
        $actor = $this->financeUser(true);
        $service = app(CodSettlementService::class);

        $service->acceptStatement($original->id, $actor->id);

        foreach ([
            [$otherScope, $original],
            [$pending, $pendingTarget],
            [$otherScope, $pendingTarget],
        ] as [$candidate, $target]) {
            try {
                $service->acceptStatement($candidate->id, $actor->id, $target->id);
                $this->fail('Invalid supersession must be rejected.');
            } catch (InvalidArgumentException) {
                $this->assertSame(SettlementAcceptanceStatus::PENDING, $candidate->fresh()->acceptance_status);
            }
        }

        $this->assertSame(SettlementAcceptanceStatus::ACCEPTED, $original->fresh()->acceptance_status);
        $this->assertSame([$original->id], $service->effectiveSet($service->scopeOf($original))->pluck('id')->all());
    }

    public function test_ambiguous_effective_set_blocks_closure_until_finance_resolves_it(): void
    {
        DB::table('activity_log')->delete();
        $order = $this->createOrder();
        $first = $this->createStatement($order, ['statement_key' => 'stmt_ambiguous_a_182']);
        $second = $this->createStatement($order, ['statement_key' => 'stmt_ambiguous_b_182']);
        $actor = $this->financeUser(true);
        $service = app(CodSettlementService::class);
        $scope = $service->scopeOf($first);
        $before = $this->snapshot(['orders', 'cod_settlement_lines', 'shipment_cod_adjustments']);

        $service->acceptStatement($first->id, $actor->id);
        $service->acceptStatement($second->id, $actor->id);

        $this->assertCount(2, $service->effectiveSet($scope));

        try {
            $service->assertClosureAllowed($scope);
            $this->fail('Ambiguous accepted set must block closure.');
        } catch (SettlementClosureBlockedException $exception) {
            $this->assertSame(__('cod_settlement.closure_ambiguous', [
                'ids' => $first->id.', '.$second->id,
            ]), $exception->getMessage());
        }

        $this->assertTrue(Activity::query()->where('log_name', 'settlement')
            ->where('event', 'settlement_closure_blocked')->exists());
        $this->assertSame($before, $this->snapshot(['orders', 'cod_settlement_lines', 'shipment_cod_adjustments']));
        $this->assertSame(SettlementAcceptanceStatus::ACCEPTED, $first->fresh()->acceptance_status);
        $this->assertSame(SettlementAcceptanceStatus::ACCEPTED, $second->fresh()->acceptance_status);

        // Explicit finance resolution removes the ambiguity.
        $service->rejectStatement($second->id, $actor->id);
        $service->assertClosureAllowed($scope);
        $this->assertSame([$first->id], $service->effectiveSet($scope)->pluck('id')->all());
    }

    public function test_supplementary_statement_does_not_replace_or_ambiguate_the_accepted_version(): void
    {
        $order = $this->createOrder();
        $original = $this->createStatement($order, ['statement_key' => 'stmt_evidence_original_182']);
        $supplementary = $this->createStatement($order, [
            'statement_key' => 'stmt_evidence_extra_182',
            'statement_role' => SettlementStatementRole::SUPPLEMENTARY->value,
        ]);
        $this->assertSame(
            SettlementStatementRole::SUPPLEMENTARY->value,
            $supplementary->metadata['statement_role'] ?? null,
        );
        $actor = $this->financeUser(true);
        $service = app(CodSettlementService::class);
        $scope = $service->scopeOf($original);

        $service->acceptStatement($original->id, $actor->id);
        $service->acceptStatement($supplementary->id, $actor->id);

        $this->assertSame([$original->id], $service->effectiveSet($scope)->pluck('id')->all());
        $service->assertClosureAllowed($scope);
        $this->assertSame(SettlementAcceptanceStatus::ACCEPTED, $original->fresh()->acceptance_status);
        $this->assertSame(SettlementAcceptanceStatus::ACCEPTED, $supplementary->fresh()->acceptance_status);
    }

    public function test_no_accepted_statement_blocks_closure_without_mutating_anything(): void
    {
        $order = $this->createOrder();
        $statement = $this->createStatement($order);
        $service = app(CodSettlementService::class);

        try {
            $service->assertClosureAllowed($service->scopeOf($statement));
            $this->fail('A scope without acceptance must block closure.');
        } catch (SettlementClosureBlockedException $exception) {
            $this->assertSame(__('cod_settlement.closure_no_accepted'), $exception->getMessage());
        }

        $this->assertSame(SettlementAcceptanceStatus::PENDING, $statement->fresh()->acceptance_status);
    }

    private function service(): CodSettlementService
    {
        return app(CodSettlementService::class);
    }

    private function createOrder(): Order
    {
        Currency::firstOrCreate(
            ['code' => 'EGP'],
            [
                'name' => ['en' => 'EGP', 'ar' => 'EGP'],
                'symbol' => ['en' => 'EGP', 'ar' => 'EGP'],
                'country_name' => ['en' => 'Test', 'ar' => 'اختبار'],
                'numeric_code' => '840',
                'decimal_places' => 2,
                'is_active' => true,
                'sort_order' => 0,
            ],
        );

        $customer = User::factory()->create(['type' => 'customer']);

        return Order::create([
            'user_id' => $customer->id,
            'name' => 'Acceptance Customer',
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
        return $this->service()->createStatement(array_merge([
            'statement_key' => 'stmt_'.uniqid('', true),
            'provider_code' => 'carrier_a',
            'mode' => SettlementMode::SANDBOX->value,
            'account_code' => 'account-01',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'lines' => [[
                'order_id' => $order->id,
                'expected' => '100.50',
                'received' => '100.00',
            ]],
        ], $overrides));
    }

    private function financeUser(bool $withPermission): User
    {
        $user = User::factory()->create(['type' => 'staff']);
        if ($withPermission) {
            $user->givePermissionTo(Permission::COD_SETTLEMENT_VIEW);
            $user->givePermissionTo(Permission::COD_SETTLEMENT_ACCEPT);
        }

        return $user;
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
}
