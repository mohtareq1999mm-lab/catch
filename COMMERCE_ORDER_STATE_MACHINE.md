# COMMERCE ORDER STATE MACHINE (Phase 1 Lock)

> Status: LOCKED. Extends: `docs/order-state-machine.md`, `docs/order-lifecycle.md`,
> `docs/database-state-transitions.md`. Source: `Marvel Order.php:18-44`,
> `OrderService:685-933`, `OrderController:169-558`, `Shipment.php:76-96`.

## 1. Five separated states (normative — never collapse)

`order_status` (business) · `payment_status` (money) · `inventory_state` (stock claim) ·
`fulfillment` entity state (warehouse) · `shipment` entity state (carrier).

## 2. Order status

`pending → processing → completed → delivered`; `pending/processing → cancelled`.
- `→completed`: actor system/payment-gateway/supervisor(`update-order-status`); pre: payment success
  (online) or mark-paid (cod/cashier); tx: `changeOrderStatus` (locks order+txn, commits inventory
  idempotent, finalizes coupon/promotion, emits `OrderStatusChanged` + `PaymentSucceeded`);
  idempotent: repeat completion = no-op (commit conditional claim); audit: actor/type recorded.
- `→cancelled`: pre: not delivered; unpaid → `release` reservation; paid+committed → `restore`;
  coupon reservation released exactly once; promotion usage decremented only if unpaid.
- `→delivered`: writer = delivery confirmation path ONLY (carrier/shipment event or supervisor —
  Q3 default: shipment `delivered` event; packer/picker CANNOT write). Order `completed` NEVER means
  warehouse-done; warehouse `delivered` NEVER means payment-done.

## 3. Payment status

`payment-pending → payment-success | payment-failed`; `payment-success → payment-refunded`.
Writer: callback pipeline / mark-paid / refund flow. Pre: server-side verify + amount/currency match.

## 4. Inventory state

`none → active → committed | released`; `committed → restored`. Writer: ONLY
`OrderReservationService`/`InventoryRestoreService` (locked conditional claims, safe no-ops).
Digital lines excluded (D1). Expiry: COD 7d, else 24h → reaper `release`.

## 5. Fulfillment state (target — replaces divergent writers)

`pending → picking → picked → packing → ready_to_ship → shipped → delivered`; exits `→cancelled` from
`pending/picking/picked/packing` (post-`ready_to_ship` cancel needs supervisor + shipment void).
`picked` (added Phase 6 with evidence: packing requires a picking-complete state distinct from
packing-in-progress). Fulfillment-level `packed` REMOVED (task-level only). Writer: single
`FulfillmentTransition` owner (model-gated like `Shipment::canTransitionTo`); same-state = no-op.

## 6. Shipment state (existing — KEEP)

`pending → label_created → picked_up → in_transit → out_for_delivery → delivered`,
`failed_delivery ↔ out_for_delivery → returned`, `delayed` loops, `cancelled` terminals
(`Shipment::allowedTransitions`, VERIFIED). Creation pre: fulfillment `ready_to_ship` (P11 guard).

## 7. Ownership matrix (authoritative writer per state)

| Entity | State | Writer | Preconditions | Side effects |
|---|---|---|---|---|
| Order | order_status | `OrderService::changeOrderStatus` | per-transition (see §2) | inventory/coupon/promo/events |
| PaymentAttempt | normalized_status | callback/webhook pipeline | server verify + match | commit/fail paths |
| Inventory | inventory_state | Reservation/Restore services | locked conditional claim | counters |
| Fulfillment | status | `FulfillmentTransition` (new, P5) | DAG + release rule | timestamps/audit |
| FulfillmentItem | status/picked qty | picking confirm (locked) | remaining check | task/batch tallies |
| PickingTask | status/claim | claim protocol / confirm | §15-18 doc | audit scans |
| PickingBatch | status | batch service | all-tasks terminal | audit |
| PackingTask | status | packing service | picked-validation | packages |
| Package | status | packing service | invariant (§20) | barcode/label |
| Shipment | status | `ShipmentService` (`canTransitionTo`) | + ready_to_ship (P11) | carrier ops |
