# Phase 09 — Shipment Lifecycle

## 1. Executive Verdict

**PASS WITH RESIDUAL RISKS.** Shipment is no longer the standalone CRUD the manual describes — it is the terminal stage of a full warehouse-management pipeline (fulfillment DAG → picking → packing → packages → shipment → order completion) built by the post-manual fulfillment program (Phases 0–8). The manual's HIGH security bug (BUG-2, permissionless shipment endpoints) is fixed: the public controller is unrouted (routes commented out) and all shipment operations sit behind granular admin permissions. Creation is fulfillment-bound with idempotency keys and a DB-level single-active-shipment backstop; dispatch/delivery/cancel run in single transactions with ordered locking; the generic-update bypass is sealed (D8-8); order completion is rule-bound (`maybeCompleteOrder`); cancel-audit columns exist. Residual risks: 36 fulfillment/shipment suites unexecuted, digital orders never auto-deliver (design question), dead public controller/routes retained as comments, and the manual is architecturally obsolete.

## 2. Phase Objective

Per `PHASE-09-SHIPMENT-LIFECYCLE.md` (source: `HEAD:docs/production-manual/PHASE-09-SHIPMENT-LIFECYCLE.md`, 601 lines): specify the shipment model, 10-state transition matrix, `ShipmentService`, controller security (BUG-2), missing inventory/events links, schema, 10 edge cases, and a priority-ordered recommendation matrix (P0–P4).

## 3. Scope

**In scope (per user instruction, all fulfillment files):** shipment service/controllers/routes/permissions, fulfillment DAG + transitions, picking (claim/execute/batches), packing (tasks/stations/packages), warehouses/locations/product-locations, auto-release on payment/COD, cancel cascade, shipment events + timeline + tracking, order-completion binding, WMS admin API + RBAC, cancel-audit columns, cardinality backstop.

Fulfillment files inventoried (73): `app/Services/Fulfillment/*` (8: BatchPickingService, FulfillmentService, FulfillmentTransition, OrderPickingService, PackingService, PickingExecutionService, ProductLocationService, ReturnService) + `app/Models/Fulfillment/*` (13) + `app/Http/Resources/Wms/*` (16) + `tests/Feature/Fulfillment/*` (21) + `tests/Feature/Wms/*` (13) + `tests/Feature/Warehouse/*` (2), plus `app/Services/Shipment/ShipmentService.php`, `app/Services/Warehouse/WarehouseAccess.php`, three shipment controllers, shipment/fulfillment events + listeners, WMS controllers (10), and fulfillment migrations (Sept 22–Oct 8 family).

**Out of scope:** courier API integration (correctly deferred), order lifecycle authority (Phase 05), refund/return policy (Phases 10/11), payment mechanics (Phase 06).

## 4. What Was Supposed to Be Implemented

The manual claims: standalone `ShipmentController` (6 endpoints, NO permission middleware — BUG-2 HIGH); `ShipmentService` with list/find/create/updateStatus/unvalidated-update; 10-state matrix with model-level `canTransitionTo`; NO events/listeners/notifications/timeline; NO inventory or order linkage; duplicate-shipment, bypass-update, cancel-propagation, and notification gaps; recommendation matrix P0 (permissions) → P4 (multi-shipment).

## 5. What Actually Exists

A fulfillment-bound shipment authority plus a WMS subsystem the manual never mentions:

