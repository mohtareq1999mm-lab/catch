# STRIPE + PAYPAL — FINAL END-TO-END INTEGRATION VERIFICATION REPORT

Date: 2026-09-26 · Project: `D:\work\meem` · Mode: verification-first (no production code changed)
Prior evidence: `PAYMENT_CATALOG_CURRENCY_VERIFICATION_REPORT.md` (currency chain, PASS WITH NON-BLOCKING LIMITATION)

> **Headline:** Both integrations are source-, config-structure-, and automated-test-verified end to end up to the provider API boundary. **Real sandbox E2E was ATTEMPTED and is BLOCKED: this environment contains no Stripe or PayPal credentials** (all slots EMPTY), so no real provider object could be created. Per the no-false-pass rule, neither gateway receives REAL SANDBOX E2E PASS. No secrets are printed anywhere in this report (presence reported as YES/NO only).

---

## 1. Executive Summary

- **Architecture (both):** complete and correct — checkout → order snapshot → resolver → registry gate → adapter → provider → callback/webhook → server-side verify → `PaymentCompletionService` → transaction + order. Stripe uses **Checkout Sessions** (`cs_*`, `success_url` templated with `{CHECKOUT_SESSION_ID}`); PayPal uses **Orders v2 API** (create → buyer approve → capture-inside-verify).
- **Config:** variable names, modes, endpoints, SDKs all verified; `.env.example` correctly documents canonical names. But runtime values are **all EMPTY** and both gateways default **disabled** → adapters report unconfigured, registry refuses initiation (fail-closed, as designed).
- **Network:** egress to `api.stripe.com` (HTTP 404 on `/v1` root = reachable) and `api-m.sandbox.paypal.com` (HTTP 401 on token endpoint without auth = reachable) VERIFIED — when credentials are supplied, no network change is needed.
- **Automated tests:** 106 tests / 711 assertions, 0 failures, covering initiation, verify, capture, webhooks (incl. replay/idempotency), mismatch rejection, currency authority, precision, enable/disable.
- **Verdicts:** `STRIPE: BLOCKED — EXTERNAL REQUIREMENT MISSING`, `PAYPAL: BLOCKED — EXTERNAL REQUIREMENT MISSING`. To unblock: fill the 6 empty slots listed in §4 and re-run the E2E checklist in §7/§11.

## 2. Environment

- PHP 8.2.30 (CLI), Laravel app boots cleanly (redacted probe executed via framework bootstrap).
- `.env` file PRESENT. All Stripe/PayPal credential slots present but **EMPTY** (verified by name-only scan; values never read or printed).
- SDKs installed via Composer: `stripe/stripe-php 13.1.0`, `srmklive/paypal 3.0.19` (classes resolve in-process).
- Outbound HTTPS to both provider APIs verified (status codes only, no auth sent): Stripe `api.stripe.com` reachable; PayPal sandbox `api-m.sandbox.paypal.com` reachable.
- Only live key in env: MyFatoorah (irrelevant to this task; untouched).

## 3. Existing Architecture

```
POST checkout → OrderController@checkout → OrderService::addItemsInOrder
  → OrderCreationService::createOrder/updateOrder (pending-only reuse)
  → PaymentCheckoutHandler::handleOnlinePayment (registry gate + adapter double-check)
  → StripeGateway | PayPalGateway ::createInvoice → provider object + redirect URL
  → Transaction row (pending, amount, snapshot currency, provider ref)
  → shopper pays at provider
  → RETURN:  checkout/callback?paymentId=… / error-callback (browser)
     SERVER: checkout/webhooks/stripe (checkout.session.completed, payment_intent.payment_failed)
             checkout/webhooks/paypal (capture COMPLETED / DENIED)
  → adapter verifyPayment (PayPal: capture-inside-verify) → PaymentCompletionService::completeLocked
     (idempotency token → order-pending → amount x1000 + currency + provider-ref fail-closed checks)
  → txn paid + order completed (exactly once) + PaymentSucceeded
```

