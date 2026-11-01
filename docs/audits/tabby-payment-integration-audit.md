# Tabby Payment Integration Audit — Discovery, Design & Plan

| | |
|---|---|
| **Task** | Integrate Tabby (MENA Buy Now Pay Later) as a new, optional, independently configurable payment gateway using the existing payment architecture — no new payment system around Tabby |
| **Repository** | `D:\work\meem` — Laravel 10.30.1 monolith (`app/` custom layer + `packages/marvel/` vendored kernel), PHP platform `^8.0|^8.1`, runtime PHP 8.5.8 |
| **Date** | 2026-10-31 |
| **Phase** | Part 1 = §1–§17: read-only discovery + Tabby API research + implementation plan (written **before any code change**) |
| **Confidence legend** | **VERIFIED** = confirmed by inspected code/config/runtime · **DOC-FACT** = verified in official docs.tabby.ai pages fetched 2026-10-31 · **TESTED** = command executed with recorded result · **INFERRED** = reasoned from verified evidence · **UNKNOWN** = insufficient evidence · **RISK** = confirmed/credible exposure |

---

## 1. Executive Summary

- The live payment architecture is config-driven and gateway-agnostic: `config/payment.php` defines gateways; `GatewaySettingsService` merges them with admin DB overrides; `PaymentGatewayRegistry` guards initiation; `PaymentCompletionService::completeLocked()` is the **single canonical completion authority** for every entry point (browser callback, Stripe/PayPal/Tap webhooks, zero-value, COD/cashier mark-paid). **[VERIFIED]**
- Adding Tabby requires: **one config entry**, **one adapter** (`App\Services\Gateway\TabbyGateway` implementing `PaymentGatewayContract`), **one webhook verifier**, **one webhook controller method + route**, and **one tiny fallback** in `OrderController::paymentReference()` because Tabby's redirect carries `payment_id` (DOC-FACT) while the callback currently reads `paymentId`/`tap_id`. **No database migration, no new interface, no binding changes.** **[VERIFIED design feasibility]**
- Tabby is **fundamentally different from every current gateway in one respect**: it is an *authorize-then-capture* BNPL flow. `AUTHORIZED` (customer verified via OTP; funds reserved) must be **completed locally AND captured** server-side (`POST /payments/{id}/captures` with an idempotent `reference_id`) before settlement. The chosen design (see §7–§8, Decision D1) folds capture into `verifyPayment()` with Tabby-side idempotency — the same `GatewayResult` contract, the same central completion path, no interface change. **[DOC-FACT + design decision]**
- Tabby currencies today: **AED and SAR only** (DOC-FACT: the `Currency` schema enum). A store whose catalog currency is not AED/SAR automatically hides Tabby in the public `payment-gateways` endpoint (existing `supports_catalog_currency` flag) and fails closed with 422 at checkout. **[VERIFIED mechanism + DOC-FACT currency list]**
- Tabby is disabled by default (`TABBY_ENABLED=false`); missing credentials fail closed at `isConfigured()`/`canInitiate()` with 422 and **no provider call**. Enabling touches nothing else. **[VERIFIED pattern — identical to Tap/Stripe/PayPal]**
- Runtime verification constraints in this environment: **MySQL is unreachable** (probe `scratch/db_probe_readonly.php`: `SQLSTATE[HY000] 2002 connection actively refused`). DB-backed feature tests **cannot run here**; DB-free unit tests **can and already run** (existing Tap unit suites: 16 tests / 82 assertions — executed today, green). The suite additionally contains a hard guard (`tests/CreatesApplication.php` L27-33) that refuses any run against the shared `catch` DB unless explicitly overridden — the incident guard from `error.md`. **[TESTED]**

---

## 2. Existing Payment Architecture

### 2.1 Registry & configuration (all `app/Services/Payment/` unless noted)

| Class | Role | Key lines | Confidence |
|---|---|---|---|
| `Contracts\PaymentGatewayContract` | The one adapter contract: `createInvoice(Order, float, callbackUrl, errorUrl, metadata): GatewayResult`, `verifyPayment(string): GatewayResult`, `refund(Order, float, ?reason): GatewayResult`, `name(), code(), isConfigured(), supportsCurrency(string)` | whole file (23 L) | VERIFIED |
| `PaymentGatewayRegistry` | `resolve(code)` instantiates `definition['class']` via `app($class)` and must implement the contract; `canInitiate(code, method, currency)` = definition exists → enabled → class exists → adapter configs → method allowed → currency supported (fail-closed, reason-coded: `unknown / disabled / misconfigured / method_unsupported / currency_unsupported`); **`canVerify()` deliberately ignores `enabled`** so in-flight payments complete after a mid-payment disable | L28-44, L49-106, L108-119 | VERIFIED |
| `PaymentGatewayFactory` | Thin seam over registry; carries the INITIATE vs VERIFY split documentation | L27-30 | VERIFIED |
| `GatewaySettingsService` | Merges `config('payment.gateways')` with `settings.options['payment_gateways']` DB overrides; **read+write allowlist `{enabled, display_name, sort_order}` only** — secrets/class/currencies/methods stay env-only even against a hostile settings row; `getAdminView()` never exposes secrets; `getPublicView($catalogCode)` is the storefront-safe availability snapshot | L228-254, L66-106, L117-146, L156-223 | VERIFIED |
| `PaymentCheckoutHandler` | **Initiation authority**: `canInitiate` gate → 422 with translations; factory `make()`; deliberate double `supportsCurrency` check (registry view + live adapter); coupon reservation BEFORE invoice (releases reservation on failure/exception); `createInvoice()`; creates the single pending `Transaction` row (`invoice_id`, `gateway_transaction_id`, `payment_method` = gateway code, `status='pending'`, `amount`, `currency`, `gateway_response` + `_callback_type`); returns `{url: redirectUrl}` | L28-140 | VERIFIED |
| `PaymentCompletionService` | **Canonical completion authority** (see §5) | L48-103, L157-264, L272-310 | VERIFIED |
| `PaymentCurrencyResolver` | Catalog currency authority: order snapshot `catalog_currency_code` → `currency_code` → `CurrencyService::getCatalogCode()` → config `payment.default_currency` | L26-49 | VERIFIED |
| `CurrencyPrecision` | ISO 4217 exponent authority: 3dp set = BHD/JOD/KWD/OMR/TND; everything else 2dp. `decimalsFor/roundForCurrency/toMinorUnits/fromMinorUnits/formatForGateway` (string formatting for decimal-string gateway APIs). Stripe zero-decimal override intentionally local to StripeGateway | L14-59 | VERIFIED |
| `CustomerContactResolver` | Gateway-safe buyer email; deterministic unroutable fallback `order-{id}@no-email.meem.local` for guests (never persisted) | L14-31 | VERIFIED |
| `StripeWebhookVerifier` / `PayPalWebhookVerifier` | SDK-backed signature-verification seams, injectable in tests | whole files | VERIFIED |
| `PaymentRefundService` | **Refund authority**: DB transaction, Transaction→Order lock order, `_refunds` idempotent ledger on `gateway_response`, per-transaction currency authorization, adapter `refund()` fail-closed, full refund → `payment_status=payment-refunded` + inventory restore + coupon release; `recordExternalRefund()` for provider-initiated refunds (used by PayPal webhooks) | L63-69, L76-309, L330-427 | VERIFIED (signatures; body read during implementation) |
| `App\DTOs\GatewayResult` | Value object: `success, redirectUrl, gatewayTransactionId, amount, currency, status, errorMessage, rawResponse` | L5-20 | VERIFIED |

### 2.2 Routes & throttling

| Route | Controller | Middleware | Name |
|---|---|---|---|
| `POST api/v1/general/checkout` | `App\...\OrderController::checkout` (also handles `online` gateway dispatch) | `api`, `throttle:public-api` | — |
| `GET|POST api/v1/general/checkout/callback` | `OrderController::checkoutCallback` | `throttle:payment-callback` (20/min/IP) | `api.checkout.callback` |
| `GET|POST api/v1/general/checkout/error-callback` | `OrderController::checkoutErrorCallback` | `throttle:payment-callback` | `api.checkout.errorCallback` |
| `POST api/v1/general/checkout/webhooks/stripe` | `PaymentWebhookController::stripe` | `throttle:payment-webhook` | `api.checkout.webhooks.stripe` |
| `POST api/v1/general/checkout/webhooks/paypal` | `PaymentWebhookController::paypal` | `throttle:payment-webhook` | `api.checkout.webhooks.paypal` |
| `POST api/v1/general/checkout/webhooks/tap` | `PaymentWebhookController::tap` | `throttle:payment-webhook` | `api.checkout.webhooks.tap` |
| `GET api/v1/general/payment-gateways` | `PaymentGatewayController::index` | public | `api.general.payment-gateways.index` |
| `GET/PUT api/v1/admin/payment-gateways...` | `PaymentGatewaySettingsController` | admin auth | admin settings |

Evidence: `routes/api.php` L51-160; `app/Providers/AppServiceProvider.php` L145-167. **[VERIFIED]**

- `OrderCreateRequest` (Marvel kernel) validates `payment_method` ∈ `online,cod,pay_at_cashier` and `gateway` as `nullable|string|max:50` — an unknown gateway string can never initiate anything because `canInitiate` returns `unknown` → 422. **[VERIFIED]**
- MyFatoorah intentionally has **no webhook** (browser-callback only). Comment evidence: `routes/api.php` L134 + `PaymentWebhookController` class docblock. **[VERIFIED]**

---

## 3. Existing Gateway Comparison

### 3.1 App-layer adapters (`app/Services/Gateway/*.php`)

