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

    public int $timeout = 900;

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
        $this->onQueue('meem-medium');
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
            $cached = Cache::get('product-export:filters:' . $this->importId);
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

            if (! Storage::disk('imports')->exists($filename)) {
                throw new \RuntimeException('Export file was not created');
            }

            $exportOperation->update([
                'status' => 'completed',
                'file_path' => $filename,
                'file_name' => $filename,
                'total_rows' => $rowCount,
                'processed_rows' => $rowCount,
                'success_rows' => $rowCount,
                'failed_rows' => 0,
                'errors' => [],
            ]);

            Cache::forget('product-export:filters:' . $this->importId);

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
                    true
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
                    true
                );
            } catch (Throwable $e) {
            }
        }
    }
}