- **BUG-2 fixed**: public shipment routes are commented out (`routes/api.php:409-411`); live surface is admin-permissioned: order-shipment reads/writes (`api.php:264-265`, `view-shipment|…` / `update-shipment|create-shipment`), fulfillment shipment creation (`api.php:400`, `create-shipment`), and WMS shipment ops (`api.php:402-407`: index/show/dispatch/deliver/cancel with `view-shipment`/`update-shipment`). Three controllers: general (legacy, unrouted), Admin (order fields), WMS (fulfillment shipments).
- **Fulfillment-bound creation**: `createForFulfillment` requires locked `ready_to_ship`, rejects cancelled, enforces one-active-shipment-per-fulfillment in-app (`assertNoActiveShipment`) + DB UNIQUE backstop on the virtual active-column (migration `2026_10_08_000001`, D8-1), idempotency keys scoped to (fulfillment, key) with cross-fulfillment refusal, Order→Fulfillment→Shipment lock order.
- **Single-transaction operations**: `dispatch` (label_created→picked_up + fulfillment→shipped + package invariant re-check), `markDelivered` (explicit chain walk picked_up→in_transit→out_for_delivery→delivered, fulfillment→delivered, `maybeCompleteOrder`), `cancelShipment` (reason required, actor/source audit D8-10, never moves fulfillment backward, never writes `orders.status`).
- **Bypass sealed (D8-8)**: generic `update()` rejects status/audit/timestamp/identity keys loudly; everything else writable + mirror re-sync.
- **Order binding**: `maybeCompleteOrder` (completed + paid + all fulfillments delivered + at least one fulfillment → `delivered` via authority; digital-only explicitly excluded); `syncOrderMirror` (shipments are source of truth; `orders.shipment_*` is a read-model mirror that never drives state, D8-9).
- **Events implemented** (manual §Missing Events closed): `ShipmentStatusChanged` + `EstimatedDeliveryChanged` → timeline recorders; `FulfillmentCompleted`; order tracking events table (`2026_09_20_194034`) + customer/admin tracking controllers; shipment tracking columns on orders (`2026_09_21_121356`).
- **Fulfillment DAG**: pending→picking→picked→packing→ready_to_ship→shipped→delivered with cancel exits (`Fulfillment.php:144-151`); `FulfillmentTransition` sole transition owner; locked assignment/start/claim/placement; batches, picking tasks (claim/confirm/reallocate/release + expired-claim sweeper), packing tasks/stations, packages (seal/void/handoff posture), product-locations with consistency asserts.
- **Auto-release on payment** (Phase 3 T6 closed): `ReleaseFulfillmentOnPayment` / `ReleaseFulfillmentOnCodPlacement` on `PaymentSucceeded` (afterCommit, high queue): digital-only skipped, deterministic idempotent key, terminal-order skip without retry, never touches payment/inventory/lifecycle.
- **Cancel cascade** (Phase 9 T12 closed): order cancel → `cancelOpenFulfillmentsForOrder` → `FulfillmentService::cancelFulfillment` atomically (Phase 05 evidence); shipment cancel audit follows the Phase-7 convention.
- **WMS RBAC**: `WarehouseAccess::denyUnless` (permission + home-warehouse confinement; `manage-warehouse` crosses; 404 anti-enumeration / 409 claim conflict); picker/packer roles structurally separated from financial permissions; granular perms (`picking.claim/complete`, `packing.complete`, `create-shipment`, …); warehouse soft deletes (`2026_10_06_000001`) + single-default backstop + deletion policy.
- **Manual's matrix preserved**: 10 shipment states + transition table intact (`ShipmentStatus`); `shipped_at` = carrier handoff on `picked_up` only (F8-9; dead `shipped` arm removed).

## 6. Architecture

```
PAYMENT → ReleaseFulfillmentOnPayment/CodPlacement (queued, idempotent key)
  → FulfillmentService::releaseForOrder/createFromOrder (locked, warehouse-scoped)
    → picking (batches → tasks → claim → confirm → reallocate) [locked, T1-hardened]
    → packing (tasks → verify → packages → seal) [picked==packed invariant]
    → ready_to_ship
      → ShipmentService::createForFulfillment (idempotent, cardinality-guarded)
        → dispatch (picked_up + fulfillment shipped)
        → markDelivered (chain walk + fulfillment delivered + maybeCompleteOrder)
        → cancelShipment (audited, non-backward)
  → maybeCompleteOrder → changeOrderStatus(delivered) [authority stays in Phase 05]
ORDER CANCEL → cancelOpenFulfillmentsForOrder → cancelFulfillment (atomic, same txn)
TRACKING: shipment/fulfillment/order events → tracking_events + timeline + customer/admin APIs
WMS ADMIN: 10 controllers behind permission + WarehouseAccess (home-warehouse confinement)
```

## 7. Complete Execution Flow