| Aspect | MyFatoorahGateway | StripeGateway | PayPalGateway | TapGateway |
|---|---|---|---|---|
| **Entry point** | `createInvoice` → SendPayment | `createInvoice` → Checkout Sessions | `createInvoice` → Orders v2 create | `createInvoice` → `POST /charges` |
| **Registered via** | config `payment.gateways.myfatoorah.class` | same pattern | same (string class incl.) | same |
| **Credentials** | `MYFATOORAH_API_KEY` + `MYFATOORAH_BASE_URL` | `STRIPE_SECRET_KEY` + `STRIPE_WEBHOOK_SECRET` | `PAYPAL_MODE`, `PAYPAL_CLIENT_ID/SECRET`, `PAYPAL_WEBHOOK_ID` | `TAP_SECRET_KEY` + `TAP_BASE_URL` + `TAP_SOURCE_ID` |
| **Session creation** | SendPayment → `Data.InvoiceURL` + `Data.InvoiceId` | checkout session; minor-unit conversion; `{CHECKOUT_SESSION_ID}` success_url template | `PayPal-Request-Id` idempotent create (CAPTURE intent) | JSON charge; `transaction.url` is the HPP redirect |
| **Customer redirect** | `GatewayResult.redirectUrl` → frontend/checkout returns `{url}` | same | same | same |
| **Status communication** | Browser callback only (`paymentId`) | Browser callback + `checkout.session.completed` / `payment_intent.payment_failed` webhooks (raw-body signature) | Browser callback + `PAYMENT.CAPTURE.COMPLETED/DENIED/REFUNDED` webhooks (SDK verify) | Browser callback (`tap_id` fallback) + webhook on charge `post.url` (`hashstring` HMAC-SHA256) |
| **Signature/webhook authenticity** | n/a (does re-verify every callback via checkInvoice) | Stripe-Signature over RAW body, timestamp tolerance, single-use event cache | PayPal SDK verify-webhook-signature (6 transmission headers + webhook_id) | Tap hashstring: HMAC-SHA256 over documented field concatenation of the charge object, amount rounded per currency, `hash_equals` (upgrade over doc sample `==`); `hashstring` preferred, `hash` tolerated |
| **Transaction update** | verify → `InvoiceStatus==='Paid'` exclusively | session `payment_status==='paid'` + PaymentIntent cross-check | status APPROVED → idempotent capture `capture-{id}` → COMPLETED only | `status==='CAPTURED'` exclusively, provider-echoed amount/currency |
| **Duplicate callbacks** | idempotency token (`transactions.idempotency_key`) is the primary guard; then order-not-pending; then DuplicateHold reconciliation row | same shared path | same | same |
| **Central completion** | `PaymentCompletionService::completeLocked` under `DB::transaction` + `lockForUpdate` (txn, then order) | same | same | same |
| **Failure/cancel** | fail-mark pending row, `PaymentFailed` event, redirect to `/payment/failed` | same + `payment_intent.payment_failed` mapping | same + capture race re-fetch; DENIED → failed | same; INITIATED/non-terminal → 200 ignored; terminal statuses → failed |
| **Refund** | MakeRefund + `isRefundConfirmed()` regex | `refunds.create`; only `status==='succeeded'` | `refundCapturedPayment`; only COMPLETED | `POST /refunds` map `reason` to documented enum; only `status==='REFUNDED'` |
| **Tests** | (see §16) | Stripe webhook unit scope | PayPal webhook tests | `tests/Unit/TapGatewayTest` (16 ✅ executed today), `tests/Unit/TapWebhookVerifierTest` (both green), `tests/Feature/Payment/TapGatewayFlowTest` + `TapWebhookTest` (DB-backed, **not runnable here**) |
| **PII handling** | `RESPONSE_ALLOWLIST` sanitize | PII-free allowlists, `sk_` redaction | at-rest only | `CHARGE_ALLOWLIST`/`REFUND_ALLOWLIST`, `sk_` redaction, bounded error messages |

### 3.2 Marvel kernel gateways (`packages/marvel/src/Payment/*`)

`Base.php` + Flutterwave, Iyzico, Mollie, Paymongo, Paypal, Paystack, Razorpay, Stripe + `PaymentInterface`. These are **vendored legacy** implementations. Runtime evidence shows the modern flow is owned by `app/`: the checkout route, callbacks and webhook routes all bind `App\Http\Controllers\...` controllers; the app-layer `PaymentGatewayFactory`/registry are the only resolution used by `PaymentCheckoutHandler`, `PaymentRefundService`, webhooks, `CancelUnpaidOrders`, and `PaymentReconciliationJob`. Marvel's `OrderController` variants remain routed for legacy admin flows but the money-completion authority is the app-layer service. **Conclusion: canonical ownership = app layer (`app/Services/Payment/*` + `app/Services/Gateway/*`); Tabby MUST be added there, not in Marvel.** [Durable project rule: AGENTS.md §21.2/§21.5] **[VERIFIED + repo policy]**

### 3.3 Tabby vs the existing gateways — the one architecture-relevant difference

| Capability | Tap (closest precedent) | Tabby |
|---|---|---|
| `verifyPayment` returns paid when… | charge `CAPTURED` (money already captured) | payment `AUTHORIZED`/`CLOSED` — and **AUTHORIZED still requires a merchant capture call** to reach settlement |
| Provider idempotency | `reference.idempotent` (24h) on charge/refund | **`reference_id` on capture and refund only** (create-session has none) |
| Webhook authenticity | hashstring HMAC over payload fields | **opt-in registration-time header** whose value is a shared secret Tabby echoes back on every webhook (constant-time compare) + IP allowlist |
| Statuses (API / webhook) | CAPTURED/FAILED/… (same casing) | `AUTHORIZED` (API, uppercase) / `authorized` (webhook, lowercase) — compare case-insensitively (DOC-FACT) |
| Refund reason | documented enum required | free-text `reason`, optional |
| Refund window | — | 180 days from creation (DOC-FACT) |

Consequence: `verifyPayment(string $gatewayTransactionId)` has only the payment id — the capture amount must come from the retrieved payment itself (docs: capture the full amount; Tabby validates it matches), and the idempotency key must be derived **from the payment id** (`capture-{payment_id}`), not the clock (DOC-FACT: "Derive the key from your order, not from the clock"). Both callback and webhook paths hit the same `verifyPayment`, so both derive the **same** key → Tabby deduplicates the capture. This mirrors the docs' recommended "shared `reference_id`" guard for exactly the two-code-path race we have. **[DOC-FACT + INFERRED design]**

---

## 4. Exact Files and Classes Involved

**App layer (canonical, all VERIFIED):**

- `config/payment.php` (38 L includes) — gateway definitions
- `app/Services/Payment/Contracts/PaymentGatewayContract.php`
- `app/Services/Payment/PaymentGatewayRegistry.php`
- `app/Services/Payment/PaymentGatewayFactory.php`
- `app/Services/Payment/GatewaySettingsService.php`
- `app/Services/Payment/PaymentCheckoutHandler.php`
- `app/Services/Payment/PaymentCompletionService.php` (+ `PaymentCompletionOutcome.php` enum: `Processed | IdempotentReplay | OrderNotPending | UnknownOrder | DuplicateHold`)
- `app/Services/Payment/PaymentRefundService.php`
- `app/Services/Payment/PaymentCurrencyResolver.php`, `CurrencyPrecision.php`, `CustomerContactResolver.php`
- `app/Services/Payment/{Stripe,Tap,PayPal}WebhookVerifier.php`
- `app/Services/Gateway/{MyFatoorah,Stripe,PayPal,Tap}Gateway.php`
- `app/Http/Controllers/Api/General/OrderController.php` (checkout L142-211; `checkoutCallback` L320-618; `paymentReference` L903-912; `getCallbackType` L914-927; `completeZeroValueOnlineOrder` L224-272)
- `app/Http/Controllers/Api/General/PaymentWebhookController.php` (1153 L: `stripe` L68, `paypal` L166, `tap` L304-384, shared `completeFromProviderRef` L395-615, `markFailedByProviderRef` L622-731, `markFailedWithResult` L737-803, `markCouponBlocked` L810-873, `recordPayPalExternalRefund` L900-995, `findTxnByRef` L1001-1015, event-id dedupe helpers L1042-1153)
- `app/Http/Controllers/Api/General/PaymentGatewayController.php` (public view)
- `app/Http/Controllers/Api/Admin/PaymentGatewaySettingsController.php` + `app/Http/Requests/Payment/UpdateGatewaySettingsRequest.php`
- `app/DTOs/GatewayResult.php`
- `app/Providers/AppServiceProvider.php` (rate limiters L145-167)
- `routes/api.php` (callbacks L130-138, checkout L149-160)
- Framework: `app/Jobs/PaymentReconciliationJob.php` (reporting-only reconcile, every 15 min), `app/Console/Commands/CancelUnpaidOrders.php` (gateway pre-check `gatewayReportsPaid` L147-176)
- Models: `packages/marvel/src/Database/Models/Transaction.php` — fillable already covers everything needed: `invoice_id, payment_method, status, amount, currency, gateway_transaction_id, gateway_response (array cast), error_message, paid_at, idempotency_key` **[no migration needed — VERIFIED]**

**Tests:** `tests/Unit/TapGatewayTest`, `tests/Unit/TapWebhookVerifierTest`, `tests/Feature/Payment/TapGatewayFlowTest`, `tests/Feature/Payment/TapWebhookTest`, `tests/Feature/Currency/CurrencyTestCase` (base for DB-backed gateway flow tests).

---

## 5. Existing Payment Lifecycle (trace)

