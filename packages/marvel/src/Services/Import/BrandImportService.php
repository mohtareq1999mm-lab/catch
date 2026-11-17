<?php

namespace Marvel\Services\Import;

use App\Events\FileOperationEvent;
use Illuminate\Support\Collection;
use Marvel\Database\Models\Brand;
use Marvel\Enums\FileOperationType;
use Marvel\Exceptions\ImportCancelledException;

/**
 * Facade for the brand import: the run lifecycle (prepare → upsert → attach),
 * the run outcome/rollback and the progress channel, with the deep row work
 * behind BrandRowWriter.
 */
class BrandImportService
{
    protected ?int $importId = null;

    protected RunOutcome $outcome;

    protected BrandRowWriter $rowWriter;

    /**
     * The one progress module: the brand family runs the threshold protocol
     * (explicit milestones, %-20 ticks, uncapped finalize + 100) through it.
     */
    protected ImportProgressReporter $progress;

    public function __construct(?int $importId = null)
    {
        $this->importId = $importId;
        // The outcome's providers capture $this->rowWriter lazily; the writer
        // is assigned below and the providers only fire after construction
        // completes, so this ordering is safe by contract.
        $this->outcome = new RunOutcome(
            fn () => $this->rowWriter->failedRowCount(),
            fn () => $this->rowWriter->failedRows()
        );
        $this->progress = new ImportProgressReporter(
            $this->outcome,
            $importId,
            FileOperationEvent::BRAND_IMPORT_PROGRESS,
            FileOperationType::BRAND_IMPORT,
            ImportProgressReporter::PROTOCOL_THRESHOLD,
        );
        $this->rowWriter = new BrandRowWriter(
            $this->outcome,
            fn () => $this->flushProgressTick()
        );
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

    public function writeExplicitProgress(float $progress): void
    {
        $this->progress->explicit($progress);
    }

    public function finalizeProgress(): void
    {
        $this->progress->finalize();
    }

    /**
     * Row-writer callback: the threshold protocol publishes on its own
     * cadence.
     */
    protected function flushProgressTick(): void
    {
        $this->progress->flush();
    }

    public function isCancelled(): bool
    {
        return $this->progress->isCancelled();
    }
}
