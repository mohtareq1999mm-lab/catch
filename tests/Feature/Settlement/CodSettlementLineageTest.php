<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Enums\CodStatementAcceptanceStatus;
use App\Enums\CodStatementType;
use App\Enums\SettlementMode;
use App\Models\Currency;
use App\Models\Settlement\CodSettlementStatement;
use App\Services\Settlement\CodSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * #179: statement lineage changes only finance acceptance state. It never
 * touches payment, order, shipment, fulfillment, or inventory authority.
 */
class CodSettlementLineageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    public function test_corrected_statement_supersedes_only_after_explicit_finance_acceptance(): void
    {
        $service = app(CodSettlementService::class);
        $actor = User::factory()->create(['type' => 'staff']);
        $this->currency('EGP');
        $order = $this->order();

        $original = $service->createStatement($this->payload($order, [
            'statement_key' => 'lineage-original-'.uniqid(),
        ]));
        $service->acceptStatement($original->id, $actor->id, 'finance accepted original');

        $correction = $service->createStatement($this->payload($order, [
            'statement_key' => 'lineage-correction-'.uniqid(),
            'statement_type' => CodStatementType::CORRECTED->value,
            'supersedes_statement_id' => $original->id,
            'lines' => [[
                'order_id' => $order->id,
                'expected' => '100.00',
                'received' => '101.00',
            ]],
        ]));

        $this->assertSame(CodStatementType::CORRECTED->value, $correction->statement_type);
        $this->assertSame($original->id, (int) $correction->supersedes_statement_id);
        $this->assertSame(
            CodStatementAcceptanceStatus::ACCEPTED->value,
            $original->fresh()->acceptance_status,
            'Creating a correction must not supersede the prior statement.'
        );
        $this->assertSame(
            CodStatementAcceptanceStatus::PENDING->value,
            $correction->fresh()->acceptance_status
        );

        $accepted = $service->acceptStatement($correction->id, $actor->id, 'finance accepted correction');

        $this->assertSame(CodStatementAcceptanceStatus::ACCEPTED->value, $accepted->acceptance_status);
        $this->assertSame($actor->id, (int) $accepted->reviewed_by);
        $this->assertNotNull($accepted->reviewed_at);
        $this->assertSame('finance accepted correction', $accepted->review_reason);

        $superseded = $original->fresh();
        $this->assertSame(CodStatementAcceptanceStatus::SUPERSEDED->value, $superseded->acceptance_status);
        $this->assertNotNull($superseded->superseded_at);
        $this->assertNotNull($superseded->reviewed_by, 'Original acceptance evidence must be preserved.');
    }

    public function test_rejected_correction_preserves_the_prior_accepted_statement(): void
    {
        $service = app(CodSettlementService::class);
        $actor = User::factory()->create(['type' => 'staff']);
        $this->currency('EGP');
        $order = $this->order();

        $original = $service->createStatement($this->payload($order, [
            'statement_key' => 'lineage-original-'.uniqid(),
        ]));
        $service->acceptStatement($original->id, $actor->id, 'finance accepted original');
        $acceptedOriginal = $original->fresh();
        $before = [
            'acceptance_status' => $acceptedOriginal->acceptance_status,
            'reviewed_by' => $acceptedOriginal->reviewed_by,
            'reviewed_at' => $acceptedOriginal->reviewed_at?->toISOString(),
            'review_reason' => $acceptedOriginal->review_reason,
            'superseded_at' => $acceptedOriginal->superseded_at?->toISOString(),
        ];

        $correction = $service->createStatement($this->payload($order, [
            'statement_key' => 'lineage-rejected-'.uniqid(),
            'statement_type' => CodStatementType::CORRECTED->value,
            'supersedes_statement_id' => $original->id,
            'lines' => [[
                'order_id' => $order->id,
                'expected' => '100.00',
                'received' => '101.00',
            ]],
        ]));

        $rejected = $service->rejectStatement($correction->id, $actor->id, 'correction rejected');

        $this->assertSame(CodStatementAcceptanceStatus::REJECTED->value, $rejected->acceptance_status);
        $this->assertSame($actor->id, (int) $rejected->reviewed_by);
        $this->assertSame('correction rejected', $rejected->review_reason);
        $afterOriginal = CodSettlementStatement::findOrFail($original->id);
        $this->assertSame([
            'acceptance_status' => $afterOriginal->acceptance_status,
            'reviewed_by' => $afterOriginal->reviewed_by,
            'reviewed_at' => $afterOriginal->reviewed_at?->toISOString(),
            'review_reason' => $afterOriginal->review_reason,
            'superseded_at' => $afterOriginal->superseded_at?->toISOString(),
        ], $before, 'Rejecting a correction must not mutate the accepted statement.');
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

    private function order(): Order
    {
        $customer = User::factory()->create(['type' => 'customer']);

        return Order::create([
            'user_id' => $customer->id,
            'name' => 'Lineage Customer',
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

    /** @return array<string,mixed> */
    private function payload(Order $order, array $overrides = []): array
    {
        return array_merge([
            'provider_code' => 'carrier_a',
            'mode' => SettlementMode::SANDBOX->value,
            'account_code' => 'account-01',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'statement_key' => 'stmt-'.uniqid(),
            'statement_type' => CodStatementType::ORIGINAL->value,
            'lines' => [[
                'order_id' => $order->id,
                'expected' => '100.00',
                'received' => '100.00',
            ]],
        ], $overrides);
    }
}