1. **Release**: payment success → queued auto-release (physical lines only) → fulfillment row (idempotent key `order-{id}-…`) or manual creation.
2. **Pick**: batch creation (guarded) → task generation → claim (locked, lease, conflict → 409) → confirm → reallocate/release; expired claims swept every 5 min.
3. **Pack**: packing tasks → verify → packages sealed; dispatch re-verifies picked==packed over non-voided packages.
4. **Ship**: label creation (fulfillment-bound, idempotent) → dispatch (handoff, `shipped_at`) → transit updates (`updateStatus`, DAG-validated) → delivered (chain walk, `delivered_at`, fulfillment delivered, order completion evaluated).
5. **Cancel**: shipment cancel (audited, reason mandatory) or order-cancel cascade (fulfillments cancelled atomically; shipped/delivered/terminal surfaced, never forced).
6. **Track**: every transition mirrored to order columns (read-model) + tracking events + timeline; customer tracking (auth + public verified) and admin dashboard read from projections.

## 8. Business Rules

| # | Rule | Implementation | Bypass? |
|---|---|---|---|
| R1 | One active shipment per fulfillment (app invariant + DB backstop) | `assertNoActiveShipment` + UNIQUE(active_fulfillment_id) | No (terminal rows are history, replaceable) |
| R2 | Shipments only from `ready_to_ship` fulfillments; never from cancelled | `createForFulfillment` guards | No |
| R3 | Cancellation never moves fulfillment backward; never writes order lifecycle | `cancelShipment` guards + scope | No |
| R4 | Order auto-delivers iff completed + paid + ≥1 fulfillment + all delivered | `maybeCompleteOrder` | Force-deliver hatch (Phase 05, audited) |
| R5 | Shipments are source of truth; order shipment columns are a mirror | `syncOrderMirror` (D8-9; never wipes with NULL) | No |
| R6 | Status/audit/timestamp/identity fields change only via transition authority | `update()` protected-key rejection (D8-8) | No |
| R7 | Dispatch requires picked==packed over non-voided packages (when packing work exists) | `assertDispatchablePackages` | Vacuous pass without packing activity (stated) |
| R8 | WMS actors confined to home warehouse; finance separated from ops roles | `WarehouseAccess` + role seeding | `manage-warehouse` only |
| R9 | Digital-only orders never auto-deliver via shipment | `maybeCompleteOrder` early-false | See F-05 |
| R10 | Cancel reason mandatory; actor recorded, never fabricated | `cancelShipment` + D8-10 convention | System source labeled explicitly |

## 9. Source of Truth / Authorities

- **Shipments**: `ShipmentService` transition authority (create/dispatch/deliver/cancel/updateStatus); generic `update` sealed.
- **Fulfillments**: `FulfillmentService` + `FulfillmentTransition` (sole DAG writers; lock-ordered; no reverse edges toward orders).
- **Order lifecycle**: `changeOrderStatus` (shipment never writes it; completion only via `maybeCompleteOrder`).
- **Inventory**: order-owned reservation (Phase 01); WMS moves reserved stock through task/package states without touching order accounting.
- **Tracking**: `order_tracking_events` + timeline listeners (projections; never authoritative).
- **RBAC**: `WarehouseAccess` + granular permissions (sole gate for WMS/admin shipment ops).
- **Dormant**: general `ShipmentController` (unrouted), commented public routes (dead code).

## 10. Database Impact

`shipments` (+`fulfillment_id`, `packing_task_id`, `idempotency_key` `2026_09_23_000005`, fulfillment fields `2026_09_22_191253`, cancel audit `cancelled_by/cancel_source/cancelled_at/cancel_reason` `2026_10_08_000001`, UNIQUE active backstop); `fulfillments` (+WMS fields `2026_09_23_000001`, warehouse hardening `2026_10_03_000001`, cancel audit `2026_10_07_000001`, soft deletes on warehouses `2026_10_06_000001`); `fulfillment_items/batches/picking_tasks/packing_stations/packing_tasks/packages(+items)/warehouses/locations/product_locations` (Sept 22–23 family); `order_tracking_events` (`2026_09_20_194034`); orders shipment mirror columns + tracking columns (`2026_09_21_121356`); `restored_quantity` on order_products (`2026_09_23_000006`); `warehouse_id` on users (`2026_09_23_000007`). All additive; cardinality backstop fails loudly on violation (by design).

