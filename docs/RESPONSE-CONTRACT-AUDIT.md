# SOURCE-OF-TRUTH RESPONSE CONTRACT AUDIT

- **Project:** Meem Commerce (Laravel 10.30.1 + Marvel Shop Package)
- **Audit date:** 2026-08-25
- **Window baseline:** `63581db` ("Optimize caching and API response handling across controllers") — parent of the 44-commit window ending at `HEAD`
- **Method:** every claim proven by `git diff 63581db..HEAD` on the exact resource/controller/service plus the current route files read line-by-line. The existing changelog (`docs/PROJECT-CHANGELOG-AND-API-IMPACT.md`) was treated as a lead only; corrections to it are listed in §C.
- **No code was modified during this audit.**

---

## ROUTE-FILE DISTINCTION (proven)

| File | Mount | Resulting prefix | Owns |
|---|---|---|---|
| `routes/api.php` | `RouteServiceProvider::prefix('api')` + inner `prefix('v1/general')` | `/api/v1/general/...`, plus root-level signed invoice/digital routes at `/api/v1/...` | Customer/storefront APIs, signed invoices & downloads, gateway callbacks |
| `packages/marvel/src/Rest/Routes.php` | `RestAPIServiceProvider`: `Route::prefix('api/v1')` | `/api/v1/...` | Admin/Marvel CRUD, dashboard analytics, refunds, shipments, admin invoices, import/export |
| `routes/web.php` | web | `/test-pusher` | removed debug route |

---

# A. ENDPOINTS WITH OBSERVABLE RESPONSE CHANGES

## A1. GET /api/v1/general/invoices/my-invoices — NEW ENDPOINT

```text
Route Definition
  File       : routes/api.php:149
  HTTP       : GET
  URI        : /api/v1/general/invoices/my-invoices
  Prefix     : api → v1/general → invoices
  Middleware : api, auth:sanctum, throttle:authenticated
  Controller : App\Http\Controllers\Api\InvoiceController@myInvoices
  Builder    : App\Http\Resources\Invoice\CustomerInvoiceListResource (NEW, lines 18–46)
```

**Classification:** `RESPONSE_FIELD_ADDED · RESPONSE_RESOURCE_CHANGED · RESPONSE_URL_CHANGED`

**BEFORE:** endpoint absent.
**AFTER:** per-row payload:

```json
{
  "uuid": "…",
  "invoice_number": "INV-…",
  "status": "…",
  "subtotal": 0.0, "shipping_price": 0.0, "total_discount": 0.0, "total": 0.0,
  "currency": "EGP",
  "payment_method": "…", "payment_gateway": "…",
  "generated_at": "…", "pdf_generated_at": "…",
  "verification_url": "/api/v1/general/invoices/verify/{uuid}",
  "view_url": "<10-min temporarySignedRoute general.invoices.view>",
  "download_url": "<10-min temporarySignedRoute general.invoices.download>"
}
```

**Verdict:** additive / non-breaking (new capability).

---

## A2 / A3. GET view/{uuid} · GET download/{uuid} — NEW SIGNED ENDPOINTS

```text
Route File : routes/api.php:159 and :161
Prefix     : v1/general/invoices
Middleware : signed   (NO Sanctum by design)
Controllers: App\Http\Controllers\Api\InvoiceController@viewByUuidSigned / @downloadByUuidSigned
```

Ownership enforced at URL issuance; redemption re-checks entitlement/PDF state.
**Classification:** new surface; drives `RESPONSE_URL_CHANGED` across invoice payloads.
**Verdict:** additive; clients must use server-issued links (old literal paths no longer generated).

---

## A4. CustomerInvoiceResource (order-invoice & verify flows)

```text
Builder: app/Http/Resources/Invoice/CustomerInvoiceResource.php:26–34
```

| Field | Before | After |
|---|---|---|
| `download_url` | condition `uuid && pdf_path`; value `url('/api/v1/general/invoices/{uuid}/download')` | condition `uuid`; value `temporarySignedRoute('general.invoices.download', 10 min)` |
| `view_url` | *(absent)* | ADDED — `temporarySignedRoute('general.invoices.view', 10 min)` |

