# STRIPE + PAYPAL — FINAL END-TO-END INTEGRATION VERIFICATION REPORT

Date (UTC): 2026-09-27. Environment: `D:\work\meem`, Laravel 10.30.1.
Policy: verification-first. No code was modified. No secret is printed anywhere in this report (presence/length only).

## 1. Executive Summary

- The existing Stripe and PayPal integrations are **correctly designed, correctly wired, and fully proven at source + automated-test level** (mocked provider seam): 149/149 payment tests pass (1050 assertions), covering enable/disable gating, catalog-currency authority, 3-decimal precision, fail-closed completion, idempotency, webhooks, and concurrency.
- **Real sandbox E2E is BLOCKED for both providers**, not failed: the canonical credentials the live code actually reads are absent from this environment (`STRIPE_SECRET_KEY`, `PAYPAL_CLIENT_ID`, `PAYPAL_CLIENT_SECRET` all EMPTY; both gateways `enabled=false`, `isConfigured()=NO`). The application therefore fail-closes at `canInitiate()` before any provider call. No real provider object, payment, callback, or order completion could be executed here.
- A `.env` legacy `STRIPE_API_KEY` value exists but is **dead configuration**: no live route, controller, service, or gateway in the checkout path reads it (only `packages/marvel/config/shop.php` + omnipay config, which have no registered routes). Same for all legacy `PAYPAL_*` keys (all EMPTY anyway).
- Final verdict: **STRIPE: BLOCKED — EXTERNAL REQUIREMENT MISSING. PAYPAL: BLOCKED — EXTERNAL REQUIREMENT MISSING.**

## 2. Environment