## 11. API Surface

| Method | URI | Auth | Handler | Notes |
|---|---|---|---|---|
| GET | `/v1/admin/orders/{orderId}/shipment` | sanctum + `view-shipment\|…` | Admin ShipmentController | order shipment read |
| POST | `/v1/admin/orders/{orderId}/shipment/update-status` | sanctum + `update-shipment\|create-shipment` | Admin ShipmentController | order-level status |
| POST | `/v1/admin/fulfillments/{id}/shipments` | sanctum + `create-shipment` | WMS ShipmentController | fulfillment-bound create |
| GET | `/v1/admin/shipments`, `/{id}` | sanctum + `view-shipment` | WMS ShipmentController | list/show |
| POST | `/v1/admin/shipments/{id}/dispatch\|deliver\|cancel` | sanctum + `update-shipment` | WMS ShipmentController | transitions (cancel needs reason) |
| (WMS) | warehouses/locations/product-locations/fulfillments/picking/batches/packing/packages/cancel | sanctum + granular perms + warehouse scope | 10 WMS controllers | 401/403→404/409/422 matrix (WmsAuthorizationGateTest statically) |
| POST | `/track-order` (public) + customer/admin tracking | mixed | tracking controllers | verified-by-email/phone public path |

## 12. Authentication & Authorization

All live shipment/WMS endpoints require sanctum + specific permissions; warehouse confinement via `WarehouseAccess` (denial → 404 anti-enumeration, claim conflict → 409); financial/ops role separation structurally seeded; public tracking is verification-gated, not session-gated. The manual's BUG-2 class is eliminated on every live route (public controller unrouted).

## 13. Validation

Fulfillment-state preconditions on every shipment op (locked fresh rows, loud refusals); DAG validation defense-in-depth (`writeStatus` re-checks); protected-key rejection on generic update; cancel reason mandatory; idempotency-key cross-fulfillment refusal; WMS requests validated per controller (`Create/UpdateShipmentRequest` + WMS request family); flow-values discipline adjacent (Phase 05).

## 14. Transactions

Single-transaction dispatch/deliver/cancel/create with Order→Fulfillment→Shipment lock order (no reverse edges; matches cancel-cascade direction); probe-then-lock patterns re-validate under lock (stale-model safe); mirror sync in-transaction (caller holds order lock); auto-release runs in queue jobs with idempotent keys (replay-safe); batch creation guarded (race-tested per Phase-5 plan).

## 15. Concurrency

| Case | Protection | Classification |
|---|---|---|
| Double label creation | app check + UNIQUE backstop (loud abort) | STRONGLY REASONED |
| Concurrent dispatch/deliver/cancel | row locks + probe/lock re-validation + DAG guards | STRONGLY REASONED |
| Picking claim races | locked claims + leases + sweeper (T1) | STRONGLY REASONED |
| Batch-create races | creation guard + race tests (plan Phase-5) | STRONGLY REASONED |
| Completion double-run | `maybeCompleteOrder` guards + authority locks; delivered-replay recovery loop | STRONGLY REASONED |
| `maybeCompleteOrder` unlocked reads | safe — callers hold locks; writer re-locks (hygiene note F-03) | STRONGLY REASONED |
| True parallel proof | 21 Fulfillment + 13 Wms + 2 Warehouse suites (incl. AssignmentRaceTest, ConcurrencyAttackTest, AsyncSafetyTest, race/governance/lifecycle suites) | UNPROVEN — NOT EXECUTED |

## 16. Async / Queues / Events

Auto-release listeners (high queue, afterCommit); `ShipmentStatusChanged`/`EstimatedDeliveryChanged` → timeline recorders; `FulfillmentCompleted`; order tracking fan-out; picking-claim sweeper (5 min); notification listeners adjacent (delivery/push). No courier webhooks (deferred by design); no shipment PDF/artifact pipeline.

## 17. Error Handling

