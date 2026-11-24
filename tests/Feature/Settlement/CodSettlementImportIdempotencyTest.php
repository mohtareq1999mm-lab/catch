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

    public function test_provider_mode_or_hash_differences_create_separate_statements(): void
    {
        $this->currency('EGP');
        $order = $this->order();
        $service = app(CodSettlementService::class);
        $base = $this->payload($order);

        $imports = [
            $service->importStatement($base),
            $service->importStatement($this->payload($order, [
                'statement_key' => 'other-provider',
                'provider_code' => 'carrier_b',
            ])),
            $service->importStatement($this->payload($order, [
                'statement_key' => 'other-mode',
                'mode' => SettlementMode::PRODUCTION->value,
            ])),
            $service->importStatement($this->payload($order, [
                'statement_key' => 'other-hash',
                'file_hash' => hash('sha256', 'different-bytes'),
            ])),
        ];

        $this->assertCount(4, array_unique(array_map(static fn ($statement): int => (int) $statement->id, $imports)));
        $this->assertSame(4, CodSettlementStatement::query()->count());
        $this->assertSame(4, CodSettlementLine::query()->count());
    }

    public function test_invalid_file_hash_is_rejected_and_mixed_case_hash_is_normalized(): void
    {
        $this->currency('EGP');
        $order = $this->order();
        $service = app(CodSettlementService::class);
        $hash = hash('sha256', 'mixed-case-file');

        foreach ([
            'deadbeef',
            str_repeat('g', 64),
            $hash . '0',
            123,
        ] as $invalidHash) {
            try {
                $service->importStatement($this->payload($order, ['file_hash' => $invalidHash]));
                $this->fail('Invalid file_hash must be rejected before any write.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertSame(__('cod_settlement.invalid_payload', ['field' => 'file_hash']), $exception->getMessage());
            }
        }

        $this->assertSame(0, CodSettlementStatement::query()->count());

        $upper = $service->importStatement($this->payload($order, [
            'statement_key' => 'upper',
            'file_hash' => strtoupper($hash),
        ]));
        $lower = $service->importStatement($this->payload($order, [
            'statement_key' => 'lower',
            'file_hash' => $hash,
        ]));

        $this->assertSame($upper->id, $lower->id);
        $this->assertSame($hash, $upper->fresh()->file_hash);
        $this->assertSame(1, CodSettlementStatement::query()->count());
    }

    public function test_create_statement_with_file_hash_uses_the_idempotent_import_path(): void
    {
        $this->currency('EGP');
        $order = $this->order();
        $service = app(CodSettlementService::class);
        $payload = $this->payload($order);

        $first = $service->createStatement($payload);
        $second = $service->createStatement($this->payload($order, ['statement_key' => 'stmt-import-2']));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, CodSettlementStatement::query()->count());
        $this->assertSame(1, CodSettlementLine::query()->count());
    }

    public function test_database_unique_index_rejects_a_duplicate_import_identity(): void
    {
        $this->currency('EGP');
        $order = $this->order();
        $statement = app(CodSettlementService::class)->importStatement($this->payload($order));

        $duplicate = $statement->only([
            'statement_key', 'file_hash', 'provider_code', 'mode', 'account_code',
            'period_start', 'period_end', 'currency_code', 'decimal_places',
            'expected_minor', 'received_minor', 'variance_minor',
        ]);
        $duplicate['statement_key'] = 'duplicate-must-be-rejected';

        try {
            CodSettlementStatement::query()->create($duplicate);
            $this->fail('The DB unique index must reject a duplicate provider+mode+file_hash.');
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            $this->assertSame(1, CodSettlementStatement::query()->count());
        }

        // NULL hashes are ordinary/manual statements: MySQL allows many of them.
        foreach (['manual-a', 'manual-b'] as $key) {
            CodSettlementStatement::query()->create(array_merge($duplicate, [
                'statement_key' => $key,
                'file_hash' => null,
            ]));
        }

        $this->assertSame(3, CodSettlementStatement::query()->count());
        $this->assertSame(2, CodSettlementStatement::query()->whereNull('file_hash')->count());
    }
}

