# Payment Flow Audit & Tap Payments Integration Design

| | |
|---|---|
| **Report type** | Read-only audit + integration design (no code, config, or data changes were made) |
| **Repository** | `D:\work\meem` (Laravel monolith: `app/` custom layer + `packages/marvel/` vendored commerce kernel) |
| **Date** | 2026-10-27 |
| **Status** | COMPLETE — static evidence + limited runtime verification (see §3) |
| **Confidence legend** | **VERIFIED** = confirmed by direct source inspection · **TESTED** = executed command with recorded output · **INFERRED** = derived from verified evidence, not directly observed · **UNKNOWN** = cannot be established from available evidence · **RISK** = identified hazard requiring mitigation |

---

## 1. Executive Summary

- The live payment architecture lives entirely in `app/` (`App\Services\Payment\*`, `App\Services\Gateway\*`, `App\Http\Controllers\Api\General\{OrderController,PaymentWebhookController,PaymentGatewayController}`). The `packages/marvel/` payment layer (Stripe/Razorpay/Paystack/Paymongo/Mollie/Iyzico/Flutterwave base classes, Marvel WebHookController) is **unrouted legacy code**. **[VERIFIED]**
- Three gateways are registered in a config-driven registry: **MyFatoorah** (default, browser-callback only, **no webhook by design**), **Stripe** (Checkout Sessions, signature-verified webhook), **PayPal** (Orders v2 via srmklive SDK, header-verified webhook). **[VERIFIED]**
- The registry deliberately splits **initiation** from **completion**: `PaymentGatewayRegistry::canInitiate` requires gateway enabled + configured + currency support, while `canVerify`/gateway resolution **ignore the `enabled` flag** so in-flight payments can still complete if a gateway is disabled mid-payment. **[VERIFIED]**
- There is a **single canonical completion authority**: `App\Services\Payment\PaymentCompletionService::completeLocked()`. All completion paths (browser callback, Stripe webhook, PayPal webhook, zero-value orders, COD/cashier mark-paid) converge on it. It enforces a caller-held DB transaction with `lockForUpdate` on both `Transaction` and `Order`, a **stamped `idempotency_key`** as the primary exactly-once defense, an order-pending secondary check, symmetric ×1000 amount/currency fail-closed mismatch assertions, provider-ref binding, and a duplicate-payment hold path. **[VERIFIED]**
- `OrderService::changeOrderStatus()` (line 946) is the **sole order-lifecycle authority**, gated by the Order Flow state machine (fail-closed) plus an F-1 authorization gate: `completed` on an UNPAID order requires the `payments.mark_paid` permission unless the caller is a system/payment path. **[VERIFIED]**
- Inventory reservations (`OrderReservationService`) and coupon reservations/consumption are correctly wired into the lifecycle: reserved before invoice creation, committed only on payment success (idempotent), released/restored on cancellation with distinct semantics for committed-but-unpaid orders. **[VERIFIED]**
- Reconciliation (`payments:reconcile` → `PaymentReconciliationJob`) is **detect-only** (no auto-repair) and records `PaymentReconciliationResult` rows for amount, currency, payment_status and order_status drift. Two weaknesses noted (§16, §17). **[VERIFIED]**
- **Tap Payments is a low-risk, purely additive integration** under the existing architecture: one new config entry, one new adapter class implementing `PaymentGatewayContract`, one new public webhook route with a hashstring verifier, and a `checkoutCallback` that needs **no structural change** because it already resolves the gateway from the transaction's `payment_method`. **No database migration is required** — the generic `transactions` table already stores `gateway_transaction_id`, `invoice_id`, `idempotency_key`, `gateway_response`. **[VERIFIED design feasibility / INFERRED runtime behavior]**
- The critical KWD constraint: KWD is a **3-decimal currency** (`CurrencyPrecision::decimalsFor` treats BHD/JOD/KWD/OMR/TND as 3dp). `PaymentCompletionService::assertNoMismatch` compares amounts symmetrically ×1000 with exact integer equality, so the Tap adapter must **send KWD amounts with 3 decimals** and **echo the amount verified from retrieve-charge** for completion to pass. Tap's official docs confirm amounts "up to 3 decimal places" for BHD/KWD/OMR. **[VERIFIED local side / DOC-FACT Tap side]**
- Runtime verification was **blocked in this environment**: no reachable MySQL (see §3). Route existence was verified via `php artisan route:list`; the PHPUnit suite was **NOT executed**. All findings labeled **TESTED** refer only to commands executed in §21.3. **[VERIFIED limitation]**

---

## 2. Scope, Methodology & Evidence Model

### 2.1 In scope

1. Trace of the full payment flow: checkout → validation → order creation → gateway dispatch → transaction creation → redirect/callback/webhook → completion → side effects (inventory, coupons, promotions, events, notifications).
2. Identification of the payment-success authority, state machines, failure handling, duplicate/idempotency handling.
3. Security review of the payment surface.
4. A Tap Payments (tap.company) integration **design** grounded in official Tap documentation, reusing existing architecture.

### 2.2 Out of scope

- Implementation of Tap (explicitly not requested; nothing was written except this report).
- Any change to code, config, `.env`, database, or routes.
- Production deployment or infrastructure changes.
- API documentation files (`docs/api/*`, `API_STORY.md`) — documentation mode is OFF; they were not touched.

### 2.3 Evidence classification used throughout

| Label | Meaning |
|---|---|
| **VERIFIED** | Read directly from source files with file paths and line numbers; no speculation. |
| **TESTED** | Command executed in this session; exact output recorded in §21.3. |
| **DOC-FACT** | Fact taken from official Tap documentation pages fetched during this audit (developers.tap.company). Applies to the Tap domain only. |
| **NEEDS-CONFIRMATION** | Tap behavior that official docs do not settle; must be confirmed with Tap support or in sandbox. |
| **INFERRED** | Reasoned conclusion from verified evidence; not directly observed. |
| **UNKNOWN** | Could not be established. |
| **RISK** | Hazard with concrete mitigation attached. |

### 2.4 Method

1. Route surface confirmed with `php artisan route:list` (executed; §21.3).
2. Static inspection of every class on the live payment path with line-precise references.
3. Cross-checks against `packages/marvel/` to distinguish live vs legacy code (grep-verified route registration).
4. `tests/Feature/Payment/*` and root-level payment tests catalogued as the behavioral contract baseline (statically; **not executed** — §3).
5. Tap official documentation fetched from `developers.tap.company` (get-started, create-a-charge, charges overview, retrieve-a-charge, knet, webhook, idempotency, create-a-refund, authentication pages) and translated into the design in §20.

---

## 3. Runtime Verification Constraints & Limitations

**Statement of limitation (important):** runtime, DB-backed verification was attempted and is **not available in this environment**. Nothing was faked.

| Check | Result | Evidence |
|---|---|---|
| PHP CLI | **TESTED** — PHP 8.2.30 (cli), Zend 4.2.30 | `php -v` executed 2026-10-27 (§21.3) |
| Laravel app boot | **TESTED** — `php artisan route:list` works; routes render | §21.3 |
| MySQL reachability | **FAILED** — `PDOException` SQLSTATE[HY000] [2002], connection actively refused at configured DB host | connection attempt recorded in audit session |
| MySQL/MariaDB service | **VERIFIED ABSENT** — no `mysql`/`mariadb` Windows service found | service enumeration in audit session |
| Docker fallback | **BLOCKED** — `docker` invocation rejected by the local shell allowlist (not by the project) | audit session |
| `php artisan db:show` | **FAILED** — DB unreachable (same root cause) | audit session |
| PHPUnit suite | **NOT RUN** — requires DB; not attempted further after connection failures | — |

Consequences, applied consistently in this report:

- Findings from source inspection are labeled **VERIFIED** (static), never "TESTED".
- **TESTED** is reserved for the commands in §21.3 (`php -v`, `php artisan route:list --path=checkout`, `php artisan route:list --path=orders`).
- The ~30 payment test files (§20.10) are treated as a **catalogued behavioral contract**, not as executed proof. Their existence is **VERIFIED**; their passing status at runtime is **UNKNOWN** in this environment.
- `phpunit.xml` sets `APP_ENV=testing`, `DB_CONNECTION=mysql`, `DB_DATABASE=catch`, `QUEUE_CONNECTION=sync`, `TELESCOPE_ENABLED=false` (non-forced, shell-overridable). **[VERIFIED]**

---

## 4. Payment Architecture Overview

### 4.1 Two-tier structure

- `packages/marvel/` — vendored Pickbazar/Chawkbazar-style kernel. Its payment subsystem (`Marvel\Payments\Payment`, `PaymentInterface`, gateway base classes for Stripe/Razorpay/Paystack/Paymongo/Mollie/Iyzico/Flutterwave, `WebHookController`, `CheckoutController`, `PaymentIntentController`, `PaymentMethodController`) has **no registered routes** — greps of `packages/marvel/src/Rest/Routes.php` return no references to these controllers. Treated as dead legacy code, **not** the integration target. **[VERIFIED]**
- `app/` — the live custom layer. Only routed Marvel remnants touch payments indirectly: Marvel `OrderController::updateStatus` (`PATCH api/v1/orders/{id}/status`, permission `update-order-status`) which delegates through the App layer's `OrderStatusBatchService`, and Marvel `CartController`. **[VERIFIED]**

### 4.2 Class map of the live architecture