- Contract: `App\Services\Payment\Contracts\PaymentGatewayContract` (createInvoice/verifyPayment/refund + name/code/isConfigured/supportsCurrency).
- Registry: `PaymentGatewayRegistry::resolve/canInitiate (enabled+configured+method+currency)/canVerify (ignores enabled — in-flight preserved)`; factory delegates.
- Currency: `PaymentCurrencyResolver::forOrder` (catalog snapshot → legacy → live catalog → default); zero base/user refs in `app/Services/Payment/**` (scoped search: 0 matches).
- Precision: `CurrencyPrecision` (BHD/JOD/KWD/OMR/TND = 3dp).
- Legacy Marvel `packages/marvel/src/Payment/*` (incl. `Paypal.php`) is dead and intentionally not reused (documented in `PayPalGateway` header).
- Routes (verified via `route:list`): `POST checkout`, `ANY checkout/callback`, `ANY checkout/error-callback`, `POST checkout/webhooks/stripe`, `POST checkout/webhooks/paypal`, `POST fast-shipping/checkout`.

## 4. Stripe Configuration

| Item | Value (redacted) |
|---|---|
| Secret key var (`STRIPE_SECRET_KEY`) | configured: **NO** (EMPTY) |
| Test-mode key (`sk_test_…`) | NO (no key at all) |
| Live key (`sk_live_…`) | NO |
| Webhook secret (`STRIPE_WEBHOOK_SECRET`) | configured: **NO** (EMPTY) |
| Gateway enabled (`STRIPE_ENABLED`, default false) | **NO** |
| Loaded by Laravel | N/A (nothing to load; `isConfigured()` → false) |
| Provider mode | determined by key prefix; no key → no mode |
| Endpoint | Stripe SDK default (`api.stripe.com`), correct; reachability VERIFIED (§2) |
| Supported currencies (`STRIPE_SUPPORTED_CURRENCIES`) | USD,EUR,KWD,SAR,AED (loaded ✓) |
| Consumed by gateway | YES — `isConfigured()` reads secret key; `supportsCurrency()` reads list; `client()` builds `StripeClient` (verified in source) |
| Mechanism used | **Checkout Session** (`checkout->sessions->create/retrieve`, `mode=payment`, `price_data` + `unit_amount`, `success_url`/`cancel_url` with `{CHECKOUT_SESSION_ID}`) — NOT PaymentIntent-direct, NOT Invoice |

To unblock E2E, fill: `STRIPE_SECRET_KEY` (test-mode key), `STRIPE_WEBHOOK_SECRET` (from webhook endpoint `…/checkout/webhooks/stripe`), set `STRIPE_ENABLED=true`.

## 5. Stripe Source-Code Flow

`OrderCreationService::resolveCurrencySnapshot` (L414-443) → `OrderController::checkout` (L92-142, amount=`order->total_price`, currency=resolver) → `PaymentCheckoutHandler::handleOnlinePayment` (L28-140: `canInitiate('stripe','online',orderCurrency)` → adapter `supportsCurrency` re-check → `createInvoice`) → `StripeGateway::createInvoice` (L73-155: `currency=strtolower(orderCurrency)`, `unit_amount=toMinorUnits` → session create → `GatewayResult(redirectUrl, cs_*, amount, currency, pending)`) → `Transaction` row (L123-133, `currency=forOrder`) → shopper pays → callback (`OrderController@checkoutCallback` L263ff, `paymentId=cs_*`) or webhook (`PaymentWebhookController@stripe` L68ff: raw-body HMAC vs `webhook_secret` → `checkout.session.completed` → `completeFromProviderRef`) → `StripeGateway::verifyPayment` (L157-238: session retrieve + **PaymentIntent cross-check**, fail-closed) → `PaymentCompletionService::completeLocked` (x1000 + currency + ref) → txn paid / order completed.

## 6. Stripe Automated Test Results

`StripeGatewayTest` 16/16 ✓ (contract, session payload incl. `unit_amount`+lowercase currency+templated URLs, zero-decimal path, unsupported-currency no-SDK-call, exception fail-closed, verify major-units/uppercase, session↔intent cross-check mismatches fail-closed, refund states, `isConfigured`, case-insensitive support, converters) · `CurrencyPrecisionTest` KWD legs ✓ (`toMinorUnits(13.255,KWD)=13255`, create-invoice sends `13255`, end-to-end snapshot→complete at 13.255) · `PaymentWebhookTest` Stripe legs ✓ (valid signature completes, bad signature 400 untouched, replay idempotent, completes-after-disabled, unknown txn ack-200-no-change) · plus shared completion/registry/disable suites (§17).