**Classification:** `RESPONSE_URL_CHANGED · RESPONSE_FIELD_ADDED · RESPONSE_VALUE_SEMANTICS_CHANGED`
**Verdict:** additive + URL migration (server-generated links keep followers working).

---

## A5. Admin invoice payloads — /api/v1/invoices/*

```text
Route File : packages/marvel/src/Rest/Routes.php:409–419 group (auth:sanctum)
Endpoints  : GET / · GET {uuid}/download (throttle:30,1) · GET {uuid}/view (throttle:30,1)
             GET {id} · POST {id}/regenerate · POST {id}/correct · POST {id}/cancel
             POST {id}/debit-note · GET verify/{uuid} (throttle:5,1) · GET uuid/{uuid}
Controller : App\Http\Controllers\Api\InvoiceController@*
Builders   : InvoiceResource (REWRITTEN), AdminInvoiceResource.php:53–61
```

**Verified baseline fact:** at `63581db`, `InvoiceResource::toArray` was **entirely commented out** — responses were effectively empty/dead (restored mid-window by commit `6d0a66c`).

**AFTER canonical fields:**
`id, uuid, order_id, invoice_number, status, subtotal, shipping_price, coupon_discount, promotion_discount, total_discount, total, amount_paid, currency, payment_method, payment_gateway, snapshot_hash, verification_hash, pdf_generated_at, generated_at, generation_attempts, last_generation_error, is_correction, correction_reason, corrected_at, cancelled_at, cancellation_reason, verified_at, downloaded_at, printed_at, archived_at, last_verified_at, verify_count, created_at, verification_url, view_url, download_url`

`AdminInvoiceResource` deltas: `download_url` now `/api/v1/invoices/{uuid}/download`; **ADDED `view_url`** (`/api/v1/invoices/{id}`).

**Classification:** `RESPONSE_RESOURCE_CHANGED · RESPONSE_FIELD_ADDED · RESPONSE_URL_CHANGED`
**Verdict:** restoration-to-contract; additive for any client that never relied on the dead shape.

---

## A6. Customer order payloads

```text
Route File : routes/api.php:117–121
Endpoints  : GET orders · GET orders/{id} · GET orders/{orderId}/invoice · POST checkout
Controller : App\Http\Controllers\Api\General\OrderController
Builders   : app/Http/Resources/Order/OrderResource.php (+41 lines)
             app/Http/Resources/Order/OrderItemResource.php (+38 lines)
```

**OrderResource — ADDED:**
`converted_total`, `currency`, `base_currency`, `catalog_currency`, `exchange_rate`,
conditional `digital_downloads[]`:
```json
{ "uuid":"…", "order_item_id":5, "status":"delivered",
  "download_limit":5, "download_count":2, "delivered_at":"…" }
```
(delivered digital lines only; never exposes storage paths/filenames).

**OrderItemResource — ADDED:**
`converted_unit_price`, `converted_total_price` (unit × order rate), `item_type` (historical snapshot, defaults `"PHYSICAL"`).

**Classification:** `RESPONSE_FIELD_ADDED` (×7)
**Verdict:** fully additive / backward-compatible.

---

## A7. Admin order show / update-status

```text
Route File : packages/marvel/src/Rest/Routes.php:176–178
Endpoint   : GET orders/{id} · PATCH orders/{id}/status (whereNumber)
Controller : Marvel\Http\Controllers\Order\OrderController@show|updateStatus
Builder    : packages/marvel/src/Http/Resources/Order/OrderResource.php:25,45
```

- `mergeWhen(request()->routeIs('orders.show'))` → now also matches **`orders.update-status`**: the customer/detail block is returned on PATCH as well → `RESPONSE_NESTING_CHANGED` (conditional) on that verb.
- **ADDED** within that block: `available_statuses` — output of `App\Services\General\OrderService::getAllowedOrderStatusTargets((string)$this->status)`.

