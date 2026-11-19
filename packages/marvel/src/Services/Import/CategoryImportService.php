<?php

namespace Marvel\Services\Import;

use App\Events\FileOperationEvent;
use App\Services\General\CategoryHierarchyService;
use Illuminate\Support\Collection;
use Marvel\Database\Models\Category;
use Marvel\Enums\FileOperationType;
use Marvel\Exceptions\ImportCancelledException;

/**
 * Facade for the category import: the run lifecycle (prepare → upsert →
 * link hierarchy → attach), the run outcome/rollback and the progress
 * channel, with the deep row work behind CategoryRowWriter and the
 * hierarchy rules behind CategoryHierarchyLinker.
 */
class CategoryImportService
{
    protected ?int $importId = null;

    protected ?int $broadcastUserId = null;

    protected RunOutcome $outcome;

    protected CategoryRowWriter $rowWriter;

    protected CategoryHierarchyLinker $hierarchyLinker;

    /**
     * The one progress module: the category family runs the threshold
     * protocol (explicit milestones, %-20 ticks, uncapped finalize + 100)
     * through it.
     */
    protected ImportProgressReporter $progress;

    public function __construct(?int $importId = null)
    {
        $this->importId = $importId;
        $this->outcome = new RunOutcome(
            fn () => $this->rowWriter->failedRowCount(),
            fn () => $this->rowWriter->failedRows()
        );
        $this->progress = new ImportProgressReporter(
            $this->outcome,
            $importId,
            FileOperationEvent::CATEGORY_IMPORT_PROGRESS,
            FileOperationType::CATEGORY_IMPORT,
            ImportProgressReporter::PROTOCOL_THRESHOLD,
        );
        $onRowProcessed = fn () => $this->flushProgressTick();
        $this->rowWriter = new CategoryRowWriter($this->outcome, $onRowProcessed);
        $this->hierarchyLinker = new CategoryHierarchyLinker(
            $this->rowWriter,
            app(CategoryHierarchyService::class),
            $onRowProcessed
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

            $this->rowWriter->upsertCategories($pending);

            $this->writeExplicitProgress(60.0);

            if ($this->isCancelled()) {
                throw new ImportCancelledException();
            }

            $this->hierarchyLinker->assignParents($pending);

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
            $category = Category::withTrashed()->find($id);

            if ($category && !$category->trashed()) {
                $category->delete();
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

    /**
     * Test seam: the row writer owns the SSRF guard; reflection-based
     * tests address it through the service (DeepVerificationTest 4.42).
     */
    protected function assertSafeUrl(string $url): void
    {
        $this->rowWriter->assertSafeUrl($url);
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
