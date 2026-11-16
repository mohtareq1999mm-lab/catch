<?php

namespace Marvel\Services\Import;

use Marvel\Enums\ImportStatus;

/**
 * One owner for the product import run outcome: counters, the
 * processed = success + failed invariant, status derivation, the single error
 * cap and the single import-row write shape.
 *
 * The invariant is clamped to the known total so auxiliary sheets can never
 * inflate product-row counters (4104→4913).
 */
final class RunOutcome
{
    public const ERROR_CAP = 1000;

    private int $totalRows = 0;

    private int $successCount = 0;

    public function __construct(private readonly ImportReport $report)
    {
    }

    public function setTotalRows(int $total): void
    {
        $this->totalRows = max(0, $total);
    }

    public function totalRows(): int
    {
        return $this->totalRows;
    }

    public function recordSuccess(): void
    {
        $this->successCount++;
    }

    public function successCount(): int
    {
        return $this->successCount;
    }

    public function failedCount(): int
    {
        return $this->report->failedRowCount();
    }

    /**
     * Un-clamped product work; never include auxiliary sheets.
     */
    public function processed(): int
    {
        return $this->successCount + $this->failedCount();
    }

    /**
     * The invariant: processed = success + failed, clamped to the known total.
     */
    public function clampedProcessed(): int
    {
        return $this->totalRows > 0
            ? min($this->processed(), $this->totalRows)
            : $this->processed();
    }

    public function clampedSuccess(): int
    {
        return $this->totalRows > 0
            ? min($this->successCount, $this->totalRows)
            : $this->successCount;
    }

    public function clampedFailed(): int
    {
        return $this->totalRows > 0
            ? min($this->failedCount(), $this->totalRows)
            : $this->failedCount();
    }

    /**
     * Terminal status: failed when nothing succeeded, partial when some rows
     * failed, completed otherwise.
     */
    public function status(): string
    {
        $failed = $this->failedCount();

        if ($failed > 0 && $this->successCount > 0) {
            return 'completed_with_errors';
        }

        if ($this->successCount === 0 && $failed > 0) {
            return ImportStatus::FAILED;
        }

        return 'completed';
    }

    /**
     * The single error cap: aggregated warnings are emitted first by the
     * report, so they survive truncation.
     *
     * @return array<int, array<string, mixed>>
     */
    public function errors(): array
    {
        return array_slice($this->report->all(), 0, self::ERROR_CAP);
    }

    public function downloadAvailable(): bool
    {
        return $this->report->all() !== [];
    }

    /**
     * The single persistence shape for import-row counters and errors.
     *
     * @return array<string, mixed>
     */
    public function rowAttributes(): array
    {
        return [
            'processed_rows' => $this->clampedProcessed(),
            'success_rows' => $this->clampedSuccess(),
            'failed_rows' => $this->clampedFailed(),
            'errors' => $this->errors(),
        ];
    }

    /**
     * Terminal total: actual product work, falling back to the known total
     * when the file had no processable rows.
     */
    public function terminalTotalRows(): int
    {
        if ($this->processed() === 0 && $this->totalRows > 0) {
            return $this->totalRows;
        }

        return $this->processed();
    }
}
