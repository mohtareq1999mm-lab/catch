<?php

namespace Marvel\Jobs;

use App\Events\FileOperationEvent;
use App\Traits\BroadcastsFileOperationProgress;
use Marvel\Database\Models\Import;
use Marvel\Enums\ImportStatus;
use Marvel\Exceptions\ImportCancelledException;
use Marvel\Imports\ProductsImport;
use Marvel\Services\Import\ProductImportService;
use Throwable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ImportProductsJob implements ShouldQueue
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
        clearstatcache(true, $path);
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
        if (Storage::disk('imports')->exists($import->file_path)) {
            return Storage::disk('imports')->path($import->file_path);
        }
        if (Storage::disk('public')->exists($import->file_path)) {
            return Storage::disk('public')->path($import->file_path);
        }
        if (Storage::disk('local')->exists($import->file_path)) {
            return Storage::disk('local')->path($import->file_path);
        }
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
        $message = preg_replace('#/[^ ]*storage[^ ]*#i', '[storage path]', $message) ?? $message;
        $message = preg_replace('#SQLSTATE\[[^\]]+\].*#i', 'Internal processing error', $message) ?? $message;
        if (strlen($message) > 500) {
            $message = substr($message, 0, 500) . '...';
        }
        $message = trim($message);
        return $message !== '' ? $message : 'Import failed due to an unexpected error';
    }

    public function handle(): void
    {
        $import = Import::select(['id', 'type', 'status', 'file_path', 'file_name'])->findOrFail($this->importId);

        $normalizedType = \Marvel\Enums\FileOperationType::normalize($import->type);
        if ($normalizedType !== \Marvel\Enums\FileOperationType::PRODUCT_IMPORT) {
            $sanitized = 'Invalid operation type for Product import: ' . ($import->type ?? 'null');
            report(new \RuntimeException($sanitized . ' (expected ' . \Marvel\Enums\FileOperationType::PRODUCT_IMPORT . ')'));
            if (! $import->isTerminal()) {
                $import->update([
                    'status' => 'failed',
                    'errors' => [[
                        'sheet' => 'system',
                        'row' => 0,
                        'sku' => '',
                        'error_message' => $sanitized,
                    ]],
                ]);
                $this->broadcastFileOperationTerminal(
                    FileOperationEvent::PRODUCT_IMPORT_PROGRESS,
                    'product-import',
                    $this->importId,
                    'failed',
                    true
                );
            }
            return;
        }

        if ($import->status === 'cancelled' || $this->cancelSignalFileExists()) {
            $this->deleteImportFile($import);
            $this->removeSignalFile('cancel');
            if ($import->status !== 'cancelled') {
                Import::where('id', $import->id)->whereIn('status', ['pending', 'processing'])->update(['status' => 'cancelled']);
            }
            return;
        }

        if (in_array($import->status, ['completed', 'completed_with_errors', 'failed'], true)) {
            return;
        }

        $updated = Import::where('id', $import->id)->whereIn('status', ['pending', 'processing'])->update([
            'status' => 'processing',
            'processed_rows' => 0,
            'success_rows' => 0,
            'failed_rows' => 0,
        ]);
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
                    'sku' => '',
                    'error_message' => 'Import file not found',
                ]],
            ]);
            $this->broadcastFileOperationTerminal(
                FileOperationEvent::PRODUCT_IMPORT_PROGRESS,
                'product-import',
                $this->importId,
                'failed',
                true
            );
            return;
        }

        $service = new ProductImportService($this->importId);
        $service->writeExplicitProgress(1.0);

        $totalRows = $this->countRows();
        $service->setTotalRows($totalRows);

        if ($import->total_rows !== $totalRows) {
            $import->update(['total_rows' => $totalRows]);
        }

        $service->writeExplicitProgress(2.0);

        try {
            $readerType = \Maatwebsite\Excel\Excel::XLSX;
            $extension = strtolower(pathinfo($import->file_name, PATHINFO_EXTENSION));
            if ($extension === 'xls') {
                $readerType = \Maatwebsite\Excel\Excel::XLS;
            } elseif ($extension === 'ods') {
                $readerType = \Maatwebsite\Excel\Excel::ODS;
            }

            // Phase 1: core import (products, variants, relations) without global transaction
            // Must not hold DB transaction across 12k image downloads
            $prevHandler = config('excel.transactions.handler');
            config(['excel.transactions.handler' => 'null']);
            try {
                $coreImport = new ProductsImport($service, false);
                Excel::import($coreImport, $filePath, null, $readerType);
            } finally {
                config(['excel.transactions.handler' => $prevHandler]);
            }

            $service->flushPendingSyncs();
            $service->finalizeVariants();

            // Persist core counters immediately so products are visible even if image phase is slow
            $service->finalizeProgress();
            $failedRows = $service->getFailedRows();
            $successCount = $service->getSuccessCount();
            $coreTotal = $successCount + count($failedRows);
            if ($coreTotal === 0 && $totalRows === 0) {
                $coreTotal = 0;
            } elseif ($coreTotal === 0) {
                $coreTotal = $totalRows;
            }
            // Temporary status update for core; final status after images
            $import->update([
                'processed_rows' => $successCount + count($failedRows),
                'success_rows' => $successCount,
                'failed_rows' => count($failedRows),
                'errors' => array_slice($service->getAllErrors(), 0, 1000),
            ]);

            // Phase 2: image processing in bounded async chunks (meem-medium)
            $this->dispatchImageJobs($filePath, $service);
            $service->writeExplicitProgress(99.0);

            $failedRows = $service->getFailedRows();
            $successCount = $service->getSuccessCount();
            $allErrors = $service->getAllErrors();

            // Invariant enforcement: product-row counters must not exceed known total
            // Reconcile total to actual product work (success+failed) like Brand/Category
            $terminalTotal = $successCount + count($failedRows);
            // If countRows was 0 (empty file), fallback to actual
            if ($terminalTotal === 0 && $totalRows === 0) {
                $terminalTotal = 0;
            } elseif ($terminalTotal === 0) {
                $terminalTotal = $totalRows;
            }
            // Ensure total = processed = success+failed at terminal
            $finalTotal = $terminalTotal;

            $status = 'completed';
            if (!empty($failedRows) && $successCount > 0) {
                $status = 'completed_with_errors';
            } elseif ($successCount === 0) {
                $status = ImportStatus::FAILED;
            }

            // Persist product-row counters; errors include variant/image for download
            $import->update([
                'status' => $status,
                'total_rows' => $finalTotal,
                'processed_rows' => $successCount + count($failedRows),
                'success_rows' => $successCount,
                'failed_rows' => count($failedRows),
                'errors' => $allErrors,
            ]);

            \Illuminate\Support\Facades\Log::info('product.import.' . $status, [
                'operation_id' => $this->importId,
                'total_rows' => $finalTotal,
                'processed_rows' => $successCount + count($failedRows),
                'successful_rows' => $successCount,
                'failed_rows' => count($failedRows),
                'variant_success' => $service->getVariantSuccessCount(),
                'variant_failed' => count($service->getVariantErrors()),
                'image_failed' => count($service->getImageErrors()),
                'status' => $status,
            ]);

            $this->broadcastFileOperationTerminal(
                FileOperationEvent::PRODUCT_IMPORT_PROGRESS,
                'product-import',
                $this->importId,
                $status,
                !empty($allErrors),
                [
                    'progress' => 100.0,
                    'total_rows' => $finalTotal,
                    'processed_rows' => $successCount + count($failedRows),
                    'success_rows' => $successCount,
                    'failed_rows' => count($failedRows),
                ]
            );

            $this->deleteImportFile($import);
            $this->removeSignalFile('progress');
        } catch (ImportCancelledException $e) {
            $service->rollbackCreatedData();
            $this->deleteImportFile($import);
            $this->cleanSignals();
            $allErrors = $service->getAllErrors();
            $import->update([
                'status' => 'cancelled',
                'total_rows' => $service->getSuccessCount() + count($service->getFailedRows()),
                'processed_rows' => $service->getSuccessCount() + count($service->getFailedRows()),
                'success_rows' => $service->getSuccessCount(),
                'failed_rows' => count($service->getFailedRows()),
                'errors' => $allErrors,
            ]);

            \Illuminate\Support\Facades\Log::info('product.import.cancelled', [
                'operation_id' => $this->importId,
                'total_rows' => $service->getSuccessCount() + count($service->getFailedRows()),
            ]);

            $this->broadcastFileOperationTerminal(
                FileOperationEvent::PRODUCT_IMPORT_PROGRESS,
                'product-import',
                $this->importId,
                'cancelled',
                !empty($allErrors),
                [
                    'progress' => 100.0,
                    'total_rows' => $service->getSuccessCount() + count($service->getFailedRows()),
                    'processed_rows' => $service->getSuccessCount() + count($service->getFailedRows()),
                    'success_rows' => $service->getSuccessCount(),
                    'failed_rows' => count($service->getFailedRows()),
                ]
            );
        } catch (Throwable $e) {
            $sanitized = $this->sanitizeExceptionMessage($e);
            report($e);
            if ($this->attempts() >= $this->tries) {
                $import->update([
                    'status' => 'failed',
                    'errors' => [['sheet' => 'system', 'row' => 0, 'sku' => '', 'error_message' => $sanitized]],
                ]);
                $this->broadcastFileOperationTerminal(
                    FileOperationEvent::PRODUCT_IMPORT_PROGRESS,
                    'product-import',
                    $this->importId,
                    'failed',
                    true
                );
                $this->deleteImportFile($import);
                $this->removeSignalFile('progress');
            } else {
                $import->update([
                    'errors' => array_merge($import->errors ?? [], [
                        ['sheet' => 'system', 'row' => 0, 'sku' => '', 'error_message' => 'Attempt ' . $this->attempts() . ': ' . $sanitized],
                    ]),
                ]);
            }
            throw $e;
        }
    }

    protected function dispatchImageJobs(string $filePath, ProductImportService $service): void
    {
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($filePath);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($filePath);
            $sheet = $spreadsheet->getSheetByName('images');
            if (!$sheet) {
                $spreadsheet->disconnectWorksheets();
                return;
            }
            $highest = $sheet->getHighestDataRow();
            if ($highest < 2) {
                $spreadsheet->disconnectWorksheets();
                return;
            }
            $rows = [];
            $chunkSize = 500;
            for ($r = 2; $r <= $highest; $r++) {
                $sku = trim((string) $sheet->getCell('A' . $r)->getValue());
                $image = trim((string) $sheet->getCell('B' . $r)->getValue());
                if ($sku === '' || $image === '') {
                    continue;
                }
                $rows[] = ['product_sku' => $sku, 'image' => $image, 'row' => $r];
                if (count($rows) >= $chunkSize) {
                    ImportProductImagesJob::dispatch($this->importId, $rows);
                    $rows = [];
                }
            }
            if (!empty($rows)) {
                ImportProductImagesJob::dispatch($this->importId, $rows);
            }
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet, $reader);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected function countRows(): int
    {
        try {
            $import = Import::find($this->importId);
            if (!$import || empty($import->file_path)) {
                return 0;
            }
            $filePath = $this->resolveImportFilePath($import);
            if ($filePath === null || ! file_exists($filePath)) {
                return 0;
            }
            // Lightweight row count: read only dimensions without full style load
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($filePath);
            $reader->setReadDataOnly(true);
            // For product import, count only products sheet data rows (exclude header) to avoid inflated total
            // Fallback to single sheet if multi-sheet inspect fails
            try {
                $spreadsheet = $reader->load($filePath);
                $productsSheet = $spreadsheet->getSheetByName('products');
                if ($productsSheet) {
                    $total = max(0, $productsSheet->getHighestDataRow() - 1);
                } else {
                    // Fallback: first sheet
                    $sheet = $spreadsheet->getSheetByName($spreadsheet->getSheetNames()[0] ?? 'products');
                    $total = $sheet ? max(0, $sheet->getHighestDataRow() - 1) : 0;
                }
                $spreadsheet->disconnectWorksheets();
                unset($spreadsheet, $reader);
                return $total;
            } catch (Throwable $inner) {
                // Fallback to reading first sheet only
                unset($reader);
                return 0;
            }
        } catch (Throwable $e) {
            return 0;
        }
    }

    public function failed(Throwable $exception): void
    {
        $import = Import::find($this->importId);
        if ($import && $import->status === 'processing') {
            $import->update(['status' => 'failed']);
            $this->broadcastFileOperationTerminal(
                FileOperationEvent::PRODUCT_IMPORT_PROGRESS,
                'product-import',
                $this->importId,
                'failed',
                true
            );
            $this->deleteImportFile($import);
            $this->removeSignalFile('progress');
        }
    }
}
