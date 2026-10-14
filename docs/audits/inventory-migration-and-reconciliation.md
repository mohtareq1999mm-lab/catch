# Migration & Reconciliation (TESTED 2026-10-14)

## Migration `2026_10_15_000001_add_variant_to_wms_inventory` — SAFE + REVERSIBLE

Adds nullable `product_variant_id` FK (cascade) + index on `product_locations`,
nullable FK (nullOnDelete) + index on `inventory_operations`; creates SKU UNIQUE
then drops pair UNIQUE (MySQL raw / Schema branches; SQLite parity). `down()` is
fail-closed: refuses when variant-scoped placements exist or duplicate simple rows
exist (working-tree adds the same guard for variant-attributed ledger rows —
parallel-agent change, left untouched).

## Backfill strategy (deterministic, no fabrication)

Existing rows keep NULL = simple SKU. No aggregate distribution among variants or
locations. Variant stock initializes only from trustworthy `product_variants` rows
(seeded at creation). Ambiguous balances are preserved + reported, never reallocated.

## Upgrade proof (TESTED, isolated DB `catch_verify`, MySQL 8)

1. `migrate:fresh` → all migrations incl. variant one DONE.
2. `migrate:rollback --path=...variant...` → variant cols gone (`[false,false]`).
3. Seeded legacy placement (product 8, qty 12, no variant dim).
4. `migrate` → DONE; row preserved `v=NULL qty=12.00`; uniques
   `{"product_locations_sku_location_unique":[...]}` (pair gone); cols `[true,true]`.
5. `migrate:fresh` to leave the isolated DB clean.

Fresh path: TESTED. Legacy-upgrade path: TESTED (above). Rollback with variant data:
fail-closed by design (NOT executed destructively — proven by code + guard tests).

## Reconciliation

- `inventory:audit-placements` (new, read-only, 9 checks): aggregate drift, negatives,
  hint overflow, duplicate SKU rows, warehouse drift, invalid refs, inactive bins,
  non-physical, variant-parent mismatch. Exit 0 clean / 1 findings / 2 bad scope.
- `inventory:migrate-reservations --dry-run` (existing): reserved drift.
- Live results on dev `catch`: placements audit clean (0/9); reservations `--dry-run`
  clean (`detached 0, re-homed 0, labelled 0, drift 0`).
- No automatic balance rewrites anywhere. Repairs = business decision executed
  through `StockOperationService` (receive/transfer/adjustment).
