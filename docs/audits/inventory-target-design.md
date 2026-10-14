# Inventory Target Design (IMPLEMENTED)

## Decision 1 — Inventory identity: SKU-level authority rows (ADOPTED)

## Decision
Simple product → `products` row (variant NULL). Each variant → own
`product_variants` row. Placements keyed `(product_id, product_variant_id, location_id)`.

## Evidence
`OrderReservationService::lockStockRow` already branched this way; 21 variant tests
pin it; migration `2026_10_15` adds the variant dimension to hints + ledger.

## Alternatives
(a) New inventory-item identity table — REJECTED: existing schema already provides
SKU rows; new table duplicates authority. (b) Nullable `variant_id` without FK/unique —
REJECTED: weak integrity; adopted FK + SKU UNIQUE + app lock instead.
(c) Polymorphic identity — REJECTED: prevents meaningful DB constraints.

## Trade-offs
No new tables; parent aggregates excluded from sums (no double-count). Nullable-variant
UNIQUE relies on app serialization for simple rows (documented RISK).

## Confidence
High. Revisit trigger: product mandates sibling bin-sharing (contradicts one-SKU-per-bin).

## Decision 2 — Aggregate vs placement authority (ADOPTED)

## Evidence
`StockOperationService` header + `syncWithStock` monitor-only + CHECK constraints.

## Alternatives
Ledger-summed authority — REJECTED: would fork a second ledger beside the existing
reservation lifecycle; hints are projections, not postings.

## Decision
Authority counters stay; hints never write back; drift is reported
(`inventory:audit-placements`), never auto-repaired. `available = stock - reserved`.

## Decision 3 — Reservations aggregate-only, allocation at picking (ADOPTED)

No location-level reserved quantities (inventing them would fork reservation ownership).
Picking allocates from hints; transfer/empty guards respect open tasks/commitments.
Two orders cannot oversell: locked two-pass claim in `reserveForOrder` + state claims.

## Decision 4 — No new inventory read route (ADOPTED)

Mission-suggested `GET /admin/inventory/products/{product}` evaluated and REJECTED as
a duplicate: canonical `GET /admin/products/{product}/placements` already returns
product + variants + authoritative aggregates + full placement distribution.
Alias would fork the read model for zero benefit (YAGNI).

## Decision 5 — Reconciliation is report-only (ADOPTED)

`inventory:audit-placements`: 9 checks, exit 1 on findings, `--warehouse` scoping
(aggregate check skipped under scope — authority is global), `--json`. Writes nothing;
reserved drift stays with `inventory:migrate-reservations --dry-run/--fix`.
Auto-repair REJECTED: no deterministic safe repair exists for hint drift.
