<?php

namespace Marvel\Jobs;

use App\Audit\ActivityAuditService;
use App\Events\FileOperationEvent;
use App\Support\OperationHeartbeat;
use App\Traits\BroadcastsFileOperationProgress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Import;
use Marvel\Enums\FileOperationType;
use Marvel\Enums\ImportStatus;
use Marvel\Services\Import\DeletesImportFile;
use Marvel\Services\Import\ImportReport;
use Marvel\Services\Import\RunOutcome;

/**
 * Deferred terminal writer for the product import's image-batch path.
 *
 * Image work runs in parallel queue chunks after the core product phase. A
 * terminal row written before those chunks finish loses their errors and
 * promises the wrong download availability (stories 14/15/24). This job is
 * dispatched by the batch only after every chunk has finished, so the status,
 * counters and report availability derive from the complete error set. The
 * other terminal paths (cancel, failure, early guards) remain on
 * ImportProductsJob; a cancel landing during the image phase is honoured here
 * instead of being overwritten by a completion.
 */
class FinalizeProductImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use BroadcastsFileOperationProgress;
    use DeletesImportFile;

    public int $tries = 3;

    public int $timeout = 300;

    public array $backoff = [30, 60, 120];

    public function __construct(
        protected int $importId,
        protected string $batchUuid,
        protected int $successRows,
        protected int $failedRows,
        protected int $totalRows,
        protected bool $chunksFailed = false,
    ) {
        $this->onQueue(config('queue.queues.medium'));
    }

    public function handle(): void
    {
        $import = Import::select([
            'id',
            'status',
            'run_uuid',
            'file_path',
            'created_by',
            'errors',
            'total_rows',
            'processed_rows',
            'success_rows',
            'failed_rows',
        ])->find($this->importId);

        if (! $import) {
            return;
        }

        // A cancel request wins over the success terminal: it may land while
        // the image chunks are still running, before the cancel endpoint has
        // flipped the row.
        if ($import->status === ImportStatus::CANCELLED || OperationHeartbeat::for($this->importId)->cancelRequested()) {
            $this->cancel($import);

            return;
        }

        if ($import->isTerminal()) {
            return;
        }

        $errors = ImportReport::mergePersisted($import->errors ?? [], []);

        // A chunk may have exhausted its retries without merging; the report
        // must say so instead of claiming completeness (stories 14/24).
        if ($this->chunksFailed) {
            array_unshift($errors, [
                'sheet' => 'system',
                'row' => 0,
                'sku' => '',
                'error_message' => __('message.IMPORT.PRODUCT.IMAGE_CHUNKS_FAILED'),
            ]);
        }

        $outcome = RunOutcome::fromCounts($this->successRows, $this->failedRows, $this->totalRows, $errors);

        // The finalizer belongs to one attempt: a retry stamps a new run_uuid
        // on the row, so a stale finalizer from a previous attempt (even one
        // whose counters match) cannot terminalize the new run.
        if ($import->run_uuid !== $this->batchUuid) {
            return;
        }

        $status = $outcome->status();
        $downloadAvailable = $outcome->downloadAvailable();

        $affected = Import::where('id', $import->id)
            ->whereIn('status', [ImportStatus::PENDING, ImportStatus::PROCESSING, ImportStatus::CANCELLING])
            ->update([
                'status' => $status,
                'total_rows' => $outcome->terminalTotalRows(),
                ...$outcome->rowAttributes(),
            ]);

        $import->refresh();

        if ($affected === 0) {
            if ($import->status === ImportStatus::CANCELLED) {
                $this->cancel($import);
            }

            return;
        }

        Log::info('product.import.' . $status, [
            'operation_id' => $this->importId,
            'total_rows' => $outcome->terminalTotalRows(),
            'processed_rows' => $outcome->processed(),
            'successful_rows' => $outcome->successCount(),
            'failed_rows' => $outcome->failedCount(),
            'status' => $status,
        ]);

        $this->recordImportTerminal($import, $status, $this->batchUuid, $outcome->successCount(), $outcome->failedCount());

        $this->broadcastFileOperationTerminal(
            $status === ImportStatus::FAILED
                ? FileOperationEvent::PRODUCT_IMPORT_FAILED
                : FileOperationEvent::PRODUCT_IMPORT_COMPLETED,
            FileOperationType::PRODUCT_IMPORT,
            $this->importId,
            $status,
            $downloadAvailable,
            [
                'progress' => 100.0,
                'total_rows' => $outcome->terminalTotalRows(),
                'processed_rows' => $outcome->processed(),
                'success_rows' => $outcome->successCount(),
                'failed_rows' => $outcome->failedCount(),
            ]
        );

        $this->deleteImportFile($import);
        OperationHeartbeat::for($this->importId)->remove('progress');
    }

    /**
     * Cleanup is idempotent and only announced by whoever performs the
     * transition: when the cancel endpoint already flipped the row, it has
     * already broadcast the cancellation.
     */
    protected function cancel(Import $import): void
    {
        $affected = Import::where('id', $import->id)
            ->whereIn('status', [ImportStatus::PENDING, ImportStatus::PROCESSING, ImportStatus::CANCELLING])
            ->update(['status' => ImportStatus::CANCELLED]);

        $import->refresh();

        $this->deleteImportFile($import);

        $heartbeat = OperationHeartbeat::for($this->importId);
        $heartbeat->remove('cancel');
        $heartbeat->remove('progress');

        if ($affected === 0) {
            return;
        }

        $this->broadcastFileOperationTerminal(
            FileOperationEvent::PRODUCT_IMPORT_CANCELLED,
            FileOperationType::PRODUCT_IMPORT,
            $this->importId,
            ImportStatus::CANCELLED,
            false,
            ['progress' => 100.0, 'download_available' => false]
        );
    }

    protected function recordImportTerminal(Import $import, string $status, string $batchUuid, int $successCount, int $failedCount): void
    {
        $failed = $status === ImportStatus::FAILED;
        $event = $failed ? 'import_failed' : 'import_completed';
        $description = $failed ? __('activity.import_failed') : __('activity.import_completed');

        ActivityAuditService::recordBatch(
            'imports',
            $event,
            $description,
            context: ['source' => 'import', 'import_id' => $import->id, 'batch_uuid' => $batchUuid],
            properties: [
                'import_id' => $import->id,
                'success_rows' => $successCount,
                'failed_rows' => $failedCount,
                'status' => $status,
            ],
            batchUuid: $batchUuid,
            causerId: $import->created_by,
            causerType: \Marvel\Database\Models\User::class,
        );
    }
}
