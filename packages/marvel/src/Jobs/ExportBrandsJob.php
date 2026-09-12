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
use Marvel\Enums\FileOperationType;
use Marvel\Exports\BrandsExport;
use Throwable;

class ExportBrandsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use BroadcastsFileOperationProgress;

    public int $tries = 2;

    public int $timeout = 900;

    protected int $importId;

    public function __construct(int $importId)
    {
        $this->importId = $importId;
        $this->onQueue('catch-medium');
    }

    public function handle(): void
    {
        $exportOperation = Import::findOrFail($this->importId);

        // Phase 7: validate operation type
        $normalizedType = FileOperationType::normalize($exportOperation->type);
        if ($normalizedType !== FileOperationType::BRAND_EXPORT) {
            $sanitized = 'Invalid operation type for Brand export: ' . ($exportOperation->type ?? 'null');
            report(new \RuntimeException($sanitized));

            if (! $exportOperation->isTerminal()) {
                $exportOperation->update(['status' => 'failed']);
                $this->broadcastFileOperationTerminal(
                    FileOperationEvent::BRAND_EXPORT_FAILED,
                    'brand-export',
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

        // Atomic transition to processing
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
            $export = new BrandsExport();

            // Use cached collection to avoid double query
            $rowCount = $export->collection()->count();

            // Phase 13: operation-specific filename to prevent collisions
            $filename = 'brands-export-' . $exportOperation->id . '-' . now()->format('Y-m-d-His') . '.xlsx';

            $export->store($filename, 'imports');

            // Verify file was actually created and is a valid XLSX package
            if (! Storage::disk('imports')->exists($filename)) {
                throw new \RuntimeException('Export file was not created');
            }
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

            $this->broadcastFileOperationTerminal(
                FileOperationEvent::BRAND_EXPORT_COMPLETED,
                'brand-export',
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

            // Phase 10: cleanup partial artifact
            if ($filename !== null) {
                try {
                    if (Storage::disk('imports')->exists($filename)) {
                        Storage::disk('imports')->delete($filename);
                    }
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            $exportOperation->update(['status' => 'failed']);

            $this->broadcastFileOperationTerminal(
                FileOperationEvent::BRAND_EXPORT_FAILED,
                'brand-export',
                $this->importId,
                'failed',
                true
            );

            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        $exportOperation = Import::find($this->importId);

        if ($exportOperation && $exportOperation->status === 'processing') {
            $exportOperation->update(['status' => 'failed']);

            $this->broadcastFileOperationTerminal(
                FileOperationEvent::BRAND_EXPORT_FAILED,
                'brand-export',
                $this->importId,
                'failed',
                true
            );
        }
    }
}
