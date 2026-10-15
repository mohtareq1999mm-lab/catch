<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\Fulfillment\Location;
use Marvel\Enums\ItemType;

/**
 * Read-only inventory placement reconciliation audit.
 *
 * Authority model (mirrors StockOperationService):
 * - Aggregate authority is SKU-level: products.stock_quantity for SIMPLE
 *   products (NULL variant), product_variants.stock_quantity per-VARIANT.
 * - product_locations rows are NON-AUTHORITATIVE placement hints; their sum
 *   is informational and is NEVER written back to stock.
 *
 * This command WRITES NOTHING. Every discrepancy is reported for a business
 * decision (re-count, transfer, receive, or controlled adjustment through
 * StockOperationService) instead of being silently repaired. Reserved-stock
 * drift is owned by `inventory:migrate-reservations --dry-run`.
 *
 * Consistency: all checks run inside one read-only database transaction, so on
 * InnoDB (REPEATABLE READ) every SELECT observes the same snapshot. Plain
 * SELECTs take no locks; writers are never blocked by this command. Concurrent
 * stock operations therefore cannot produce contradictory per-check results
 * within a single run (stale-but-consistent at worst).
 *
 * Check statuses: clean | findings | skipped | error. Overall status is
 * `clean` only when every required check ran and found nothing; any findings
 * or any check error prevents clean. Warehouse-scoped runs SKIP the global
 * aggregate comparisons (authority is global) and say so explicitly.
 *
 * Exit codes: 0 clean, 1 findings-or-errors, 2 invalid CLI input.
 */
class AuditInventoryPlacements extends Command
{
    public const STATUS_CLEAN = 'clean';

    public const STATUS_FINDINGS = 'findings';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_ERROR = 'error';

    protected $signature = 'inventory:audit-placements
        {--warehouse= : Only audit placements in one warehouse ID}
        {--json : Output machine-readable JSON instead of human-readable lines}';

    protected $description = 'Report-only audit of placement hints vs SKU-level stock authority (writes nothing)';

