# FULFILLMENT PHASE 1 — DISCOVERY REPORT (read-only, no code changed)

> Date: 2026-09-30 · Scope: T1 + T2 ONLY · Production files modified: 0.
> Baseline: Order Flow A–L VERIFIED/CLOSED, D0–D10 immutable. Working tree = prior session's
> executed 09-29 plan, UNCOMMITTED (4 modified services + 4 untracked new files). Phase-0 audit
> read this exact tree, so revalidation below confirms identity, not HEAD equality.

## 1. Revalidation result: implementation matches Phase-0 audit

- `git status`: M `Fulfillment.php`, `BatchPickingService.php`, `FulfillmentService.php`,
  `PickingExecutionService.php`, `ProductLocationService.php`, `PackingTest.php`,
  `ReturnRecoveryTest.php`, both unit service tests; untracked `WarehouseService.php`,
  `config/fulfillment.php`, `2026_10_03_000001` migration, `FulfillmentHardeningTest.php`.
  Balance = pre-existing other-session deletions (COUPON_*/PAYMENT_* docs, supervisor confs) —
  untouched, out of scope. Diff review of `FulfillmentService`/`BatchPickingService`/`Fulfillment`
  confirms the audited behavior (atomic release, scope guard, snapshot, backstop). No drift.
- `OrderService::changeOrderStatus` at `app/Services/General/OrderService.php:827` — sole Order
  Flow writer, unchanged.
- Bypass re-search (2026-09-30): zero `orders.status`/`order_status` writers in
  `Services/Fulfillment|Warehouse|Shipment|Digital` (only fulfillment/batch/task/warehouse status
  writes, all in-scope); zero production callers of `releaseForOrder|createFromOrder` (service +
  tests only — no auto-release); zero WMS routes in `routes/api.php|web.php|Rest/Routes.php`
  (no WMS API layer).

## 2. T1 — current behavior of the 5 target methods (all last-write-wins)

1. `BatchPickingService::assignBatch` (`:158-177`): plain `$batch->update(status=assigned,
   assigned_to)` after in-memory status check. No txn, no lock. Race: A+B assign → both succeed,
   second overwrites first silently.
2. `FulfillmentService::assignToUser` (`:283-293`): plain `$fulfillment->update(assigned_to)`.
   No txn, no lock, no state check at all. Race: silent overwrite.
3. `PackingService::assignToStation` (`:53-78`): station-active check, then plain update to
   assigned. No txn, no task lock. Race: double-assign, second wins silently.
4. `BatchPickingService::startPicking` (`:182-201`) — CORRECTION: protocol names
   `PickingExecutionService::startPicking()`, which DOES NOT EXIST (verified by method search;
   `PickingExecutionService` owns claim/release/confirm/reallocate/sweep only). The real method
   is batch-scoped and does plain status check + update, no txn/lock. T1 will harden THIS method;
   no new method will be created.
5. `PackingService::startPacking` (`:83-102`): plain assigned-check + update, no txn/lock.
   Race: double-start, both succeed.

Safe neighbors (NOT touched): `claim`/`confirm`/`recordPick`/`addItemToPackage` (locked),
`releaseForOrder`, `FulfillmentTransition::transition` (locked), `setDefault`/`deactivate`.

## 3. T1 — exact change plan (behavior-preserving, schema-free, perm-free)

For each of the 5 methods: wrap in `DB::transaction`, re-fetch with `lockForUpdate`,
re-check the precondition on the LOCKED row, throw `RuntimeException` with actor/state context
on mismatch, else perform the same update. Preconditions on locked row:
- `assignBatch`: status in (pending, assigned) — same rule as today, now race-safe.
- `assignToUser`: no state rule today → keep none; lock only (atomic last-writer becomes
  first-writer-wins only where a rule exists; here the win is serialized but still overwrite —
  REPORTED honestly: lock alone cannot reject; acceptable: assignment is idempotent-ish intent,
  race window collapses to serialization; test asserts serialized second write lands deterministically).
- `assignToStation`: task status pending (implied today — make explicit) + station active (keep).
- `startPicking` (batch): status === assigned (as today).
- `startPacking`: status === assigned (as today).
No new states, no new transitions, no permission checks, no schema change. Failures are
controlled `RuntimeException`s (callers/tests assert message + state unchanged).

