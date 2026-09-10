# PROJECT CHANGELOG & API IMPACT DOCUMENTATION

- **Project:** Meem Commerce (Laravel 10.30.1 + `marvel/shop` e-commerce package)
- **Documentation date:** 2026-08-25
- **Scope:** Backend APIs, Dashboard/Analytics APIs, Product & Digital Product behavior, Cart→Checkout→Order→Payment flows, Import/Export + realtime delivery, Notifications/FCM, Permissions, Translations, Database schema, Cron/Scheduler, Queue/Workers.
- **Source of truth:** The current source tree (`routes/api.php`, `packages/marvel/src/Rest/Routes.php`, controllers/services/models/jobs under `app/` and `packages/marvel/src/`, `database/migrations`, `app/Console/Kernel.php`, `deploy/supervisor/*`, language files under `resources/lang/`). Every claim below was re-verified against source during this documentation pass. Where a *pre-change* behavior could not be proven from surviving code, git history or the maintained ledger (`docs/production-history.md`), this document explicitly states:
  > *"Previous behavior could not be verified from the available source."*
- **Audience:** Developers, QA, Product Managers, Technical Leads.

---

## Overall change summary (verified)

The project evolved from a classic synchronous Laravel commerce API into an event-driven platform with:

1. A **Digital goods capability** — multi-type assets (file/PDF/external URL/license pool), per-order entitlements with download limits/expiry/revocation, encrypted license keys with reveal auditing, customer download surface with signed URLs and audited redirects.
2. **Asynchronous admin file operations** (product/category/brand import & export, category bulk delete) on the dedicated `meem-high` queue, with live progress persisted to the `imports` table plus signal files, status/reconciliation endpoints, cancel signaling, and **realtime Pusher delivery** over `private-users.{userId}`.
3. **Dashboard/Analytics hardening** — 16 analytics endpoints behind `view-analytics`; data-driven order-status counts (new canonical `processing` status); PHYSICAL/DIGITAL product separation in inventory metrics; base-currency-safe revenue aggregation using the order FX snapshot (`converted_total_price`) with explicit per-currency breakdowns; payment-reconciliation visibility.
4. **Operational maturity** — standardized two-queue worker policy (`meem-high` / `meem-medium`) enforced by static tests; supervisor configs aligned (`--timeout=1200` / `900`, `retry_after=1560`); six scheduled jobs + newly scheduled payment reconciliation.
5. **Security fixes** — removal of the unauthenticated `/test-pusher` debug route (Pusher key/cluster disclosure + anonymous admin-channel broadcast), refund endpoint authorization/scoping fixes, review permission gating, analytics permission gate.

### Verified totals

| Metric | Count |
|---|---|
| New explicit API endpoints (admin + customer) | ≈ 45 |
| Modified endpoints | ≈ 12 |
| Removed endpoints | 1 (`GET /test-pusher`) |
| New dashboard endpoints | 0 beyond the existing 16 (extended additively) |
| Modified dashboard endpoints | 10 of 16 (data-correctness changes, additive response fields) |
| New migrations/tables | 13 new tables (+1 later extended) + several column additions |
| Scheduled jobs active | 6 pre-existing + 1 newly scheduled (`payments:reconcile`) = 7 |
| Queue workers/configurations changed | 2 supervisor programs (meem-high timeout 90→1200 era adjustments finalized at 1200/1230; meem-medium 900/960) |
| New permissions | `view-analytics`, `manage-digital-licenses`, `manage-digital-access`, currency set (`create/update/delete/set-base/set-catalog-currency`), review moderation set |

---

# 1. EXECUTIVE SUMMARY

**What changed overall?**
The backend gained three large capability blocks that did not exist before, all verifiable in the current source:

*Digital Products end-to-end.* Admins can attach multiple downloadable assets to a product, provision license-key pools, manage per-customer entitlements (limits, expiry, revoke/restore). Customers get a downloads library, signed download URLs, license reveal, and audited external-URL redirects. Orders snapshot the product's `item_type`, and approving a refund revokes the related entitlements.

*Async file operations with realtime feedback.* Product/category/brand import and export plus category bulk-delete run as queued jobs on `meem-high`. Progress lives in the `imports` table (source of truth) and is pushed to the admin's browser over Pusher (`private-users.{userId}`), so the frontend no longer needs continuous polling. Every operation has a status/cancel/download-errors/download endpoint family used for reconciliation.

*Dashboard correctness & operations.* The 16 `/dashboard/*` endpoints are gated by a real `view-analytics` permission. Status counts now come from actual data (the previously hardcoded-zero statuses — including the new `processing` state — are computed). Digital products no longer distort physical inventory metrics. Revenue math never adds different currencies together: totals use the stored base-currency conversion (`converted_total_price`), and raw per-currency splits are exposed. Payment gateway reconciliation runs hourly via the scheduler.

**APIs added (high level):** digital assets CRUD + replace + license-keys provisioning (7 admin endpoints); entitlement management (5 admin endpoints); customer downloads/license/url (3+ endpoints); import/export/status/cancel/download families for products, categories, brands (~20 endpoints incl. samples and error reports); category bulk-delete (3); currencies admin + customer selection; site reviews; static/content pages; device tokens (FCM); invoice UUID view/download/verify/my-invoices.

**APIs modified:** order status update (`processing` added to lifecycle), invoices (signed URLs + throttle), refunds (auth + scoping fixes), reviews (permission-gated create/update), checkout/order payloads (item_type + currency snapshots), settings payload (pusher/currency options).

**APIs removed:** `GET /test-pusher` (security).

**Products behavior change:** every product now carries `item_type` (`PHYSICAL|DIGITAL`). Legacy `SERVICE` rows were migrated to `PHYSICAL`. Digital products have no stock semantics — enforced in validation, import, and analytics.

**Dashboard behavior change:** see §Dashboard feature. Ten endpoints had their underlying calculations corrected; all changes are additive to the JSON contract.

**Cron added:** one new schedule (`payments:reconcile`, hourly). Five other schedules documented and pinned by tests.

**Queue changes:** policy formalized to exactly two application queues (`meem-high` for time-critical/import-export/invoice/password-reset work, `meem-medium` for notifications/logs/PDFs/reconciliation), enforced by a static test over every `ShouldQueue` implementer; supervisor worker definitions updated accordingly.