    public function handle(): int
    {
        $raw = $this->option('warehouse');
        $warehouseId = null;
        if ($raw !== null && $raw !== '') {
            if (!ctype_digit((string) $raw) || (int) $raw <= 0) {
                $this->error('Invalid --warehouse: expected a positive warehouse ID.');

                return self::INVALID;
            }
            $warehouseId = (int) $raw;
            if (!DB::table('warehouses')->where('id', $warehouseId)->exists()) {
                $this->error("Unknown --warehouse: no warehouse #{$warehouseId}.");

                return self::INVALID;
            }
        }

        $findings = $this->audit($warehouseId);

        if ($this->option('json')) {
            $this->line(json_encode($findings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $findings['status'] === self::STATUS_CLEAN ? self::SUCCESS : self::FAILURE;
        }

        $this->line('Scope: '.($warehouseId === null ? 'global (all warehouses)' : "warehouse #{$warehouseId} (global aggregate checks skipped)"));
        $this->line(sprintf(
            'Checks: %d clean, %d findings, %d skipped, %d error',
            $findings['status_counts'][self::STATUS_CLEAN],
            $findings['status_counts'][self::STATUS_FINDINGS],
            $findings['status_counts'][self::STATUS_SKIPPED],
            $findings['status_counts'][self::STATUS_ERROR],
        ));
        $this->line('Counts: '.$findings['total_findings_note']);
        foreach ($findings['checks'] as $check) {
            $label = match ($check['status']) {
                self::STATUS_CLEAN => 'CLEAN',
                self::STATUS_FINDINGS => 'FINDINGS',
                self::STATUS_SKIPPED => 'SKIPPED',
                default => 'ERROR',
            };
            $this->line(sprintf('%-28s : %s (%d)', $check['key'], $label, $check['count']));
            if (!empty($check['message'])) {
                $this->line('    ['.$check['message'].']');
            }
            if (!empty($check['note'])) {
                $this->line('    ('.$check['note'].')');
            }
            $shown = array_slice($check['samples'], 0, 5);
            foreach ($shown as $sample) {
                $this->line('    - '.json_encode($sample, JSON_UNESCAPED_UNICODE));
            }
            if (count($check['samples']) > count($shown)) {
                $this->line(sprintf('    (showing %d of %d sampled, %d total in check)', count($shown), count($check['samples']), $check['count']));
            }
        }

        $this->line(sprintf('Total findings: %d', $findings['total_findings']));
        if (!empty($findings['overall_note'])) {
            $this->line('('.$findings['overall_note'].')');
        }

        $this->info(match ($findings['status']) {
            self::STATUS_CLEAN => 'Placement audit clean — every required check ran with no discrepancies.',
            self::STATUS_FINDINGS => "Placement audit found {$findings['total_findings']} finding(s) — business decision required, nothing was repaired.",
            default => 'Placement audit could not complete reliably — see ERROR checks above; do not treat this as clean.',
        });

        return $findings['status'] === self::STATUS_CLEAN ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{status:string, scope:string, warehouse_id:?int, total_findings:int, total_findings_note:string, status_counts:array<string,int>, overall_note:?string, checks:array<int,array{key:string,status:string,count:int,message:string,samples:array<int,array<string,mixed>>,note?:string,blocking?:bool}>}
     */
    public function audit(?int $warehouseId = null): array
    {
        $scope = function ($query) use ($warehouseId) {
            if ($warehouseId !== null) {
                $query->where('product_locations.warehouse_id', $warehouseId);
            }

            return $query;
        };

        // One read-only transaction = one InnoDB snapshot for every check.
        // Any check that throws is recorded as `error` (never silent, never clean).
        $checks = DB::transaction(function () use ($scope, $warehouseId) {
            return [
                $this->runCheck('aggregate_vs_locations', fn () => $this->checkAggregateDrift($scope, $warehouseId)),
                $this->runCheck('unplaced_stock', fn () => $this->checkUnplacedStock($scope, $warehouseId)),
                $this->runCheck('negative_quantities', fn () => $this->checkNegatives($scope)),
                $this->runCheck('hint_exceeds_placement', fn () => $this->checkHintOverflow($scope)),
                $this->runCheck('duplicate_sku_rows', fn () => $this->checkDuplicates($scope)),
                $this->runCheck('warehouse_drift', fn () => $this->checkWarehouseDrift($scope)),
                $this->runCheck('invalid_refs', fn () => $this->checkInvalidRefs($scope)),
                $this->runCheck('live_hints_on_inactive_bins', fn () => $this->checkInactiveBins($scope)),
                $this->runCheck('non_physical_placements', fn () => $this->checkNonPhysical($scope)),
                $this->runCheck('variant_parent_mismatch', fn () => $this->checkVariantMismatch($scope)),
            ];
        });

        $statusCounts = [
            self::STATUS_CLEAN => 0,
            self::STATUS_FINDINGS => 0,
            self::STATUS_SKIPPED => 0,
            self::STATUS_ERROR => 0,
        ];
        $total = 0;
        foreach ($checks as $check) {
            $statusCounts[$check['status']]++;
            $total += (int) $check['count'];
        }

        ['status' => $status, 'overall_note' => $note] = $this->overallStatus($checks);

        return [
            'status' => $status,
            'scope' => $warehouseId === null ? 'global' : "warehouse:{$warehouseId}",
            'warehouse_id' => $warehouseId,
            'total_findings' => $total,
            'total_findings_note' => 'Sums per-check counts; one database row may appear in several checks, so this is not a distinct-row count.',
            'status_counts' => $statusCounts,
            'overall_note' => $note,
            'checks' => $checks,
        ];
    }

    /**
     * Fail-closed priority: errors first, then findings, then blocking skips
     * (required invariants left unevaluated by missing schema).
     * Scope-limitation skips (blocking=false) never block clean.
     *
     * @param array<int,array{status:string,blocking?:bool}> $checks
     * @return array{status:string, overall_note:?string}
     */
    protected function overallStatus(array $checks): array
    {
        $hasError = false;
        $hasFindings = false;
        $blockingSkips = [];
        foreach ($checks as $check) {
            if ($check['status'] === self::STATUS_ERROR) {
                $hasError = true;
            } elseif ($check['status'] === self::STATUS_FINDINGS) {
                $hasFindings = true;
            } elseif ($check['status'] === self::STATUS_SKIPPED && ($check['blocking'] ?? false)) {
                $blockingSkips[] = $check['key'] ?? '?';
            }
        }

        if ($hasError) {
            $status = self::STATUS_ERROR;
        } elseif ($hasFindings) {
            $status = self::STATUS_FINDINGS;
        } elseif (count($blockingSkips) > 0) {
            $status = self::STATUS_ERROR;
        } else {
            $status = self::STATUS_CLEAN;
        }

        // Coexisting unevaluated invariants are never dropped from the summary,
        // whatever the overall status is.
        $note = count($blockingSkips) > 0
            ? 'Required checks left unevaluated: '.implode(', ', $blockingSkips).'. Do not treat as clean.'
            : null;

        return ['status' => $status, 'overall_note' => $note];
    }

    /**
     * Execute one check; a throwing check becomes an explicit `error` result.
     *
     * @return array{key:string,status:string,count:int,message:string,samples:array<int,array<string,mixed>>}
     */
    protected function runCheck(string $key, \Closure $check): array
    {
        try {
            $result = $check();
        } catch (\Throwable $e) {
            report($e);

            return [
                'key' => $key,
                'status' => self::STATUS_ERROR,
                'count' => 0,
                'message' => 'Check could not complete reliably; do not treat as clean.',
                'samples' => [],
                'note' => substr(get_class($e).': '.$e->getMessage(), 0, 200),
            ];
        }

        $result['key'] = $key;
        $result['status'] = ($result['status'] ?? null) === self::STATUS_SKIPPED
            ? self::STATUS_SKIPPED
            : (((int) ($result['count'] ?? 0)) > 0 ? self::STATUS_FINDINGS : self::STATUS_CLEAN);
        $result['message'] = $result['message'] ?? '';
        $result['samples'] = $result['samples'] ?? [];

        return $result;
    }

    /**
     * Per-SKU placement sum vs authority stock. Hints drift by design
     * (returns/receiving transiently exceed authority before the authority
     * moves), so ANY row here is a business-decision item, never an auto-repair.
     *
     * Starts from placement groups; SKUs with NO placement rows are covered by
     * `unplaced_stock`, not here. Missing authority rows are owned by
     * `invalid_refs`, not here (INNER JOIN excludes them by construction).
     *
     * Memory-bounded by design: the HAVING clause pushes drift filtering into
     * the database, so both passes stream only drifting groups (chunked) —
     * never the full SKU population. No unbounded get(), no giant whereIn().
     *
     * Warehouse-scoped runs SKIP this check: the authority is global while a
     * scoped placed_sum covers one warehouse, so comparing them would
     * false-positive on every legitimately split SKU.
     */
    private function checkAggregateDrift(\Closure $scope, ?int $warehouseId): array
    {
        if ($warehouseId !== null) {
            return ['count' => 0, 'samples' => [], 'status' => self::STATUS_SKIPPED, 'message' => '', 'note' => 'skipped: authority is global, scope is per-warehouse', 'blocking' => false];
        }

        $buildSimple = fn () => $scope(DB::table('product_locations')
            ->join('products', 'products.id', '=', 'product_locations.product_id')
            ->whereNull('product_locations.product_variant_id')
            ->selectRaw('product_locations.product_id, NULL as product_variant_id, SUM(product_locations.quantity) as placed, products.stock_quantity as authority')
            ->groupBy('product_locations.product_id', 'products.stock_quantity')
            ->havingRaw('ABS(SUM(product_locations.quantity) - products.stock_quantity) > 0.0001')
            ->orderBy('product_locations.product_id'));

        $buildVariant = fn () => $scope(DB::table('product_locations')
            ->join('product_variants', 'product_variants.id', '=', 'product_locations.product_variant_id')
            ->selectRaw('product_locations.product_id, product_locations.product_variant_id, SUM(product_locations.quantity) as placed, product_variants.stock_quantity as authority')
            ->groupBy('product_locations.product_id', 'product_locations.product_variant_id', 'product_variants.stock_quantity')
            ->havingRaw('ABS(SUM(product_locations.quantity) - product_variants.stock_quantity) > 0.0001')
            ->orderBy('product_locations.product_id')->orderBy('product_locations.product_variant_id'));

        $simpleSamples = [];
        $variantSamples = [];
        $count = 0;
        $buildSimple()->chunk(1000, function ($rows) use (&$simpleSamples, &$count) {
            $count += count($rows);
            foreach ($rows as $row) {
                if (count($simpleSamples) >= 5) {
                    break;
                }
                $simpleSamples[] = [
                    'product_id' => (int) $row->product_id,
                    'product_variant_id' => null,
                    'placed_sum' => (float) $row->placed,
                    'authority_stock' => (float) $row->authority,
                ];
            }
        });
        $buildVariant()->chunk(1000, function ($rows) use (&$variantSamples, &$count) {
            $count += count($rows);
            foreach ($rows as $row) {
                if (count($variantSamples) >= 5) {
                    break;
                }
                $variantSamples[] = [
                    'product_id' => (int) $row->product_id,
                    'product_variant_id' => (int) $row->product_variant_id,
                    'placed_sum' => (float) $row->placed,
                    'authority_stock' => (float) $row->authority,
                ];
            }
        });
        $samples = array_merge($simpleSamples, $variantSamples);

        return ['count' => $count, 'samples' => $samples, 'message' => 'One count per drifting SKU group (database-filtered, streamed); samples capped at 10. Remediation: receive/transfer/adjust through StockOperationService; never write authority from hints.'];
    }

    /**
     * Authority stock with NO placement rows. Starts from the authority tables
     * (products / product_variants), closing the false-CLEAN gap where drift
     * detection — which starts from placement groups — cannot see these SKUs.
     *
     * A mismatched-parent placement (product_id=A, variant of B) counts as a
     * placement for variant B, suppressing an unplaced finding there; the SKU
     * still surfaces under variant_parent_mismatch, so nothing is hidden.
     *
     * Excluded by verified domain rule: DIGITAL products (hold no physical
     * stock; see StockOperationService D1) and soft-deleted products (history,
     * must not demand new placements). NULL item_type = legacy physical.
     * Zero-stock SKUs without placements are valid and not reported.
     *
     * Skipped under warehouse scope (a SKU placed in another warehouse would
     * false-positive as unplaced here).
     */
    private function checkUnplacedStock(\Closure $scope, ?int $warehouseId): array
    {
        if ($warehouseId !== null) {
            return ['count' => 0, 'samples' => [], 'status' => self::STATUS_SKIPPED, 'message' => '', 'note' => 'skipped: placement coverage is global, scope is per-warehouse', 'blocking' => false];
        }

        if (!Schema::hasColumn('products', 'item_type')) {
            return ['count' => 0, 'samples' => [], 'status' => self::STATUS_SKIPPED, 'message' => '', 'note' => 'skipped: products.item_type column missing, digital exclusion unavailable', 'blocking' => true];
        }

        $physical = function ($query) {
            $query->where(function ($q) {
                $q->whereNull('products.item_type')->orWhere('products.item_type', '!=', ItemType::DIGITAL);
            });
            if (Schema::hasColumn('products', 'deleted_at')) {
                $query->whereNull('products.deleted_at');
            }

            return $query;
        };

        $simpleRows = $physical(DB::table('products')
            ->where('products.stock_quantity', '>', 0)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('product_locations')
                    ->whereColumn('product_locations.product_id', 'products.id')
                    ->whereNull('product_locations.product_variant_id');
            })
            ->orderBy('products.id')->limit(10))
            ->get(['products.id', 'products.sku', 'products.stock_quantity']);

        $simpleCount = (int) $physical(DB::table('products')
            ->where('products.stock_quantity', '>', 0)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('product_locations')
                    ->whereColumn('product_locations.product_id', 'products.id')
                    ->whereNull('product_locations.product_variant_id');
            }))->count();

