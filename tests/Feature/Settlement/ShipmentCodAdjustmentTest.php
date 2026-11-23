<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Enums\SettlementMode;
use App\Exceptions\CodSettlementPrecisionException;
use App\Models\Currency;
use App\Models\Settlement\ShipmentCodAdjustment;
use App\Services\Settlement\CodSettlementService;
use App\Services\Settlement\ShipmentCodAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Tests\Feature\Currency\CurrencyTestCase;

class ShipmentCodAdjustmentTest extends CurrencyTestCase
{
    use RefreshDatabase;

    private function order(array $overrides = []): Order
    {
        $customer = $this->createCustomer();

        return Order::create(array_merge([
            'user_id' => $customer->id,
            'name' => 'Settlement Customer',
            'user_email' => $customer->email,
            'user_phone' => '01000000000',
            'status' => Order::ORDER_STATUS_PENDING,
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'fulfillment_status' => 'pending',
            'price' => 100,
            'total_price' => 100,
            'currency_code' => 'USD',
            'base_currency_code' => 'USD',
            'payment_method' => 'cod',
            'address' => ['city' => 'Cairo'],
        ], $overrides));
    }

    public function test_adjustment_is_append_only_requires_reason_actor_and_different_approver(): void
    {
        $this->seedCurrencyData();
        $order = $this->order(['currency_code' => 'USD', 'total_price' => 100]);
        $actor = $this->createCustomer();
        $approver = $this->createCustomer();

        $service = app(ShipmentCodAdjustmentService::class);

        $adjustment = $service->record([
            'order_id' => $order->id,
            'currency_code' => 'USD',
            'original_collectible_minor' => 10000,
            'old_collectible_minor' => 10000,
            'new_collectible_minor' => 9000,
            'reason' => 'Carrier reconciliation',
            'actor_id' => $actor->id,
            'idempotency_key' => 'adj-1',
        ]);

        $this->assertDatabaseHas('shipment_cod_adjustments', [
            'id' => $adjustment->id,
            'order_id' => $order->id,
            'currency_code' => 'USD',
            'decimal_places' => 2,
            'status' => 'pending_approval',
            'actor_id' => $actor->id,
        ]);

        $approved = $service->approve($adjustment, $approver->id);
        $this->assertSame('approved', $approved->status);
        $this->assertSame($approver->id, $approved->approved_by);
        $this->assertNotNull($approved->approved_at);

        $this->assertSame($approved->id, $service->approve($adjustment->fresh(), $approver->id)->id);
        $this->assertSame(1, ShipmentCodAdjustment::query()->count());

        $this->expectException(\DomainException::class);
        $approved->forceFill(['reason' => 'mutated'])->save();
    }

    public function test_approval_by_same_actor_is_rejected_and_idempotency_is_preserved(): void
    {
        $this->seedCurrencyData();
        $order = $this->order(['currency_code' => 'USD', 'total_price' => 100]);
        $actor = $this->createCustomer();

        $service = app(ShipmentCodAdjustmentService::class);
        $payload = [
            'order_id' => $order->id,
            'currency_code' => 'USD',
            'original_collectible_minor' => 10000,
            'old_collectible_minor' => 10000,
            'new_collectible_minor' => 9500,
            'reason' => 'Manual correction',
            'actor_id' => $actor->id,
            'idempotency_key' => 'adj-idem-1',
        ];

        $first = $service->record($payload);
        $second = $service->record($payload);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ShipmentCodAdjustment::query()->count());