| Concern | Class | Path |
|---|---|---|
| Gateway contract | `App\Services\Payment\Contracts\PaymentGatewayContract` | `app/Services/Payment/Contracts/` |
| Registry | `App\Services\Payment\PaymentGatewayRegistry` | `app/Services/Payment/` |
| Factory seam | `App\Services\Payment\PaymentGatewayFactory` | `app/Services/Payment/` |
| Settings merge | `App\Services\Payment\GatewaySettingsService` | `app/Services/Payment/` |
| Checkout payment orchestrator | `App\Services\Payment\PaymentCheckoutHandler` | `app/Services/Payment/` |
| Completion authority | `App\Services\Payment\PaymentCompletionService` (+ `PaymentCompletionOutcome`) | `app/Services/Payment/` |
| Currency authority | `App\Services\Payment\PaymentCurrencyResolver` | `app/Services/Payment/` |
| Money precision | `App\Services\Payment\CurrencyPrecision` | `app/Services/Payment/` |
| Webhook verifiers | `App\Services\Payment\StripeWebhookVerifier`, `App\Services\Payment\PayPalWebhookVerifier` | `app/Services/Payment/` |
| Refunds | `App\Services\Payment\PaymentRefundService` | `app/Services/Payment/` |
| Contact resolution | `App\Services\Payment\CustomerContactResolver` | `app/Services/Payment/` |
| Adapters | `App\Services\Gateway\MyFatoorahGateway`, `StripeGateway`, `PayPalGateway` | `app/Services/Gateway/` |
| Result DTO | `App\DTOs\GatewayResult` (`success, redirectUrl, gatewayTransactionId, amount, currency, status, errorMessage, rawResponse`) | `app/DTOs/` |

All files above exist and were inspected; no Tap-named class exists anywhere under `app/`. **[VERIFIED]**

### 4.3 Gateway contract (design target for Tap)

`PaymentGatewayContract` methods **[VERIFIED]**:

- `createInvoice(...)` — provider call + `GatewayResult` with redirect URL and provider transaction id.
- `verifyPayment(...)` — server-side re-fetch/fail-closed verification of a provider transaction.
- `refund(...)` — provider refund.
- `name()`, `code()`, `isConfigured()`, `supportsCurrency(string $code)`.

### 4.4 Registry semantics — the initiate/verify split **[VERIFIED]**

- `canInitiate` = gateway **enabled** (config `payment.gateways.{gw}.enabled` merged with settings override) **AND** `isConfigured()` **AND** payment method allowed (`methods: ['online']`) **AND** `supportsCurrency($currency)`.
- `canVerify` / gateway resolution deliberately **ignore `enabled`**: a gateway disabled after a customer began paying still completes in-flight payments (behavioral contract covered by `tests/Feature/Payment/GatewayDisableTest.php`).
- `GatewaySettingsService` merges `config('payment.gateways')` with `settings.options['payment_gateways']` overrides and **only reads an allowlist of keys** (`enabled`, `display_name`, `sort_order`) from the admin-editable settings store; **secrets are env-only**. Admin settings cannot inject a secret or arbitrary config. **[VERIFIED]**

### 4.5 Configuration surface (`config/payment.php`) **[VERIFIED]**

| Key | Env source | Default |
|---|---|---|
| default gateway | `DEFAULT_PAYMENT_GATEWAY` | `myfatoorah` |
| default currency | `DEFAULT_CURRENCY` | `KWD` |
| order timeouts | config-only values | — |
| test bypass | `PAYMENT_TEST_GATEWAY_BYPASS` | `false` (and only honored in local/testing env) |
| myfatoorah | `MYFATOORAH_ENABLED` (default true), `MYFATOORAH_API_KEY`, `MYFATOORAH_BASE_URL`, `MYFATOORAH_SUPPORTED_CURRENCIES` | currencies `KWD,SAR,AED,BHD,QAR,OMR,EGP` |
| stripe | `STRIPE_ENABLED` (default false), `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_SUPPORTED_CURRENCIES` | currencies `USD,EUR,KWD,SAR,AED` |
| paypal | `PAYPAL_ENABLED` (default false), `PAYPAL_MODE`, `PAYPAL_CLIENT_ID`, `PAYPAL_CLIENT_SECRET`, `PAYPAL_WEBHOOK_ID`, `PAYPAL_SUPPORTED_CURRENCIES` | currencies `USD,EUR` |

- All gateways declare `methods: ['online']`. **[VERIFIED]**
- `.env.example` documents the key **names** above; **no `TAP_*` keys exist anywhere**. Legacy keys `STRIPE_API_KEY` / `STRIPE_WEBHOOK_SECRET_KEY` are read only by `packages/marvel/config/shop.php` (dead config path). **[VERIFIED]**
- `MyFatoorahGateway` reads `config('payment.gateways.myfatoorah.api_key')`, consistent with `config/services.php`. **[VERIFIED]**
- No secret **values** were read, printed, or recorded in this audit; only key names. **[VERIFIED practice]**

---

## 5. Route Surface & Entry Points

Re-verified live on 2026-10-27 via `php artisan route:list --path=checkout` and `--path=orders` (§21.3). **[TESTED]**

| Route | Method | Name | Controller | Middleware | Note |
|---|---|---|---|---|---|
| `api/v1/general/checkout` | POST | — | `OrderController@checkout` (routes/api.php L150) | — | Entry point |
| `api/v1/general/checkout/callback` | GET+POST | `api.checkout.callback` (L131) | `OrderController@checkoutCallback` (L320) | `throttle:payment-callback` | Gateway browser callback |
| `api/v1/general/checkout/error-callback` | GET+POST | `api.checkout.errorCallback` (L132) | `OrderController@checkoutErrorCallback` (L620) | `throttle:payment-callback` | Failure landing |
| `api/v1/general/checkout/webhooks/stripe` | POST | `api.checkout.webhooks.stripe` (L136) | `PaymentWebhookController@stripe` | `throttle:payment-webhook` | Public, signature-verified |
| `api/v1/general/checkout/webhooks/paypal` | POST | `api.checkout.webhooks.paypal` (L137) | `PaymentWebhookController@paypal` | `throttle:payment-webhook` | Public, header-verified |
| `api/v1/general/checkout/promotions` | GET | — | `OrderController@eligiblePromotions` | — | Pre-checkout UI |
| `api/v1/general/checkout/cod/{orderId}/mark-paid` | POST | — | `OrderController@markCodAsPaid` (L1537 svc) | `permission:payments.mark_paid` | Manual path |
| `api/v1/general/checkout/cashier/{orderId}/mark-paid` | POST | — | `OrderController@markCashierPaid` (L1571 svc) | `permission:payments.mark_paid` | Manual path |
| `api/v1/general/payment-gateways` | GET | `api.general.payment-gateways.index` (L119) | `PaymentGatewayController@index` | — | Public method list |
| `api/v1/admin/payment-gateways` | GET/… | — | admin controller | admin auth | Registry admin |
| `api/v1/general/fast-shipping/checkout` | POST | — | `FastShippingController@checkout` | — | Separate flow (audited adjacent) |

- MyFatoorah has **no webhook route by design** — commented explicitly at `PaymentWebhookController` L35: "MyFatoorah has NO webhook endpoint on purpose: it is a browser-callback" flow. **[VERIFIED]**
- Rate limiters defined at `app/Providers/AppServiceProvider.php`: `payment-callback` L145 (20/min per IP), `payment-webhook` L157 (20/min per IP); plus `authenticated` 300/min, `admin` 400/min. `RouteServiceProvider`: `api` 60/min, `auth` 10/min, `orders` 10/min, `refunds` 5/min. **[VERIFIED]**

### 5.1 Request validation at entry

`Marvel\Http\Requests\OrderCreateRequest` (used by checkout): `payment_method` `in:online,cod,pay_at_cashier`; `gateway` nullable string `max:50`. **[VERIFIED]**

---

## 6. Checkout Flow Trace

`OrderController::checkout` (L142) → `OrderService::addItemsInOrder` (L207) — all inside one DB transaction. **[VERIFIED]**

1. **Cart locking**: cart rows locked `lockForUpdate` to prevent concurrent mutation. **[VERIFIED]**
2. **Coupon revalidation** of the applied coupon before totals. **[VERIFIED]**
3. **Totals**: `calculateCheckoutTotals` incl. tax computation; pricing is centralized (no pricing math in controllers/resources — see project rule). **[VERIFIED]**
4. **Pending-order reuse**: an existing pending order for the customer is reused or superseded (unique-pending-order DB constraint exists; migration verified). **[VERIFIED]**
5. **Order + items created** (`createOrder`/`createOrderItems`); currency stamped on the order (currency columns migration verified). **[VERIFIED]**
6. **Inventory reservation**: `OrderReservationService::reserveForOrder` (none → active state). **[VERIFIED]**
7. **Method dispatch**:
   - `cod` / `pay_at_cashier`: order committed as payment-pending at creation; completion happens only via the explicit, permission-protected mark-paid endpoints (§15.2) or delivery of physical goods semantics — not automatically. **[VERIFIED]**
   - `online`: `CurrencyPrecision::roundForCurrency(total_price, currency)` applied (KWD stays 3dp). **[VERIFIED]**
8. **Cart slice cleared** for purchased items. **[VERIFIED]**

### 6.1 Online payment handoff — `PaymentCheckoutHandler::handleOnlinePayment` **[VERIFIED]**

