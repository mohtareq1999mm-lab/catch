# Inventory Audit Hardening Design

## 1. Verified stock authorities (VERIFIED)

- Simple product aggregate: `products.stock_quantity` (NULL `product_variant_id` on placements).
- Variant aggregate: `product_variants.stock_quantity` per variant row.
- `product_locations.quantity`: non-authoritative placement hint. `allocated_hint`:
  informational pending-allocation suggestion, NOT reservations (rename migration
  `2026_09_23_000002` explicitly kills the second-authority confusion).
- Reservations: authority-row `reserved_quantity`, lifecycle
  none→active→committed/released/restored (`OrderReservationService`,
  `InventoryRestoreService`). Audit never touches them.

## 2. Placement / allocation semantics (VERIFIED)

- `quantity`: last-known units of one SKU in one bin. `allocated_hint`: picking
  suggestion only. Available-to-sell is NEVER derived from hints.
- Guaranteed by DB (MySQL CHECKs): quantity≥0, hint≥0, hint≤quantity; SKU
  UNIQUE(product,location,variant) with NULL semantics (duplicate NULL-simple rows
  possible → app location-lock is the guard → `duplicate_sku_rows` is a live check).
- Application-guarded: one SKU per bin, warehouse immutability, placeability,
  idempotent receipt/transfer (`inventory_operations` UNIQUE key).
- `locations.status` NOT NULL DEFAULT 'active' (no NULL bypass); `type` NULL =
  legacy placeable; warehouses + products SoftDeletes; locations none.

## 3. Valid vs invalid unplaced stock (VERIFIED against creation/receiving paths)

- VALID (not reported): zero-stock SKUs; DIGITAL SKUs (D1, hold no physical stock);
  soft-deleted products (history); SKUs with any placement row (drift check owns them).
- FINDING: non-deleted, non-digital SKU with stock_quantity>0 and zero placement
  rows → `unplaced_stock` (remediation: receive into a bin via stock-receipts).
- Rationale: product creation accepts `quantity` without `location_id`, and
  `commit()` decrements authority without moving hints, so unplaced/under-placed
  states are reachable and must be visible, never silently clean.

## 4. Command contract

- Statuses: `clean` (ran, applicable, nothing found) | `findings` (ran, violations)
  | `skipped` (intentionally not run + reason) | `error` (could not complete).
- Overall `status`: `clean` only if every required check ran clean; any findings →
  `findings`; any check error → `error` (findings+errors → `findings`).
  Scoped runs report `scope: warehouse:N` + explicit skips; they never imply global
  validation.
- Exit codes: 0 clean, 1 findings-or-errors, 2 invalid CLI input (existing convention).
- JSON: `{status, scope, warehouse_id, total_findings, total_findings_note,
  status_counts{clean,findings,skipped,error}, checks[{key,status,count,message,
  samples,note?}]}`. `total_findings` sums per-check counts (documented; one row
  may appear in several checks). Human output mirrors the same structure with
  CLEAN/FINDINGS/SKIPPED/ERROR labels, scope line, messages, notes, samples (5).
- Consistency: one read-only transaction (InnoDB REPEATABLE READ snapshot, no locks
  taken, writers never blocked). No migrations, no new dependencies, command name
  and options unchanged.

## 5. Decisions rejected

- Auto-repair / write-back of hints→authority: REJECTED (would fork authority).
- New tables/queues: REJECTED (existing schema suffices).
- Stronger isolation (SERIALIZABLE/locking reads): REJECTED (snapshot suffices for
  diagnostics; locks would contend with WMS writers).
- Collapsing skipped/error into clean: REJECTED (the original false-CLEAN class).
