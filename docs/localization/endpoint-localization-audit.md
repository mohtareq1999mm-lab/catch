# Endpoint Localization Audit — API (general + Marvel admin)

Scope: `routes/api.php` (app) + `packages/marvel/src/Rest/Routes.php` (Marvel kernel, read-only).
Locales: `ar` (default, `config/app.php` `locale=ar`) + `en` (fallback). Locale selected via
`lang` request header by `CheckLangMiddleware` (also in global `api` group). No frontend in repo.

Status legend: PENDING / IN_PROGRESS / FIXED / VERIFIED / BLOCKED.
Finding a test file ≠ tested. Inspected / Modified / Tested / Verified tracked per group.

## Final statuses (2026-10-12)

- D1: FIXED + VERIFIED (TranslationCatalogueTest legacy-English test).
- D2: FIXED + VERIFIED (parity test covers all 14 files × 2 locales).
- D3: FIXED + VERIFIED (shipment-keys survival test + duplicate scan test).
- D4: FIXED + VERIFIED (literal scans return 0; endpoint tests assert no raw keys).
- D5: FIXED + VERIFIED (HandlerLocalizationTest, 19 tests).
- D6: FIXED + VERIFIED (LocaleMiddlewareTest incl. leak + Carbon sync + case-insensitivity).
- D7: FIXED + VERIFIED (TrackingControllerIntegrityTest + OrderTrackingTest 11/15;
  4 admin 403s are pre-existing permission-setup failures, proven unrelated).
- D8: FIXED + VERIFIED (parity script + catalogue test: 0 diffs).
- D9: FIXED + VERIFIED (attributes added; endpoint tests assert localized names).

Groups G1–G8: all FIXED. VERIFIED for G1–G6 via EndpointLocalizationTest (16 tests,
real MySQL HTTP, both locales) + catalogue/middleware/handler/integrity suites
(57 tests). G7 WMS VERIFIED via StockOperationsTest (25) + literal/type scans;
ReturnService + distribution queue internals + provider payloads documented as
internal-only (see §Out of scope). G8 Marvel kernel: no code changes (read-only);
locale coverage via shared catalogue tests.

## Independent review follow-up (REJECT → addressed)

- Batch assign contention now throws WmsConflictException; controller maps
  conflict→409, other Runtime→422 (matches its documented intent).
- Cancellation reasons render via tracking.cancellation_reasons.* with unknown fallback.
- NotFoundHttpException('Resource Not Found') now maps to the catalogue.
- getStatusLabel unknown-status default returns the stable code (no English prose).
- lang header normalized (trim + lowercase).
- User-input reflection capped (barcode :code, shipment :field).
- Duplicate-key test extended to every nesting level × all files.
- Machine-code test replaced with real placeholder-stability assertions.
- Explicitly declined (documented): persisting translated coupon-block messages
  (pre-existing; changing stored shape is a bigger migration), translating
  ReturnService/queue/provider internals (unreachable via supplied endpoints),
  touching docs/ (API Documentation Safety Mode), deleting the dead
  'method ' validation key (retention rule).
- Known micro-contract deltas (justified, documented): UNAUTHENTICATED trailing
  period unified; tracking delivery 'delivered' gains a period; estimated delivery
  uses locale-neutral Y-m-d + translated sentence (replaces English-only
  diffForHumans); validation top message is always the localized generic sentence
  (replaces first-error echo + untranslatable "(and N more errors)" suffix);
  estimated_date_formatted 'l, F j, Y' → 'Y-m-d'.

## Baseline defects (all groups)

- D1: `resources/lang/ar/auth.php`, `ar/passwords.php`, `ar/pagination.php` contain ENGLISH text (untranslated).
- D2: Missing files: `en/auth.php`, `en/passwords.php`, `en/pagination.php`, `ar/common.php`, `ar/sms.php`.
- D3: `tracking.php` (ar+en) defines `shipment` key TWICE — first block (`created`, `failed`, …) is
  overwritten; `tracking.shipment.created*` / `tracking.shipment.failed*` resolve to raw keys at runtime.
