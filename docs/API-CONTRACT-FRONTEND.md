# FRONTEND API CONTRACT — CHANGED ENDPOINTS (WINDOW 63581db..HEAD)

- **Audience:** Frontend developers. No backend source access required to integrate.
- **Source of truth:** Current source tree + `git diff 63581db..HEAD`. Every rule/status/payload below was read from FormRequests/controllers/resources this pass. Anything unverifiable is marked **NOT PROVEN FROM SOURCE**.
- **Scope:** ONLY endpoints that are NEW / REMOVED / whose request, validation, status-codes, headers, URL format, authorization behavior, response fields or response values changed. Unaffected endpoints are intentionally omitted.
- **Companion docs:** `docs/RESPONSE-CONTRACT-AUDIT.md` (field-level proof), `docs/PROJECT-CHANGELOG-AND-API-IMPACT.md` (narrative).

## OWNERSHIP LEGEND

| Code | Meaning |
|---|---|
| **[C]** | CUSTOMER / STOREFRONT — owned by `routes/api.php` |
| **[A]** | ADMIN / MARVEL — owned by `packages/marvel/src/Rest/Routes.php` |
| **[W]** | web route (`routes/web.php`) |

Global prefixes: Marvel routes mount at `/api/v1`; customer routes at `/api/v1/general` (RouteServiceProvider adds `api`; files add their own segments). Signed routes carry no Sanctum — authorization is embedded in the signature + server re-checks.

---

# PART 1 — CUSTOMER / STOREFRONT (`routes/api.php`)

## 1.1 POST /api/v1/general/products/{id}/reviews
```text
Route File : routes/api.php:139
Ownership  : [C]  Auth: sanctum  Throttle: authenticated
Controller : App\Http\Controllers\Api\General\ProductController@addProductReview
CHANGE     : STATUS_CODE — missing create-review permission now returns 403 (was allowed)
```
### REQUEST — Body
```json
{ "rating": 5, "comment": "…", "title": "…" }
```
Validation lives in the review creation path (rating integer 1–5 required; comment required — **exact rule set NOT PROVEN FROM SOURCE** for this controller; site-review equivalent shown in 1.13).
### RESPONSE
`201` review resource · `403` `{message}` when permission absent · `422` validation.

---

## 1.2 PUT /api/v1/general/products/reviews/{id}
```text
routes/api.php:140 · [C] sanctum · ProductController@updateProductReview
CHANGE: STATUS_CODE — 403 without update-review permission.
```
Same body semantics as 1.1; `{id}` numeric path param.

---

## 1.3 GET /api/v1/general/site-reviews
```text
routes/api.php:104 · [C] public · throttle:public-api · SiteReviewController@index
NEW in window.
```
### RESPONSE
`200` collection of site-review resources (rating/title/comment/author/status approved-only — **full field list NOT PROVEN FROM SOURCE**, built by SiteReviewResource).

---

## 1.4 POST /api/v1/general/site-reviews
```text
routes/api.php:146 · [C] sanctum · throttle:authenticated · SiteReviewController@store → 201
```
### REQUEST — Body (CreateSiteReviewRequest, verified)
```json
{ "rating": 5, "title": "optional ≤191", "comment": "required ≤2000" }
```
### RESPONSE
`201` `SiteReviewResource` · `422` validation.

---

## 1.5 GET /api/v1/general/currencies
```text
routes/api.php:105 · NEW · public · CurrencyController@index
```
### RESPONSE
`200` enabled-currency list (CurrencyResource: code/name/symbol/etc — **full field list NOT PROVEN FROM SOURCE**).

---

## 1.6 POST /api/v1/general/currencies/select
```text
routes/api.php:107 · NEW · SelectCurrencyRequest (currency_code required, valid currency)
Controller: App\Http\Controllers\Api\Currency\CurrencyController@select
```
### REQUEST
```json
{ "currency_code": "usd" }   // upper-cased server-side
```
### BEHAVIOR
Sets user preference (sanctum) **and/or** guest cookie; clears effective-code cache.
### RESPONSE
`200` standard success envelope + selected currency payload.