```
Checkout (POST /checkout, auth'd user)
  → OrderController::checkout (L142)
      cart lock & slice, OrderService::addItemsInOrder (order created PENDING,
      OrderReservationService reserveForOrder, coupon revalidated, totals snapshotted)
  → online ⇒ amount rounded at order-currency exponent (CurrencyPrecision, L189-192)
      ≤0 ⇒ local completion D-05 (no gateway);
      else PaymentCheckoutHandler::handleOnlinePayment (L28)
          ① registry.canInitiate(gateway,'online',orderCurrency)   → 422 fail-closed
          ② factory.make → adapter
          ③ adapter.supportsCurrency (double check)
          ④ coupon RESERVED (Rule 9)
          ⑤ adapter.createInvoice → GatewayResult{redirectUrl, gatewayTransactionId, …}
             (exception ⇢ release coupon, 500; failure ⇢ release coupon, 4xx/5xx)
          ⑥ Transaction::create(status=pending, gateway_transaction_id, invoice_id, currency, gateway_response+_callback_type)
          ⑦ response {url}
  → customer pays on provider HPP → provider redirects to
      api.checkout.callback (paymentId | tap_id)                [browser path]
      and/or provider posts the payment event to webhook        [server path]
  → OrderController::checkoutCallback / PaymentWebhookController::{gateway}
      reference param validate (regex ≤191)
      gateway resolved FROM THE TRANSACTION ROW (payment_method), factory make (verify path ignores enabled)
      adapter.verifyPayment(provider ref)   ← server-side re-verification; redirect params are untrusted hints
         ↳ for provider webhooks FIRST: verifier (signature) → only then completion
      unknown order ⇒ fail-safe (B4 / webhook ack)
      DB::transaction:
          lockForUpdate txn (by gateway_transaction_id ∨ invoice_id) → lockForUpdate order
          PaymentCompletionService::completeLocked(txn, order, result):
              A. txn.idempotency_key ≠ null ⇒ IdempotentReplay (PRIMARY guard)
              B. stamp fresh UUID token BEFORE logic (serialize concurrent holders)
              C. order.status ≠ 'pending' ⇒ OrderNotPending
                 (provider says paid on ANOTHER paid row ⇒ DuplicateHold reconciliation record)
              D. assertNoMismatch (fail-closed throw):
                 amount ×1000integer equality, currency upper-equality, provider-ref ∈ {txn.gateway_transaction_id, txn.invoice_id}
              E. commitLocked (byte-for-byte legacy sequence):
                 txn→paid(+paid_at, merged gateway_response),
                 order payment_status=SUCCESS + paid_at,
                 OrderReservationService::commit (exactly-once inventory claims),
                 OrderService::finalizePromotionUsageAfterPayment,
                 OrderService::changeOrderStatus(txn.invoice_id,'completed', …)   ← owns coupon usage; refusal throws CouponConsumptionException ⇒ caller txn ROLLS BACK
      PaymentMismatchException ⇒ txn marked failed inside the lock, token retained
      CouponConsumptionException ⇒ token rotated to null + _coupon_blocked markers, txn failed visible
      outcome.Processed ⇒ PaymentSucceeded event after commit (F-14: never with missing order)
      responses: mobile ⇒ JSON {status,payment_id,order_id}; web ⇒ 302 to frontend /payment/success|failed?order_id=…
  → reconciliation safety nets: payments:reconcile every 15 min (REPORTS mismatches only),
      orders:cancel-unpaid (reservation expiry ⇒ verifier pre-check gatewayReportsPaid before cancelling)
```

**Authoritative components table**

| Transition | Authority |
|---|---|
| Checkout totals / final amount | `OrderService::addItemsInOrder` + order snapshot `total_price` (rounded at checkout) |
| Currency | `PaymentCurrencyResolver::forOrder` |
| Precision | `CurrencyPrecision` |
| Initiation gate | `PaymentGatewayRegistry::canInitiate` (+ adapter double-check in handler) |
| Provider call | adapter `createInvoice` via `PaymentGatewayFactory` |
| Transaction persistence | `PaymentCheckoutHandler` (single pending row) |
| Verification | adapter `verifyPayment` (server-side re-fetch; browser/webhook params are untrusted hints) |
| **Completion** | `PaymentCompletionService::completeLocked` |
| Order status | `OrderService::changeOrderStatus` (Order Flow fail-closed) |
| Inventory | `OrderReservationService` (none→active→committed/released) |
| Refunds | `PaymentRefundService` (+ adapter `refund`) |

---

## 6. Extension Points for Tabby (exact)

1. **`config/payment.php` → new `gateways.tabby` block** (class, `enabled`, `secret_key`, `base_url`, `merchant_code`, `webhook_secret`, `webhook_header`, timeouts, `supported_currencies`, `methods:['online']`). Registry/settings/admin endpoints pick it up automatically — **no other registration code exists or is needed**. [VERIFIED — `GatewaySettingsService::merged()` is the only source]
2. **`App\Services\Gateway\TabbyGateway implements PaymentGatewayContract`** — new file; `PaymentGatewayContract` is already bindable by class name (no interface binding in a provider — checked: no `isProvider` bindings for gateways).
3. **`App\Services\Payment\TabbyWebhookVerifier`** — new final class; constant-time compare of the registration-time header value (DOC-FACT mechanism; no SDK exists to use).
4. **`PaymentWebhookController::tabby()` + route** `POST checkout/webhooks/tabby` (name `api.checkout.webhooks.tabby`, `throttle:payment-webhook`) — reuses `completeFromProviderRef`, `markFailedByProviderRef`, `findTxnByRef`, `_webhook_event_ids` dedupe as-is.
5. **`OrderController::paymentReference()`** — add `payment_id` as a lower-precedence fallback (Tabby redirect param; DOC-FACT). One-line-class-of-change; Tap's `tap_id` fallback is the exact precedent (L896-912).
6. **One new translation key** `message.ERROR.*` (en + ar) for a provider-side session rejection surfaced at checkout (see §14.4). Everything else reuses existing keys verified present: `message.ERROR.PAYMENT_CURRENCY_UNSUPPORTED` (L183), `message.ERROR.PAYMENT_GATEWAY_UNAVAILABLE` (L501).
7. **`.env.example`** — add documented `TABBY_*` block (mirror of the TAP block, L327-345).

**Explicitly NOT needed** (verified): no migration (Transaction fields suffice), no new service provider, no container binding, no frontend route, no additional translation groups, no changes to `PaymentCompletionService`, `PaymentCheckoutHandler`, `GatewaySettingsService`, `PaymentRefundService`.

---

## 7. Required Tabby API Operations (official docs, fetched 2026-10-31)

Sources: docs.tabby.ai — `/pay-in-4-custom-integration/quick-start.md`, `/checkout-flow.md`, `/payment-statuses.md`, `/payment-processing.md`, `/webhooks.md`, `/api-reference/checkout/session-payload-model.md`, `/api-reference/payments/{retrieve,capture,refund}-a-payment.md`, `/api-reference/webhooks/register-a-webhook.md`, `/api-reference/response-codes.md`, `/testing-guidelines/testing-credentials.md`.

### 7.1 Endpoints classified for this project

| Operation | Endpoint | Required? | Rationale |
|---|---|---|---|
| **Create checkout session** | `POST {base}/api/v2/checkout` — Bearer **secret key**; body: `payment{amount(str ≤2dp), currency(AED|SAR), buyer{name,email,phone}, shipping_address{city,address,zip}, order{reference_id, items[≥1: {title,quantity,unit_price,category}]}, buyer_history{registered_since}}, lang, merchant_code, merchant_urls{success,cancel,failure}` | **Required (initiation)** | Creates session **and** payment; `payment.id` is the persisted provider ref; HPP link = `configuration.available_products.installments[0].web_url` (validate presence; `status:"rejected"` ⇒ scoring rejection, no web_url) |
| **Retrieve payment** | `GET {base}/api/v2/payments/{payment_id}` | **Required (verification)** | Server-side truth; returns uppercase status + `captures[]` + `refunds[]` + amount/currency |
| **Capture payment** | `POST {base}/api/v2/payments/{id}/captures` — `{amount, reference_id}` both required | **Required (Tabby-specific)** | `AUTHORIZED` ⇒ funds reserved, not settled. Full capture ⇒ `CLOSED` ⇒ settled. `reference_id` = idempotency key (retries reuse it). 400 `already closed` / `could not capture not authorized payments`; 409 `payment is locked` ⇒ retry same key |
| **Refund** | `POST {base}/api/v2/payments/{id}/refunds` — `{amount, reference_id, reason?}` both required | **Required (admin refund path)** | Only `CLOSED` payments; ≤ captured amount; multiple partial refunds; 180-day window |
| **Webhook registration** | `POST {base}/api/v1/webhooks` — headers `Authorization: Bearer sk_…` + `X-Merchant-Code`; body `{url, header:{title,value?}}`; ≤4 webhooks per merchant_code+key | **Operational (merchant action, no code)** | Self-service; environment derives from the key (test vs live); signing header is what our verifier compares |
| Close payment | `POST {base}/api/v2/payments/{id}/close` | **Optional — NOT integrated (Decision D4)** | Cancels an authorized-but-uncaptured payment. Our flow captures immediately at `verifyPayment`; the dashboard/marketplace handles the residual case manually |
| List payments / update payment / disputes / on-site messaging | — | **Not integrated** | Out of scope for this checkout flow (YAGNI; disputes arrive via dots dashboard) |

### 7.2 Payment session vs payment object vs lifecycle (DOC-FACT, explicit)

- **Checkout session** — the `POST /checkout` envelope; creates a Payment immediately at `CREATED`. Distinct from the **payment** object addressed later by `payment.id`. Session ≠ payment ⇒ our transaction stores the **payment id**, not the session id.
- **Payment statuses** (API uppercase): `CREATED` (session incomplete) → `AUTHORIZED` (OTP verified, funds reserved) → `CLOSED` (captured in full / merchant-closed) — terminal but refundable; `REJECTED` (Tabby declined) and `EXPIRED` (customer cancelled / ~20-min session timeout + ~10 min) — terminal, no action required.
- **Authorization** = merchant-obligating guarantee (customer committed). **Capture** = merchant acceptance (`{amount, reference_id}`; full capture closes). **Settlement** = post-close payout (Tabby-side). **Cancellation** = Close without capture (customer pays nothing). **Refund** = only after capture ≤ captured amount.
- Webhook payloads use **lowercase** statuses; API uppercase — compare case-insensitively (DOC-FACT). Webhook payload = the payment object incl. `captures[]`, `refunds[]`, `order.reference_id`, `amount`, `currency`, `status`, `id`.

### 7.3 Tabby → local status mapping (Transaction, canonical `GatewayResult.status`)