- D4: Hardcoded English literals passed to `apiResponse()` / `response()->json()` in app controllers
  bypass `translateNotice()` → always English, both locales. (Full list in §Groups.)
- D5: `Handler::handleJsonResponse()` returns hardcoded English for generic HTTP errors
  (`Internal Server Error`, `Resource Not Found`, `Unauthenticated`, …) + raw `$exception->getMessage()`
  passthroughs (ValidationException top message, HttpException, Marvel fallback, Spatie parse fallbacks).
- D6: `CheckLangMiddleware`: unsupported `lang` value leaves locale untouched → locale can leak between
  requests on long-running workers; missing header defaults to `en` while app default is `ar` (undocumented).
- D7: `OrderTrackingController` calls undefined `getStatusLabel/Description/Icon/Color`,
  `getProgressPercentage` → success-path tracking responses fatal (500). Must implement using
  existing `tracking.*` keys before any tracking localization can be verified.
- D8: `message.php` parity: 12 keys en-only (`MESSAGE.USER_*`, `MESSAGE.WRONG_CSV`,
  `MESSAGE.NOT_AUTHORIZED`, `MESSAGE.SOMETHING_WENT_WRONG`, `QUANTITY_*`, `ERROR.REQUIRED_INFO_MISSING`,
  `welcome`) and 5 legacy ar-only keys. Under `ar`, requests for the en-only keys return raw keys.
- D9: Validation `attributes` lack checkout/tracking/device/notification/flow-admin field names
  (`order_number`, `token`, `client`, `platform`, `code`, dozens of flow/wms fields); nested
  `items.*.product_id`-style attributes missing → raw paths in messages.

## Groups

### G1 public catalog (no auth) — routes/api.php `v1/general` public
GET nav-data, categories, categories/{slug}, brands, brands/{slug}, brands-products, banners,
banners/{slug}, sliders, sliders/{slug}, tags, tags/{slug}, promotions, promotions/{slug}, coupons,
content-pages(+{slug}), static-pages(+{slug}), products, products/{slug}, flash-sales(+{slug}),
flash-sale-products*, settings, faqs, governorates(+{id}), countries(+{id}), cities(+{id}),
pickup-locations(+{id}), fast-shipping/status, site-reviews, currencies, payment-gateways,
order-flows/available, order-flows/by-shipping-type/{type}
Controllers: Home/Category/Brand/Banner/Slider/Tag/Promotion/Coupon/ContentPage/StaticPage/Product/
FlashSale/Setting/FAQ/Governorate/Country/City/PickupLocation/FastShipping/SiteReview/Currency/
PaymentGateway/FlowDefinition. Success via `FETCH_DATA_SUCCESSFULLY` constant → `translateNotice`
(covered once D8 fixed). Validation: query params mostly unvalidated/free-form. Status: PENDING.

### G2 currencies select + checkout callbacks/webhooks + public tracking — routes/api.php public
POST currencies/select (CurrencyController@select — validation: code/currency? inspect);
MATCH checkout/callback, checkout/error-callback; POST checkout/webhooks/stripe|paypal
(PaymentWebhookController — provider-signed, machine-to-machine; constants PAYMENT_* — no change);
POST track-order (OrderTrackingController@trackByOrderNumber — D4 literals + D7 fatal + D9
`order_number` attribute). Status: PENDING.

### G3 coupons (auth) — routes/api.php
GET coupons/mine|available, POST coupons/apply (D9 `code` attr; INVALID_COUPON… constant OK),
POST coupons/{id}/claim (ClaimCouponRequest — inspect messages/attributes). CouponController@index
has hardcoded `'Unauthenticated.'` (D4). Status: PENDING.