        $variantRows = DB::table('product_variants')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where(function ($q) {
                $q->whereNull('products.item_type')->orWhere('products.item_type', '!=', ItemType::DIGITAL);
            })
            ->when(Schema::hasColumn('products', 'deleted_at'), fn ($q) => $q->whereNull('products.deleted_at'))
            ->where('product_variants.stock_quantity', '>', 0)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('product_locations')
                    ->whereColumn('product_locations.product_variant_id', 'product_variants.id');
            })
            ->orderBy('product_variants.id')->limit(10)
            ->get(['product_variants.id as variant_id', 'product_variants.product_id', 'product_variants.sku', 'product_variants.stock_quantity']);

        $variantCount = (int) DB::table('product_variants')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where(function ($q) {
                $q->whereNull('products.item_type')->orWhere('products.item_type', '!=', ItemType::DIGITAL);
            })
            ->when(Schema::hasColumn('products', 'deleted_at'), fn ($q) => $q->whereNull('products.deleted_at'))
            ->where('product_variants.stock_quantity', '>', 0)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('product_locations')
                    ->whereColumn('product_locations.product_variant_id', 'product_variants.id');
            })->count();

        // Fair sampling: simple and variant findings each keep up to 5 samples
        // so a large simple backlog can never starve variant evidence.
        $samples = array_merge(
            array_slice(array_map(fn ($r) => ['type' => 'simple'] + (array) $r, $simpleRows->all()), 0, 5),
            array_slice(array_map(fn ($r) => ['type' => 'variant'] + (array) $r, $variantRows->all()), 0, 5),
        );

        return [
            'count' => $simpleCount + $variantCount,
            'samples' => $samples,
            'message' => 'Authority SKUs with positive stock and zero placement rows (samples: up to 5 simple + 5 variant). Remediation: receive into a bin via POST locations/{id}/stock-receipts; digital and soft-deleted SKUs are excluded by rule.',
        ];
    }

    /**
     * Backstop only: MySQL CHECKs (quantity>=0, hint>=0) make these rows
     * uninsertable through any path; only out-of-band writes with checks
     * disabled could produce them.
     */
    private function checkNegatives(\Closure $scope): array
    {
        $rows = $scope(DB::table('product_locations')
            ->where(function ($q) {
                $q->where('quantity', '<', 0)->orWhere('allocated_hint', '<', 0);
            })
            ->orderBy('id')->limit(10))
            ->get(['id', 'product_id', 'product_variant_id', 'location_id', 'quantity', 'allocated_hint']);

        return [
            'count' => (int) $scope(DB::table('product_locations')
                ->where(function ($q) {
                    $q->where('quantity', '<', 0)->orWhere('allocated_hint', '<', 0);
                }))->count(),
            'samples' => $rows->map(fn ($r) => (array) $r)->all(),
            'message' => 'Full-table count; samples capped at 10. Database CHECKs are the primary guard.',
        ];
    }

    /**
     * Backstop only: the service guard plus MySQL CHECK (hint<=quantity) refuse
     * these writes; only legacy rows or FK-off writes could appear here.
     */
    private function checkHintOverflow(\Closure $scope): array
    {
        $rows = $scope(DB::table('product_locations')
            ->whereColumn('allocated_hint', '>', 'quantity')
            ->orderBy('id')->limit(10))
            ->get(['id', 'product_id', 'product_variant_id', 'location_id', 'quantity', 'allocated_hint']);

        return [
            'count' => (int) $scope(DB::table('product_locations')->whereColumn('allocated_hint', '>', 'quantity'))->count(),
            'samples' => $rows->map(fn ($r) => (array) $r)->all(),
            'message' => 'allocated_hint is informational only and never decides sellability; overflow is a data defect, not a reservation. Samples capped at 10.',
        ];
    }

    /**
     * Count = number of duplicate SKU groups (not rows). MySQL UNIQUE allows
     * multiple NULL-variant rows, so simple-SKU duplicates are genuinely
     * creatable and app-lock-serialization is the only guard: this check is live,
     * not theoretical. Variant duplicates are refused by the UNIQUE backstop.
     */
    private function checkDuplicates(\Closure $scope): array
    {
        $count = 0;
        $samples = [];
        $scope(DB::table('product_locations')
            ->selectRaw('product_id, COALESCE(product_variant_id, 0) as variant_key, location_id, COUNT(*) as dup_rows')
            ->groupByRaw('product_id, COALESCE(product_variant_id, 0), location_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderByRaw('product_id, COALESCE(product_variant_id, 0), location_id'))
            ->chunk(1000, function ($groups) use (&$count, &$samples) {
                $count += count($groups);
                foreach ($groups->take(max(0, 10 - count($samples))) as $g) {
                    $samples[] = (array) $g;
                }
            });

        return [
            'count' => $count,
            'samples' => $samples,
            'message' => 'Count is duplicate groups (samples capped at 10); each sample carries its row count (dup_rows). Remediation is a business decision (merge/split), never silent deletion.',
        ];
    }

    private function checkWarehouseDrift(\Closure $scope): array
    {
        $rows = $scope(DB::table('product_locations')
            ->join('locations', 'locations.id', '=', 'product_locations.location_id')
            ->whereColumn('product_locations.warehouse_id', '!=', 'locations.warehouse_id')
            ->orderBy('product_locations.id')->limit(10))
            ->get([
                'product_locations.id', 'product_locations.product_id',
                'product_locations.product_variant_id', 'product_locations.location_id',
                'product_locations.warehouse_id', 'locations.warehouse_id as location_warehouse_id',
            ]);

        return [
            'count' => (int) $scope(DB::table('product_locations')
                ->join('locations', 'locations.id', '=', 'product_locations.location_id')
                ->whereColumn('product_locations.warehouse_id', '!=', 'locations.warehouse_id'))->count(),
            'samples' => $rows->map(fn ($r) => (array) $r)->all(),
            'message' => 'Denormalized placement warehouse disagrees with its location (locations are warehouse-immutable). Fail closed; never silently re-home. Samples capped at 10.',
        ];
    }

    /**
     * Defense in depth: FK cascades make orphans impossible through ORM
     * deletes; only raw-SQL / FK-disabled writes can produce them.
     * Count = total orphan rows across the three reference kinds.
     */
    private function checkInvalidRefs(\Closure $scope): array
    {
        $samples = [];

        $missingProducts = (int) $scope(DB::table('product_locations')
            ->leftJoin('products', 'products.id', '=', 'product_locations.product_id')
            ->whereNull('products.id'))->count();
        foreach ($scope(DB::table('product_locations')
            ->leftJoin('products', 'products.id', '=', 'product_locations.product_id')
            ->whereNull('products.id')
            ->orderBy('product_locations.id')->limit(5))
            ->get(['product_locations.id', 'product_locations.product_id']) as $r) {
            $samples[] = ['type' => 'missing_product'] + (array) $r;
        }

        $missingVariants = (int) $scope(DB::table('product_locations')
            ->whereNotNull('product_locations.product_variant_id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'product_locations.product_variant_id')
            ->whereNull('product_variants.id'))->count();
        foreach ($scope(DB::table('product_locations')
            ->whereNotNull('product_locations.product_variant_id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'product_locations.product_variant_id')
            ->whereNull('product_variants.id')
            ->orderBy('product_locations.id')->limit(5))
            ->get(['product_locations.id', 'product_locations.product_variant_id']) as $r) {
            $samples[] = ['type' => 'missing_variant'] + (array) $r;
        }

        $missingLocations = (int) $scope(DB::table('product_locations')
            ->leftJoin('locations', 'locations.id', '=', 'product_locations.location_id')
            ->whereNull('locations.id'))->count();
        foreach ($scope(DB::table('product_locations')
            ->leftJoin('locations', 'locations.id', '=', 'product_locations.location_id')
            ->whereNull('locations.id')
            ->orderBy('product_locations.id')->limit(5))
            ->get(['product_locations.id', 'product_locations.location_id']) as $r) {
            $samples[] = ['type' => 'missing_location'] + (array) $r;
        }

        return ['count' => $missingProducts + $missingVariants + $missingLocations, 'samples' => $samples, 'message' => 'Count is total orphan rows (products + variants + locations); samples capped at 5 per kind.'];
    }

    /**
     * Mirrors Location::scopePlaceable exactly (active + NULL-or-placeable
     * type). locations.status is NOT NULL DEFAULT 'active', so no NULL-status
     * bypass exists; NULL type is legacy-placeable by rule.
     */
    private function checkInactiveBins(\Closure $scope): array
    {
        $placeable = Location::PLACEABLE_TYPES;
        $rows = $scope(DB::table('product_locations')
            ->join('locations', 'locations.id', '=', 'product_locations.location_id')
            ->where(function ($q) {
                $q->where('product_locations.quantity', '>', 0)
                    ->orWhere('product_locations.allocated_hint', '>', 0);
            })
            ->where(function ($q) use ($placeable) {
                $q->where('locations.status', '!=', Location::STATUS_ACTIVE)
                    ->orWhere(function ($qq) use ($placeable) {
                        $qq->whereNotNull('locations.type')->whereNotIn('locations.type', $placeable);
                    });
            })
            ->orderBy('product_locations.id')->limit(10))
            ->get([
                'product_locations.id', 'product_locations.product_id',
                'product_locations.product_variant_id', 'product_locations.location_id',
                'product_locations.quantity', 'locations.status', 'locations.type',
            ]);

        return [
            'count' => (int) $scope(DB::table('product_locations')
                ->join('locations', 'locations.id', '=', 'product_locations.location_id')
                ->where(function ($q) {
                    $q->where('product_locations.quantity', '>', 0)
                        ->orWhere('product_locations.allocated_hint', '>', 0);
                })
                ->where(function ($q) use ($placeable) {
                    $q->where('locations.status', '!=', Location::STATUS_ACTIVE)
                        ->orWhere(function ($qq) use ($placeable) {
                            $qq->whereNotNull('locations.type')->whereNotIn('locations.type', $placeable);
                        });
                }))->count(),
            'samples' => $rows->map(fn ($r) => (array) $r)->all(),
            'message' => 'Only live hints (quantity>0 or hint>0) are flagged; zero-quantity history on dead bins is not a finding. Samples capped at 10.',
        ];
    }

    /**
     * Digital lines hold no physical stock (D1). When products.item_type is
     * absent (pre-feature schema) the check is SKIPPED explicitly — never
     * reported as clean.
     */
    private function checkNonPhysical(\Closure $scope): array
    {
        if (!Schema::hasColumn('products', 'item_type')) {
            return ['count' => 0, 'samples' => [], 'status' => self::STATUS_SKIPPED, 'message' => '', 'note' => 'skipped: products.item_type column missing, digital exclusion unavailable', 'blocking' => true];
        }

        $rows = $scope(DB::table('product_locations')
            ->join('products', 'products.id', '=', 'product_locations.product_id')
            ->where('products.item_type', ItemType::DIGITAL)
            ->where(function ($q) {
                $q->where('product_locations.quantity', '>', 0)
                    ->orWhere('product_locations.allocated_hint', '>', 0);
            })
            ->orderBy('product_locations.id')->limit(10))
            ->get(['product_locations.id', 'product_locations.product_id', 'product_locations.quantity']);

        return [
            'count' => (int) $scope(DB::table('product_locations')
                ->join('products', 'products.id', '=', 'product_locations.product_id')
                ->where('products.item_type', ItemType::DIGITAL)
                ->where(function ($q) {
                    $q->where('product_locations.quantity', '>', 0)
                        ->orWhere('product_locations.allocated_hint', '>', 0);
                }))->count(),
            'samples' => $rows->map(fn ($r) => (array) $r)->all(),
            'message' => 'Digital lines hold no physical stock (D1): a live placement on a digital product is a data defect. Samples capped at 10.',
        ];
    }

    private function checkVariantMismatch(\Closure $scope): array
    {
        $rows = $scope(DB::table('product_locations')
            ->join('product_variants', 'product_variants.id', '=', 'product_locations.product_variant_id')
            ->whereColumn('product_variants.product_id', '!=', 'product_locations.product_id')
            ->orderBy('product_locations.id')->limit(10))
            ->get([
                'product_locations.id', 'product_locations.product_id',
                'product_locations.product_variant_id', 'product_variants.product_id as variant_parent_id',
            ]);

        return [
            'count' => (int) $scope(DB::table('product_locations')
                ->join('product_variants', 'product_variants.id', '=', 'product_locations.product_variant_id')
                ->whereColumn('product_variants.product_id', '!=', 'product_locations.product_id'))->count(),
            'samples' => $rows->map(fn ($r) => (array) $r)->all(),
            'message' => 'Placement SKU identity contradicts the variant parentage; sibling-variant bins hold other goods. Samples capped at 10.',
        ];
    }
}