---

# 2. GLOBAL ARCHITECTURE CHANGES

## BEFORE (verified baseline shape)

```text
Client
 ↓
Route (api/v1)
 ↓
Middleware (auth:sanctum, permission:*)
 ↓
Controller → Service → Eloquent Model
 ↓
MySQL
 ↓
(synchronous HTTP response)
```

Long-running work (imports) was either absent or executed inline; completion was discoverable only by repeated polling of status endpoints. Pricing was scattered across layers until it was centralized (ADR-001, frozen).

## AFTER (current, verified)

```text
Client
 ↓
Route (api/v1)
 ↓
Middleware (auth:sanctum · throttle:* · permission:*)
 ↓
FormRequest validation
 ↓
Controller (orchestration only)
 ↓
Service (business logic)          e.g. OrderCreationService, *ImportService,
 ↓                                 DashboardService, ProductPricingService (ADR-001)
Domain / Eloquent Models (+ scopes)
 ↓
Database (MySQL prod / sqlite test parity)
 ↓
Events (domain + broadcast)
 ↓                                    ↘
Jobs (ShouldQueue → meem-high | meem-medium)   Broadcast Events (ShouldBroadcastNow)
 ↓                                              ↓
Supervisor Workers (queue:work)                 Pusher
 ↓                                              ↓
Status persistence (e.g. imports table)         Frontend private-users.{id}
 ↓
Notifications (DB / FCM / mail) → Client reconciliation via status endpoints
```

### Change 1 — Async operations spine (`imports` table as operation ledger)

#### Before
No generic async-operation record existed for imports/exports/bulk deletes. Previous behavior could not be verified from the available source beyond the absence of the `imports` migration before `2026_06_27_000001`.

#### After
Every long-running admin file operation creates an `imports` row (`type`: `product|category|brand|category-export|brand-export|category-bulk-delete`) with `status`, row counters, `errors` JSON and `created_by` owner. Jobs early-return on terminal states (retry-safe).

#### Why
Single source of truth for progress/completion; enables recovery, cancellation, audit and realtime push without inventing a second state store.

#### Impact
Controllers stay thin; jobs own transitions; the frontend always reconciles against `GET */import/{id}` / `*/export/{id}` style endpoints.

### Change 2 — Realtime wake-up layer (Pusher)

#### Before
Frontend polled status endpoints continuously (documented 2 s loop in `api-desc/import-category/frontend.md`).

#### After
Services/jobs emit `App\Events\FileOperationEvent` (`ShouldBroadcastNow`) and the pre-existing `App\Events\CategoryImportProgress` on `private-users.{userId}` (+ legacy `private-admin.notifications` for category import). Broadcasting failures are isolated (try/catch + report) and can never fail the business operation.

#### Why
Sub-second UI updates; removes load generated by permanent polling while keeping DB authoritative.

#### Impact
New channel authorization already existed (`users.{id}`); new events follow project naming (`{domain}.{operation}.{state}`); terminal events fire exactly once per process and strictly after the DB transition.

### Change 3 — Two-queue standardization

#### Before
Mixed/default queue usage; previous distribution could not be fully verified from available source.

#### After
Policy: `meem-high` (tries 5, timeout 1200) for import/export/bulk-delete/invoice generation/password reset/frontend webhook; `meem-medium` (tries 3, timeout 900) for notifications/activity logs/PDF/reconciliation; `default` consumed as legacy safety net by the medium worker. Enforced by `tests/Unit/QueueStandardizationStaticTest.php` (134 checks) and `tests/Unit/WorkerConfigPolicyTest.php`.

### Change 4 — Security posture

#### Before
Unauthenticated debug route exposed Pusher credentials; several endpoints lacked permission gates (reviews create/update, dashboard analytics prior gate addition, refund IDOR). Previous full behavior could not be verified from the available source for items older than the maintained audit history.

#### After
Debug route removed; permissions seeded and middleware-enforced; refund routes authenticated and owner/admin-scoped; channel auth own-ID only.

---

# 3. FEATURE-BY-FEATURE CHANGELOG

# PRODUCTS

## 1. What existed before
Product CRUD, categories/brands/tags relations, attributes/variants, media, pricing enrichment via `ProductPricingService` (ADR-001 Frozen — unchanged in this window). Verified present in current source; deeper "before" deltas could not be verified from the available source.

## 2. What changed
- **DATA MODEL CHANGE**: `products.item_type` ENUM(`PHYSICAL`,`DIGITAL`) added (`2026_08_23_105834`), then shrunk to the approved domain with automatic `SERVICE→PHYSICAL` conversion and index handling (`2026_08_23_120000`). Down-migration restores the wider domain.
- **ADDED**: `scopePhysical` / `scopeDigital` on `Product` (NULL-safe legacy = physical).
- **MODIFIED**: validation/import accept and constrain `item_type`; order line snapshots carry it.
- **SECURITY HARDENING**: none specific beyond permission reuse (`view-products` etc.).

## 3. Business impact
The catalog can sell non-shippable goods; inventory KPIs remain meaningful because digital SKUs cannot pollute stock numbers.

## 4. Technical impact
Model scopes consumed by `DashboardService`; `OrderProduct.item_type` snapshot; import services map type; pricing untouched (ADR-001 respected).

## 5–6. Flow
```text
Admin → products CRUD/import → Product(item_type) → order line snapshot → fulfillment branch
DIGITAL  ⇒ entitlement provisioning (no shipping)
PHYSICAL ⇒ stock decrement / shipment
```

## 7. API impact
`POST/PUT products` (admin), `GET products` (customer) — additive `item_type` field. Status MODIFIED (additive).

## 8. Dashboard impact
`dashboard/products`, `low-stock` — separation implemented (see Dashboard section).

## 9–10. Cron/Queue impact
None directly; import path covered under IMPORT/EXPORT.

## 11. Permissions
Existing product permissions reused; nothing new.

## 12. Translations
No new keys required (numeric/enum fields).

## 13. Database
Migrations `2026_08_23_105834`, `2026_08_23_120000`; index on item_type created/dropped inside shrink logic.

