# COMMERCE SECURITY MODEL (Phase 1 Lock)

> Status: LOCKED. Verified: routes/api.php groups, in-controller authorizeAdmin,
> Permission enum (shipment + pickup-location only), mark-paid routes.

## 1. Permission set (new, kebab-case per convention; financial perms untouched)

`view-warehouse, manage-warehouse, view-location, manage-location, view-fulfillment,
manage-fulfillment, picking-execute, packing-execute, fulfillment.override,
inventory.adjust` (existing `view/create/update-shipment*`, `update-order-status` reused).

## 2. Separation (normative)

Picker/packer roles get `picking-execute/packing-execute` + `view-*` ONLY — never
`update-order-status`, payment, or refund perms. Marking paid/completed/delivered stays with
existing financial/owner paths. Supervisor override isolated in `fulfillment.override`.

## 3. Scoping (normative)

Every WMS query scoped `where(warehouse_id, actor.warehouse_id)` unless `manage-warehouse`.
Task mutate requires `claimed_by = actor` (or override). Barcode scans validated server-side
(expected vs scanned); qty validated under lock; all rejects audited. Cross-warehouse access →
404 (not 403, anti-enumeration); claim conflicts → 409.

## 4. P1 fixes (block B4/B5/B6/M1)

B4: unknown-order callback → failure/unknown. B5: test bypass env-gated (`app()->environment`
allowlist, never URL-substring). B6: move mark-paid under admin namespace + ownership/audit +
consider supervisor dual-control. M1: dedicated `distribute-coupon` perm.
