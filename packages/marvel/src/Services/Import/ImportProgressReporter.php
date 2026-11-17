<?php

namespace Marvel\Services\Import;

use App\Events\FileOperationEvent;
use App\Support\OperationHeartbeat;
use App\Traits\BroadcastsFileOperationProgress;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Import;
use Marvel\Exceptions\ImportCancelledException;

/**
 * Owns the run progress channel: smooth progress, the processing signal,
 * heartbeat reads/writes, cancellation detection and the import-row progress
 * persistence (single write shape owned by RunOutcome).
 */
final class ImportProgressReporter
{
    use BroadcastsFileOperationProgress;

    private const FLUSH_THRESHOLD = 10;

    private int $processedCount = 0;

    private float $startedAt;

    private float $lastTickTime;

    private int $lastTickProcessedCount = 0;

    private float $currentProgress = 0.0;

    public function __construct(
        private readonly RunOutcome $outcome,
        private readonly ?int $importId,
    ) {
        $now = microtime(true);
        $this->startedAt = $now;
        $this->lastTickTime = $now;
    }

    public function flush(): void
    {
        if ($this->importId === null) {
            return;
        }

        $this->processedCount = $this->outcome->processed();

        $rowsSinceLastTick = $this->processedCount - $this->lastTickProcessedCount;
        $timeSinceLastTick = microtime(true) - $this->lastTickTime;

        if ($rowsSinceLastTick >= 10 || $timeSinceLastTick >= 30) {
            $this->currentProgress = $this->calculateSmoothProgress();
            $this->lastTickProcessedCount = $this->processedCount;
            $this->lastTickTime = microtime(true);

            $this->broadcastFileOperationProgress(
                FileOperationEvent::PRODUCT_IMPORT_PROGRESS,
                'product-import',
                (int) $this->importId,
                $this->currentProgress,
                $this->processedCount,
                $this->outcome->successCount(),
                $this->outcome->failedCount()
            );
        }

        $this->writeSignal('progress', [
            'processed_rows' => $this->processedCount,
            'success_rows' => $this->outcome->successCount(),
            'failed_rows' => $this->outcome->failedCount(),
            'progress' => $this->currentProgress,
        ]);

        if ($this->isCancelled()) {
            $this->writeProgress(true);
            throw new ImportCancelledException();
        }

        if ($this->processedCount % self::FLUSH_THRESHOLD === 0) {
            $this->writeProgress();
        }
    }

    /**
     * Heartbeat for auxiliary sheets (variants/images): cancellation check
     * only — auxiliary work must NOT inflate product-row counters.
     */
    public function flushAux(): void
    {
        if ($this->importId === null) {
            return;
        }

        if ($this->isCancelled()) {
            $this->writeProgress(true);
            throw new ImportCancelledException();
        }
    }

    public function explicit(float $progress): void
    {
        if ($this->importId === null) {
            return;
        }

        $this->currentProgress = $progress;
        $this->lastTickProcessedCount = $this->outcome->processed();
        $this->lastTickTime = microtime(true);
        $this->writeSignal('progress', [
            'processed_rows' => $this->outcome->processed(),
            'success_rows' => $this->outcome->successCount(),
            'failed_rows' => $this->outcome->failedCount(),
            'progress' => $progress,
        ]);

        $this->broadcastFileOperationProgress(
            FileOperationEvent::PRODUCT_IMPORT_PROGRESS,
            'product-import',
            (int) $this->importId,
            $progress,
            $this->outcome->processed(),
            $this->outcome->successCount(),
            $this->outcome->failedCount()
        );
    }

    public function finalize(): void
    {
        if ($this->importId === null) {
            return;
        }

        $this->processedCount = $this->outcome->processed();

        if ($this->isCancelled()) {
            throw new ImportCancelledException();
        }

        Import::where('id', $this->importId)
            ->update($this->outcome->rowAttributes());
    }

    public function isCancelled(): bool
    {
        if ($this->importId === null) {
            return false;
        }

        return $this->heartbeat()->cancelRequested();
    }

    protected function writeProgress(bool $ignoreStatus = false): void
    {
        try {
            $query = Import::where('id', $this->importId);

            if (!$ignoreStatus) {
                $query->where('status', 'processing');
            }

            // Single write shape owned by RunOutcome (invariant + error cap).
            $query->update($this->outcome->rowAttributes());
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected function calculateSmoothProgress(): float
    {
        $processed = $this->outcome->processed();
        if ($this->outcome->totalRows() > 0) {
            // Honest progress: processed / total * 99, capped
            $real = ($processed / $this->outcome->totalRows()) * 99.0;
            return round(min(max($real, 0.0), 99.0), 2);
        }
        $elapsed = max(microtime(true) - $this->startedAt, 0);
        $timeBased = 99.0 * (1 - exp(-$elapsed / 60));
        $rowBased = 99.0 * (2 / M_PI) * atan($processed / 200);
        $progress = max($timeBased, $rowBased);
        return round(min($progress, 99.0), 2);
    }

    protected function heartbeat(): OperationHeartbeat
    {
        return OperationHeartbeat::for($this->importId ?? 0);
    }

    protected function writeSignal(string $type, array $data = []): void
    {
        if ($this->importId === null) {
            return;
        }

        $this->heartbeat()->write($type, $data);
    }

    protected function readSignal(string $type): ?array
    {
        if ($this->importId === null) {
            return null;
        }

        return $this->heartbeat()->read($type);
    }

    protected function removeSignal(string $type): void
    {
        if ($this->importId === null) {
            return;
        }

        $this->heartbeat()->remove($type);
    }
}
