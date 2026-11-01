# Tap Payments Integration Audit — Discovery, Implementation & Final Results

| | |
|---|---|
| **Task** | Integrate Tap Payments as an optional, independently configurable gateway without breaking existing providers or workflows |
| **Repository** | `D:\work\meem` — Laravel 10.30.1 monolith (`app/` custom layer + `packages/marvel/` vendored kernel), PHP 8.2.30 |
| **Date** | 2026-10-27 |
| **Confidence legend** | **VERIFIED** = confirmed by inspected code/config/schema · **TESTED** = executed with recorded command+result · **INFERRED** = reasoned from verified evidence · **UNKNOWN** = insufficient evidence · **RISK** = confirmed/credible exposure |
| **Part 1** | §1–§14: read-only discovery + integration design (written before any code change) |
| **Part 2** | §15 (appended after implementation): implemented changes, executed commands, test results, security/concurrency findings, acceptance checklist, final readiness |

---

## Part 1 — Discovery & Design

## 1. Executive Summary

- The live payment architecture is config-driven and gateway-agnostic: `config/payment.php` defines gateways, `GatewaySettingsService` merges them with admin settings overrides, `PaymentGatewayRegistry` guards initiation, and `PaymentCompletionService::completeLocked()` is the **single canonical completion authority** used by every completion path (browser callback, Stripe webhook, PayPal webhook, zero-value, COD/cashier mark-paid). **[VERIFIED]**
- Adding Tap requires: one config entry, one adapter (`App\Services\Gateway\TapGateway` implementing `PaymentGatewayContract`), one webhook verifier (`TapWebhookVerifier` — hashstring HMAC-SHA256 per official docs), one webhook controller method + route, and a **minimal callback query-param fallback** because Tap's redirect carries `tap_id` (DOC-FACT) while the existing callback reads `paymentId`. **No database migration, no new translation keys, no interface binding changes.** **[VERIFIED design feasibility]**
- **Currency is never hardcoded**: the adapter derives the charge currency from `PaymentCurrencyResolver::forOrder($order)` (order snapshot `catalog_currency_code` → `currency_code` → catalog currency), validates against the config `supported_currencies` list, and formats the amount at the currency's ISO exponent via `CurrencyPrecision` (3dp for BHD/JOD/KWD/OMR/TND, 2dp otherwise — exactly matching Tap's own rounding table). **[VERIFIED local; DOC-FACT Tap]**
- Tap is **disabled by default** (`TAP_ENABLED=false`); enabling it touches nothing else; missing credentials fail closed at `isConfigured()`/`canInitiate()` with 422 and no provider call. **[VERIFIED pattern]**
- Runtime verification constraints: **MySQL is unreachable in this environment** (connection actively refused; the repo config explicitly forbids re-adding sqlite/pg connections — `config/database.php` L38-40 comment). DB-backed feature tests are **NOT RUN**; DB-free unit tests **are executed**. **[VERIFIED limitation]**

## 2. Verified Technology Stack & Repository Architecture

| Item | Value | Label |
|---|---|---|
| Laravel | 10.30.1 (`composer.lock`) | VERIFIED |
| PHP runtime | 8.2.30 (cli, ZTS) — `php -v` executed | TESTED |
| Old-style bootstrap | `bootstrap/app.php` with legacy HTTP/kernel wiring (Laravel 10 style) | VERIFIED |
| DB | MySQL-only canonical connection `catch`; sqlite/sqlsrv/pgsql stanzas deliberately absent (`config/database.php` L38-40 comment: "Do not re-add sqlite/sqlsrv/pgsql stanzas") | VERIFIED |
| HTTP client | Laravel `Http::` facade (`MyfatoraService` L31-33: `withHeaders()->acceptJson()->timeout(30)`) | VERIFIED |
| Payment SDKs | stripe/stripe-php ^13.1, srmklive/paypal ^3.0 | VERIFIED |
| Queue/infra | Redis/Predis, Scout+Meilisearch, Telescope; RabbitMQ removed and must not be reintroduced | VERIFIED |
| Tests | PHPUnit 10; `phpunit.xml` APP_ENV=testing, non-forced `DB_CONNECTION=mysql`/`DB_DATABASE=catch`, QUEUE_CONNECTION=sync | VERIFIED |
| Legacy namespace | `packages/marvel/src/Payments/*` unrouted (dead code) — no routes reference WebHookController/CheckoutController/PaymentIntentController | VERIFIED |

## 3. Existing Payment-Provider Architecture

### 3.1 Registry & config (all `app/Services/Payment/` unless noted)

| Class | Role | Key lines |
|---|---|---|
| `Contracts\PaymentGatewayContract` | Adapter contract: `createInvoice(Order, float, callbackUrl, errorUrl, metadata): GatewayResult`, `verifyPayment(string): GatewayResult`, `refund(Order, float, ?reason): GatewayResult`, `name/code/isConfigured/supportsCurrency` | whole file |
| `PaymentGatewayRegistry` | `resolve()` instantiates `definition['class']` via `app($class)`; `canInitiate()` = enabled + class exists + `isConfigured()` + method allowed + currency supported (fail-closed, reason-coded); `canVerify()` deliberately ignores `enabled` | L28-44, L49-106, L108-119 |
| `PaymentGatewayFactory` | Thin seam over registry; verify path bypasses enable gates by design | L27-30 |
| `GatewaySettingsService` | Merges `config('payment.gateways')` + `settings.options['payment_gateways']` overrides; read allowlist `{enabled, display_name, sort_order}` only (L243-249) — secrets/classes/currencies/methods stay env-only; `getAdminView()` never exposes secrets; `updateOverride()` write allowlist | L228-254, L66-106, L156-223 |
| `PaymentCheckoutHandler` | `handleOnlinePayment()`: canInitiate gate → 422 translations; double `supportsCurrency` check; coupon reservation BEFORE invoice (Rule 9/CP-09); `createInvoice`; on exception/failure release coupon reservation; creates `Transaction(status=pending, invoice_id=gatewayTransactionId, gateway_transaction_id=…, gateway_response+_callback_type)`; returns `{url: redirectUrl}` | L28-140 |
| `PaymentCompletionService` | Canonical completion (see §5) | L48-103, L157-264, L272-310 |
| `PaymentCurrencyResolver` | Catalog currency authority: order snapshot `catalog_currency_code` → `currency_code` → `CurrencyService::getCatalogCode()` → config `payment.default_currency` | L26-49 |
| `CurrencyPrecision` | ISO exponent authority: 3dp set BHD/JOD/KWD/OMR/TND; `decimalsFor/roundForCurrency/toMinorUnits/fromMinorUnits/formatForGateway` | L24-59 |
| `StripeWebhookVerifier` / `PayPalWebhookVerifier` | SDK-backed signature verification seams (test-injectable) | whole files |

### 3.2 Adapters (`app/Services/Gateway/`)

| Adapter | Initiation | Verification | Refund | Notable |
|---|---|---|---|---|
| `MyFatoorahGateway` | SendPayment → `Data.InvoiceURL`+`Data.InvoiceId`; response sanitized via `RESPONSE_ALLOWLIST` (L19-29) | checkInvoice; success **only** `InvoiceStatus==='Paid'` (L130) | MakeRefund; fail-closed via `isRefundConfirmed()` regex (L242-261) | callback-only (no webhook by design) |
| `StripeGateway` | Checkout Session; minor-unit conversion via `CurrencyPrecision` + Stripe zero-decimal list (L35-38); `{CHECKOUT_SESSION_ID}` success_url templating | retrieve session + cross-check PaymentIntent amount/currency (L193-227) | refunds.create; success **only** `status==='succeeded'` (L329); `sk_` redaction in errors (L526-535) | PII-free allowlists |
| `PayPalGateway` | Orders v2 create (CAPTURE); `PayPal-Request-Id` idempotency; `formatForGateway` amount string (L117) | showOrderDetails → APPROVED → idempotent capture (`capture-{id}`) + already-captured race re-fetch (L197-223) | refundCapturedPayment; success **only** `COMPLETED` (L337) | injected client-factory seam for tests (L78-84, L397-430) |

### 3.3 Settings/features/config

- `config/payment.php`: `default_gateway` env `DEFAULT_PAYMENT_GATEWAY` (default `myfatoorah`), `default_currency` env `DEFAULT_CURRENCY`, order timeout hours, `test_gateway_bypass_enabled` env `PAYMENT_TEST_GATEWAY_BYPASS` (default false, honored only in local/testing), gateway blocks `myfatoorah` (default enabled), `stripe` (default disabled), `paypal` (default disabled) — each with `class`, `enabled`, secrets, `supported_currencies` (env lists), `methods: ['online']`. **[VERIFIED]**
- `.env.example` documents key names (MYFATOORAH_* , STRIPE_* incl. legacy STRIPE_API_KEY read only by Marvel shop config, PAYPAL_* incl. legacy blocks). **No `TAP_*` keys exist.** **[VERIFIED]**
- Admin settings path: `GatewaySettingsService::updateOverride()` + admin routes `api/v1/admin/payment-gateways` (index/update) — enables/disables a gateway without deploy; cannot inject secrets. **[VERIFIED]**
- Public surface: `GET api/v1/general/payment-gateways` → `{code, display_name, supported_currencies, supports_catalog_currency}` per gateway. **[VERIFIED]**

## 4. Actual Current Payment Flow (file paths + line references)

