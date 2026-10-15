# API Domain Coverage — summary (IN PROGRESS)

> Denominator: 533 runtime routes (515 api/v1). Per-route detail: error/route-inventory.md. Contracts per domain: error/api-contracts/. Test rows: error/test-matrix.md. All counts VERIFIED via Route facade (lead script) + Agent A live --path checks.

## Per-domain route counts (VERIFIED)
| Domain | Routes | Notes |
|---|---|---|
| general storefront (api/v1/general) | 76 | nav/catalog/coupons/checkout/orders/tracking/digital/invoices/reviews/tokens/site-reviews; incl. callbacks + webhooks + mark-paid |
| admin (api/v1/admin — WMS/orders/flows/refunds/analytics/tracking) | 108 | Route-facade bucket; Agent A 115 by family sum (±7 grouping diff, INFERRED). Includes warehouses/locations/fulfillments/picking/batches/packing/stations/packages/shipments/inventory + order-statuses/flows/inputs + refunds + exports + gateways + tracking + admin notifications |
| dashboard (api/v1/dashboard) | 21 | 15 DashboardController + 5 AnalyticsController +1 (aggregate; static sums 20, ±1 unattributed) |
| coupons (api/v1/coupons) | 20 | resource 5 + assignments 5 + rules + validate/usage/suggest + targeting 3 + distribute 3 |
| products | 19 | 12 custom (bulk/import/export) + resource 5 + 2 nested digital-assets |
| categories | 18 | feature + resource 5 + import 5 + export 4 + bulk-delete 3 |
| brands | 16 | reorder + resource 5 + import 5 + export 5 |
| users | 10 | index/show/update + block/unblock + assign/remove-role + 3 permission routes |
| invoices | 10 | index/uuid/show/regenerate/correct/cancel/debit-note/download/view/verify |
| governorates | 8 | + change-status + fast-shipping toggle + cities nested |
| currencies | 8 | + set-base/set-catalog/rate-mode |
| sections | 8 | + reorder/types/toggle |
| contacts | 7 | resource-update + reply + 2 delete-alls |
| sliders | 7 | change-status + reorder + resource 5 |
| countries | 7 | + governorates nested + change-status |
| content-pages | 7 | + attach-sections + toggle-active |
| static-pages | 7 | 3 + sections 4 (reorder first) |
| cart | 7 | index/store/show/bulk/update/delete-item/delete-items (throttle:cart) |
| roles | 6 | + roles/{roleId}/permissions |
| notifications (user) | 6 | index/unread/show/read/read-all/destroy |
| notifications (admin) | 6 | index/unread/read/read-all/destroy/destroyAll (permission-gated) |
| shipments (generic) | 6 | index/store/uuid/show/update/status (NO warehouse scope — SEC-008) |
| admin-users | 6 | add/update-activation/delete/restore/trashed/delete-forever (constructor permission) |
| flash-sale | 6+1 | resource 5 + reorder + product-info |
| faqs | 6 | resource 5 + reorder |
| address | 5 | full resource (customer_id=auth.id — good) |
| pickup-locations | 5 | full resource |
| attributes | 5 | full resource |
| orders (Marvel) | 5 | GET / + GET {id} + PATCH /status (batch) + PATCH {id}/status + GET {id}/statuses |
| banners | 5+2 | resource 5 + singular change-status/reorder (split family) |
| cities | 5 | full resource |
| shipping-prices | 5 | full resource |
| currency-rates | 5 | full resource |
| digital-assets | 5 | show/update/destroy/replace/license-keys |
| digital-entitlements | 5 | index/show/limit/revoke/restore |
| promotions | 5 | full resource |
| tags | 5 | full resource |
| refunds (Marvel) | 5 | full resource |
| refunds (admin) | 4 | index/show/approve/reject (payments.refund) |
| user group (api/v1/user) | 5 | notif-prefs 2 + devices 2 + history |
| auth | ~12 | register/token/admin-login/social/logout/me/forget x3/OTP x2/change-password/update-contact |
| social | 4 | redirect/redirectFromQuery/exchange/callback |
| site-reviews | 4 | general index + store + admin index/show/approve/reject (4 admin) |
| misc | — | settings 2, logs/activity 1, fast-shipping 5, track-order 1, enum-types 1, product-type 1, wishlists 3, permissions 1, content sections/types |

## Contract/DB/side-effect status per domain
- Discovery (method+URI+controller+middleware): PASS for all domains (Agent A + route-inventory).
- Request/response contract examples (error/api-contracts/): NOT STARTED (skeleton only).
- DB read/write footprint: PASS for Order Flow + WMS (Agents E/F); PARTIAL for others (needs per-controller tracing).
- Localization keys: PARTIAL (inventory done, per-rule audit pending).
- Test rows: discovery only; execution BLOCKED except safe read-only + one safe test file (see test-matrix, verification-evidence).

## Upstream/prerequisite vs direct vs adjacent (Order Flow/WMS)
- Direct: checkout, order statuses/batch/statuses, flows/inputs, fulfillments/picking/batches/packing/packages/shipments/inventory/placements/cancel paths.
- Upstream prerequisite: cart, products/variants, shipping prices, coupons/promotions, currencies, payment gateways, addresses, governorates/cities.
- Adjacent: auth/RBAC, catalog, CMS, notifications/devices, reviews, dashboard/analytics/exports, invoices/refunds/digital.