## 7. Stripe Real Sandbox E2E Results — BLOCKED (credentials missing)

| Step | Result |
|---|---|
| Test-mode credentials loaded | **NO** — slot empty; nothing to load |
| Gateway enabled | NO (default false) |
| SDK authenticates | NOT ATTEMPTED — no key (attempting would be a guaranteed 401 and proves nothing beyond §4) |
| Real Checkout Session created via app | NO — `isConfigured()=false` → registry `canInitiate` returns `misconfigured`; initiation correctly refused (fail-closed evidence, not E2E) |
| Amount/currency match | verified at payload level only (13255/KWD, mocked client) |
| Real payment / dashboard / return / verify / DB completion | NOT EXECUTED — blocked |
| Unblock checklist | set the 3 Stripe slots in §4 → new order in catalog currency → `POST checkout {gateway:stripe}` → pay with Stripe test card `4242 4242 4242 4242` (any future expiry/CVC) in test mode → approve → callback auto-fires; register `…/checkout/webhooks/stripe` in Dashboard with signing secret for the webhook leg → assert §§10/16 tables |

## 8. PayPal Configuration

| Item | Value (redacted) |
|---|---|
| Client ID (`PAYPAL_CLIENT_ID`) | configured: **NO** (EMPTY) |
| Client secret (`PAYPAL_CLIENT_SECRET`) | configured: **NO** (EMPTY) |
| Mode (`PAYPAL_MODE`, default `sandbox`) | `sandbox` default active (empty→sandbox; invalid→sandbox fail-safe in `client()`) |
| Webhook ID (`PAYPAL_WEBHOOK_ID`) | configured: **NO** (EMPTY; missing → webhook endpoint returns 503 `misconfigured`, tested) |
| Gateway enabled (`PAYPAL_ENABLED`, default false) | **NO** |
| Loaded by Laravel | N/A (nothing to load) |
| Endpoint | srmklive sandbox `api-m.sandbox.paypal.com` via `mode=sandbox`, correct; reachability VERIFIED (§2, 401 = alive) |
| Supported currencies (`PAYPAL_SUPPORTED_CURRENCIES`) | USD,EUR (loaded ✓) |
| Consumed by gateway | YES — `client($currency)` sets mode+creds+currency then `getAccessToken()`; SDK currency allowlist additionally rejects non-PayPal currencies fail-closed (e.g. KWD → controlled failure, documented in adapter header) |
| Mechanism used | **Orders v2 API**: `createOrder` (CAPTURE intent, `purchase_units[].amount{currency_code,value}`, `PayPal-Request-Id: order-{id}-{attempt}`) → buyer approval (`token`/`PayerID` appended to return URL) → **capture-inside-verify** (`capturePaymentOrder`, idempotent `capture-{id}` key, already-captured race re-fetch) → refund via `refundCapturedPayment` |

To unblock E2E, fill: `PAYPAL_CLIENT_ID` + `PAYPAL_CLIENT_SECRET` (sandbox app), `PAYPAL_WEBHOOK_ID` (from webhook `…/checkout/webhooks/paypal`), set `PAYPAL_ENABLED=true`. NOTE: PayPal-supported currencies are USD/EUR-class only — a KWD catalog order will fail-closed at initiation; use a USD/EUR catalog (or a supported currency) for the PayPal E2E leg.

## 9. PayPal Source-Code Flow

Same chain to the adapter (§5) → `PayPalGateway::createInvoice` (L86-171: resolver currency → `supportsCurrency` → `client($currency)` OAuth → `createOrder` with decimal-string `value=formatForGateway(amount,currency)` → approve URL + PayPal order id → `GatewayResult(amount, currency, pending)`) → transaction row → buyer approves in PayPal sandbox → return (`token=<paypalOrderId>`, correlated via stored `gateway_transaction_id`) or webhook (`PaymentWebhookController` PayPal leg: SDK `verify-webhook-signature` vs `webhook_id` → COMPLETED → `completeFromProviderRef`) → `PayPalGateway::verifyPayment` (L173-243: APPROVED→capture, COMPLETED→totals via `completedResult`/`capturedTotals` fail-closed on ambiguous/mixed captures) → `PaymentCompletionService::completeLocked` → txn paid / order completed. Refund: `refundCapturedPayment` on the captured payment id, success only on completed status.

## 10. PayPal Automated Test Results

