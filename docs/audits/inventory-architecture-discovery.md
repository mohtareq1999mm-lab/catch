# Inventory Architecture Discovery (VERIFIED, 2026-10-14)

Branch `main`, HEAD `8f84790`. Read-only discovery first; implementation followed.
Scope: TARGET repo `D:\work\meem` only. `D:\work\meem_old`, `D:\work\new meem` are
OUT OF SCOPE (never merged). Root `probe_*_tmp.php` / `stage3_*_tmp.php` /
`storage/master_*_tmp.php` / `closure_*_verify.php` are prior-agent GENERATED
ARTIFACTS (left untouched). Untracked `WmsMasterInventoryGapsTest`,
`WmsReceivingReliabilityTest` are prior-agent tests (executed, not claimed as ours).

## 1. Actual schema (VERIFIED from migrations + live MySQL 8)

- `products`: `stock_quantity`, `reserved_quantity`, `sold_quantity`, `in_stock`,
  `product_type` (simple/variable), `item_type` (PHYSICAL/DIGITAL, NULL=legacy physical),
  SoftDeletes. Aggregate authority for SIMPLE products.
- `product_variants`: `stock_quantity`, `reserved_quantity`, `sold_quantity`,
  `in_stock`, `sku`, `product_id` FK. Per-SKU authority for VARIABLE products.
- `product_locations`: `(product_id, location_id, product_variant_id NULL)`,
  `warehouse_id` denorm, `quantity`, `allocated_hint`; CHECKs
  (`quantity>=0`, `allocated_hint>=0`, `allocated_hint<=quantity`);
  UNIQUE `(product_id, location_id, product_variant_id)` (MySQL NULL semantics:
  multiple NULL simple rows allowed at DB level; app location-lock is authoritative).
  NON-AUTHORITATIVE placement hints — monitor-only `syncWithStock`, never written back.
- `inventory_operations`: idempotency ledger (`idempotency_key` UNIQUE, operation,
  product/variant, warehouse, source/dest locations, quantity, status, result JSON).
- `warehouses` (SoftDeletes, default rules), `locations` (status, placeable types,
  immutable `warehouse_id`), `order_products` (`product_variant_id`, `item_type`,
  `restored_quantity`), `orders.inventory_state` (`none/active/committed/released/restored`).

## 2. Sources of truth (VERIFIED)

- Sellable availability: `stock_quantity - reserved_quantity` on the SKU authority row
  (`OrderReservationService::lockStockRow` branches variant/product — the single rule
  every service mirrors).
- Reservations: `OrderReservationService` (none→active→committed/released, locked
  conditional claims, idempotent). Restoration: `InventoryRestoreService`
  (committed→restored + partial `restoreLines` capped by `restored_quantity`).
- Intake/relocation: `StockOperationService` (receive += authority & hint; transfer
  moves hints only, authority provably unchanged; `placeInitialStock` assigns once).
- Fulfillment/picking/packing/shipment NEVER write stock counters (only
  `quantity_picked`, task states) — VERIFIED by search (zero `stock_quantity` writes
  in Shipment/Fulfillment/Picking services).
- Pricing/coupons/payments/Order Flow are separate authorities, untouched.

## 3. Variant behavior (VERIFIED)

Simple = NULL variant, authority = Product, variant REQUIRED to be null.
Variable = variant REQUIRED, must belong to product, authority = ProductVariant,
parent never written. Digital refused everywhere (D1). One SKU per bin
(`assertLocationVacantFor` shared guard; zero rows are history). BarcodeResolver,
FulfillmentService::assignPlacement, PickingExecutionService, ReturnService::restock
are all variant-aware (prior holes closed in-tree before this session).

## 4. Mutation matrix (VERIFIED, active paths only)

| Operation | Entry point | Authority | Txn/locks | Variant-aware |
|---|---|---|---|---|
| Receive | POST locations/{id}/stock-receipts → StockOperationService | authority+hint | txn, authority→location→placement locks, idempotency UNIQUE | yes |
| Transfer | POST stock-transfers → StockOperationService | hints only | txn, deterministic placement order, E-guards + post-lock recheck | yes |
| Initial placement | ProductRepository::storeProduct → placeInitialStock | authority assign + hint | caller's txn + savepoint | yes (variable refused without SKU) |
| Reserve/commit/release | OrderService checkout, PaymentTrait, CancelUnpaidOrders → OrderReservationService | reserved/stock counters | txn, locked state claims | yes |
| Restore | cancel/refund/return → InventoryRestoreService | stock/sold counters | txn, committed claim + remainder cap | yes |
| Pick/pack/ship | WMS controllers → Fulfillment/Picking/Packing/Shipment | none (progress only) | row locks, op_seq idempotency | identity-aware, no counters |
| Return restock | ReturnService::restockReturnItem | restoreLines + hint | txn, warehouse + SKU guards | yes |
| Audit | inventory:audit-placements (new, read-only) | none | read-only | yes |

DEAD (unwired, preserved): `Marvel OrderRepository::deductStock/validateAndLockStock`
(Variation-based, no route), `ManageProductInventory` + `ProductInventoryRestore`
listeners (not in either EventServiceProvider). Documented, not deleted.

## 5. API contracts (VERIFIED via route:list)

- POST `/api/v1/admin/locations/{location}/stock-receipts` (inventory-adjust)
- POST `/api/v1/admin/stock-transfers` (inventory-adjust)
- GET `/api/v1/admin/products/{product}/placements` (view-location, `?product_variant_id`, `?warehouse_id`)
- GET `/api/v1/admin/inventory/overview` (view-location, global totals + scoped bins/txns)
- GET `/api/v1/admin/inventory/operations/{key}` (view-location, Case-C resolution)
- Envelope `{status,message,success,data}` + AR/EN `resources/lang/{en,ar}/wms.php` (all keys present incl. `placement_variant`).

## 6. Risks / unknowns

- RISK: MySQL UNIQUE allows duplicate NULL simple rows; guarded by app lock only.
- RISK: hints drift from authority by design (accepted, monitored).
- UNKNOWN: external frontend callers (no frontend in repo; `resources/js` has no inventory UI).
- BLOCKED: none for this scope. Genuine multi-node concurrency beyond 2-process probe not exercised.
