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
        $this->onQueue(config('queue.queues.medium'));
    }

    public function handle(): void
    {
        $import = Import::findOrFail($this->importId);

        if (in_array($import->status, ['completed', 'completed_with_errors', 'failed', 'cancelled'], true)) {
            return;
        }

        $updated = Import::where('id', $this->importId)
            ->whereIn('status', ['pending', 'processing'])
            ->update([
                'status' => 'processing',
                'processed_rows' => 0,
                'success_rows' => 0,
                'failed_rows' => 0,
            ]);
        $import->refresh();
        if ($updated === 0 && $import->status !== 'processing') {
            if ($import->isTerminal()) {
                return;
            }
        }

        $this->broadcastFileOperationProgress(
            FileOperationEvent::CATEGORY_EXPORT_PROGRESS,
            'category-export',
            $this->importId,
            5.0,
            0,
            0,
            0,
            null,
            'processing',
            ['message' => 'Category export processing.', 'download_available' => false]
        );

        $filename = null;
        try {
            $export = new CategoriesExport();

            $rowCount = $export->collection()->count();

            $filename = 'categories-export-' . $this->importId . '-' . now()->format('Y-m-d-His') . '.xlsx';

            $export->store($filename, 'imports');

            $this->broadcastFileOperationProgress(
                FileOperationEvent::CATEGORY_EXPORT_PROGRESS,
                'category-export',
                $this->importId,
                90.0,
                $rowCount,
                0,
                0,
                $rowCount,
                'processing',
                ['message' => 'Category export file generated.', 'download_available' => false]
            );

            if (! \Illuminate\Support\Facades\Storage::disk('imports')->exists($filename)) {
                throw new \RuntimeException('Export file was not created');
            }
            $path = \Illuminate\Support\Facades\Storage::disk('imports')->path($filename);
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

            $affected = Import::where('id', $import->id)
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
                $import->refresh();
                if ($import->isTerminal()) {
                    return;
                }
            } else {
                $import->refresh();
            }

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
                    'download_available' => true,
                    'message' => 'Category export completed.',
                ]
            );
        } catch (Throwable $e) {
            if ($filename !== null) {
                try {
                    if (\Illuminate\Support\Facades\Storage::disk('imports')->exists($filename)) {
                        \Illuminate\Support\Facades\Storage::disk('imports')->delete($filename);
                    }
                } catch (Throwable $cleanup) {
                    report($cleanup);
                }
            }
            $import->update(['status' => 'failed']);

            $this->broadcastFileOperationTerminal(
                FileOperationEvent::CATEGORY_EXPORT_FAILED,
                'category-export',
                $this->importId,
                'failed',
                true,
                ['progress' => 100.0, 'download_available' => false]
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
                true,
                ['progress' => 100.0, 'download_available' => false]
            );
        }
    }
}