| Tabby (verify/webhook) | Interpretation | `verifyPayment` outcome |
|---|---|---|
| `AUTHORIZED` (+captcha empty) | customer committed; funds reserved | **attempt full capture** (idempotent `capture-{payment_id}`) ⇒ success `paid` when payment reaches `CLOSED` with captures |
| `AUTHORIZED` (raw `captures[]` non-empty — webhook confirmation case) | capture already applied | success `paid` |
| `CLOSED` with `captures[]` non-empty | captured (maybe by us, partially-then-closed, or by Tabby's 21-day missing-capture policy) | success `paid` (provider amount/currency echoed for `assertNoMismatch`) |
| `CLOSED` with empty captures | merchant closed without capture (customer pays nothing) | failure `failed` |
| `REJECTED` | Tabby declined | failure `failed` |
| `EXPIRED` | customer abandoned / session timed out | failure `failed` |
| `CREATED` | checkout still in progress | failure with `status='pending'` semantics (row stays pending — **no terminal failure marking**) |

---

## 8. Security Considerations

| Threat | Control | Evidence |
|---|---|---|
| Forged/skipped verification | Browser/webhook params are lookup hints only; every completion path re-verifies server-side (`GET /payments/{id}`) before `completeLocked` | OrderController L320-618; WebhookController L395-460; DOC-FACT "Never rely on the redirect alone" |
| Webhook authenticity | `webhook_secret` (registration-time header value) compared **constant-time** (`hash_equals`) against the configured header title (default `X-Auth-Key`); missing/wrong ⇒ 401; missing secret config ⇒ 503 fail-closed | DOC-FACT register-a-webhook + webhooks page; repo precedent: Tap hashstring verifier fail-closed |
| Secret leakage | Secret key only in env-backed config; never in stored payloads/logs; error strings redacted (`sk_(live\|test)_…` → `[redacted]`, mirroring Tap/Stripe), bounded to 300/500 chars, `strip_tags` | TapGateway L459-479 precedent |
| PII / card material | Provider response allowlists ONLY technical fields (no buyer phone/address echoed); webhook bodies never logged; `gateway_response` gets allowlisted fields + dedupe list | TapGateway L27-41 precedent |
| Duplicate completion | Primary: txn `idempotency_key` stamped inside row lock; secondary: order-not-pending; tertiary: DuplicateHold reconciliation row; webhook-level: `_webhook_event_ids` capped dedupe list | PaymentCompletionService L63-103; WebhookController L997-1153 |
| Amount tampering | `assertNoMismatch` ×1000 integer comparison provider-vs-order-snapshot + currency + provider-ref binding | PaymentCompletionService L157-264 |
| Wrong gateway binding | Webhook path requires `txn.payment_method === 'tabby'` (existing `completeFromProviderRef` check L413-421) | VERIFIED |
| Abuse/rate | 20/min/IP webhook throttle; public gateways endpoint rate-limited; `payment-gateways` public view exposes no secrets | AppServiceProvider L145-167 |
| Callback header | `payment_id` accepted as fallback but validated `strlen ≤ 191` + `^[A-Za-z0-9\-_]+$` (UUIDs pass) — same regex as existing refs | OrderController L326-328 |
| TLS | Laravel `Http` client with default verified TLS; **no** `withoutVerifying` anywhere in payment code | grep-verified pattern (TapGateway `request()` seam) |
| Region confusion | Wrong base URL returns 403 `no_permission` (DOC-FACT) — fail closed; base URL is single config value | Tabby response-codes page |

---

## 9. Currency and Amount Handling

- **Order currency is authoritative**: `PaymentCurrencyResolver::forOrder($order)` (snapshot `catalog_currency_code` → `currency_code` → catalog). Never hardcoded in the adapter. **[VERIFIED]**
- **Tabby currencies: AED, SAR** (DOC-FACT `Currency` enum "currently there are 2 possible currency options"; the capture schema mentions "up to 3 decimals for KWD" but the enum is authoritative ⇒ default `supported_currencies=['AED','SAR']`, env-overridable if Tabby later enables more). A store settled in AED/SAR sees Tabby in the public list; anything else fails closed at `canInitiate` → 422 `PAYMENT_CURRENCY_UNSUPPORTED`. **[VERIFIED mechanism]**
- **Amount formatting**: Tabby amounts are **strings**, "up to 2 decimals for AED and SAR" (DOC-FACT). `CurrencyPrecision::formatForGateway($amount, $currency)` produces exactly `'13.25'`-style strings — the same helper PayPalGateway uses. Checkout already rounds the order total at the currency exponent (OrderController L189-192). **[VERIFIED]**
- **Completion check**: provider-echoed float amount vs `order.total_price`, ×1000 integer equality (existing authority, insensitive to 2dp/3dp as both sides scale identically). Tabby echoes `payment.amount` as a string float — cast to float at the adapter boundary, provider value wins (TINs same contract as Tap `verifyPayment`). **[VERIFIED authority]**
- The capture amount = **the retrieved payment's own amount string** (docs: "Tabby verifies that the amount in the Capture Request matches the payment amount") — never recomputed locally. **[DOC-FACT]**

---

## 10. Webhook / Callback Strategy

**Callback (browser)** — Tabby redirects to `merchant_urls` with `payment_id` query param (DOC-FACT):
- `success` URL and `cancel` URL and `failure` URL all can go to the **same** generic `api.checkout.callback` route: success lands `AUTHORIZED`/`CLOSED` (verification completes the order), cancel ⇒ `EXPIRED` (verify ⇒ failed ⇒ user redirected to `/payment/failed` with order id, cart already consumed, retry possible), failure ⇒ `REJECTED` (same failure UX). This keeps a single generic server callback exactly like Tap's `tap_id` handling. `paymentReference()` gains a `payment_id` fallback (lowest precedence; `paymentId` keeps first place; `tap_id` second).
- The callback is a **frontend navigation signal only**; the backend always re-verifies (DOC-FACT) — already the repo behavior.

**Webhook (server-to-server)** — new `POST checkout/webhooks/tabby`:
1. Config present? (`webhook_secret`) — else 503.
2. Header `<{webhook_header, default X-Auth-Key}>` value vs `webhook_secret` via `hash_equals` — miss ⇒ 401. (Also log-only IP note from the docs allowlist is NOT enforced in code — documented rationale: IP lists change operationally; the shared-secret header is the verification mechanism. R2 in §17.)
3. Parse body; require `id` (payment id, UUID-shaped via existing regex/length constraints) and string `status` (case-insensitive).
4. Dedupe id synthesis (`_webhook_event_ids` cap 20):
   - completion/marking events ⇒ `paymentId:status`
   - refund events (payload `refunds[]` non-empty) ⇒ per-refund `paymentId:refund:{refund.id}` — avoids colliding with the completion `closed` event, so a later refund notification is never swallowed by the completion dedupe.
5. Mapping (case-insensitive):
   - `authorized` + empty `captures[]` ⇒ completion trigger (order likely still pending) — verified via re-verify+capture in `completeFromProviderRef` (never trust the payload); unknown transaction ⇒ **404** (non-200 ⇒ Tabby retries ≤4× — DOC-FACT edge case: "webhook before your own order is saved"; deviation note in §17 R7)
   - `authorized` + non-empty `captures` ⇒ capture confirmation — no action, 200 ignored
   - `closed` + captures non-empty ⇒ completion trigger (also covers out-of-order "closed before authorized" — statuses only move forward; completion is idempotent via token)
   - `closed` + empty captures ⇒ `markFailedByProviderRef` (merchant cancelled the authorization)
   - `rejected` / `expired` ⇒ `markFailedByProviderRef`
   - `refunds[]` present ⇒ `PaymentRefundService::recordExternalRefund` per refund entry (PayPal external-refund precedent) under DB::transaction + row locks; idempotent by provider refund ref
6. Everything shares the existing response contract: 200 ack on processed/ignored/duplicate; 200 with `status:'failed'` on recorded mismatch (fail-safe non-retry); 401/400/404 on unverifiable.

**Out-of-order events**: `closed` completing after an earlier `authorized` completion is a no-op (token). A late `authorized` (capture confirmation) after completion → dedupe id `paymentId:authorized` already recorded earlier → cheap ack. Order-not-pending with a *second* paid row ⇒ DuplicateHold row, never a second completion. **Reuse of the D-06 machinery.** [VERIFIED]

---

## 11. Idempotency Strategy

| Layer | Mechanism | Original evidence |
|---|---|---|
| Local completion | `transactions.idempotency_key` UUID stamped **inside** the locked completion; re-entry ⇒ IdempotentReplay | PaymentCompletionService L63-103 |
| Webhook replays | `_webhook_event_ids` list (cap 20, pruned) per txn row | WebhookController L51-54, L1042-1153 |
| Captures (Tabby-side) | `reference_id = 'capture-{payment_id}'` — stable per payment; both callback and webhook paths construct the SAME key; retries (timeout/409/5xx) reuse it; Tabby returns the first capture. Pre-check: captures non-empty ⇒ skip capture entirely | DOC-FACT idempotent-requests + duplicate-capture guidance |
| Refunds (Tabby-side) | `reference_id = 'refund-{txn_id}-{amount×10³(cents)}'` so concurrent/nonce retries hit the same key; plus the local `_refunds` ledger in `PaymentRefundService` (per-transaction authorization, replay detection) | DOC-FACT + PaymentRefundService ledger |
| Session creation | **No idempotency key exists on `POST /checkout`** (DOC-FACT). Retry of a failed checkout creates a **new** session/payment — same semantic as MyFatoorah SendPayment retries today. Bounded by the D-06 duplicate guards at completion. | INFERRED equivalence; R6 |
| Duplicate webhook + duplicate callback racing | Both funnel into `completeLocked` under row locks; one completes, loser observes `IdempotentReplay`/`OrderNotPending` | VERIFIED |

---

## 12. Failure and Expiration Handling (mapped)

| Scenario | Handling |
|---|---|
| Checkout creation failure (network/timeout/5xx) | Adapter `request()` seam returns fail-closed `GatewayResult`; handler releases the coupon reservation; order stays pending; 4xx/500 message; no transaction row (exception case) |
| Session created but `status:"rejected"` / missing web_url | Fail-closed result with provider-declined message (new translation key); no transaction row; order stays pending (customer can pick another method) |
| Customer cancellation | Redirect to callback ⇒ verify ⇒ `EXPIRED` ⇒ txn failed + `PaymentFailed` + frontend `/payment/failed` (reusable semantics) |
| Payment rejection | Same as cancellation with `REJECTED` |
| Session/payment expiry (~30 min) | `EXPIRED` terminal; webhook (if `expire` event enabled) or callback marks failed; `orders:cancel-unpaid` releases inventory when reservation expires |
| Network timeout during verify | Adapter transport failure ⇒ fail-closed `GatewayResult('Payment gateway error')`; **reconciliation job + later callbacks/webhooks retry**; token not stamped on transport failure (stamps only happen inside `completeLocked`) |
| Capture timeout / 409 locked / 500 | Same-`reference_id` retry ladder inside `verifyPayment` (bounded: 2 capture attempts + 1 re-retrieve, configurable); unresolved ⇒ fail-closed with `status='pending'` semantics (row stays pending; webhook/buyer-callback re-verification completes later; payments:reconcile only RECORDS mismatches — completion happens solely via webhook/callback events). Never marked paid without a confirmed capture; never marked failed while authorization possibly exists without completing the order first |
| Duplicate callback | idempotency token ⇒ IdempotentReplay; response stays 200/success-shape (no error storm) |
| Duplicate webhook | `_webhook_event_ids` dedupe ⇒ 200 `ignored/duplicate` |
| Invalid payment reference | Regex/UUID constraint ⇒ 400 before any provider call; webhook unknown ref ⇒ 404 (Tabby retries; see §17 R7) |
| Amount mismatch | `PaymentMismatchException` ⇒ txn failed inside same lock; DuplicateHold only when provider says paid on a different row |
| Currency mismatch | Same fail-closed throw; the double `supportsCurrency` gate also prevents initiating in an unsupported currency |
| Wrong order / already-paid order | provider-ref binding + order-not-pending + DuplicateHold row |
| Already paid order + second AUTHORIZED event | `isDuplicatePayment` ⇒ DuplicateHold reconciliation record, no second completion |
| Delayed provider notification | Webhooks arrive whenever; completeFromProviderRef/verifyPayment re-verify server-side on every delivery; payments:reconcile re-verifies pendings and RECORDS mismatches (completion happens only via webhook/callback events); `CancelUnpaidOrders` refuses to cancel when the gateway reports paid (and our verify would then capture + complete on a later event) |
| Unknown provider status | Non-terminal mapping keeps order pending (never assumed paid, never destroyed) |
| Refund failure | Adapter fail-closed (non-2xx / non-closed payment / over-amount) ⇒ `PaymentRefundService` surfaces error, ledger untouched (try again later / dashboard) |

---

## 13. Refund Limitations and Requirements

- **Only `CLOSED` payments are refundable; total refunds ≤ captured amount; 180 days window; reason free-text** (DOC-FACT). Our adapter sends `{amount, reference_id (required), reason (optional free text — preserved in local metadata)}`.
- **Full refund once; partial multiple** — the existing `PaymentRefundService` already owns per-transaction ledger math (`gateway_response['_refunds']`, `ledgerRemaining()`, `refundedMinor()`), over-refund guards and full-refund side effects (payment-refunded + inventory restore + coupon release) — the adapter only talks to the provider (exact Tap contract precedent).
- **Partial refunds do NOT change the local order status** unless cumulative = paid amount (service's existing `isFull` logic).
- **Provider-initiated refunds** (dashboard refund by ops) reconciled via the new webhook refund branch → `recordExternalRefund` (per-refund entries, idempotent). **[PayPal precedent VERIFIED]**
- **Refund of a non-captured auth** = Close, not Refund — surfaces as excluded: our flow never leaves authorized-uncaptured states long-lived; residual cases handled manually on the dashboard (D4, §7.1).

---

## 14. Required Configuration and Code Changes

### 14.1 `config/payment.php` — new block (additive; zero risk to existing keys)

```php
'tabby' => [
    'class' => App\Services\Gateway\TabbyGateway::class,
    'enabled' => env('TABBY_ENABLED', false),
    'secret_key' => env('TABBY_SECRET_KEY'),
    'base_url' => env('TABBY_BASE_URL', 'https://api.tabby.ai'),   // api.tabby.sa for KSA
    'merchant_code' => env('TABBY_MERCHANT_CODE'),
    'webhook_secret' => env('TABBY_WEBHOOK_SECRET'),
    'webhook_header' => env('TABBY_WEBHOOK_HEADER', 'X-Auth-Key'),
    'timeout' => env('TABBY_TIMEOUT', 30),
    'capture_attempts' => env('TABBY_CAPTURE_ATTEMPTS', 2),
    'retry_delay_ms' => env('TABBY_RETRY_DELAY_MS', 2000),
    'supported_currencies' => array_values(array_filter(array_map(
        'strtoupper', explode(',', (string) env('TABBY_SUPPORTED_CURRENCIES', 'AED,SAR'))
    ))),
    'methods' => ['online'],
],
```

### 14.2 Create

| File | Purpose |
|---|---|
| `app/Services/Gateway/TabbyGateway.php` | Adapter implementing `PaymentGatewayContract` (session create / verify+capture / refund; allowlist sanitization; bounded error messages; `sk_` redaction; Http:: seam with configured timeout; no `withoutVerifying`) |
| `app/Services/Payment/TabbyWebhookVerifier.php` | `final class` — `verify(?string $receivedHeader, array $payload, string $expectedHeaderName, string $secret): bool` (constant-time; fail-closed on missing/empty pieces) |
| `tests/Unit/TabbyGatewayTest.php` | DB-free unit tests (11+ scenarios from §16) |
| `tests/Unit/TabbyWebhookVerifierTest.php` | Verifier unit tests |
| `tests/Feature/Payment/TabbyGatewayFlowTest.php` | Flow tests mirroring `TapGatewayFlowTest` (initiation/callback/refund authority) — DB-backed (**not runnable in this environment**; will pass on any env with a reachable isolated DB) |
| `tests/Feature/Payment/TabbyWebhookTest.php` | Webhook tests mirroring `TapWebhookTest` — DB-backed (same caveat) |

### 14.3 Modify (each minimal and purposeful)

| File | Change | Why the existing architecture requires it | Alternative rejected |
|---|---|---|---|
| `config/payment.php` | add `tabby` block (above) | Registry/settings read only from merged config; no gateway registration exists beyond the merged definitions | A new service-provider registration — violates DRY, duplicates the registry pattern |
| `app/Http/Controllers/Api/General/OrderController.php` | `paymentReference()`: add `payment_id` fallback AFTER `paymentId` and `tap_id` | Tabby redirects carry `payment_id` (DOC-FACT); the callback is the generic reference-driven re-verify point | A Tabby-specific controller route — duplicates the callback pipeline and violates the "reuse central verification" rule |
| `app/Http/Controllers/Api/General/PaymentWebhookController.php` | add `tabby(Request $request)` method + constructor-inject `TabbyWebhookVerifier`; add a small private helper for tabby-specific event dispatch reusing `completeFromProviderRef`/`markFailedByProviderRef`/`recordExternalRefund` predecessors | Controller is the home of all provider-signed webhook entry points; shared completion helpers already exist | A separate TabbyWebhookController — duplicates completion logic (anti-pattern the repo forbids) |
| `routes/api.php` | add `Route::post('checkout/webhooks/tabby', …)->middleware('throttle:payment-webhook')->name('api.checkout.webhooks.tabby')` | Matches stripe/paypal/tap lines 136-138 exactly | Query-param "webhook" handling inside callback — no webhook authenticity possible |
| `.env.example` | add TABBY docs block after the TAP block | Config discoverability convention | — |
| `resources/lang/en/message.php` + `resources/lang/ar/message.php` | add ONE key (e.g. `ERROR.PAYMENT_PROVIDER_DECLINED`) for provider-side checkout-session rejection surfaced to shoppers | Repo rule: user-facing strings always translated; rejection copy exists in Tabby docs (en+ar) and must not be hardcoded | Reusing "gateway unavailable" — semantically wrong (provider live, scoring declined) |

### 14.4 Deliberate non-changes

- `PaymentGatewayContract` — unchanged (capture folds inside `verifyPayment`; D1).
- Database — unchanged (§14 discovers all needed columns exist already).
- `GatewaySettingsService`, `PaymentGatewayRegistry`, `PaymentCheckoutHandler`, `PaymentCompletionService`, `PaymentRefundService`, admin/public controllers — unchanged (auto-include tabby via config).
- Marvel kernel — untouched.

---

## 15. Webhook Strategy Summary (see §10) · Idempotency (see §11)

## 16. Required Tests

**DB-free unit tests (executable in this environment — plan + will run):**

`tests/Unit/TabbyGatewayTest.php`
1. implements the contract; correct name/code
2. `isConfigured` false without secret key
3. creates a session per the documented contract (payload shape: amount string 2dp, buyer from resolver, shipping address, order items+reference_id, merchant_code, merchant_urls, lang)
4. never hardcodes currency (different currencies produce different payload + are validated against config)
5. fails closed on unsupported order currency (no provider call)
6. fails closed on non-positive amount
7. fails closed when provider rejects the session (`status:"rejected"`/missing web_url) — no `gatewayTransactionId` surfaced
8. fails closed on provider rejection fields + transport failure (timeout/exception) with no exception escaping
9. strips buyer/shipping PII from stored `rawResponse` (allowlist); keeps `payment.id`, status, amount, currency, web_url
10. verify: AUTHORIZED ⇒ capture sent with `reference_id='capture-{id}'` + full amount; on 200 CLOSED ⇒ success `paid`
11. verify: already-captured CLOSED (with captures) ⇒ success without capture call
12. verify: CLOSED empty captures / REJECTED / EXPIRED ⇒ failure `failed`
13. verify: CREATED ⇒ failure with `status='pending'` (non-terminal)
14. verify: capture 400 "already closed" ⇒ re-retrieve ⇒ success when captures present
15. verify: capture 409/5xx ⇒ bounded retry with SAME reference_id; unresolved ⇒ failure `pending` semantics
16. redacts `sk_` key material; bounded error strings; no PII in errors
17. refund: guards fail closed without a provider call (no paid txn ⇒ message; unsupported currency; amount ≤ 0)
18. refund: posts `{amount, reference_id:'refund-…', reason}`; success only on payment with new/cumulative refund confirmed; idempotent replay tolerated

`tests/Unit/TabbyWebhookVerifierTest.php`
19. accepts the valid header/secret combination (case: value equality, constant-time path)
20. rejects missing header/wrong value/missing secret/non-scalar payloads

**DB-backed feature tests (created; runnable only where an isolated DB is reachable — REFUSED here by design):**

`tests/Feature/Payment/TabbyGatewayFlowTest.php` — mirrors `TapGatewayFlowTest` (`Http::preventStrayRequests`, config overrides, mocked factory for re-verify):
- initiation creates pending txn + returns `{url}` from the HPP link
- disabled gateway ⇒ 422 with no provider call
- missing credentials ⇒ 422 fail-closed
- unsupported order currency ⇒ 422
- callback accepts `payment_id` and completes via server verification (completion under locks + events)
- `paymentId` keeps precedence over `payment_id`
- missing reference ⇒ 400
- refund through `PaymentRefundService` records ledger + order currency authority

`tests/Feature/Payment/TabbyWebhookTest.php` — webhook signature failures (401), verified `authorized` event completes a pending order + capture idempotency binding, `closed` with captures completes idempotently, `rejected`/`expired` mark failed, refunds recorded via `recordExternalRefund`, unknown payment ⇒ 404, replay ⇒ cheap ack, order-not-pending ⇒ ignored, mismatch ⇒ failed + 200.

**Regression**: existing suites untouched & re-run where runnable — `TapGatewayTest`, `TapWebhookVerifierTest` (green today), route and config validation.

---

## 17. Risks and Unresolved Questions

| # | Risk / open question | Class | Mitigation / note |
|---|---|---|---|
| R1 | **authorize→capture coupling inside `verifyPayment`** is a side-effectful "verify". If capture stalls (409/no response), verify returns fail-closed with pending semantics — order remains pending and completes only on a later webhook/reconcile/manual admin action. Customer saw success at Tabby but may see stale "failed" locally in that rare window. | DECISION D1 (accepted) | Tabby's own docs prescribe completing the order on AUTHORIZED and treating capture as confirmable later (21-day auto-capture); docs' two-path dedupe (shared reference) prevents double capture |
| R2 | Docs offer IP allowlist, but no shell access here to manage firewall; skipped as code-level enforcement | INFERRED | Shared-secret header verification is the documented authenticity mechanism; IP allowlist documented for ops |
| R3 | Buyer phone format (`+971…` vs digits) — docs show both forms across pages | UNKNOWN | Adapter normalizes to E.164-with-plus digits; MUST be validated in Sandbox testing before go-live |
| R4 |零食 `shipping_address.zip` — orders may carry free-form addresses without zip | UNKNOWN | `'00000'` fallback; validated in sandbox |
| R5 | Tabby min/max amounts beyond its own scoring (e.g. min 50 AED/SAR?) rejected at scoring stage | UNKNOWN | surfaced as provider-declined message; no local enforcement needed |
| R6 | No session-creation idempotency: checkout retries can create extra sessions | DOC-FACT (no key field) | bounded by completion dedupe; matches MyFatoorah semantics; monitor DuplicateHold rows |
| R7 | Unknown webhook payment_id ⇒ existing helper acks 200; Tabby docs prefer 404 so retries land after local order exists. In our flow orders ALWAYS exist before sessions (order created before initiation) ⇒ 200 ignored is deliberate, not accidental | DECISION D3 | document; revisit trigger: any "webhook for unknown transaction" log lines at go-live |
| R8 | **Test environment cannot run DB-backed tests** (MySQL unreachable; guard refuses `catch`) — feature suites ship but show as NOT RUN here | VERIFIED | unit suites + prior Tap flows carry behavior confidence; sandbox checklist (§manual) for provider-side |
| R9 | Sandbox testing cannot run from this environment without Tabby test credentials (`sk_test_…`, merchant codes) — none exist in env | UNKNOWN | manual sandbox runbook provided in final doc; requires merchant onboarding |
| R10 | KWD appears in capture schema amount description (`up to 3 decimals for KWD`) while the currency enum says AED/SAR only | UNKNOWN | default config list = AED,SAR (enum authoritative); revisit if Tabby issues a KWD merchant code |

### Decisions requiring the user's eyes before implementation (Phase-Three gate)

- **D1 — capture-on-verify**: AUTHORIZED completes the local order AND triggers capture; capture failures surface as pending/fail-safe. (Alternative rejected: a new `capture()` contract method — would change the shared interface and every existing adapter mock for one gateway; a separate capture-job queue — new architecture not present in the repo.)
- **D2 — three merchant_urls all bound to the generic callback route** (success/cancel/failure). Customer-facing outcome decided solely by server re-verification, consistent with the repo's single-callback design.
- **D3 — unknown payment_id on webhook ⇒ HTTP 404** for the tabby route specifically (non-200 triggers provider retry; cannot expose pre-order status there because orders precede sessions → the 404 case only happens when the id does not belong to us at all).
- **D4 — Close API not integrated**; residual authorized-uncaptured states handled on the Merchant Dashboard.

---

## 18. Phase-Two Notes: Tabby Sandbox (for the final runbook)

- Test keys: `sk_test_…` (secret; register webhooks with it) / `pk_test_…` (client snippets only; never server-side).
- Base URLs: `https://api.tabby.ai` (UAE) / `https://api.tabby.sa` (KSA); wrong region ⇒ 403.
- Success test buyer: `otp.success@tabby.ai` + `+971500000001` (UAE) / `+966500000001` (KSA), **OTP always 8888**. Pre-scoring reject: `+971500000002`. HPP rejection: `otp.rejected@tabby.ai`.
- Corner case to cover manually: close the tab before the success redirect ⇒ payment `AUTHORIZED`; webhook/cron path must capture ⇒ order completes server-side (the "Don't Miss Authorized Payments" requirement).
- Capture confirmation online: Retrieve Payment after capture shows `CLOSED` + non-empty `captures[]`.
- Refund test: full refund once; same `reference_id` replay returns the first refund; new `reference_id` allowed until the captured amount is exhausted.
- Webhook registration: self-service `POST {base}/api/v1/webhooks` with `X-Merchant-Code` + `sk_test_…`; set `url` to the public `POST /api/v1/general/checkout/webhooks/tabby` endpoint and `header` `{title:"X-Auth-Key", value:"<TABBY_WEBHOOK_SECRET>"}`.

*(To be appended in Phase Six: final implementation summary, file-by-file changes, API request/response examples, commands executed, test results, remaining risks.)*


---

# PART 2 — IMPLEMENTATION RECORD (written at Phase Four completion)

Part 1 above is the approved plan; this part is the as-built record. Where the
as-built intentionally differs from the Part 1 sketch, the difference is listed
in §15.3 rather than silently rewriting Part 1.

## 15. Implementation Summary

### 15.1 Files (as-built)

| File | Status | Content |
|---|---|---|
| `app/Services/Gateway/TabbyGateway.php` | NEW | `PaymentGatewayContract` adapter: `createInvoice` / `verifyPayment` (capture-first) / `refund` / `name` / `code` / `isConfigured` / `supportsCurrency`; single `request()` HTTP seam (Bearer, JSON, 30s, TLS on, `\Throwable` → fail-closed GatewayResult); allowlist sanitizers; bounded+redacted error messages |
| `app/Services/Payment/TabbyWebhookVerifier.php` | NEW | `final class` — `verify(string $receivedValue, string $secret): bool` via `hash_equals`, fail-closed on empty pieces |
| `app/Http/Controllers/Api/General/PaymentWebhookController.php` | MODIFIED | + constructor-inject `TabbyWebhookVerifier`; + `TABBY_FRESH_READ_STATUSES = ['authorized','closed','rejected','expired']` (outcome arbitrated exclusively by the fresh read — review fix M3); + `tabby()` endpoint; + `reconcileTabbyRefunds()` with refundable set `['paid','partially_refunded']` (review fix M2); existing Stripe/PayPal/Tap methods untouched |
| `app/Http/Controllers/Api/General/OrderController.php` | MODIFIED | `paymentReference()` gains the `payment_id` third fallback; `checkoutCallback()` AND `checkoutErrorCallback()` now both route through it |
| `config/payment.php` | MODIFIED | additive `gateways.tabby` block |
| `routes/api.php` | MODIFIED | `POST checkout/webhooks/tabby` (`throttle:payment-webhook`, name `api.checkout.webhooks.tabby`) |
| `.env.example` | MODIFIED | TABBY_* block incl. self-service webhook registration curl |
| `resources/lang/{en,ar}/message.php` | MODIFIED | + `ERROR.PAYMENT_PROVIDER_DECLINED` (both files) |
| `tests/Unit/TabbyGatewayTest.php` (`20 tests`) + `tests/Unit/TabbyWebhookVerifierTest.php` (`3 tests`) | NEW | see §15.6 |
| `tests/Feature/Payment/TabbyGatewayFlowTest.php` + `TabbyWebhookTest.php` | NEW | DB-backed skeletons — NOT RUNNABLE here (§15.6) |

Touching nothing: `PaymentCompletionService`, `PaymentCheckoutHandler`,
`PaymentGatewayFactory/Registry`, `PaymentRefundService`, all existing
gateways, Marvel models, no migrations.

### 15.2 The D1-R lifecycle as built (`TabbyGateway::verifyPayment`)

Reference ids (Part 1 D6 unchanged):
- capture: `capture-{paymentId}` — constant per Tabby payment; every retry shares it
- refund: `refund-{orderId}-{2dp amount}` — constant per (order, amount)
- local completion token: the row's existing `idempotency_key` (untouched `completeLocked` semantics)
- webhook dedupe: `{paymentId}:{status}` (no provider event id exists — synthesised, Tap precedent)

Status map (the single outcome authority, fresh-read Arbiter):

| Fresh GET `/payments/{id}` | Outcome | Adapters' GatewayResult |
|---|---|---|
| `CLOSED` + captures[] non-empty | paid | `success=true`, `status='paid'`, provider-verified amount/currency echoed |
| `CLOSED` + captures[] empty | authority closed uncaptured | `success=false`, `status='failed'` (terminal) |
| `REJECTED` / `EXPIRED` | never took money | `success=false`, `status='failed'` (terminal) |
| `AUTHORIZED` + empty captures | attempt capture here (§ below) | per capture arbitration |
| `AUTHORIZED` + non-empty captures | capture CONFIRMATION is not a trigger — one fresh re-read; still not CLOSED+captures → pending | `success=false`, `status='pending'` |
| `CREATED` | checkout unfinished — NO capture call | `success=false`, `status='pending'` |
| unseen status / read failure (5xx, transport) | ambiguity never decides | `success=false`, `status='pending'` |

Capture arbitration (`captureFirst`, bounded `capture_attempts` retries at
`capture_retry_delay_ms`, config clamped 0..5 / 0..30000):

| Event during capture | Resolution |
|---|---|
| 409 "payment is locked" / 5xx / transport drop | same-reference_id retry (Tabby dedupe) |
| 2xx | fresh read arbitrates; ONLY `CLOSED`+captures ⇒ paid — anything else ⇒ pending 'unconfirmed' (never believed on the raw 2xx) |
| other 4xx (e.g. 400 already closed) | NO retry on the raw error — fresh read arbitrates: CLOSED+captures ⇒ paid (benign dedupe); AUTHORIZED ⇒ same-key retry; REJECTED/EXPIRED/CLOSED-empty/CREATED ⇒ fresh-read status map |
| budget exhausted | ONE final reconciliation read (a capture may have landed behind the last 409); CLOSED+captures ⇒ paid; terminal statuses ⇒ failed; anything else ⇒ pending 'unresolved' |

KEY GUARANTEES (machine-checked by the unit suite):
- ORDER/INVENTORY/PROMOTIONS are never touched before a Tabby-verified capture;
  local completion remains EXCLUSIVELY `PaymentCompletionService::completeLocked`
  and is only invoked by callers on `$result->success` (unchanged shared pipeline).
- Ambiguous outcome ⇒ `status='pending'`; the unchanged webhook/penalty fail
  paths persist `$result->status ?? 'failed'`, so the row sequence stays `pending`
  (completable) rather than wrong-terminal.
- No capture ever carries a different reference_id (idempotency contract asserted in tests).

### 15.3 As-built deltas vs the Part 1 sketch (deliberate)

| Part 1 sketch | As-built | Why |
|---|---|---|
| `base_url` = `https://api.tabby.ai` | `https://api.tabby.ai/api/v2` incl. version + adapter appends paths | single seam; regional override swaps one env value |
| `capture_attempts` / `retry_delay_ms` env names | `TABBY_CAPTURE_ATTEMPTS` / `TABBY_CAPTURE_RETRY_DELAY_MS` | explicit key group; int-cast + clamped in the adapter |
| verifier signature `verify(?string $header, array $payload, …)` | `verify(string $receivedValue, string $secret)` | Tabby's documented verification IS the shared-secret comparison; no payload digest exists on this family |
| `timeout` config key | hard-coded 30s in `request()` (mirror of TapGateway) | avoid unused-config drift; revisit if op needs differ per environment |
| § webhook default header `X-Auth-Key` | default `x-tabby-signature` | title is merchant-chosen at registration; default kept repo-neutral |

### 15.4 Webhook endpoint behavior contract (`tabby()`)

Order of operations (fail-closed top to bottom):

1. `webhook_secret`/`webhook_header` present? else **503**
2. body present + valid JSON? else **400**
3. registered header VALUE present and constant-time equal? else **401**
4. `payment.id` present + charset/length bound? else **400**
5. transaction exists by ref? else **404** (Tabby retries; DELIBERATE, docs-sanctioned)
6. `$probe->payment_method === 'tabby'`? else **400**
7. dedupe id `{paymentId}:{status}`; EVERY actionable status
   (`authorized`, `closed`, `rejected`, `expired` — one unified branch) →
   `completeFromProviderRef('tabby', …)` → adapter fresh read arbitrates
   BOTH directions ⇒ completeLocked (CLOSED+captures) OR markFailedWithResult
   (persists `$result->status`: confirmed terminal provider states ⇒ failed;
   ambiguity ⇒ pending rows stay completable). A stale terminal-failure
   delivery racing an already-landed capture therefore completes the payment
   instead of failing money the provider took (review fix M3).
   Anything else (`created`, `update`, unknown) → **200 ignored (no state change)**.
8. refunds[] in the delivered body → `reconcileTabbyRefunds()`: only when the
   row is locally refundable `['paid', 'partially_refunded']` (the manual
   refund path's refundable set — the first external refund flips the row to
   partially_refunded, so 'paid'-only gating would drop every LATER distinct
   refund webhook; review fix M2), per-refund ledger row via
   `PaymentRefundService::recordExternalRefund`, dedupe id
   `tabby:refund:{txnId}:{refundId}`, cumulative over-refund rejected by the
   service's remaining-amount guard, currency divergence throws (caught per
   refund, logged); lock order = transaction row then order (service's own canon).

Threat notes: the payload's `status`, `amount`, `captures` are NEVER consumed as
truth — only lookup hints + the verifier gate the request; secret/PII never
logged (ids/statuses only).

### 15.5 Error message extraction decision (deliberate, mirrored to the provider envelope)

`errorMessageFrom(response, fallback)` echoes Tabby's DOCUMENTED error text —
`error` (string) or `errors[]` first entry (Tabby's envelope has no `message`
field; docs: response-codes) — bounded (300 chars), strip_tags, `sk_` redacted;
non-string shapes and empty extraction fall back to `"{fallback} (HTTP {n})"`.
TapGateway is the same RULE applied to its envelope (`message`). A unit test
pins both sides (extracted provider text AND object-shape fallback).

### 15.6 Test evidence

Unit tier (executed HERE — MySQL unreachable). METHOD NOTE (review finding,
empirically proven): PHPUnit's CLI executes ONLY the FIRST path argument —
`phpunit a.php b.php` silently skips b.php. Earlier "4-file" invocations
reporting 23/113 had actually run TabbyGatewayTest alone. The protocol is
therefore per-file:

```
$env:DB_DATABASE='catch_verify'
php vendor\phpunit\phpunit\phpunit tests\Unit\TabbyGatewayTest.php         --no-coverage → OK (23 tests, 113 assertions)
php vendor\phpunit\phpunit\phpunit tests\Unit\TabbyWebhookVerifierTest.php --no-coverage → OK (3 tests, 10 assertions)
php vendor\phpunit\phpunit\phpunit tests\Unit\TapGatewayTest.php           --no-coverage → OK (16 tests, 82 assertions)
php vendor\phpunit\phpunit\phpunit tests\Unit\TapWebhookVerifierTest.php   --no-coverage → OK (8 tests, 15 assertions)
```

→ TOTAL 23 + 3 + 16 + 8 = 50 tests / 220 assertions — ALL GREEN
(2026-10-31, re-verified after the review fixes).

Coverage highlights: documented session envelope (top-level siblings, buyer
PII→allowlist, `$payment.id`+`web_url` presence gate, rejected session →
`rejection_reason`), unsupported currency ⇒ `Http::assertNothingSent()`,
capture-paid via fresh read, benign 400-already-closed ⇒ paid,
409/5xx/transport same-reference_id retries (asserted identical keys),
budget-exhausted ⇒ pending 'unresolved' (never terminal), 2xx-without-fresh-read
⇒ pending 'unconfirmed', confirmation-not-a-trigger (no capture HTTP call),
CREATED ⇒ pending zero capture calls, REJECTED/EXPIRED/CLOSED-empty ⇒ terminal
failed, ambiguous read (500 & transport) ⇒ pending, unknown-status ⇒ pending,
PII strip on verification payloads, malformed ids ⇒ no provider call,
verifier constant-time/empty fail-closed, stale `expired`-after-capture
completing from the fresh read (crash-window recovery, webhooks),
terminal-failure payloads arbitrated exclusively by the fresh read,
refund reconcile set `[paid|partially_refunded]` incl. the multi-refund
chain and replay idempotency (webhooks).

Root causes of the two originally failing tests (fixed test-side, adapter
unchanged):
1. `lang` assertion assumed `'en'`; the suite boots the app's `ar` locale —
   the docs contract is enum mar|en, so membership is now asserted.
2. `Http::fake()` URL-map stubs MERGE (first non-null wins — Laravel Factory
   source); re-faking a URL mid-test is silently ignored. Two responses for
   one endpoint must use `Http::sequence()`.

Feature tier (NOT RUNNABLE in this environment — run where MySQL exists):

```
php vendor\phpunit\phpunit\phpunit tests\Feature\Payment\TabbyGatewayFlowTest.php tests\Feature\Payment\TabbyWebhookTest.php
```

Catalogued intent: initiation (pending txn, sanitized response, envelope),
422 currency/disabled gates with zero provider calls, `payment_id` fallback
(+ `paymentId` precedence), capture-unresolved ⇒ pending ⇒ later `authorized`
webhook completes (the D1-R recovery invariant), amount-mismatch completion
refusal, rejected (fresh-read-arbitrated)/created/unknown-id/foreign-gateway/
unsigned webhook behaviors, stale `expired` delivery AFTER a landed capture
⇒ completes from the fresh read (crash-window regression), DB-backed refunds
+ refund-authority ledger, refund-webhook ledger reconciliation incl. the
MULTI-refund chain (later distinct refund after the row flips
partially_refunded), replay idempotency and deferral on non-refundable rows.

Also VERIFIED here (non-test runtime checks):

- `php artisan route:list`: `POST api/v1/general/checkout/webhooks/tabby` named
  `api.checkout.webhooks.tabby` behind `throttle:payment-webhook`
- `GatewaySettingsService::definition('tabby')` + `PaymentGatewayRegistry::resolve('tabby')`
  end-to-end (settings overrides fail to [] on DB failure → config definition →
  container instantiation → contract honored; currencies AED/SAR in, KWD out)
- `php -l` on every changed/created file
- log/PII sweep: all Tabby `Log::` lines carry gateway/event/status/result/ids only
- Full Unit census (527 tests): 512 green; 15 pre-existing failures/errors in
  four NON-payment areas — missing `deploy/supervisor` worker configs
  (QueueConfigurationTest ×2, WorkerConfigPolicyTest ×4), CRLF-vs-LF fixture
  mismatches (SeederProductionSafetyStaticTest ×6), stateful fulfillment
  packing (PackingServiceTest ×2), Arabic i18n content drift
  (WarehouseDefaultTest ×1). No failing test name intersects the payment diff.

### 15.7 Sandbox runbook (fake-data examples; NO real traffic was made)

1. Sandbox portal: create merchant + secret key (sk_test_…), note merchant_code.
2. Self-service webhook registration (header title/value chosen here):

```bash
curl -X POST https://api.tabby.ai/api/v1/webhooks \
  -H "Authorization: Bearer sk_test_..." \
  -H "X-Merchant-Code: my_merchant_code" \
  -H "Content-Type: application/json" \
  -d '{"url": "https://your-host/api/v1/general/checkout/webhooks/tabby",
       "header": {"title": "x-tabby-signature", "value": "SUPER_LONG_RANDOM_SECRET"}}'
```

3. `.env`: TABBY_ENABLED=true, TABBY_SECRET_KEY=sk_test_…,
   TABBY_MERCHANT_CODE=my_merchant_code, TABBY_BASE_URL=https://api.tabby.sa/api/v2 (KSA),
   TABBY_WEBHOOK_HEADER=x-tabby-signature, TABBY_WEBHOOK_SECRET=SUPER_LONG_RANDOM_SECRET.
4. Checkout with a SAR/AED order (KWD is refused 422 pre-contact), complete the
   HPP, then observe: `transactions.status=pending` → AUTHORIZED event logs
   (`capture-{paymentId}`) → on confirmed capture the row flips `paid`, the order
   completes with the token stamped; deliberately SIGSTOP the app after a
   capture 2xx to watch the next webhook re-verify to `CLOSED+captures ⇒ paid`.
5. Dashboard refund → refund webhook → `gateway_response['_refunds']` ledger
   grows by exactly one row per refund id (replays add nothing).

FAKE-DATA example (session create; values invented — schema-true only):

```json
{
  "payment": {
    "amount": "100.00", "currency": "SAR",
    "buyer": {"name": "Sara Al-Otaibi", "email": "sara@example.com", "phone": "0555000001"},
    "shipping_address": {"city": "Riyadh", "address": "King Fahd Rd 12", "zip": "12345"},
    "order": {"reference_id": "5",
              "items": [{"title": "Order #ORD-5", "quantity": 1, "unit_price": "100.00", "category": "Other"}]},
    "buyer_history": {"registered_since": "2026-01-01T00:00:00+00:00"},
    "meta": {"order_id": "5"}
  },
  "lang": "ar",
  "merchant_code": "my_merchant_code",
  "merchant_urls": {"success": "https://app/api/v1/general/checkout/callback",
                    "cancel": "https://app/api/v1/general/checkout/callback",
                    "failure": "https://app/api/v1/general/checkout/callback"}
}
```

### 15.8 Known limitations & follow-ups

| # | Area | Limitation / follow-up |
|---|---|---|
| 1 | Events | `markFailedWithResult` fires `PaymentFailed` even when the underlying result is a REUSABLE-pending capture (shared-pipeline behavior, Tap inherits). Row integrity unaffected; SHOULD-FIX later at the pipeline level (terminal vs pending event distinction) — cross-gateway change, deliberately NOT bundled here |
| 2 | Refund reference_id | intentional equal-amount split refunds deduplicate to one provider refund (documented trade-off; revisit on business need) |
| 3 | Capture wall time | worst-case buyer-side stall ≈ attempts × delay (default ≤ ~2×3s) before failing OPEN to pending — config clamps 0..30000ms |
| 4 | Auto-capture backstop | Tabby auto-captures missing-capture payments after 21 days; webhooks + the buyer callback are the primary recovery paths, `payments:reconcile` re-verifies pendings and RECORDS mismatches (its verification side effects incidentally fire captures for AUTHORIZED rows); D4 dashboard Close is the manual escape hatch |
| 5 | Feature fixtures | `CurrencyTestCase::seedCurrencyData` seeds USD/KWD/SAR only — Tabby feature fixtures use SAR (supported) and KWD (refusal gate); AED fully covered at unit tier |
| 6 | Webhook body | no max-size cap before verification (throttle limits frequency; consistent with existing gateway endpoints) |
| 7 | Buyer staging text (S1) | a budget-exhausted UNRESOLVED capture currently surfaces the generic PAYMENT_PROVIDER_DECLINED copy (business state is accurate — nothing captured, row stays completable); a distinct PAYMENT_NOT_CONFIRMED_YET key (en+ar) is queued so pending ≠ declined in buyer-facing text |
| 8 | Captured-funds-after-cancel (S2) | cross-gateway shared residue: if the reaper cancels a pending order and a capture then lands, completion no-ops as OrderNotPending and only `payments:reconcile` records the mismatch — Tabby's long AUTHORIZED window widens exposure; ops-visible follow-up, deliberately not a code change here |
| 9 | merchant_code miss (N1) | `isConfigured()` checks secret_key only; an empty merchant_code passes admin inspection and fails as a provider 400 on first checkout |
| 10 | Verifier order (N3) | webhook body is JSON-parsed before the secret compare (no side effects precede auth; route throttled); Stripe-style verify-first remains an available hardening |

### 15.9 Proof-status legend for this part

- VERIFIED here at runtime: unit suite (per-file protocol, 50 tests / 220 assertions), full Unit census (§15.6), route existence, registry resolution, `php -l`, log sweep.
- DOC-FACT (official docs, re-checked 2026-10-31): session payload shape,
  capture/close semantics, statuses, refund constraints, webhook registration /
  retry / no-event-id model, currency enum AED|SAR.
- FEATURE-TIER-INTENT (not verified here, queued): §15.6 feature skeletons.
- NOT VERIFIED anywhere yet: zero real Tabby traffic — sandbox §15.7 is
  required before production enablement (TABBY_ENABLED default false).

### 15.10 Independent review + five-focus-area outcome (2026-10-31)

The original read-only reviewer verified all 7 contract points HONORED with
file:line evidence and explicit "nothing found" statements (no SQL-injection
or PII vectors; no paid-without-fresh-read path; no Stripe/PayPal/Tap
regression in the Tabby hunks). The follow-up five-focus-area review session
was terminated by a free-tier usage limit and never delivered; its exact
scope was instead executed by the task owner as a traced analysis —
classification below.

| ID | Area | Finding | Disposition |
|---|---|---|---|
| M1 | Registry test | `GatewaySettingsAdminTest` still asserted 4 gateways / exact order without `tabby` — hard-fails on any DB-capable runner (DB-backed: invisible in this environment) | FIXED (test-side): count 5, `tabby` membership + `supports_catalog_currency=false` (AED/SAR vs USD catalog), exact order pinned [paypal 1 → tabby 4 → myfatoorah 5 → stripe 9 → tap 20] |
| M2 | Refund webhooks | `reconcileTabbyRefunds` gated on row status `paid` only — the first external refund flips the row to `partially_refunded`, so every LATER distinct provider-refund webhook was skipped forever (Tabby acks 200, stops retrying): money moved with no ledger row | FIXED: guard widened to `['paid', 'partially_refunded']` (the manual path's refundable set); replay idempotency, cumulative remaining-amount guard and non-refundable-row deferral unchanged; multi-refund regression skeleton test added |
| M3 | Out-of-order / crash-recovery webhooks | `rejected`/`expired` events were marked failed straight from the PAYLOAD (no fresh read) — a stale terminal-failure delivery racing a landed capture would terminally fail money Tabby already took | FIXED: one unified fresh-read branch — EVERY actionable status (`authorized`/`closed`/`rejected`/`expired`) arbitrates through `verifyPayment`; confirmed terminal provider states still fail visibly; ambiguity stays pending/completable; stale-expired-after-capture regression skeleton test added |
| S1 | Buyer text | budget-exhausted UNRESOLVED capture surfaces "provider declined" copy (row integrity fine) | ACCEPTED RISK — queued translation follow-up (§15.8 row 7) |
| S2 | Reaper residue | captured-funds-after-cancel leaves no reconciliation row outside duplicate-hold (pre-existing shared behavior; Tabby's long AUTHORIZED window widens it) | ACCEPTED RISK — documented (§15.8 row 8) |
| N1–N7 | assorted | merchant_code miss (N1), parse-before-auth (N3), phone-less-buyer fixture (N4), latency clamp docs (N5), cache/scratch hygiene (N6), reconcile wording (N7) | ACCEPTED RISK; hygiene applied: `.phpunit.cache/test-results` reverted; N7 reworded in Part 1 §12/§13 and §15.8 row 4 |
| — | Concurrency (focus areas 4+5) | verified SAFE at code level: same capture `reference_id` under ALL concurrent verifies/captures; `completeLocked` token-first idempotency + order-status secondary defense + fail-closed amount/currency/provider-ref checks under caller-held row locks; `PaymentSucceeded` dispatched only on the `completed` outcome; per-refund dedupe + cumulative-over-refund guard inside the caller's locked transaction | NO FIX REQUIRED |
| — | Pending semantics (focus area 2) | row integrity verified: `markFailedWithResult` persists `$result->status` so unresolved rows stay `pending`/completable with token untouched; the `PaymentFailed` notification blast radius (buyer/admin notifications + timeline) is the accepted gap in §15.8 row 1 | NO FIX REQUIRED |
| — | Suite bookkeeping | PHPUnit CLI executes only the FIRST path argument; historical "23/113 across 4 files" claims never ran the other three | Corrected: per-file protocol, 50 tests / 220 assertions (§15.6) |
