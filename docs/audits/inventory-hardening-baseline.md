# Inventory Hardening Baseline (2026-10-14, second loop)

Branch `main`, HEAD `8f84790`. PHP 8.2.30, Laravel 10.30.1, MySQL 8 (InnoDB).
DB: `catch` (dev, read-only in this loop) + `catch_verify` (isolated, all writes).

## Git state before work (preserved)

Modified (parallel-agent + prior-session, untouched): `app/Console/Kernel.php`,
`app/Http/Controllers/Api/Admin/Wms/InventoryController.php`,
`app/Models/Inventory/InventoryOperation.php`,
`app/Services/Fulfillment/ProductLocationService.php`,
`database/migrations/2026_10_15_000001_add_variant_to_wms_inventory.php`,
`docs/api/*` (many), `packages/marvel/src/Rest/Routes.php`,
`resources/lang/{ar,en}/wms.php`, several `tests/Feature/Fulfillment/*` and
`tests/Feature/Wms/WmsRbacCompletionTest.php`.
Untracked (preserved): prior probes `probe_*_tmp.php`, `stage3_*_tmp.php`,
`closure_*`, new `ListInventoryOperationsRequest`, `InventoryOperationResource`,
`WmsInventoryOperationsListTest`, plus prior-session `AuditInventoryPlacements.php`,
`WmsPlacementAuditTest.php`, `docs/audits/*` (5 docs).

## Verified authorities (all VERIFIED from source/schema)

- Simple: `products.stock_quantity`; variant: `product_variants.stock_quantity`
  (`OrderReservationService::lockStockRow`, mirrored by every service).
- `product_locations.quantity` = placement hint (non-authoritative);
  `allocated_hint` = informational pending-allocation suggestion, NOT reservations
  (rename migration `2026_09_23_000002` explicitly kills the second-authority confusion).
- Reservations live on authority rows (`reserved_quantity`), lifecycle
  none→active→committed/released/restored (`OrderReservationService`,
  `InventoryRestoreService`). Never touched by audit.
- DB guarantees (MySQL): quantity≥0, hint≥0, hint≤quantity (CHECKS);
  SKU UNIQUE(product,location,variant) with NULL semantics; FK cascades on all
  three placement refs; `locations.status` NOT NULL default 'active';
  `locations.type` nullable (NULL = legacy placeable); `products.item_type`
  NOT NULL default PHYSICAL; warehouses + products SoftDeletes; locations none.

## Existing command behavior (TESTED)

`inventory:audit-placements [--warehouse=] [--json]`, 9 checks, read-only,
exit 0/1/2. Prior tests: `WmsPlacementAuditTest` 5 passed.

## Proven defect (TESTED, the driver of this loop)

Seeded product (stock 9, zero placements) on `catch_verify` → audit printed
overall `clean (0)` / "no discrepancies". False CLEAN confirmed: aggregate drift
starts from grouped `product_locations`, so positive-stock-no-placement SKUs are
never examined. JSON also lacks overall status, per-check statuses, and scope
markers; `SKIPPED` (scoped aggregate, missing-schema non-physical) collapses toward
clean-shaped output.

## Pre-existing failures

None observed in scope; full regression runs in Phase 6 will separate any.
