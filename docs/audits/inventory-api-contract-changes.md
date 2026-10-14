# API Contract Changes

Envelope `{status,message,success,data}` preserved. `auth:sanctum` + admin throttle
+ permissions + warehouse scoping unchanged. AR/EN via `resources/lang/{en,ar}/wms.php`.

| Method | Endpoint | Change | Request before → after | Response before → after | Callers | Verification |
|---|---|---|---|---|---|---|
| POST | `/api/v1/admin/locations/{location}/stock-receipts` | UNCHANGED (variant-aware already) | `product_id,quantity[,reference,idempotency_key]` → + optional `product_variant_id` (pre-existing) | `operation{product_id,received_quantity,stock_quantity,…}` → + `product_variant_id,sku` | none in repo | TESTED (21 variant + 14 reliability) |
| POST | `/api/v1/admin/stock-transfers` | UNCHANGED (variant-aware already) | same + optional `product_variant_id` (pre-existing) | same + `product_variant_id,sku,source/destination_placement` | none in repo | TESTED (same suites) |
| GET | `/api/v1/admin/products/{product}/placements` | UNCHANGED (canonical read; mission-suggested `/admin/inventory/products/{product}` evaluated and rejected as duplicate) | + optional `?product_variant_id` (404 missing / 422 wrong-parent), `?warehouse_id` narrow | `product{…,variants[]}` + `placements[]{…,product_variant_id,sku,variant_attributes,allocated_hint+note}` (old fields intact) | none in repo | TESTED |
| GET | `/api/v1/admin/inventory/overview` | PRE-EXISTING (verified routed) | `?warehouse_id` narrow | `{total_sku_units,simple_units,variant_units,totals_scope:'global',active_bins,today_transactions,warehouse_id}` | none in repo | TESTED |
| GET | `/api/v1/admin/inventory/operations/{key}` | PRE-EXISTING (verified routed) | — | `{key,operation,status,…,product_variant_id,result}` | none in repo | TESTED |
| CLI | `inventory:audit-placements [--warehouse=] [--json]` | NEW (console-only, read-only, no HTTP surface) | — | exit 0/1/2 + 9 checks | on-demand ops | TESTED (5 tests) |

Backward compatibility: all added fields are additive; `product_variant_id` nullable;
missing variant → 404, wrong-parent → 422 (localized, no raw keys). No breaking change.
Variable product creation with `location_id` but no SKU: 422 (documented; per-variant
receives after creation are the supported path).
