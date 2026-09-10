<?php

namespace Marvel\Jobs;

use App\Events\FileOperationEvent;
use App\Traits\BroadcastsFileOperationProgress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Marvel\Database\Models\Import;
use Marvel\Enums\FileOperationType;
use Marvel\Exceptions\ImportCancelledException;
use Marvel\Imports\CategoriesImport;
use Marvel\Services\Import\CategoryImportService;
use Throwable;

class ImportCategoriesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use BroadcastsFileOperationProgress;

    public int $tries = 3;

    public int $timeout = 1200;

    public array $backoff = [60, 120, 240];

    protected int $importId;

    public function __construct(int $importId)
    {
        $this->importId = $importId;
        $this->onQueue('meem-medium');
    }

    protected function removeSignalFile(string $type): void
    {
        $path = storage_path("app/imports/{$type}_{$this->importId}.json");

        if (file_exists($path)) {
            @unlink($path);
        }
    }

    protected function cancelSignalFileExists(): bool
    {
        $path = storage_path("app/imports/cancel_{$this->importId}.json");
        clearstatcache(true, $path);

        return file_exists($path);
    }

    protected function cleanSignals(): void
    {
        $this->removeSignalFile('cancel');
        $this->removeSignalFile('progress');
    }

    protected function resolveImportFilePath(Import $import): ?string
    {
        if (empty($import->file_path)) {
            return null;
        }

        // Primary: private imports disk (canonical location via CategoryImportController)
        if (Storage::disk('imports')->exists($import->file_path)) {
            return Storage::disk('imports')->path($import->file_path);
        }

        // Legacy fallback: public disk
        if (Storage::disk('public')->exists($import->file_path)) {
            return Storage::disk('public')->path($import->file_path);
        }

        // Fallback: local disk
        if (Storage::disk('local')->exists($import->file_path)) {
            return Storage::disk('local')->path($import->file_path);
        }

        // Last resort: try direct path resolution via imports disk (may be stale)
        try {
            return Storage::disk('imports')->path($import->file_path);
        } catch (Throwable $e) {
            return null;
        }
    }

    protected function deleteImportFile(Import $import): void
    {
        if (empty($import->file_path)) {
            return;
        }

        // Delete from primary disk; also attempt legacy disks to avoid orphans
        try {
            Storage::disk('imports')->delete($import->file_path);
        } catch (Throwable $e) {
            report($e);
        }

        try {
            Storage::disk('public')->delete($import->file_path);
        } catch (Throwable $e) {
        }

        try {
            Storage::disk('local')->delete($import->file_path);
        } catch (Throwable $e) {
        }
    }

    protected function sanitizeExceptionMessage(Throwable $e): string
    {
        $message = $e->getMessage();

        // Prevent leaking absolute paths, SQL, or credentials
        $message = preg_replace('#/[^ ]*storage[^ ]*#i', '[storage path]', $message) ?? $message;
        $message = preg_replace('#SQLSTATE\[[^\]]+\].*#i', 'Internal processing error', $message) ?? $message;

        // Truncate overly long messages
        if (strlen($message) > 500) {
            $message = substr($message, 0, 500) . '...';
        }

        $message = trim($message);

        return $message !== '' ? $message : 'Import failed due to an unexpected error';
    }

    public function handle(): void
    {
        $import = Import::select(['id', 'type', 'status', 'file_path', 'file_name'])->findOrFail($this->importId);

        // Validate operation type matches Category import
        $normalizedType = FileOperationType::normalize($import->type);
        if ($normalizedType !== FileOperationType::CATEGORY_IMPORT) {
            $sanitized = 'Invalid operation type for Category import: ' . ($import->type ?? 'null');
            report(new \RuntimeException($sanitized . ' (expected ' . FileOperationType::CATEGORY_IMPORT . ')'));

            if (! $import->isTerminal()) {
                $import->update([
                    'status' => 'failed',
                    'errors' => [[
                        'sheet' => 'system',
                        'row' => 0,
                        'name_en' => '',
                        'name_ar' => '',
                        'parent_name_en' => '',
                        'error_message' => $sanitized,
                    ]],
                ]);

                $this->broadcastCategoryImportTerminal('failed', true);
            }

            return;
        }

        if ($import->status === 'cancelled' || $this->cancelSignalFileExists()) {
            $this->deleteImportFile($import);
            $this->removeSignalFile('cancel');

            // Ensure DB is cancelled if signal exists but DB not yet updated
            if ($import->status !== 'cancelled') {
                Import::where('id', $import->id)->whereIn('status', ['pending', 'processing'])->update(['status' => 'cancelled']);
            }

            return;
        }

        if (in_array($import->status, ['completed', 'completed_with_errors', 'failed'], true)) {
            return;
        }

        // Atomic transition to processing
        $updated = Import::where('id', $import->id)->whereIn('status', ['pending', 'processing'])->update([
            'status' => 'processing',
            'processed_rows' => 0,
            'success_rows' => 0,
            'failed_rows' => 0,
        ]);

        // If another worker already transitioned, respect terminal state
        if ($updated === 0 && $import->status !== 'processing') {
            $import->refresh();
            if ($import->isTerminal()) {
                return;
            }
        } else {
            $import->refresh();
        }

        $filePath = $this->resolveImportFilePath($import);

        if ($filePath === null || ! file_exists($filePath)) {
            $import->update([
                'status' => 'failed',
                'errors' => [[
                    'sheet' => 'system',
                    'row' => 0,
                    'name_en' => '',
                    'name_ar' => '',
                    'parent_name_en' => '',
                    'error_message' => 'Import file not found',
                ]],
            ]);

            $this->broadcastCategoryImportTerminal('failed', true);

            return;
        }

        $service = new CategoryImportService($this->importId);
        $service->writeExplicitProgress(1.0);

        $totalRows = $this->countRows();

        if ($import->total_rows !== $totalRows) {
            $import->update(['total_rows' => $totalRows]);
        }

        $service->writeExplicitProgress(2.0);

        $previousHandler = config('excel.transactions.handler');
        try {
            // Avoid wrapping HTTP image downloads in a DB transaction (long I/O inside transaction is anti-pattern).
            // Brand/Category proven strategy: short transactions per entity, not one global Excel transaction.
            // TransactionManager expects driver name 'null' (string) for NullTransactionHandler, not php null.
            config(['excel.transactions.handler' => 'null']);

            $importObj = new CategoriesImport($service);

            $readerType = \Maatwebsite\Excel\Excel::XLSX;
            $extension = strtolower(pathinfo($import->file_name, PATHINFO_EXTENSION));

            if ($extension === 'xls') {
                $readerType = \Maatwebsite\Excel\Excel::XLS;
            } elseif ($extension === 'ods') {
                $readerType = \Maatwebsite\Excel\Excel::ODS;
            }

            Excel::import($importObj, $filePath, null, $readerType);

            $service->writeExplicitProgress(99.0);

            $service->finalizeProgress();

            $failedRows = $service->getFailedRows();
            $successCount = $service->getSuccessCount();

            $status = 'completed';

            if (!empty($failedRows) && $successCount > 0) {
                $status = 'completed_with_errors';
            } elseif ($successCount === 0) {
                $status = 'failed';
            }

            $import->update([
                'status' => $status,
                'total_rows' => $successCount + count($failedRows),
                'processed_rows' => $successCount + count($failedRows),
                'success_rows' => $successCount,
                'failed_rows' => count($failedRows),
                'errors' => $failedRows,
            ]);

            $this->broadcastCategoryImportTerminal($status, !empty($failedRows), [
                'progress' => 100.0,
                'total_rows' => $successCount + count($failedRows),
                'processed_rows' => $successCount + count($failedRows),
                'success_rows' => $successCount,
                'failed_rows' => count($failedRows),
            ]);

            $this->deleteImportFile($import);
            $this->removeSignalFile('progress');
            config(['excel.transactions.handler' => $previousHandler]);
        } catch (ImportCancelledException $e) {
            config(['excel.transactions.handler' => $previousHandler]);
            $service->rollbackCreatedData();
            $this->deleteImportFile($import);
            $this->cleanSignals();

            $import->update([
                'status' => 'cancelled',
                'total_rows' => $service->getSuccessCount() + count($service->getFailedRows()),
                'processed_rows' => $service->getSuccessCount() + count($service->getFailedRows()),
                'success_rows' => $service->getSuccessCount(),
                'failed_rows' => count($service->getFailedRows()),
                'errors' => $service->getFailedRows(),
            ]);

            $this->broadcastCategoryImportTerminal('cancelled', !empty($service->getFailedRows()), [
                'progress' => 100.0,
                'total_rows' => $service->getSuccessCount() + count($service->getFailedRows()),
                'processed_rows' => $service->getSuccessCount() + count($service->getFailedRows()),
                'success_rows' => $service->getSuccessCount(),
                'failed_rows' => count($service->getFailedRows()),
            ]);
        } catch (Throwable $e) {
            config(['excel.transactions.handler' => $previousHandler]);
            $sanitized = $this->sanitizeExceptionMessage($e);
            report($e);

            if ($this->attempts() >= $this->tries) {
                $import->update([
                    'status' => 'failed',
                    'errors' => [[
                        'sheet' => 'system',
                        'row' => 0,
                        'name_en' => '',
                        'name_ar' => '',
                        'parent_name_en' => '',
                        'error_message' => $sanitized,
                    ]],
                ]);

                $this->broadcastCategoryImportTerminal('failed', true);

                // Clean up on terminal failure
                $this->deleteImportFile($import);
                $this->removeSignalFile('progress');
            } else {
                $import->update([
                    'errors' => array_merge($import->errors ?? [], [[
                        'sheet' => 'system',
                        'row' => 0,
                        'name_en' => '',
                        'name_ar' => '',
                        'parent_name_en' => '',
                        'error_message' => 'Attempt ' . $this->attempts() . ': ' . $sanitized,
                    ]]),
                ]);
            }

            throw $e;
        }
    }

    /**
     * Terminal signal for category imports, carried on the existing
     * `category.import.progress` event name with additive `status` /
     * `kind` / `has_errors` keys. Existing payload keys (import_id, type)
     * are preserved for wire compatibility with current consumers.
     */
    protected function broadcastCategoryImportTerminal(
        string $status,
        bool $hasErrors,
        array $extraPayload = [],
    ): void {
        $this->broadcastFileOperationTerminal(
            FileOperationEvent::CATEGORY_IMPORT_PROGRESS,
            'category-import',
            $this->importId,
            $status,
            $hasErrors,
            array_merge([
                'type' => 'category',
                'import_id' => $this->importId,
            ], $extraPayload)
        );
    }

    protected function countRows(): int
    {
        try {
            $import = Import::find($this->importId);

            if (! $import || empty($import->file_path)) {
                return 0;
            }

            $filePath = $this->resolveImportFilePath($import);

            if ($filePath === null || ! file_exists($filePath)) {
                return 0;
            }

            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($filePath);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($filePath);

            $total = 0;

            foreach ($spreadsheet->getSheetNames() as $name) {
                $sheet = $spreadsheet->getSheetByName($name);

                if ($sheet) {
                    $total += $sheet->getHighestDataRow();
                }
            }

            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet, $reader);

            return $total;
        } catch (Throwable $e) {
            return 0;
        }
    }

    public function failed(Throwable $exception): void
    {
        $import = Import::find($this->importId);

        if ($import && $import->status === 'processing') {
            $import->update(['status' => 'failed']);

            $this->broadcastCategoryImportTerminal('failed', true);

            $this->deleteImportFile($import);
            $this->removeSignalFile('progress');
        }
    }
}
