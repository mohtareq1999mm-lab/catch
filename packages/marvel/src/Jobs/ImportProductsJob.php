<?php

namespace Marvel\Jobs;

use App\Events\FileOperationEvent;
use App\Audit\ActivityAuditService;
use App\Support\ProductTypeSchema;
use App\Traits\BroadcastsFileOperationProgress;
use App\Enums\FrontendResource;
use App\Services\General\HomeService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Marvel\Database\Models\Import;
use Marvel\Enums\ImportStatus;
use Marvel\Exceptions\ImportCancelledException;
use Illuminate\Support\Facades\Bus;
use Marvel\Imports\ImagesSheetReader;
use Marvel\Imports\ProductsImport;
use Marvel\Workbook\ProductWorkbookSchema;
use Marvel\Services\Import\ImportRunLifecycle;
use Marvel\Services\Import\ProductImportService;
use Throwable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Maatwebsite\Excel\Facades\Excel;

class ImportProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use BroadcastsFileOperationProgress;
    use ImportRunLifecycle;

    public int $tries = 3;

    public int $timeout = 1200;

    public array $backoff = [60, 120, 240];

    protected int $importId;

    public function __construct(int $importId)
    {
        $this->importId = $importId;
        $this->onQueue(config('queue.queues.medium'));
    }

    public function handle(): void
    {
        $import = Import::select(['id', 'type', 'status', 'file_path', 'file_name', 'created_by'])->findOrFail($this->importId);
        $batchUuid = (string) Str::uuid();

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
                    FileOperationEvent::PRODUCT_IMPORT_FAILED,
                    'product-import',
                    $this->importId,
                    'failed',
                    true,
                    ['progress' => 100.0, 'download_available' => false]
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
            'run_uuid' => $batchUuid,
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

        $this->recordImportStarted($import, $batchUuid);

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
                FileOperationEvent::PRODUCT_IMPORT_FAILED,
                'product-import',
                $this->importId,
                'failed',
                true,
                ['progress' => 100.0, 'download_available' => false]
            );
            return;
        }

        $service = new ProductImportService($this->importId);
        $service->writeExplicitProgress(1.0);

        // Guard the product_type ENUM before any row is written: non-strict
        // MySQL stores '' instead of failing, which corrupts the catalog
        // silently. Fail once, with an actionable system error.
        if ($this->failForIncompatibleProductTypeSchema($import, $batchUuid)) {
            return;
        }

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
            $outcome = $service->outcome();
            $import->update($outcome->rowAttributes());

            // Invalidate frontend caches if any product rows were mutated (even if partially succeeded)
            if ($outcome->successCount() > 0 || $service->getVariantSuccessCount() > 0) {
                $this->invalidateFrontendCaches();
            }

            // Phase 2: image processing in bounded async chunks. The terminal
            // write is deferred until every chunk has merged its errors, so the
            // final report and download_available are complete (stories 14/15/24).
            $service->writeExplicitProgress(99.0);

            // Core-phase diagnostics: the deferred terminal write happens on
            // another job, so log the variant/image bookkeeping here where the
            // service state still exists.
            \Illuminate\Support\Facades\Log::info('product.import.core', [
                'operation_id' => $this->importId,
                'total_rows' => $outcome->totalRows(),
                'processed_rows' => $outcome->processed(),
                'successful_rows' => $outcome->successCount(),
                'failed_rows' => $outcome->failedCount(),
                'variant_success' => $service->getVariantSuccessCount(),
                'variant_failed' => count($service->getVariantErrors()),
                'image_failed' => count($service->getImageErrors()),
            ]);

            $imageJobs = $this->imageJobs($filePath);
            $successRows = $outcome->successCount();
            $failedRows = $outcome->failedCount();
            $totalRows = $outcome->totalRows();

            if ($imageJobs === []) {
                self::dispatchFinalizer(
                    $this->importId,
                    $batchUuid,
                    $successRows,
                    $failedRows,
                    $totalRows,
                );

                return;
            }

            $importId = $this->importId;

            Bus::batch($imageJobs)
                ->onQueue(config('queue.queues.medium'))
                ->allowFailures()
                ->finally(static function ($batch, $e = null) use ($importId, $batchUuid, $successRows, $failedRows, $totalRows): void {
                    self::dispatchFinalizer(
                        $importId,
                        $batchUuid,
                        $successRows,
                        $failedRows,
                        $totalRows,
                        $batch->failedJobs > 0,
                    );
                })
                ->dispatch();
        } catch (ImportCancelledException $e) {
            $service->rollbackCreatedData();
            $this->deleteImportFile($import);
            $this->cleanSignals();
            $allErrors = $service->getAllErrors();
            $cancelledTotal = $service->outcome()->processed();
            $import->update([
                'status' => 'cancelled',
                'total_rows' => $cancelledTotal,
                'processed_rows' => $cancelledTotal,
                'success_rows' => $service->getSuccessCount(),
                'failed_rows' => count($service->getFailedRows()),
                'errors' => $allErrors,
            ]);

            \Illuminate\Support\Facades\Log::info('product.import.cancelled', [
                'operation_id' => $this->importId,
                'total_rows' => $cancelledTotal,
            ]);

            $this->recordImportRollback($import, $batchUuid);

            if ($service->getSuccessCount() > 0 || $service->getVariantSuccessCount() > 0) {
                $this->invalidateFrontendCaches();
            }

            $this->broadcastFileOperationTerminal(
                FileOperationEvent::PRODUCT_IMPORT_CANCELLED,
                'product-import',
                $this->importId,
                'cancelled',
                !empty($allErrors),
                [
                    'progress' => 100.0,
                    'download_available' => false,
                    'total_rows' => $cancelledTotal,
                    'processed_rows' => $cancelledTotal,
                    'success_rows' => $service->getSuccessCount(),
                    'failed_rows' => count($service->getFailedRows()),
                ]
            );
        } catch (Throwable $e) {
            $sanitized = $this->sanitizeExceptionMessage($e);
            report($e);
            if ($this->attempts() >= $this->tries) {
                $import->update([
                    'status' => ImportStatus::FAILED,
                    'errors' => [['sheet' => 'system', 'row' => 0, 'sku' => '', 'error_message' => $sanitized]],
                ]);
                if ($service->getSuccessCount() > 0 || $service->getVariantSuccessCount() > 0) {
                    $this->invalidateFrontendCaches();
                }
                $this->broadcastFileOperationTerminal(
                    FileOperationEvent::PRODUCT_IMPORT_FAILED,
                    'product-import',
                    $this->importId,
                    'failed',
                    true,
                    ['progress' => 100.0, 'download_available' => false]
                );
                $this->deleteImportFile($import);
                $this->removeSignalFile('progress');
                $this->recordImportFailed($import, $batchUuid);
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

    /**
     * @return bool true when the import was failed and must not continue
     */
    protected function failForIncompatibleProductTypeSchema(Import $import, string $batchUuid): bool
    {
        $reason = app(ProductTypeSchema::class)->incompatibilityReason();

        if ($reason === null) {
            return false;
        }

        // A schema failure does not invalidate the workbook: keep it so the
        // admin can retry after repairing the column (retry endpoint).
        $this->failImportWithSystemError($import, $reason, $batchUuid, keepFile: true);

        return true;
    }

    protected function failImportWithSystemError(Import $import, string $message, string $batchUuid, bool $keepFile = false): void
    {
        $import->update([
            'status' => ImportStatus::FAILED,
            'errors' => [[
                'sheet' => 'system',
                'row' => 0,
                'sku' => '',
                'error_message' => $message,
            ]],
        ]);

        $this->broadcastFileOperationTerminal(
            FileOperationEvent::PRODUCT_IMPORT_FAILED,
            'product-import',
            $this->importId,
            'failed',
            true,
            ['progress' => 100.0, 'download_available' => false]
        );

        if (!$keepFile) {
            $this->deleteImportFile($import);
        }

        $this->removeSignalFile('progress');
        $this->recordImportFailed($import, $batchUuid);
    }

    /**
     * The batch callback and the no-chunks path share one dispatch shape.
     *
     * @param  bool  $chunksFailed  true when at least one image chunk
     *                              exhausted its retries; the finalizer
     *                              must keep the report honest about it.
     */
    protected static function dispatchFinalizer(int $importId, string $batchUuid, int $successRows, int $failedRows, int $totalRows, bool $chunksFailed = false): void
    {
        FinalizeProductImportJob::dispatch(
            $importId,
            $batchUuid,
            $successRows,
            $failedRows,
            $totalRows,
            $chunksFailed,
        );
    }

    /**
     * @return array<int, ImportProductImagesJob>
     */
    protected function imageJobs(string $filePath): array
    {
        try {
            $rows = (new ImagesSheetReader())->rows($filePath);

            if ($rows === []) {
                return [];
            }

            $chunkSize = ProductWorkbookSchema::dispatchChunkSize(ProductWorkbookSchema::IMAGES);

            return array_map(
                fn (array $chunk): ImportProductImagesJob => new ImportProductImagesJob($this->importId, $chunk),
                array_chunk($rows, $chunkSize)
            );
        } catch (\Throwable $e) {
            report($e);

            return [];
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
                $productsSheet = $spreadsheet->getSheetByName(ProductWorkbookSchema::title(ProductWorkbookSchema::PRODUCTS));
                if ($productsSheet) {
                    $total = max(0, $productsSheet->getHighestDataRow() - 1);
                } else {
                    // Fallback: first sheet
                    $sheet = $spreadsheet->getSheetByName($spreadsheet->getSheetNames()[0] ?? ProductWorkbookSchema::title(ProductWorkbookSchema::PRODUCTS));
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
            if ((int) ($import->success_rows ?? 0) > 0) {
                $this->invalidateFrontendCaches();
            }
            $this->broadcastFileOperationTerminal(
                FileOperationEvent::PRODUCT_IMPORT_FAILED,
                'product-import',
                $this->importId,
                'failed',
                true,
                ['progress' => 100.0, 'download_available' => false]
            );
            $this->deleteImportFile($import);
            $this->removeSignalFile('cancel');
            $this->removeSignalFile('progress');
        }
    }

    protected function invalidateFrontendCaches(): void
    {
        try {
            $needsFlush = false;
            $tags = [FrontendResource::PRODUCTS->value];
            try {
                $resolver = app(\App\Services\General\ProductEngine\ProductStrategyResolver::class);
                foreach ($resolver->supportedTypes() as $type) {
                    $tags[] = FrontendResource::PRODUCTS->value . '_' . $type;
                }
            } catch (\Throwable $e) {}
            $tags = array_merge($tags, [FrontendResource::CATEGORIES->value, FrontendResource::BRANDS->value, FrontendResource::BRANDS_PRODUCTS->value]);
            foreach ($tags as $tag) {
                try {
                    Cache::tags([$tag])->flush();
                    if (! Cache::getStore() instanceof \Illuminate\Cache\TaggableStore) {
                        $needsFlush = true;
                    }
                } catch (\BadMethodCallException) {
                    $needsFlush = true;
                }
            }
            if ($needsFlush) {
                Cache::flush();
            }
            HomeService::clearCache();
            Cache::increment('api_cache_version');
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected function recordImportStarted(Import $import, string $batchUuid): void
    {
        ActivityAuditService::recordBatch(
            'imports',
            'import_started',
            __('activity.import_started'),
            context: ['source' => 'import', 'import_id' => $import->id, 'batch_uuid' => $batchUuid],
            properties: ['import_id' => $import->id],
            batchUuid: $batchUuid,
            causerId: $import->created_by,
            causerType: \Marvel\Database\Models\User::class,
        );
    }

    protected function recordImportRollback(Import $import, string $batchUuid): void
    {
        ActivityAuditService::recordBatch(
            'imports',
            'rollback',
            __('activity.import_rolled_back'),
            context: ['source' => 'import', 'import_id' => $import->id, 'batch_uuid' => $batchUuid],
            properties: ['import_id' => $import->id],
            batchUuid: $batchUuid,
            reason: 'import_rollback',
            causerId: $import->created_by,
            causerType: \Marvel\Database\Models\User::class,
        );
    }

    protected function recordImportFailed(Import $import, string $batchUuid): void
    {
        ActivityAuditService::recordBatch(
            'imports',
            'import_failed',
            __('activity.import_failed'),
            context: ['source' => 'import', 'import_id' => $import->id, 'batch_uuid' => $batchUuid],
            properties: ['import_id' => $import->id],
            batchUuid: $batchUuid,
            causerId: $import->created_by,
            causerType: \Marvel\Database\Models\User::class,
        );
    }
}
