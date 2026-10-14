# WMS Variant Integration — Stage 1 Current-State Report (read-only discovery)

Date: 2026-10-14 12:05 local. Branch: `main`. HEAD: `18302194fc9d0a4c7a3f8672cd2a5570a1af32b1` (unchanged).
No destructive commands run. No project files modified in this phase. No work of others touched
(no reset/clean/stash/reset-index/commit). New docs file (this report) is the only output.

## 1. Working-tree state (evidence)

- Staged (index, 9 new files, +2592): `ProductPlacementsResource.php`, `LocationEmptyService.php`,
  `WmsLocationEmptyTest.php`, `WmsMultiLocationTest.php`, `WmsVariantInventoryTest.php`,
  `delete-admin-locations-products.md`, `get-admin-products-placements.md`,
  `wms-final-verification-2026-10-14.md`, `storage/migcheck.php` (+11, worktree copy absent).
- Unstaged modifications (36 files): WMS controllers/services/resources/requests/models/lang/routes/tests
  (full list in §3). Staging area left exactly as found.
- Untracked: `database/migrations/2026_10_15_000001_add_variant_to_wms_inventory.php` (v3, parses clean),
  `docs/api/get-admin-inventory-overview.md`.
- Environment: PHP 8.2.30, Laravel 10.30.1, MySQL 8/InnoDB. A parallel agent is active on `catch`
  (`artisan test --filter=test_reject_missing_variant_for_variable`, then `WmsFulfillmentOrderSyncTest`).
  All Stage 4 test work MUST use an isolated DB (`DB_DATABASE`, supported by phpunit.xml).

## 2. Report-match check (2026-10-14 verification report vs current tree)

| Report claim | Current state | Match |
|---|---|---|
| No working migration supplies `product_variant_id` | v3 migration EXISTS (untracked), parses, UNTESTED (v1 parse-error and v2 FK-1215 both superseded) | Partially changed — Stage 3/4 must prove v3 fresh + upgrade |
| Conflicting product- vs SKU-level occupancy | RESOLVED by in-tree contract: `WmsVariantInventoryTest` case 12 mandates one-SKU-per-bin; all services implement it; old product rule subsumed | Changed — no product stop needed (§5) |
| 99 green on pre-variant snapshot | Still valid for that snapshot; current tree untested (11:43+ variant mutation) | Holds; re-verify on stable tree |
| Concurrency unproven; frontend unverified | Unchanged | Holds |

## 3. Variant modification inventory (all read, none changed)

- Migration (untracked v3): nullable `product_variant_id` FK + index on `product_locations`
  (cascade) and `inventory_operations` (nullOnDelete); MySQL plain UNIQUE(product,location,variant),
  drops old pair UNIQUE; SQLite branch; `down()` best-effort restore.
- Models: `ProductLocation` (variant fillable/relation/`forSku`), `InventoryOperation` (variant fillable).
- `StockOperationService` (784 lines): `resolveAuthority` (simple→Product, variable→variant REQUIRED,
  digital refused), `lockAuthority`, SKU `lockedPlacement` (+1062 fallback, denorm + SKU guards),
  private `assertSkuVacant` (DUPLICATE of shared guard — consolidate), variant snapshots/audit/ledger.
- `ProductLocationService`: SKU `syncWithStock`/`allocateFromLocations`/`suggestForWarehouse`/
  `assertLocationVacantFor(..., ?variantId)`; INCONSISTENCY: `updateLocationQuantity` drops variant
  when calling `syncWithStock` (wrong-SKU monitor — fix).
- `FulfillmentService`: variant allocation + item identity; `assignPlacement` checks product but NOT
  variant (hole — fix + new lang key).
- `ReturnService::restockReturnItem`: variant-BLIND (guard without variant, 3-key placement without
  variant; central restore via `restoreLines` IS variant-correct) — fix by deriving SKU from
  orderItem/fulfillmentItem.
- `InventoryController`: variant passthrough (receive/transfer), placements `?product_variant_id`
  filter + mismatch guard, `overview()` method present but UNROUTED.
- Requests: nullable `product_variant_id` rules (both). Resources: variant/sku/notes fields, compat shape.
- Routes: placements + 2 empty aliases present; **`GET /api/v1/admin/inventory/overview` MISSING**.
- Lang EN+AR: `variant_mismatch`, `variant_required`, `overview_fetched` present; fulfillment
  `placement_variant` key MISSING.
- Tests: `WmsVariantInventoryTest` (20 cases — the contract spec). Docs: 3 variant API docs (parallel).
- Authorities untouched: `OrderReservationService::lockStockRow` (variant-aware),
  `InventoryRestoreService::restoreLines` (variant-aware), Order Flow, payments. Zero diff there.
- Committed base schema: NO variant cols on product_locations/inventory_operations (v3 must supply);
  `order_products.product_variant_id` is a working production dependency (out of scope).

## 4. Schema state (live reads only)

- `catch` mid-RefreshDatabase during reads (tables transiently absent); no variant WMS migration ever
  recorded (only `…_make_promotion_gift_product_variant_nullable` matches `%variant%`).
- Prior evidence stands: `catch.product_locations` = 8 cols, NO variant col; pair UNIQUE only.

## 5. Stage 2 domain decision (recorded — implementation follows)

**Chosen: one inventory SKU per bin.** Simple product (NULL variant) and each variant are distinct SKUs;
siblings may NOT share a bin; zero-quantity rows are history and do not occupy.
Basis: unanimous in-tree requirements (variant test cases 2/4/5/12, `resolveAuthority` requiring variant
for variable products, `forSku` scoping in every placement path) + backward compatibility (old
different-product refusals still refuse; same-SKU re-receipt still merges; old MultiLocation assertions
hold verbatim under the SKU rule). Options considered: (a) one-parent-per-bin — REJECTED (contradicts
test 12 + variant authority split; would force sibling variants into one hint row, corrupting per-SKU
accounting); (b) one-SKU-per-bin — ADOPTED. DB: UNIQUE(product_id,location_id,product_variant_id) with
documented MySQL-NULL semantics (app location-lock serialization is authoritative; FK-induced parent
locking serializes concurrent inserts on InnoDB); service: single shared guard with SKU predicate on all
write paths. No external product decision required; revisit trigger: product explicitly mandates
sibling bin-sharing.

## 6. Stage 3 work plan (minimal, authorized)

1. Validate v3 migration fresh + upgrade (empirical, §7); keep owner attribution (untracked file stays
   untracked until its owner stages it).
2. Register `GET /api/v1/admin/inventory/overview` (`permission:view-location`); update RBAC inventory
   tests 75→76 + names + URI filter (their comments demand deliberate updates).
3. `updateLocationQuantity`: pass placement variant into `syncWithStock`.
4. Consolidate guard: `lockedPlacement` → shared `assertLocationVacantFor(productId, variantId)`; delete
   private duplicate + dead `$key` block.
5. Variant-aware restock (SKU from orderItem→fulfillmentItem fallback) incl. denorm/1062 fail-closed.
6. `assignPlacement` variant-equality guard + `wms.fulfillment.placement_variant` EN+AR.
7. No changes to: reservation/restore/flow/payment authorities, LocationEmpty/destroy, picking execution.

## 7. Stage 4/5 plan

Isolated DB `catch_wms_stage4` (create = non-destructive): migrate fresh (proves v3 fresh path) →
variant suite → WMS/RBAC/lifecycle/regression suites → idempotency + E1–E4 two-process probes with
DB-state snapshots → upgrade-path proof (legacy seed → migrate → assert quantities/history preserved).
Then final diff audit + report. Frontend: NOT VERIFIED (no repo in scope).
