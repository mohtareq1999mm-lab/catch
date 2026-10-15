# Inventory Audit Hardening — Test Evidence (MySQL 8, isolated DB `catch_verify`)

Engine: MySQL 8 InnoDB (no SQLite substitution for locking/migration behavior).
Dev DB `catch` used read-only (audit runs, exit-code checks).

## Focused matrix: `WmsPlacementAuditTest` — 25 passed, 206 assertions

Command: `$env:DB_DATABASE='catch_verify'; php artisan test --filter=WmsPlacementAuditTest`
Result: PASS (25/25). Covers: clean state; drift lower + excess; unplaced simple +
variant + multi-variant parent; multi-placement match; wrong-parent placement;
duplicate NULL-simple rows; warehouse drift; invalid refs ×3 (FK-off seeding);
inactive + quarantine bins; digital include/exclude; soft-delete exclusion;
CHECK refusals (3819, MySQL); JSON contract (10 checks, statuses, scope, counts,
overall_note); scoped skips; error wrapper + overall priority matrix (7 combos);
read-only state snapshot (13 signals) + 30-token source write-guard; sample-cap
12-groups→count 12/samples 10; 300-row scale (<60 s, <100 queries, exit 0).

## Regression suites (isolated DB, all PASS)

- `WmsVariantInventoryTest`: 21 passed (83 assertions).
- `WmsReceivingReliabilityTest`: 14 passed (74 assertions).
- `WmsMasterInventoryGapsTest`: 3 passed (16 assertions).
- Combined variant+receiving re-run post-restore: 35 passed (157 assertions).
- Earlier (pre-restore): multi-location+empty+cancellation 48/48; RBAC+warehouse+foundation 42/42.

## Live command evidence (dev `catch`, read-only)

- `inventory:audit-placements`: 10 clean, exit 0.
- `inventory:audit-placements --json`: `status=clean, scope=global, status_counts.clean=10`.
- `inventory:migrate-reservations --dry-run`: detached 0, re-homed 0, drift 0.
- False-CLEAN proof (pre-fix): stock-9/no-placement SKU → overall clean (fixed; now `unplaced_stock` FINDINGS).
- Genuine 2-process same-key receipt race (prior loop): winner executed, loser replayed, stock exactly once.

## Environment incident (evidence, not a code defect)

Mid-loop, `vendor/` was found completely empty and `packages/marvel/` source deleted
(parallel-agent commit `f3b27c8` deleted the 911-file tracked tree). Symptoms during
the window: missing Whoops file, missing autoload, order-dependent empty
`Artisan::output()`. Recovery (all TESTED): `git checkout f3b27c8^ -- packages/marvel`
(911 files), `composer install --ignore-platform-req=ext-sockets` (ext-sockets absent
in CLI PHP; amqplib unused since RabbitMQ removal), `composer dump-autoload`.
Post-restore: 25/25 + 35/35 green. `composer install` without the ignore flag fails
on ext-sockets (environment limitation, pre-existing).

## Limitations / not verified

- >1000-drift pagination stability (chunking reasoned + reviewed, unexecuted at scale).
- Concurrent-write snapshot contradiction beyond the REPEATABLE READ design claim.
- Live MySQL EXPLAIN at production volume; production-data upgrade (isolated-DB proof only).
- True multi-node concurrency for the audit itself (documented guarantee only).
- `overallStatus` blocking-skip path via real missing-column schema (dead code on real
  DBs; covered by unit matrix, not by live schema surgery).
