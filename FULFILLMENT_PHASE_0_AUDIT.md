# FULFILLMENT & WAREHOUSE — PHASE 0 DISCOVERY AUDIT (read-only)

> Project: `D:\work\meem` · Date: 2026-09-30 · Mode: DISCOVERY ONLY.
> Production files modified: 0. Migrations modified: 0. Tests modified: 0. DB touched: NO.
> Baseline: Order Flow Single Source of Truth (Final Evidence Report A–L) treated as VERIFIED/CLOSED.
> This audit found **no concrete contradiction** requiring reopening any D0–D10 decision.
> Prior session artifact `FULFILLMENT_IMPLEMENTATION_PLAN.md` (2026-09-29) describes work this audit
> independently verifies as **already implemented** (WarehouseService, claim lease, batch guard,
> granular perms, snapshot, single-default backstop). That plan is superseded by the Phase-0 plan.

## A. Executive Summary

- The WMS is a **service-layer-only operations engine**: 8 services, 13 models, 22 migrations,
  permission constants + role seeds, scheduled claim sweeper. There is **no HTTP surface** for any
  WMS entity (warehouse/location/fulfillment/picking/batch/packing/package) — all execution is via
  service calls (today: tests only) or Artisan (`picking:sweep-expired-claims`).
- Fulfillment creation has **zero production callers**: no payment-success hook, no COD hook, no
  event/listener/job/CLI path. `releaseForOrder` is idempotent and releasability-gated, but nothing
  invokes it outside tests. §§5–7 business wiring is therefore MISSING (service EXISTS-VERIFIED).
- The operational state machine (`Fulfillment::allowedTransitions`) is **correctly decoupled** from
  Order Flow: no fulfillment/picking/packing/shipment code writes `orders.status`; the only
  Order-Flow write is `ShipmentService::maybeCompleteOrder` via canonical
  `OrderService::changeOrderStatus` (completed→delivered absorption, already approved in flow audit).
- Warehouse governance (§§8–10,31) is the strongest area: `WarehouseService` + partial-unique DB
  backstop + write-once snapshot + FK restrict. One real conflict: business allows warehouse deletion
  (§11) but `fulfillments.warehouse_id` is `ON DELETE RESTRICT` — deletion is schema-blocked.
- Inventory authority is clean: central `products.stock_quantity`/`reserved_quantity` remain master;
  `product_locations.quantity`/`allocated_hint` are explicitly non-authoritative placement hints with
  drift-monitor (never a gate) and denorm guard. No second inventory master exists.
- Permissions are **defined but unenforced**: 8 granular constants + picker/packer/supervisor/manager
  role seeds exist, `WarehouseAccess` boundary exists, but zero production callers wire them to any
  action (no HTTP layer to guard; services are caller-enforced by contract only).
- Package cardinality **conflicts** with business: code + tests implement multi-package
  (`PackingTest` creates packageA+packageB); §24 requires one package per fulfillment; no DB unique
  enforces either. NEEDS BUSINESS DECISION (do not touch until decided).
- Dead states/paths: `FulfillmentCompleted` event (no dispatch, no listener), package
  `handed_off`/`voided` (no writer methods), unrouted `App\Http\Controllers\Api\ShipmentController`
  (its raw `create()` bypasses the fulfillment boundary — unrouted, but must stay unrouted or be fixed).
- Net classification: 13 EXISTS-VERIFIED · 11 EXISTS-PARTIAL · 4 CONFLICTING · 9 MISSING ·
  5 DEAD/UNUSED · 0 DUPLICATED second-engines · 3 UNSAFE races · 2 NEEDS BUSINESS DECISION.

## B. Repository Architecture

