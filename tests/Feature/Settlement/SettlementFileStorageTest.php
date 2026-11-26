<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Enums\SettlementMode;
use App\Events\OrderStatusChanged;
use App\Events\PaymentSucceeded;
use App\Models\Settlement\SettlementImportFile;
use App\Services\Settlement\SettlementFileImportService;
use App\Services\Settlement\SettlementParserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * #185: settlement imports are private, hashed, hash-idempotent evidence.
 *
 * Synthetic CSV content only; no real customer, provider, or secret payloads.
 */
class SettlementFileStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        Storage::fake('private');
    }

    private const VALID_CSV = "order_id,expected,received\n101,10.00,9.50\n";

    private function upload(string $contents, string $name = 'settlement.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $contents);
    }

    private function identity(array $overrides = []): array
    {
        return array_merge([
            'statement_key' => 'stmt-185-01',
            'provider_code' => 'carrier_a',
            'mode' => SettlementMode::SANDBOX->value,
            'account_code' => 'account-01',
        ], $overrides);
    }

    private function financialSnapshot(): array
    {
        $snapshot = [];

        foreach ([
            'orders', 'cod_settlement_statements', 'cod_settlement_lines', 'currency_rates',
        ] as $table) {
            $snapshot[$table] = Schema::hasTable($table) ? DB::table($table)->count() : 0;
        }

        return $snapshot;
    }

    /**
     * @return mixed The guarded callable's result.
     */
    private function withoutFinancialSideEffects(callable $callback): mixed
    {
        Event::fake([OrderStatusChanged::class, PaymentSucceeded::class]);
        Bus::fake();
        Queue::fake();

        $before = $this->financialSnapshot();
        $result = $callback();

        Event::assertNotDispatched(OrderStatusChanged::class);
        Event::assertNotDispatched(PaymentSucceeded::class);
        Bus::assertNothingDispatched();
        Queue::assertNothingPushed();
        $this->assertSame($before, $this->financialSnapshot());

        return $result;
    }

    public function test_import_is_private_hashed_and_never_publicly_linked(): void
    {
        $result = $this->withoutFinancialSideEffects(
            fn () => app(SettlementFileImportService::class)->import($this->upload(self::VALID_CSV), $this->identity())
        );

        $this->assertFalse($result->duplicate);
        $this->assertSame('private', config('settlement.imports.disk'));
        $this->assertSame('private', config('filesystems.disks.private.visibility'));
        $this->assertArrayNotHasKey('url', config('filesystems.disks.private'));
        $this->assertStringStartsWith('settlement-statements/', $result->file->path);
        $this->assertTrue(Storage::disk('private')->exists($result->file->path));
        $this->assertFalse(Storage::disk('public')->exists($result->file->path));
        $this->assertNull($result->file->url ?? null);

        $this->assertSame(hash('sha256', self::VALID_CSV), $result->file->file_hash);
        $this->assertSame(self::VALID_CSV, Storage::disk('private')->get($result->file->path));
        $this->assertDatabaseHas('settlement_import_files', [
            'id' => $result->file->id,
            'file_hash' => hash('sha256', self::VALID_CSV),
            'disk' => 'private',
            'path' => $result->file->path,
            'parser_version' => 'csv-v1',
            'parsed_line_count' => 1,
        ]);
    }

    public function test_duplicate_hash_is_detected_without_duplicating_evidence_rows(): void
    {
        $service = app(SettlementFileImportService::class);
        $identity = $this->identity();

        $first = $service->import($this->upload(self::VALID_CSV), $identity);
        $duplicate = $service->import($this->upload(self::VALID_CSV), $identity);

        $this->assertFalse($first->duplicate);
        $this->assertTrue($duplicate->duplicate);
        $this->assertSame($first->file->id, $duplicate->file->id);
        $this->assertSame($first->file->path, $duplicate->file->path);
        $this->assertSame([], $duplicate->parse->lines, 'Duplicate hashes must emit no new statement lines.');
        $this->assertSame($first->parse->malformedRows, $duplicate->parse->malformedRows);
        $this->assertCount(1, $first->parse->lines);
        $this->assertSame(1, $duplicate->file->parsed_line_count);
        $this->assertSame(1, SettlementImportFile::query()->count());
        $this->assertCount(1, Storage::disk('private')->allFiles());

        // Same bytes under a distinct statement identity are distinct evidence.
        $otherStatement = $service->import(
            $this->upload(self::VALID_CSV),
            $this->identity(['statement_key' => 'stmt-185-02'])
        );

        $this->assertFalse($otherStatement->duplicate);
        $this->assertSame(2, SettlementImportFile::query()->count());
    }

    public function test_parser_is_read_only_and_does_not_mutate_financial_state(): void
    {
        $parser = app(SettlementParserFactory::class)->forProvider('carrier_a');
        $this->assertSame('csv-v1', $parser->version());
        $this->assertTrue($parser->supports(self::VALID_CSV));

        $parse = $this->withoutFinancialSideEffects(fn () => $parser->parse(self::VALID_CSV));

        $this->assertCount(1, $parse->lines);
        $this->assertSame(101, $parse->lines[0]['order_id']);
        $this->assertSame([], $parse->malformedRows);
        $this->assertSame(0, DB::table('cod_settlement_statements')->count());
        $this->assertSame(0, DB::table('cod_settlement_lines')->count());
        $this->assertSame(0, SettlementImportFile::query()->count());
    }

    public function test_malformed_rows_are_reported_and_retained(): void
    {
        $csv = "order_id,expected,received\n101,10.00,9.50\nBAD-ROW\n102,not-money,1.00\n";

        $result = $this->withoutFinancialSideEffects(
            fn () => app(SettlementFileImportService::class)->import($this->upload($csv), $this->identity())
        );

        $this->assertCount(1, $result->parse->lines);
        $this->assertCount(2, $result->parse->malformedRows);
        $this->assertSame(3, $result->parse->malformedRows[0]['row']);
        $this->assertSame('column_count', $result->parse->malformedRows[0]['reason']);
        $this->assertSame('BAD-ROW', $result->parse->malformedRows[0]['raw']);
        $this->assertSame(4, $result->parse->malformedRows[1]['row']);
        $this->assertSame('amount', $result->parse->malformedRows[1]['reason']);
        $this->assertSame('102,not-money,1.00', $result->parse->malformedRows[1]['raw']);

        $storedMalformed = $result->file->fresh()->malformed_rows;
        $this->assertCount(2, $storedMalformed);
        foreach ($result->parse->malformedRows as $index => $malformed) {
            $this->assertSame($malformed['row'], $storedMalformed[$index]['row']);
            $this->assertSame($malformed['reason'], $storedMalformed[$index]['reason']);
            $this->assertSame($malformed['raw'], $storedMalformed[$index]['raw']);
        }
        $this->assertSame(1, $result->file->fresh()->parsed_line_count);
        $this->assertSame(0, DB::table('cod_settlement_statements')->count());
    }

    public function test_invalid_content_is_rejected_before_private_storage(): void
    {
        try {
            app(SettlementFileImportService::class)->import($this->upload("\x00\x01binary"), $this->identity());
            $this->fail('Invalid settlement content must be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $this->assertSame(0, SettlementImportFile::query()->count());
        $this->assertCount(0, Storage::disk('private')->allFiles());
        $this->assertSame(0, DB::table('cod_settlement_statements')->count());
    }
}
