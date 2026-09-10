<?php

namespace Marvel\Jobs;

use App\Events\FileOperationEvent;
use App\Traits\BroadcastsFileOperationProgress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Marvel\Database\Models\Import;
use Marvel\Exports\CategoriesExport;
use Throwable;

class ExportCategoriesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use BroadcastsFileOperationProgress;

    public int $tries = 2;

    public int $timeout = 900;

    protected int $importId;

    public function __construct(int $importId)
    {
        $this->importId = $importId;
        $this->onQueue('meem-medium');
    }

    public function handle(): void
    {
        $import = Import::findOrFail($this->importId);

        if (in_array($import->status, ['completed', 'completed_with_errors', 'failed', 'cancelled'], true)) {
            return;
        }

        $import->update([
            'status' => 'processing',
            'processed_rows' => 0,
            'success_rows' => 0,
            'failed_rows' => 0,
        ]);

        try {
            $export = new CategoriesExport();

            $rowCount = $export->collection()->count();

            $filename = 'categories-export-' . now()->format('Y-m-d-His') . '.xlsx';

            $export->store($filename, 'imports');

            $import->update([
                'status' => 'completed',
                'file_path' => $filename,
                'file_name' => $filename,
                'total_rows' => $rowCount,
                'processed_rows' => $rowCount,
                'success_rows' => $rowCount,
                'failed_rows' => 0,
                'errors' => [],
            ]);

            $this->broadcastFileOperationTerminal(
                FileOperationEvent::CATEGORY_EXPORT_COMPLETED,
                'category-export',
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
            $import->update(['status' => 'failed']);

            $this->broadcastFileOperationTerminal(
                FileOperationEvent::CATEGORY_EXPORT_FAILED,
                'category-export',
                $this->importId,
                'failed',
                true
            );

            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        $import = Import::find($this->importId);

        if ($import && $import->status === 'processing') {
            $import->update(['status' => 'failed']);

            $this->broadcastFileOperationTerminal(
                FileOperationEvent::CATEGORY_EXPORT_FAILED,
                'category-export',
                $this->importId,
                'failed',
                true
            );
        }
    }
}