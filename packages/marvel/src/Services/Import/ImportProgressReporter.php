<?php

namespace Marvel\Services\Import;

use App\Events\FileOperationEvent;
use App\Support\OperationHeartbeat;
use App\Traits\BroadcastsFileOperationProgress;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Import;
use Marvel\Enums\FileOperationType;
use Marvel\Exceptions\ImportCancelledException;

/**
 * The single progress module for the import family: smooth progress, the
 * processing signal, heartbeat reads/writes, cancellation detection and the
 * import-row progress persistence.
 *
 * One module, two protocol profiles, parameterized per family:
 *  - PROTOCOL_SMOOTH (product): window-based ticks, row-write persistence on
 *    the flush threshold, silent finalize (the deferred terminal write owns
 *    the final state).
 *  - PROTOCOL_THRESHOLD (category/brand): explicit milestones, broadcasts on
 *    TICK_THRESHOLD ticks between milestones keeping the milestone progress,
 *    finalize writes the three counter keys uncapped and publishes 100.
 *
 * The families differ only in the progress event and channel; the
 * BroadcastsFileOperationProgress trait owns the payload contract.
 */
final class ImportProgressReporter
{
    use BroadcastsFileOperationProgress;

    public const PROTOCOL_SMOOTH = 'smooth';

    public const PROTOCOL_THRESHOLD = 'threshold';

    /** Product protocol: row-write persistence cadence. */
    private const FLUSH_THRESHOLD = 10;

    /** Family protocol: broadcast cadence between milestones. */
    private const TICK_THRESHOLD = 20;

    private int $processedCount = 0;

    private float $startedAt;

    private float $lastTickTime;

    private int $lastTickProcessedCount = 0;

    private float $currentProgress = 0.0;

    public function __construct(
        private readonly RunOutcome $outcome,
        private readonly ?int $importId,
        private readonly string $progressEvent = FileOperationEvent::PRODUCT_IMPORT_PROGRESS,
        private readonly string $kind = FileOperationType::PRODUCT_IMPORT,
        private readonly string $protocol = self::PROTOCOL_SMOOTH,
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

        if ($this->protocol === self::PROTOCOL_THRESHOLD) {
            if ($this->processedCount % self::TICK_THRESHOLD !== 0) {
                return;
            }

            $this->publish($this->currentProgress);

            return;
        }

        $rowsSinceLastTick = $this->processedCount - $this->lastTickProcessedCount;
        $timeSinceLastTick = microtime(true) - $this->lastTickTime;

        if ($rowsSinceLastTick >= 10 || $timeSinceLastTick >= 30) {
            $this->currentProgress = $this->calculateSmoothProgress();
            $this->lastTickProcessedCount = $this->processedCount;
            $this->lastTickTime = microtime(true);

            $this->broadcastFileOperationProgress(
                $this->progressEvent,
                $this->kind,
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

        $this->publish($progress);
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

        if ($this->protocol === self::PROTOCOL_THRESHOLD) {
            // Family finalize: the three counter keys, uncapped — the
            // rowAttributes clamp and error cap are product-report semantics.
            Import::where('id', $this->importId)->update([
                'processed_rows' => $this->processedCount,
                'success_rows' => $this->outcome->successCount(),
                'failed_rows' => $this->outcome->failedCount(),
            ]);

            $this->publish(100.0);

            return;
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

    /**
     * Persist the progress snapshot to the signal file and broadcast it to
     * the importing user's private channel — the canonical payload owned by
     * BroadcastsFileOperationProgress.
     */
    protected function publish(float $progress): void
    {
        $this->writeSignal('progress', [
            'processed_rows' => $this->outcome->processed(),
            'success_rows' => $this->outcome->successCount(),
            'failed_rows' => $this->outcome->failedCount(),
            'progress' => $progress,
        ]);

        if ($this->importId === null) {
            return;
        }

        $this->broadcastFileOperationProgress(
            $this->progressEvent,
            $this->kind,
            (int) $this->importId,
            $progress,
            $this->outcome->processed(),
            $this->outcome->successCount(),
            $this->outcome->failedCount()
        );
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
