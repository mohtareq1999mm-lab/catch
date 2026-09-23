# COMMERCE FULFILLMENT FLOW (Phase 1 Lock)

> Status: LOCKED. Verified: `FulfillmentService.php:21-170`, `ProductLocationService.php:17-147`,
> `BatchPickingService:147-212`, `PackingService:100-153`, `ReturnService:218-267`,
> `Location.php` (58L), `Shipment.php:76-96`.

## 1. Creation rule (locked — resolves §3 conflict)

Fulfillment created when order is RELEASED (§1 arch doc): `status != cancelled/delivered` AND
(`inventory_state=committed` + `payment_status=success` for capture methods — commit and capture
coincide in the callback, so active+paid never coexists; Phase 6 test-proven) OR
(`inventory_state=active` + payment pending for deferred cod/cashier). Creator: release pipeline
(system; supervisor manual-trigger allowed with `manage-fulfillment`). One order → N fulfillments
(keys disambiguate; unkeyed calls return existing pending fulfillment). Implemented:
`FulfillmentService::releaseForOrder` (Phase 6).

## 2. Allocation (plan, never a mutation)

Owner: fulfillment service. Data: `fulfillment_items{product_location_id, quantity}` rows.
Deterministic (location priority → quantity desc), warehouse-scoped, quantity-safe
(allocations sum = item qty), idempotent (recompute; creation guarded by unique
`fulfillment_number` + request idempotency key, allowing N fulfillments per
(order, warehouse) for same-warehouse splits; duplicate key returns existing row).
Reads `product_locations` hints; NEVER writes central counters (normative).

## 3. ProductLocation contract (locked)

`quantity`/`allocated_hint` (rename from `reserved_quantity` in P1) are LAST-KNOWN PLACEMENT,
not sellable stock. Cases: central 10 / hints 8 → 2 unplaced, allocation capped by hints, shortfall
→ manual-assign item (existing fallback pattern, KEEP). Central 10 / hints 15 → drift: allocation
capped by CENTRAL available; drift monitor alerts (sync→alert, never throw). Picker finds 7 of 10 →
short-pick exception (§5), never silent 7-as-10.

## 4. Inventory exceptions (boundaries; built P8+)

`short_pick | missing_stock | damaged | wrong_location | wrong_product | misplaced |
cycle_count | adjustment (perm-gated) | quarantine | return_inspection`. Each: reporter, resolver
role, qty disposition (reallocate / backorder / cancel-line), supervisor-approval threshold,
customer-notification rule. Quarantine/damaged locations are non-sellable placement only — they
never alter central availability (locked §14 semantics).

## 5. Picking (one task model; `batch_id nullable`)

Lifecycle: `pending → assigned (claim) → picking → picked`; `→cancelled/released`.
Claim: conditional `pending→assigned where claimed_by NULL` + `claimed_by/at/expiry`;
expiry → auto-release; supervisor override needs `fulfillment.override`.
Scan flow: open → scan location (validate expected) → scan product/variant (validate) →
qty (validate ≤ remaining under row lock) → confirm → audit (actor, expected vs scanned, result).
Rejects (wrong location/product, over-pick) are audited, no state change.

## 6. Batch picking

Batch = container of tasks (`fulfillment_batches` exists). Fan-in: aggregate same product across
orders per location. Fan-back: each task row carries `fulfillment_item_id` (+ denormalized
`order_id/order_item_id`); confirm writes picked qty to owning item under lock. Traceability
OrderItem↔FulfillmentItem↔Task is never broken.

## 7. Packing & packages

`packing_tasks` per fulfillment at `packing_stations`. Package belongs to Fulfillment
(`order_id` denormalized); `package_items → fulfillment_items` (+ `order_item_id`).
Invariant `Σ(package_items.qty) ≤ fulfillment_item.quantity_picked` enforced by
locked read-check-write in ONE tx (unique constraint insufficient — normative).
Over-pack / duplicate / unpicked-pack all REJECT. Seal → barcode → fulfillment verified →
`ready_to_ship` via single owner only.