| Area | Location | Files |
|---|---|---|
| Models | `app/Models/Fulfillment/` | Fulfillment, FulfillmentItem, FulfillmentBatch, PickingTask, PackingTask, PackingStation, Package, PackageItem, Location, ProductLocation, Warehouse, ReturnRequest, ReturnItem |
| Services (ops) | `app/Services/Fulfillment/` | FulfillmentService, FulfillmentTransition, OrderPickingService, BatchPickingService, PickingExecutionService, PackingService, ProductLocationService, ReturnService |
| Services (warehouse) | `app/Services/Warehouse/` | WarehouseService, WarehouseAccess, BarcodeResolver |
| Services (shipment) | `app/Services/Shipment/` | ShipmentService |
| Shipment model | `app/Models/Shipment.php` | 10-state machine, fulfillment/packingTask relations |
| Config | `config/fulfillment.php` | `claim_lease_minutes` (15), `sweep_limit` (100) |
| Migrations | `database/migrations/` | 22 files (2026-07 through 2026-10-03 hardening) |
| Seeders | `database/seeders/` | WarehouseSeeder (MAIN + 7 placeable locations), PermissionSeeder::seedWarehouseRoles |
| Permissions enum | `packages/marvel/src/Enums/Permission.php:311-332` | 8 coarse + 8 granular WMS constants |
| Scheduler | `app/Console/Kernel.php:32` | `picking:sweep-expired-claims` every 5 min |
| Sweep command | `app/Console/Commands/SweepExpiredPickingClaims.php` | delegates to `sweepExpiredClaims` |
| Exceptions | `app/Exceptions/` | PickingValidationException, UnknownBarcodeException |
| Events (WMS) | `app/Events/Fulfillment/FulfillmentCompleted.php` | DEAD (no dispatch/listener) |
| Controllers (WMS) | — | NONE (no fulfillment/warehouse/picking/batch/packing/package controller) |
| Shipment controllers | `app/Http/Controllers/Api/ShipmentController.php` (UNROUTED) + `Api/Admin/ShipmentController.php` (routed: `orders/{orderId}/shipment`) | — |
| Routes (WMS) | `routes/api.php`, `packages/marvel/src/Rest/Routes.php` | NONE for WMS entities; only pickup-locations + shipment-admin |
| Tests | `tests/Feature/Fulfillment/` (7), `tests/Unit/Services/Fulfillment/` (6), `tests/Feature/Warehouse/` (2), `tests/Feature/OrderFlow/SubsystemBindingTest.php` | service-level only, no HTTP tests |
| Docs | `docs/FULFILLMENT_ARCHITECTURE_MAPPING.md` | 738-line mapping; states stock-master authority (consistent with code) |

Call-graph fact (evidence): `releaseForOrder|createFromOrder|createBatchFromFulfillments|
createPackingTaskFromFulfillment|createPackage` have **zero callers** in `app/`, `routes/`,
`config/`, `packages/marvel/src` — only `tests/` invoke them. Fulfillment is unreachable in production.

## C. Database Schema

- `warehouses`: PK id, `code` UNIQUE, `status` default active, `is_default` bool default false,
  index(status,is_default); hardening migration adds generated `default_singleton` + UNIQUE (MySQL),
  partial unique index (pgsql/sqlite). No soft deletes. Survivor-dedupe before backstop.
- `locations`: FK warehouse_id CASCADE, parent_id SET NULL, UNIQUE(warehouse_id,code),
  index(warehouse_id,status,priority); `barcode` column added by `2026_09_23_000002` hint migration.
  Deleting a warehouse cascades locations away (history loss for locations; fulfillments keep snapshot).
- `product_locations`: FK product CASCADE, location CASCADE, warehouse CASCADE (denorm, perf);
  UNIQUE(product_id,location_id); `reserved_quantity` renamed → `allocated_hint` by `2026_09_23_000002`
  with CHECK renames (allocated>=0, allocated<=quantity on mysql). Placement hints only.
- `fulfillments`: FK order CASCADE, warehouse RESTRICT; `fulfillment_number` UNIQUE;
  `idempotency_key` UNIQUE nullable (race backstop); `ready_to_ship_at`; snapshot
  `warehouse_code/name` nullable (backfilled, write-once via model guard); indexes
  (order_id,status),(warehouse_id,status,priority),(assigned_to,status).
- `fulfillment_items`: FK fulfillment CASCADE, order_item (order_products) CASCADE, product RESTRICT,
  variant RESTRICT nullable, product_location SET NULL nullable; qty + qty_picked decimals.
- `picking_tasks`: batch_id NULLABLE (order-picking) FK fulfillment_batches CASCADE;
  claim fields (claimed_by users SET NULL, claimed_at, claim_expires_at); denorm order_id/order_item_id
  (plain BIGINT, no FK); op_seq + scan_log; indexes (batch_id,sequence),(order_id,status),(claimed_by,status).