        $this->expectException(\DomainException::class);
        $service->approve($first, $actor->id);
    }

    public function test_adjustment_fails_closed_on_missing_currency_precision_and_does_not_mutate_order_or_payment_state(): void
    {
        $order = $this->order(['currency_code' => 'ZZZ', 'total_price' => 100, 'payment_status' => Order::PAYMENT_STATUS_PENDING, 'status' => Order::ORDER_STATUS_PENDING]);
        $actor = $this->createCustomer();
        $before = $order->fresh()->only(['status', 'payment_status', 'total_price', 'currency_code']);

        $service = app(ShipmentCodAdjustmentService::class);

        try {
            $service->record([
                'order_id' => $order->id,
                'currency_code' => 'ZZZ',
                'original_collectible_minor' => 10000,
                'old_collectible_minor' => 10000,
                'new_collectible_minor' => 9000,
                'reason' => 'Bad currency',
                'actor_id' => $actor->id,
                'idempotency_key' => 'adj-bad-currency',
            ]);
            $this->fail('Expected precision failure.');
        } catch (CodSettlementPrecisionException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $this->assertSame(0, ShipmentCodAdjustment::query()->count());
        $this->assertSame($before, $order->fresh()->only(array_keys($before)));
    }

    public function test_settlement_line_has_expected_received_variance_and_adjustment_records_do_not_change_order_status(): void
    {
        $this->seedCurrencyData();
        $order = $this->order(['currency_code' => 'USD', 'total_price' => 100, 'payment_status' => Order::PAYMENT_STATUS_PENDING, 'status' => Order::ORDER_STATUS_PENDING]);
        $actor = $this->createCustomer();
        $approver = User::create(['name' => 'Approver', 'email' => 'approver@example.test', 'password' => bcrypt('password')]);

        $statement = app(CodSettlementService::class)->createStatement([
            'provider_code' => 'carrier_a',
            'mode' => SettlementMode::SANDBOX->value,
            'account_code' => 'acct-1',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'statement_key' => 'stmt-adj-1',
            'lines' => [[
                'order_id' => $order->id,
                'expected' => '100.00',
                'received' => '90.00',
            ]],
        ]);

        $this->assertDatabaseHas('cod_settlement_lines', [
            'statement_id' => $statement->id,
            'order_id' => $order->id,
            'expected_minor' => 10000,
            'received_minor' => 9000,
            'variance_minor' => -1000,
        ]);

        $service = app(ShipmentCodAdjustmentService::class);
        $adjustment = $service->record([
            'statement_id' => $statement->id,
            'order_id' => $order->id,
            'currency_code' => 'USD',
            'original_collectible_minor' => 10000,
            'old_collectible_minor' => 10000,
            'new_collectible_minor' => 9000,
            'reason' => 'Carrier short paid',
            'actor_id' => $actor->id,
            'idempotency_key' => 'adj-stmt-1',
        ]);
        $service->approve($adjustment, $approver->id);

        $fresh = $order->fresh();
        $this->assertSame(Order::PAYMENT_STATUS_PENDING, $fresh->payment_status);
        $this->assertSame(Order::ORDER_STATUS_PENDING, $fresh->status);
    }

    public function test_adjustment_rejects_statement_not_owned_by_order_currency_or_context(): void
    {
        $this->seedCurrencyData();
        $order = $this->order(['currency_code' => 'USD', 'total_price' => 100]);
        $otherOrder = $this->order(['currency_code' => 'USD', 'total_price' => 50]);
        $actor = $this->createCustomer();

        $statement = app(CodSettlementService::class)->createStatement([
            'provider_code' => 'carrier_a',
            'mode' => SettlementMode::SANDBOX->value,
            'account_code' => 'acct-1',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'statement_key' => 'stmt-other-order',
            'lines' => [[
                'order_id' => $otherOrder->id,
                'expected' => '50.00',
                'received' => '50.00',
            ]],
        ]);

        $service = app(ShipmentCodAdjustmentService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid statement_id.');
        $service->record([
            'statement_id' => $statement->id,
            'order_id' => $order->id,
            'currency_code' => 'USD',
            'original_collectible_minor' => 10000,
            'old_collectible_minor' => 10000,
            'new_collectible_minor' => 9000,
            'reason' => 'Wrong statement',
            'actor_id' => $actor->id,
            'idempotency_key' => 'adj-wrong-statement',
        ]);
    }
}