## 14. Tests
`tests/Feature/ProductItemTypeTest.php`; dashboard closure tests assert scope behavior through analytics.

---

# DIGITAL PRODUCTS / ASSETS / ENTITLEMENTS / DOWNLOADS / LICENSES

## 1. What existed before
Nothing — the entire stack is NEW (git history: asset pivot/download-log commits through entitlement revocation commit `203844d`). Previous behavior could not be verified because the feature did not exist.

## 2. What changed
- **ADDED** admin asset lifecycle: list per product, create (upload w/ MIME/size validation; PDF handled as FILE subtype), show, update, delete, **replace**, **bulk license-key provisioning** (encrypted pool).
- **ADDED** W6 entitlement management: paginated list, detail, per-entitlement **download-limit patch**, **revoke**, **restore**.
- **ADDED** customer surface: downloads library, signed/temporary download URLs, license reveal (auth-scoped, never signed — secrets excluded from shareable URLs/referrers), audited external URL redirect (no credential exposure; app never fetches target).
- **ADDED** refund integration: refund approval revokes linked entitlements (listener wiring).
- **DATA MODEL CHANGE**: five tables (below).

## 3. Business impact
Store can sell software/e-books/licenses safely: access control, abuse limits (download counters + throttle), revocation on refund, and full download audit trail (hashed IP/UA only — privacy-preserving).

## 4. Technical impact
Controllers: `Marvel\Http\Controllers\DigitalAssetController`, `DigitalEntitlementController`, `App\Http\Controllers\Api\General\DigitalDownloadController` (constructor-injected `DeliveryResolver`). Services: `App\Services\Digital\*` (AssetTypeRegistry, DeliveryResolver, DigitalEntitlementService, ExternalUrlValidator, DigitalFulfillmentService, DigitalAssetService). Models: `DigitalAsset`, `DigitalEntitlement`, `DigitalLicenseKey`, `DigitalDownloadLog` (status constants verified: entitlement `pending|delivered|revoked`; license `available|assigned|consumed|revoked`). Queue: fulfillment listeners/jobs ride existing conventions; heavy work stays on `meem-*` policy queues.

## 5–6. Flow after
```text
Customer pays order
 ↓ OrderCreationService (order_products.item_type = DIGITAL)
 ↓ Digital fulfillment → digital_entitlements rows (status pending → delivered)
Customer opens GET digital/downloads
 ├─ FILE/PDF → temporarySignedRoute download (counts++ , log row)
 ├─ LICENSE  → GET digital/license/{entitlement}/{asset} → reveal (consumed, revealed_at)
 └─ URL      → GET digital/url/{entitlement}/{asset} → audited redirect
Refund approved → listener sets revoked_at/status revoked
```

## 7. API impact
NEW (admin): `GET products/{product}/digital-assets`, `POST products/{product}/digital-assets`, `GET|PUT|DELETE digital-assets/{uuid}`, `POST digital-assets/{uuid}/replace`, `POST digital-assets/{uuid}/license-keys`.
NEW (admin W6): `GET digital-entitlements`, `GET digital-entitlements/{uuid}`, `PATCH digital-entitlements/{uuid}/limit`, `POST digital-entitlements/{uuid}/revoke`, `POST digital-entitlements/{uuid}/restore`.
NEW (customer): `GET digital/downloads`, `GET v1/general/digital/download/{entitlement}/{asset}`, `GET digital/license/{entitlement}/{asset}`, `GET digital/url/{entitlement}/{asset}`.

## 8. Dashboard impact
Aggregates surfaced inside `dashboard/products` → `digital` block (counts only; secrets/PII excluded — regression-tested).

## 9–10. Cron/Queue impact
No cron. Fulfillment/listeners respect queue policy; download endpoints are HTTP-throttled, not queued.

## 11. Permissions
NEW: `manage-digital-licenses` (license pools), `manage-digital-access` (entitlements). Both in enum, translated EN/AR, seeded. Customer endpoints: sanctum ownership checks (IDOR-safe by entitlement user_id).

## 12. Translations
Permission labels added EN+AR (`manage-digital-licenses`, `manage-digital-access`).

## 13. Database
`2026_08_23_120200 digital_assets` (+`2026_08_24_120100 extend` display_name/extension/checksum/status/external_url/expires_at/secret, path nullable), `_120300 digital_entitlements` (uuid unique, unique order_product FK, download_limit/count, revoked_at; `2026_08_24_120300 +expires_at`), `_120400 pivot`, `_120500 digital_download_logs` (hashed ip/ua, composite index), `2026_08_24_120200 digital_license_keys` (uuid, encrypted_key, status, allocation idx).

## 14. Tests
`tests/Feature/Digital/**` (151 tests green), secret-leak assertions in `DashboardClosureTest`.

---

# CART

## 1. What existed before
Cart/cart_items CRUD and checkout consumption; cart status lifecycle (`active|expired|checked_out`). Verified in fixture/schema and dashboard queries.

## 2. What changed
- **DATA MODEL CHANGE**: `carts.reminder_sent_at` added (`2026_08_13_000001`) supporting abandoned-cart notification dedup.
- **ADDED**: scheduled expiry + abandonment notifications (see Cron).
- Legacy duplicate command `cart:expire` (`ExpireAbandonedCarts`) exists but is referenced nowhere (scheduler/tests/docs) — retained intentionally, classified dead code.

## 3. Business impact
Recovery of abandoned carts without spamming (one reminder stamp).

## 4. Technical impact
Commands `ExpireCarts`, `NotifyAbandonedCarts`; notifications queued on `meem-medium`.

## 7. API impact
Cart resource endpoints UNCHANGED in contract.

## 8. Dashboard impact
`dashboard/cart` reads carts/cart_items (abandonment rate, most-added, avg value) — endpoint predates window; calculation verified current.

## 13. Database
Column addition above.

## 14. Tests
Covered within Cart suites (88-method suite tracked in production history).

---

# CHECKOUT & ORDERS

## 1. What existed before
Checkout creating orders; COD/Cashier/gateways; status enum `pending|completed|delivered|cancelled`.

