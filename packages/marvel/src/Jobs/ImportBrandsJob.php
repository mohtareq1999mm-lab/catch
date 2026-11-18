<?php

namespace Marvel\Jobs;

use App\Enums\FrontendResource;
use App\Events\FileOperationEvent;
use App\Services\General\HomeService;
use App\Traits\BroadcastsFileOperationProgress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Facades\Excel;
use Marvel\Database\Models\Import;
use Marvel\Enums\FileOperationType;
use Marvel\Exceptions\ImportCancelledException;
use Marvel\Imports\BrandsImport;
use Marvel\Services\Import\BrandImportService;
use Marvel\Services\Import\FinalizesImportOutcome;
use Marvel\Services\Import\ImportRunLifecycle;
use Throwable;

class ImportBrandsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use BroadcastsFileOperationProgress;
    use FinalizesImportOutcome;
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
        $import = Import::select(['id', 'type', 'status', 'file_path', 'file_name'])->findOrFail($this->importId);

        // Phase 7: Job-level operation type validation
        $normalizedType = FileOperationType::normalize($import->type);
        if ($normalizedType !== FileOperationType::BRAND_IMPORT) {
            $sanitized = 'Invalid operation type for Brand import: ' . ($import->type ?? 'null');
            report(new \RuntimeException($sanitized . ' (expected ' . FileOperationType::BRAND_IMPORT . ')'));

            if (! $import->isTerminal()) {
                $import->update([
                    'status' => 'failed',
                    'errors' => [[
                        'sheet' => 'system',
                        'row' => 0,
                        'name_en' => '',
                        'name_ar' => '',
                        'error_message' => $sanitized,
                    ]],
                ]);

                $this->broadcastFileOperationTerminal(
                    FileOperationEvent::BRAND_IMPORT_FAILED,
                    'brand-import',
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
                    'error_message' => 'Import file not found',
                ]],
            ]);

            $this->broadcastBrandImportTerminal('failed', true);

            return;
        }

        $service = new BrandImportService($this->importId);
        $service->writeExplicitProgress(1.0);

        $totalRows = $this->countRows();

        if ($import->total_rows !== $totalRows) {
            $import->update(['total_rows' => $totalRows]);
        }

        $service->writeExplicitProgress(2.0);

        try {
            $importObj = new BrandsImport($service);

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

            $outcome = $service->outcome();

            // Terminal write owned by RunOutcome; the shared orchestration
            // (guarded write, terminal check, caches, broadcast) lives on
            // FinalizesImportOutcome.
            $alreadyTerminal = $this->finalizeSuccessTerminal(
                $import,
                $outcome,
                $this->broadcastBrandImportTerminal(...)
            );
            if ($alreadyTerminal) {
                return;
            }

            $this->deleteImportFile($import);
            $this->removeSignalFile('progress');
        } catch (ImportCancelledException $e) {
            $service->rollbackCreatedData();
            $this->deleteImportFile($import);
            $this->cleanSignals();

            $this->writeCancelledTerminal(
                $import,
                $service->outcome(),
                $service->getFailedRows(),
                $this->broadcastBrandImportTerminal(...)
            );
        } catch (Throwable $e) {
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

                if ($service->getSuccessCount() > 0) {
                    $this->invalidateFrontendCaches();
                }

                $this->broadcastBrandImportTerminal('failed', true);

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
                        'error_message' => 'Attempt ' . $this->attempts() . ': ' . $sanitized,
                    ]]),
                ]);
            }

            throw $e;
        }
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

    protected function broadcastBrandImportTerminal(string $status, bool $hasErrors, array $extraPayload = []): void
    {
        $event = match ($status) {
            'completed', 'completed_with_errors' => FileOperationEvent::BRAND_IMPORT_COMPLETED,
            'failed' => FileOperationEvent::BRAND_IMPORT_FAILED,
            'cancelled' => FileOperationEvent::BRAND_IMPORT_CANCELLED,
            'cancelling' => FileOperationEvent::BRAND_IMPORT_CANCELLING,
            default => FileOperationEvent::BRAND_IMPORT_PROGRESS,
        };
        $base = ['download_available' => $hasErrors];
        if (!array_key_exists('progress', $extraPayload) && in_array($status, ['completed', 'completed_with_errors', 'failed', 'cancelled'], true)) {
            $base['progress'] = 100.0;
        }
        $this->broadcastFileOperationTerminal($event, 'brand-import', $this->importId, $status, $hasErrors, array_merge($base, $extraPayload));
    }

    public function failed(Throwable $exception): void
    {
        $import = Import::find($this->importId);

        if ($import && $import->status === 'processing') {
            $import->update(['status' => 'failed']);

            if ((int) ($import->success_rows ?? 0) > 0) {
                $this->invalidateFrontendCaches();
            }

            $this->broadcastBrandImportTerminal('failed', true);

            $this->deleteImportFile($import);
            $this->removeSignalFile('progress');
        }
    }

    protected function invalidateFrontendCaches(): void
    {
        try {
            $needsFlush = false;
            foreach ([FrontendResource::BRANDS->value, FrontendResource::BRANDS_PRODUCTS->value, FrontendResource::PRODUCTS->value] as $tag) {
                try {
                    Cache::tags([$tag])->flush();
                    if (! Cache::getStore() instanceof \Illuminate\Cache\TaggableStore) { $needsFlush = true; }
                } catch (\BadMethodCallException) { $needsFlush = true; }
            }
            if ($needsFlush) Cache::flush();
            HomeService::clearCache();
            Cache::increment('api_cache_version');
        } catch (\Throwable $e) { report($e); }
    }
}
