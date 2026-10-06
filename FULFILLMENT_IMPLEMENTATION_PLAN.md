# FULFILLMENT IMPLEMENTATION PLAN — Phase-0 derived (supersedes 2026-09-29 plan)

> Authority: `FULFILLMENT_PHASE_0_AUDIT.md` + `FULFILLMENT_PHASE_0_GAP_MATRIX.md`.
> Supersession note: the 2026-09-29 plan's "changeset" (WarehouseService, claim lease, batch guard,
> granular perms, snapshot, single-default backstop, hint rename) is verified IMPLEMENTED — those
> items are closed below, not re-planned. Order Flow stays authoritative; fulfillment is ops execution.
> Forbidden: second order engine, shipment redesign, RabbitMQ, Marvel rebuild, API-doc edits.
> Zero production code is changed by Phase 0 itself.

## Phase 1 — Domain hardening (small, no API)

- T1. Lock assignment/start writes (R-assign).
  Reason: close the only UNSAFE race set. Existing files: `BatchPickingService.php:158-201`,
  `FulfillmentService.php:283-293`, `PackingService.php:53-102`. Files to change: same three
  (wrap in `DB::transaction` + `lockForUpdate` + state preconditions: assign only from
  pending/assigned, start only from assigned). Database impact: none. API impact: none.
  Permission impact: none. Dependencies: none. Risk: low (strictly narrower transitions; existing
  tests assert same happy paths). Tests: concurrent-assign test (two actors, one winner) +
  invalid-transition throws. Acceptance: all existing Fulfillment suites green + new race test.
  Rollback: revert three methods (behavioral only).
- T2. Null-allocation item assignment (§14-null).
  Reason: unblock stuck items (currently skipped forever). Existing files:
  `OrderPickingService.php`, `ProductLocationService.php` (suggest/assert guards to reuse).
  Files to change: new `assignPlacement(FulfillmentItem, productLocationId)` in
  `FulfillmentService` or `OrderPickingService` (locked: same warehouse/product, target active+
  placeable+has availability, item still unpicked) + wire into `createTasksForFulfillment` retry path.
  Database impact: none (uses existing nullable FK). API impact: none yet. Permission impact: none yet.
  Dependencies: none. Risk: low. Tests: null item → assign → task created → confirm works; cross-
  warehouse/inactive rejected. Acceptance: no fulfillment can strand an item when stock exists.
  Rollback: remove method (items return to skipped state).
- T3. Shipment raw-create guard (R-shipraw).
  Reason: defuse boundary bypass before any routing. Existing files:
  `ShipmentService.php:43-49`, unrouted `Api\ShipmentController.php`. Files to change: deprecate or
  gate `create()` (require `ready_to_ship` fulfillment or mark `@deprecated internal`) — smallest
  correct: route it through `createForFulfillment` semantics. Database impact: none. API impact: none
  (still unrouted). Permission impact: none. Dependencies: none. Risk: low. Tests: direct `create`
  with non-ready fulfillment rejected (or removal asserted). Acceptance: no code path creates
  shipments outside the boundary. Rollback: revert method.

## Phase 2 — Warehouse / Location (decisions + tiny changes)

- T4. Warehouse deletion policy (Y2/§11).
  Reason: schema RESTRICT contradicts business-allowed deletion. Existing files:
  `create_fulfillments_table.php:17`, `Fulfillment.php:48-58` (snapshot). Files to change: NONE until
  owner decision. Options: (a) keep RESTRICT + document "deletion blocked while referenced" as the
  rule; (b) soft-delete warehouses; (c) archive-then-delete with snapshot backfill (already backfilled).
  Database impact: per option. API impact: none. Permission impact: `manage-warehouse` governs.
  Dependencies: OWNER DECISION. Risk: medium (history semantics). Tests: per option. Acceptance:
  written decision + behavior matching it. Rollback: N/A (decision first).
- T5. Location-move guard (optional, §F).
  Reason: `warehouse_id` mass-assignable; drift only caught at read time. Existing files:
  `Location.php`, `ProductLocationService::assertConsistent`. Files to change: model `updating` guard
  refusing `warehouse_id` change while `productLocations` exist (or explicit `moveLocation` service).
  Database impact: none. API impact: none. Dependencies: none. Risk: low. Tests: move-with-stock
  blocked; empty location movable. Acceptance: drift impossible, not merely detected. Rollback: revert
  guard. (May be deferred — current detection is safe; mark optional.)

## Phase 3 — Fulfillment (auto-release decision)

- T6. Auto-release wiring decision + implementation (Y3/§§5–7).
  Reason: fulfillment unreachable in production. Existing files: `FulfillmentService::releaseForOrder`,
  `OrderCreationService.php:572` (`OrderCreated::dispatch`), payment callback paths, `FulfillDigitalProducts`
  (digital precedent). Files to change: TBD — candidate: `PaymentSucceeded` listener (capture methods)
  + order-creation path (COD with ACTIVE reservation), idempotency key = `order-{id}-{paymentAttempt}`
  or outbox-style. Database impact: none (key column exists). API impact: none. Permission impact: none
  (system actor). Dependencies: OWNER + OPS DECISION (which events, COD timing, failure alerting, key
  source). Risk: medium (first live mutation path). Tests: online success → releasable → fulfillment;
  failure → none; COD → fulfillment without paid flag; duplicate event → single fulfillment.
  Acceptance: end-to-end release proven in `catch_flow_verify`, reaper/cancel paths unaffected.
  Rollback: remove listener (return to manual). DO NOT implement before decision.

