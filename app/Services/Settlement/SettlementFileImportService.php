<?php

declare(strict_types=1);

namespace App\Services\Settlement;

use App\DTOs\Settlement\SettlementImportResult;
use App\DTOs\Settlement\SettlementParseResult;
use App\Enums\SettlementMode;
use App\Models\Settlement\SettlementImportFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

/**
 * #185: store original settlement files privately and extract evidence rows.
 *
 * This service writes import metadata only. It never creates or accepts a
 * statement line, reconciles a variance, adjusts COD, marks an order paid, or
 * dispatches lifecycle side effects.
 */
final class SettlementFileImportService
{
    public function __construct(private SettlementParserFactory $parsers) {}

    public function import(UploadedFile $file, array $identity): SettlementImportResult
    {
        $scope = $this->validateIdentity($identity);
        $contents = $this->validateUpload($file);
        $mime = $this->detectedMime($file);
        $this->validateMime($mime);
        $this->validateContent($contents);
        $hash = hash('sha256', $contents);

        $existing = $this->findDuplicate($scope, $hash);
        if ($existing !== null) {
            return $this->duplicateResult($existing);
        }

        $disk = (string) config('settlement.imports.disk', 'private');
        $path = $this->storagePath($scope, $hash);
        $parser = $this->parsers->forProvider($scope['provider_code']);

        if (!$parser->supports($contents)) {
            throw new InvalidArgumentException(__('cod_settlement.invalid_content'));
        }

        $parse = $parser->parse($contents);

        if (!Storage::disk($disk)->put($path, $contents, 'private')) {
            throw new InvalidArgumentException(__('cod_settlement.storage_failed'));
        }

        try {
            $record = SettlementImportFile::create([
                'statement_key' => $scope['statement_key'],
                'provider_code' => $scope['provider_code'],
                'mode' => $scope['mode'],
                'account_code' => $scope['account_code'],
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 191),
                'disk' => $disk,
                'path' => $path,
                'file_hash' => $hash,
                'mime_type' => $mime,
                'size_bytes' => strlen($contents),
                'parser_class' => $parser::class,
                'parser_version' => $parser->version(),
                'parsed_line_count' => count($parse->lines),
                'malformed_rows' => $parse->malformedRows,
            ]);
        } catch (Throwable $e) {
            // A concurrent winner owns this deterministic path; never delete it.
            $winner = $this->findDuplicate($scope, $hash);
            if ($winner !== null) {
                return $this->duplicateResult($winner);
            }

            Storage::disk($disk)->delete($path);

            throw $e;
        }

        return new SettlementImportResult($record, false, $parse);
    }

    /**
     * A duplicate hash is an idempotent no-op: no new parsed lines are emitted.
     * The original file record remains the sole source of parsed evidence.
     */
    private function duplicateResult(SettlementImportFile $file): SettlementImportResult
    {
        return new SettlementImportResult(
            $file,
            true,
            new SettlementParseResult([], $file->malformed_rows ?? [])
        );
    }

    private function findDuplicate(array $scope, string $hash): ?SettlementImportFile
    {
        $query = SettlementImportFile::query()->where('file_hash', $hash);
        foreach ($scope as $field => $value) {
            $query->where($field, $value);
        }

        return $query->first();
    }

    /**
     * @return array{statement_key: string, provider_code: string, mode: string, account_code: string}
     */
    private function validateIdentity(array $identity): array
    {
        $mode = $identity['mode'] ?? null;
        $mode = $mode instanceof SettlementMode ? $mode : SettlementMode::tryFrom((string) $mode);
        if ($mode === null) {
            throw new InvalidArgumentException(__('cod_settlement.invalid_payload', ['field' => 'mode']));
        }

        return [
            'statement_key' => $this->stringField($identity['statement_key'] ?? null, 'statement_key', 191),
            'provider_code' => $this->stringField($identity['provider_code'] ?? null, 'provider_code', 32),
            'mode' => $mode->value,
            'account_code' => $this->stringField($identity['account_code'] ?? null, 'account_code', 64),
        ];
    }

    /** Validate upload shape before MIME detection, hashing, storage, or parsing. */
    private function validateUpload(UploadedFile $file): string
    {
        $realPath = $file->getRealPath();
        if (!$file->isValid() || !is_string($realPath) || !is_file($realPath)) {
            throw new InvalidArgumentException(__('cod_settlement.invalid_file'));
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, (array) config('settlement.imports.allowed_extensions', []), true)) {
            throw new InvalidArgumentException(__('cod_settlement.invalid_file'));
        }

        $size = $file->getSize();
        $maxBytes = (int) config('settlement.imports.max_bytes', 2097152);
        if ($size === false || $size < 1 || $size > $maxBytes) {
            throw new InvalidArgumentException(__('cod_settlement.file_too_large', ['max' => $maxBytes]));
        }

        $contents = file_get_contents($realPath);
        if ($contents === false) {
            throw new InvalidArgumentException(__('cod_settlement.invalid_file'));
        }

        return $contents;
    }

    private function validateMime(string $mime): void
    {
        if (!in_array($mime, (array) config('settlement.imports.allowed_mime_types', []), true)) {
            throw new InvalidArgumentException(__('cod_settlement.invalid_mime'));
        }
    }

    private function validateContent(string $contents): void
    {
        if (str_contains($contents, "\0") || preg_match('//u', $contents) !== 1) {
            throw new InvalidArgumentException(__('cod_settlement.invalid_content'));
        }
    }

    private function detectedMime(UploadedFile $file): string
    {
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->file((string) $file->getRealPath());

        return is_string($detected) ? strtolower($detected) : '';
    }

    /**
     * @param array{statement_key: string, provider_code: string, mode: string, account_code: string} $scope
     */
    private function storagePath(array $scope, string $hash): string
    {
        $scopeHash = substr(hash('sha256', implode('|', [
            $scope['provider_code'],
            $scope['mode'],
            $scope['account_code'],
            $scope['statement_key'],
        ])), 0, 16);
        $configuredDirectory = (string) config('settlement.imports.directory', 'settlement-statements');
        $directory = preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/D', $configuredDirectory) === 1
            ? $configuredDirectory
            : 'settlement-statements';
        $date = now()->setTimezone((string) config('app.timezone', 'UTC'))->format('Y/m');

        return "{$directory}/{$date}/{$scopeHash}/{$hash}.csv";
    }

    private function stringField(mixed $value, string $field, int $maxLength): string
    {
        if (!is_string($value) && !is_int($value)) {
            throw new InvalidArgumentException(__('cod_settlement.invalid_payload', ['field' => $field]));
        }

        $value = trim((string) $value);
        if ($value === '' || strlen($value) > $maxLength) {
            throw new InvalidArgumentException(__('cod_settlement.invalid_payload', ['field' => $field]));
        }

        return $value;
    }
}