Loud refusals with state-specific messages on every guard (no silent no-ops except idempotent delivered-replay, which logs + recovers); terminal-order auto-release skips without retry (loud log); poison WMS rows surface (no silent claims); cancel timestamps/reasons mandatory; mirror sync never wipes operator data with NULLs.

## 18. Security

- Granular per-action permissions on all shipment/WMS endpoints; home-warehouse confinement; anti-enumeration 404s.
- Finance/ops separation by construction (role seeding).
- No public mutation surface (legacy routes commented).
- Claim conflicts → 409 (no lock leakage); validation errors → 422 with clear messages; inactive-warehouse → 422.
- Audit log for override/cancel/void actions (Phase-8 T11 wiring).

## 19. Performance

Row-level locks with consistent global order (deadlock-averse; refund path measured 1213-fixed in adjacent domain); probe-first patterns avoid holding locks across fulfillment checks longer than needed; package-invariant check is read-only and scoped to the fulfillment; tracking reads are projection-indexed; WMS list endpoints paginated/filtered/sorted per standards; no N+1 on hot reads (eager relations in resources).

## 20. Tests & Verification

21 `Fulfillment/*` (lifecycle, picking foundation/batches/claim-sweep, packing phases 6–7, cancellation phase 7, shipment boundary + phase-8 governance/lifecycle, auto-release wiring, assignment race, concurrency attack, async safety, location immutability, restock integrity, return recovery, single-warehouse release, warehouse soft-delete) + 13 `Wms/*` (API foundation, auth gates, RBAC ×2, picking/batch/packing/shipment/warehouse-location, order cancellation, E2E ×2) + 2 `Warehouse/*` (barcode resolver, security). **None executed** (environment).

## 21. Edge Cases

Covered: duplicate label (refused + backstop); cancelled fulfillment label (refused); dispatch without packing coverage (refused); delivery from wrong state (refused with expected-state message); delivered replay (idempotent + completion recovery); cancel of shipped/delivered fulfillment (refused); order cancel mid-picking/packing/ready (atomic cascade; sealed custody/packed work/live shipment refusals roll back); digital-only (no fulfillment, no auto-deliver); empty-packing fulfillments (vacuous pass, stated); idempotency-key cross-binding (loud refusal); terminal shipment rows as replaceable history.

## 22. Potential Bugs

