<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Enums\SettlementMode;
use App\Models\Currency;
use App\Models\Settlement\CodSettlementLine;
use App\Models\Settlement\CodSettlementStatement;
use App\Models\User;
use App\Services\Settlement\CodSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Order;
use Marvel\Enums\Permission;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Tests\TestCase;

class CodSettlementImportIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function currency(string $code = 'EGP', int $decimalPlaces = 2): Currency
    {
        return Currency::create([
            'code' => $code,
            'name' => $code,
            'symbol' => $code,
            'decimal_places' => $decimalPlaces,
            'is_active' => true,
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

    private function payload(Order $order, array $overrides = []): array
    {
        return array_merge([
            'provider_code' => 'carrier_a',
            'mode' => SettlementMode::SANDBOX->value,
            'account_code' => 'account-01',
            'period_start' => '2026-11-01',
            'period_end' => '2026-11-30',
            'statement_key' => 'stmt-import-1',
            'file_hash' => hash('sha256', 'same-file-content'),
            'lines' => [
                [
                    'order_id' => $order->id,
                    'expected' => '100.50',
                    'received' => '100.00',
                ],
            ],
        ], $overrides);
    }

    public function test_reimporting_same_file_returns_existing_result_without_duplicating_lines(): void
    {
        $this->currency('EGP');
        $order = $this->order();
        $service = app(CodSettlementService::class);
        $payload = $this->payload($order);

        $first = $service->importStatement($payload);
        $second = $service->importStatement($payload);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, CodSettlementStatement::query()->count());
        $this->assertSame(1, CodSettlementLine::query()->count());
        $this->assertSame($first->file_hash, $second->fresh()->file_hash);
    }

    public function test_same_file_hash_is_idempotent_by_provider_mode_and_file_hash(): void
    {
        $this->currency('EGP');
        $order = $this->order();
        $service = app(CodSettlementService::class);
        $fileHash = hash('sha256', 'identical-bytes');

        $first = $service->importStatement($this->payload($order, [
            'statement_key' => 'stmt-a',
            'file_hash' => $fileHash,
            'provider_code' => 'carrier_a',
            'mode' => SettlementMode::SANDBOX->value,
        ]));

        // Different statement_key but same provider+mode+file hash should return existing import.
        $second = $service->importStatement($this->payload($order, [
            'statement_key' => 'stmt-b-different',
            'file_hash' => $fileHash,
            'provider_code' => 'carrier_a',
            'mode' => SettlementMode::SANDBOX->value,
        ]));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, CodSettlementStatement::query()->count());
        $this->assertSame(1, CodSettlementLine::query()->count());
    }
}

