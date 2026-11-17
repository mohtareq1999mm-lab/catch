<?php

namespace Marvel\Services\Import;

use Marvel\Enums\ImportStatus;

/**
 * One owner for the product import run outcome: counters, the
 * processed = success + failed invariant, status derivation, the single error
 * cap and the single import-row write shape.
 *
 * The invariant is clamped to the known total so auxiliary sheets can never
 * inflate product-row counters (4104→4913). The outcome is family-neutral:
 * each import family provides its live failed-count (and optional error list)
 * provider.
 */
final class RunOutcome
{
    public const ERROR_CAP = 1000;

    private int $totalRows = 0;

    private int $successCount = 0;

    /**
     * Family-neutral: the outcome doesn't own the error bucket — each family
     * provides its live failed-count (and optional error list) provider.
     */
    public function __construct(
        private readonly \Closure $failedCount,
        private readonly ?\Closure $errors = null,
    ) {
    }

    public function setTotalRows(int $total): void
    {
        $this->totalRows = max(0, $total);
    }

    /**
     * Rebuild the outcome from the counters persisted by the core phase, so a
     * deferred terminal write derives status, clamped counters and the error
     * shape through this module instead of re-implementing the rules.
     *
     * @param  array<int, array<string, mixed>>  $errors  Final persisted error set
     */
    public static function fromCounts(int $successCount, int $failedCount, int $totalRows, array $errors = []): self
    {
        $outcome = new self(
            static fn (): int => max(0, $failedCount),
            static fn (): array => $errors,
        );
        $outcome->successCount = max(0, $successCount);
        $outcome->setTotalRows($totalRows);

        return $outcome;
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
        return ($this->failedCount)();
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
     * product report, so they survive truncation. Families without an error
     * provider report no row-level errors.
     *
     * @return array<int, array<string, mixed>>
     */
    public function errors(): array
    {
        $provider = $this->errors;

        if ($provider === null) {
            return [];
        }

        return array_slice($provider(), 0, self::ERROR_CAP);
    }

    public function downloadAvailable(): bool
    {
        $provider = $this->errors;

        if ($provider === null) {
            return false;
        }

        return $provider() !== [];
    }

    /**
     * The single persistence shape for import-row counters and errors. The
     * errors key is present only for families that provide an error list.
     *
     * @return array<string, mixed>
     */
    public function rowAttributes(): array
    {
        $attributes = [
            'processed_rows' => $this->clampedProcessed(),
            'success_rows' => $this->clampedSuccess(),
            'failed_rows' => $this->clampedFailed(),
        ];

        if ($this->errors !== null) {
            $attributes['errors'] = $this->errors();
        }

        return $attributes;
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