### Finding F-01
- Severity: MEDIUM
- Type: DOCUMENTATION
- Status: PROVEN
#### Finding
The manual describes a standalone shipment CRUD with no fulfillment, no events, no permissions, and no order linkage — and prescribes building all of it (P0–P4 matrix). All of it now exists in a different architecture (fulfillment-bound + WMS). The manual's architecture, edge-case mitigations ("none currently"), and recommendations are obsolete as build instructions.
#### Evidence
`ShipmentService.php` (624 lines vs manual's ~100-line sketch); fulfillment program files (73 inventoried); routes (`api.php:264-265,394-407` vs manual's permissionless table); ESP shipment wiring; manual §§Architecture/Limitations/Edge Cases/Recommendations.
#### Why it matters
Build-against-manual would duplicate a system that already exists; ops debugging via the manual misses the fulfillment authority entirely.
#### Current behavior
Correct implementation; stale manual.
#### Recommended future action
Rewrite Phase 9 around the fulfillment-bound model (on explicit docs request).
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-02
- Severity: INFO
- Type: DOCUMENTATION
- Status: PROVEN
#### Finding
Manual BUG-2 (permissionless endpoints) is fixed — public routes commented out, live surface permissioned. Manual `update()`-bypass concern is sealed (D8-8 protected keys). Manual duplicate-shipment concern is closed (D8-1 + UNIQUE backstop). Manual missing-events concern is closed (status/ETA events + timeline + tracking).
#### Evidence
`routes/api.php:402-411`; `ShipmentService.php:455-494,527-544`; ESP shipment listeners; `2026_10_08_000001` migration.
#### Why it matters
Record fixed items; the P0–P4 matrix is largely worked off (courier integration correctly remaining).
#### Current behavior
Correct.
#### Recommended future action
None (record only).
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-03
- Severity: LOW
- Type: CONCURRENCY (hygiene)
- Status: PROVEN (code) / STRONGLY REASONED (safety)
#### Finding
`maybeCompleteOrder` performs its guard reads unlocked (`Order::whereKey()->first`, fulfillment `exists()` checks). Safety holds because every caller holds the order lock and the actual mutation re-locks inside `changeOrderStatus` — but the method's contract does not state its locking assumption, inviting a future unlocked caller to check-then-act across a race window.
#### Evidence
`ShipmentService.php:381-413` (no locks; docblock says "locked §22" without enforcing).
#### Why it matters
A future caller (e.g., scheduler, new listener) could evaluate stale guards and fire a completion the invariant would have rejected.
#### Current behavior
Safe via callers; contract implicit.
#### Recommended future action
Document the locking precondition in the docblock (or take the lock inside when no transaction is active).
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-04
- Severity: LOW
- Type: BUSINESS LOGIC
- Status: STRONGLY REASONED (design question)
#### Finding
Digital-only orders can never reach `delivered` (`maybeCompleteOrder` returns false without fulfillments; D8-5 requires fulfillment completion). If downstream consumers (reviews, loyalty, "delivered" analytics, return windows) key off `delivered`, digital orders are second-class citizens permanently parked at `completed`.
#### Evidence
`ShipmentService.php:401-406` (explicit exclusion with rationale); `OrderService.php:919-946` (D8-5 guard).
#### Why it matters
Lifecycle analytics and post-delivery features must handle a permanent completed-but-never-delivered cohort, or the lifecycle needs a digital-delivery transition.
#### Current behavior
Intentional (payment path owns digital completion); force-deliver hatch exists for recovery.
#### Recommended future action
Confirm digital terminal-state policy; either bless `completed`-as-terminal for digital or add an entitlement-driven `delivered` path; pin with a test.
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-05
- Severity: LOW
- Type: LEGACY
- Status: PROVEN
#### Finding
The legacy public `ShipmentController` (3.5KB) and its commented-out routes remain in the tree. Three shipment controllers (general/admin/WMS) with overlapping names invite wrong-controller edits.
#### Evidence
`app/Http/Controllers/Api/ShipmentController.php` (unrouted — routes commented at `api.php:409-411`); `Api/Admin/ShipmentController.php`; `Api/Admin/Wms/ShipmentController.php`.
#### Current behavior
Inert but confusing.
#### Recommended future action
Delete the unrouted controller + commented routes (or formally deprecate with a note); keep the admin/WMS split documented.
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-06
- Severity: MEDIUM
- Type: TEST GAP
- Status: PROVEN (gap) / UNPROVEN (runtime)
#### Finding
36 fulfillment/shipment/WMS suites exist — including race, attack, governance, and E2E suites — and none were executed here. TRUE PARALLEL CONCURRENCY NOT PROVEN for label races, claim races, dispatch/deliver/cancel races, or cascade atomicity.
#### Evidence
Test inventory verified (21 + 13 + 2 files); execution impossible (MySQL-only).
#### Why it matters
The WMS is the most lock-dense subsystem; its safety claims need runtime proof most.
#### Current behavior
Well-constructed; unproven.
#### Recommended future action
Execute Fulfillment + Wms + Warehouse suites against real MySQL in CI (isolated DB — shared-`catch` writers were observed in prior verification notes); record results.
> NOTE: This audit is READ-ONLY. No fix was applied.

## 23. Potential Failure Scenarios

- **Lost label-creation race**: loser aborts loudly via UNIQUE backstop (no silent double-label).
- **Delivery recorded while completion blocked**: replay path re-evaluates and recovers once unblocked.
- **Order cancelled mid-warehouse-work**: atomic cascade; sealed/packed/live-shipment states refuse loudly (no orphaned custody).
- **Courier never picks up**: label stays `label_created`; no auto-escalation exists (ops process gap — no timeout/aging alert found).
- **Partial warehouse stockout at pick**: task-level confirm/reallocate loop; reservation stays order-owned (no WMS write to order accounting).
- **Multi-server schedulers**: sweeper/reaper overlap guarded by `withoutOverlapping` (+ `onOneServer` where present); claim leases bound the window regardless.