## Phase 4 — Picking (explicit completion decision)

- T7. Complete-picking action (T-§20).
  Reason: close PARTIAL with an explicit permission-gated step or bless emergent completion.
  Existing files: `PickingExecutionService`, `Permission.php:328`. Files to change: TBD per decision —
  either `completePicking(fulfillment)` (locked: all items fully picked → transition `picked` via owner,
  requires `picking.complete`) or written blessing + wire perm to verify path. Database impact: none.
  API impact: later endpoint. Permission impact: `picking.claim` (claim) vs `picking.complete`
  (complete) separation enforced. Dependencies: OWNER DECISION (light). Risk: low. Tests: per decision.
  Acceptance: §20 row becomes VERIFIED. Rollback: revert method.

## Phase 5 — Batch (verification only)

- No code planned: creation guard, lifecycle, persistence-by-task-rows verified. Work: add
  concurrent batch-create race test (same fulfillments, one winner set of tasks) to harden confidence.
  Dependencies: none. Risk: none. Acceptance: race test green.

## Phase 6 — Packing / Package (decision-gated)

- T8. Package cardinality decision (Y1/§24). Options: (a) enforce single (DB UNIQUE fulfillment_id +
  migrate existing multis — breaking); (b) bless multi (document; keep over-pack invariant).
  Existing files: `PackingService.php:302-420`, `create_packages_tables.php`, `PackingTest.php:90-92`.
  Files to change: NONE until OWNER DECISION (breaking either way — same conclusion as prior P0-2).
  Database/API/permission per option. Tests: per option. Acceptance: code + tests + docs agree.
- T9. Package void/handoff (R-pkgstate). Reason: dead states or missing lifecycle end.
  Files to change (after T8): `voidPackage` (supervisor-only, reason required, restores packable
  quantity accounting) + `handoffPackage` (carrier handoff marker) OR remove unreachable states.
  Dependencies: T8 + OWNER DECISION. Risk: low. Tests: void reopens packing correctly; handoff seals
  immutably. Acceptance: every package status reachable or removed. Rollback: revert methods.

## Phase 7 — API (greenfield, after hardening + decisions)

- T10. WMS HTTP surface: warehouses, locations, product-locations, fulfillments (+items), picking
  tasks (claim/confirm/reallocate/release), batches (+operate), packing tasks (+verify), packages
  (+seal), all behind `auth:sanctum` + granular perms via `WarehouseAccess::denyUnless`
  (404 anti-enumeration, 409 claim conflict, 422 validation, idempotency-key support on creation
  endpoints, pagination/filter/sort per project standards). Dependencies: T1–T9 decisions.
  Risk: high (first external mutation surface — permission wiring is load-bearing). Tests: HTTP
  happy + 401/403/404/409/422 + cross-warehouse forbidden + idempotent retry per endpoint.
  Acceptance: §36 matrix all EXISTS with documented concurrency/idempotency per endpoint.
  Rollback: unroute controllers (services unaffected).

## Phase 8 — Permissions / Authorization (with Phase 7)

- T11. Enforcement wiring: middleware `permission:` per route + `denyUnless` in controllers +
  `Handler` mappings (`PickingValidationException→422`, `UnknownBarcodeException→404`,
  claim-conflict `RuntimeException→409`, inactive-warehouse→422 with clear message) + audit log
  for override/cancel/void actions. Dependencies: Phase 7. Risk: medium. Tests: matrix of
  role × action × warehouse (allowed/denied). Acceptance: §28 row VERIFIED; no endpoint unwired.
  Rollback: unroute.

## Phase 9 — Tests (per phase, plus)

- T12. Order-cancel cascade policy test+implementation (§29 gaps): decide cascade (cancel order →
  cancel open fulfillments with reason `order_cancelled`, void open packages per T9, shipments per
  shipment-cancel rules, inventory via existing owner path), implement in `OrderService` cancel branch
  (NOT in fulfillment — ownership stays with order path), prove no double-restore.
  Dependencies: T8/T9 + OWNER DECISION. Risk: medium (touches verified cancel path — requires
  re-verification of Order Flow battery). Tests: cancel-during-picking/packing/ready_to_ship matrix +
  full Order Flow regression. Acceptance: zero orphans, single restore, battery green. Rollback: revert
  branch (fulfillment orphans return — documented).
- T13. Coverage for each phase's acceptance criteria as built (unit + feature + concurrency).

## Phase 10 — Final verification

- Re-run affected suites in `catch_flow_verify` (`Fulfillment,Warehouse,Picking,Packing,Batch,
  PaymentCompletion` filters, then broader suites), `php -l` on touched files, second independent
  audit pass (business rule → implementation → DB → API → permission → concurrency → test), update
  audit/matrix statuses, evidence report with Not Verified section (anything deferred). Shipment stays
  boundary-only; no API docs touched (docs safety mode).

## Explicitly OUT OF SCOPE

- Shipment redesign (§26, future phase). RabbitMQ/new infra (forbidden). Marvel rebuild. Merging the
  fulfillment DAG into Order Flow (verified separate — keep). Auto-release before T6 decision.
  Single-package enforcement before T8 decision. Order-cancel cascade before T12 decision.
