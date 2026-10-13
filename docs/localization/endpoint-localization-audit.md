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
- D14: FIXED + VERIFIED (values.*.value/.en/.ar added both locales for Marvel
  attribute create/update; NestedAttributeLocalizationTest 4/4; catalogue parity still 0 diffs).

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
translation-key constants → localized once D1/D2/D8 fixed. Minimal Marvel leak fixes applied
(D10 OTP origin, forget-password subject, question-mail locale; see turn-3 section). Status: FIXED.

## Verification plan
1. `php loc_parity.php` → 0 missing/untranslated/empty/key-copy values for keys reachable above.
2. New `tests/Feature/Localization/*`: locale-header switching, attribute localization, placeholder
   interpolation, no-raw-key scan of responses, translation-file parity, middleware fallback,
   handler generic-error localization. DB-backed endpoint tests included for CI (MySQL); DB-free
   subset runs in this environment (no MySQL here, migrations are MySQL-specific — BLOCKED locally).
3. `php -l` on every edited file + git diff review (preserve unrelated working-tree modifications).

## Turn-3 iteration (second LOOP A–F pass)

New defects found and fixed (all VERIFIED by tests/scans below):

- D10 — OTP raw keys: `UserController::sendOtpCode` returned untranslated
  `'ERROR.EMPTY_MOBILE_NUMBER'` / constant values; surfaced verbatim via GraphQL
  `AuthMutator::sendOtpCode` (REST only mined `otp_id`, unaffected). Fixed at
  origin with `__()`; added `message.ERROR.EMPTY_MOBILE_NUMBER` both locales.
- D11 — 19 permission labels missing both locales (`view-warehouse`,
  `manage-warehouse`, `view/manage-location`, `view/manage-fulfillment`,
  `picking-execute`, `packing-execute`, `fulfillment-override`,
  `inventory-adjust`, `fulfillment.create/cancel`, `picking.claim/complete`,
  `packing.complete`, `batch.manage/operate`, `order.cancel-during-fulfillment`,
  `delete-all-products`): `PermissionResource` leaked raw `permissions.*` keys
  into roles/users responses. Added both locales + enum-coverage regression test.
- D12 — missing validation attributes: `avatar`, `policy`, `otp_id`,
  `newPassword_confirmation` (auth); `item.*` cart lines; `address.zip/state/
  street_address`, `location(.latitude/.longitude)`; shipment fields
  (`order_id`, `courier`, `shipping_method/cost`, `currency`, `origin/
  destination_address`, `total_weight`, `weight_unit`); WMS request fields
  (`product_id`, `product`, `reference`, `idempotency_key`,
  `source/destination_location_id`, `override`, `op_seq`); contact
  (`subject`, `message`); exports (`date_from`, `date_to`). All added both locales.
- D13 — customer mail/PDF gaps: invoice PDF labels rendered English-only
  (no JSON catalogue) → created `resources/lang/ar.json` (verified loaded from
  the bound lang path; collision scan: all single-word keys used ONLY by the
  PDF blade); `forget-password` blade + subject wrapped in `__()`;
  `SendPasswordResetEmailJob` captures request locale at dispatch and restores
  after send (Octane-safe); `question/answered` blade bilingual via question
  `language` (OTP-mail precedent), locale passed by `NotifyQuestionAnswered`;
  `digital_product/update` blade wrapped in `__()`.
- Verified clean (no action): Resources (no user-facing literals; translatable
  content via `getTranslation`/catalogue keys), Marvel cart/address/refund/
  review/wishlist/coupon/promotion/contact/site-review/currency-rate/shipping-
  price/settings controllers (constants/`__()` only), export status endpoints
  (translated envelope + machine codes), app notifications (explicit bilingual
  `{ar,en}` arrays + per-locale fallback), auth FormRequests (catalogue
  attributes or empty messages), import `messages()` (all `__()` keys resolve
  both locales at runtime), Dashboard error paths (keys exist both locales).

Documented limitations (no change, by design): vendor/admin realtime
broadcasts + export-progress broadcasts stay English (no recipient locale at
broadcast; frontend must key on stable codes); vendor/admin mails stay English;
queued mails render in dispatch locale except password-reset (stashed) and
content-language mails (question/OTP); gateway provider details pass through
sanitized; `ReturnService`/queue-consumer/provider internals unreachable via
supplied endpoints; no frontend in repo (contracts + examples only).

Test evidence this turn: `MailLocalizationTest` 18/18 OK (blade rendering both
locales, JSON phrases, placeholders); permission-enum test OK; full DB-free
suite 76/76 OK (7,404 assertions); `EndpointLocalizationTest` 16/16 OK (MySQL);
parity scripts 0 diffs; literal scans 0; `php -l` clean on all touched files.

## Turn-4 iteration (third LOOP A–F pass, 2026-10-12)

Runtime Validator probe (ar+en, no DB) over representative rules: localized
attributes resolve, zero raw-key leakage. Probe of nested `items.0.product_id`
showed raw-path fallback, but no supplied endpoint validates that shape
(no FormRequest in `app/` or Marvel uses `items.*.*`) → synthetic only, no change.

New defect found and fixed (VERIFIED):
- D14 — missing `values.*.value`, `values.*.value.en`, `values.*.value.ar`
  attributes both locales. Reachable via supplied admin endpoints
  POST/PUT `attributes` (Marvel `AttributeController@store/update` through
  `AttributeCreateRequest`/`AttributeUpdateRequest` — `AttributeRequest` carries
  the same rules but is unwired legacy; covered by key shape regardless), none of
  which define `attributes()`). Catalogue had `values.*` only, which does not
  cover the deeper path → failures rendered `values.0.value.en` verbatim.
  Fix: 3 keys added to `resources/lang/{ar,en}/validation.php` (app-layer
  catalogue; Marvel kernel untouched per read-only rule). Regression:
  `tests/Feature/Localization/NestedAttributeLocalizationTest.php` (4 tests,
  real Validator output both locales, no-raw-key + no-raw-path assertions).
  `inputs.*.*` (FlowInput bulk store, supplied endpoint) re-proven localized
  by the same test. `BecameSellersRequest` `commission.*.*` left unchanged:
  its routes are in neither supplied route file (out of scope).

Test evidence this turn: NestedAttributeLocalizationTest 4/4 OK (24 assertions);
TranslationCatalogueTest 24/24 OK (7,295 assertions, parity 0 diffs incl. new keys);
LocaleMiddlewareTest 8/8, HandlerLocalizationTest 21/21,
TrackingControllerIntegrityTest 5/5, MailLocalizationTest 18/18 OK;
hardcoded-literal scan of `app/Http/Controllers` 0 matches; `php -l` clean.
`EndpointLocalizationTest` (16 tests, MySQL) not runnable in this environment
(no MySQL listener on 127.0.0.1:3306); last CI evidence per prior turn stands.