## 24. Documentation Drift

Manual accurate for: 10-state matrix + transition table (preserved), model UUID behavior, `writeStatus` timestamp semantics (modulo F8-9 `shipped` cleanup), service method names (list/find/create/updateStatus/update — all still exist with hardened bodies). Obsolete: standalone architecture, permissionless endpoints, missing events/inventory/timeline (all implemented), edge-case mitigations ("none"), recommendation matrix (worked off except courier integration), file layout (`app/Services/Shipment/` vs manual's path).

## 25. Dependencies

- **Depends on**: Phase 05 (lifecycle authority + cancel cascade target), Phase 01 (release triggers via payment), Phase 06 (paid state), inventory reservation (order-owned), warehouse master data, auth/permission system.
- **Consumed by**: Phase 05 (delivered transitions), Phase 11 (return-shipment linkage), Phase 12 (tracking/delivery notifications), Phase 15 (timeline fulfillment stages).
- **Shared tables**: `shipments`, fulfillment family, `orders` (mirror + lifecycle), `order_tracking_events`, packages/tasks.
- **Shared services**: `ShipmentService`, `FulfillmentService/FulfillmentTransition`, picking/packing/batch/location services, `WarehouseAccess`, `OrderService`.

## 26. Out of Scope

Courier API integration and label purchasing (correctly deferred), carrier webhook ingestion, multi-package routing optimization, warehouse replenishment/procurement, returns processing internals (Phase 11), demand forecasting.

## 27. Residual Risks

1. Runtime proof absent across 36 suites (F-06).
2. Manual describes a system that no longer exists (F-01).
3. Digital terminal-state policy unconfirmed (F-04).
4. `maybeCompleteOrder` implicit locking contract (F-03).
5. Dead controller + commented routes retained (F-05).
6. No label-aging/auto-escalation for stuck `label_created` rows.

## 28. Evidence / Source Files

Manual: `HEAD:docs/production-manual/PHASE-09-SHIPMENT-LIFECYCLE.md` (601 lines, read fully, temp extract). Code read: `app/Services/Shipment/ShipmentService.php` (full, 624 lines); `app/Services/Fulfillment/*` (8 files — method/lock inventory; `FulfillmentService` release/create/cancel/assign paths verified); `app/Models/Fulfillment/*` (13 — DAG `Fulfillment.php:144-158` verified); `app/Models/Shipment.php` (linkage/idempotency/audit columns); `app/Services/Warehouse/WarehouseAccess.php` (full boundary); WMS controllers (10 — routed surface verified); `app/Http/Controllers/Api/ShipmentController.php` (legacy, unrouted) + `Api/Admin/ShipmentController.php` + `Api/Admin/Wms/ShipmentController.php`; `CreateShipmentRequest/UpdateShipmentRequest/UpdateShipmentStatusRequest`; WMS requests; `ReleaseFulfillmentOnPayment.php` (full) + `ReleaseFulfillmentOnCodPlacement` (referenced); `FulfillmentCompleted`, `ShipmentStatusChanged`, `EstimatedDeliveryChanged` events + `RecordShipmentStatusInTimeline`, `RecordETAChangeInTimeline` listeners; tracking controllers + `OrderTrackingEvent`; routes (`api.php:260-265,368-407,409-411`); fulfillment root reports (`FULFILLMENT_PHASE_0_AUDIT/GAP_MATRIX/IMPLEMENTATION_PLAN/PHASE1_*` — surviving on disk); `ORDERFLOW_AUDIT_REPORT.md` §-level shipment rules (quoted). Tests (36 files inventoried, not executed). Migrations: fulfillment/WMS family (`2026_09_22_081822 → 2026_10_08_000001` chain).

## 29. Final Assessment

**Verdict: PASS WITH RESIDUAL RISKS.**

Shipment + fulfillment form the most transformed subsystem in the audit: a permissionless CRUD became a locked, audited, idempotent warehouse pipeline with rule-bound order completion — and the manual's headline security bug is fixed. The verdict is capped by absent runtime proof, an obsolete manual, and open design questions (digital terminal state, label aging). No blocking defect found.