---

## 1.7 POST /api/v1/general/device-tokens
```text
routes/api.php:143 · NEW · sanctum · DeviceTokenController@store
```
### REQUEST — verified rules
```json
{ "token": "≤512 chars (required)", "client": "client_a|client_b", "platform": "android|ios (opt, default android)" }
```
### RESPONSE
`200` `{ uuid }` — token globally unique; re-register reassigns owner.

---

## 1.8 DELETE /api/v1/general/device-tokens
```text
routes/api.php:144 · NEW · sanctum · DeviceTokenController@destroy
```
### REQUEST
```json
{ "token": "…" }
```
Deletes only caller-owned tokens → `200` success envelope.

---

## 1.9 GET /api/v1/general/digital/downloads
```text
routes/api.php:128 · NEW · sanctum · DigitalDownloadController@index
Builder: inline map over currentAssets() (lines 44–104)
```
### RESPONSE `200`
```json
{ "success": true, "data": [ {
    "entitlement": {"uuid":"…","status":"delivered","download_limit":5,"download_count":2,"expires_at":null},
    "assets": [ { "uuid":"…", "type":"FILE|PDF|URL|LICENSE|ACCESS",
                  "original_name":"…", "display_name":"…",
                  "delivery": "<signed-url | license-reveal-path | redirect-path>" } ]
} ] }
```
Asset sub-keys verified: `uuid,type,original_name` (+ display/delivery routing); **complete inner key list NOT PROVEN FROM SOURCE** beyond these.

---

## 1.10 GET /api/v1/general/digital/license/{entitlement}/{asset}
```text
routes/api.php:131 · NEW · sanctum · name general.digital.license
Controller: DigitalDownloadController@reveal → DeliveryResolver@revealCredential
```
### RESPONSES
- `200` `{ "type":"license"|"access", "credential":"<decrypted>", "revealed_at":"ISO" }`
- `403` second reveal attempt: `DIGITAL_LICENSE_ALREADY_REVEALED`
- `404` not owned / no allocation · envelope `{status,message,success:false}`

Reveal is single-shot and audited (`revealed_at`). Never embed credentials in URLs.

---

## 1.11 GET /api/v1/general/digital/url/{entitlement}/{asset}
```text
routes/api.php:136 · NEW · sanctum · audited external redirect
```
### RESPONSE
`302` redirect to stored external URL (no download-credit consumption; app never fetches target) · `404/403` envelopes on invalid/inaccessible.

---

## 1.12 GET /api/v1/general/digital/download/{entitlement}/{asset}
```text
routes/api.php:168 (root level, outside general prefix) · middleware signed + throttle:30,1
Controller: DigitalDownloadController@download → DeliveryResolver@deliver(mode download)
```
Signed URL issued by `digital/downloads` / order payload. Redemption re-checks entitlement status/ownership/limit.
### RESPONSES
Binary stream (FILE/PDF/AUDIO/VIDEO attachment) · `404` envelopes for expired/revoked/exceeded · wrong-signature → Laravel 403.

---

## 1.13 GET /api/v1/general/invoices/my-invoices
```text
routes/api.php:149 · NEW · sanctum · InvoiceController@myInvoices
Query: ?limit=N (default 15, max 100, verified) · paginated via AdminInvoiceCollection wrapper
Builder: CustomerInvoiceListResource (per row):
```
```json
{ "uuid":"…","invoice_number":"INV-…","status":"paid",
  "subtotal":0.0,"shipping_price":0.0,"total_discount":0.0,"total":0.0,
  "currency":"EGP","payment_method":"card","payment_gateway":"…",
  "generated_at":"…","pdf_generated_at":"…",
  "verification_url":"/api/v1/general/invoices/verify/{uuid}",
  "view_url":"<signed 10min>","download_url":"<signed 10min>" }
```

---