1. `registry.canInitiate(gateway, currency)` — on failure: HTTP 422 with translations `PAYMENT_CURRENCY_UNSUPPORTED` / `PAYMENT_GATEWAY_UNAVAILABLE`. **[VERIFIED]**
2. **Second** `supportsCurrency()` check inside the handler (defense in depth). **[VERIFIED]**
3. **Coupon reservation is taken BEFORE invoice creation** (Rule 9 / CP-09) so a gateway failure releases it deterministically. **[VERIFIED]**
4. `adapter->createInvoice(...)` → `GatewayResult`.
5. On adapter exception: coupon reservation released, order left pending, error surfaced. **[VERIFIED]**
6. A local `Transaction` row is created: `status = 'pending'`, `invoice_id = gatewayTransactionId`, `gateway_transaction_id`, `gateway_response` (sanitized), plus `_callback_type` marker (`web` | `mobile` from request `type`). **[VERIFIED]**
7. Response contract to the client: `{url: redirectUrl}` with `CHECKOUT_SUCCESSFUL` message, 200 — the Tap adapter must return `transaction.url` in this slot (§20.6). **[VERIFIED]**

---

## 7. Gateway Registry, Configuration & Settings

Covered in §4.4–4.5. Summary of behaviors that matter for Tap:

- Adding a gateway = add a `config/payment.php` entry + an adapter class registered in the factory/registry. No schema change. **[VERIFIED]**
- Disabling a gateway (config or admin settings override) blocks **new** initiations but never blocks **completion** of in-flight transactions. **[VERIFIED]**
- Admin settings can only override `enabled`, `display_name`, `sort_order` — a safe enable/kill-switch channel for Tap (§20.12). **[VERIFIED]**
- Public method list endpoint exposes display metadata only (`PublicPaymentGatewaysTest` covers it). **[VERIFIED]**

---

## 8. Gateway Adapter Behaviors

### 8.1 `MyFatoorahGateway` (default) **[VERIFIED]**

- `createInvoice` → MyFatoorah SendPayment; extracts `Data.InvoiceURL` + `Data.InvoiceId`.
- `verifyPayment` → checkInvoice by `Key`/`PaymentId`; success **only** when `InvoiceStatus === 'Paid'`.
- `refund` → makeRefund; **fail-closed**: requires a confirming `RefundStatus` regex match, otherwise treated as not-refunded.
- Stores provider id in both `invoice_id` and `gateway_transaction_id`.
- `RESPONSE_ALLOWLIST` strips PII from raw provider payloads before persisting into `gateway_response`.
- **No webhook**: completion authority is exclusively the browser callback (`checkoutCallback`). **[VERIFIED — class docblock L35 region]**

### 8.2 `StripeGateway` **[VERIFIED]**

- Checkout Sessions create; **minor-unit conversion** via `CurrencyPrecision::decimalsFor` — shared 3dp table for BHD/JOD/KWD/OMR/TND (×1000) and a zero-decimal currency list (×1). **[VERIFIED — methods at CurrencyPrecision L28/L42/L47/L56]**
- `verifyPayment`: retrieve session **and** cross-check the PaymentIntent `amount`/`currency`; any disagreement is fail-closed.
- `refund`: creates refund; success **only** on `status === 'succeeded'` (fail-closed).

### 8.3 `PayPalGateway` **[VERIFIED]**

- srmklive SDK; `createOrder` with intent `CAPTURE`; `PayPal-Request-Id` headers for provider-side idempotency.
- `verifyPayment`: `showOrderDetails` → `APPROVED` → `capturePaymentOrder` with idempotency key `capture-{id}`; races on "already captured" re-fetch to `COMPLETED`.
- Currency allowlist **excludes KWD** (PayPal does not support it here).
- Contains an explicit note that PayPal sandbox is blocked in this environment; network calls go through an **injected client factory seam** so tests mock the SDK. **[VERIFIED]** This is the pattern the Tap adapter must copy (§20.4).

### 8.4 `App\DTOs\GatewayResult` **[VERIFIED]**

Fields: `success`, `redirectUrl`, `gatewayTransactionId`, `amount`, `currency`, `status`, `errorMessage`, `rawResponse`.

---

## 9. Callback Flow — `OrderController::checkoutCallback` (L320) / `checkoutErrorCallback` (L620) **[VERIFIED]**

1. **Input**: `paymentId` from query/input; validated against regex `^[A-Za-z0-9\-_]+$`, max length 191. Callback `type` constrained to `web|mobile`.
2. **Gateway resolution**: explicit gateway name if provided, else the transaction's `payment_method`; the **hardcoded fallback default is `'myfatoorah'`** only when no transaction can be resolved. For a Tap callback the transaction row resolves gateway = `tap` automatically — **no structural change needed**, but see §20.5 redirect-param caveat. **[VERIFIED]**
3. `verifyPayment` → on failure: **fail-closed** transaction update to `failed` + `PaymentFailed` event. Unknown/unmatched order → fail-safe branch (no resurrection of cancelled orders; covered by `PaymentCallbackStressTest`).
4. **Locking protocol**: `DB::transaction` with `lockForUpdate` on the `Transaction` row (looked up by `gateway_transaction_id` OR `invoice_id`, from `paymentId` then verified id) **then** the `Order` row — consistent lock ordering avoids MySQL 1213 deadlocks. **[VERIFIED]**
5. `PaymentCompletionService::completeLocked(...)` executes within the held locks.
6. **Outcome handling**:
   - `PaymentMismatchException` → transaction `failed` **in-lock** (no state left dangling).
   - `CouponConsumptionException` → **coupon claim token rotation** (idempotency_key nulled), `_coupon_blocked` markers, transaction `failed`, `PaymentFailed` event; a retry re-attempts cleanly.
   - `Processed` → `PaymentSucceeded` event **after commit**, with an F-14 null-guard on the handler result.
7. **Response**: `mobile` callback → JSON; `web` callback → HTTP redirect to `config('app.app_url_frontend') . '/' . locale . '/payment/success|failed'`. **[VERIFIED]**

`checkoutErrorCallback` (L620) is the failure landing path: marks the transaction failed fail-closed, emits `PaymentFailed`, redirects/returns accordingly. **[VERIFIED]**

---

## 10. Webhook Flow — `PaymentWebhookController` **[VERIFIED]**

Shared machinery `completeFromProviderRef(...)`:

1. `findTxnByRef` probe on the provider reference.
2. **Gateway binding check**: the transaction's `payment_method` must match the receiving gateway; mismatch → 400.
3. **Event dedupe**: `_webhook_event_ids` list inside `gateway_response`, capped at 20 entries, re-attached after completion.
4. `adapter->verifyPayment(...)` — webhooks **never trust the payload's own claim**; they re-fetch through the adapter (fail-closed).
5. Locked completion via `PaymentCompletionService::completeLocked` (same DB-transaction + lockForUpdate protocol as callbacks).
6. Event-ids re-attached post-completion; `PaymentSucceeded` emitted for `completed` outcomes.
7. Mismatch between payload claim and verification → **200 "fail ack"** (terminal, provider stops retrying). Unknown reference → **200 ignored** so providers stop retrying harmless unknowns.

### 10.1 Stripe

- Raw body + `Stripe-Signature` + configured webhook secret → `StripeWebhookVerifier::construct` (crypto verification; forged signatures covered by `PaymentSecurityTest`).
- `checkout.session.completed` → `completeFromProviderRef`.
- `payment_intent.payment_failed` → session resolved via `_payment_intent_id` correlation marker (`StripeFailureCorrelationTest`).
- `charge.refunded` intentionally **NOT** handled (200 ignored) — external refunds currently recorded only for PayPal (§14).

### 10.2 PayPal

- Webhook id (`PAYPAL_WEBHOOK_ID`) required, else 503.
- Headers `Paypal-Auth-Algo`, `Paypal-Cert-Url`, `Paypal-Transmission-Id`, `Paypal-Transmission-Sig`, `Paypal-Transmission-Time` → `PayPalWebhookVerifier::verify`; failure → 401.
- `PAYMENT.CAPTURE.COMPLETED` → `completeFromProviderRef`; `PAYMENT.CAPTURE.DENIED` → `markFailedByProviderRef`; `PAYMENT.CAPTURE.REFUNDED` / `REVERSED` → `recordPayPalExternalRefund`.
- Replay handling covered by `PaymentWebhookTest`.

### 10.3 MyFatoorah — no webhook **[VERIFIED]**

Browser-callback-only; rationale documented in the controller (L35). Security consequence: the callback endpoint is the sole MyFatoorah authority, mitigated by fail-closed `verifyPayment` (server-side re-fetch) — the callback never trusts the redirect alone.

---

## 11. Payment Completion Authority — `PaymentCompletionService` **[VERIFIED]**

`completeLocked(txn, order, result, opts)` — **caller must already hold**: a DB transaction + `lockForUpdate` on the `Transaction` row and on the `Order` row. Enforcement of this protocol is by convention + tests (`PaymentCompletionTest`, `PaymentCallbackStressTest` assert the no-lock paths fail).

Defense layers, in order:

1. **`idempotency_key` (primary)**: a token stamped onto the transaction **before** business logic runs; every non-completing exit keeps the token, so the next replay lands on the same locked row and short-circuits. Unique index exists (migration `2026_09_25_*`). **[VERIFIED]**
2. **Order-pending secondary check**: completion only proceeds if the order is still pending (prevents completing a superseded/cancelled order).
3. **Duplicate payment detection (D-06)**: if the order is already non-pending and a **different** transaction row arrives with a valid payment, the outcome is `PaymentReconciliationResult` type `duplicate_payment` — held for human review, **no second completion**. **[VERIFIED]**
4. **`assertNoMismatch`** — fail-closed:
   - amount: `order.total_price` vs gateway-verified amount, both sides ×1000, **exact integer equality** (safe for 3dp KWD — 10.500 → 10500);
   - currency: string equality;
   - provider-ref: must match the transaction's `gateway_transaction_id`/`invoice_id`;
   - test-bypass option only via the explicitly passed opt.