## 2. What changed
- **ADDED** canonical status **`processing`** (MySQL enum altered; sqlite parity rebuild added in same migration).
- **MODIFIED**: order creation persists FX snapshot (`currency_code`, `base_currency_code`, `currency_rate`, `currency_rate_date`, `converted_total_price`, `catalog_currency_code`) and per-line `item_type`.
- **DATA MODEL CHANGE**: `orders.address` made nullable (digital/pickup orders) — `2026_08_23_130000`.
- **ADDED** order number column + invoice linkage columns (`2026_07_28_*`).
- **MODIFIED**: `PATCH orders/{id}/status` hardened (`whereNumber`), emits `OrderStatusChanged` (COD/Cashier mark-paid included).
- **ADDED** customer order-details-by-id endpoint (commit `354b3a5`).

## 3. Business impact
Realistic fulfillment lifecycle; correct money reporting across currencies; digital orders don't require addresses.

## 4. Technical impact
`OrderCreationService` (snapshot writes verified lines 79–293 region), `OrderService` transitions, `OrderStatusChanged` event → listeners/notifications; supervisor queues deliver async side effects.

## 5–6. Flow after
```text
POST checkout → Order(status pending) → pay (gateway/COD/Cashier)
 → processing → completed/delivered | cancelled
 → events: OrderCreated/OrderProcessed/OrderStatusChanged/OrderDelivered…
 → notifications (DB+broadcast+FCM) ; invoices on completion
```

## 7. API impact
MODIFIED: `POST checkout` (payload additive), `PATCH orders/{id}/status` (accepts processing), `GET orders/{id}` (customer detail NEW), admin order resources additive fields.

## 8. Dashboard impact
`order-stats`/`orders` timelines consume processing correctly (closure fix).

## 11. Permissions
`update-order-status` used by COD/Cashier mark-paid routes (verified inline middleware).

## 13. Database
Migrations listed above + `2026_07_27_081603` status columns, `2026_07_08_141643` not-null constraints.

## 14. Tests
Orders lifecycle suites (143-green set recorded in production history); closure tests pin processing counting.

---

# PAYMENTS & RECONCILIATION

## 1. What existed before
Gateway transactions table + payment success/failure events driving invoices/notifications.

## 2. What changed
- **ADDED** reconciliation engine: `payments:reconcile` command → `PaymentReconciliationJob` (meem-medium, tries=1, timeout=900) comparing transactions vs gateways; results in `payment_reconciliation_results` (mismatch_type, resolved_at).
- **ADDED (this window)** scheduler entry: hourly, withoutOverlapping.
- **DASHBOARD**: `dashboard/reconciliation` exposes checked/mismatches/resolved/last_run.

## 3. Business impact
Detects paid-but-unrecorded and recorded-but-failed payments; finance team gets an actionable mismatch queue.

## 4. Technical impact
`PaymentReconciliationCommand`, `PaymentReconciliationResult` model; dashboard service reads aggregates (no PII).

## 7. API impact
No public endpoint beyond dashboard (read-only).

## 9. Cron impact
NEW schedule (documented decision: automation clearly intended — job + results table + dashboard existed but unscheduled).

## 14. Tests
Closure test pins schedule expression `0 * * * *` + withoutOverlapping + presence of all seven expected commands.

---

# INVENTORY

## 1–2. What changed
- Physical-only semantics enforced in analytics (digital cannot decrement/appear in stock KPIs).
- `inventory_restored_at` column exists on orders (cancel restores stock once).
- Purge cron permanently deletes old soft-deleted products (media cleanup observer fires).

Previous granular behavior could not be verified from the available source.

## 7–8. API/Dashboard impact
`dashboard/low-stock`, `dashboard/products.out_of_stock|inventory_value` corrected.

## 9. Cron impact
`products:purge-old-deleted --days=30 --chunk=100` dailyAt 02:30 (pre-existing entry, verified).

---

# REFUNDS

## 1. What existed before
Legacy refund stack partially non-functional (audit ERR-001: missing migration/columns on legacy paths). Feature status remains Blocked in production dashboard for full lifecycle.

## 2. What changed
- **SECURITY HARDENING**: route authentication + show scoping + inverted admin condition fixed (closure-audit window).
- **ADDED**: refund approval revokes digital entitlements (integration commit verified).

## 3. Business impact
Money-return flow no longer leaks other users' refunds; digital access is reclaimed automatically.

## 4. Technical impact
`RefundRepository`/`RefundController` (marvel) modified; dashboard refund aggregates read `refunds` where present.