- Repo root: `D:\work\meem` (git repo, Laravel 10.30.1 via `php artisan --version`).
- `.env` files present: `.env` (4716 bytes), `.env.example` (10536 bytes).
- Runtime config read via a bootstrapped Laravel script reporting presence only:
  - `payment.gateways.stripe.secret_key` => EMPTY; `webhook_secret` => EMPTY; `enabled` => false; `supported_currencies` => USD,EUR,KWD,SAR,AED.
  - `payment.gateways.paypal.client_id` => EMPTY; `client_secret` => EMPTY; `mode` => EMPTY (note: `.env` contains `PAYPAL_MODE=` empty, which overrides the `sandbox` default); `enabled` => false; `webhook_id` => EMPTY; `supported_currencies` => USD,EUR.
  - `payment.default_currency` => USD; `payment.default_gateway` => myfatoorah.
  - `StripeGateway::isConfigured()` => NO. `PayPalGateway::isConfigured()` => NO.
  - `CurrencyService::getCatalogCode()` => USD (this environment's catalog currency).
- `.env` key audit (names + lengths only, values never read out):
  - Canonical keys `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_ENABLED`, `PAYPAL_CLIENT_ID`, `PAYPAL_CLIENT_SECRET`, `PAYPAL_ENABLED`, `PAYPAL_SUPPORTED_CURRENCIES` => ABSENT. `PAYPAL_MODE`, `PAYPAL_WEBHOOK_ID` => present but EMPTY.
  - Legacy keys: `STRIPE_API_KEY` => SET (first-token format does not match any `sk_test_`/`sk_live_` pattern); `STRIPE_WEBHOOK_SECRET_KEY` => SET; all legacy `PAYPAL_*` (`SANDBOX/LIVE_CLIENT_ID/SECRET`, `CURRENCY`, etc.) => EMPTY.
- Canonical env contract (`config/payment.php` + `.env.example` registry section, lines 315–325): Stripe = `STRIPE_ENABLED` / `STRIPE_SECRET_KEY` / `STRIPE_WEBHOOK_SECRET` / `STRIPE_SUPPORTED_CURRENCIES` (default `USD,EUR,KWD,SAR,AED`); PayPal = `PAYPAL_ENABLED` / `PAYPAL_MODE` / `PAYPAL_CLIENT_ID` / `PAYPAL_CLIENT_SECRET` / `PAYPAL_WEBHOOK_ID` / `PAYPAL_SUPPORTED_CURRENCIES` (default `USD,EUR`).

## 3. Existing Architecture

Canonical (live) path, all under `app/`:

- `config/payment.php` — gateway definitions (class, enabled, credentials, supported_currencies, methods).
- `app/Services/Gateway/StripeGateway.php` — Checkout Sessions (`mode=payment`, single line item, `success_url`/`cancel_url` templated with `{CHECKOUT_SESSION_ID}`), verify via session retrieve + PaymentIntent amount/currency cross-check, refund via PaymentIntent. Minor units via `toMinorUnits()` (zero-decimal override + `CurrencyPrecision` delegation).
- `app/Services/Gateway/PayPalGateway.php` — Orders v2 (`CAPTURE` intent, `PayPal-Request-Id: order-{id}-{attempt}`), verify captures APPROVED orders (idempotent `capture-{id}` key, `ORDER_ALREADY_CAPTURED` race handled by refetch), fail-closed `capturedTotals()` check. SDK client built from canonical `mode` + single id/secret pair.
- `app/Services/Payment/PaymentCurrencyResolver.php` — `forOrder()`: `catalog_currency_code` → `currency_code` → live catalog code → `payment.default_currency` fallback.
- `app/Services/Payment/CurrencyPrecision.php` — single 3dp-vs-2dp authority (BHD/JOD/KWD/OMR/TND = 3).
- `app/Services/Payment/PaymentGatewayRegistry.php` — `canInitiate()` (enabled + configured + method + currency) for NEW money; `canVerify()`/`resolve()` intentionally ignore `enabled` so in-flight payments complete after a disable.
- `app/Services/Payment/GatewaySettingsService.php` — merges `config/payment.gateways` with `settings.options.payment_gateways` overrides; admin may change ONLY `{enabled, display_name, sort_order}` (read + write allowlisted; secrets stay env-only).
- `app/Services/Payment/PaymentCheckoutHandler.php` — `handleOnlinePayment()`: registry gate → factory → adapter `supportsCurrency()` double-check → coupon reserve → `createInvoice($order, $orderPrice, …)` → persist `Transaction` (amount = order-derived, currency = `forOrder()`).
- `app/Services/Checkout/OrderCreationService.php` — `resolveCurrencySnapshot()`: `currency_code = catalogCode`, `catalog_currency_code = catalogCode` (written once at creation).
- `app/Services/Payment/PaymentCompletionService.php` — `completeLocked()`: idempotency token (primary) → order-pending (secondary) → amount×1000 + currency + provider-ref fail-closed checks → canonical commit (txn paid → reservation commit → promotion finalize → `completed`).
- `app/Http/Controllers/Api/General/OrderController.php::checkout` — amount = `roundForCurrency($order->total_price, forOrder($order))`; `checkoutCallback`/`checkoutErrorCallback` — txn lookup by provider ref → `verifyPayment()` → locked `completeLocked()`.
- `app/Http/Controllers/Api/General/PaymentWebhookController.php::stripe|paypal` — raw-body signature verify (Stripe) / transmission verify against `webhook_id` (PayPal) → `completeFromProviderRef()` under row locks. MyFatoorah deliberately has NO webhook (browser-callback only).
- Routes (`routes/api.php`): `checkout/callback`, `checkout/error-callback` (throttle:payment-callback), `checkout/webhooks/stripe`, `checkout/webhooks/paypal` (throttle:payment-webhook), `v1/admin/payment-gateways` GET/PUT, `v1/admin/payments/{order}/refund`.
- Legacy/dead: `packages/marvel/src/Payment/Stripe.php`, `Paypal.php`, `Traits/PaymentStatusManagerWithOrderTrait.php`, `Http/Controllers/WebHookController.php`, `config/shop.php` stripe/paypal blocks — **zero routes registered, zero references from `app/` or `routes/`** (verified by grep). The legacy `.env` keys are unread by any live code path.
- `OrderCreateRequest` (`packages/marvel/src/Http/Requests/OrderCreateRequest.php`): fields are name/phone/address/notes/gateway/payment_method/shipping — **no `currency`, no `amount`, no `total_price`**. Client cannot submit pricing.

## 4. Stripe Configuration

| Item | Value (redacted) | Verified how |
|---|---|---|
| `STRIPE_SECRET_KEY` configured | NO (absent from `.env`) | `.env` audit + bootstrapped `config()` check => EMPTY |
| `STRIPE_WEBHOOK_SECRET` configured | NO | same |
| `STRIPE_ENABLED` | false (default; var absent) | `config()` => false |
| Supported currencies | USD,EUR,KWD,SAR,AED | `config()` |
| Consumed by gateway | YES — `isConfigured()` reads `payment.gateways.stripe.secret_key`; `client()` builds `StripeClient(secret_key)` | source `StripeGateway.php:357-362,444-451` |
| `isConfigured()` runtime | NO | executed via bootstrapped script |
| Mode/endpoint | Test vs Live is encoded in the key prefix (`sk_test_` vs `sk_live_`); no key present so no mode. `StripeClient` defaults to `https://api.stripe.com` | source + SDK default |

## 5. Stripe Source-Code Flow

Mechanism (determined from source, not assumed): **Checkout Session** (`mode=payment`), one line item (`Order #id` × 1), currency lowercased, `unit_amount` = minor units, `metadata.order_id`. Verify = session retrieve; if `payment_status=paid` and a `payment_intent` is referenced, the intent's amount/currency must equal the session's or verification FAILS. Refund = resolve session → PaymentIntent → `refunds->create` (only on succeeded intent). File: `app/Services/Gateway/StripeGateway.php` (createInvoice L73, verifyPayment L157, refund L240, toMinorUnits L378, findSessionIdByPaymentIntent L412).

## 6. Stripe Automated Test Results

`tests/Feature/Payment/StripeGatewayTest.php` (16 tests, 83 assertions): PASS. `StripeFailureCorrelationTest` (4): PASS. Webhook/security/concurrency/completion suites covering Stripe (PaymentWebhookTest 10, PaymentSecurityTest 11, PaymentConcurrencyTest 9, PaymentCompletionTest 14): all PASS. All tests use the injected-client seam (mocked Stripe SDK) — proof of adapter logic, NOT of provider connectivity.

## 7. Stripe Real Sandbox E2E Results

**NOT EXECUTED — BLOCKED.** Chain of evidence: canonical secret EMPTY → `isConfigured()=NO`, `enabled=false` → `PaymentGatewayRegistry::canInitiate('stripe',…)` returns `misconfigured`/`disabled` → `PaymentCheckoutHandler` returns 422 before any SDK call (this exact block is covered by `GatewayRegistryTest`: "stripe paypal known but not initiable by default", "misconfigured empty api key blocks initiate"). No Checkout Session, payment, callback, or order completion can occur in this environment. The legacy `.env` `STRIPE_API_KEY` was deliberately NOT used: it is unread by the live adapter, and calling Stripe outside the application is excluded as a substitute by the task rules. To unblock: set `STRIPE_SECRET_KEY` (test key), `STRIPE_WEBHOOK_SECRET` (test endpoint secret), `STRIPE_ENABLED=true`, then re-run this phase.

## 8. PayPal Configuration

| Item | Value (redacted) | Verified how |
|---|---|---|
| `PAYPAL_CLIENT_ID` / `PAYPAL_CLIENT_SECRET` | NO / NO (absent) | `.env` audit + `config()` => EMPTY |
| `PAYPAL_MODE` | EMPTY (`.env` has `PAYPAL_MODE=` empty, overriding the `sandbox` default — fail-closed nuance, adapter coerces non-`sandbox`/`live` to `sandbox`) | `.env` audit + `config()` + `PayPalGateway::client()` L403-406 |
| `PAYPAL_ENABLED` | false | `config()` => false |
| `PAYPAL_WEBHOOK_ID` | EMPTY | `config()` => EMPTY (webhook endpoint returns 503 until set — verified by `PaymentWebhookTest`: "paypal missing webhook id is 503") |
| Supported currencies | USD,EUR | `config()` |
| Consumed by gateway | YES — `isConfigured()` reads id+secret; `client()` builds `srmklive/paypal` credentials for `mode` + `setCurrency($currency)` + `getAccessToken()` (OAuth) | source `PayPalGateway.php:375-382,397-430` |
| `isConfigured()` runtime | NO | executed via bootstrapped script |

## 9. PayPal Source-Code Flow

Mechanism: **Orders v2 API, `CAPTURE` intent** (`createOrder` → approve URL; `showOrderDetails` → `capturePaymentOrder`; refunds via `refundCapturedPayment`). Idempotency: `PayPal-Request-Id: order-{id}-{attempt}` on create, `capture-{id}` on verify. Verify captures APPROVED orders (capture-on-return by design), accepts COMPLETED, rejects everything else. Amounts are decimal strings at the currency exponent (`CurrencyPrecision::formatForGateway`). KWD note: KWD is in the Stripe default list but NOT in PayPal's default `USD,EUR` allowlist nor the SDK guard mirror — a KWD PayPal checkout fail-closes with 422 at `supportsCurrency()`/client-build (by design; documented in the adapter header). File: `app/Services/Gateway/PayPalGateway.php` (createInvoice L86, verifyPayment L173, refund L245, capturedTotals L485).

## 10. PayPal Automated Test Results

`tests/Feature/Payment/PayPalGatewayTest.php` (17 tests): PASS. `PayPalCaptureIdempotencyTest` (3): PASS. PayPal webhook/security/completion coverage (same suites as §6): PASS. All via the injected client-factory seam (mocked SDK). OAuth against the real PayPal sandbox was NOT attempted (no credentials) — no token was requested, none printed.

## 11. PayPal Real Sandbox E2E Results

**NOT EXECUTED — BLOCKED** (same gate as §7: `isConfigured()=NO`, `enabled=false` → 422 before OAuth/order creation). To unblock: set `PAYPAL_CLIENT_ID`/`PAYPAL_CLIENT_SECRET` (sandbox), `PAYPAL_MODE=sandbox`, `PAYPAL_WEBHOOK_ID`, `PAYPAL_ENABLED=true`, then re-run: OAuth check → app checkout → approve with sandbox buyer → return/capture → verification → completion.

## 12. Currency Authority Verification — PASS (source + automated test)

Chain proven in source: Admin catalog (`CurrencyService::getCatalogCode()`, from `settings.options.catalog_currency_code`, `config/shop.php` fallback) → `OrderCreationService::resolveCurrencySnapshot()` stamps `currency_code = catalog_currency_code = catalogCode` → `OrderController::checkout` charges `roundForCurrency(total_price, forOrder(order))` → `PaymentCheckoutHandler` persists `Transaction{amount, currency=forOrder()}` → gateways use `currencyResolver->forOrder($order)` and never request input. Client-supplied `currency`/`amount` are impossible: `OrderCreateRequest` has no such fields, and `PaymentSecurityTest` ("checkout ignores forged pricing and status fields") PASSES. Display-only paths (`X-Currency`/user preference/`SelectCurrencyRequest`) affect catalog presentation (`getEffectiveCode()`), never the order snapshot. Suites: `CatalogCurrencyAuthorityTest` PASS, `PaymentConcurrencyTest` ("stripe/paypal handler uses catalog currency despite usd preference") PASS, `GatewayCurrencySupportTest` + `PaymentCurrencyTest` (Feature/Currency) PASS.

## 13. Amount Precision Verification — PASS (executed)

Direct execution (no mocks): `StripeGateway::toMinorUnits(13.255,'KWD')` = **13255**; `fromMinorUnits(13255,'KWD')` = **13.255**; `CurrencyPrecision::formatForGateway(13.255,'KWD')` = **'13.255'** (PayPal value); `toMinorUnits(10.00,'USD')` = 1000; `toMinorUnits(10,'JPY')` = 10 (zero-decimal). Completion compares at ×1000 symmetric scale, exact for ≤3dp (`PaymentCompletionService::assertNoMismatch` L161). Suite `CurrencyPrecisionTest` PASS. Provider-side echo of 13255/`13.255` remains UNVERIFIED (blocked, §7/§11).

## 14. Order Currency Snapshot Verification — PASS (source + automated test)

`resolveCurrencySnapshot()` (OrderCreationService L447-461) writes the snapshot once; no code path mutates `currency_code`/`catalog_currency_code` post-creation (grep confirms only creation + read sites). `PaymentCurrencyResolver::forOrder()` prefers the stored snapshot over live catalog, so a catalog switch (KWD→SAR) affects only NEW orders; refunds after a switch settle in the transaction currency (`RefundCurrencyAndLedgerTest`: "refund after catalog switch succeeds in transaction currency" PASS; `OrderItemSnapshotTest` history test asserts the same values — 1 string-format assertion there fails on `'0.221000'` vs `'0.221'`, see §24, values equal numerically).

## 15. Callback/Webhook Verification — PASS (automated test; real delivery NOT verified)

Browser callbacks (`OrderController::checkoutCallback`, L267+): paymentId format-validated → txn lookup (`gateway_transaction_id`/`invoice_id`) → gateway from `transaction.payment_method` → `verifyPayment()` → locked `completeLocked()`; unknown-order paid callbacks fail SAFE (no success UI). Webhooks (`PaymentWebhookController`): Stripe raw-body signature vs `webhook_secret` (`checkout.session.completed` → complete; `payment_intent.payment_failed` → resolve session → mark failed; unknown → ack 200 ignored); PayPal transmission verify vs `webhook_id` (`PAYMENT.CAPTURE.COMPLETED` → complete; DENIED → failed; REFUNDED/REVERSED → ledger note). Suites `PaymentWebhookTest` (10) + `StripeFailureCorrelationTest` (4) PASS. Real provider delivery to these endpoints NOT verified (blocked).

## 16. Database Verification

No live E2E rows exist (nothing was initiated — correctly, per fail-closed gating). The persisted shape is proven by suites: `Transaction{order_id, user_id, invoice_id=gateway ref, payment_method, status pending→paid/failed, amount, currency, gateway_transaction_id, gateway_response (allowlisted + `_callback_type`), idempotency_key, paid_at}`; `Order{status pending→completed, payment_status, paid_at, currency snapshot}` exactly once. Real provider↔application correlation table cannot be produced until §7/§11 unblock — template retained in §20 note below. (No `FIELD/APPLICATION/PROVIDER/MATCH` table is fabricated.)

## 17. Fail-Closed Verification — PASS (automated test; real-provider NOT verified)

`PaymentCompletionService::assertNoMismatch` throws on missing/divergent amount (×1000), missing/divergent currency, or foreign provider ref; callers mark txn failed and never complete (`PaymentCompletionTest`: amount/currency/provider-ref mismatches; `PaymentSecurityTest`: forged ids, forged signatures, user-B isolation, tampered verify). Gateway adapters independently fail closed (unsupported currency, bad/empty responses, SDK exceptions, PayPal capture-total ≠ unit-total). Real-provider mismatch (e.g. underpaid Stripe session) NOT exercised against live APIs (blocked).

## 18. Idempotency Verification — PASS (automated test)

Token primary (`idempotency_key` stamped under lock; replay → `IdempotentReplay`), order-pending secondary, duplicate-paid-row → `DuplicateHold` + `PaymentReconciliationResult` (no second completion), webhook event-id dedupe (cap 20), PayPal idempotent capture keys + `ORDER_ALREADY_CAPTURED` collapse. Suites: `PaymentConcurrencyTest` (dual callbacks, callback+webhook race), `PaymentCompletionTest` (replay, duplicate hold), `PaymentWebhookTest` (event replay), `PayPalCaptureIdempotencyTest` (double verify, already-captured race). Double real-callback processing NOT verified live (blocked).

## 19. Gateway Enable/Disable Verification — PASS (automated test)

`GatewayDisableTest` + `GatewaySettingsAdminTest` + `GatewayRegistryTest` PASS: admin PUT `{enabled}` persists to `settings.options.payment_gateways` (only allowlisted keys; secrets never stored/returned — asserted), disabled gateway blocks NEW initiation (422, no transaction row), `canVerify()`/`resolve()` still complete in-flight payments after disable (`PaymentWebhookTest`: "stripe completes after gateway disabled"). Admin view never leaks secrets. Matrix A/B/C (Stripe on/off × PayPal on/off) is enforced by the same `canInitiate()` gate for both adapters; per-combination live initiation NOT executed (blocked — both currently disabled/unconfigured).

## 20. Evidence Matrix

| Verification | Stripe | PayPal |
|---|---|---|
| Source integration | PASS | PASS |
| Config loaded (canonical, runtime) | FAIL (EMPTY — §4) | FAIL (EMPTY — §8) |
| Admin enable/disable | PASS (mocked) | PASS (mocked) |
| Automated tests | PASS (149-suite) | PASS (149-suite) |
| Real API authentication | BLOCKED | BLOCKED |
| Real payment/order creation | BLOCKED | BLOCKED |
| Real provider payment | BLOCKED | BLOCKED |
| Callback/webhook (real delivery) | BLOCKED | BLOCKED |
| Provider amount verified | BLOCKED (math PASS §13) | BLOCKED (math PASS §13) |
| Provider currency verified | BLOCKED | BLOCKED |
| DB transaction verified | BLOCKED (shape PASS via tests) | BLOCKED (shape PASS via tests) |
| Order completion verified | BLOCKED (logic PASS via tests) | BLOCKED (logic PASS via tests) |
| Idempotency verified | PASS (mocked) / real BLOCKED | PASS (mocked) / real BLOCKED |
| Fail-closed verified | PASS (mocked) / real BLOCKED | PASS (mocked) / real BLOCKED |

DB correlation table (§16 of the task): not producible — no real provider IDs exist. No row is fabricated.

## 21. Exact Execution Traces

Stripe (Checkout Session): Admin catalog (`CurrencyService::getCatalogCode`, `app/Services/Currency/CurrencyService.php:64`) → catalog pricing (`ProductPricingService`/runtime pricing, `docs/architecture/runtime-pricing-architecture.md`) → `POST checkout` (`OrderController::checkout`, `app/Http/Controllers/Api/General/OrderController.php:92`) → `OrderCreationService::createOrder` + `resolveCurrencySnapshot` (`app/Services/Checkout/OrderCreationService.php:31,447`) → `CurrencyPrecision::roundForCurrency(total_price)` (OrderController L136) → `PaymentCheckoutHandler::handleOnlinePayment` (`app/Services/Payment/PaymentCheckoutHandler.php:28`) → `PaymentGatewayRegistry::canInitiate` (`PaymentGatewayRegistry.php:50`) → `PaymentGatewayFactory::make` → `StripeGateway::createInvoice` (`app/Services/Gateway/StripeGateway.php:73`, minor units L378) → Stripe Checkout Session → customer pays → `GET checkout/callback?paymentId=cs_*` (`OrderController::checkoutCallback` L267) and/or `POST checkout/webhooks/stripe` (`PaymentWebhookController::stripe` L68, verifier `StripeWebhookVerifier`) → `StripeGateway::verifyPayment` (L157, intent cross-check) → `PaymentCompletionService::completeLocked` (`PaymentCompletionService.php:48`: token L56 → pending L78 → `assertNoMismatch` L157 → `commitLocked` L272: txn paid, reservation commit, promotion finalize, `changeOrderStatus completed`).

PayPal (Orders v2 CAPTURE): same chain to the factory → `PayPalGateway::createInvoice` (`app/Services/Gateway/PayPalGateway.php:86`, value `formatForGateway`, `PayPal-Request-Id`) → PayPal order + approve URL → buyer approves → return `GET checkout/callback?token=<id>` (correlated via stored `gateway_transaction_id`) and/or `POST checkout/webhooks/paypal` (`PaymentWebhookController::paypal` L166, `PayPalWebhookVerifier` vs `webhook_id`) → `PayPalGateway::verifyPayment` (L173: APPROVED→capture→`completedResult`/`capturedTotals` L454/485) → same `completeLocked()` commit path.

## 22. Problems Found

1. (BLOCKER for E2E, not a code bug) Canonical Stripe/PayPal credentials absent + both gateways disabled in this environment — real sandbox E2E cannot run. Owner: environment configuration.
2. (Config nuance) `.env` contains `PAYPAL_MODE=` (empty), which overrides the `sandbox` default with `''`; the adapter coerces it back to `sandbox` (PayPalGateway L403-406), and `PAYPAL_WEBHOOK_ID=` empty forces webhook 503. Safe behavior, but the empty vars should be set explicitly when configuring.
3. (Dead config, informational) Legacy `STRIPE_API_KEY`/`STRIPE_WEBHOOK_SECRET_KEY` values exist in `.env` but feed only unrouted Marvel config — they give a false impression that "Stripe has keys". Recommend either removing them or documenting that the canonical `STRIPE_SECRET_KEY`/`STRIPE_WEBHOOK_SECRET` are the live keys. No change made (out of scope without approval).
4. (Out-of-scope pre-existing failures, NOT touched) `tests/Feature/Currency`: 9 failures — 8× `'0.221000'` vs `'0.221'` string-format assertions (`OrderCurrencyTest` ×3, `OrderItemSnapshotTest` ×1, `CurrencyRateModeTest` ×2, `MandatoryCurrencyGateTest` ×2 partial) and `MandatoryCurrencyGateTest` ×1 `LogActivityJob::__construct()` type error (string passed where `ActivitySnapshot` expected). All are currency-rate/audit areas, none in the Stripe/PayPal path (`PaymentCurrencyTest`, `GatewayCurrencySupportTest` PASS).

## 23. Changes Made

NONE. Zero files changed (`git status` untouched). Per Phase 14: everything that passed must not be modified; the only failure (real E2E) is environmental (missing credentials), not an application bug, so no fix was warranted.

## 24. Regression Results

- `php artisan test tests/Feature/Payment` → **149 passed, 1050 assertions, 0 failed** (19 classes incl. StripeGatewayTest, PayPalGatewayTest, PaymentCompletionTest, CatalogCurrencyAuthorityTest, CurrencyPrecisionTest, GatewayDisableTest, GatewayRegistryTest, GatewaySettingsAdminTest, PaymentWebhookTest, PaymentSecurityTest, PaymentConcurrencyTest, RefundCurrencyAndLedgerTest).
- `php artisan test tests/Feature/Currency` → 186 passed / **9 failed** (pre-existing, unrelated — §22.4; payment-relevant `PaymentCurrencyTest`, `GatewayCurrencySupportTest` PASS).
- Precision execution check: 13255 / 13.255 / '13.255' / 1000 / 10 — all exact.
- No re-runs after fixes were needed (no fixes made).

## 25. Final Verdict

- **STRIPE: BLOCKED — EXTERNAL REQUIREMENT MISSING** (canonical test credentials + enablement; then execute §7 steps and re-verify).
- **PAYPAL: BLOCKED — EXTERNAL REQUIREMENT MISSING** (canonical sandbox credentials + webhook_id + enablement; then execute §11 steps and re-verify).

Unblock checklist (no code changes required): set `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_ENABLED=true`; `PAYPAL_CLIENT_ID`, `PAYPAL_CLIENT_SECRET`, `PAYPAL_MODE=sandbox`, `PAYPAL_WEBHOOK_ID`, `PAYPAL_ENABLED=true`; confirm catalog currency supports the provider path (Stripe default list includes KWD/SAR/AED; PayPal default list is `USD,EUR` — extend `PAYPAL_SUPPORTED_CURRENCIES` only if the sandbox account supports the currency); expose webhook endpoints to the providers; then run one real order per gateway through checkout → provider payment → callback/webhook → verification → completion and fill the §16 correlation table.