1. **Checkout entry**: `POST api/v1/general/checkout` → `App\Http\Controllers\Api\General\OrderController::checkout` (L142; route `routes/api.php` L150) with `Marvel\Http\Requests\OrderCreateRequest` (`payment_method` in `online,cod,pay_at_cashier`; `gateway` nullable string max 50). **[VERIFIED]**
2. **Order creation**: `OrderService::addItemsInOrder` (L207) — DB transaction, cart `lockForUpdate`, coupon revalidation, `calculateCheckoutTotals`, pending-order reuse/supersede, `OrderReservationService::reserveForOrder`, cart slice clear. **[VERIFIED]**
3. **Online dispatch**: `checkout` rounds the total to the order-currency exponent (`CurrencyPrecision::roundForCurrency`, L189-192); zero/`<=0` → local completion D-05 (`completeZeroValueOnlineOrder` L224 — paid txn with `gateway_response ['zero_value'=>true]`, `changeOrderStatus('completed')`, no gateway call); else `PaymentCheckoutHandler::handleOnlinePayment` (L28). **[VERIFIED]**
4. **Gateway call** (per adapter, §3.2): `createInvoice` → `GatewayResult{redirectUrl, gatewayTransactionId, amount, currency}`; pending `Transaction` row created by the handler (L123-133) with `payment_method = gateway code`. **[VERIFIED]**
5. **Redirect**: customer pays on the provider page; provider redirects to `api.checkout.callback` (routes/api.php L131; `throttle:payment-callback` 20/min).
6. **Callback**: `OrderController::checkoutCallback` (L320): `paymentId` regex `^[A-Za-z0-9\-_]+$` ≤191 (L326); gateway name = transaction `payment_method` with fallback default `'myfatoorah'` (L334-340); `verifyPayment` (fail-closed on failure, L374-417); unknown-order fail-safe B4 (L421-439); `DB::transaction` + `lockForUpdate` on txn (by `gateway_transaction_id` OR `invoice_id`, L452-462) then order (L468) → `PaymentCompletionService::completeLocked` (L471-476); `PaymentMismatchException` → failed in-lock (L477-499); `CouponConsumptionException` → token rotation + `_coupon_blocked` markers (L510-565); `Processed` → `PaymentSucceeded` after commit (L589-601); mobile → JSON, web → redirect `app_url_frontend/{locale}/payment/success|failed` (L603-616). **[VERIFIED]**
7. **Webhooks**: `PaymentWebhookController` (Stripe L68, PayPal L166): raw-body/headers → verifier → `completeFromProviderRef` (L275-495): probe `findTxnByRef` (L881-895), gateway-binding check (payment_method must match, L293-301), `_webhook_event_ids` dedupe cap 20 (L52-54, L929-968), adapter re-verification (L336), locked completion, event ids re-attached post-completion, `PaymentSucceeded` on completed, mismatch → 200 fail-ack, unknown → 200 ignored. PayPal DENIED → `markFailedByProviderRef`; REFUNDED/REVERSED → `recordPayPalExternalRefund` (L780-875). **[VERIFIED]**
8. **Completion authority**: see §5.
9. **Side effects**: inventory `OrderReservationService` commit (exactly-once locked claims), promotions finalize, coupon usage record (fail-closed), invoice generation on first transition away from pending, notifications/timeline listeners (EventServiceProvider wiring verified). **[VERIFIED]**
10. **Refunds**: `PaymentRefundService::refund()` — DB transaction, **Transaction→Order lock order** (MySQL 1213 fix, L76-86), idempotent ledger replay (`gateway_response['_refunds']`, L127-137), per-transaction authorization against the **transaction's original currency** (L101-121), adapter `refund()` fail-closed, ledger append, full refund → order `payment_status = payment-refunded` + side effects. **[VERIFIED]**

## 5. Authoritative Components (per task §2B)

| Value/transition | Authority | Evidence |
|---|---|---|
| Checkout totals | `OrderService::addItemsInOrder` + `calculateCheckoutTotals` (centralized; pricing must not be recomputed in controllers) | OrderService L207+ |
| Final order amount | `order.total_price` (rounded via `CurrencyPrecision::roundForCurrency` at checkout, L189-192) | OrderController |
| Currency | `PaymentCurrencyResolver::forOrder($order)` — order snapshot (`catalog_currency_code` → `currency_code`) → catalog currency → config default | PaymentCurrencyResolver L26-49 |
| Currency precision | `CurrencyPrecision` (single authority; Stripe zero-decimal override intentionally local to StripeGateway) | CurrencyPrecision L7-17 |
| Payment initiation gate | `PaymentGatewayRegistry::canInitiate` (+ handler double-check) | Registry L49-106; Handler L36-78 |
| Gateway call | Adapter `createInvoice` via `PaymentGatewayFactory` | Factory L27-30 |
| Transaction persistence | `PaymentCheckoutHandler` creates the single pending row | Handler L123-133 |
| Browser callback verification | `checkoutCallback` → adapter `verifyPayment` (server-side re-fetch; redirect is untrusted) | OrderController L320-417 |
| Webhook verification | `StripeWebhookVerifier`/`PayPalWebhookVerifier` + adapter re-verification | WebhookController |
| **Payment success/completion** | `PaymentCompletionService::completeLocked` (idempotency token → order-pending → `assertNoMismatch` ×1000 → `commitLocked`) | PaymentCompletionService L48-103 |
| Order status transition | `OrderService::changeOrderStatus` (L946) — Order Flow fail-closed; F-1 `payments.mark_paid` gate for `completed` on UNPAID unless `assertPaymentAuthority=false` | OrderService |
| Inventory | `OrderReservationService` (none→active→committed/released; exactly-once claims) | Inventory service |
| Refunds | `PaymentRefundService` (+ adapter `refund()`) | PaymentRefundService |

## 6. Database Tables, Models, Migrations & Relationships

- `transactions` (`Marvel\Database\Models\Transaction`): fillable `order_id, invoice_id, payment_method, user_id, uuid, status, amount, currency, gateway_transaction_id, gateway_response, error_message, qr_code_url, paid_at, idempotency_key`; casts `gateway_response` array, `paid_at` datetime, `amount` float; uuid on creating; scopes pending/paid/failed; belongsTo Order. **[VERIFIED]**
- Status values used: `pending` → `paid` | `failed` | `partially_refunded` | `refunded` (refund ledger). **[VERIFIED]**
- `orders` (`Marvel\Database\Models\Order`): `ORDER_STATUS_*` pending/processing/completed/cancelled/delivered; `PAYMENT_STATUS_*` payment-pending/success/failed/refunded; `INVENTORY_STATE_*`; currency snapshot columns `currency_code` + `catalog_currency_code`. **[VERIFIED]**
- `payment_reconciliation_results` (`App\Models\PaymentReconciliationResult`): reconciliation findings incl. `duplicate_payment` holds. **[VERIFIED]**
- Migrations of record: `idempotency_key` unique (`2026_09_25_*`), amount widened to 3dp (`2026_09_27_*`), payment tracking backfill (`2026_07_08_000002`), reconciliation table (`2026_07_12_*`), order currency columns, unique pending-order constraint, order_status_flow tables. **[VERIFIED]**
- **Tap needs no schema change**: charge id → `gateway_transaction_id`/`invoice_id` (varchar ≤191; Tap ids like `chg_TS020420211019Ja242609987` fit), webhook dedupe → `gateway_response._webhook_event_ids`, refund ledger → `gateway_response._refunds`. **[VERIFIED]**

## 7. Existing Routes, Callbacks, Webhooks, Jobs & Tests

Routes (all re-verified via `php artisan route:list`, §15.4): checkout POST, checkout/callback GET+POST (`api.checkout.callback`, L131), error-callback (L132), webhooks stripe/paypal (L136-137, `throttle:payment-webhook`), payment-gateways public (L119), admin payment-gateways, cod/cashier mark-paid (permission `payments.mark_paid`, L157-158). Rate limiters: payment-callback/payment-webhook 20/min per IP (`AppServiceProvider` L145/L157). Jobs: `PaymentReconciliationJob` (detect-only; iterates all txns with `gateway_transaction_id`, resolves gateway by `payment_method` — a new gateway is covered automatically); scheduler: `orders:cancel-unpaid` (5 min), `coupons:*`, `fulfillment:release-ready`. Tests: 19 payment feature tests + root-level payment tests + Currency suite (existence VERIFIED; **NOT RUN** here — DB blocker). **[VERIFIED]**

## 8. Currency Configuration & Compatibility Findings

- **App currency model**: catalog currency authority (`CurrencyService::getCatalogCode()`, env `DEFAULT_CURRENCY` default `KWD`), per-order snapshot columns, historical orders preserve currency; per-customer selection exists as an admin/catalog concern — the payment path always derives from the order. **No currency is hardcoded on the payment path**; `config('payment.default_currency')` default is only the last-resort fallback inside the resolver. **[VERIFIED]**
- **Precision**: `CurrencyPrecision` — 3dp: BHD/JOD/KWD/OMR/TND; 2dp: everything else on this path. Snapshots, gateway payloads, refunds share this table. **[VERIFIED]**
- **Gateway currency restrictions**: per-gateway env lists (MyFatoorah default KWD,SAR,AED,BHD,QAR,OMR,EGP; Stripe USD,EUR,KWD,SAR,AED; PayPal USD,EUR — no KWD). Checkout blocks unsupported order currencies with 422 `PAYMENT_CURRENCY_UNSUPPORTED` (parameterized translation, en+ar present). **[VERIFIED]**
- **Tap currency compatibility (official docs)**: supported currencies per Tap support article — AED, BHD, EUR, GBP, KWD, OMR, QAR, SAR, USD enumerated (article states "10 currencies" but lists 9 in visible text; the 10th is not identified in the fetched page). Tap's **webhook rounding table** additionally defines exponents for **EGP (2.00)** and **JOD (3.000)** — 11 currencies total with explicit exponents. Amounts: charge `amount` is a decimal **number** "in ISO standard decimal places", minimum 0.100; refunds: BHD/KWD/OMR "up to 3 decimal places", others 2. **[DOC-FACT]**
- **Tap exponent table vs `CurrencyPrecision`**: identical (3dp set is a superset intersection; every currency Tap lists gets the same exponent from `decimalsFor`). **[VERIFIED]**
- **Merchant-account enablement**: docs confirm platform-supported currencies but NOT which currencies a specific merchant account has enabled — multi-currency must be requested from Tap. Unresolvable from docs; fails safely at charge creation if unsupported. **[UNKNOWN → fail-safe by design]**

## 9. Tap Integration Design & Exact Extension Points

### 9.1 Extension points (all existing, no new abstractions)

| # | Extension point | File | Change |
|---|---|---|---|
| 1 | Gateway definition | `config/payment.php` | New `tap` block (disabled by default) |
| 2 | Adapter | `app/Services/Gateway/TapGateway.php` (new) | Implements `PaymentGatewayContract`; registry auto-discovers via `class` key — no factory/registry code change |
| 3 | Webhook verifier | `app/Services/Payment/TapWebhookVerifier.php` (new) | hashstring HMAC-SHA256 (official algorithm, §9.3) |
| 4 | Webhook endpoint | `app/Http/Controllers/Api/General/PaymentWebhookController.php` + `routes/api.php` | New `tap()` method mirroring stripe/paypal handlers + route `checkout/webhooks/tap` (`throttle:payment-webhook`) |
| 5 | Callback param | `app/Http/Controllers/Api/General/OrderController.php` (`checkoutCallback` L322, `checkoutErrorCallback` L622) | `tap_id` query fallback (Tap redirect contract, DOC-FACT); `paymentId` behavior byte-identical for existing gateways |
| 6 | Env docs | `.env.example` | `TAP_*` key names with defaults/comments |
| 7 | Tests | `tests/Unit/TapWebhookVerifierTest.php`, `tests/Unit/TapGatewayTest.php` (runnable now), `tests/Feature/Payment/TapWebhookTest.php`, `tests/Feature/Payment/TapGatewayFlowTest.php` (run when DB available) | See §13 |

### 9.2 Charge creation (DOC-FACT: POST https://api.tap.company/v2/charges, Bearer secret)

Required fields per official schema: `amount` (number, ISO decimal places, min 0.100), `currency` (uppercase 3-letter), `customer` (`first_name`, `email` required), `source` (`id`), `redirect` (`url`). Optional used: `post.url` (webhook), `reference.order` (merchant order ref), `reference.idempotent` (24h idempotency — official doc recommends per-order constancy: "The idempotent string should be consistent for the same order"), `metadata` (non-sensitive ids), `threeDSecure` (doc default true).