- `fulfillment_batches`: FK warehouse RESTRICT; batch_number UNIQUE; counters total/picked_items.
- `packing_tasks`: FK fulfillment CASCADE, station SET NULL, assigned_to SET NULL; weight/dimensions/materials.
- `packages`: FK fulfillment CASCADE, order_id plain nullable, packing_task SET NULL nullable;
  package_number UNIQUE, barcode UNIQUE nullable; status open/sealed/handed_off/voided; NO unique on
  fulfillment_id (multi-package physically possible). `package_items`: UNIQUE(package_id,
  fulfillment_item_id), fulfillment_item RESTRICT (protects packed audit).
- `shipments`: fulfillment_id SET NULL nullable, packing_task_id SET NULL nullable,
  idempotency_key (`2026_09_23_000005`); legacy order-level shipment columns coexist
  (shipment_status/tracking on orders, written by admin controller + timeline listener).

## D. Model Relationships

- Order 1—N Fulfillment (FK order CASCADE; multi-fulfillment per order physically allowed, explicit only).
- Fulfillment N—1 Warehouse (RESTRICT; snapshot code/name write-once via `booted()` guard).
- Fulfillment 1—N FulfillmentItem (CASCADE); item N—1 ProductLocation (SET NULL — allocation loss
  tolerated, item survives with notes); item N—1 OrderProduct (CASCADE).
- PickingTask N—1 FulfillmentItem (CASCADE), N—1 ProductLocation (RESTRICT — cannot delete a location
  referenced by a task; forces explicit reallocation first), N—1 Batch nullable (CASCADE),
  denorm order_id/order_item_id (no FK — traceability only, never authoritative).
- Fulfillment 1—N PackingTask (CASCADE); PackingTask N—1 Station (SET NULL); 1—1 Shipment via
  packing_task_id (SET NULL); Fulfillment 1—N Shipment (SET NULL).
- Fulfillment 1—N Package (CASCADE; cardinality CONFLICTS with §24 — see Y).
- Package 1—N PackageItem (CASCADE); PackageItem N—1 FulfillmentItem (RESTRICT).
- Location N—1 Warehouse (CASCADE), self-tree parent/children (SET NULL), 1—N ProductLocation (CASCADE).
- ProductLocation N—1 Product (CASCADE) + denorm N—1 Warehouse (CASCADE) — drift possible, guarded at
  read time (`assertConsistent`), never trusted.
- Batch N—1 Warehouse (RESTRICT); Batch 1—N PickingTask; NO batch↔order join table — membership is
  derived via tasks→items→fulfillments→orders (persistent through task rows, queryable, no separate ledger).

## E. Warehouse Audit (§§8–10,31)

- Selection precedence (§8): `WarehouseService::resolveForNewFulfillment` — explicit wins (with
  `assertActive`), else lowest-id active default; throws on missing/inactive. Wired into
  `FulfillmentService` (both creation doors) and `BatchPickingService`. EXISTS-VERIFIED.
- Active gate (§9,§31): creation-time only; existing fulfillments/batches continue after deactivation
  (no stop listener; nothing polls warehouse status). EXISTS-VERIFIED.
- Single default (§10): `setDefault` transactional + `lockForUpdate` + requires active target +
  partial-unique DB backstop + deploy-time dedupe. `deactivate` BLOCKED for current default with
  explicit error (no auto-replacement). Zero-default → loud `RuntimeException` at creation.
  EXISTS-VERIFIED. Concurrency: two simultaneous `setDefault` serialize on row locks; UNIQUE is final
  backstop. `WarehouseSeeder` creates exactly one default (MAIN). Seeded types are placeable.
- WarehouseAccess: home-warehouse scoping + global `manage-warehouse`, denial→exception (controllers
  would map 404/409). Zero production callers — EXISTS-PARTIAL (boundary exists, unwired).

## F. Location Audit (§§14,32 + §12)

- One-warehouse anchoring: UNIQUE(warehouse_id,code); allocation and reallocation both constrain to
  the fulfillment warehouse. `warehouse_id` is mass-assignable (location move possible — no move guard;
  drift caught by `assertConsistent` at allocation and by reallocation checks). EXISTS-PARTIAL.