**Classification:** `RESPONSE_FIELD_ADDED · RESPONSE_NESTING_CHANGED (conditional)`
**Verdict:** additive.

---

## A8–A17. Dashboard endpoints (10 modified of 16)

```text
Route File : packages/marvel/src/Rest/Routes.php:357–373
Prefix     : dashboard  (all GET)
Middleware : auth:sanctum · throttle:analytics · permission:view-analytics
Controller : App\Http\Controllers\Api\General\DashboardController@*
Builder    : App\Services\Dashboard\DashboardService
Baseline proof: git show 63581db:app/Services/Dashboard/DashboardService.php
               contains 'processing' => 0 hardcoded and raw SUM(total_price) everywhere;
               no digital / currency blocks existed.
```

| # | Endpoint (GET /api/v1/dashboard/…) | Observable delta | Classification tags |
|---|---|---|---|
| A8 | `order-stats` | `processing` now real counts per bucket; `delivered` key added; unknown statuses appear dynamically; legacy keys (`refunded`,`failed`,`local_facility`,`out_for_delivery`) preserved | `DATA_LOGIC_CHANGED · FIELD_ADDED` |
| A9 | `products` | `inventory_value` physical-only (**value change**); `out_of_stock[]` digital rows removed (**array content**); ADDED `digital{digital_products,digital_units_sold,entitlements{active,revoked,expired},downloads{total,last_30_days},licenses{<status>:count}}` | `VALUE_SEMANTICS · ARRAY_STRUCTURE_CONTENT · FIELD_ADDED` |
| A10 | `low-stock` | digital rows removed from array | `ARRAY_STRUCTURE_CONTENT` |
| A11 | `revenue` | money base-converted; ADDED `revenue_by_currency{CODE:amount}` | `FIELD_ADDED · VALUE_SEMANTICS` |
| A12 | `finance` | values base-converted (incl. shipping × rate); ADDED `gross_by_currency` | same |
| A13 | `sales` | daily/comparison/payment-method/fulfillment sums base-converted | `VALUE_SEMANTICS` |
| A14 | `orders` | timeline SUMs base-converted | `VALUE_SEMANTICS` |
| A15 | `categories` | line revenue × `COALESCE(currency_rate,1)` | `VALUE_SEMANTICS` |
| A16 | `coupons` | coupon revenue base-converted | `VALUE_SEMANTICS` |
| A17 | `overview` · `recent-orders` · `top-products` · `category-stats` · `cart` · `reconciliation` | NO structural change; overview sums converted-base (observable only with multi-currency rows) | `NO_RESPONSE_CHANGE` / `DATA_LOGIC` |

Multi-currency proof (from closure tests): EGP 1000 + USD 100 @ rate 50 ⇒ `gross_revenue = 6000` (not 1100); `gross_by_currency = {"EGP":1000,"USD":100}`.

**Verdict:** all additive/value-corrective; field names & nesting preserved ⇒ **non-breaking**.

---

## A18. Auth token payloads

```text
Route File : packages/marvel/src/Rest/Routes.php:67–73
Endpoints  : POST /register · POST /token · POST /admin-login · POST /social/exchange (+callback flows)
Controller : Marvel\Http\Controllers\UserController (window commits 9035ee0, 1f1072c, 1263bc0)
```

- **ADDED** `expires_at` to every token response.
- Tokens now time-boxed: `createToken('auth_token', [], now()->addWeekdays(14))` for `token`; `addWeek()` for register/admin-login/social paths (previously non-expiring).
- Login/register adopt guest currency preference (`UserCurrencyPreferenceService::adoptGuestCurrencyOnLogin`) — side-effect, not a field.

**Classification:** `RESPONSE_FIELD_ADDED · RESPONSE_VALUE_SEMANTICS_CHANGED` — additive.

---

## A19. Cart & coupon-apply payloads

```text
Route File : packages/marvel/src/Rest/Routes.php (cart group, throttle:cart)
             routes/api.php:113 (POST coupons/apply)
Controller : Marvel\Http\Controllers\CartController ; General\CouponController@applyCoupon
Builder    : packages/marvel/src/Http/Resources/CartResource.php:36–78
```

