# Catalog placement backfill — operator runbook

Canonical mapping-driven workflow: `inventory:backfill-placements`.
Read-only audit: `inventory:reconcile-catalog`.
Both delegate placement writes to `StockOperationService::recordExistingPlacement`
(authority lock → locked placeable location → occupancy → hint-cap → audit).

## 1. Verify target identity (every run)

```powershell
php artisan db:show --database=mysql   # confirm name/host BEFORE any apply
php artisan inventory:reconcile-catalog --json > reconcile-before.json
```

## 2. Read-only reconciliation

```powershell
# Global report (exit 0 clean, 1 findings, 2 invalid input)
php artisan inventory:reconcile-catalog --json > reconcile.json

# Warehouse-scoped coverage (aggregate checks stay global by design)
php artisan inventory:reconcile-catalog --warehouse=1 --json > reconcile-wh1.json
```

## 3. Mapping file schema

JSON array of rows (all IDs are existing database IDs, quantities are integers ≥ 1):

```json
[
  {"product_id": 12, "product_variant_id": null, "location_id": 5, "quantity": 50},
  {"product_id": 13, "product_variant_id": 44, "location_id": 6, "quantity": 10, "warehouse_id": 1}
]
```

Rules: every row needs an explicit bin (no defaults are ever inferred);
`warehouse_id` is optional per row and consistency-checked when present;
variable products require their variant; digital products are refused;
inactive/unplaceable bins are refused; one SKU per bin; requested hints are
capped at authority stock; existing rows at/above the mapped quantity are
skipped (never rewritten).

## 4. Dry-run first (writes nothing)

```powershell
php artisan inventory:backfill-placements --mapping=mapping.json --json > dryrun.json
# exit 0 = every row would apply; exit 1 = failures listed; exit 2 = bad file
```

## 5. Apply on an isolated database

```powershell
php artisan inventory:backfill-placements --mapping=mapping.json --apply --json > apply.json
php artisan inventory:reconcile-catalog --json > reconcile-after.json
# Compare reconcile-before.json vs reconcile-after.json; rerun apply must be
# a no-op (applied 0, all previously-created rows skipped_covered).
```

## 6. Production guardrails

- Production apply refuses without `--force` (explicit approval).
- Never blanket-assign: rows without a trusted mapping are REPORTED, not placed.
- Never manufacture stock: the primitive never writes `stock_quantity`,
  `reserved_quantity`, or `in_stock`; over-authority mappings fail with
  `hint_cap` and persist nothing.
- Retry safely: reruns converge; per-row failures never abort the batch.
- Recovery: every applied row is audited (`stock.placement_recorded`);
  reconcile before/after reports are the incident-response baseline.

## 7. Exit codes

| Code | Meaning |
| ---- | ------- |
| 0 | success (dry-run valid, or apply with zero failures) |
| 1 | findings (reconcile) / one or more rows failed (backfill) |
| 2 | invalid input (bad warehouse, unreadable/malformed mapping file) |