## 1.14 GET /api/v1/general/invoices/verify/{uuid}
```text
routes/api.php:151 · throttle:5,1 · InvoiceController@verify
```
### RESPONSE
Public verification payload built from CustomerInvoiceResource family: core money/status fields + `snapshot` object when present + `verification_url/view_url/download_url`.
Exact top-level key order irrelevant; **full snapshot schema NOT PROVEN FROM SOURCE** (InvoiceSnapshotResource).

---

## 1.15 GET view/{uuid} · 1.16 GET download/{uuid}
```text
routes/api.php:159 / :161 · prefix v1/general/invoices · middleware signed ONLY
Controllers: InvoiceController@viewByUuidSigned / @downloadByUuidSigned
```
Browser-facing PDF endpoints behind 10-minute signatures. `view` renders inline; `download` streams attachment. Invalid/expired signature ⇒ Laravel 403 HTML.

---

## 1.17 POST /api/v1/general/checkout  *(validation change)*
```text
routes/api.php:115 · sanctum · OrderController@checkout(OrderCreateRequest)
CHANGE: orders.address now NULLABLE — omit address for DIGITAL/pickup orders.
```
Body otherwise unchanged (items/customer/payment method…). Response order resource gained additive fields (see Part 3 §3.6 mirror — same builder family).

---

## 1.18 POST /checkout/callback · /checkout/error-callback (ANY)
```text
routes/api.php:109–110 · NEW public gateway-redirect handlers (throttle:public-api)
OrderController@checkoutCallback | @checkoutErrorCallback
```
Gateway redirects land here; response is redirect/JSON per gateway flow (**body NOT PROVEN FROM SOURCE**).

---

## 1.19 Pickup locations — `is_default` added
```text
GET pickup-locations · GET pickup-locations/{id} (public) — PickupLocationResource
RESPONSE ADDITIVE: "is_default": true|false
```

## 1.20 GET /api/v1/general/settings — additive fields
`SettingResource`: + `tiktok`, `snapchat`, `currency_selection_enabled(bool)`.

## 1.21 Reviews 403s → see 1.1/1.2.

---

# PART 2 — ADMIN / MARVEL (`packages/marvel/src/Rest/Routes.php`, prefix /api/v1)

## 2.1–2.5 PRODUCT IMPORT FAMILY (all NEW, constructor middleware `auth:sanctum` + `permission:create-product|super_admin`)
```text
Routes : 225–233 (products/import/*)  Controller: Marvel\Http\Controllers\ProductImportController
```
| Verb/URI | Purpose | Request |
|---|---|---|
| POST products/import | start async import | multipart `file` — required, mimes xlsx/xls/ods, max 20480 KB |
| GET products/import/sample | template | — |
| GET products/import/{id} | status/reconcile | — |
| POST products/import/{id}/cancel | cooperative cancel | — |
| GET products/import/{id}/download-errors | failed rows XLSX (binary; delete-after-send) | — |

**Start → `202`**: `{status:202,message,success,data:{import_id,status:"pending"}}`
**Status → `200` data** (no-cache headers):
```json
{ "id":12, "status":"pending|processing|cancelling|completed|completed_with_errors|failed|cancelled",
  "total_rows":0, "processed_rows":0, "success_rows":0, "failed_rows":0,
  "progress":0.0, "errors":[…] }
```
**Cancel →** terminal states give `409`; else `200` `{import_id,status:"cancelled"}` (+ realtime event).
**Errors download:** binary; `404` envelope when no errors.

## 2.6 GET products/export — UNCHANGED sync binary XLSX (G3 deferred; listed because siblings changed)

## 2.7–2.11 CATEGORY IMPORT FAMILY — NEW (auth + category import perms; `CategoryImportRequest`: file required/mimes xlsx,xls,ods/max 20480 translated messages)
Routes 154–158. Same verb set as §2.1–2.5.
Status data keys differ: `successful_rows` (not `success_rows`) and adds `created_at`,`completed_at`:
```json
{ "id":9,"status":"…","total_rows":0,"processed_rows":0,
  "successful_rows":0,"failed_rows":0,"errors":[],
  "created_at":"…","completed_at":null }
```
Cancel on terminal → `409`; else `200 {import_id,status:"cancelled"}` + broadcast.