`PayPalGatewayTest` 17/17 ✓ (contract, CAPTURE intent + major-unit value + URLs, attempt-based idempotency key, unsupported-currency no-client-call, exception/missing-approve-link fail-closed, APPROVED→capture→paid, already-captured no-re-capture, non-completed fail, exception fail-closed, refund completed-only + ambiguous-capture/uncaptured fail-closed, per-mode `isConfigured`, case-insensitive support) · `CurrencyPrecisionTest` PayPal leg ✓ (`value='13.255'`) · `PaymentWebhookTest` PayPal legs ✓ (COMPLETED completes, DENIED fails txn, unverified rejected untouched, unknown txn ack-200-logged, missing webhook id 503).

## 11. PayPal Real Sandbox E2E Results — BLOCKED (credentials missing)

| Step | Result |
|---|---|
| Sandbox credentials loaded | **NO** — both slots empty |
| OAuth authentication | NOT ATTEMPTED — no credentials (guaranteed 401; §2 proves the token endpoint is reachable for the retry) |
| Real PayPal order via app | NO — `isConfigured()=false` → initiation refused fail-closed |
| Buyer approval / capture / return / DB completion | NOT EXECUTED — blocked |
| Unblock checklist | set the 4 PayPal slots in §8 → catalog currency to a PayPal-supported code (e.g. USD) → checkout `{gateway:paypal}` → approve with sandbox buyer account → return auto-fires `checkout/callback`; register webhook + ID for the server leg → assert §§10/16 tables |

## 12. Currency Authority Verification — PASS (source + tests)

Chain proven class-by-class: `CurrencyService::setCatalogCurrency` writes ONLY `catalog_currency_code` → `getCatalogCode` → `OrderCreationService` totals+snapshot (`currency_code=catalog`, `catalog_currency_code=catalog`) → `PaymentCurrencyResolver::forOrder` (snapshot-first; never base/preference/header) → adapters. `OrderCreateRequest` has no currency/amount fields — client cannot override. `CurrencyPrecisionTest`, `CatalogCurrencyAuthorityTest` (preference USD vs catalog KWD → KWD everywhere), `OrderCurrencyTest` (base KWD vs catalog USD → order USD) all PASS (re-run §17).

## 13. Amount Precision Verification — PASS (source + tests)

`CurrencyPrecision` single exponent table; scope example 10.125+3.130=13.255 KWD verified: snapshot `13.255`, Stripe `13255` minor units, PayPal `'13.255'`, MyFatoorah `InvoiceValue 13.255`, callback completes at 13.255 (`kwd_13255_snapshots_and_completes_end_to_end`). BHD/JOD/KWD/OMR/TND loop-asserted incl. case/whitespace.

## 14. Order Currency Snapshot Verification — PASS (source + tests)

Frozen at creation; `setCatalogCurrency` touches settings only; `updateOrder` applies solely to the single `pending` order (`findPendingOrderForUser`, row-locked) = pre-payment retry = new checkout. `catalog_switch_only_affects_new_orders` (KWD→SAR: old stays KWD/KWD, new SAR) and `refund_after_catalog_switch_succeeds_in_transaction_currency` PASS.

## 15. Callback/Webhook Verification — PASS (source + tests, live delivery NOT verified)

Browser: `checkout/callback` + `error-callback` (paymentId format-validated, txn lookup by `gateway_transaction_id`/`invoice_id`, server-side `verifyPayment`, unknown-order fail-safe, mismatch→failed, coupon-blocked→failed+retryable). Server: Stripe raw-body HMAC (`checkout.session.completed`, `payment_intent.payment_failed`→session resolution) + PayPal SDK signature check, both with `_webhook_event_ids` dedupe (cap 20) into the same `completeLocked`. `PaymentWebhookTest` 10/10 ✓. Live provider→app delivery NOT verified (needs credentials + public URL/webhook registration).

## 16. Database Verification — PASS (test-driven; no live rows exist)

Proven schema/flow: `transactions{order_id,user_id,invoice_id,payment_method,status,amount,currency,gateway_transaction_id,gateway_response(+_callback_type/_webhook_event_ids),error_message,paid_at,idempotency_key}`; `orders{currency_code,base_currency_code,catalog_currency_code,currency_rate,converted_total_price,total_price,payment_status,paid_at}`. Tests assert per-leg: transaction currency = order currency, mismatch → `failed` + order stays pending, success → `paid` + `completed` exactly once. No real E2E rows exist (nothing was created — correctly, since initiation is refused without credentials).