- Suggestion (§14 system): `allocateFromLocations`/`suggestForWarehouse` — active+placeable+hasStock,
  same-warehouse, priority-desc then quantity-desc, split across locations allowed (A1=3+A3=2 pattern
  natively supported), throws with found-vs-required context on shortage. EXISTS-VERIFIED.
- Manual choice (§14 human): `reallocateTask` — same-warehouse, same-product, target active+placeable,
  placement-vs-location drift check, terminal-task guard. EXISTS-VERIFIED for tasks.
- GAP: `FulfillmentItem` created with `product_location_id = NULL` on allocation failure has **no
  assignment path** — `createTasksForFulfillment` skips null items permanently ("manual assignment
  required" comment, but no method assigns it). MISSING.
- Inactive-during-picking (§32): allocation excludes inactive via `scopePlaceable`; `reallocateTask`
  rejects inactive targets; nothing auto-detects a location that deactivates mid-pick (no watcher) —
  recovery is operator-driven via reallocate. EXISTS-PARTIAL.

## G. Inventory Authority (§13)

- Master: `products.stock_quantity` / `reserved_quantity` (`available = stock - reserved`).
  Writers: `OrderReservationService` (reserve ACTIVE / commit / release RELEASED, all `lockForUpdate`),
  `InventoryRestoreService` (paid-cancel restore), `PaymentRefundService` reads committed flag.
  Fulfillment code never touches product stock. EXISTS-VERIFIED — no second master.
- Placement: `product_locations.quantity` + `allocated_hint` are hints. `syncWithStock` is
  MONITOR-ONLY (logs drift, returns bool, never throws — locked decision). Allocation never mutates
  central inventory and never decides sellability. `updateLocationQuantity` guards negatives and
  hint-floor under lock. ReturnService seeds return placements with `allocated_hint = 0`.
  EXISTS-VERIFIED.
- Reservation truth for releasability: `orders.inventory_state` (ACTIVE for COD/pay_at_cashier,
  COMMITTED+paid for capture methods). `assertReleasable` encodes exactly this. EXISTS-VERIFIED.

## H. Fulfillment Creation (§§5–7)

- Doors: `releaseForOrder` (public: idempotency-key reuse + pending (order,warehouse) reuse + order
  `lockForUpdate` + `assertReleasable` + UNIQUE backstop, all one transaction) and `createFromOrder`
  (low-level, same warehouse resolution + snapshot + per-item allocation with null-fallback items).
  Duplicate-safe, race-safe. EXISTS-VERIFIED as a service.
- Wiring: **no production caller** — no `PaymentSucceeded` listener, no COD hook, no event, no job,
  no CLI, no HTTP. Online-payment (§6) and COD (§7) release paths are therefore MISSING end-to-end.
  `FulfillDigitalProducts` handles digital only (separate path, unaffected). The releasability rule
  itself correctly distinguishes COD (ACTIVE reservation, NOT marked paid) from capture methods
  (COMMITTED + paid) — so the designed rule satisfies §7's "no false paid marking".
- One-warehouse (§12): single-warehouse allocation per fulfillment, no fallback loop, shortage throws
  (→ null item + notes, fulfillment stays incomplete-able via retry/manual). BUT nothing prevents a
  second fulfillment for the same order in a *different* warehouse via separate call — rule is
  conventional, not enforced. EXISTS-PARTIAL.

## I. Picking (§§14–21,30,32)

- Lifecycle: `OrderPickingService::createTasksForFulfillment` (idempotent reuse of open tasks, skips
  null-allocation items) → `claim` (conditional pending→assigned, exactly-one-winner, same-worker
  idempotent refresh, expired-lease steal, override flag) → `confirm` (scan-validated location/product/
  remaining-quantity, `op_seq` replay idempotent, scan_log evidence, partial progress on item via
  `increment`) → batch auto-advance or explicit verify downstream. Retry = re-confirm against
  re-read remaining (no history record required or kept). Shortage (§15/§30): partial picks persist,
  task stays open, `Incomplete` is a state of quantities — never auto-failed. EXISTS-VERIFIED (engine).
- Claim expiry (§19): lease configurable (`config/fulfillment.php`, env override, per-call override),
  sweeper covers assigned+picking preserving progress, scheduled every 5 min `withoutOverlapping`.
  EXISTS-VERIFIED.
- Methods (§17): scan-validated `confirm` + scan-free `recordPick` (batch direct entry, same
  remaining-guard) + `override` escape hatch; `BarcodeResolver` resolves location/product/variant/
  fulfillment/package/order. Manual + barcode both supported, scan optional. EXISTS-VERIFIED.
- Access (§18): `assignToUser`/`assignBatch` (explicit) + claim-any-pending (self-selection).
  Claim race safe; **assignment writes have no locks** (`assignBatch`, `assignToUser`, `assignToStation`,
  `startPicking`, `startPacking` — last-write-wins). UNSAFE under concurrent assignment.
- Complete picking (§20): NO explicit permission-gated complete-picking action exists; completion
  emerges from task confirms + fulfillment `picked` transition. `picking.complete` permission exists
  but is wired to nothing. EXISTS-PARTIAL.
- After picking (§21): NOTHING auto-advances Order Flow; fulfillment `picked→packing` is operational
  (owner-enclosed), Order stage moves only via existing flow APIs. EXISTS-VERIFIED (correct decoupling).

## J. Batch (§22)

- `createBatchFromFulfillments`: rejects empty set; enforces one warehouse + one Order Flow + one
  current stage with offending-fulfillment/order context; requires all fulfillments `pending`;
  resolves warehouse via authority; groups tasks by location (route-optimized); moves fulfillments
  pending→picking **via `FulfillmentTransition`** (not direct write); fan-out denorm order_id/item_id.
  No separate batch flow; orders keep own flow. EXISTS-VERIFIED.
- Batch lifecycle (assign/start/record/skip/cancel/refresh) is service-complete; `recordPick` and
  engine-`confirm`+`refreshBatchProgress` dual paths share remaining-guards; completion advances only
  fully-picked fulfillments still in `picking`. `cancelBatch` requires non-terminal, skips open tasks.
  Concurrency: creation is transactional; `assignBatch`/`startPicking` unlocked (same UNSAFE note as §I).

## K. Packing (§§23,25)

- `createPackingTaskFromFulfillment` allows `picked|packing` only (flow-agnostic operational gate —
  does not hard-code picking→packing as universal; task creation is callable whenever the
  fulfillment is in a packable operational state). `assignToStation` (active station only) →
  `startPacking` → `completePacking` (task `packed`; fulfillment deliberately stays `packing` —
  ghost-state removal) → `verifyPacking` (task `verified`; fulfillment → `ready_to_ship` via owner).
  Packing completion (§25) is explicit + separate verify step, but NEITHER step checks a permission
  (service-level; `packing.complete` perm unwired). EXISTS-PARTIAL (mechanics verified, auth missing).

## L. Package (§24)

- `createPackage` (fulfillment `packing|picked`), `addItemToPackage` (locked read-check-write enforcing
  Σ package_items ≤ quantity_picked, cross-fulfillment + over-pack + unpicked + sealed-package rejections),
  `sealPackage` (non-empty, barcode issuance). Multi-package physically allowed and covered by
  `PackingTest` (packageA + packageB). **CONFLICTS with §24 one-package rule**; no DB unique either
  way. NEEDS BUSINESS DECISION — do not implement until decided.
- `handed_off`/`voided` statuses have no writer methods (no handoff, no void, no supervisor reopen);
  only read is the voided-exclusion in the over-pack guard. DEAD states.

## M. Shipment Boundary (§26 — audit only, no redesign)

- Approved boundary respected: `createForFulfillment` requires locked `ready_to_ship` + idempotency;
  `dispatch` moves shipment→picked_up AND fulfillment ready_to_ship→shipped atomically;
  `markDelivered` walks carrier chain, fulfillment→delivered, then `maybeCompleteOrder` (completed +
  paid + all-fulfillments-delivered + at-least-one-fulfillment) via canonical
  `OrderService::changeOrderStatus` (completed→delivered absorption approved in flow audit).
  Digital-only orders explicitly excluded. EXISTS-VERIFIED.
- Parallel legacy surface (OUT OF SCOPE, noted): `Api/Admin/ShipmentController` writes
  `orders.shipment_status/tracking/courier/ETA` via `ShipmentStatusChanged` → timeline listener
  (timeline + denorm update, no flow write). `App\Http\Controllers\Api\ShipmentController` (with raw
  `ShipmentService::create` bypassing the fulfillment gate) is **unrouted** (routes commented) —
  DEAD but hazardous: must stay unrouted or be hardened before routing. UNSAFE-if-routed.

## N. Cancellation (§§29–30 + flow)

- `cancelFulfillment` requires non-blank reason, transitions via owner, skips open picking tasks
  (picked progress preserved for audit), cancels open packing tasks, never touches inventory
  (owned by order-cancel path: paid→restore COMMITTED, unpaid→release ACTIVE, coupon release,
  promotion-decrement rules). Records preserved (state cancellation, no deletes). EXISTS-PARTIAL.
- GAPS: no customer-vs-internal distinction (no guard blocking customer cancel after start — no API
  exists to attempt it, but no service guard either); `order.cancel-during-fulfillment` perm unwired;
  order→fulfillment cascade missing (cancelling an order leaves fulfillments orphan-open — documented
  orphan risk); packages/shipments untouched by cancel (no void path — blocked by missing void writer).
- Flow interaction: cancellation of the ORDER goes through verified `changeOrderStatus`
  (cancelled↔completed guards intact); fulfillment cancel never writes order state. No competing
  cancellation machine. EXISTS-VERIFIED (decoupling), MISSING (cascade + guards).

## O. Permissions (§28)

- Defined: 8 coarse (`view/manage-warehouse|location|fulfillment`, `picking/packing-execute`,
  `fulfillment-override`, `inventory-adjust`) + 8 granular (`fulfillment.create/cancel`,
  `picking.claim/complete`, `packing.complete`, `batch.manage/operate`,
  `order.cancel-during-fulfillment`) in `Permission.php:311-332`, seeded + role-mapped
  (picker/packer/supervisor/manager, financial perms structurally excluded from picker/packer).
  `WarehouseSecurityTest` proves assignment + `WarehouseAccess` scoping. EXISTS (data) — PARTIAL.
- Enforced: nowhere in production (no middleware/policy/service `denyUnless` call; services declare
  caller-enforced contract, e.g. `PickingExecutionService:19-20`). No role hard-coding anywhere
  (verified — no `role == picker` checks). Classification: enforcement MISSING by design-deferral;
  becomes UNSAFE the moment any WMS HTTP endpoint is added without wiring.

## P. APIs (§36)

- WMS entities: NO routes, NO controllers, NO FormRequests, NO Resources. Classification: MISSING
  (entire §36 surface). Consequence: idempotency/concurrency/auth analysis at HTTP layer is N/A —
  service layer already carries idempotency keys, locks, and validation.
- Shipment: public `ShipmentController` UNROUTED (dead); admin order-shipment read/update-status
  routed with `view-shipment|…` / `update-shipment|create-shipment` perms (legacy order-denorm path,
  intact). Pickup-locations routed (unrelated domain). No WMS breaking-change risk today.

## Q. Events / Jobs / Queues (§§5,34)

- Fulfillment domain events: exactly one class (`FulfillmentCompleted`) with zero dispatches and zero
  listeners — DEAD. No picking/packing/batch events. After-commit semantics N/A (nothing dispatched).
- Active queue work touching fulfillment: none. `FulfillDigitalProducts` (PaymentSucceeded→digital,
  idempotent, tries=5) is the only auto-fulfillment wiring and is digital-only — physical fulfillment
  has no equivalent (MISSING per §H, needs business decision, not an oversight to silently fix).
- Schedulers: claim sweeper every 5 min (verified in Kernel); no fulfillment auto-release schedule.

## R. Concurrency (§35)

| Race | Verdict | Evidence |
|---|---|---|
| default change ×2 | SAFE | row locks + txn + partial-unique backstop |
| fulfillment duplicate create | SAFE | order lock + pending reuse + UNIQUE(idempotency_key) |
| claim ×2 | SAFE | `lockForUpdate` + conditional, 409 semantics; same-worker idempotent |
| confirm replay ×2 | SAFE | `op_seq` idempotent + locked remaining-check |
| pick ×2 (same task) | SAFE | locked remaining guard in both `confirm` and `recordPick` |
| over-pack ×2 | SAFE | locked read-check-write (unique alone insufficient — documented) |
| batch create ×2 | SAFE (creation) | single txn + fail-fast scope check + owner transitions |
| assign/start (batch, fulfillment, station, packing) | UNSAFE | no locks, last-write-wins (`assignBatch`, `assignToUser`, `assignToStation`, `startPicking`, `startPacking`) |
| cancel × pick/pack | PARTIAL | cancel is transactional + status-scoped (`whereIn` open states); in-flight `confirm` holding an older lock may still post progress after skip — benign (progress preserved, task skipped) but uncoordinated |
| payment × fulfillment create | SAFE (by absence) | nothing auto-creates; manual release locks the order row |
| location/warehouse deactivation mid-work | SAFE (design) | creation-time gates only; reallocate path for recovery |

## S. Database Integrity (§§34,38)

- DB-enforced: single default (partial unique), warehouse code unique, location code per-warehouse
  unique, placement pair unique, fulfillment/batch/package numbers unique, idempotency uniques,
  non-negative + hint-floor CHECKs (mysql), RESTRICT on warehouse/batch/location-task/package-item
  links, CASCADE for compositional children, check on `reserved→allocated` rename migration.
- Application-enforced: active-warehouse gate, releasability, scope guards, over-pack invariant,
  write-once snapshot, cross-warehouse/drift checks, terminal-state guards.
- NOT enforced: one-package-per-fulfillment (either way), one-warehouse-per-order, customer-cancel
  block, zero-vs-one default at DB level (zero defaults pass DB, fail loudly at creation — accepted),
  `handed_off`/`voided` reachability, order→fulfillment cancel cascade.

## T. Test Coverage (§§39,31)

- 16 files: 7 feature (lifecycle, batch, order-picking, packing, concurrency-attack, return-recovery,
  shipment-boundary) + 6 unit (service, hardening, batch, packing, placement, returns) + 2 warehouse
  (barcode, security) + `SubsystemBindingTest` (flow↔fulfillment↔shipment contract). Service-level
  happy paths + negative paths (inactive/missing warehouse, scope mismatch, over-pick/over-pack,
  duplicate key/claim, placement mismatch) + concurrency attacks + boundary guards. No HTTP tests
  (nothing to test — no API), no multi-user claim-race test at HTTP level, no chaos test for
  deactivation-mid-pick (service paths covered, scheduler covered by unit sweep test).
- Overall: service behavior well-proven; integration (auto-release), API, and permission-enforcement
  coverage are MISSING because those layers do not exist yet.

## U. Order Flow Integration (§§4,16,21,33 — MOST IMPORTANT)

- No fulfillment/picking/packing/shipment service writes `orders.status` or `orders.order_status`
  (bypass grep: only fulfillment-status writes via `FulfillmentTransition`, task writes, shipment
  writes, and the order-denorm timeline listener for `shipment_status` — a non-flow column).
  The single Order-Flow mutation reachable from this domain is `maybeCompleteOrder` → canonical
  `changeOrderStatus` (completed→delivered absorption, flow-approved). Fulfillment DAG transitions
  are operational only and never assume the next Order stage (picking completion leaves the Order
  untouched; packing/shipment readiness likewise). Final order stage stays flow-determined
  (`delivered` via absorption, never hard-coded by fulfillment). **NO BYPASS. NO SECOND ENGINE.**
  The fulfillment DAG (`pending→picking→picked→packing→ready_to_ship→shipped→delivered`) is a
  subordinate operational tracker, not a competing order lifecycle. VERIFIED — do not merge the two.

## V. Bypass Audit (§40)

- `orders.status` writers reachable from WMS: NONE (only `OrderService::changeOrderStatus` via
  `maybeCompleteOrder`). `orders.order_status`: NONE (D4 mirror sole writer untouched).
  `fulfillments.status`: ONLY `FulfillmentTransition::transition` (plus idempotent no-op same-state).
  `picking_tasks` / `packing_tasks` / `packages`: owning services only, all locked where racy except
  the UNSAFE assign/start list in §R. `product_locations`: `updateLocationQuantity` (locked) +
  ReturnService seeding; `warehouses.is_default`: ONLY `setDefault` (+ deploy dedupe);
  `warehouse_id`/`location_id` assignment: creation + `reallocateTask` (guarded). No raw SQL / query-
  builder backdoors found in the WMS. Test-only writers are hermetic seeders. VERDICT: no live bypass.

## W. Existing vs Required Matrix

See `FULFILLMENT_PHASE_0_GAP_MATRIX.md` (every business rule §5–§38 mapped with evidence).

## X. Risks

1. Orphan fulfillments on order cancel (no cascade) — operational confusion + reserved-stock release
   while picks continue. 2. Unrouted raw shipment `create()` — one route-registration away from a
   boundary bypass. 3. Unlock assignment writes — supervisor/picker double-assign races. 4. Null-
   allocation items permanently stuck (no assignment path). 5. Warehouse deletion blocked by FK vs
   business-allowed — first admin attempt will 500. 6. Permission shell without enforcement — any
   future endpoint added without `WarehouseAccess` silently opens cross-warehouse access.
   7. Multi-vs-single package undecided — packing work may build on the wrong invariant.

## Y. Contradictions (STOP-or-decide, do not silently resolve)

- Y1 (§24 vs code+tests): one-package rule vs multi-package implementation + `PackingTest` multi-split.
- Y2 (§11 vs schema): deletion allowed vs `ON DELETE RESTRICT` blocking it.
- Y3 (§§5–7 auto-creation vs current manual posture): wiring auto-release needs a business/ops decision
  (which payment events, COD timing, idempotency key source, failure alerting) — the prior session's
  P0-3 correctly deferred this; this audit confirms deferral is still the safe posture.
- No contradiction with Order Flow D0–D10 found.

## Z. Evidence Index

Services: `app/Services/Fulfillment/FulfillmentService.php` (release 37-85, releasable 90-118, create
123-170, item 175-229, cancel 248-278), `FulfillmentTransition.php` (25-56),
`app/Services/Warehouse/WarehouseService.php` (25-111), `WarehouseAccess.php` (whole),
`BarcodeResolver.php` (28-79), `ProductLocationService.php` (allocate 60-119, suggest 125-128,
assertConsistent 134-144, updateQty 149-182, sync monitor 20-52), `OrderPickingService.php` (whole),
`PickingExecutionService.php` (claim 33-70, release 75-93, confirm 102-175, reallocate 185-226, sweep
234-254), `BatchPickingService.php` (create 22-111, scope 119-153, recordPick 206-277, refresh 285-298,
advance 305-323, cancel 348-374), `PackingService.php` (task 25-48 → verify 147-170, shipment delegate
177-209, package 302-329, addItem 338-391, seal 398-420), `app/Services/Shipment/ShipmentService.php`
(createFor 57-89, dispatch 95-107, delivered 114-137, maybeComplete 143-175).
Models: `Fulfillment.php` (snapshot guard 48-58, DAG 130-148), `Location.php` (placeable 84-90),
`ProductLocation.php` (hint 43), `PickingTask.php`, `PackingTask.php`, `Package.php` (states 14-17),
`FulfillmentBatch.php`, `Shipment.php` (transitions 82-97).
DB: `2026_09_22_*` (warehouses/locations/product_locations/fulfillments/items/batches/picking/packing/
shipments-fields), `2026_09_23_000001` (idempotency+ready_to_ship), `000002` (barcode+hint rename),
`000003` (claim fields), `000004` (packages), `000005` (shipment idempotency), `000007`
(users.warehouse_id), `2026_10_03_000001` (single-default backstop + snapshot).
AuthZ: `Permission.php:311-332`, `PermissionSeeder.php:595-645`, `WarehouseSecurityTest.php`.
Routes: `routes/api.php:108-110,228,260-265,297-299`, `Routes.php:175-188`, `Kernel.php:29,32`.
Tests: `tests/Feature/Fulfillment/*` (7), `tests/Unit/Services/Fulfillment/*` (6),
`tests/Feature/Warehouse/*` (2), `SubsystemBindingTest.php:152-212`.
Config/sweep: `config/fulfillment.php`, `SweepExpiredPickingClaims.php`.
Searches (all read-only, 2026-09-30): fulfillment-creation callers (zero production), orders.status
writers in WMS (zero), granular-perm enforcement (zero production), `FulfillmentCompleted` dispatch
(zero), package void/handoff writers (zero), `product_location_id` writers (creation + reallocate only).
