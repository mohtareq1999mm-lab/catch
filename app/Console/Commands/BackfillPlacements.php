<?php

namespace App\Console\Commands;

use App\Services\Inventory\StockOperationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Mapping-driven placement backfill for an existing catalog.
 *
 * SCOPE (deliberately narrow — see AC-16/AC-18):
 * - Does NOT discover bin assignments: every row comes from an explicit
 *   operator-supplied mapping file. SKUs without a mapping are REPORTED,
 *   never auto-placed (no first-bin/random/bin-guessing shortcuts).
 * - Does NOT write the stock authority: quantities flow through
 *   StockOperationService::recordExistingPlacement, which only raises the
 *   placement HINT (capped at authority stock). stock_quantity,
 *   reserved_quantity, prices, SKUs, IDs and history are never touched.
 * - Default mode is DRY-RUN (validates every row, writes nothing). Writes
 *   happen only with --apply. Production refuses without --force.
 * - Idempotent by convergence: a row already at/above the mapped quantity
 *   is skipped (reruns are no-ops). Per-row transactions: one bad row never
 *   aborts the rest; failures are collected and reported.
 *
 * Mapping file (JSON array, chunked at 200 rows to bound memory):
 *   [{"product_id":1,"product_variant_id":null,"location_id":5,"quantity":50}]
 * warehouse_id is optional per row (consistency-checked when present,
 * otherwise derived from the location row).
 */
class BackfillPlacements extends Command
{
    protected $signature = 'inventory:backfill-placements
        {--mapping= : Path to the JSON mapping file (required)}
        {--apply : Perform writes (default is dry-run validation only)}
        {--force : Allow execution in production (requires explicit approval)}
        {--json : Output machine-readable JSON instead of human-readable lines}';

    protected $description = 'Dry-run/record existing-catalog placement hints from an explicit mapping (writes only with --apply)';

    public function handle(StockOperationService $stock): int
    {
        $mappingPath = (string) ($this->option('mapping') ?? '');
        if ($mappingPath === '' || !is_file($mappingPath) || !is_readable($mappingPath)) {
            $this->error('Invalid --mapping: expected a readable JSON file path.');

            return self::INVALID;
        }

        $decoded = json_decode((string) file_get_contents($mappingPath), true);
        if (!is_array($decoded)) {
            $this->error('Invalid --mapping: file must contain a JSON array of rows.');

            return self::INVALID;
        }

        $apply = (bool) $this->option('apply');
        if ($apply && app()->isProduction() && !(bool) $this->option('force')) {
            $this->error('Refusing to apply a catalog backfill in production without --force.');

            return self::INVALID;
        }

        $rows = array_values($decoded);
        $summary = [
            'mode' => $apply ? 'apply' : 'dry-run',
            'total_rows' => count($rows),
            'applied' => 0,
            'skipped_covered' => 0,
            'failed' => 0,
            'failures' => [],
            'failures_truncated' => false,
        ];

        foreach (array_chunk($rows, 200) as $chunkIndex => $chunk) {
            foreach ($chunk as $chunkOffset => $row) {
                $outcome = $this->processRow($stock, $row, $apply);
                match ($outcome['status']) {
                    'applied' => $summary['applied']++,
                    'skipped_covered' => $summary['skipped_covered']++,
                    default => $this->recordFailure($summary, $chunkIndex * 200 + $chunkOffset, $row, $outcome['reason']),
                };
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
        }

        $this->line(sprintf(
            'Backfill %s: %d rows (%d applied, %d already covered, %d failed)',
            $summary['mode'],
            $summary['total_rows'],
            $summary['applied'],
            $summary['skipped_covered'],
            $summary['failed']
        ));
        foreach (array_slice($summary['failures'], 0, 10) as $failure) {
            $this->line('    - ' . json_encode($failure, JSON_UNESCAPED_UNICODE));
        }
        if (count($summary['failures']) > 10) {
            $this->line(sprintf('    (showing 10 of %d failures)', count($summary['failures'])));
        }

        if (!$apply) {
            $this->info('Dry-run only: no database writes were made. Re-run with --apply to record placements.');
        }

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{status: string, reason?: string}
     */
    private function processRow(StockOperationService $stock, mixed $row, bool $apply): array
    {
        if (!is_array($row)) {
            return ['status' => 'failed', 'reason' => 'row is not an object'];
        }

        $productId = isset($row['product_id']) ? (int) $row['product_id'] : 0;
        $variantId = array_key_exists('product_variant_id', $row) && $row['product_variant_id'] !== null
            ? (int) $row['product_variant_id']
            : null;
        $locationId = isset($row['location_id']) ? (int) $row['location_id'] : 0;
        $warehouseId = array_key_exists('warehouse_id', $row) && $row['warehouse_id'] !== null
            ? (int) $row['warehouse_id']
            : null;

        // Integer-valued quantities only (intake convention, mirroring
        // receiveStock(int)): fractional strings truncate silently under
        // (int) casts, so non-integer numerics are rejected explicitly.
        if (!isset($row['quantity']) || !is_numeric($row['quantity']) || (int) $row['quantity'] != $row['quantity']) {
            return ['status' => 'failed', 'reason' => 'quantity must be an integer >= 1'];
        }
        $quantity = (int) $row['quantity'];

        if ($productId <= 0 || $locationId <= 0 || $quantity < 1) {
            return ['status' => 'failed', 'reason' => 'product_id/location_id must be positive and quantity >= 1'];
        }

        if (!$apply) {
            try {
                // Dry-run performs the full read-path validation (authority,
                // location, occupancy, cap) inside a transaction that is
                // always rolled back: proves the row WOULD apply without
                // writing anything. The convergence flag is captured before
                // the rollback so dry-run reports covered rows truthfully.
                $wouldApply = null;
                DB::transaction(function () use ($stock, $productId, $variantId, $locationId, $warehouseId, $quantity, &$wouldApply) {
                    $wouldApply = $stock->recordExistingPlacement($productId, $variantId, $locationId, $warehouseId, $quantity)['applied'];
                    throw new DryRunRollback();
                });
            } catch (DryRunRollback) {
                return ['status' => $wouldApply ? 'applied' : 'skipped_covered'];
            } catch (\Throwable $e) {
                return ['status' => 'failed', 'reason' => $this->shortReason($e)];
            }
        }

        try {
            $result = $stock->recordExistingPlacement($productId, $variantId, $locationId, $warehouseId, $quantity);
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'reason' => $this->shortReason($e)];
        }

        return ['status' => $result['applied'] ? 'applied' : 'skipped_covered'];
    }

    private function shortReason(\Throwable $e): string
    {
        $class = basename(str_replace('\\', '/', get_class($e)));

        return substr($class . ': ' . $e->getMessage(), 0, 200);
    }

    private function recordFailure(array &$summary, int $index, mixed $row, string $reason): void
    {
        $summary['failed']++;
        // Bounded memory: exact failed COUNT is kept, but only the first
        // 100 failure samples are retained (audit-command convention).
        if (count($summary['failures']) < 100) {
            $summary['failures'][] = ['row' => $index, 'input' => is_array($row) ? $row : null, 'reason' => $reason];
        } else {
            $summary['failures_truncated'] = true;
        }
    }
}

class DryRunRollback extends \Exception {}
