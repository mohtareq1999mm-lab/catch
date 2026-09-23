# COMMERCE WAREHOUSE FLOW (Phase 1 Lock)

> Status: LOCKED. Verified: `Location.php`, `Warehouse` (`default()` scope),
> migrations 081818/081824/081825. One warehouse at launch; `warehouse_id` everywhere.

## 1. Location model (reuse existing hierarchy)

`locations{warehouse_id, parent_id→self, code, name, type, status, priority, barcode unique
nullable (P6), metadata}`. Hierarchy Warehouse→Zone→Aisle→Rack→Shelf→Bin via `parent_id` +
structured `code`. Rules: code unique per warehouse; `parent_id` must share `warehouse_id`;
inactive location blocks new allocation, keeps history; `priority` drives allocation order.

## 2. Location semantics (locked — place ≠ condition ≠ sellability)

Types: `receiving, storage, picking, packing, staging` (operational, placeable) ·
`quarantine, damaged, returns` (non-sellable placement; informational only).
Sellability is decided SOLELY by central authority; location type NEVER changes availability math.
Allocation filter (normative): only `active` locations of placeable types in the order's warehouse.

## 3. Barcode architecture (locked)

Separate identities: Product/Variant WHAT (SKU as scannable v1 via resolution layer) ·
Location WHERE (`LOC-{warehouse}-{code}`, generated unique immutable) · Order (`order_number`) ·
Fulfillment (`FUL-YYYYMMDD-XXXXXX`, keep) · Package (`PKG-…` unique) · Shipment (tracking_number).
Never encode mutable location into product identity. Resolution layer maps scanned string →
{kind, entity} so multi-barcode/EAN/UPC/GTIN later needs no picking rewrite. `product_barcodes`
table deferred until business requires multi-barcode (Q5 default: single).
