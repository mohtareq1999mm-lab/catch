# FULFILLMENT PHASE 1 — FINAL REPORT (T1 + T2 implemented)

## A. Objective

Harden WMS assignment/start races (T1, with approved first-winner-wins correction) and unstick
NULL-allocation fulfillment items via controlled placement assignment (T2), without touching Order
Flow, schema, APIs, permissions, packages, or business decisions.

## B. Files Changed (production: 3, tests: 2 new)

- `app/Services/Fulfillment/BatchPickingService.php` — `assignBatch` (:159), `startPicking` (:193)
- `app/Services/Fulfillment/FulfillmentService.php` — `assignToUser` (:285), `assignPlacement` (:321, new)
- `app/Services/Fulfillment/PackingService.php` — `assignToStation` (:53), `startPacking` (:99)
- `tests/Feature/Fulfillment/AssignmentRaceTest.php` (new, 5 tests)
- `tests/Feature/Fulfillment/PlacementAssignmentTest.php` (new, 5 tests)

## C. Why Each Change Exists

- T1 ×5: five assign/start methods performed read-then-write without transaction/lock — two actors
  racing produced silent last-write-wins overwrites and invalid transitions (e.g. start from pending
  after a concurrent cancel). Each now runs `DB::transaction` + `lockForUpdate` + locked-state
  re-check, throwing controlled `RuntimeException` (claim-conflict convention) on mismatch.
- T1 correction (approved): `assignToUser`/`assignBatch`/`assignToStation` are first-winner-wins —
  NULL → assign, same user → idempotent, other user → conflict. No silent overwrite.
- T1 deliberate extension (pre-declared in discovery §3, approved): `assignToStation` additionally
  requires status pending|assigned (old code assigned from ANY status, including verified/cancelled —
  terminal-state corruption). Same (pending, assigned) rule `assignBatch` already had.
- T2 `assignPlacement`: NULL-allocation items were skipped forever by `OrderPickingService`
  (verified: no assignment path existed). New locked method assigns a placement under 6 guards
  (unpicked, no open task, same product, same warehouse, active+placeable, no drift), after which
  the EXISTING task flow continues unchanged.

## D. Before/After Behavior

| Method | Before | After (happy path identical) |
|---|---|---|
| assignToUser/assignBatch/assignToStation | second actor silently overwrites | second actor gets `... already assigned to user N`; winner kept |
| startPicking/startPacking | double-start both succeed / start-from-wrong-state racy | second/wrong-state gets `Cannot start ... in status: X` |
| NULL item | stuck permanently | assignable once, then normal claim→confirm→picked |
| Status exception type | `\Exception` (assign/start guards) | `\RuntimeException` (subclass — existing `expectException(\Exception)` callers unaffected; full suite green proves it) |

## E. Database Impact

NONE. No migration, no column, no index, no constraint change. Writes use existing columns only
(`assigned_to`, `status`, timestamps, `product_location_id`). Locks are `SELECT ... FOR UPDATE`
inside InnoDB transactions.

## F. API Impact

NONE. No controllers/routes/resources added or modified (no WMS HTTP surface exists).

## G. Permission Impact

NONE. No permission added, removed, or newly enforced (enforcement deferred to Phase 7/8 per plan).

## H. Concurrency Analysis

- assign/start ×5: SAFE now (txn + row lock + locked re-check; exactly-one-winner; idempotent
  same-actor retry). Lock scope is single-row (+ station row for assignToStation); no deadlock
  ordering risk (one row, or task→station fixed order).
- assignPlacement: SAFE (item + open-task check + placement locks in fixed order; uniqueness of
  outcome enforced by open-task guard + task-creation idempotency).
- Honest limit: in-process tests prove re-check logic serially (same standard as existing
  `ConcurrencyAttackTest`); the DB lock itself is proven by inspection + MySQL `lockForUpdate`
  semantics. True parallel-thread proof would need a multi-process harness (not built — documented).
- Residual (OUT of approved T1 scope, documented for later): `skipTask`, `completePacking`,
  `verifyPacking`, `cancelTask` remain unlocked — flagged, not touched per scope discipline.

## I. Tests Added

- `AssignmentRaceTest` (5): fulfillment first-winner + idempotent + conflict-preserved;
  batch conflict + terminal-rejected; startPicking wrong-state + double-start; station conflict +
  packed-terminal; startPacking wrong-state + double-start.
- `PlacementAssignmentTest` (5): NULL→assign→task→claim→confirm→picked with stock/hint/qty
  invariance asserted; wrong-warehouse / wrong-product / inactive-location / open-task rejections
  with item unchanged.

## J. Test Results (`catch_flow_verify`, MySQL 8.4.3)

- New: 10/10 passed (36 assertions).
- Regression: Fulfillment feature+unit 38/38 (133 assert, incl. 10 new) · Warehouse 10/10 (29) ·
  `SubsystemBindingTest` 3/3 (12) · schema leak check 128 tables intact.
- `php -l`: clean on all 5 files (3 services + 2 tests). Pre-change baselines (28+10+3, 0 failures)
  recorded in discovery report §8.

## K. Security Review

- No authN/authZ change; no new entry point (services only, no HTTP); no PII/log change (existing
  Log::info patterns reused, no new fields). Conflict errors expose only internal IDs + state —
  same as existing claim-conflict errors. No secret/config change. `override` flags untouched.
- Risk if Phase 7 wires HTTP without perms stands as documented (unchanged by this phase).

## L. Second Audit Result

- Re-grep 2026-09-30: zero `orders.status`/`order_status` writers in Fulfillment/Warehouse/Shipment/
  Digital; zero stock/reserved writes (only pre-existing log-context reads); `OrderService:827`
  untouched (pre-existing working-tree state from flow sessions, not this phase).
- All direct status updates enumerated: 6 new locked writes + pre-existing locked paths unchanged;
  fulfillment-status writes still ONLY via `FulfillmentTransition`. No flow bypass. No second engine.
- Inventory authority intact: `assignPlacement` writes ONLY `product_location_id` (proven by code +
  `test_null_item_assigned_then_picked_with_authority_intact` asserting stock/qty/hint unchanged).

## M. Remaining Deferred Decisions (untouched)

Y1 package cardinality · Y2 warehouse deletion · Y3 auto-release · cancel cascade · void/handoff ·
explicit complete-picking · location-move guard · HTTP API + perm enforcement (Phases 7–8).
