# Order Lifecycle — Complete Architecture (FINAL)

One coherent story: checkout → order → payment → inventory → (warehouse
dormant) → shipment tracking → delivery → cancellation/return. ONE
lifecycle, ONE flow engine, ONE status pipeline.

## 1. Subsystem ownership

| State | Owner | Mutated by | Never by |
|---|---|---|---|
| `orders.status` + `current_status_id` | `OrderService::changeOrderStatus()` (canonical) | unified `PATCH /orders/status`, legacy single PATCH (delegate), gateway callbacks, `markCodAsPaid/markCashierPaid`, `ShipmentService::maybeCompleteOrder` (system), `CancelUnpaidOrders` (intentional bypass, ORD-1) | fulfillment, shipment, picking, packing, listeners |
| `payment_status`, transactions | `PaymentCompletionService` (callbacks, idempotency-token first) + F-1 `mark_paid` | — | order flow inputs, shipment |
| `inventory_state`, counters | `OrderReservationService` (none→active→committed/released; `InventoryRestoreService` for paid cancels) | checkout, `changeOrderStatus` (completed/cancelled), `CancelUnpaidOrders` | fulfillment/shipment |
| `fulfillment_status` (order mirror) | `changeOrderStatus()` map (processing/cancelled/delivered) | — | `FulfillmentTransition` (owns `fulfillments.*` only) |
| `fulfillments`, picking, packing | `FulfillmentService` / `FulfillmentTransition` / `OrderPickingService` / `PackingService` | warehouse phase (dormant: no routes/callers yet) | order pipeline |
| `shipments` table | `ShipmentService` (`canTransitionTo` guard) | staff shipment APIs, packing chain | — |
| `orders.shipment_status` (ops mirror) | `RecordShipmentStatusInTimeline` listener (via admin shipment API) | — | order flow |
| returns | `ReturnService` (dormant: requires shipped/delivered fulfillment) | future return APIs | — |
| refunds (money) | `PaymentRefundService` (gateway, idempotent) + Marvel `RefundController` (records; customer request, admin approve) | — | status pipeline |

## 2. Creation transaction (regular checkout)

`OrderService::addItemsInOrder()` — ONE `DB::transaction`: lock cart →
coupon revalidation → totals → pending lookup + **shipping_type guard**
→ flow resolution + checkout input validation (fail closed, zero writes)
→ create/update order + pin flow → sync items → reserve inventory
(throws on insufficient stock → full rollback) → clear cart slice →
commit → `finalizeOrder` dispatches `OrderCreated` AFTER commit.
Fast checkout mirrors this with local-forcing + international-pending
rejection. Failure answers: validation/flow/creation/inventory/payment-init
failures leave no order and no reservation; event/job failures are
reported, never rolled back into the order.

## 3. Payment → inventory → fulfillment chain

Online callback: idempotency token → verify amount/currency → mark paid →
`reserve→commit` → `changeOrderStatus(completed, emit=false)` →
`PaymentSucceeded` (invoice, coupon, notifications). COD/cashier:
pending order + ACTIVE reservation → releasable to warehouse (deferred)
→ `mark-paid` (needs `payments.mark_paid`) → same canonical completion.
Cancel: unpaid → release reservation; paid+committed → restore to stock;
coupon/promotion rules per policy. Expiry: `orders:cancel-unpaid`
(24h online/cashier, 7d COD) with gateway paid-check + lock + re-check.

## 4. Fulfillment / picking / packing / shipment / delivery

Fulfillment release rule: capture methods need COMMITTED+paid; deferred
need ACTIVE+pending; cancelled/delivered never release. Idempotent
(`idempotency_key` or pending-per-order-warehouse dedupe). Picking uses
claim/lease + expiry sweep; packing verifies then creates shipments at
the `ready_to_ship` boundary; dispatch/deliver advance shipment +
fulfillment atomically; `maybeCompleteOrder` moves completed+paid+
all-fulfillments-delivered orders to `delivered` through the canonical
pipeline. **Dormant in production**: no HTTP surface and no production
callers for release/picking/packing/returns yet — the next
fulfillment/picking phase must expose them; the order lifecycle does
NOT depend on them today.

## 5. Cancellation / return paths

- Staff: `PATCH /orders/status → cancelled` (any non-terminal except
  completed/delivered) with full side effects.
- Customer: `POST /general/orders/{id}/cancel` (owner, pending/processing
  + unpaid only) → same pipeline.
- Expiry: `orders:cancel-unpaid` (intentional `changeOrderStatus`
  bypass: identical state effects minus promotion decrement for
  never-paid orders, ORD-1).
- Returns: staff `PATCH → returned` around `out_for_delivery` exits
  (terminal); customer return-request APIs deferred until fulfillment
  exists (ReturnService requires shipped/delivered fulfillment).
- Refunds: `POST /admin/payments/{order}/refund` (gateway, idempotent,
  `payments.refund`) + Marvel refund records (customer request, admin
  approve → wallet credit + credit note).

## 6. Customer API readiness (all PASS)

Discover flows (guest `available`) · render inputs (schema + sources) ·
checkout (order + flow pinning) · read order incl. `payment_status` /
`fulfillment_status` / flow / current status · track (timeline,
progress, `can_cancel`, ETA) · cancel (new endpoint) · retry payment
(pending reuse) · invoice · refunds request. FAIL (deferred with
reason): return-request (needs fulfillment), shipment-object read
(staff-only; tracking timeline covers customers).

## 7. Admin API readiness (all PASS)

Flow/catalog/input management (guards) · order list/detail · unified +
legacy status mutation (dual permission) · mark-paid (financial perm) ·
gateway refund · shipment CRUD + order shipment fields (permission-gated,
SEC-1 fixed) · tracking dashboard (own `view-orders` check) · analytics.
FAIL (deferred): fulfillment/picking/packing/return consoles (dormant
services, next phase).

## 8. Concurrency / idempotency

Pending-per-user partial unique index · cart/order/inventory
`lockForUpdate` · locked conditional inventory claims (no double
commit/release) · payment idempotency tokens + duplicate-hold ·
per-order transactions in bulk mutation · fulfillment/shipment
idempotency keys · last-active-flow guard in-transaction with row lock.
Known non-invariant: order `shipment_status` transitions are unguarded
(admin ops field, timeline-audited).

## 9. What was intentionally NOT built

Second engines (flow/status/payment/inventory/fulfillment/shipment) ·
tenancy · versioning · edges table · dynamic shipping types ·
fulfillment/picking/packing/return HTTP surface (dormant phase) ·
customer shipment-object API · `is_default` routing · legacy-union
removal (parity-gated) · OR-permission tightening (role-audit-gated).
