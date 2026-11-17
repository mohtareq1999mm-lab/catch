<?php

namespace Marvel\Services\Import;

use Closure;
use Marvel\Database\Models\Import;

/**
 * One owner for the terminal orchestration the import jobs share: the
 * guarded outcome write, the terminal-state check, the cache invalidation
 * and the broadcast payload composition.
 *
 * RunOutcome owns WHAT the terminal row says (status derivation, the
 * processed = success + failed invariant, the single error cap and the
 * row-write shape); this trait owns the single way every family job
 * writes and announces it. The job supplies the family broadcast callable
 * and keeps its own file/signal cleanup, which differs per family.
 */
trait FinalizesImportOutcome
{
    /**
     * Write the success terminal and announce it.
     *
     * @return bool true when the import was already terminal (a worker
     *              race) and the caller must bail out entirely.
     */
    protected function finalizeSuccessTerminal(Import $import, RunOutcome $outcome, Closure $broadcast): bool
    {
        $affected = Import::where('id', $import->id)
            ->whereIn('status', ['pending', 'processing', 'cancelling'])
            ->update([
                'status' => $outcome->status(),
                'total_rows' => $outcome->terminalTotalRows(),
                ...$outcome->rowAttributes(),
            ]);

        $import->refresh();

        if ($affected === 0 && $import->isTerminal()) {
            return true;
        }

        if ($outcome->successCount() > 0) {
            $this->invalidateFrontendCaches();
        }

        $broadcast(
            $outcome->status(),
            $outcome->failedCount() > 0,
            [
                'progress' => 100.0,
                'total_rows' => $outcome->terminalTotalRows(),
                'processed_rows' => $outcome->processed(),
                'success_rows' => $outcome->successCount(),
                'failed_rows' => $outcome->failedCount(),
            ]
        );

        return false;
    }

    /**
     * Write the cancelled terminal and announce it. The raw failed rows are
     * passed through uncapped — cancellation keeps the legacy uncapped
     * error write; only the success terminal caps through the outcome.
     *
     * @param  array<int, array<string, mixed>>  $failedRows
     */
    protected function writeCancelledTerminal(Import $import, RunOutcome $outcome, array $failedRows, Closure $broadcast): void
    {
        $import->update([
            'status' => 'cancelled',
            'total_rows' => $outcome->processed(),
            'processed_rows' => $outcome->processed(),
            'success_rows' => $outcome->successCount(),
            'failed_rows' => $outcome->failedCount(),
            'errors' => $failedRows,
        ]);

        if ($outcome->successCount() > 0) {
            $this->invalidateFrontendCaches();
        }

        $broadcast('cancelled', $outcome->failedCount() > 0, [
            'progress' => 100.0,
            'total_rows' => $outcome->processed(),
            'processed_rows' => $outcome->processed(),
            'success_rows' => $outcome->successCount(),
            'failed_rows' => $outcome->failedCount(),
        ]);
    }
}
