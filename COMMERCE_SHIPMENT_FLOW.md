# COMMERCE SHIPMENT FLOW (Phase 1 Lock)

> Status: LOCKED. Extends: `docs/order-tracking/ADMIN_SHIPMENT_INTEGRATION.md`,
> `CUSTOMER_ORDER_TRACKING_API.md`. Verified: `ShipmentService:34-80`, `Shipment:76-96`.

## 1. Boundary (locked)

Fulfillment ends at `ready_to_ship`. Shipment begins at creation. Carrier state belongs to
Shipment; warehouse state to Fulfillment; business state to Order. Creation guard (P11):
`fulfillment.status=ready_to_ship` (locked check) + `permission:create-shipment` +
warehouse scope + idempotency key → else 409/422, never silent create.

## 2. Lifecycle (existing, KEEP)

`pending → label_created → picked_up → in_transit → out_for_delivery → delivered`
(+ `failed_delivery ↔`, `delayed`, `returned`, `cancelled`) via model-owned
`allowedTransitions`, locked `updateStatus`. Fulfillment `ready_to_ship→shipped` ONLY via
shipment-dispatch event (never direct write). Shipment `delivered` event → fulfillment
`delivered` → order completion rule evaluates (all fulfillments delivered AND payment success).

## 3. Delivery/completion (locked)

Writer of order `delivered`: delivery-confirmation path (default: shipment-delivered event;
Q3 alternatives documented). Warehouse pick/pack NEVER completes an order. Completion rule:
`status=completed AND payment=success AND all fulfillments terminal-delivered`.
