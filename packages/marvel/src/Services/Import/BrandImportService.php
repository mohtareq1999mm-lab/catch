<?php

namespace Marvel\Services\Import;

use App\Events\FileOperationEvent;
use App\Support\OperationHeartbeat;
use App\Traits\BroadcastsFileOperationProgress;
use Illuminate\Support\Collection;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\Import;
use Marvel\Exceptions\ImportCancelledException;

/**
 * Facade for the brand import: the run lifecycle (prepare → upsert → attach),
 * the run outcome/rollback and the progress channel, with the deep row work
 * behind BrandRowWriter.
 */
class BrandImportService
{
    use BroadcastsFileOperationProgress;

    protected const FLUSH_THRESHOLD = 20;

    protected ?int $importId = null;

    protected RunOutcome $outcome;

    protected BrandRowWriter $rowWriter;

    protected int $processedCount = 0;

    protected float $currentProgress = 0.0;

    protected float $startedAt;

    protected int $lastTickProcessedCount = 0;

    protected float $lastTickTime;

    public function __construct(?int $importId = null)
    {
        $this->importId = $importId;
        $this->outcome = new RunOutcome(
            fn () => $this->rowWriter->failedRowCount(),
            fn () => $this->rowWriter->failedRows()
        );
        $this->rowWriter = new BrandRowWriter(
            $this->outcome,
            fn () => $this->flushProgressTick()
        );
        $this->startedAt = microtime(true);
        $this->lastTickTime = $this->startedAt;
    }

    public function processRows(Collection $rows): void
    {
        try {
            $pending = $this->rowWriter->prepareRows($rows);

            $this->writeExplicitProgress(10.0);

            if ($this->isCancelled()) {
                throw new ImportCancelledException();
            }

            $this->rowWriter->upsertBrands($pending);

            $this->writeExplicitProgress(80.0);

            if ($this->isCancelled()) {
                throw new ImportCancelledException();
            }

            $this->rowWriter->attachImages($pending);

            $this->writeExplicitProgress(99.0);
        } finally {
            $this->rowWriter->cleanupTempFiles($pending ?? []);
        }
    }

    public function rollbackCreatedData(): void
    {
        $ids = array_reverse($this->rowWriter->createdIds());

        foreach ($ids as $id) {
            $brand = Brand::withTrashed()->find($id);

            if ($brand && !$brand->trashed()) {
                $brand->delete();
            }
        }
    }

    public function getFailedRows(): array
    {
        return $this->rowWriter->failedRows();
    }

    public function getSuccessCount(): int
    {
        return $this->outcome->successCount();
    }

    public function outcome(): RunOutcome
    {
        return $this->outcome;
    }

    protected function heartbeat(): OperationHeartbeat
    {
        return OperationHeartbeat::for($this->importId ?? 0);
    }

    protected function writeSignal(string $signalType, array $data): void
    {
        if ($this->importId === null) {
            return;
        }

        $this->heartbeat()->write($signalType, $data);
    }

    protected function isCancelled(): bool
    {
        if ($this->importId === null) {
            return false;
        }

        return $this->heartbeat()->cancelRequested();
    }

    public function writeExplicitProgress(float $progress): void
    {
        $this->currentProgress = $progress;
        $this->lastTickProcessedCount = $this->outcome->successCount() + $this->outcome->failedCount();
        $this->lastTickTime = microtime(true);

        $this->publishProgress($progress);
    }

    protected function flushProgressTick(): void
    {
        $this->processedCount = $this->outcome->successCount() + $this->outcome->failedCount();

        if ($this->processedCount % self::FLUSH_THRESHOLD !== 0) {
            return;
        }

        $this->publishProgress($this->currentProgress);
    }

    public function finalizeProgress(): void
    {
        if ($this->importId === null) {
            return;
        }

        $this->processedCount = $this->outcome->successCount() + $this->outcome->failedCount();

        if ($this->isCancelled()) {
            throw new ImportCancelledException();
        }

        Import::where('id', $this->importId)->update([
            'processed_rows' => $this->processedCount,
            'success_rows' => $this->outcome->successCount(),
            'failed_rows' => $this->outcome->failedCount(),
        ]);

        $this->publishProgress(100.0);
    }

    protected function publishProgress(float $progress): void
    {
        $data = [
            'processed_rows' => $this->outcome->successCount() + $this->outcome->failedCount(),
            'success_rows' => $this->outcome->successCount(),
            'failed_rows' => $this->outcome->failedCount(),
            'progress' => $progress,
        ];

        $this->writeSignal('progress', $data);

        if ($this->importId === null) {
            return;
        }

        $this->broadcastFileOperationProgress(
            FileOperationEvent::BRAND_IMPORT_PROGRESS,
            'brand-import',
            (int) $this->importId,
            $progress,
            $this->outcome->successCount() + $this->outcome->failedCount(),
            $this->outcome->successCount(),
            $this->outcome->failedCount()
        );
    }
}