- `total_price`, `subtotal`, `coupon_discount`, `total_after_coupon` now converted catalog→effective currency via `CurrencyService`.
- **ADDED** `currency` (effective code).

**Classification:** `RESPONSE_FIELD_ADDED · RESPONSE_VALUE_SEMANTICS_CHANGED` — additive (numeric magnitudes change only for non-base currencies).

---

## A20. Settings payloads

```text
Endpoints : GET /api/v1/settings (front, routes/api.php:102) · GET/PUT settings (admin, Rest:119–120)
Builder   : packages/marvel/src/Http/Resources/SettingResource.php:41–52
ADDED     : tiktok, snapchat, currency_selection_enabled(bool)
```
Tag: `RESPONSE_FIELD_ADDED` — additive.

---

## A21. Pickup locations (public + admin)

```text
routes/api.php:99–100 · Rest apiResource pickup-locations
Builder   : PickupLocationResource.php:22 — ADDED is_default(bool)   (commit 966804b)
```
Tag: `RESPONSE_FIELD_ADDED` — additive.

---

## A22. Product payloads (customer + admin)

```text
Builder : packages/marvel/src/Http/Resources/product/ProductResource.php:31
ADDED   : item_type ("PHYSICAL" | "DIGITAL")
```
Tag: `RESPONSE_FIELD_ADDED` — additive.

---

## A23. Product review create/update — STATUS-CODE change

```text
Route File : routes/api.php:139–140
Endpoints  : POST products/{id}/reviews · PUT products/reviews/{id}
Controller : App\Http\Controllers\Api\General\ProductController
Gate added : permission:CREATE_REVIEW (store) · permission:UPDATE_REVIEW (update)
```

Callers without the new permissions now receive **403** where they previously succeeded.

**Classification:** `RESPONSE_STATUS_CODE_CHANGED`
**Verdict:** intentionally breaking for un-migrated clients (mitigated by permission seeding).

---

## A24. Refund endpoints — security-status change

```text
Route File : packages/marvel/src/Rest/Routes.php:405–408
Group      : auth:sanctum · throttle:refunds → apiResource refunds
Controller : Marvel\Http\Controllers\RefundController (+ RefundRepository)
Window     : closure-audit security fixes + commit 203844d
```

- Anonymous access to refund records: previously returned data (IDOR) → now **401**.
- Wrong-owner show inverted-admin-condition fixed → correct 403/404 semantics.

**Classification:** `RESPONSE_STATUS_CODE_CHANGED · RESPONSE_DATA_LOGIC_CHANGED`
**Verdict:** intentionally breaking for abusive callers only.

---

## A25. NEW endpoint families (no "before" exists)

| Endpoints (route file) | Response builder / shape notes |
|---|---|
| Import/export/status/cancel/download-errors ×3 families + bulk-delete trio (`Rest/Routes.php:134–164, 225–233`) | `ApiResponse` envelope `{status,message,success,data:{import_id\|export_id,status}}`; status adds `progress,total_rows,processed_rows,success_rows,failed_rows,errors`; status endpoints send no-cache headers (`Cache-Control/Pragma/Expires`) → `RESPONSE_HEADER_CHANGED` by design |
| Digital assets CRUD + replace + license-keys (`Rest:234–241`) | `DigitalAssetResource` |
| Entitlement management (`Rest:243–248`) | `DigitalEntitlementResource`: `uuid,status,download_limit,unlimited(bool derived),download_count,delivered_at,revoked_at,expires_at,order_id,order_product_id, user{id,name,email}(whenLoaded), product{id,name}(whenLoaded),created_at` |
| digital/downloads · license · url · download-signed (`routes/api.php:128,131,136,168`) | index: entitlement/asset summaries + signed URL or credential reveal (stored secrets never in list); license reveal = credential JSON (auth-scoped, never signed); url = audited 302 redirect; download = streamed binary, `throttle:30,1`, `signed` middleware |
| currencies list/select + admin FX CRUD/set-base/set-catalog (`api:105–107`; `Rest:225–232`) | `CurrencyResource` / `CurrencyRateResource` (new resources) |
| site-reviews public/admin (`api:104,146`; `Rest:227–230`) | SiteReview resources |
| static/content pages incl. sections + section-type settings subresources (both files) | StaticPageService-built `SectionResource` (eager-load optimization — identical JSON keys) |
| device-tokens (`api:143–144`) | minimal ack envelope |
| checkout/callback & error-callback ANY (`api:109–110`) | gateway redirect handlers (public) |
| orders/{orderId}/invoice (`api:119`) | CustomerInvoiceResource |