## 17. Fail-Closed Verification — PASS (tests)

Amount mismatch, currency mismatch, missing amount/currency, provider-ref mismatch → `PaymentMismatchException` → txn `failed`, order pending (`PaymentCompletionTest`, incl. `callback_amount_and_currency_mismatch_mark_failed`; Stripe session↔intent cross-checks; PayPal ambiguous-capture fail-closed; reconciliation currency-mismatch row). Test bypass requires flag AND local env. Unsupported currency → 422 pre-provider, zero transaction rows.

## 18. Idempotency Verification — PASS (tests)

Token-stamp-first + status guards + duplicate-hold (second paid row → reconciliation record, no second completion) + PayPal `PayPal-Request-Id` keys + Stripe `{CHECKOUT_SESSION_ID}` correlation + webhook event dedupe. Tests: `callback_replay_is_idempotent_single_completion`, `stripe_replay_of_same_event_is_idempotent`, `duplicate_paid_callback_on_second_txn_holds_for_reconciliation`, PayPal already-captured no-re-capture, refund duplicate-key single provider call.

## 19. Gateway Enable/Disable Verification — PASS (tests)

Disable→`canInitiate=disabled`→422 with zero transaction rows; in-flight verify/completion still succeeds (`disable_mid_payment_still_completes_then_new_initiation_422`, `stripe_completes_after_gateway_disabled`, `can_verify_true_while_disabled`); re-enable restores; per-code overrides (`updateOverride($code)` touches only that code) so gateways are independent; admin list/update audited, secrets never stored/returned (`GatewaySettingsAdminTest` 11, `GatewayRegistryTest` 9, `GatewayDisableTest` 10 — all PASS).

## 20. Evidence Matrix

| Verification | Stripe | PayPal |
|---|---|---|
| Source integration | PASS | PASS |
| Config loaded | PASS-structure / **FAIL-values (EMPTY)** | PASS-structure / **FAIL-values (EMPTY)** |
| Admin enable/disable | PASS (tested) | PASS (tested) |
| Automated tests | PASS (16+shared) | PASS (17+shared) |
| Real API authentication | **BLOCKED** (no key) | **BLOCKED** (no creds) |
| Real payment/order creation | **BLOCKED** | **BLOCKED** |
| Real provider payment | **BLOCKED** | **BLOCKED** |
| Callback/webhook (code+mocked) | PASS / live delivery **BLOCKED** | PASS / live delivery **BLOCKED** |
| Provider amount verified | PASS (payload-level) / live **BLOCKED** | PASS (payload-level) / live **BLOCKED** |
| Provider currency verified | PASS (payload-level) / live **BLOCKED** | PASS (payload-level) / live **BLOCKED** |
| DB transaction verified | PASS (test-driven) / live rows N/A | PASS (test-driven) / live rows N/A |
| Order completion verified | PASS (test-driven) / live **BLOCKED** | PASS (test-driven) / live **BLOCKED** |
| Idempotency verified | PASS | PASS |
| Fail-closed verified | PASS | PASS |

## 21. Exact Execution Traces

Stripe: `CurrencyService::setCatalogCurrency` (`app/Services/Currency/CurrencyService.php` L265) → `getCatalogCode` (L64) → `OrderCreationService::createOrder` (L31: `app/Services/Checkout/OrderCreationService.php`) → `resolveCurrencySnapshot` (L414) → `OrderController::checkout` (`app/Http/Controllers/Api/General/OrderController.php` L92) → `OrderService::addItemsInOrder` (`app/Services/General/OrderService.php` L189; pending reuse L244/L283) → `PaymentCheckoutHandler::handleOnlinePayment` (`app/Services/Payment/PaymentCheckoutHandler.php` L28; gates L36-53, adapter check L64-78, txn row L123-133) → `PaymentGatewayRegistry::canInitiate` (`app/Services/Payment/PaymentGatewayRegistry.php` L49; `GatewaySettingsService` merge `app/Services/Payment/GatewaySettingsService.php` L199) → `StripeGateway::createInvoice` (`app/Services/Gateway/StripeGateway.php` L73; currency L96, minor units L97, session L105) → `PaymentCurrencyResolver::forOrder` (`app/Services/Payment/PaymentCurrencyResolver.php`) at every hop → return `checkout/callback` (`OrderController` L263; completion L394-453) / webhook `PaymentWebhookController::stripe` (L68; `StripeWebhookVerifier`) → `StripeGateway::verifyPayment` (L157; intent cross-check L191-227) → `PaymentCompletionService::completeLocked/assertNoMismatch` (`app/Services/Payment/PaymentCompletionService.php` L48/L157) → txn paid + order completed (L272-310).

