# COMMERCE WORKFLOW FINAL REPORT

> Branch: main @ 107dd6b. All phases executed autonomously with gates.
> Legend: VERIFIED = source read or test executed this run.

## Executive Summary

One coherent commerce lifecycle now runs from checkout through delivery with
proven guards at every boundary: provider-agnostic payment (3 BLOCKERs fixed),
single-writer order states, deterministic pricing, singular inventory authority,
release-gated fulfillment, single-owner fulfillment DAG, scan-validated picking
(order + batch), invariant-guarded packing, guarded shipment with a derived
completion rule, per-line return restores without double-credit, least-privilege
warehouse security, and auditable everything. 245 tests pass across 17 suites;
3 pre-existing stress-rig failures remain (unchanged, documented).

## Final Architecture

Release-to-Warehouse: checkout → reserve(active) → payment attempts (adapter
boundary) → server verification → commit/release → per-method RELEASE
(committed+paid online; active+pending cod/cashier) → fulfillment → allocation
(plan, non-mutating) → picking (claim→scan→confirm) → packing → packages →
shipment (ready_to_ship guard) → dispatch → delivery → derived completion.
Five separated states; one writer each. Master diagram:
COMMERCE_FAILURE_RECOVERY.md.

## Payment Architecture

Contract + factory (myfatoorah live; cod/cashier manual; Marvel legacy dead);
transactions = attempts (≤1 paid via commit claim); token+status idempotency;
millis amount/currency fail-closed; B4 fail-safe unknown-order, B5 env-gated
bypass (`payment.test_gateway_bypass_enabled`), M2 token rotation on
coupon-block. Zero provider branches in OrderService (verified).

## Order Lifecycle

Sole writer `changeOrderStatus` (transition maps throw →422, audit history,
actor tracking); Marvel admin route uses it; legacy repository bypass contained
(§27 decision item); D1 dispatch guard (listener failures can't roll back
completion).

## Inventory Architecture

Authority products/variants; available = stock − reserved; conditional-claim
reserve/commit/release/restore; digital exclusion; COD 7d/online 24h expiry.

## Fulfillment Architecture

`releaseForOrder` (rule + keyed/unkeyed idempotency + splits);
`FulfillmentTransition` sole owner; DAG
pending→picking→picked→packing→ready_to_ship→shipped→delivered (+supervised
cancels); ghost `packed` removed; operational cancel orchestration.

## Warehouse Architecture

Reused hierarchy + type semantics (placeable scope; NULL-type legacy
compatible); `allocated_hint` replaces authority-confusing reserved_quantity;
drift monitor (bool) replaces throwing gate; barcode column + resolver
(WHAT/WHERE/WHICH + package).

## Picking Architecture

One task model (`batch_id` nullable); claim protocol (exclusive, lease,
override, sweeper command + schedule); scan-validated confirm (locked,
op_seq dedupe, reject audit); batch fan-out with denorm + double-pick guard.

## Packing Architecture

Task lifecycle; packages under fulfillments; locked pack invariant
(Σ ≤ picked); multi-package splits; seal rules; barcode labels.

## Shipment Architecture

`createForFulfillment` (ready_to_ship guard + idempotency); dispatch advances
both sides; delivery walks carrier chain; completion rule (completed + paid +
all fulfillments delivered; digital-only untouched). Legacy creation delegated.

## Return/Refund Architecture

Request lifecycle intact; sellable restock → central `restoreLines` (capped,
digital-excluded) BEFORE hint update; `restored_quantity` per line; full
restore covers remainders only; duplicate restock rejected; dead duplicate
listener DELETED.

## Security Model

10 WMS permissions + 4 least-privilege roles (pickers never financial);
users.warehouse_id; `WarehouseAccess` (perm + home-scope; manage global).
Route enforcement awaits WMS APIs (guards ready).

## Concurrency Model

Locks + conditional claims/updates + unique keys + op_seq (sqlite-proven;
MySQL row-lock proof unavailable here — staging gate, connection refused).

## Idempotency Model

Per-domain keys everywhere (payment token, creation keys, claim-conditional,
op_seq, package/shipment keys, M2 rotation). Matrix: COMMERCE_IDEMPOTENCY_MATRIX.md.

## Events / Audit

All WMS mutations structured-logged (actor/reason); scan rejects auditable;
order history pre-existing; no new pre-commit side effects; sweeper scheduled.

## Legacy Cleanup

DELETED dead restore listener; REMOVED public test-PAN route; portable
ordering; KEPT (decision-gated): legacy order-update bypass, Marvel Payment/*,
RabbitMQ, payment-status fallback.

## Tests Executed (all PASS unless noted)

SecurityRemediation 32 · Lifecycle family 50 · Reservation 24 · Gift 8 ·
Fulfillment 6 · OrderPicking 5 · BatchPicking 3 · Packing 2 · Shipment 3 ·
ReturnRecovery 4 · ConcurrencyAttack 5 · Barcode 5 · WarehouseSecurity 5 ·
Unit fulfillment 51 · InvoiceLifecycle 24 · CheckoutApi 13 · PaymentCurrency 5 ·
Stress 6/9 (3 pre-existing rig failures, identical pre-change).

## Validation Evidence

Commands recorded per phase in progress file; failure→root-cause→fix→retest
trails for: B4/B5/M2, D1/listener, KWD allowlist, stale schemas (3 suites),
currency fixtures, sync wedge (live-fire), DAG jump, split-key design.

## Known Limitations

MySQL lock proof; staging-only checks (fresh-migrate CHECK/rename interplay,
queue workers, .env secrets); legacy bypass migration; supervisor void policy;
aggregated-quantity batches; carrier API; metrics dashboards.

## Remaining Risks

Dirty-tree coexistence (untouched work preserved); reaper time-sensitivity
(1 transient flake); local .env key rotation (dev-only, backed up then removed).

## Files Changed

Production: OrderController, OrderService, GenerateInvoiceListener,
CurrencyValidator, Fulfillment{Service,Transition}, BatchPickingService,
PackingService, ReturnService, ProductLocationService, Location,
ProductLocation, Fulfillment, PickingTask, Package(+Item, new),
Shipment(+Service), OrderReservationService (none — verified as-is),
InventoryRestoreService, BarcodeResolver+WarehouseAccess (new),
PickingExecutionService+OrderPickingService (new), SweepExpiredPickingClaims
(new), Permission enum+seeder, Marvel User fillable, Kernel schedule,
Rest/Routes (PAN removal). Migrations 000001–000007 + packages tables.
Tests: 8 new files, 9 extended. Docs: 12 COMMERCE_* + this report.

## Database Changes

7 additive migrations (all with down()): fulfillment keys/timestamps, location
barcode, hint rename, picking claim/traceability, packages ×2 tables, shipment
key, order_items.restored_quantity, users.warehouse_id. No destructive change.

## Final Acceptance Checklist

§25 quality gate: architecture coherent ✓ · states single-owned ✓ · payment
agnostic/idempotent/refund-safe ✓ · inventory singular/safe ✓ · WMS complete
✓ · shipment/delivery/completion ✓ · recovery verified ✓ · security modeled +
tested ✓ · validation: unit/integration/db/auth/E2E-shape ✓ (concurrency on
sqlite-subset; MySQL gate open) · docs + ledger complete ✓.

## Production Readiness Verdict

CONDITIONAL GO: ship behind staging gates (fresh MySQL migrate, queue workers,
MySQL lock proof, secrets audit, legacy-bypass decision). No BLOCKERs remain
in the implemented scope; open items are gated follow-ups, not defects.
