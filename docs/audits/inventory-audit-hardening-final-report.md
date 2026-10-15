# Inventory Audit Hardening — Final Report

## Final status

**VERIFIED COMPLETE** for the audit-command scope. All acceptance items have
evidence except explicitly listed NOT-VERIFIED scale/concurrency externals, which
are documented limitations, not defects.

## Executive summary

The audit had one critical defect (false CLEAN for positive-stock SKUs with zero
placements — TESTED proof) plus contract gaps (no statuses, SKIPPED collapsing to
clean, error-hiding overall, unbounded reads). All fixed: new `unplaced_stock`
check, explicit clean/findings/skipped/error statuses with fail-closed priority
(error > findings > blocking-skip-error > clean), JSON contract
(status/scope/counts/overall_note/per-check status+message), read-only snapshot
transaction, streaming database-filtered drift detection, fair sampling, and a
25-test matrix. Three independent review rounds: REQUEST-CHANGES → fixes →
REQUEST-CHANGES (1 must-fix) → fixes → APPROVE.

## Verified findings and fixes

1. False CLEAN on unplaced stock (critical, TESTED) → `unplaced_stock` (authority-side
   NOT EXISTS, digital/soft-deleted/zero-stock exclusions by verified rule).
2. Statuses collapsed (major) → per-check status + overall priority + blocking flags.
3. Unbounded DISTINCT/whereIn (major, review) → JOIN+HAVING streaming passes.
4. Sampling bias, message gaps, note drops, alias groupBy fragility (minor, review) → fixed.
5. Test-helper order dependence via global `Artisan::output()` (environmental) →
   explicit buffers + raw-head diagnostics.

## Changed files and why

- `app/Console/Commands/AuditInventoryPlacements.php` — the hardened audit (only
  functional change; still read-only, verified by grep + state-snapshot test).
- `tests/Feature/Wms/WmsPlacementAuditTest.php` — 25-test matrix.
- `app/Console/Kernel.php` — 1-line command registration (prior loop).
- `docs/audits/inventory-hardening-baseline.md`, `inventory-audit-hardening-design.md`,
  `inventory-audit-hardening-test-evidence.md`, this file — deliverables.
- `packages/marvel/*` (911 files) — RESTORED after parallel-agent `f3b27c8` deleted
  the tracked tree (recovery, not a feature change; staged, uncommitted by me).
- Removed my own scaffolding (`WmsAuditDebug*Test`, `storage/*_tmp.txt`).

## Test evidence

25/25 (206 assertions) matrix + 35/35 regression + earlier 48/48 and 42/42 suites,
all MySQL 8 isolated DB. Live dev-DB audit clean, exit 0. See test-evidence doc.

## Independent reviews

Round 1: REQUEST-CHANGES (SQLite portability, scoped aggregate, invalid_refs count —
prior loop, all fixed). Round 2: REQUEST-CHANGES (error-hiding overall + 8 notes).
Round 3: APPROVE with lows, all addressed except foreign-tree hygiene (not mine).

## Remaining risks

- RISK: catalogs >100k SKUs — chunked/streamed by design, unexecuted beyond 300 rows.
- RISK: parallel agent mutating shared branch (deleted Marvel tree once; vendor wiped
  once) — recommend isolated worktrees/branches.
- Foreign worktree changes (Routes.php whitespace, batches docs, assigned-tasks test)
  left untouched per preservation rules.

## Blocked criteria

None blocking. NOT VERIFIED (documented): >1000-drift pagination, live snapshot under
write load, production-data upgrade, multi-node concurrency.

## Production readiness

The command is proven read-only, fail-closed, and contract-stable for on-demand
operator use. Not a scheduler-managed monitor (no alerting wiring claimed). Stock
authorities, Order Flow, WMS lifecycles, pricing, payments untouched.