Adapter payload design:

```php
[
  'amount'        => CurrencyPrecision::roundForCurrency($amount, $currency), // number, exponent of currency
  'currency'      => $currency,                    // from PaymentCurrencyResolver::forOrder — never hardcoded
  'customer'      => ['first_name' => …, 'email' => CustomerContactResolver::emailForGateway($order), 'phone' => …],
  'source'        => ['id' => config('payment.gateways.tap.source_id', 'src_all')], // hosted page: all merchant-enabled methods
  'redirect'      => ['url' => $callbackUrl],      // api.checkout.callback (absolute, route())
  'post'          => ['url' => route('api.checkout.webhooks.tap')],
  'reference'     => ['order' => (string) $order->id, 'idempotent' => 'order-'.$order->id],
  'metadata'      => ['order_id' => (string) $order->id],   // no PII
  'threeDSecure'  => true,
]
```

Response handling: success → `id` (chg_…) + `transaction.url` → `GatewayResult{redirectUrl: transaction.url, gatewayTransactionId: id, amount, currency, status: 'pending', rawResponse: sanitized}`; any failure shape → fail-closed `GatewayResult` (handler releases coupon reservation, order stays pending, no txn row). **Idempotent-replay note:** a retried checkout on the same order re-sends the same `reference.idempotent` within 24h → Tap returns the original response (no duplicate charge). If the original charge expired/was terminal-failed, the user sees the failure landing path; the order is eventually swept by `orders:cancel-unpaid` and a new order gets a fresh idempotency scope. **[INFERRED from DOC-FACT idempotency semantics]**

### 9.3 Webhook verification (DOC-FACT, official `developers.tap.company/docs/webhook`)

Tap POSTs the charge object raw to `post.url` (agent `Tap-Webhooks/1.0`; cannot be localhost) with **two headers**: `hashstring` and `hash`. Verification (official PHP sample, reproduced verbatim structure):

```
toBeHashed = 'x_id'.$id.'x_amount'.$amount.'x_currency'.$currency
           .'x_gateway_reference'.$gateway_reference      // reference.gateway, empty string if absent
           .'x_payment_reference'.$payment_reference      // reference.payment
           .'x_status'.$status.'x_created'.$created       // transaction.created
hash = hash_hmac('sha256', toBeHashed, <Secret API Key>)
```

with **amount rounded to the currency's ISO exponent as an additional step** (AED 2.00, BHD 3.000, KWD 3.000, OMR 3.000, QAR 2.00, SAR 2.00, USD 2.00, EUR 2.00, GBP 2.00, EGP 2.00, JOD 3.000) — matches `CurrencyPrecision::decimalsFor` for every listed currency. Implementation compares with `hash_equals` (timing-safe; the doc sample uses `==`, upgraded deliberately). The secret is the **same Secret API Key** used for charges (per doc) — a separate webhook-secret config key is intentionally NOT introduced (misconfiguration hazard). **[DOC-FACT]**

### 9.4 Status mapping (DOC-FACT statuses: INITIATED, ABANDONED, CANCELLED, FAILED, DECLINED, RESTRICTED, CAPTURED, VOID, TIMEDOUT, UNKNOWN; per charges doc: CAPTURED = charged; all other listed = "Payment failed")

| Tap status (webhook/verify) | Local action |
|---|---|
| `CAPTURED` | adapter `verifyPayment` re-fetch → `completeFromProviderRef` → `completeLocked` (only this completes money) |
| `FAILED`, `DECLINED`, `CANCELLED`, `TIMEDOUT`, `ABANDONED`, `RESTRICTED`, `VOID`, `UNKNOWN` | `markFailedByProviderRef` → pending txn → `failed`, `PaymentFailed` event (fail-closed; a later CAPTURED webhook can still complete since the failure path preserves the null idempotency token and `completeLocked` gates on the token, not the status) |
| `INITIATED` (webhook) | 200 ignored — not a terminal state; order pending until callback/webhook/timeout sweep |

`verifyPayment` success is **exclusively** `status === 'CAPTURED'` after a server-side `GET /v2/charges/{id}` re-fetch — redirect params (`tap_id`, `redirect.status`) are never trusted as proof of payment. **[VERIFIED implementation rule from DOC-FACT]**

### 9.5 Redirect contract (DOC-FACT: redirect docs)

After payment, the user is redirected to `redirect.url` **with a `tap_id` query parameter** (charge id). The existing callback validates `paymentId` with `^[A-Za-z0-9\-_]+$` ≤191 — Tap ids (`chg_…`) satisfy it. Minimal change: accept `tap_id` as a fallback source before the regex validation, preserving existing-gateway behavior exactly.

### 9.6 Refunds