5. **`commitLocked`** (all inside the caller's transaction):
   - transaction → `paid`, `paid_at` set;
   - order → `payment_status = Marvel\Enums\PaymentStatus::SUCCESS` (Schema-guarded), `paid_at`;
   - `OrderReservationService::commit` (inventory reserved → committed; idempotent exactly-once claims);
   - `finalizePromotionUsageAfterPayment`;
   - `OrderService::changeOrderStatus(invoiceId, 'completed', ..., emitPaymentSuccess=false, assertPaymentAuthority=false)` — the system path bypasses the F-1 permission gate legitimately (§12).
6. **Event emission**: `PaymentSucceeded` is dispatched **after commit** by the controllers (single source; completion service does not double-emit).

### 11.1 Why single-authority matters for Tap

Every integration path (callback, webhook, mark-paid, zero-value) funnels into `completeLocked`. Tap does not add a completion path — it adds **inputs** to the existing one. This is the central reason the integration is low-risk. **[VERIFIED architecture; INFERRED that the guarantee transfers unchanged to a new adapter since `completeLocked` is gateway-agnostic]**

---

## 12. Order Lifecycle & Payment Status Authority — `OrderService::changeOrderStatus` (L946) **[VERIFIED]**

- **Order Flow is the sole lifecycle authority**: `OrderFlowService::allowsFlowTransition` is fail-closed (unknown transitions rejected); self-transitions are no-ops.
- **F-1 authorization gate**: transitioning to `completed` on an **UNPAID** order requires the `payments.mark_paid` permission **unless** the caller passes `assertPaymentAuthority=false` — reserved for system/gateway completion paths (completion service, webhooks, callbacks). `MarvelStatusReachabilityTest` documents this authority boundary; the legacy Marvel `PATCH orders/{id}/status` route exists but routes through the App layer's `OrderStatusBatchService` with no independent mutation logic. **[VERIFIED]**
- `delivered` requires a delivery-completion invariant or the explicit `forceDelivered` escape hatch.
- On `completed` writes: `payment_status`, `paid_at`, fulfillment-status map, legacy `order_status` sync, immutable `order_status_history` record (actor types: `system` / `payment_gateway` / `admin` / `user`).
- **Invoice generation** on the first valid transition away from `pending`.
- On `completed`: `recordCouponUsage` (**fail-closed** — a broken coupon state throws `CouponConsumptionException` and aborts completion rather than completing a paid order with unrecorded coupon usage), `finalizePromotionUsageAfterPayment`, reservation `commit` (idempotent), customer metrics afterCommit.
- On `cancelled`: inventory **restore** (if reservations were committed; `inventory_restored_at` marker) or **release** (if still active); promotion decrement **only if unpaid**; coupon reservation released; fulfillment cascade.
- Events: `OrderStatusChanged`, `OrderCancelled`, `OrderDelivered`, `PaymentSucceeded` (wiring in §13.3).

---

## 13. Inventory, Coupon & Promotion Side Effects

### 13.1 Inventory — `App\Services\Inventory\OrderReservationService` **[VERIFIED]**

State machine: `none → active` (`reserveForOrder`, at checkout) → `committed` (`commit`, on payment success) | `released` (`release`).

- Source of truth: `order_products` rows; `reserved_quantity` counters updated.
- **Digital lines excluded** (domain rule D1 — no physical stock).
- **Exactly-once** via locked conditional claims — a repeated `commit()`/`release()` is a no-op, not a double mutation.
- Committed-but-unpaid order later cancelled → **restore** (not release), `inventory_restored_at` marker (dedicated migration).

### 13.2 Coupons & promotions

- Reserved at checkout (before invoice), revalidated, released on gateway failure, consumed on `completed` (`recordCouponUsage`, fail-closed), finalized for promotions after payment.
- Blocked-coupon recovery: token rotation + `_coupon_blocked` markers (§9.6).
- `coupons:expire-reservations` / `coupons:expire-claims` and `coupons:reconcile` (hourly) sweep stale state. **[VERIFIED — Kernel/routes/console.php]**

### 13.3 Event wiring — `app/Providers/EventServiceProvider.php` **[VERIFIED]**

| Event | Listeners |
|---|---|
| `PaymentSucceeded` | SendPaymentSucceededNotification, GenerateInvoiceListener, SendUserPaymentSucceededNotification, FulfillDigitalProducts, ReleaseFulfillmentOnPayment, MarkCouponClaimRedeemed, RecordPaymentSuccessInTimeline, SyncOrderWithFulfillmentProgress |
| `PaymentFailed` | SendPaymentFailedNotification, SendUserPaymentFailedNotification, RecordPaymentFailedInTimeline |
| `OrderCreated` | notifications + ReleaseFulfillmentOnCodPlacement + timeline |
| `OrderStatusChanged` | notifications/SMS/email/push/timeline |
| `OrderCancelled` | RestoreProductInventory + notifications |

Marvel's own EventServiceProvider registers additional (legacy) listeners; a duplicate RefundApproved legacy registration was already removed per in-code comment. **[VERIFIED]**

---

## 14. Refunds

- Canonical admin refund: `App\Services\Payment\PaymentRefundService` — per-transaction refund ledger stored in `gateway_response['_refunds']`, lock order **Transaction → Order** (consistent with §9 to avoid MySQL 1213 deadlocks). **[VERIFIED]**
- Adapter-level `refund()` is the integration point for any new gateway (fail-closed status confirmation — MyFatoorah regex match; Stripe `succeeded`). **[VERIFIED]**
- **External refunds**: only PayPal webhook currently records provider-initiated refunds (`recordPayPalExternalRefund`); Stripe `charge.refunded` is deliberately ignored; MyFatoorah refunds flow through its own refund flow outside webhooks. **[VERIFIED]**
- `RefundCurrencyAndLedgerTest` + `ExternalRefundTest` cover ledger/currency behavior. **[VERIFIED existence; NOT RUN]**
- For Tap: `TapGateway::refund()` mirrors the adapters; the decimal-amount ambiguity (DOC-FACT vs schema) is flagged in §20.9/Q3.

---

## 15. Special Payment Paths

### 15.1 Zero-value orders (rule D-05) **[VERIFIED]**

Online orders with total ≤ 0 **bypass gateways entirely**: `completeZeroValueOnlineOrder` (OrderController L224) creates a `paid` transaction with `gateway_response = ['zero_value' => true]`, transitions the order to `completed` with `emitPaymentSuccess=false`, and does **not** emit `PaymentSucceeded`. The `supportsCurrency()` check still runs before this branch — so a Tap adapter must implement it even though Tap never processes a zero-amount charge.

### 15.2 Manual mark-paid (COD / cashier) **[VERIFIED]**

`markCodAsPaid` (L1537) / `markCashierPaid` (L1571): lock the order's pending `cod`/`pay_at_cashier` transaction → `paid` → `changeOrderStatus('completed', emitPaymentSuccess=true)` with audit context `manual_mark_paid`. Endpoints are permission-gated (`payments.mark_paid`).

### 15.3 Test gateway bypass **[VERIFIED]**

`test_gateway_bypass_enabled` = env `PAYMENT_TEST_GATEWAY_BYPASS`, **default false**, honored only in local/testing environments; covered by `BypassGateTest`. Relevant to Tap only as an environmental control — Tap itself needs no bypass support.

---

## 16. Reconciliation & Scheduled Operations

### 16.1 `payments:reconcile` → `App\Jobs\PaymentReconciliationJob` (queue `queue.queues.high`) — **detect-only** **[VERIFIED]**

- Iterates transactions `status != failed` **with** `gateway_transaction_id`; calls each gateway's `verifyPayment`.
- Records `PaymentReconciliationResult` rows for drift in: **amount** (compare with >0.01 float tolerance), **currency** (source: `order->currency_code ?? base ?? default_currency`), **payment_status** (`isGatewayStatusPaid`: paid/success/completed; failed: failed/expired/cancelled), **order_status**.
- `compareRefundStatus` **always returns false** (known limitation — refunds are not reconciled).
- No repair actions: drift is surfaced, not auto-fixed.

**Weaknesses [RISK]:**
1. Reconciliation currency resolution bypasses the catalog authority (`PaymentCurrencyResolver`) — could mislabel drift for non-order-currency edge cases.
2. Float-tolerance amount compare is weaker than the ×1000 integer compare used at completion.
3. Refund status never reconciled.

### 16.2 Scheduler (Kernel + `routes/console.php`) **[VERIFIED]**

| Command | Frequency |
|---|---|
| `orders:cancel-unpaid` | every 5 min |
| `coupons:expire-reservations`, `coupons:expire-claims` | scheduled |
| `coupons:reconcile` | hourly |
| `fulfillment:release-ready` | hourly |
| `payments:reconcile` | command-driven (job on high queue) |

---

## 17. Security Review

| Area | Finding | Label |
|---|---|---|
| Entry validation | `OrderCreateRequest` whitelists `payment_method`; `gateway` capped at 50 chars | VERIFIED |
| Callback input | `paymentId` regex `^[A-Za-z0-9\-_]+$` ≤191 — blocks injection shapes | VERIFIED |
| Webhook auth (Stripe) | Crypto signature over raw body; forged-signature test exists | VERIFIED |
| Webhook auth (PayPal) | Header verification, 401 on fail, 503 if webhook id unconfigured | VERIFIED |
| Webhook auth (MyFatoorah) | None (no webhook) — mitigated by server-side `verifyPayment` at callback | VERIFIED |
| Trust boundary | Webhooks re-verify via adapter; payload claims never trusted alone | VERIFIED |
| Completion authorization | F-1 gate: `completed` on UNPAID needs `payments.mark_paid`; system paths explicit-opt-out | VERIFIED |
| Manual mark-paid | Permission middleware on both endpoints | VERIFIED |
| Secrets | Env-only; settings store allowlist prevents secret injection via admin API | VERIFIED |
| PII in stored payloads | `RESPONSE_ALLOWLIST` strips provider payloads before persist | VERIFIED |
| Test bypass | Default-off, env-gated to local/testing | VERIFIED |
| Rate limiting | payment-callback 20/min, payment-webhook 20/min per IP (L145/L157) | VERIFIED |
| Idempotency | `idempotency_key` unique index + stamped-before-logic protocol | VERIFIED |
| Dedupe | `_webhook_event_ids` cap 20 | VERIFIED |
| Mismatch handling | Fail-closed amount/currency/provider-ref; mismatched txn → failed in-lock | VERIFIED |
| Duplicate completion | D-06 hold → `duplicate_payment` reconciliation record, no double completion | VERIFIED |
| Dead surface | Legacy Marvel webhook/payment controllers unrouted | VERIFIED |
| Log hygiene | No secrets printed in this audit; key names only | VERIFIED (practice) |

**Residual risks [RISK]:**
1. Reconciliation currency source bypasses catalog authority (§16.1).
2. Float tolerance in reconcile amount compare (§16.1).
3. Refund reconciliation is a no-op (`compareRefundStatus` → always false) (§16.1).
4. Webhook event-id dedupe cap of 20 could, in theory, permit replay of a >20-generation-old event id on an extremely chatty transaction — bounded by the fail-closed `verifyPayment` re-fetch, so exploit value is nil, but worth noting.
5. `Transaction.amount` is a float-cast column (3dp migration widened it); the ×1000 mismatch assertion compensates at completion, but decimal drift remains possible if an adapter ever rounds inconsistently — hence the §20.4 amount rules for Tap.

---

## 18. Failure, Duplicate, Idempotency & Concurrency Matrix

| Scenario | Behavior | Protection | Label |
|---|---|---|---|
| Gateway down at checkout | 422, coupon reservation released, order stays pending | canInitiate + try/catch + release | VERIFIED |
| Callback verification fails | txn → failed, `PaymentFailed` | fail-closed verifyPayment | VERIFIED |
| Callback arrives for unknown/absent order | fail-safe, no resurrection of cancelled orders | guard branch + stress test | VERIFIED |
| Duplicate callback (same txn) | short-circuit; exactly-once | idempotency_key + locks | VERIFIED |
| Callback + webhook race (same payment) | single completion | DB txn + lockForUpdate both rows; `callback_and_webhook_race_completes_only_once` test | VERIFIED (test exists; NOT RUN here) |
| Webhook replay | ignored/deduped | `_webhook_event_ids`; Stripe sig replay covered | VERIFIED |
| Amount/currency mismatch | txn failed in-lock | assertNoMismatch ×1000 | VERIFIED |
| Provider-ref mismatch | rejected | ref binding | VERIFIED |
| Duplicate payment on completed order (D-06) | hold, record `duplicate_payment`, no second completion | secondary order-pending check | VERIFIED |
| Coupon consumption failure at completion | token rotation, `_coupon_blocked`, txn failed | fail-closed exception path | VERIFIED |
| Gateway disabled mid-flight | in-flight still completes | canVerify ignores `enabled` | VERIFIED |
| Inventory double-deduction | impossible | exactly-once locked claims; `PaymentProductionHardenTest` covers | VERIFIED (test exists; NOT RUN here) |
| Promotion double-increment | prevented | finalize-once + harden test | VERIFIED (test exists; NOT RUN here) |
| Committed-but-unpaid cancellation | restore (not release) + marker | `inventory_restored_at` | VERIFIED |
| Zero-value online order | local completion, no gateway | D-05 branch | VERIFIED |
| Reconciliation drift | detected, recorded, not repaired | detect-only design | VERIFIED |
| Deadlock between concurrent completers | consistent lock ordering txn→order | ordering convention | VERIFIED |

---

## 19. State Machines & Transition Tables

### 19.1 Local `Transaction` **[VERIFIED]**

| From | To | Trigger | Guard |
|---|---|---|---|
| *(created)* | `pending` | checkout online handoff | — |
| `pending` | `paid` | `commitLocked` inside completeLocked | caller holds locks; assertNoMismatch passed; idempotency_key match |
| `pending` | `failed` | verify failure, mismatch, coupon-block, error-callback, markFailedByProviderRef | fail-closed paths |
| `paid` | *(terminal for payment)* | refunds recorded only in `gateway_response['_refunds']` ledger | PaymentRefundService |

Scopes on `Marvel\Database\Models\Transaction`: `pending`/`paid`/`failed`; cast: `gateway_response` array, `amount` float, `paid_at` datetime; `idempotency_key` unique; uuid on creating; belongsTo Order. **[VERIFIED]**

### 19.2 Local `Order` payment/lifecycle **[VERIFIED]**

| Field | Values |
|---|---|
| `payment_status` (constants) | `payment-pending`, `success`, `failed`, `refunded` |
| `ORDER_STATUS_*` | `pending`, `processing`, `completed`, `cancelled`, `delivered` |
| `INVENTORY_STATE_*` | `none`, `active`, `released`, `committed`, `restored` |

`Marvel\Enums\PaymentStatus` (BenSampo) also defines PENDING/PROCESSING/SUCCESS/FAILED/REVERSAL/REFUNDED/CASH_ON_DELIVERY/CASH/WALLET/AWAITING_FOR_APPROVAL. **[VERIFIED]**

### 19.3 Order completion authority table

| Transition to `completed` | Allowed when |
|---|---|
| system/gateway path (callback, webhook) | `assertPaymentAuthority=false` + paid txn inside completeLocked |
| admin/mark-paid path | actor holds `payments.mark_paid` (F-1) |
| anything else | **rejected** (fail-closed Order Flow) |

### 19.4 Tap charge statuses → local mapping (design)

DOC-FACT statuses (official charges docs): `INITIATED, ABANDONED, CANCELLED, FAILED, DECLINED, RESTRICTED, CAPTURED, VOID, TIMEDOUT, UNKNOWN`. `INITIATED` carries `transaction.url`; `CAPTURED` = charged.

| Tap status | Local action | Label |
|---|---|---|
| `INITIATED` | no local change (await) | DESIGN |
| `CAPTURED` | `verifyPayment` re-fetch → `completeLocked` → txn `paid`, order `completed` | DESIGN |
| `FAILED`, `DECLINED`, `TIMEDOUT`, `CANCELLED` | fail-closed: txn → `failed` (after re-fetch verification) | DESIGN |
| `ABANDONED` | **NEEDS-CONFIRMATION** — candidate: mark failed after verification, or leave for `orders:cancel-unpaid` sweep | UNKNOWN |
| `RESTRICTED` | **NEEDS-CONFIRMATION** — hold pending, alert (fraud/KYC semantics) | UNKNOWN |
| `VOID` | refund/void-side concept; not expected on charge flow | UNKNOWN |
| `UNKNOWN` | no local change, 200 ack | DESIGN (safe default) |

---

## 20. Tap Payments Integration Design

> **Design principle:** reuse the existing registry/adapter/completion architecture. No new abstraction, no DB migration, no change to `PaymentCompletionService` or `OrderService`.

### 20.1 What the official Tap documentation establishes (DOC-FACT vs NEEDS-CONFIRMATION)

Pages fetched during this audit (all `developers.tap.company`): get-started, create-a-charge, charges overview, retrieve-a-charge, knet, webhook, idempotency, create-a-refund, authentication.

**DOC-FACT [VERIFIED against official pages]:**
- Create charge: `POST https://api.tap.company/v2/charges`, `Authorization: Bearer <secret key>`. Required: `amount`, `currency`, `customer`, `source`, `redirect` (+ `post.url` for webhooks).
- KNET source: `source.id = "src_kw.knet"`; **KWD only**; `redirect.url` mandatory for KNET/mada/3DS; response contains `transaction.url` for the hosted page.
- Hosted page (multi-method) source: `src_all`.
- `post.url` = webhook target; Tap posts **raw webhook payloads** (no signature header like Stripe); **cannot be localhost** — must be a public HTTPS URL.
- Webhook authenticity: **`hashstring` header** = HMAC-SHA256 of the concatenation
  `x_id{id}x_amount{amount rounded per ISO currency decimals}x_currency{currency}x_gateway_reference{reference.gateway — empty if absent}x_payment_reference{reference.payment}x_status{status}x_created{transaction.created}`
  keyed with the **Secret API Key**; a second header `hash` also exists. Agent UA `Tap-Webhooks/1.0`.
- Idempotency: `reference.idempotent` string — 24h validity; replaying the same idempotent value returns the **original response** instead of creating a new charge/refund/authorize.
- Statuses: `INITIATED / ABANDONED / CANCELLED / FAILED / DECLINED / RESTRICTED / CAPTURED / VOID / TIMEDOUT / UNKNOWN`.
- Retrieve: `GET /v2/charges/{charge_id}`.
- Refund: `POST /v2/refunds` with `charge_id`, `amount`, `currency`, `reason`; `reference.idempotent` supported; refund has its own optional webhook `post.url`.
- Amounts: "up to 3 decimal places" for BHD/KWD/OMR — matches the app's `CurrencyPrecision` 3dp table for KWD.
- 3DS is applied internally by default for customer-initiated transactions; an external `authentication` object is optional.
- Sandbox keys (`sk_test…`) come from the Tap Dashboard; secret keys are backend-only (never in frontends).
- Metadata: `udf1..udf8`-style `metadata` and `order.id` fields available for correlation.
- Charge `transaction` expiry configurable 5–60 min.

**NEEDS-CONFIRMATION [UNKNOWN — ask Tap support / probe sandbox]:**
1. Exact redirect contract: which query parameters accompany the user redirect back to `redirect.url` (param name carrying the charge id), and whether failed charges redirect to `redirect.url` vs only successful ones.
2. Hashstring algorithm versioning and the precedence of `hashstring` vs `hash` headers.
3. `create-a-refund` schema declares `amount` as **int32** while the doc text says up to 3 decimals for KWD — resolve before implementing KWD refunds.
4. Whether a charge idempotent replay inside the 24h window can be used per **attempt** safely under retries from our own stack.
5. Semantics of `ABANDONED` and `RESTRICTED` and recommended merchant handling.
6. Webhook retry policy (cadence, backoff) — matters for rate-limit sizing (`throttle:payment-webhook` is 20/min per IP).
7. Whether `post.url` payloads are sent for every status transition or only terminal ones.

### 20.2 Configuration design (no behavior change until adapter exists)

`config/payment.php` — new gateway entry, mirroring existing structure:

```php
'tap' => [
    'enabled'              => env('TAP_ENABLED', false),
    'display_name'         => 'Tap',
    'sort_order'           => 40,
    'methods'              => ['online'],
    'secret_key'           => env('TAP_SECRET_KEY'),
    'base_url'             => env('TAP_BASE_URL', 'https://api.tap.company/v2'),
    'webhook_secret'       => env('TAP_WEBHOOK_SECRET'), // Secret API key used for hashstring verification
    'supported_currencies' => array_map('trim', explode(',', env('TAP_SUPPORTED_CURRENCIES', 'KWD'))),
],
```

New `.env.example` key **names** (values never recorded here): `TAP_ENABLED`, `TAP_SECRET_KEY`, `TAP_WEBHOOK_SECRET`, `TAP_SUPPORTED_CURRENCIES`, `TAP_BASE_URL` (optional override; useful to point sandbox tests at `api.tap.company/v2` with an `sk_test` key — DOC-FACT: sandbox uses the same API base with test keys).

Notes:
- `webhook_secret` defaults conceptually to the same Secret API Key per DOC-FACT (hashstring is keyed with the Secret API Key); a separate key name keeps the config explicit and lets Tap rotate keys independently — **NEEDS-CONFIRMATION** whether Tap ever issues a distinct webhook-signing key.
- Default `enabled=false` means merging the config is inert until enabled — matches how Stripe/PayPal were introduced. **[VERIFIED pattern]**

### 20.3 Route design

```php
Route::post('checkout/webhooks/tap', [PaymentWebhookController::class, 'tap'])
    ->middleware('throttle:payment-webhook')
    ->name('api.checkout.webhooks.tap');
```

- Public (no auth), same block as Stripe/PayPal (routes/api.php ~L136-137). **[VERIFIED pattern]**
- No DB migration needed: the generic `transactions` table already carries everything Tap requires (`gateway_transaction_id`, `invoice_id`, `idempotency_key`, `gateway_response`, `_webhook_event_ids` slot). **[VERIFIED]**

### 20.4 `App\Services\Gateway\TapGateway` (new adapter, implements `PaymentGatewayContract`)

| Contract method | Tap implementation |
|---|---|
| `name()` / `code()` | `'Tap'` / `'tap'` |
| `isConfigured()` | secret key present in config |
| `supportsCurrency($c)` | membership in `supported_currencies` (default `KWD`) — also invoked on the zero-value path (§15.1) |
| `createInvoice(...)` | `POST {base}/charges` with `Bearer` secret; **amount via `CurrencyPrecision::formatForGateway($amount, $currency)`** (VERIFIED method, L56 — renders 3dp for KWD); `source.id` (`src_kw.knet` or `src_all` per config/requested method); `redirect.url` = absolute URL of `api.checkout.callback`; `post.url` = absolute URL of the new webhook route; `reference.idempotent` = per-attempt key (e.g., derived from the pending transaction id + attempt counter); `metadata` carries order/transaction ids for correlation. Uses an **injected HTTP client seam** (PayPal pattern) so tests never hit the network. Returns `GatewayResult{redirectUrl = transaction.url, gatewayTransactionId = charge id, amount, currency}` |
| `verifyPayment(...)` | `GET {base}/charges/{id}`; success **only** on `status === 'CAPTURED'` (fail-closed; no other status completes); returns the **echoed amount/currency** from the retrieve response so `assertNoMismatch` (×1000 exact) passes; maps non-success statuses per §19.4 |
| `refund(...)` | `POST {base}/refunds` with `charge_id`, `amount` (3dp question — Q3), `currency`, `reason`, `reference.idempotent`; fail-closed on refund status confirmation; integrates with `PaymentRefundService` ledger (`_refunds`) — no new refund path |
| Sanitization | `RESPONSE_ALLOWLIST` (MyFatoorah pattern) to strip PII from the charge object before persisting `gateway_response` |

**Amount discipline (critical):** KWD = 3 decimals both locally (`decimalsFor`) and at Tap (DOC-FACT). Send `formatForCurrency`-style 3dp string; on verification, parse the echoed amount and hand it to `GatewayResult.amount` unmodified. The ×1000 exact-integer compare in `assertNoMismatch` then behaves exactly as it does for MyFatoorah today. **[VERIFIED local semantics / DOC-FACT Tap semantics]**

**Callback default-gateway note:** `checkoutCallback` resolves the gateway from the transaction's `payment_method`; the `'myfatoorah'` fallback only fires when no transaction resolves. With Tap, the pending txn row (created at checkout, step 6 of §6.1) binds `payment_method='tap'`, so resolution is automatic. **[VERIFIED]**

### 20.5 Webhook controller design — `PaymentWebhookController::tap()`

Mirrors the existing `completeFromProviderRef` machinery:

1. Read JSON payload; extract charge `id`, `status`, amounts, `reference`.
2. **Verify authenticity**: compute HMAC-SHA256 hashstring over the DOC-FACT concatenation with the Secret API Key; `hash_equals` against the incoming `hashstring` header (fall back to `hash` header after confirming precedence — Q2). Failure → 401 (PayPal convention).
3. Locate transaction by `gateway_transaction_id` = charge id (also probe `invoice_id`).
4. **Gateway binding check** (payment_method must be `tap`) → 400 on mismatch.
5. **Event dedupe**: `_webhook_event_ids` (cap 20).
6. **Re-verify via adapter** `verifyPayment` (GET retrieve-charge) — payload claims never trusted.
7. `CAPTURED` → locked `completeLocked`; terminal-failure statuses → fail-closed txn `failed`; `INITIATED`/`UNKNOWN` → 200 ignored.
8. Re-attach event ids post-completion; `PaymentSucceeded` on completion; mismatch → 200 fail-ack; unknown reference → 200 ignored.

**Redirect-param caveat [NEEDS-CONFIRMATION]:** MyFatoorah redirects carry `paymentId`. If Tap's redirect uses a different parameter name (Q1), the callback must learn to accept it — smallest change: read the Tap param inside `checkoutCallback` only when the resolved gateway is `tap`, or normalize in the adapter's redirect URL by appending the charge id under the existing `paymentId` key if Tap permits extra query params on `redirect.url`. **This must be settled in sandbox before Phase 4 sign-off.**

### 20.6 Proposed end-to-end sequence (Mermaid)

```mermaid
sequenceDiagram
    autonumber
    participant U as Customer
    participant CO as POST /api/v1/general/checkout
    participant OH as PaymentCheckoutHandler
    participant TG as TapGateway (new)
    participant TAP as Tap API api.tap.company
    participant DB as transactions/orders
    participant CB as checkout/callback
    participant WH as checkout/webhooks/tap (new)
    participant PCS as PaymentCompletionService

    U->>CO: checkout (gateway=tap, currency=KWD)
    CO->>OH: handleOnlinePayment
    OH->>OH: registry.canInitiate (enabled+configured+KWD)
    OH->>TG: createInvoice(order)
    TG->>TAP: POST /v2/charges (Bearer sk_…, amount KWD 3dp, source.id, redirect.url=callback, post.url=webhook, reference.idempotent)
    TAP-->>TG: INITIATED {id=chg_…, transaction.url}
    TG-->>OH: GatewayResult{redirectUrl=transaction.url, gatewayTransactionId=chg_…}
    OH->>DB: INSERT Transaction(pending, invoice_id=chg_…, gateway_transaction_id=chg_…)
    OH-->>U: {url: transaction.url}
    U->>TAP: pay on hosted page (KNET / card)
    Note over TAP: INITIATED → CAPTURED
    TAP-->>U: HTTP redirect → redirect.url (checkout/callback)
    TAP->>WH: POST webhook (hashstring header)
    WH->>WH: HMAC-SHA256 hashstring verify (hash_equals)
    WH->>DB: find txn by gateway_transaction_id=chg_… (bind gateway=tap, dedupe event id)
    WH->>TAP: GET /v2/charges/chg_… (verifyPayment, fail-closed)
    WH->>PCS: completeLocked(txn, order, result) — inside DB::transaction, lockForUpdate(txn, order)
    PCS->>PCS: idempotency_key + order-pending + assertNoMismatch (amount/currency ×1000)
    PCS->>DB: txn=paid+paid_at; order payment_status=success; reservations commit; promotions finalize; changeOrderStatus(completed)
    PCS-->>WH: PaymentSucceeded (after commit)
    U->>CB: GET callback ?paymentId=chg_…
    CB->>DB: lock txn (gateway_transaction_id OR invoice_id) → order
    CB->>TAP: GET /v2/charges/chg_… (verifyPayment)
    CB->>PCS: completeLocked(...)
    Note over PCS: already completed → idempotent no-op
    PCS-->>U: web redirect → frontend /{locale}/payment/success
```

Both arrival paths (redirect callback and webhook) **race but converge** on `completeLocked`; exactly-once is enforced by the same mechanism proven by `PaymentConcurrencyTest::callback_and_webhook_race_completes_only_once` (analog: INFERRED for Tap; the completion service is gateway-agnostic — VERIFIED).

### 20.7 Existing vs proposed comparison

| Aspect | Today (MyFatoorah / Stripe / PayPal) | Proposed (Tap) |
|---|---|---|
| Registry entry | config + settings override | same, new `tap` key |
| Adapter | 3 classes in `App\Services\Gateway\` | new `TapGateway`, same contract |
| KWD support | MyFatoorah ✅ (3dp passthrough), Stripe ✅ (×1000), PayPal ❌ | Tap ✅ (3dp per DOC-FACT) |
| Completion path | callback (MF), callback + webhook (Stripe/PayPal) | callback + webhook, same completion service |
| Webhook verification | Stripe signature / PayPal headers / MF none | Tap hashstring HMAC-SHA256 |
| Idempotency | local idempotency_key; PayPal provider idempotency headers | local idempotency_key + `reference.idempotent` (24h, DOC-FACT) |
| Refunds | PaymentRefundService + adapter `refund()` | same via `TapGateway::refund()` |
| DB | generic `transactions` | unchanged |
| Statuses | MF InvoiceStatus `Paid`; Stripe session/PI; PayPal APPROVED/COMPLETED | Tap `CAPTURED` (per §19.4) |

### 20.8 Phased implementation plan

| Phase | Content | Risk | Reversibility |
|---|---|---|---|
| **0 — Prerequisites** | Merchant Tap account; confirm KNET enabled for the merchant (Q-M1); obtain sandbox `sk_test` key; public HTTPS host for `post.url` | — | — |
| **1 — Config only** | `config/payment.php` `tap` entry + `.env.example` keys; gateway disabled by default | none (inert) | delete entry |
| **2 — Adapter** | `TapGateway` with injected HTTP client seam; unit tests with mocked client (PayPal pattern) | none (unrouted until used) | delete class |
| **3 — Webhook route + verifier** | route + `TapWebhookController::tap()` + hashstring verification; tests: valid/invalid/replay/mismatch/unknown/gateway-disabled-completion | none (route inert without enabled gateway traffic) | remove route |
| **4 — Callback contract** | confirm/normalize redirect param (Q1); `PaymentRedirectContractTest` analog | low | revert param handling |
| **5 — Concurrency & idempotency tests** | callback-vs-webhook race analog; duplicate-payment hold analog | none | tests only |
| **6 — Sandbox E2E** | KNET test cards (§20.9); reconciliation dry-run; refund probes (Q3) | none | — |
| **7 — Production enablement** | flip via admin settings override (no deploy), monitor, keep MyFatoorah as default until Tap validated | operational | settings override off / `TAP_ENABLED=false` |

Each phase ships independently; no phase requires DB migration or touches the completion authority.

### 20.9 Test matrix (existing baseline → Tap additions)

Existing suite (existence **VERIFIED**; **NOT RUN** in this environment): 19 files in `tests/Feature/Payment/` — BypassGateTest, CatalogCurrencyAuthorityTest, CurrencyPrecisionTest (KWD ×1000 minor units), ExternalRefundTest, GatewayDisableTest, GatewayRegistryTest, GatewaySettingsAdminTest, MarvelStatusAuthorityTest, MarvelStatusReachabilityTest, PaymentCompletionTest (D-05, idempotent replay, mismatches, coupon-blocked), PaymentConcurrencyTest, PaymentRedirectContractTest, PaymentSecurityTest, PaymentWebhookTest, PayPalCaptureIdempotencyTest, PayPalGatewayTest, PublicPaymentGatewaysTest, RefundCurrencyAndLedgerTest, StripeFailureCorrelationTest, StripeGatewayTest — plus root-level: WebhookPaymentCompletionTest, PaymentCallbackStressTest, PaymentCheckoutTest, PaymentIdempotencyTest, PaymentProductionHardenTest, PaymentReconciliationTest, PaymentSystemTest, CheckoutConcurrencyStressTest, CheckoutPendingOrderRedesignTest, CheckoutRegressionTest, FinancialDeepAuditTest, FinancialVerificationTest, GatewayCurrencySupportTest + `tests/Feature/Currency/*`.

| # | Tap scenario | Test to add | Based on |
|---|---|---|---|
| T1 | createInvoice builds correct charge payload (3dp KWD, redirect/post urls, idempotent) | unit (mocked HTTP) | PayPalGatewayTest |
| T2 | verifyPayment completes only on CAPTURED | unit | StripeGatewayTest |
| T3 | verifyPayment fail-closed on non-CAPTURED (each §19.4 status) | unit | PaymentWebhookTest |
| T4 | webhook valid hashstring completes payment | feature | PaymentWebhookTest (stripe) |
| T5 | webhook invalid/tampered hashstring → 401, no completion | feature | PaymentSecurityTest |
| T6 | webhook replay same event id → dedupe | feature | PaymentWebhookTest |
| T7 | webhook for non-tap transaction → 400 | feature | completeFromProviderRef binding |
| T8 | webhook when tap disabled → in-flight still completes | feature | GatewayDisableTest |
| T9 | callback + webhook race → single completion | feature | PaymentConcurrencyTest |
| T10 | amount mismatch (echo ≠ order) → txn failed | feature | PaymentCompletionTest |
| T11 | currency mismatch → txn failed | feature | PaymentCompletionTest |
| T12 | KWD 3dp round-trip (10.500 → 10500) | unit | CurrencyPrecisionTest |
| T13 | refund happy path + fail-closed refund | feature | ExternalRefundTest / RefundCurrencyAndLedgerTest |
| T14 | duplicate payment on completed order → hold | feature | PaymentProductionHardenTest |
| T15 | zero-value order with tap selected → local completion | feature | PaymentCompletionTest D-05 |
| T16 | redirect contract param normalization (post-Q1) | feature | PaymentRedirectContractTest |

### 20.10 Sandbox testing guide (operational; DOC-FACT + placeholders)

**Principles:** sandbox uses the same base URL `https://api.tap.company/v2` with an `sk_test` secret key from the Tap Dashboard; secret keys backend-only; the webhook `post.url` must be publicly reachable (not localhost) — use a tunnel or an inspection service (e.g., webhook.site) while developing, then the real `/checkout/webhooks/tap` route. **No environment values are reproduced here.**

**A. Create a KNET charge (cURL, backend):**

```bash
curl -X POST https://api.tap.company/v2/charges \
  -H "Authorization: Bearer $TAP_SECRET_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "amount": 10.500,
    "currency": "KWD",
    "customer": {"first_name": "Test", "email": "test@example.com"},
    "source": {"id": "src_kw.knet"},
    "redirect": {"url": "https://<public-host>/api/v1/general/checkout/callback"},
    "post":    {"url": "https://<public-host>/api/v1/general/checkout/webhooks/tap"},
    "reference": {"idempotent": "txn-<local-id>-attempt-1"},
    "metadata": {"order_id": "<local-order-id>"}
  }'
```

Expect `status: INITIATED` and `transaction.url` → open in a browser, complete payment with the KNET test card below.

**B. Retrieve the charge (backend):**

```bash
curl https://api.tap.company/v2/charges/chg_<id> -H "Authorization: Bearer $TAP_SECRET_KEY"
```

Expect `status: CAPTURED` after a successful KNET payment; this is exactly what `TapGateway::verifyPayment` does.

**C. KNET test cards (official docs):**

| Card | Expiry | PIN | Expected result |
|---|---|---|---|
| 8888880000000001 (or …02) | 09/30 | 1234 | CAPTURED |
| 8888880000000001 | 05/21 | 1234 | NOT CAPTURED |
| — select **KNET1** in the Bank dropdown on the hosted page — | | | |

Expiry-driven outcome matrix (official docs): 01/39 → APPROVED · 05/22 → DECLINED · 04/27 → EXPIRED_CARD · 08/28 → TIMED_OUT · 01/37 → ACQUIRER_SYSTEM_ERROR · 02/37 → UNSPECIFIED_FAILURE · 05/37 → UNKNOWN. CSC codes: 100 → MATCH · 101 → NOT_PROCESSED · 102 → NO_MATCH. Benefit/Naps and MC/VISA/AMEX/Mada test cards are listed on the official test-cards documentation (card numbers intentionally not reproduced here beyond the KNET pair recorded from fetched pages).

**D. Webhook validation:**

1. Point `post.url` at an inspection endpoint (webhook.site) first to capture the exact payload shape and confirm which fields populate the hashstring (Q2/Q7).
2. Re-compute HMAC-SHA256 locally with the Secret API Key over the documented concatenation and compare with the `hashstring` header before wiring the verifier.
3. Negative tests: tampered payload, wrong key, replayed payload — all must be rejected by the future verifier (maps to T5/T6).
4. Then point `post.url` at the real route and repeat T4.

**E. Postman:** build a small collection with the two requests above using a `{{TAP_SECRET_KEY}}` variable set from the local environment (never committed); run against sandbox.

**F. End-to-end through the app:** checkout with `gateway=tap`, KWD order, complete with test card 8888880000000001 (09/30) → assert locally: transaction `paid`, order `completed`, payment_status `success`, inventory `committed`, coupon claim `redeemed`, `PaymentSucceeded` side effects, redirect to `/{locale}/payment/success`.

### 20.11 Rollback plan

| Trigger | Action | Effect |
|---|---|---|
| Tap malfunction / incident | set `TAP_ENABLED=false` (env) or admin settings override (`enabled=false`) — no deploy | new initiations blocked (422); **in-flight Tap payments still complete** because canVerify ignores `enabled` **[VERIFIED registry semantics]** |
| Webhook misbehaving | webhook route keeps failing closed (hashstring 401); callbacks still work | no data risk |
| Full withdrawal | remove config entry + adapter + route | zero DB footprint — **no migration to roll back** **[VERIFIED design]** |
| Data cleanup | duplicate-payment / drift records in `payment_reconciliation_results` remain auditable | detect-only design leaves an audit trail |

### 20.12 Monitoring plan

- **Reconciliation:** once the adapter exists with `gateway_transaction_id` populated, `payments:reconcile` covers Tap automatically — drift in amount/currency/payment_status/order_status is recorded in `payment_reconciliation_results`. **[VERIFIED mechanism; INFERRED coverage via generic job]**
- **Alerts:** reconcile rows for `tap`; `duplicate_payment` holds; webhook 401 rate (tampering or key rotation); 429s on the webhook route (throttle 20/min — correlate with Tap retry cadence, Q6).
- **Dashboards:** transaction counts by status for `payment_method='tap'`; pending transactions older than the charge expiry window (5–60 min DOC-FACT) flagged for the `orders:cancel-unpaid` sweep.
- **Logs:** provider correlation via `metadata.order_id` / `udf` fields; keep `RESPONSE_ALLOWLIST` sanitization so stored payloads stay PII-free.

### 20.13 Open questions — merchant & Tap support

| # | Question | Why it matters |
|---|---|---|
| Q-M1 | Is **KNET enabled for this merchant account** (and is live KWD capability confirmed)? | KNET is the primary Kuwaiti rail; without it Tap adds little over MyFatoorah |
| Q-M2 | Which Tap methods should the hosted page expose (KNET only, or KNET+mada+cards)? | drives `source.id` choice (`src_kw.knet` vs `src_all`) |
| Q-T1 | Redirect contract back to `redirect.url` (param names; failure redirect behavior) | callback integration (Q1 above) |
| Q-T2 | hashstring: algorithm version + `hashstring` vs `hash` precedence | webhook verifier correctness |
| Q-T3 | Refund `amount` int32 schema vs 3-decimal doc text for KWD | KWD refunds |
| Q-T4 | `ABANDONED` / `RESTRICTED` recommended handling | status mapping (§19.4) |
| Q-T5 | Webhook retry cadence/backoff | rate-limit sizing |
| Q-T6 | Webhook payload coverage (all transitions vs terminal only) | state-sync fidelity |
| Q-T7 | `reference.idempotent` scoping guarantees per charge/attempt | checkout retry semantics |

### 20.14 Final readiness assessment

| Dimension | Assessment |
|---|---|
| Existing payment flow architecture | **READY** — single completion authority, fail-closed verification, idempotency, consistent locking, detect-only reconciliation. Confidence: **HIGH** (static) / **MEDIUM** (runtime behavior — suite not executed here, §3) |
| Tap integration feasibility | **READY TO IMPLEMENT** — purely additive; contract-compatible; KWD/3dp compatible with the local amount model; no DB migration |
| Tap integration runtime readiness | **CONDITIONAL** — blocked on Q-T1..T-T7 confirmations and sandbox E2E |
| Overall recommendation | Proceed with Phases 1–5 immediately (inert, reversible); gate Phase 6–7 on the open questions |

---

## 21. Appendix: Files Inspected, Commands Executed, Test Results

### 21.1 Files inspected (static evidence base)

**Live payment architecture (`app/`)**

- `app/Http/Controllers/Api/General/OrderController.php` — checkout L142, completeZeroValueOnlineOrder L224, checkoutCallback L320, checkoutErrorCallback L620
- `app/Http/Controllers/Api/General/PaymentWebhookController.php` — MyFatoorah no-webhook comment L35; stripe/paypal handlers; completeFromProviderRef
- `app/Http/Controllers/Api/General/PaymentGatewayController.php`
- `app/Services/Payment/PaymentCompletionService.php`, `PaymentCompletionOutcome.php`, `PaymentCheckoutHandler.php`, `PaymentGatewayRegistry.php`, `PaymentGatewayFactory.php`, `GatewaySettingsService.php`, `PaymentCurrencyResolver.php`, `CurrencyPrecision.php` (L28/L33/L42/L47/L56), `StripeWebhookVerifier.php`, `PayPalWebhookVerifier.php`, `PaymentRefundService.php`, `CustomerContactResolver.php`, `Contracts/PaymentGatewayContract.php`
- `app/Services/Gateway/MyFatoorahGateway.php`, `StripeGateway.php`, `PayPalGateway.php`
- `app/DTOs/GatewayResult.php`
- `app/Services/General/OrderService.php` — addItemsInOrder L207, changeOrderStatus L946, markCodAsPaid L1537, markCashierPaid L1571
- `app/Services/Inventory/OrderReservationService.php`
- `app/Providers/AppServiceProvider.php` — rate limiters L145/L157; `app/Providers/EventServiceProvider.php`
- `app/Providers/RouteServiceProvider.php`, `app/Jobs/PaymentReconciliationJob.php`, `app/Console/Commands/*` (CancelUnpaidOrders, PaymentReconciliationCommand, ReconcileCouponState, ExpireCouponReservations, ExpireCouponClaims)
- `config/payment.php`, `config/services.php`, `routes/api.php`, `routes/console.php`, `bootstrap/app.php`, `composer.json`, `phpunit.xml`, `.env.example` (key names only)

**Marvel kernel (ownership checks)**

- `packages/marvel/src/Rest/Routes.php`, `packages/marvel/src/Providers/EventServiceProvider.php`, `packages/marvel/src/Database/Models/Transaction.php`, `packages/marvel/src/Database/Models/Order.php`, `packages/marvel/src/Enums/PaymentStatus.php`, `packages/marvel/src/Payments/*` (legacy), `packages/marvel/config/shop.php` (legacy stripe key names)

**Migrations cited:** idempotency_key unique (`2026_09_25_*`), payment_reconciliation_results (`2026_07_12_*`), amount → 3dp (`2026_09_27_*`), payment tracking backfill (`2026_07_08_000002`), order currency columns, unique pending-order constraint, order_status_flow tables.

**Tests catalogued (existence verified; not executed):** all files listed in §20.9.

**Documentation consulted:** `docs/audits/` (prior audit convention), `docs/architecture/` (runtime pricing / lifecycle references).

**External documentation (official Tap):** developers.tap.company — get-started, create-a-charge, charges overview, retrieve-a-charge, knet, webhook, idempotency, create-a-refund, authentication.

### 21.2 Commands executed (all sessions of this audit)

| Command | Result |
|---|---|
| `php artisan route:list --path=checkout` | **TESTED** — routes rendered (§5) |
| `php artisan route:list --path=checkout` (re-run 2026-10-27, output in session) | **TESTED** — 9 checkout-family routes incl. webhooks/stripe, webhooks/paypal |
| `php artisan route:list --path=orders` | **TESTED** — orders surface incl. Marvel `PATCH orders/{id}/status` |
| `php -v` (2026-10-27) | **TESTED** — PHP 8.2.30 (cli), ZTS x64 |
| `php artisan db:show` | **FAILED** — DB unreachable (SQLSTATE[HY000] [2002], connection actively refused) |
| MySQL connection attempt to configured host:port | **FAILED** — actively refused; no `mysql`/`mariadb` Windows service present |
| `docker ps` (fallback attempt) | **BLOCKED** — local shell allowlist rejected `docker` |
| `Get-ChildItem docs\audits` | **TESTED** — 9 existing inventory audit files; target report name free |
| Static inspections | file reads + greps as catalogued in §21.1 (no mutations) |

### 21.3 Test results

| Suite | Status |
|---|---|
| PHPUnit (payment feature tests) | **NOT RUN** — requires reachable MySQL; unavailable in this environment (§3). Existence of the behavioral contract is VERIFIED; passing status is UNKNOWN here. |
| Route existence assertions in this report | **TESTED** via `route:list` (§5) |
| Static analysis | none available in-project; not executed |

### 21.4 Unverified areas (explicit)

1. Runtime behavior of the entire payment suite (blocked, §3).
2. Tap sandbox/live behavior of every endpoint used in §20 (no network calls were made to Tap).
3. Tap redirect query-parameter contract (Q-T1).
4. Tap hashstring header precedence/versioning (Q-T2).
5. Tap refund amount decimal semantics for KWD (Q-T3).
6. `ABANDONED`/`RESTRICTED` recommended handling (Q-T4).
7. Tap webhook retry cadence (Q-T5) and payload coverage (Q-T6).
8. Merchant KNET/live capability (Q-M1/Q-M2).

---

*End of report. This audit created exactly one file: `docs/audits/payment-flow-and-tap-integration-audit.md`. No other repository content was created, modified, or deleted.*