PayPal: identical to the adapter, then `PayPalGateway::createInvoice` (`app/Services/Gateway/PayPalGateway.php` L86; currency L93, OAuth client L397-430, `createOrder` L119-135, idempotency L113) → return (token/PayerID; correlated via stored ref) / webhook (PayPal leg; `PayPalWebhookVerifier`; missing-ID 503) → `PayPalGateway::verifyPayment` (L173; capture L197-223, `completedResult` L454, `capturedTotals` L485 fail-closed) → same `completeLocked` → txn paid + order completed. Refund legs: `StripeGateway::refund` (L240; succeeded-only), `PayPalGateway::refund` (L245; completed-only).

## 22. Problems Found

1. **BLOCKING (environmental, not code): no Stripe/PayPal credentials in this workspace** — all slots EMPTY, both gateways disabled-by-default → real sandbox E2E impossible here. (This refutes the task's "credentials already exist" premise; verified, not assumed.)
2. Non-blocking doc note: some `api-desc/currency/*` passages describe the snapshot as base currency; implementation is catalog (flagged for a future docs-sync; untouched per safety rules).
3. Non-blocking: no single test asserting A-disabled/B-initiable simultaneously (independence proven structurally per-code).

## 23. Changes Made

**NONE** — no production code, config, env, or test was modified. Only evidence artifacts created: this report (+ prior currency report) and a redacted temp probe script (outside the repo; prints booleans only).

## 24. Regression Results

No changes ⇒ no regressions possible. Current-state suite re-run for this report (all PASS, 0 failures):

| Suite | Tests | Assertions |
|---|---|---|
| Payment/StripeGatewayTest | 16 | 83 |
| Payment/PayPalGatewayTest | 17 | 80 |
| Payment/PaymentWebhookTest | 10 | 79 |
| Payment/PaymentCompletionTest | 14 | 117 |
| Payment/CatalogCurrencyAuthorityTest | 3 | 28 |
| Payment/CurrencyPrecisionTest | 7 | 40 |
| Payment/GatewayRegistryTest | 9 | 34 |
| Payment/GatewayDisableTest | 10 | 78 |
| Payment/GatewaySettingsAdminTest | 11 | 112 |
| Currency/OrderCurrencyTest | 5 | 37 |
| Payment/RefundCurrencyAndLedgerTest | 4 | 23 |
| **Total** | **106** | **711** |

Unrelated project tests were not run (out of scope); no full-health claim is made.

## 25. Final Verdict

```text
STRIPE: BLOCKED — EXTERNAL REQUIREMENT MISSING
PAYPAL: BLOCKED — EXTERNAL REQUIREMENT MISSING
```

Both integrations are **implementation-complete and test-verified to the provider boundary** (initiation payloads, verification parsing, capture semantics, webhooks, idempotency, fail-closed matching, currency authority, precision, admin gating). They are **NOT yet live-proven**: the first real sandbox charge/capture/return against provider test infrastructure still has to be executed once credentials are supplied. When they are, the code paths to exercise are exactly those traced in §21, and success criteria are the comparison tables in §7/§11 plus the acceptance checklists below.

Stripe acceptance: credentials loaded (secret REDACTED, test-mode YES) · gateway enabled · real `cs_*` session created by the app · amount = order total in minor units · currency = order currency · test-card payment completed · Dashboard confirms · callback/webhook returns · server verify + `completeLocked` · txn paid + ref persisted · order completed exactly once · mismatch legs still reject.

PayPal acceptance: sandbox ID/secret loaded (REDACTED) · mode=sandbox · OAuth PASS (no token printed) · real PayPal order created by the app · `currency_code`+`value` = order · sandbox buyer approves · capture confirmed · return/webhook processed · server verify + `completeLocked` · txn paid + ref persisted · order completed exactly once · mismatch legs still reject.
