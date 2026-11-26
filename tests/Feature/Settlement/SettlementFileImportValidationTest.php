<?php

declare(strict_types=1);

namespace Tests\Feature\Settlement;

use App\Enums\SettlementMode;
use App\Models\Settlement\SettlementImportFile;
use App\Services\Settlement\SettlementFileImportService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Mockery;
use Tests\TestCase;

/**
 * #185: rejected imports leave no private file, metadata row, or financial state.
 *
 * Synthetic files only; no customer, provider, or secret payloads.
 */
class SettlementFileImportValidationTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_CSV = "order_id,expected,received\n101,10.00,9.50\n";

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        Storage::fake('private');
    }

    private function upload(string $contents, string $name = 'settlement.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $contents);
    }

    /** @return array{statement_key: string, provider_code: string, mode: string, account_code: string} */
    private function identity(array $overrides = []): array
    {
        return array_merge([
            'statement_key' => 'stmt-185-validation',
            'provider_code' => 'carrier_a',
            'mode' => SettlementMode::SANDBOX->value,
            'account_code' => 'account-01',
        ], $overrides);
    }

    private function assertRejected(UploadedFile $file, array $identity, string $expectedMessage): void
    {
        try {
            app(SettlementFileImportService::class)->import($file, $identity);
            $this->fail('Expected settlement import to be rejected: ' . $expectedMessage);
        } catch (InvalidArgumentException $e) {
            $this->assertSame($expectedMessage, $e->getMessage());
        }

        $this->assertSame(0, SettlementImportFile::query()->count());
        $this->assertCount(0, Storage::disk('private')->allFiles());
    }

    public function test_wrong_extension_is_rejected_before_storage(): void
    {
        $this->assertRejected(
            $this->upload(self::VALID_CSV, 'settlement.txt'),
            $this->identity(),
            __('cod_settlement.invalid_file')
        );
    }

    public function test_disallowed_mime_is_rejected_before_storage(): void
    {
        $this->assertRejected(
            UploadedFile::fake()->image('settlement.csv', 2, 2),
            $this->identity(),
            __('cod_settlement.invalid_mime')
        );
    }

    public function test_oversize_upload_is_rejected_before_storage(): void
    {
        config()->set('settlement.imports.max_bytes', 8);

        $this->assertRejected(
            $this->upload(self::VALID_CSV),
            $this->identity(),
            __('cod_settlement.file_too_large', ['max' => 8])
        );
    }

    public function test_invalid_identity_fields_are_rejected_before_storage(): void
    {
        $cases = [
            [['statement_key' => null], 'statement_key'],
            [['provider_code' => '   '], 'provider_code'],
            [['mode' => 'production-at-present'], 'mode'],
            [['account_code' => str_repeat('a', 65)], 'account_code'],
        ];

        foreach ($cases as [$overrides, $field]) {
            $this->assertRejected(
                $this->upload(self::VALID_CSV),
                $this->identity($overrides),
                __('cod_settlement.invalid_payload', ['field' => $field])
            );
        }
    }

    public function test_parser_unavailable_is_rejected_before_storage(): void
    {
        config()->set('settlement.imports.parsers', ['default' => null]);

        $this->assertRejected(
            $this->upload(self::VALID_CSV),
            $this->identity(),
            __('cod_settlement.parser_unavailable')
        );
    }

    public function test_storage_failure_leaves_no_metadata_row(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->once()->andReturn(false);
        $disk->shouldNotReceive('delete');
        Storage::shouldReceive('disk')->with('private')->once()->andReturn($disk);

        try {
            app(SettlementFileImportService::class)->import($this->upload(self::VALID_CSV), $this->identity());
            $this->fail('Private storage failure must be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(__('cod_settlement.storage_failed'), $e->getMessage());
        }

        $this->assertSame(0, SettlementImportFile::query()->count());
    }

    public function test_unsafe_configured_directory_falls_back_to_safe_segment(): void
    {
        config()->set('settlement.imports.directory', '../outside');

        $result = app(SettlementFileImportService::class)->import(
            $this->upload(self::VALID_CSV),
            $this->identity()
        );

        $this->assertStringStartsWith('settlement-statements/', $result->file->path);
        $this->assertStringNotContainsString('..', $result->file->path);
        $this->assertTrue(Storage::disk('private')->exists($result->file->path));
    }
}