---

## A26. REMOVED — GET /test-pusher

```text
File     : routes/web.php (deleted block, −41 lines)
Response : REMOVED entirely — previously returned JSON incl.
           pusher_key, pusher_cluster, broadcast_result, direct_pusher_result
Reason   : unauthenticated Pusher credential disclosure + anonymous admin-channel trigger
Tags     : whole-response REMOVED (security fix)
```

---

# B. IMPLEMENTATION CHANGES WITH **NO** OBSERVABLE RESPONSE DELTA

Verified non-changes — must not be reported as contract changes:

- Queue/supervisor/retry_after reconfiguration; scheduler addition (`payments:reconcile` has no HTTP face; reconciliation endpoint values are driven by its results table).
- `Product::physical()/digital()` scopes, `item_type` shrink migration, sqlite parity rebuild — internal; product JSON gained only `item_type` (counted in A22).
- Realtime Pusher events (`FileOperationEvent`, `CategoryImportProgress`) — WebSocket layer, not HTTP responses.
- Broadcast channel authorizations, signal files, once-only terminal guards, BrandImportService false-log fix.
- `SectionResource` settings resolution switched to eager-loaded relation — identical output keys (`front`, `back`).
- `check-card-payment`, `/enum-types` closures, notifications groups, roles/permissions management set, OTP group (disabled), password-reset trio, address/contacts/shipping-prices/faqs/wishlists/promotions/tags/sliders/banners CRUD — untouched this window.

---

# C. CORRECTIONS TO THE EXISTING CHANGELOG (lead vs source)

1. Changelog said “16 dashboard endpoints, 0 removed” — confirmed, but it understated that baseline `InvoiceResource` was **fully commented out**; the admin invoice contract is a **restoration**, not a modification.
2. Changelog listed `orders/{id}/status` as merely hardened — source proves an observable response change (detail payload now returned on PATCH + `available_statuses` field).
3. Changelog implied cart contracts unchanged — false at value level: cart/coupon money fields are currency-converted and a `currency` key was added.
4. Changelog attributed review gating to the customer controller — the constructor middleware addition lives in `ReviewController`; the customer routes delegate to `General\ProductController` whose store/update paths enforce the same permissions via the gated layer (net effect identical: 403).

---

# D. VERDICT SUMMARY

| Class | Count (endpoints) | Breaking? |
|---|---|---|
| NEW | ≈45 | n/a — additive surface |
| FIELD_ADDED only | 8 groups (A6, A7, A18, A19, A20, A21, A22, A11/A12 additions) | No |
| VALUE_SEMANTICS / DATA_LOGIC | 14 (dashboard money/status correctness) | No (corrective) |
| STATUS_CODE_CHANGED | 3 (reviews create/update → 403; refunds anonymous → 401) | Yes-by-design (security) |
| URL_CHANGED | 4 (invoice link family A2–A5) | Link-format migration; server-issued links keep clients working |
| RESOURCE_CHANGED | 2 (InvoiceResource restoration; CustomerInvoiceListResource new) | Restoration additive |
| REMOVED | 1 (/test-pusher) | Intentional |
| NO_RESPONSE_CHANGE (impl-only work) | queues/scheduler/scopes/events/log-fix/section-eager-load | n/a |

**Overall:** every response change in the window is either additive or corrective. The only client-visible breakages are the three intentional authorization-status changes and the retired debug route.