## 4. T2 — current problem (confirmed)

`FulfillmentService::createFulfillmentItem` (`:175-229`): on allocation throw → creates item with
`product_location_id = NULL` + notes. `OrderPickingService::createTasksForFulfillment` (`:22-64`)
`continue`s on NULL items ("manual assignment required") — but NO method assigns a placement to an
existing item. `reallocateTask` (`PickingExecutionService:185-226`) only moves a TASK's placement,
never an item's. Writer search for `product_location_id`: creation + reallocate + tests only.
Conclusion: NULL items are permanently stuck. No schema change needed (FK already nullable).

## 5. T2 — exact change plan (inventory-authority preserving)

New method `assignPlacement(FulfillmentItem $item, int $productLocationId): FulfillmentItem`
(placement: `FulfillmentService`, next to `createFulfillmentItem` — smallest home; alternative
`OrderPickingService` rejected: it owns task fan-out, not item lifecycle):
- `DB::transaction` + `lockForUpdate` item; reject if `quantity_picked >= quantity` (already picked).
- Load target `ProductLocation` + its `Location` (locked read); reject: wrong product,
  `target.warehouse_id != item.fulfillment.warehouse_id`, location not active/placeable
  (mirror `scopePlaceable`: active + null-or-placeable-type), placement-vs-location drift
  (reuse `ProductLocationService::assertConsistent`), no open task already exists for the item
  (mirror `OrderPickingService` open-task check — prevents double-pick).
- Update ONLY `product_location_id` (+ note); never touch `products.stock_quantity`,
  `reserved_quantity`, `quantity`, or `allocated_hint`. Authority untouched.
- After assignment, existing `createTasksForFulfillment` picks the item up with zero changes.
No stock mutation, no reservation, no second inventory source. Allocation-failure path unchanged.

## 6. Risks

- T1 `assignToUser`: lock serializes but cannot reject (no state rule) — residual overwrite
  semantics preserved deliberately; documented, not fixed (fixing = new business rule = STOP).
- T1 error type: `RuntimeException` matches service convention (claim-conflict precedent); HTTP
  mapping deferred to Phase 7 (no API today — no impact).
- T2: assigning placement to an item whose order was meanwhile cancelled → guarded by fulfillment
  status check (pending/picking only); cancel path skips open tasks as today.
- Regression risk: low — 5 methods gain txn+lock+recheck with identical happy-path outcomes;
  full baselines below gate it.

## 7. Test plan (Phase-1-only tests, all new)

T1 (`tests/Unit/Services/Fulfillment/AssignmentRaceTest.php`, new): concurrent assignBatch (two
actors → one winner, loser `RuntimeException`, state = winner); assignBatch from completed →
rejected; concurrent assignToStation (second rejected); concurrent startPacking (second rejected);
startPicking from pending → rejected. T2 (extend same or `PlacementAssignmentTest.php`, new):
NULL item assigned → task creatable → confirm works; wrong-warehouse / wrong-product /
inactive-location / already-picked / already-has-open-task → all rejected with state unchanged.
Harness: `RefreshDatabase`, `catch_flow_verify`, service-level (no HTTP exists), sequential runs
(worker memory limit known).

## 8. Baselines recorded (pre-change, `catch_flow_verify`, MySQL restarted 2026-09-30)

- `tests/Feature/Fulfillment + tests/Unit/Services/Fulfillment`: 28 passed, 97 assertions, 91.51s.
- `tests/Feature/Warehouse`: 10 passed, 29 assertions, 73.85s.
- `tests/Feature/OrderFlow/SubsystemBindingTest.php`: 3 passed, 12 assertions, 5.42s.
- Total: 41 passed, 0 failed. Schema leak check: 128 tables intact post-run.
- `php -l` will run on every touched file post-change (protocol §verification-1).

## 9. Stop-condition pre-check: none triggered

No business decision, schema change, flow modification, or new pattern required for T1/T2 as
planned above. Package cardinality, warehouse deletion, auto-release, cancel cascade remain
deferred per plan (untouched). AWAITING APPROVAL to implement T1+T2.