## 2.12–2.14 CATEGORY EXPORT FAMILY — NEW (EXPORT_CATEGORY)
Routes 159–161. Start `POST`→ actually **GET categories/export** returns `202 {export_id,status:"pending"}`; `GET categories/export/{id}` status (same keys as 2.11 minus progress signal merge); `GET …/download` → binary or `409 EXPORT_NOT_READY`.

## 2.15–2.19 BRAND IMPORT FAMILY — NEW (BrandImportRequest same file rules)
Routes 136–140. Status mirrors Category keys (`successful_rows` style). Cancel/errors identical semantics.

## 2.20–2.22 BRAND EXPORT FAMILY — NEW (EXPORT_BRAND) — Routes 141–143; same shapes as category export.

## 2.23–2.25 CATEGORY BULK DELETE — NEW (DELETE_CATEGORY) — Routes 162–164
| Verb | URI | Notes |
|---|---|---|
| POST | categories/bulk-delete | Body `{ "ids":[int,…] }` — ids required/array/min:1/*integer/min:1/distinct/exists → `202` started envelope (operation id key: `bulk_delete_id`) |
| GET | categories/bulk-delete/{id} | status: effective `cancelling` when cancel signal present; keys: id,status,total_rows,processed_rows,**successful_rows**,failed_rows,errors,**error_count**,created_at,completed_at |
| POST | …/{id}/cancel | writes cancel signal → `200 {bulk_delete_id,status:"cancelling"}`; job later flips DB to cancelled + realtime |

## 2.26–2.32 DIGITAL ASSETS — NEW (product mgmt perms; license-keys → manage-digital-licenses)
Routes 234–241.
| Verb/URI | Request (verified rules) | Response |
|---|---|---|
| GET products/{product}/digital-assets | — | list of `DigitalAssetResource` |
| POST products/{product}/digital-assets | multipart: `file` (required unless URL/ACCESS/LICENSE type; prohibited otherwise), registry-driven mimes/maxKb; `type` sometimes∈registry; `original_name`≤255; `sort_order`≥0 int; `external_url` required-if-URL ≤2048; `secret` required-if-ACCESS ≤2000 | asset resource |
| GET digital-assets/{uuid} | — | resource |
| PUT digital-assets/{uuid} | DigitalAssetUpdateRequest (metadata) | resource |
| DELETE digital-assets/{uuid} | — | ack |
| POST digital-assets/{uuid}/replace | multipart `file` required (registry rules); `display_name` opt; FILE-type only (else 422 DIGITAL_ASSET_NOT_REPLACEABLE) | resource |
| POST digital-assets/{uuid}/license-keys | `{ "keys": ["…"], min:1, max: config digital.licenses.max_batch_keys=500 }` | pool summary |

## 2.33–2.37 ENTITLEMENT MANAGEMENT — NEW (manage-digital-access) — Routes 244–248
| Verb/URI | Notes |
|---|---|
| GET digital-entitlements | paginated `DigitalEntitlementResource`: uuid,status(pending\|delivered\|revoked),download_limit,**unlimited**(bool),download_count,delivered_at,revoked_at,expires_at,order_id,order_product_id,user{id,name,email}(whenLoaded),product{id,name}(whenLoaded),created_at |
| GET digital-entitlements/{uuid} | single |
| PATCH …/limit | `{ "limit": int|null ≥0 ≤4294967295 }` — null may denote unlimited (**semantics NOT PROVEN FROM SOURCE**) |
| POST …/revoke · /restore | state transitions; resource returned |

## 2.38 GET /api/v1/dashboard/order-stats — MODIFIED
Middleware: sanctum·throttle:analytics·permission:view-analytics. No params.
```json
"data": { "today":   { "pending":0,"processing":N,"completed":0,"cancelled":0,"delivered":0,
                       "refunded":0,"failed":0,"local_facility":0,"out_for_delivery":0 },
           "weekly":{…}, "monthly":{…}, "yearly":{…} }
```
Δ: processing real; delivered added; unknown statuses appear dynamically.

## 2.39 dashboard/products — MODIFIED
Additive `digital` block; `inventory_value`/`out_of_stock` physical-only:
```json
"digital": {
  "digital_products":0,"digital_units_sold":0,
  "entitlements":{"active":0,"revoked":0,"expired":0},
  "downloads":{"total":0,"last_30_days":0},
  "licenses":{"available":0,"consumed":0}
}
```

## 2.40 dashboard/low-stock — physical-only rows (digital excluded).

## 2.41 dashboard/revenue — ADDED `"revenue_by_currency":{"EGP":0.0}`; totals base-converted.

## 2.42 dashboard/finance — base-converted; ADDED `"gross_by_currency"`.

## 2.43 dashboard/sales · 2.44 orders · 2.45 categories · 2.46 coupons — monetary values base-converted (structure unchanged).

*(overview / recent-orders(?limit≤50) / top-products(limit≤50) / category-stats / cart / reconciliation — NO observable change; recent-orders/top-products/low-stock accept `limit` default 10 max 50.)*

## 2.47 PATCH /api/v1/orders/{id}/status — MODIFIED
Routes:178 · OrderStatusUpdateRequest · OrderController(updateStatus)·Marvel OrderResource.
- Path `{id}` numeric-enforced (non-numeric → 404 pre-controller).
- Accepted statuses now include **processing**.
- Response now returns FULL order-detail payload (previously list-shape) incl. new **`available_statuses`** array.

## 2.48 Refunds apiResource — AUTHORIZATION CHANGE (Routes 405–408; sanctum+throttle:refunds)
Anonymous access now **401** (was data leak); cross-user show corrected. Verbs index/store/show/update/destroy individually affected identically.

## 2.49–2.52 INVOICES ADMIN GROUP (Routes 409–419, sanctum)
- GET invoices/ — paginated AdminInvoiceResource (ADDED view_url; download_url host/path changed).
- GET invoices/{uuid}/download · {uuid}/view — binary, throttle:30,1 (URLs changed format).
- GET invoices/{id} · uuid/{uuid} — restored full canonical InvoiceResource (was dead/empty at baseline): see audit A5 field list (34 fields incl. hashes/attempts/correction/cancellation/audit timestamps).
- POST {id}/regenerate|correct|cancel|debit-note — action envelopes (resource-based; **exact action bodies NOT PROVEN FROM SOURCE**).

---

# PART 3 — SHARED RESOURCE FIELD MAPS (for mapping UI)

**CustomerOrderResource additions:** `converted_total,currency,base_currency,catalog_currency,exchange_rate, digital_downloads[]?(uuid,order_item_id,status,download_limit,download_count,delivered_at)`
**OrderItemResource additions:** `converted_unit_price, converted_total_price, item_type`
**Marvel OrderResource (show/update-status):** detail block now also on PATCH; +`available_statuses[]`
**CartResource:** money fields catalog→effective converted; **+`currency`**
**SettingResource:** +tiktok,+snapchat,+currency_selection_enabled
**PickupLocationResource:** +is_default
**ProductResource:** +item_type
**TokenResponses:** +expires_at (TTLs: token=14 weekdays, others=1 week)

---

# PART 4 — REMOVED

| Route File | Endpoint | Note |
|---|---|---|
| routes/web.php | GET /test-pusher | security removal (credential leak). Any hardcoded frontend reference must be deleted. |

---

# PART 5 — FRONTEND MIGRATION CHECKLIST

1. Replace continuous polling with Pusher subscription `private-users.{userId}` (events `*.import.progress`, `*.export.completed|failed`, `category.bulk-delete.*`); reconcile via the status GETs above.
2. Stop calling `/test-pusher`.
3. Handle 403 on review create/update; ensure session permissions requested.
4. Render `digital_downloads` from order payloads; deep-link signed download URLs; treat 403 as expired link → refetch library.
5. Multi-currency: read `currency`/`gross_by_currency`/`revenue_by_currency`; never assume base denomination on cart totals when `currency_selection_enabled=true`.
6. Show processing bucket in order-status widgets.
7. Use `available_statuses` to drive admin status dropdowns.