Through the existing `PaymentRefundService` → `TapGateway::refund()`: POST `/v2/refunds` with `charge_id` (latest paid txn's `gateway_transaction_id`), `amount` (rounded at currency exponent), `currency` (transaction's original currency — resolver + txn check), `reason` (API accepts documented values `duplicate|fraudulent|requested_by_customer` — free-text admin reason is preserved in the local ledger/metadata and the API reason is mapped to `requested_by_customer`), fail-closed on anything other than response `status === 'REFUNDED'`. Over-refund/duplicate protection, currency authorization, and ledger recording are already enforced by `PaymentRefundService` (partial ledger, `remainingMinor` math). **Limitation documented:** provider-initiated Tap-dashboard refunds are not auto-recorded (no refund webhook configured in v1; the refund endpoint's optional `post.url` is intentionally not pointed at the local webhook route in v1 — a future enhancement would add a refund-event branch).

### 9.7 Proposed end-to-end sequence (post-implementation flow)

```mermaid
sequenceDiagram
    autonumber
    participant U as Customer
    participant CO as POST checkout (gateway=tap)
    participant OH as PaymentCheckoutHandler
    participant TG as TapGateway
    participant TAP as Tap API
    participant DB as transactions/orders
    participant CB as checkout/callback
    participant WH as checkout/webhooks/tap
    participant PCS as PaymentCompletionService

    U->>CO: checkout
    CO->>OH: handleOnlinePayment(order, amount=roundForCurrency(total, orderCurrency))
    OH->>OH: registry.canInitiate (enabled+configured+online+currency)
    OH->>TG: createInvoice(order, amount, callbackUrl, errorUrl)
    TG->>TAP: POST /v2/charges (Bearer, amount ISO-decimals, currency=orderCurrency, source.id, redirect.url=callback, post.url=webhook, reference.idempotent=order-{id})
    TAP-->>TG: INITIATED {id=chg_…, transaction.url}
    TG-->>OH: GatewayResult{redirectUrl, gatewayTransactionId}
    OH->>DB: Transaction(pending, invoice_id=chg_…, gateway_transaction_id=chg_…, currency=orderCurrency)
    OH-->>U: {url: transaction.url}
    U->>TAP: pay on hosted page
    Note over TAP: INITIATED → CAPTURED
    TAP-->>U: redirect → redirect.url?tap_id=chg_…
    TAP->>WH: POST raw charge object (hashstring/hash headers)
    WH->>WH: HMAC-SHA256 hashstring verify (hash_equals)
    WH->>DB: probe txn by chg_… (payment_method must be tap)
    WH->>TAP: GET /v2/charges/chg_… (verifyPayment — CAPTURED only)
    WH->>PCS: completeLocked (DB::transaction + lockForUpdate txn→order)
    PCS->>DB: txn paid+paid_at; order payment_status=success; reservations commit; promotions finalize; changeOrderStatus(completed)
    PCS-->>WH: PaymentSucceeded (after commit)
    U->>CB: GET callback ?tap_id=chg_…
    CB->>TAP: GET /v2/charges/chg_…
    CB->>PCS: completeLocked → idempotent replay (already completed)
    PCS-->>U: redirect /payment/success
```

## 10. Proposed Configuration & Environment Variables

`config/payment.php` new block (mirrors existing structure; disabled by default):

```php
'tap' => [
    'class' => 'App\Services\Gateway\TapGateway',
    'enabled' => env('TAP_ENABLED', false),
    'secret_key' => env('TAP_SECRET_KEY'),
    'base_url' => env('TAP_BASE_URL', 'https://api.tap.company/v2'),
    'source_id' => env('TAP_SOURCE_ID', 'src_all'),
    'supported_currencies' => array_values(array_filter(array_map(
        'strtoupper',
        explode(',', (string) env('TAP_SUPPORTED_CURRENCIES', 'AED,BHD,EGP,EUR,GBP,JOD,KWD,OMR,QAR,SAR,USD'))
    ))),
    'methods' => ['online'],
],
```

`.env.example` key **names** (values are deployment secrets, never committed): `TAP_ENABLED`, `TAP_SECRET_KEY`, `TAP_BASE_URL`, `TAP_SOURCE_ID`, `TAP_SUPPORTED_CURRENCIES`.

- **Test/live separation**: mode follows the credential itself — Tap sandbox keys are `sk_test…`, live keys are `sk_live…` (DOC-FACT); the SAME `TAP_SECRET_KEY` slot serves both, so test/live mixing is prevented by key prefix discipline, and production deployments set only the live key. (Same single-slot convention as `MYFATOORAH_API_KEY`/`STRIPE_SECRET_KEY` in this repo.) `TAP_BASE_URL` stays at the canonical `https://api.tap.company/v2` for both modes (DOC-FACT: same base for test keys).
- Default `supported_currencies` is the provider-capability list from official docs (§8) — **not** an app-currency hardcode; deployment narrows it via env (e.g. `KWD` alone).

## 11. Required Code, Database & API Changes

| Type | Change | Necessary? |
|---|---|---|
| Code (new) | `TapGateway`, `TapWebhookVerifier`, `PaymentWebhookController::tap()` | Yes |
| Code (edit, minimal) | `OrderController::checkoutCallback/checkoutErrorCallback`: accept `tap_id` fallback param | Yes (Tap redirect contract) |
| Config | `config/payment.php` tap block; `.env.example` | Yes |
| Routes | `checkout/webhooks/tap` POST | Yes |
| Database/migrations | — | **No** (generic transactions schema suffices) |
| API surface | checkout response contract unchanged (`{url}`); public/admin gateway lists auto-include `tap` via registry | No breaking changes |
| Translations | none new (reuses `PAYMENT_CURRENCY_UNSUPPORTED`, `PAYMENT_GATEWAY_UNAVAILABLE`) | No |

## 12. Security Threats & Mitigations

| Threat | Mitigation | Label |
|---|---|---|
| Webhook forgery | hashstring HMAC-SHA256 over documented field set with Secret API Key; `hash_equals`; missing/invalid hash → 401; unsigned/misconfigured → 400/503 | VERIFIED design |
| Webhook replay | Tap webhooks carry no event id — dedupe id synthesized as `{charge_id}:{status}` in `_webhook_event_ids` (cap 20); duplicate CAPTURED replays additionally fail on the stamped idempotency token (single completion) | VERIFIED design |
| Blind trust of redirect | `tap_id` treated as lookup hint only; server-side GET re-verification; completion ONLY on verified `CAPTURED` | VERIFIED design |
| Amount/currency tampering | `assertNoMismatch` ×1000 symmetric compare vs order snapshot; adapter echoes the provider-verified amount/currency | VERIFIED (existing) |
| Cross-order confirmation | provider-ref must belong to the txn row (`assertNoMismatch` ref check); gateway-binding check (`payment_method==='tap'`) | VERIFIED (existing) |
| Duplicate charge creation | `reference.idempotent` (per-order, 24h, DOC-FACT) + local pending-txn uniqueness semantics | DOC-FACT + VERIFIED |
| Secret leakage | Secret key env-only; never in settings store (read allowlist); never in responses/logs; error messages sanitized + truncated; provider payloads stored via allowlists (no customer/card objects) | VERIFIED design |
| Sensitive payload logging | Logs carry ids/gateway/event/result/status codes only — no raw bodies (deliberate deviation from `MyfatoraService` which logs full bodies; documented) | VERIFIED design |
| Open redirect | Redirect target = `route('api.checkout.callback')` (server-generated); customer redirect to `transaction.url` accepted as provider-returned URL (same trust level as MyFatoorah `InvoiceURL`/Stripe session url) | VERIFIED pattern |
| TLS | HTTP client keeps certificate verification enabled always (no `withoutVerifying`, deviating from MyfatoraService's local-env relaxation — documented) | VERIFIED design |
| Rate abuse | `throttle:payment-webhook` 20/min per IP on the new route (same as stripe/paypal) | VERIFIED (existing) |
| Test/live credential mixing | Single key slot + `sk_test`/`sk_live` prefix discipline; no mode flag to mismatch | VERIFIED design |
| Deadlock | Global lock order Transaction→Order preserved by reusing the existing completion/refund paths | VERIFIED (existing) |

## 13. Testing Strategy & Measurable Acceptance Criteria

**Environment constraint (documented blocker):** MySQL unreachable (`config/database.php` L38-40 forbids sqlite re-add; no DB service). Therefore:

- **Runnable now (TESTED):** DB-free unit tests — verifier algorithm, adapter behavior with `Http::fake()` and non-persisted Order models (`PaymentCurrencyResolver::forOrder` reads order attributes only; no queries on the guarded paths).
- **Catalogued, runnable when DB exists (NOT RUN here):** feature tests mirroring `PaymentWebhookTest`/`PayPalGatewayTest` conventions (RefreshDatabase + factory-mocked verification) covering the full §7-B/§7-C matrix.
- **Load testing:** blocked in this environment (checkout persistence requires MySQL; generating traffic against Tap's API with real charges is prohibited). Status: **NOT RUN — BLOCKED**, with the measurement plan documented.

Acceptance criteria evaluated in §15.7 with PASS/FAIL/BLOCKED/NOT RUN per item.

## 14. Risks, Blockers & Unresolved Questions

| # | Item | Class | Detail |
|---|---|---|---|
| 1 | No reachable MySQL in this environment | BLOCKER (for runtime tests) | Feature tests + load testing cannot execute here; unit-level verification only. Evidence: connection actively refused; repo config explicitly MySQL-only. |
| 2 | Tap sandbox credentials unavailable | BLOCKER (for live sandbox E2E) | No `TAP_*` values in `.env`/dashboard access provided. Mocked tests only; manual sandbox steps documented (§15.9). |
| 3 | Refund `amount` schema says `int32` while description says "up to 3 decimal places" for BHD/KWD/OMR | UNKNOWN (doc conflict) | Sandbox probe required before enabling Tap refunds for 3dp currencies; implementation sends the rounded decimal value and fails closed on refusal. |
| 4 | Webhook retry cadence/backoff undocumented | UNKNOWN | Mitigated by idempotent design; rate limit 20/min may throttle bursts — provider 429 handling relies on retry. |
| 5 | `post.url` payload coverage (per-transition vs terminal-only) undocumented | UNKNOWN | Handler tolerates any status; only CAPTURED completes, terminal-failures mark failed, others ignored. |
| 6 | Merchant-account currency enablement | UNKNOWN | Platform list ≠ account entitlements; unsupported currency fails at charge creation (fail-closed 422/500). |
| 7 | `hash` vs `hashstring` header precedence | UNKNOWN (minor) | Verifier accepts either; both documented in the official webhook example. |
| 8 | Reconciliation blind spots (pre-existing, unchanged) | RISK (pre-existing) | Detect-only; currency source bypasses resolver; refunds not reconciled — unchanged by this task. |

---

## Part 2 — Implementation & Final Audit

## 15. Implementation Results & Final Audit

**Confidence labels used in this part:** TESTED (executed command + observed result), VERIFIED (direct repository/framework evidence), INFERRED (reasoned from evidence, not executed), UNKNOWN (unresolvable without credentials/provider access), RISK.

### 15.1 Change set (complete)

| # | File | Change | Status |
|---|---|---|---|
| 1 | `config/payment.php` (L53-71) | New `tap` block: `class` (registry auto-discovery), `enabled` (`env('TAP_ENABLED', false)`), `secret_key`, `base_url` (default `https://api.tap.company/v2`), `source_id` (`src_all`), `supported_currencies` (env-parsed list, default = provider-capability table), `methods` `['online']` | DONE, `php -l` clean, TESTED |
| 2 | `.env.example` (L325-347) | `TAP_*` block with credential-prefix discipline documented; all values empty/false | DONE |
| 3 | `app/Services/Gateway/TapGateway.php` | NEW adapter (~480 lines): `createInvoice` / `verifyPayment` / `refund` / `isConfigured` / `supportsCurrency`; single `request()` HTTP seam (Bearer secret, `acceptJson`, 30s timeout, TLS verification always on, fail-closed on transport errors); charge/refund/status allowlist sanitization; `sk_` redaction in error messages | DONE, `php -l` clean, TESTED (16 unit + 5 flow tests) |
| 4 | `app/Services/Payment/TapWebhookVerifier.php` | NEW verifier: Tap's official hashstring mechanism — `hash_hmac('sha256', 'x_id'.$id.'x_amount'.$amount.'x_currency'.$currency.'x_gateway_reference'.$gr.'x_payment_reference'.$pr.'x_status'.$status.'x_created'.$created, SecretAPIKey)`, amount rounded to currency ISO exponent, absent gateway/payment reference hashed as empty string, timing-safe `hash_equals` (deliberate upgrade over the doc sample's `==`), fail-closed on every missing/non-scalar field | DONE, `php -l` clean, TESTED (8 unit tests incl. exact KWD 3dp case) |
| 5 | `app/Http/Controllers/Api/General/PaymentWebhookController.php` | New `tap()` method (L281-388): secret-missing → 503; empty/invalid body → 400; unsigned → 400; hash mismatch → 401; charge-id validation (`^[A-Za-z0-9\-_]+$`, ≤191); synthesized dedupe id `{charge_id}:{status}` (Tap webhooks carry no event id); `TAP_TERMINAL_FAILURES` const (FAILED/DECLINED/CANCELLED/TIMEDOUT/ABANDONED/RESTRICTED/VOID/UNKNOWN); CAPTURED → `completeFromProviderRef`, terminal failures → `markFailedByProviderRef` (idempotency token preserved so a later CAPTURED can still complete), INITIATED/unknown → 200 ignored; `TapWebhookVerifier` constructor-injected | DONE, `php -l` clean, TESTED (11 webhook tests) |
| 6 | `routes/api.php` (L138) | `Route::post('checkout/webhooks/tap', [PaymentWebhookController::class, 'tap'])->middleware('throttle:payment-webhook')->name('api.checkout.webhooks.tap')` | DONE; route existence TESTED via `php artisan route:list --path=webhooks/tap` → `POST api/v1/general/checkout/webhooks/tap … api.checkout.webhooks.tap › PaymentWebhookController@tap` with `throttle:payment-webhook` |
| 7 | `app/Http/Controllers/Api/General/OrderController.php` | `checkoutCallback` + `checkoutErrorCallback`: `tap_id` fallback reference source (`paymentId` keeps precedence), extracted into shared private helper `paymentReference()`; id remains an untrusted lookup hint — outcome decided by server-side GET re-verification | DONE, `php -l` clean, TESTED (3 callback tests) |
| 8 | `app/Services/Payment/PaymentRefundService.php` | **Bug fix F-TAP-REPLAY** (pre-existing defect, found by the Tap refund-replay test — see §15.5) | DONE, `php -l` clean, TESTED |
| 9 | `tests/Unit/TapGatewayTest.php` (16), `tests/Unit/TapWebhookVerifierTest.php` (8) | New unit tests | TESTED |
| 10 | `tests/Feature/Payment/TapGatewayFlowTest.php` (11), `tests/Feature/Payment/TapWebhookTest.php` (11) | New feature tests (RefreshDatabase, isolated `catch_verify` DB, `Http::preventStrayRequests()` hygiene, mock-bound-before-first-request pattern) | TESTED |
| 11 | `tests/Feature/Payment/GatewaySettingsAdminTest.php` | Contract update: registry legitimately grew 3 → 4 gateways (count assertion, `tap` in by-code assertion, resort test pins `tap` at sort_order 20) | TESTED (11/11 after update) |
| 12 | Database/migrations | **None** — generic `transactions` schema suffices (as designed §11) | VERIFIED (no migration files added) |
| 13 | Translations | **None** — reuses `PAYMENT_CURRENCY_UNSUPPORTED`, `PAYMENT_GATEWAY_UNAVAILABLE` (as designed §11) | VERIFIED (no lang files touched) |

### 15.2 Corrections to Part 1 claims

| Part 1 claim | Status | Correction |
|---|---|---|
| §1, §13, §14#1: "MySQL unreachable — feature tests + load testing cannot execute" | **SUPERSEDED** | MySQL 8.0 **is** reachable. The actual blocker was narrower: the shared `catch` database must never be touched by tests. All runs use a process-local guard that refuses `catch` and permits the isolated `catch_verify` DB (PowerShell prefix `$env:DB_DATABASE='catch_verify'`). Every feature suite in §15.3 therefore has full RefreshDatabase execution evidence. §14#1 reclassified BLOCKER → **RESOLVED**. |
| §13: "Runnable now: DB-free unit tests only" | **SUPERSEDED** | Full feature matrix executed (§15.3, §15.7). |

The discovery *content* of Part 1 (architecture, ownership, flow analysis, DOC-FACT citations) is unaffected — the correction concerns only the environment/feasibility claims.

### 15.3 Test execution evidence

Environment for every run: `$env:DB_DATABASE='catch_verify'` (isolated DB; guard refuses the shared `catch` DB). Each artisan invocation = fresh process ≈ 30-50s RefreshDatabase migration cost (visible as the first test's duration — NOT a lock wait; genuine InnoDB 1205 lock waits additionally fail with a QueryException on `insert into currencies`).

| Command (`php artisan test --filter=…`) | Result |
|---|---|
| `TapGatewayTest\|TapWebhookVerifierTest` (unit) | **24 passed (97 assertions)** |
| `TapWebhookTest\|TapGatewayFlowTest` (feature) | **25 passed (190 assertions)** — 22 original + 3 coverage tests added during the review gate (§15.8.1 N2); includes `Http::preventStrayRequests()` hygiene (N1) |
| `TapGatewayFlowTest\|TapWebhookVerifierTest` (re-run after clean-code fixes) | **19 passed (84 assertions)** |
| `RefundCurrencyAndLedgerTest\|PaymentConcurrencyTest\|ExternalRefundTest` (re-run after the M1 lock-order fix) | **17 passed (166 assertions)** |
| `Tests\\Feature\\Payment\\PaymentCompletionTest::` (anchored filter — plain substring also matches the pre-existing-red `WebhookPaymentCompletionTest`) | **14 passed (117 assertions)** |
| `GatewaySettingsAdminTest` | **11 passed (125 assertions)** |
| `PaymentWebhookTest\|PublicPaymentGatewaysTest` | **19 passed (132 assertions)** |
| `PayPalGatewayTest\|PayPalCaptureIdempotencyTest\|StripeGatewayTest` | **36 passed (174 assertions)** |
| `StripeFailureCorrelationTest\|PaymentConcurrencyTest\|PaymentCompletionTest` | **27 passed** (run also matched `WebhookPaymentCompletionTest` by substring — 5 failures, pre-existing red, §15.6) |
| `PaymentSecurityTest\|RefundCurrencyAndLedgerTest\|MarvelStatusReachabilityTest` | **16 passed (193 assertions)** |
| `BypassGateTest\|MarvelStatusAuthorityTest\|PaymentRedirectContractTest\|CatalogCurrencyAuthorityTest\|CurrencyPrecisionTest\|ExternalRefundTest\|GatewayRegistryTest` | **45 passed (221 assertions)** |
| `php -l` on every changed file | clean |
| `php artisan route:list --path=webhooks/tap` | route present, `throttle:payment-webhook` (§15.1 #6) |

Regression total: **154 regression tests green** across 20 payment/currency classes in the pre-review sweep, **plus 31 post-review re-verification** after the M1 lock-order fix (§15.8.1), plus 24 Tap unit + 25 Tap feature (283 assertions in the re-verification groups alone). A full 27-class batch was attempted once and killed at the 25-minute timeout — see §15.4 (cascade contamination); small-group execution was adopted instead.

### 15.4 Test-infrastructure discoveries (verified, framework-level)

These are test-mechanics findings — production behavior is unaffected (each HTTP request resolves a fresh controller in production).

1. **`Route::getController()` caches the controller instance on the Route object** (VERIFIED: `vendor/laravel/framework/src/Illuminate/Routing/Route.php` L269-278 — `if (! $this->controller) { $this->controller = $this->container->make(ltrim($class, '\\')); }`, reset only at L318). In-process feature tests (`$this->call()`) reuse the cached instance, so a container binding made **after** the first request is invisible to the controller. Rule adopted in `TapWebhookTest`: **bind mocks before the first request in every test**.
2. **Mockery/unfulfilled-expectation → InnoDB 1205 cascade** (root-caused from live evidence): an unfulfilled `times(1)` expectation makes `Mockery::close()` throw in `tearDown()` **before** `parent::tearDown()` — the RefreshDatabase rollback is skipped, an open transaction leaks, and the next test's `insert into currencies` waits ~50s → 1205. Fixes: (a) expectations must be fulfilled (bind before first request), (b) teardown order `parent::tearDown(); \Mockery::close();` in both Tap feature files. Empirically verified: zero 1205s in all green runs after the fix. Batch contamination: once a cascade starts mid-batch, subsequent class results are polluted until the process ends — hence small-group execution (§15.3).
3. **MySQL 8.0 auto-increment counters persist across RefreshDatabase rollbacks** — fixture ids shift across tests in one process; hardcoded `actorId: 1` caused FK 1452 on `order_status_history.changed_by` in later tests (passed alone by coincidence). Rule: always `(int) $order->user_id` for actor ids.
4. **Callback-type precedence** (VERIFIED: `OrderController::getCallbackType()` L912-925): the stored `gateway_response['_callback_type']` **wins** over the request `type` param → fixtures must not set `_callback_type => 'web'` when testing mobile JSON responses (test-fixture bug, fixed).

### 15.5 F-TAP-REPLAY — pre-existing `PaymentRefundService` bug found & fixed

**Discovery:** the Tap full-refund replay test was the first exerciser of the full-refund replay path and exposed a dead-code branch in the canonical refund authority:

- The documented `idempotent_replay` return path (docblock; original L127-137) ran **after** the `ALREADY_REFUNDED` guard (L88-90), and the transaction lookup (L80-84) excluded status `'refunded'`.
- A full refund sets `payment_status = 'payment-refunded'` and the transaction to `'refunded'` — so a retry with the SAME idempotency key threw `ALREADY_REFUNDED` instead of returning the original summary. Partial-refund replays worked; **full-refund replays were impossible** — violating the service's own documented contract.
- Impact class: duplicate-refund UX/audit correctness (no double refund was possible — the guard also blocked it — but the idempotency contract was broken and replaying a successful refund request surfaced an error instead of its original result).

**Fix:** the ledger-replay scan moved BEFORE the terminal-state guards (`app/Services/Payment/PaymentRefundService.php`): scans every order transaction (`paid` / `partially_refunded` / `refunded`, `orderByDesc('id')`, `lockForUpdate`) for the caller's idempotency key; on match returns the original summary from the committed ledger with **no provider call**. The guards are unchanged for genuinely new keys; the scan is bounded (per-order transaction count × capped ledger size).
**Deliberate semantic change (per independent review S1):** the replay *scope* broadens from per-transaction to per-**order** — a key recorded on one transaction now also replays against sibling transactions of the same order. This is the safer direction for money movement and is now the documented contract (class docblock updated); keys on different orders remain independent refunds.
**Lock-order correction (per independent review M1):** the initial fix placed the scan *after* the Order lock, violating the F-AUDIT-01 global Transaction → Order ordering (AB-BA window vs a concurrent callback on a sibling transaction row). All Transaction locks — the refundable-txn query and the replay scan (deterministic `orderByDesc('id')` scan order) — now precede the Order lock; the redundant post-lock re-read was dropped (rows already locked).
**Regression:** full-refund replay test passes (TESTED, §15.3); all pre-existing refund suites green — `ExternalRefundTest`, `RefundCurrencyAndLedgerTest`, `PaymentConcurrencyTest`, `PaymentCompletionTest`, `GatewayRegistryTest` (§15.3, incl. the post-M1 re-runs).

### 15.6 Pre-existing red suites (documented, NOT caused by this change set, out of scope)

1. **`GatewayDisableTest` refund tests (7 failures, 3× 404 + 4× 1205 cascade):** they POST `/api/v1/admin/payments/{order}/refund` (`GatewayDisableTest.php` L29, L36). Evidence the endpoint never existed: `git log --all -S "payments/{order}/refund" -- routes/api.php` → empty; `git show HEAD:routes/api.php` contains no refund routes; `route:list` shows only the separate `api/v1/admin/refunds` approve/reject workflow. The prior audit (`payment-flow-and-tap-integration-audit.md` L697) recorded the payment suite as "existence VERIFIED; **NOT RUN** in this environment". Decision: do **not** build the missing endpoint in this task; recorded for follow-up.
2. **`WebhookPaymentCompletionTest` (5 failures, `BadMethodCallException`):** `Call to undefined method Marvel\Database\Models\Product::factory()` (also `Order::factory()`, `Coupon::factory()`) — the Marvel models have no factories; the failure is in fixture setup (test L34-55, L86-132) before any payment code runs. File is committed and unmodified by this task (`git log -1` → fcbcad0 2026-09-10 "catch update").

### 15.7 Acceptance criteria checklist (per §10/§11/§13)

| # | Criterion | Result | Evidence |
|---|---|---|---|
| 1 | Tap is an optional 4th gateway, disabled by default; enabling does not affect others | **PASS** | `config/payment.php` L63 default `false`; `TapGatewayFlowTest` "tap disabled new initiation is 422 with no provider call"; `GatewayRegistryTest` 10/10 and `GatewayDisableTest` non-refund tests green |
| 2 | No DB migration | **PASS** | §15.1 #12 |
| 3 | No new translation keys | **PASS** | §15.1 #13 |
| 4 | Currency never hardcoded — derived from `PaymentCurrencyResolver` order authority; unsupported → fail-closed | **PASS** | `TapGatewayTest` "never hardcodes the charge currency" + "fails closed on an unsupported order currency"; `TapGatewayFlowTest` unsupported-currency test |
| 5 | Missing credentials fail closed (422), no provider call | **PASS** | `TapGatewayFlowTest` "tap missing credentials fail closed with no provider call" |
| 6 | No silent fallback to other providers | **PASS** | initiation goes only through the requested gateway's adapter; disabled/unknown fail with 422/404 (`GatewayRegistryTest`, `TapGatewayFlowTest`) |
| 7 | Webhook verification = Tap's official hashstring mechanism, timing-safe, fail-closed | **PASS** | `TapWebhookVerifierTest` 8 tests incl. exact doc-sample reproduction + KWD 3dp case; mismatch → 401, unsigned → 400, secret missing → 503 (`TapWebhookTest`) |
| 8 | Redirects untrusted; server-side GET re-verification; completion ONLY on verified CAPTURED | **PASS** | `TapGatewayFlowTest` callback tests (mocked `verifyPayment` is the server-side authority); `TapWebhookTest` out-of-order FAILED→CAPTURED completes only on CAPTURED; adapter: success exclusively `status === 'CAPTURED'` |
| 9 | Idempotency preserved; no second state machine | **PASS** | canonical `PaymentCompletionService`/locked completion reused; webhook dedupe `{charge_id}:{status}`; completion idempotency token; replayed CAPTURED acks (`TapWebhookTest` replay test); refund ledger replay (§15.5) |
| 10 | Refunds only via `PaymentRefundService` + adapter `refund()` | **PASS** | `TapGatewayFlowTest` refund tests (targets latest transaction, reason mapping, fail-closed on non-REFUNDED/provider error); `PaymentRefundService` unchanged as authority |
| 11 | Existing gateways & suites unaffected (regression) | **PASS** | §15.3 regression total 154 green; only `GatewaySettingsAdminTest` contract updated (legitimate registry growth) |
| 12 | No secrets/PII in logs or stored state; TLS verification always on | **PASS** | allowlist sanitization (CHARGE/REFUND allowlists); `sk_` redaction; no `withoutVerifying` anywhere in `TapGateway`; log lines carry ids/statuses only (code review §15.8) |
| 13 | Route existence asserted in tests | **PASS** | `route:list` output (§15.1 #6); tests POST the named route |
| 14 | Load testing (target ~400 req/min, local + mocked provider) | **NOT RUN — BLOCKED** | see §15.10 |
| 15 | Manual Tap sandbox E2E (real credentials) | **BLOCKED** | no `TAP_*` credentials available (§14#2); manual steps in §15.9 |

### 15.8 Code review gates

**clean-code-guard guard pass** (applied to the full diff before independent review):

- `app/Http/Controllers/Api/General/OrderController.php` — fixed: duplicated 8-line `tap_id` fallback (duplicated knowledge in two callbacks) extracted into private helper `paymentReference()` (file precedent: `getCallbackType()`); re-tested.
- `app/Services/Payment/TapWebhookVerifier.php` — fixed: dead trailing `. ''` concatenation artifact removed (doc-sample transcription noise); re-tested.
- `clean-code-guard: 2 fixed, 0 flagged`

**Independent review:** completed (read-only, full change set line-by-line vs `StripeGateway`/`PayPalGateway`/`PaymentGatewayContract`/`PaymentCompletionService`/registry/settings/lang). Verdict: **no BLOCKER**; verified non-findings across all seven review areas (adapter contract parity — currency exclusively resolver-derived, no `KWD` literal; webhook security — fail-closed verifier, timing-safe compare, no unsigned path, secrets never logged; guards unchanged for new keys; callback precedence byte-identical with identical validation after the helper; no endpoint shape change; no vacuous test assertions; config defaults match `.env.example` exactly). Findings & dispositions:

#### 15.8.1 Independent reviewer findings & dispositions

| # | Class | Finding | Disposition |
|---|---|---|---|
| M1 | **MUST-FIX** (fixed) | The F-TAP-REPLAY replay scan acquired its Transaction `lockForUpdate()` **after** the Order lock (`PaymentRefundService.php` L86 before the scan) — violating the F-AUDIT-01 global lock order (Transaction → Order, `:76-79`) and opening an AB-BA deadlock window: concurrent admin `refund()` (holds Order → waits txn) × in-flight callback/webhook on a *different* transaction row of the same order (holds txn → waits Order) → MySQL 1213, one transaction killed. InnoDB locking reads lock before the WHERE filter, so even non-matching rows in the scan participate. | **Fixed & re-tested:** all Transaction locks (refundable-txn query + replay scan, deterministic `orderByDesc('id')`) now precede the Order lock; the redundant post-lock re-read dropped (rows already locked). Re-ran `RefundCurrencyAndLedgerTest` + `PaymentConcurrencyTest` + `ExternalRefundTest` (17 passed / 166 assertions) and `PaymentCompletionTest` (14 passed / 117 assertions) — no deadlocks. The reviewer's §15.5 contradiction is acknowledged: the original fix claim "global lock order preserved" was true only for the first query. |
| S1 | **SHOULD-FIX** (fixed) | Class docblock (L25-29) still claimed "replay check scans only THIS transaction's ledger" (per-transaction idempotency scope) — the F-TAP-REPLAY scan is per-**order**: a key recorded on txn A now replays against txn B of the same order. For money movement per-order is strictly safer, but the documented contract was stale and §15.5's "semantics-preserving" claim overlooked that the replay *scope* is the one deliberate semantic change. | **Fixed:** docblock rewritten — scope is per-order (keys unique per order; different-order keys remain independent refunds) and the LOCKING paragraph documents the scan's pre-Order-lock position. §15.5 wording corrected (this part): the fix is behavior-preserving for new keys; for replays it deliberately broadens scope from per-transaction to per-order (safer direction, no production caller exists today — the service is the designated canonical refund authority for the admin refund work). |
| N1 | NICE-TO-HAVE (fixed) | `TapGatewayFlowTest` never called `Http::preventStrayRequests()` — a precedence regression could fall back to a real `myfatoorah` network call before the assertion failed. | **Fixed & re-tested:** `preventStrayRequests()` added to setUp; full Tap feature suite green (25 passed / 190 assertions). |
| N2 | NICE-TO-HAVE (fixed) | Three behavior claims of `tap()` were untested: the `hash`-header fallback (accepted only when `hashstring` absent), invalid charge-id → 400, unrecognized non-terminal status → 200 ignored. | **Fixed & re-tested:** three new webhook tests added (`tap_hash_header_fallback_is_accepted_when_hashstring_absent`, `tap_invalid_charge_id_is_400_and_state_untouched`, `tap_unrecognized_non_terminal_status_is_ignored`); suite now 14 tests, green. |
| N3 | NICE-TO-HAVE (applied) | `.phpunit.cache/test-results` is a pre-existing TRACKED build artifact (tracked since HEAD `ab8a341`, not gitignored) modified by every test run — should not ship in this change set. | **Applied:** restored to HEAD (`git checkout --`); excluded from the change set. Adding it to `.gitignore` is a separate housekeeping decision (not done here). |

Post-review verification totals: Tap unit 24/24 (97 assertions), Tap feature **25/25 (190 assertions, was 22/166 — 3 coverage tests added)**, refund-authority suites 31/31 (17+14, 283 assertions).

### 15.9 Manual Tap sandbox verification steps (blocked on credentials — §14#2)

1. Obtain sandbox Secret API Key (`sk_test_…`) from a Tap developer account.
2. Set `.env`: `TAP_ENABLED=true`, `TAP_SECRET_KEY=sk_test_…` (others default).
3. `php artisan config:clear`.
4. Checkout with Tap against a KWD order → confirm redirect URL opens the hosted payment page; complete with Tap's sandbox test card; confirm redirect back to `checkout/callback?tap_id=…` completes the order (payment-success, invoice generated).
5. In Tap's dashboard, register the webhook endpoint `POST {public_base}/api/v1/general/checkout/webhooks/tap`; confirm the dashboard's hashstring validation tool and a live CAPTURED notification complete the order idempotently (replay the same notification → still one completion).
6. Sandbox refund from the admin flow → confirm `status REFUNDED`, ledger row, order `payment-refunded`.
7. §14#3 probe: refund a 3-decimal currency (KWD) amount `0.001`-precision value and record whether Tap accepts decimals despite the `int32` schema note.
8. Confirm §14#6: attempt an order in a currency not enabled on the merchant account → expect the fail-closed unsupported-currency error.

### 15.10 Load testing — NOT RUN (BLOCKED)

The measurement plan (§13) requires a standing harness and an environment where suite-level DB access is stable. Executing a meaningful ~400 req/min measurement was **not** possible in this environment: the dev-machine MySQL exhibited intra-suite transaction cascades (§15.4 #2) even in isolated single-class runs before the teardown fix, each invocation carries 30-50s migration overhead, and there is no staging environment. Per the task's own rule (metrics reported only if actually executed), **no load metrics are claimed**. The mitigations that matter for load safety are in place and unit/feature-verified: per-order idempotency (`reference.idempotent`), webhook dedupe cap (20 ids), `throttle:payment-webhook` on the public endpoint, and bounded lock scopes. Revisit trigger: a staging environment with `catch_verify`-equivalent isolation.

### 15.11 Currency findings (final)

- Charge/refund currency is derived **only** from `PaymentCurrencyResolver::forOrder($order)` (order snapshot → catalog currency); no `KWD` literal exists in the adapter; unit test "it never hardcodes the charge currency" pins this (TESTED).
- Amounts are JSON numbers at the currency ISO exponent via `CurrencyPrecision::roundForCurrency()`; KWD/BHD/JOD/OMR 3dp verified end-to-end at 13.255 (TESTED, `CurrencyPrecisionTest` + flow tests).
- `supported_currencies` default is the provider capability list (env-overridable, e.g. deployment narrows to `KWD`); unsupported order currency fails closed **before** any provider call (TESTED).
- Webhook hashstring amount rounding uses the same exponent table (unit TESTED with KWD 3dp).

### 15.12 Final readiness classification

**READY FOR SANDBOX VERIFICATION** — not "PRODUCTION READY".

- Code-complete: all §11 required changes implemented; no invented business rules; no interface changes; no migrations.
- Automated verification: 24 unit + 25 feature Tap tests green; 154 regression tests green in the pre-review sweep + 31 post-M1 re-verification; route existence verified.
- Independent review: no BLOCKER; 1 MUST-FIX (M1 lock order) and 1 SHOULD-FIX (S1 docblock) fixed and re-verified; 3 NICE-TO-HAVE addressed (§15.8.1).
- Not claimed: sandbox/live E2E (no credentials), load metrics (§15.10), §14 UNKNOWN items #3-#7 — all require Tap sandbox access.
- Two pre-existing red suites documented out of scope (§15.6) — they block neither this integration nor its rollback (Tap ships disabled).

---

## Part 3 — Final Verification Audit (2026-10-27)

Independent re-verification pass. **Nothing from Part 2 was accepted as proof** — every conclusion below is supported by fresh inspection or commands executed during this audit. No code was changed (one temporary mutation was applied and fully restored — §16.6).

### 16.1 Scope reviewed & method

| Area | Method | Status |
|---|---|---|
| Git working tree | `git status --porcelain` — exactly the task's change set, uncommitted; one pre-existing user stash untouched; `tmp_trx_check.php` absent; `.phpunit.cache/test-results` restored to HEAD | PASS |
| Report integrity | `docs/audits/tap-payments-integration-audit.md` ends at §15.12 (Part 1 + Part 2 preserved) | PASS |
| DB safety guard | VERIFIED active: `tests/CreatesApplication.php` L20-34 — any run resolving mysql `catch`@127.0.0.1 exits 1 before setup unless `ALLOW_CATCH_TESTS=true`; `phpunit.xml` L21-25 sets `catch` as a non-forced default so a process-local `DB_DATABASE=catch_verify` override is the sanctioned isolation | PASS |
| Implementation files | All 12 changed/new PHP files re-inspected line-by-line (adapter, verifier, webhook controller, OrderController, refund service, config, routes, env, tests) | PASS |

### 16.2 Commands executed & results (fresh reproduction)

All test runs: `$env:DB_DATABASE='catch_verify'` (isolated; guard refuses `catch`). Full-suite run: **NOT RUN** (see note below).

| Command | Result |
|---|---|
| `php -l` × 12 changed/new PHP files | **0 failures** |
| `php artisan route:list --path=checkout` | all 5 payment routes present: `checkout/callback`, `checkout/error-callback`, `webhooks/paypal`, `webhooks/stripe`, **`webhooks/tap` → `PaymentWebhookController@tap`** |
| `php artisan test --filter="TapGatewayTest\|TapWebhookVerifierTest"` | **24 passed (97 assertions)** |
| `php artisan test --filter="TapWebhookTest\|TapGatewayFlowTest"` | **25 passed (190 assertions)** |
| `--filter="GatewayRegistryTest\|GatewaySettingsAdminTest\|PaymentWebhookTest\|PublicPaymentGatewaysTest"` | **40 passed (292 assertions)** |
| `--filter="PayPalGatewayTest\|PayPalCaptureIdempotencyTest\|StripeGatewayTest\|StripeFailureCorrelationTest"` | **40 passed (186 assertions)** (an initial pipe-truncated invocation printed exit-1 from a closed PowerShell pipe; the logged full run shows all four classes PASS with the summary above) |
| `--filter="PaymentSecurityTest\|RefundCurrencyAndLedgerTest\|ExternalRefundTest\|PaymentConcurrencyTest\|Tests\\Feature\\Payment\\PaymentCompletionTest::\|CurrencyPrecisionTest\|CatalogCurrencyAuthorityTest"` | **52 passed (523 assertions)** |
| `--filter="BypassGateTest\|MarvelStatusAuthorityTest\|MarvelStatusReachabilityTest\|PaymentRedirectContractTest"` | **22 passed (96 assertions)** |
| `--filter="…\|GatewayDisableTest"` (same process as the four above) | **16 failed, 16 passed (62 assertions)** — all 16 failures traced (§16.3 F2/F3) |
| `--filter='WebhookPaymentCompletionTest::'` (solo) | **5 failed** — pre-existing red (F2) |

**Fresh reproduction total: 203 passed tests / 1,384 assertions** (24+25+40+40+52+22). Failures observed are exclusively the two documented pre-existing red suites (§15.6) plus cascade victims of the first, re-run clean.

**Full suite note:** the entire repo suite (~479 test files per `error/verification-evidence.md`) was **NOT RUN** — out of this audit's payment scope; the groups above cover every Tap-touched path and the complete payment/currency/refund/concurrency/webhook regression set.

### 16.3 Findings by severity

**No BLOCKER, no CRITICAL, no HIGH findings. No new MEDIUM/LOW defects were introduced by the change set.**

| # | Severity | Finding | Evidence & disposition |
|---|---|---|---|
| F1 | (none new) | — | The audit attempted to find defects; none surfaced in code, config, routes, tests, or currency handling. |
| F2 | MEDIUM — **pre-existing, test-only, out of scope** | `GatewayDisableTest` refund tests (7) POST `/api/v1/admin/payments/{order}/refund` — an endpoint that never existed in any commit (`git log --all -S` empty; HEAD `routes/api.php` has no refund routes; `route:list` shows only the separate `admin/refunds` approve/reject workflow). Re-reproduced this session: 3× fast 404 + 4× 1205 lock-timeout cascade (its own leaked holder then poisons later tests in the same process — `MarvelStatusReachabilityTest`, `PaymentRedirectContractTest` failed at ~50.8s each in the contaminated run). | File committed & unmodified by this change set. Cascade victims re-verified green in a clean process: **22 passed (96 assertions)**. Do not build the missing endpoint in this task; separate follow-up recommended (implement endpoint OR rewrite tests against the real refund API). |
| F3 | MEDIUM — **pre-existing, test-only, out of scope** | `WebhookPaymentCompletionTest` (5) fails with `Call to undefined method Marvel\Database\Models\Product::factory()` / `Order::factory()` / `Coupon::factory()` in fixture setup (test L34-55, L86-132) — Marvel models have no factories; failure precedes any payment code. File committed & unmodified (last touch fcbcad0 2026-09-10). | Re-reproduced solo this session. Unrelated to Tap (no code path reached). Separate follow-up recommended (write manual fixtures like the Tap tests do, or add factories). |

### 16.4 Dynamic currency verification matrix

**Conclusion: dynamic currency handling is VERIFIED — no runtime currency hardcode exists in the Tap integration.**

| Matrix row | Status | Evidence |
|---|---|---|
| Runtime hardcode search (`KWD`, `USD`, `EGP`, `'currency' =>`) | **PASS** | `TapGateway`: currency only from `PaymentCurrencyResolver::forOrder($order)` — charge L90, refund L250; the only `KWD`/`USD` strings are doc-comment exponent tables (L22-23, L34-36) and allowlist field NAMES (L56, L67). `TapWebhookVerifier`: currency from posted payload (L48). `PaymentWebhookController`: zero currency literals. `OrderController`: zero runtime currency literals in Tap paths (L187 is an exponent comment). Config `supported_currencies` default (config/payment.php L67-70) is env-overridable config — not a runtime hardcode. |
| Charge currency derivation authority | **PASS** | `PaymentCurrencyResolver::forOrder()` (app/Services/Payment/PaymentCurrencyResolver.php L26-45): order snapshot `catalog_currency_code` → `currency_code` → catalog currency — order-authoritative, dynamic per transaction; VERIFIED |
| Two-decimal currency | **PASS (TESTED)** | USD end-to-end webhook completion (13 of the 25 feature tests run on USD 100.00); SAR 2.00 exponent in the unit hashvector tests |
| Three-decimal currency | **PASS (TESTED)** | `tap_kwd_order_completes_with_three_decimal_amount` — 13.255 KWD end-to-end through the webhook (hashstring rounding 13.255, charge amount JSON number); flow test asserts 13.255 charge amount for KWD orders |
| Unsupported currency | **PASS (TESTED)** | `TapGatewayTest` "fails closed on an unsupported order currency" + `TapGatewayFlowTest` unsupported-currency test → fails closed with `PAYMENT_CURRENCY_UNSUPPORTED`, no provider call |
| Missing currency | **PASS** | Resolver L28-45 treats null/blank as absent and falls to the configured catalog currency (explicit authority, not silent substitution); hashvector tests confirm the verifier rejects a missing/empty currency (verifier L57 `currency === ''` → false → 401) |
| Order-vs-provider currency mismatch | **PASS** | Shared completion authority `assertNoMismatch` is gateway-independent: `PaymentCompletionTest::callback_amount_and_currency_mismatch_mark_failed` (L206-236 — currency mismatch USD-vs-KWD provider response → 400 + failed) runs through the same completion path Tap uses; tap's `verifyPayment` echoes provider-verified currency into that authority (TapGateway L227, L238). Tap-specific webhook currency-mismatch test: **NOT PRESENT** — LOW, remaining nice-to-have (the shared authority covers the path; see §16.9). |
| Refund currency consistency | **PASS (TESTED)** | `RefundCurrencyAndLedgerTest` "refund after catalog switch succeeds in transaction currency" + "same idempotency key on different orders are independent refunds"; adapter refund derives currency from the resolver (L250) and records it on the ledger entry |
| Precision/rounding boundaries | **PASS (TESTED)** | `CurrencyPrecisionTest` (7 tests: 3dp minor units, stripe/paypal payloads, KWD snapshot-to-completion end-to-end, refund millis per exponent); mutation-free unit hashvector tests independently reproduce the documented rounding table |
| Zero-decimal currency | **PASS (TESTED — existing gateway)** | `StripeGatewayTest::create_invoice_zero_decimal_currency_sends_amount_as_is` — establishes the shared precision layer handles zero-decimal correctly; Tap config list contains no zero-decimal currency by default, so no Tap-specific zero-decimal path exists |
| Existing non-Tap gateways unchanged | **PASS (TESTED)** | PayPal 40/40 group + Stripe/FailureCorrelation 40/40 + Security/Completion/Concurrency groups all green; `GatewayRegistryTest` 10/10 with tap present (auto-discovery only adds a row) |
| Merchant-account entitlement verification | **BLOCKED** | Static config list ≠ account enablement; §14#6 UNKNOWN stands — requires sandbox credentials (§16.7) |

### 16.5 Webhook security & idempotency results

| Check | Status | Evidence |
|---|---|---|
| Official mechanism re-verified against CURRENT docs | **PASS** | Fetched `developers.tap.company/docs/webhook` (doc updatedAt **2026-10-04**; fetched 2026-10-27): algorithm is EXACTLY `'x_id'.$id.'x_amount'.$amount.'x_currency'.$currency.'x_gateway_reference'.$gr.'x_payment_reference'.$pr.'x_status'.$status.'x_created'.$created` hashed with `hash_hmac('sha256', …, SecretAPIKey)`; amount rounded to the standard ISO decimal value BEFORE hashing (doc's table ≡ `CurrencyPrecision::decimalsFor` for all 11 listed currencies incl. JOD 3dp/EGP 2dp); `gateway_reference` "empty value" when absent (≡ verifier L51 empty-string handling); `created` = `charge.transaction.created` (≡ verifier L53). The doc's comparison uses `==` — the implementation deliberately upgrades to `hash_equals` (timing-safe), documented in the verifier docblock L28-29. |
| Dual-header handling | **PASS** | Doc's charges example posts BOTH `hash` and `hashstring` with DIFFERENT values; only `hashstring` is documented → implementation prefers `hashstring`, accepts `hash` only when absent (controller L330); TESTED by `tap_hash_header_fallback_is_accepted_when_hashstring_absent`. This resolves prior §14#7 UNKNOWN → documented-faithful. |
| Fail-closed verification | **PASS** | Verifier L43-59: empty hash/secret, missing/non-scalar id/currency/status/amount → false; controller L304-388: missing secret → 503, empty/invalid body → 400, unsigned → 400, mismatch/exception → 401 — TESTED (`tap_bad_or_unsigned_hash…`, `tap_misconfigured_secret_is_503`) |
| No redirect-trust completion | **PASS** | CAPTURED webhook → `completeFromProviderRef` → `PaymentGatewayFactory::verifyPayment` server-side re-fetch (TESTED: out-of-order test asserts exactly 1 verify call); browser callback success is never trusted without re-verification |
| Cross-order/cross-gateway protection | **PASS** | Foreign-gateway rejection TESTED (`tap_webhook_for_a_foreign_gateway_transaction_is_rejected`); `markFailedByProviderRef`/`completeFromProviderRef` re-bind txn.payment_method === gateway and mutate only the txn located by provider ref; provider-ref binding + `assertNoMismatch` (×1000 precision) prevent cross-order confirmation |
| Dedupe/idempotency/out-of-order | **PASS** | Synthesized dedupe id `{charge_id}:{status}` (cap 20); replayed CAPTURED acks ignored (TESTED); FAILED→CAPTURED out-of-order completes (TESTED); terminal states not overwritten by stale events (`markFailedByProviderRef` leaves non-pending rows untouched — controller L685-690 region, re-inspected) |
| Route security | **PASS** | `POST api/v1/general/checkout/webhooks/tap` with `throttle:payment-webhook` (same as stripe/paypal); verification precedes any DB touch; no secrets/payload dumps in any log line (re-inspected) |
| Concurrency/lock ordering | **PASS** | Global lock order Transaction → Order preserved on every refund path after the M1 fix; webhook completion locks txn → order (`PaymentWebhookController` L478-507 region); `PaymentConcurrencyTest` + `PaymentCompletionTest` green this session |

### 16.6 Refund, ledger & concurrency results — F-TAP-REPLAY mutation test

**The fix is present AND its regression test provably fails if the defect returns** (mutation-verified this session):

| Step | Action | Result |
|---|---|---|
| 1 | Fresh baseline: `--filter="full_refund_through_the_refund_authority"` (in the 25/25 run) | PASS (part of 190 assertions) |
| 2 | Mutation: replay branch disabled (`if (false && $replayCandidate !== null)`) | applied |
| 3 | Re-run the same test | **FAIL — exactly the original defect signature: `RuntimeException Already refunded` (`PaymentRefundService.php` L146 guard)** |
| 4 | Mutation reverted; `php -l` clean; `git diff --stat` restored to the intended 64+/19- shape | applied |
| 5 | Re-run the same test | **PASS (10 assertions)** |

Additional refund/ledger evidence (all TESTED this session, group C — 52 passed / 523 assertions): duplicate-refund replay protection, concurrent-refund limits, partial/full ledger accumulation with cap-100 pruning, fail-closed on provider refusal/ambiguity, transaction-currency preservation across catalog switches, and independence of same-key refunds across different orders.

### 16.7 Sandbox E2E status — **BLOCKED**

Missing prerequisites: (1) a Tap developer/sandbox account with a Secret API Key (`sk_test_…`); (2) a reachable public HTTPS base URL for `post.url` (Tap cannot POST to localhost — official doc, fetched 2026-10-27); (3) dashboard access to check the account's enabled currencies. The full step-by-step operator checklist is §15.9 (steps 1-8, unchanged). No sandbox probe was attempted; no sandbox result is claimed.

### 16.8 Load-testing status — **NOT RUN (BLOCKED)**

No approved staging environment exists in this workspace; the dev-machine MySQL exhibits the documented intra-suite cascade (§16.3 F2) even for test-only runs, and no standing measurement harness exists. Per rule #7/#10: no load metric is claimed. Safe execution plan (unchanged from §15.10 + §13): isolated staging DB, `Http::fake()`-style mocked provider transport (never Tap production), baseline → gradual ramp, measure throughput/p50/p95/p99/error/timeout/duplicate-txn/DB-contention, stop on instability. Revisit trigger: staging availability.

### 16.9 Remaining uncertainties & required operator actions

1. **Merchant currency entitlements** (§14#6) — sandbox/dashboard check required (§15.9 step 8).
2. **Refund `int32`-vs-decimal doc conflict** (§14#3) — sandbox probe on a 3dp refund (§15.9 step 7).
3. **Webhook retry cadence/backoff** (§14#4) — the fetched doc (2026-10-04 version) does not document retry behavior; design remains safe (idempotent handler + dedupe + throttling). Unresolved.
4. **`post.url` transition coverage** (§14#5) — handler tolerates any status; unresolved but non-blocking.
5. **LOW — tap-specific webhook currency-mismatch feature test** — the shared `assertNoMismatch` authority is currency-mismatch-verified (§16.4) but a tap-webhook-specific test would pin the adapter's echo path end-to-end. Nice-to-have, not a defect.
6. **Operator actions:** run §15.9 steps 1-8 with sandbox credentials; decide the follow-up for the two pre-existing red suites (F2/F3); optionally add the F5 test.

### 16.10 Changed files & diff summary (unchanged by this audit — net zero code delta)

7 modified + 5 new code/test files exactly as §15.1 (this audit's only deltas: one temporary mutation applied and restored in `PaymentRefundService.php`, verified by `php -l` + `git diff --stat`; `.phpunit.cache/test-results` restored to HEAD after the runs). No commit or push was performed at any point in this audit.

### 16.11 No commit/push confirmation

VERIFIED: `git status --porcelain` shows only the task's uncommitted changes; `git stash list` unchanged (one pre-existing user stash); no push (no network git operations executed).

### 16.12 Final readiness decision

**`READY FOR SANDBOX VERIFICATION`** — re-affirmed with fresh evidence: static review clean (0 new defects; no BLOCKER/CRITICAL/HIGH), 203 reproduced passing tests covering the full Tap surface and payment/currency/refund/concurrency regressions, webhook mechanism re-verified against the current official documentation (2026-10-04 revision), dynamic currency handling verified with no runtime hardcode. Actual provider behavior (merchant entitlements, real hashstring vectors, refund semantics) remains unverified pending credentials — the sandbox E2E checklist (§15.9) and safe load plan (§16.8) are prepared for the operator.

---

# Part 4 — End-to-End Flow Re-Verification & Developer Guide (2026-10-27, post-Part-3 session)

## 17.1 Purpose and scope

Post-Part-3 request: verify the actual flow end-to-end from the current working tree (no assumption that prior fixes are in place), run the available tests with fresh evidence, fix any newly confirmed issues, update this report, and produce a developer learning guide. No new business logic was introduced; this part adds verification evidence and one documentation deliverable only.

## 17.2 Baseline re-confirmed

- Working tree identical to §16.10 (7 modified + 5 new; the pre-existing user stash untouched). VERIFIED via `git status --porcelain`.
- PHP 8.2.30, Laravel 10.30.1. `composer.json` has no pint/phpstan/psalm — static analysis remains `php -l`.

## 17.3 Flow re-trace (request phases A-E; every anchor re-read from source this session)

- **Initiation:** `OrderController::checkout` (L142) → amount from `$order->total_price`, rounded per the resolver currency (`CurrencyPrecision::roundForCurrency`, L186-199) → zero-value D-05 branch (`completeZeroValueOnlineOrder` L224) → `PaymentCheckoutHandler::handleOnlinePayment` (L28-140): registry gate `canInitiate` (L36-53), adapter build (L55-59), **double `supportsCurrency` gate** (L66-78 — registry definition vs live adapter, drift fails closed), coupon reservation (L80-91), `createInvoice` (L96), fail-closed on exception or unsuccessful result with coupon release (L102-118), `Transaction::create` status `pending` with resolver currency (L123-133), redirect URL returned (L139).
- **Tap adapter** (`TapGateway.php`): `createInvoice` L83-186 (currency from resolver L90; amount as JSON number at the currency's ISO exponent L106-108; `reference.idempotent = 'order-{id}'` L129-135; `post.url` auto-filled L126-128; `threeDSecure` true L137); `verifyPayment` L188-243 (id regex + `urlencode` L192-199; success EXCLUSIVELY the verified `CAPTURED` status L230-232; provider amount/currency echoed for the completion authority L224-228); `refund` L245+ (currency from resolver L250); single network seam `request()` L378-405 (fail-closed on missing config and on any Throwable; 30s timeout; no provider detail echoed).
- **Callback:** `checkoutCallback` L320 — `paymentReference()` call L322 (helper L896-912: `paymentId` precedence, `tap_id` fallback only), id guards L323-328, gateway name from the transaction row L334-340, `verifyPayment` L351, lookup by `gateway_transaction_id`/`invoice_id`, locked completion L451-509 (Transaction lock → Order lock) via `PaymentCompletionService::completeLocked` (token-first idempotency L54-70, secondary order-pending check L77-96, D-06 DuplicateHold L82-86, `assertNoMismatch` L98).
- **Webhook:** `PaymentWebhookController::tap` L304-384 — 503 missing secret (L308-312), 400 empty/invalid body (L316-328), `hashstring` preferred with `hash` fallback only when hashstring absent (L330-336), verifier exception → unverified (L338-342), 401 on mismatch (L344-351), charge-id pattern guard (L353-360), synthesized dedupe id (L362-367; `WEBHOOK_EVENT_IDS_CAP = 20`, L58), `CAPTURED` → `completeFromProviderRef` (L369-371), `TAP_TERMINAL_FAILURES` (8 statuses, L66-68) → `markFailedByProviderRef` (L373-375), everything else 200 ignored (L377-383); `completeFromProviderRef` L395+ — unknown ref 200 ack, gateway mismatch 400, duplicate-event ack, factory re-verify (L456), fail → `markFailedWithResult` (L459), locked completion inside `DB::transaction` (L469+).
- **Refund:** `PaymentRefundService::refund` L76+ — Transaction locks first (refundable L91-95; replay scan L110-114, deterministic `orderByDesc('id')`), Order lock L130, replay return L132-143, terminal guards L145-151. The F-TAP-REPLAY and F-AUDIT-01 fixes are present exactly as documented (§16.5-16.6); mutation evidence (§16.6) unchanged.

## 17.4 Fresh test evidence (this session; every run with `DB_DATABASE=catch_verify`)

| Command | Result |
|---|---|
| `php artisan test --filter="TapGatewayTest\|TapWebhookVerifierTest"` | **24 passed (97 assertions)** |
| `php artisan test --filter="TapWebhookTest\|TapGatewayFlowTest"` | **25 passed (190 assertions)** |
| `php artisan test --filter='PaymentSecurityTest\|RefundCurrencyAndLedgerTest\|ExternalRefundTest\|PaymentConcurrencyTest\|Tests\\Feature\\Payment\\PaymentCompletionTest::\|CurrencyPrecisionTest\|CatalogCurrencyAuthorityTest'` | **52 passed (523 assertions)** — matches the §16 post-review result exactly |
| `php -l` × 7 changed PHP files | 7/7 PASS |

Tooling note (VERIFIED this session): the same composite filter passed **double-quoted** in a prior run but returned `No tests found` in this session's PowerShell invocation; the **single-quoted** form with `\|` separators reproduces the documented 52-passed result. Use the single-quoted form on this machine. All other filters unchanged.

## 17.5 Newly confirmed defects

**None.** The flow re-trace found no defect beyond those already fixed and documented (§15.3 F-TAP-REPLAY, §16.5 M1). No code was modified in this session.

## 17.6 New deliverable — developer learning guide

`docs/guides/tap-payments-flow-explained.md` — Egyptian-Arabic teaching guide (identifiers in English): big-picture concepts (order vs transaction vs Tap charge; why charge creation ≠ payment confirmation), real file responsibilities with line anchors, end-to-end Mermaid sequence, line-by-line walkthroughs of the checkout initiation, charge payload, HTTP seam, verification, hashstring verifier, webhook shields, completion authority, callback reference resolution, and refund replay logic, the Laravel concepts as actually used here, real status values and transitions, security rationale with explicit gaps, a test tutorial with safe repeatable commands, a fictional 100.00 USD / 13.255 KWD walkthrough (clearly labeled non-real), and 5 exercises with solutions.

**Guide accuracy pass:** every line reference was verified against source before finalizing; **27 corrections** were applied during that pass (e.g., `checkoutErrorCallback` is L620 not L628; resolver range is L26-48 including the documented config fallback; verifier method span L41-75; refund lock labels L91-95/L110-114/L130). The published guide contains no unverified line references.

## 17.7 Checklist deltas (this session)

| Check | Prior | Now |
|---|---|---|
| Phase 3 matrix (§16.2 items 1-14) | PASS | PASS — re-verified, no drift; flow anchors re-confirmed |
| Regression set | 52 passed | **52 passed (fresh re-run)** |
| Static analysis | `php -l` PASS | `php -l` 7/7 PASS |
| Sandbox E2E | BLOCKED | BLOCKED (unchanged — §16.7) |
| Load test | NOT RUN | NOT RUN (unchanged — §16.8) |

## 17.8 Final readiness decision — unchanged

**`READY FOR SANDBOX VERIFICATION`** — re-affirmed a third time with this session's fresh reproduction (24 + 25 + 52 = **101 tests / 810 assertions** green in this session, `php -l` clean, guide accuracy pass complete, **zero new defects**). The open items remain exactly those of §16.9; no new blocker was introduced.

