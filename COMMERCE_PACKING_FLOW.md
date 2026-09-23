# COMMERCE PACKING FLOW (Phase 1 Lock)

> Status: LOCKED. Verified: `PackingService:100-153`, `packing_stations/tasks` migrations.

## 1. Flow

Picked → packing task (per fulfillment, at station, claimed worker) → scan item (must match a
`picked` fulfillment item, qty ≤ picked − already-packed, ONE tx) → create/fill package →
seal (barcode label, immutable contents) → all items packed+verified → `ready_to_ship` (owner only).

## 2. Package model (locked)

`packages{fulfillment_id, order_id denorm, package_number unique, barcode unique, status
open→sealed→handed_off (+voided), weight/dimensions}`.
`package_items{package_id, fulfillment_item_id, order_item_id denorm, quantity}`
unique `(package_id, fulfillment_item_id)`.

## 3. Invariant enforcement (normative)

`Σ(package_items.quantity) ≤ fulfillment_item.quantity_picked`, checked by locked
read-check-write inside the pack tx (unique constraint alone INSUFFICIENT per §20).
Over-pack/duplicate/unpicked → REJECT + audit. Reopen sealed package = supervisor-only
`sealed→open` with reason + audit (v1 policy; configurable later).