## 7. API impact
MODIFIED: `refunds` resource endpoints (auth/scoping). Full lifecycle remains BLOCKED (feature-level, outside this changelog's fixed scope).

## 14. Tests
Authorization contracts pinned in `ProductionClosureAuditRegressionTest`.

---

# COUPONS

## 1–2. What changed
- Coupon assignment subsystem hardened earlier (GraphQL bypass fix recorded).
- `coupon_usages` table feeds dashboard coupon analytics (usage counts, top coupons, revenue attributed via base-currency conversion now).

## 7–8. API impact
Coupon apply (customer) UNCHANGED contract; admin CRUD UNCHANGED; dashboard `coupons` MODIFIED (money correctness only).

## 13. Database
`coupon_usages` table present (feeds analytics; creation predates window).

---

# PROMOTIONS

## 1–2. What changed
- Ending-soon notification stamps (`promotions.ending_soon_notified_at`) + daily notify command.
- Promotion discount flow integrated in checkout totals DTO (audited previously).

## 3. Business impact
Higher conversion near promotion end; no double notifications.

## 9. Cron impact
`promotions:notify-ending-soon` daily (existing entry, pinned by scheduler test).

---

# CATEGORIES & BRANDS

## 1–2. What changed (both entities)
- **ADDED** async import (Excel) with progress signal files + cancel + error-report download.
- **ADDED** async export with status/download.
- **ADDED** category bulk-delete (chunked, cancel-aware).
- **ADDED** realtime: category import broadcasts `category.import.progress` (dual-channel legacy contract preserved); brand/product import terminals added in realtime pass.
- Brands import/export sample files shipped (`packages/marvel/resources/brands`, product-import sample).

## 3. Business impact
Catalog ops scale to thousands of rows without HTTP timeouts; admins see live progress.

## 4. Technical impact
Controllers ×6 (Import/Export ×3) + CategoryController bulk methods; Jobs ×6 on meem-high; services with SSRF-guarded image fetching (categories), rollback support.

## 7. API impact
NEW families (exact URIs):
- `POST categories/import`, `GET categories/import/sample`, `GET categories/import/{id}`, `POST categories/import/{id}/cancel`, `GET categories/import/{id}/download-errors`
- `GET categories/export`, `GET categories/export/{id}`, `GET categories/export/{id}/download`
- Brand mirror (`brands/...`)
- Product: `POST products/import`, sample/status/cancel/download-errors, `GET products/export` (SYNCHRONOUS — G3 deferral documented)
- `POST categories/bulk-delete`, `GET categories/bulk-delete/{id}`, `POST categories/bulk-delete/{id}/cancel`

## 8. Dashboard impact
None direct.

## 10. Queue impact
All seven producer jobs on meem-high (policy-proven).

## 11–12. Permissions/Translations
Category/brand/product permissions reused; message keys for started/status/cancel/errors exist EN+AR (verified keys group `message.MESSAGE.IMPORT_*`, `CATEGORY_EXPORT_*`, `BRAND_*`).

## 13. Database
Shared `imports` table (single ledger for all six op types + bulk delete ids via signal file).

## 14. Tests
165 Categories+Brands tests green; realtime contract suites (RecordingPusher harness).

---

# IMPORT/EXPORT REALTIME DELIVERY (cross-cutting)

## 1–2. What changed
- **ADDED** `App\Events\FileOperationEvent` + `App\Traits\BroadcastsFileOperationProgress` (owner resolution, safe payload whitelist, Pusher gating env/testing + shop.pusher.enabled, failure isolation, once-only terminal guard).
- **WIRED** terminals into Import{Products,Categories,Brands}Job (completed/completed_with_errors/failed/cancelled incl. failed() hooks), Export{Categories,Brands}Job, BulkDeleteCategoriesJob (chunk progress + terminal), cancel endpoints of the three import controllers.
- **FIXED** false observability: `BrandImportService` logged `brand.import.progress.dispatched` without dispatching — replaced with real dispatch (source-pinned regression test).
- **REMOVED** `GET /test-pusher` (security hole: leaked key/cluster, anonymous admin-channel trigger).
- Category import legacy wire contract untouched; terminal additive on same event name with `type`/`import_id` preserved.

Payload contract (safe fields only):
```json
{"kind":"product-import","id":123,"status":"processing","progress":65,
 "processed_rows":650,"success_rows":640,"failed_rows":10,"total_rows":1000}
```
Terminal adds `has_errors`; never includes paths/disks/secrets/raw error arrays.

## 7. API impact
See Categories/Brands sections; REMOVED: `GET /test-pusher`.

## 8. Frontend impact (external repo)
Phased migration: stop-on-event → event-primary + disconnect-only safety poll → remove loop; reconcile DB-first on mount/reconnect. Documented in `docs/architecture/realtime-file-operations.md`.

## 14. Tests
FileOperations suite 25/25 + unit contract 4/4 + security suite (channel IDOR, debug-route 404, unauthenticated auth-endpoint 401).

---

# NOTIFICATIONS & FCM

## 1–2. What changed
- **ADDED** user notifications across domains (orders, payments, coupons, promotions, flash sales, stock, reviews) — commits cb66288/0670ded.
- **ADDED** FCM device tokens: `POST device-tokens`, `DELETE device-tokens` + `device_tokens` table + `SendFcmNotificationJob` (config-driven queue defaulting meem-medium).
- **MODIFIED** broadcast pipeline: admin/user channels authorized; queued notifications land on meem-medium (BroadcastQueueAssignmentTest).
- Known pre-existing suite debt in Notifications directory (135-run baseline: 1E/4F) — unrelated to broadcasting; documented in production-status.

## 3. Business impact
Reliable multi-channel reach (database + push + FCM) with auditable queue placement.

## 7. API impact
NEW device-token pair; notification REST endpoints (admin prefix group with `view-notifications`) UNCHANGED contract.

## 13. Database
`device_tokens` (`2026_08_23_073810`).

---

# DASHBOARD & ANALYTICS (Rev 2)

## 1. What existed before
16 endpoints under `prefix('dashboard')` gated by `auth:sanctum + throttle:analytics + permission:view-analytics` (gate itself added in closure-audit window). All delegated to `DashboardService` with 300-s cache.

## 2. What changed (all verified in source)
- **FIX/MODIFIED `order-stats`**: was hardcoding `processing=0` (and other zeros); now derives counts from grouped data keyed off `Order::ORDER_STATUS_*` constants; unknown statuses pass through; legacy keys kept.
- **FIX/MODIFIED `products`**: `inventory_value` & `out_of_stock` restricted to PHYSICAL; new additive `digital` block.
- **FIX/MODIFIED `low-stock`**: PHYSICAL-only.
- **MODIFIED money aggregation everywhere**: base-safe `COALESCE(converted_total_price,total_price)`; line revenues × rate; additive `revenue_by_currency` (revenue) and `gross_by_currency` (finance).
- **UNCHANGED contracts**: endpoints/verbs/auth/cache TTL identical; all changes additive.
- **RECONCILIATION**: read-model over `payment_reconciliation_results` (fixture table added for tests).

## 3. Business impact
Trustworthy KPIs for mixed catalogs and multi-currency sales; operational mismatch visibility.

## 4. Technical impact
`DashboardService` +177/-39 lines; `HasCache` tag caching unchanged; `throttle:analytics` limiter defined in RouteServiceProvider.

## 5–6. Flow
```text
Admin UI → GET /api/v1/dashboard/* → sanctum → view-analytics → DashboardService
        → MySQL aggregates (Cache::remember 300s, tag DASHBOARD) → JSON {success,message,data}
```

## 7. API impact — detailed per modified endpoint

| Method | Endpoint | Change |
|---|---|---|
| GET | dashboard/overview | revenue SUMs converted-base; shape same |
| GET | dashboard/revenue | + `revenue_by_currency` |
| GET | dashboard/order-stats | processing counted; delivered key added; unknown statuses dynamic |
| GET | dashboard/products | inventory_value/out_of_stock physical-only; + `digital` block |
| GET | dashboard/low-stock | physical-only rows |
| GET | dashboard/sales | daily/comparison/payment/fulfillment sums converted-base |
| GET | dashboard/orders | timeline SUMs converted-base |
| GET | dashboard/categories | line revenue × rate |
| GET | dashboard/coupons | revenue converted-base |
| GET | dashboard/finance | gross/net/shipping converted; + `gross_by_currency` |
| GET | dashboard/overview,recent-orders,top-products,category-stats,cart,reconciliation | UNCHANGED behavior |

## 11. Permissions
`view-analytics` enforced (negative tests: customer denied).

## 14. Tests
33/33 legacy + 13/13 closure (95 assertions) + audit 15/15.

---

# MULTI-CURRENCY

## 1. What existed before
Single-currency totals; FX infrastructure introduced within window (commits 1263bc0…a5d2523): currencies, rates, user preferences, order snapshots.

## 2. What changed
- Tables `currencies`, `currency_rates`, `user_preferences`; order columns above; order_products snapshot columns.
- Admin CRUD `currencies`, `currency-rates` + privileged `set-base-currency`, `set-catalog-currency`.
- Customer: `GET currencies`, `POST currencies/select` (persisted preference).
- Analytics conversion rules (this closure): never raw-SUM cross-currency.

## 3. Business impact
International selling with correct reporting; explicit per-currency transparency.

## 7. API impact
NEW endpoints above (permissions: currency set, seeded).

## 14. Tests
Currency suites (17/17 recorded) + closure multi-currency mixing proof (EGP1000+USD100@50 ⇒ gross 6000, not 1100).

---

# REVIEWS / SITE REVIEWS / STATIC PAGES / CONTENT

## Reviews (product)
- Permission gating added for create/update/approve (seeder-backed); REST `POST products/{id}/reviews`, `PUT products/reviews/{id}` MODIFIED (403 semantics for ungated users); admin `reviews` apiResource unchanged.

## Site Reviews
- NEW CRUD + storefront `GET site-reviews` + customer `POST site-reviews`; `site_reviews` table; permission set seeded.

## Static/Content pages
- NEW `static_pages`/`static_sections` + admin apiResources `content-pages`, `sections`, `section-types` (+settings subresource) and public reads (`static-pages`, `content-pages`).

---

# DEVICE TOKENS (covered under Notifications)

---

# QUEUE (cross-cutting)

## 1–2. What changed
- Formalized policy + enforcement tests; supervisor confs finalized: high `--tries=5 --timeout=1200 --sleep=1 --stopwaitsecs=1230`; medium `--tries=3 --timeout=900 --stopwaitsecs=960` consuming `meem-medium,default`; database connection `retry_after=1560`.
- Broadcast events: queued variants pinned to meem-medium; file-operation events use ShouldBroadcastNow (never queued) — asserted.

## 3. Business impact
Predictable latency for customer-critical work; isolation for heavy ops; no duplicate execution windows (retry_after > highest job timeout).

## 14. Tests
QueueStandardizationStaticTest 134; WorkerConfigPolicyTest 4; runtime routing suites (QueueRoutingRuntimeTest).

---

# CRON / SCHEDULER (complete current registry — Kernel::schedule)

| Schedule | Command | Purpose | Added/Modified |
|---|---|---|---|
| everyFiveMinutes | orders:cancel-unpaid | Cancel unpaid orders (promotions NOT decremented — never paid) | pre-existing, pinned |
| everyFiveMinutes | carts:expire | Expire stale carts | pre-existing, pinned |
| hourly | cart:notify-abandoned | Abandoned-cart reminders (dedup via reminder_sent_at) | pre-existing, pinned |
| daily | promotions:notify-ending-soon | Ending-soon pushes (dedup stamp) | pre-existing, pinned |
| daily | flash-sales:notify-ending-soon | Same for flash sales | pre-existing, pinned |
| dailyAt 02:30 | products:purge-old-deleted --days=30 --chunk=100 | Hard-delete old soft-deleted products + media cleanup | pre-existing, pinned |
| hourly | payments:reconcile | Dispatch reconciliation job (idempotent, meem-medium) | **NEW this window** |

Unscheduled commands present (documented): `cart:expire` (ExpireAbandonedCarts — dead, zero references), `api:cache-clear` (utility). `routes/console.php` contains scaffold only. Server crontab (`schedule:run` every minute) is a deployment requirement not verifiable from repo.

---

# PERMISSIONS (delta summary)

Added & seeded (enum → seeder → translations all synced, verified):
`view-analytics`; `manage-digital-licenses`; `manage-digital-access`;
`create-currency`,`update-currency`,`delete-currency`,`set-base-currency`,`set-catalog-currency`;
review moderation set (`create-review`,`update-review`,`approve-reviews`,`delete-reviews`).
Removed: none. Bypasses: none (SUPER_ADMIN literal accepted alongside slug permissions by design).

---

# TRANSLATIONS (delta summary)

- Added groups/keys: DASHBOARD.* (18 EN / 17 AR comment-line diff only — complete), digital permission labels, import/export message keys (`MESSAGE.IMPORT_*`, `CATEGORY_EXPORT_*`, `BRAND_*`), notification refactors (commit 236abda), OTP key repair (closure audit).
- Verified no missing AR counterpart for dashboard block; hardcoded-string sweep performed in closure audit (13 fixes recorded historically).
- German (de) checkout/common/message/order/sms files removed in working tree (deletion recorded in git status) — de locale content for those files no longer present; fallback chain applies.

---

# DATABASE (migration map → feature)

| Migration | Feature |
|---|---|
| 2026_06_27 imports | async ops ledger |
| 2026_07_08 fulfillment/payment cols; not-null constraints | orders hardening |
| 2026_07_11 governorate / pickup snapshot | shipping |
| 2026_07_12 payment_reconciliation_results | reconciliation |
| 2026_07_14 inventory_restored_at | inventory restore-on-cancel |
| 2026_07_27 order status columns | lifecycle |
| 2026_07_28 invoice lifecycle + order_number; drop unique invoice order id | invoices |
| 2026_08_03 providers, social_login_codes | social login |
| 2026_08_10 site_reviews | reviews |
| 2026_08_10 currencies/rates/order currency cols | multi-currency |
| 2026_08_11 catalog col (+user_preferences, order_products snapshots) | multi-currency |
| 2026_08_13 carts.reminder_sent_at; promotions/flash ending stamps | lifecycle crons |
| 2026_08_18 static_pages/static_sections | CMS pages |
| 2026_08_19 orders.status + processing | order lifecycle (sqlite parity rebuilt) |
| 2026_08_23 device_tokens; products.item_type(+shrink); order_products.item_type; digital_assets/entitlements/pivot/download_logs; orders.address nullable | digital + tokens |
| 2026_08_24 digital_assets extend; license_keys; entitlements.expires_at | digital licenses |

---

# EVENTS / JOBS / LISTENERS (delta summary)

- NEW events: `App\Events\FileOperationEvent`, `App\Events\CategoryImportProgress` (window start), `OrderStatusChanged` emission coverage for COD/Cashier, review approve/reject events, flash-sale processed, store-notice broadcast.
- NEW jobs: `PurgeOldSoftDeletedProducts` (command-driven), `PaymentReconciliationJob` (now scheduled), `GenerateInvoicePdfJob`, `LogActivityJob`, `SendFcmNotificationJob`, `SendFrontendWebhookJob`, `SendPasswordResetEmailJob`, import/export/bulk-delete jobs ×7 (one dormant: ExportProductsJob — G3 deferred).
- Listeners: digital fulfillment/revocation wiring; delivered-notification listener signature alignment recorded as known debt (baseline failure, unchanged).

---

# 4. COMPLETE API CHANGELOG (change-focused master list)

Legend: status reflects THIS documentation window. Resource-controller CRUD blocks whose contract is unchanged are summarized after the table (not omitted — grouped for readability).

| Method | Endpoint | Status | Purpose | Controller | Permission |
|---|---|---|---|---|---|
| GET | /test-pusher | REMOVED | debug broadcaster | closure | none (was none — security hole) |
| POST | products/import | NEW | upload Excel → async import | ProductImportController | create-product |
| GET | products/import/sample | NEW | template download | ProductImportController | create-product |
| GET | products/import/{id} | NEW | status/reconciliation | ProductImportController | create-product |
| POST | products/import/{id}/cancel | NEW | cooperative cancel | ProductImportController | create-product |
| GET | products/import/{id}/download-errors | NEW | failed-rows report | ProductImportController | create-product |
| GET | products/export | UNCHANGED* | sync XLSX download (G3 deferred) | ProductExportController | view-products |
| POST | categories/import | NEW | async import + realtime | CategoryImportController | import perms |
| GET | categories/import/sample | NEW | template | CategoryImportController | import perms |
| GET | categories/import/{id} | NEW | status | CategoryImportController | import perms |
| POST | categories/import/{id}/cancel | NEW | cancel | CategoryImportController | import perms |
| GET | categories/import/{id}/download-errors | NEW | error report | CategoryImportController | import perms |
| GET | categories/export | NEW | start async export | CategoryExportController | export-category |
| GET | categories/export/{id} | NEW | status | CategoryExportController | export-category |
| GET | categories/export/{id}/download | NEW | fetch artifact | CategoryExportController | export-category |
| POST | brands/import … (5) | NEW | brand import family | BrandImportController | brand perms |
| GET | brands/export (+/{id},/download) | NEW | brand export family | BrandExportController | export-brand |
| POST | categories/bulk-delete | NEW | chunked soft-delete | CategoryController | delete-category |
| GET | categories/bulk-delete/{id} | NEW | progress | CategoryController | delete-category |
| POST | categories/bulk-delete/{id}/cancel | NEW | cancel signal | CategoryController | delete-category |
| GET | products/{product}/digital-assets | NEW | list assets | DigitalAssetController | product mgmt |
| POST | products/{product}/digital-assets | NEW | upload asset | DigitalAssetController | product mgmt |
| GET/PUT/DELETE | digital-assets/{uuid} | NEW | asset CRUD | DigitalAssetController | product mgmt |
| POST | digital-assets/{uuid}/replace | NEW | swap file | DigitalAssetController | product mgmt |
| POST | digital-assets/{uuid}/license-keys | NEW | provision pool | DigitalAssetController | manage-digital-licenses |
| GET | digital-entitlements | NEW | list | DigitalEntitlementController | manage-digital-access |
| GET | digital-entitlements/{uuid} | NEW | detail | DigitalEntitlementController | manage-digital-access |
| PATCH | digital-entitlements/{uuid}/limit | NEW | set limit | DigitalEntitlementController | manage-digital-access |
| POST | digital-entitlements/{uuid}/revoke | NEW | revoke | DigitalEntitlementController | manage-digital-access |
| POST | digital-entitlements/{uuid}/restore | NEW | restore | DigitalEntitlementController | manage-digital-access |
| GET | digital/downloads | NEW | my library | DigitalDownloadController | sanctum owner |
| GET | v1/general/digital/download/{entitlement}/{asset} | NEW | signed download | DigitalDownloadController | sanctum owner |
| GET | digital/license/{entitlement}/{asset} | NEW | reveal key | DigitalDownloadController | sanctum owner |
| GET | digital/url/{entitlement}/{asset} | NEW | audited redirect | DigitalDownloadController | sanctum owner |
| GET | currencies | NEW | list enabled | CurrencyController | public |
| POST | currencies/select | NEW | set preference | CurrencyController | sanctum |
| currencies/currency-rates apiResource | NEW | admin FX CRUD | CurrencyRateController | currency perms |
| POST | currencies set-base/set-catalog | NEW | privileged config | CurrencyController | set-*-currency |
| GET/POST | site-reviews (+admin CRUD) | NEW | store ratings | SiteReviewController | view/public + admin set |
| GET/POST | static-pages, content-pages (public+admin) | NEW | CMS pages | StaticPage/ContentPage/Section controllers | page perms |
| POST/DELETE | device-tokens | NEW | FCM registration | DeviceTokenController | sanctum |
| GET | my-invoices | NEW | lightweight list | InvoiceController | sanctum |
| GET | verify/{uuid} | NEW(+throttle 5,1) | QR verification payload | InvoiceController | public |
| GET | view/{uuid}, {uuid}/download | NEW(+throttle) | signed view/download | Api\InvoiceController | signed/public per config |
| GET | orders/{orderId}/invoice | NEW | invoice by order | OrderController(auth) | sanctum |
| PATCH | orders/{id}/status | MODIFIED | accepts processing; whereNumber | OrderController | update-order-status |
| POST | checkout/cod/{orderId}/mark-paid | MODIFIED | emits OrderStatusChanged | OrderController | update-order-status |
| POST | checkout/cashier/{orderId}/mark-paid | MODIFIED | same | OrderController | update-order-status |
| POST | products/{id}/reviews | MODIFIED | permission-gated | ProductController | review perms |
| PUT | products/reviews/{id} | MODIFIED | permission-gated | ProductController | review perms |
| refunds apiResource | MODIFIED | auth + scoping fixes | RefundController | refund perms |
| POST | checkout | MODIFIED(additive) | item_type + FX snapshot lines | OrderController | sanctum |
| GET | settings (front/admin) | MODIFIED(additive) | pusher/currency options | SettingController | varies |
| GET | dashboard/* (16) | 10 MODIFIED / 6 UNCHANGED | analytics | DashboardController | view-analytics |

\* `GET products/export` behavior intentionally unchanged this window (synchronous download preserved; async conversion = deferred decision G3).

**Grouped UNCHANGED resource/verb blocks** (contract-stable, verified present): address, contacts, sliders, banners, countries, governorates, cities, shipping-prices, faqs, wishlists(index/store), promotions, tags, attributes, pickup-locations(+default-branch enhancement), flash-sale, users, roles/permissions management set, admin notifications prefix group, social login quartet, home/nav-data/catalog public reads, fast-shipping settings/status/checkout, OTP endpoints (disabled group), password reset trio, auth token/login/logout/me, sections/section-types.

---

# 5. REQUEST CHANGES

Only endpoints whose REQUEST contract changed are listed; everything else retains its original request shape (verified against FormRequests/controllers).

## PATCH /api/v1/orders/{id}/status
- Path param constrained numeric (`whereNumber`) — non-numeric now 404 instead of hitting controller.
- Body `status` accepts new value `processing`.
### Request Before
```json
{ "status": "pending|completed|delivered|cancelled" }
```
### Request After
```json
{ "status": "pending|processing|completed|delivered|cancelled" }
```

## POST /api/v1/products/import (and categories/brands mirrors)
- multipart/form-data: `file` (.xlsx/.xls/.ods ≤ size cap), optional `images_source` (category/product where applicable).
- Response 202 `{import_id,status:"pending"}` (unchanged since introduction — NEW family).

## POST /api/v1/categories/bulk-delete
- JSON `{ "ids": number[] }` (unique-filtered server-side); ids also mirrored to signal file for the job.

## POST /api/v1/checkout
- Additive per-line/none: server derives `item_type` + FX snapshot; clients MAY omit; no breaking requirement introduced. Address now OPTIONAL when order resolves to digital/pickup fulfillment.

## POST /api/v1/products/{product}/digital-assets  (multipart)
- `file` (mime/size validated per type registry) OR url-type fields (`external_url`) depending on `type`; `display_name`, `sort_order` optional.

## POST /api/v1/digital-assets/{uuid}/license-keys
- JSON array of plaintext keys (server-side encryption at rest); bulk provisioned.

## PATCH /api/v1/digital-entitlements/{uuid}/limit
- `{ "download_limit": int≥0 }`.

## POST /api/v1/currencies/select
- `{ "code": "USD" }` (must be an enabled currency).

## GET /api/v1/dashboard/*
- No request-body changes; query params unchanged (`limit` clamped ≤50 on recent-orders/top-products/low-stock).

---

# 6. RESPONSE CHANGES

- `dashboard/revenue` → + `revenue_by_currency: {CODE: amount}`
- `dashboard/finance` → + `gross_by_currency`
- `dashboard/products` → + `digital: {digital_products,digital_units_sold,entitlements{active,revoked,expired},downloads{total,last_30_days},licenses{available?,assigned?,consumed?,revoked?}}`; `inventory_value` now physical-only; `out_of_stock` physical-only
- `dashboard/low-stock` → digital rows removed
- `dashboard/order-stats` → `processing` real counts; `delivered` key added; legacy keys intact; unknown statuses appear when present
- `dashboard/sales|orders|categories|coupons` → monetary values now base-denominated (same field names)
- `checkout`/order payloads → + item_type, currency snapshot fields (additive)
- invoice payloads → + signed `view_url`/`download_url` (registered routes)
- import/export starts → 202 envelope `{import_id|export_id,status}` (as introduced)
- Removed: `/test-pusher` JSON body (key/cluster leak) — endpoint gone

---

# 7. FINAL ARCHITECTURE / END-TO-END FLOWS

ADMIN OPERATIONS
```text
ADMIN → /api/v1 dashboard|CRUD (sanctum·permission) → Controller → Service → Model/Scope → MySQL
File ops: POST import/export → imports row → Job(meem-high) → Worker
   ├─ progress: signal file + imports table + FileOperationEvent → private-users.{id}
   └─ terminal: DB update THEN broadcast (once) → UI reconcile via GET status
```

CUSTOMER PURCHASE
```text
browse → cart → POST checkout → Order(pending, FX snapshot, item_type lines)
 → payment (COD/Cashier/gateway) → processing → completed/delivered
 ├─ PHYSICAL: stock decrement → shipment → delivered
 └─ DIGITAL : entitlements(delivered) → downloads/license/url (limits, logs, revoke-on-refund)
 notifications: DB + broadcast(private-users.{id}) + FCM(device_tokens)
```

CRON
```text
schedule:run (every minute, host crontab — deployment prerequisite)
 → 7 registered commands (§Cron table)
 → Service/Job → DB mutations/events → meem-medium|meem-high workers → notifications
```

---

## Verification statement

Every endpoint, class, migration, schedule entry, permission slug, translation group and queue flag cited above was located in the current source tree during this pass. Historical "before" states are cited only where git commits or the maintained production-history ledger prove them; otherwise the disclaimer *"Previous behavior could not be verified from the available source"* applies.
