<?php

namespace Marvel\Jobs;

use App\Events\FileOperationEvent;
use App\Traits\BroadcastsFileOperationProgress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\Import;
use Marvel\Enums\FileOperationType;
use Marvel\Exports\ProductsExport;
use Throwable;

class ExportProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use BroadcastsFileOperationProgress;

    public int $tries = 2;

    public int $timeout = 1200;

    protected int $importId;

    protected array $filters;

    public function __construct(int|array $importId, array $filters = [])
    {
        if (is_array($importId)) {
            $filters = $importId;
            $importId = 0;
        }
        $this->importId = (int) $importId;
        $this->filters = $filters;
        $this->onQueue(config('queue.queues.medium'));
    }

    public function handle(): void
    {
        $exportOperation = Import::findOrFail($this->importId);

        $normalizedType = FileOperationType::normalize($exportOperation->type);
        if ($normalizedType !== FileOperationType::PRODUCT_EXPORT) {
            $sanitized = 'Invalid operation type for Product export: ' . ($exportOperation->type ?? 'null');
            report(new \RuntimeException($sanitized));
            if (! $exportOperation->isTerminal()) {
                $exportOperation->update(['status' => 'failed']);
                $this->broadcastFileOperationTerminal(
                    FileOperationEvent::PRODUCT_EXPORT_FAILED,
                    'product-export',
                    $this->importId,
                    'failed',
                    true
                );
            }
            return;
        }

        if (in_array($exportOperation->status, ['completed', 'completed_with_errors', 'failed', 'cancelled'], true)) {
            return;
        }

        // If filters not passed (retry from queue serialization may lose?), try cache
        $filters = $this->filters;
        if (empty($filters)) {
            $cached = Cache::store('file')->get('product-export:filters:' . $this->importId);
            if (is_array($cached)) {
                $filters = $cached;
            }
        }

        Import::where('id', $exportOperation->id)
            ->whereIn('status', ['pending', 'processing'])
            ->update([
                'status' => 'processing',
                'processed_rows' => 0,
                'success_rows' => 0,
                'failed_rows' => 0,
            ]);
        $exportOperation->refresh();
        if ($exportOperation->isTerminal() && $exportOperation->status !== 'processing') {
            return;
        }

        // Lifecycle: processing progress
        $this->broadcastFileOperationProgress(
            FileOperationEvent::PRODUCT_EXPORT_PROGRESS,
            'product-export',
            $this->importId,
            5.0,
            0,
            0,
            0,
            null,
            'processing',
            ['message' => 'Product export processing.', 'download_available' => false]
        );

        $filename = null;
        try {
            $export = new ProductsExport($filters);

            // Count via query to avoid loading all
            $rowCount = 0;
            try {
                $rowCount = $export->sheets()['products']->query()->count();
            } catch (Throwable $e) {
                $rowCount = 0;
            }

            $filename = 'products-export-' . $exportOperation->id . '-' . now()->format('Y-m-d-His') . '.xlsx';

            $export->store($filename, 'imports');

            $this->broadcastFileOperationProgress(
                FileOperationEvent::PRODUCT_EXPORT_PROGRESS,
                'product-export',
                $this->importId,
                90.0,
                $rowCount,
                0,
                0,
                $rowCount,
                'processing',
                ['message' => 'Product export file generated.', 'download_available' => false]
            );

            if (! Storage::disk('imports')->exists($filename)) {
                throw new \RuntimeException('Export file was not created');
            }
            // Phase 11: physical XLSX validation — non-zero, valid ZIP, contains XLSX structure
            $path = Storage::disk('imports')->path($filename);
            if (!is_file($path) || filesize($path) === 0) {
                throw new \RuntimeException('Export file is empty');
            }
            $zip = new \ZipArchive();
            $zipRes = $zip->open($path);
            if ($zipRes !== true) {
                throw new \RuntimeException('Export file is not a valid ZIP (XLSX) — ZipArchive open failed: ' . $zipRes);
            }
            if ($zip->locateName('[Content_Types].xml') === false || $zip->locateName('xl/workbook.xml') === false) {
                $zip->close();
                throw new \RuntimeException('Export file is not a valid XLSX package (missing [Content_Types].xml or xl/workbook.xml)');
            }
            $zip->close();

            $affected = Import::where('id', $exportOperation->id)
                ->whereIn('status', ['pending', 'processing'])
                ->update([
                    'status' => 'completed',
                    'file_path' => $filename,
                    'file_name' => $filename,
                    'total_rows' => $rowCount,
                    'processed_rows' => $rowCount,
                    'success_rows' => $rowCount,
                    'failed_rows' => 0,
                    'errors' => [],
                ]);
            if ($affected === 0) {
                $exportOperation->refresh();
                if ($exportOperation->isTerminal()) {
                    return;
                }
            } else {
                $exportOperation->refresh();
            }
            Cache::store('file')->forget('product-export:filters:' . $this->importId);

            $this->broadcastFileOperationTerminal(
                FileOperationEvent::PRODUCT_EXPORT_COMPLETED,
                'product-export',
                $this->importId,
                'completed',
                false,
                [
                    'progress' => 100.0,
                    'total_rows' => $rowCount,
                    'processed_rows' => $rowCount,
                    'success_rows' => $rowCount,
                    'failed_rows' => 0,
                    'download_available' => true,
                    'message' => 'Product export completed.',
                ]
            );
        } catch (Throwable $e) {
            report($e);
            if ($filename !== null) {
                try {
                    if (Storage::disk('imports')->exists($filename)) {
                        Storage::disk('imports')->delete($filename);
                    }
                } catch (Throwable $cleanup) {
                    report($cleanup);
                }
            }
            $exportOperation->update(['status' => 'failed']);
            try {
                $this->broadcastFileOperationTerminal(
                    FileOperationEvent::PRODUCT_EXPORT_FAILED,
                    'product-export',
                    $this->importId,
                    'failed',
                    true,
                    ['progress' => 100.0, 'download_available' => false]
                );
            } catch (Throwable $b) {
            }
            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        $exportOperation = Import::find($this->importId);
        if ($exportOperation && $exportOperation->status === 'processing') {
            $exportOperation->update(['status' => 'failed']);
            try {
                $this->broadcastFileOperationTerminal(
                    FileOperationEvent::PRODUCT_EXPORT_FAILED,
                    'product-export',
                    $this->importId,
                    'failed',
                    true,
                    ['progress' => 100.0, 'download_available' => false]
                );
            } catch (Throwable $e) {
            }
        }
    }
}