### G4 checkout + orders (auth) — routes/api.php
GET checkout/promotions, POST checkout (OrderCreateRequest — inspect rules/messages/attributes;
catch blocks pass `$e->getMessage()` — origins already `__()`-translated, verified, passthrough kept),
POST checkout/cod|/cashier/{orderId}/mark-paid, POST fast-shipping/checkout (constants + `__()` OK),
GET orders, GET orders/{orderId}/invoice, GET orders/{id}, POST orders/{id}/cancel (`__()` OK).
Status: PENDING.

### G5 tracking (auth) + digital + reviews + devices + site reviews + invoices — routes/api.php
GET my-orders, GET orders/{orderId}/track (D4+D7), digital/downloads|license|url|download
(constants OK), POST products/{id}/reviews + PUT products/reviews/{id} (inspect request msgs),
device-tokens store/destroy (D9 `token/client/platform` attrs; DEVICE_TOKEN_* constants OK),
POST site-reviews (inspect request msgs), invoices my-invoices|verify|view|download (constants OK).
Status: PENDING.

### G6 user notifications — routes/api.php `v1/user`
index|update|registerDevice|unregisterDevice|notificationHistory — 6 hardcoded literals (D4),
D9 attrs (`notification_language`, `token`, `platform`, …). Status: PENDING.

### G7 admin tracking/analytics/shipments/refunds/gateways/flows/wms — routes/api.php
AdminOrderTracking (5 literals D4 + abort() English → D5), AnalyticsController (6 literals D4 +
abort() English → D5), AdminShipmentController (3 literals D4, `response()->json` style),
Admin RefundController (`__()` OK), PaymentGatewaySettings (3 literals D4),
OrderStatusCatalog (3 literals D4), OrderFlowController (5 literals D4 + `$e->getMessage()`
passthroughs of already-translated service messages — kept), FlowInputController (4 literals D4,
indexed `"inputs.{$i}: "` prefix kept as locator), FlowDefinition (2 literals D4 + translated
passthrough — kept), WMS Batch/Fulfillment/Location/Warehouse (+Packing/Package/Picking/Shipment/
Inventory/OrderCancellation — audit literals), CouponConfiguration (validateConfiguration +
usage-info + suggest-fix guidance — D4; long-form admin guidance localized via keys, en verbatim),
CouponDistribution/Targeting/Rules (constants/`__()` OK), AnalyticsExport (inspect). Status: PENDING.

### G8 Marvel kernel — packages/marvel/src/Rest/Routes.php (READ-ONLY)
Auth (/register /token /admin-login /social* /logout /me /forget-password /verify-forget-password-token
/reset-password /send-otp-code /otp-login /change-password /update-contact, address resource),
contacts, settings, activity-log, fast-shipping/settings, admin-users*, brands(+import/export),
sliders, categories(+import/export/bulk-delete), pickup-locations, attributes, orders(+status batch),
banner, countries, governorates, cities, shipping-prices, reviews, site-reviews, currencies,
currency-rates, products(+import/export/bulk), digital-assets(+license-keys), digital-entitlements,
flash-sale, faqs, coupons(+assignments/rules/config/targeting/distribute), wishlists, promotions,
tags, roles/permissions, content-pages/sections/section-types, static-pages, users, product-type,
cart, dashboard/*, admin+user notifications, refunds, shipments, invoices, analytics/export,
payment-gateways, order-statuses/flows/inputs. All inspected Marvel controllers use `__()` or
translation-key constants → localized once D1/D2/D8 fixed. No Marvel code changes. Status: PENDING.

## Verification plan
1. `php loc_parity.php` → 0 missing/untranslated/empty/key-copy values for keys reachable above.
2. New `tests/Feature/Localization/*`: locale-header switching, attribute localization, placeholder
   interpolation, no-raw-key scan of responses, translation-file parity, middleware fallback,
   handler generic-error localization. DB-backed endpoint tests included for CI (MySQL); DB-free
   subset runs in this environment (no MySQL here, migrations are MySQL-specific — BLOCKED locally).
3. `php -l` on every edited file + git diff review (preserve unrelated working-tree modifications).
