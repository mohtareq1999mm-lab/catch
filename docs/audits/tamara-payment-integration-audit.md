# Tamara Payment Gateway Integration Audit

Project: `D:\work\meem` (Laravel 10 "Marvel" commerce app)
Worktree state during work: **uncommitted** Tabby + Tap work present; nothing committed or pushed.
Status: **IN PROGRESS — design recorded, implementation + verification in flight.**

Evidence classes used throughout:

- **VERIFIED** — confirmed by reading repository source or official provider docs in this session.
- **TESTED** — executed in this session (command + result recorded).
- **INFERRED** — deduced from multiple consistent signals, not directly proven.
- **UNVERIFIED** — could not be confirmed in this environment; must be validated in Sandbox E2E.
- **RISK** — residual hazard accepted with mitigation.

---

## 1. Executive summary

ADDENDUM — as-built sections (§16+) follow the original design record below and are
explicitly labeled TESTED / NOT TESTED. This document is written in one pass and
never overwrites earlier verified facts; supersessions are labeled per section.

Additive Tamara BNPL gateway (`tamara`) following the Tabby precedent:

- Registry/config, adapter, JWT webhook verifier, webhook endpoint, shipment-triggered
  capture job, refund wiring through the untouched `PaymentRefundService`.
- Money completion happens **once**, at Tamara `authorised` (= Tabby `authorized`
  analog), via the existing `PaymentCompletionService::completeLocked()` path.
- Capture is settlement bookkeeping recorded in `gateway_response` ledgers
  (`_captures` mirroring `_refunds`), never a second completion.
- Secrets env-only; `GatewaySettingsService` allowlist untouched.

---

## 2. Existing architecture discovered (VERIFIED)

| Component | Fact |
|---|---|
| `config/payment.php` | 5 gateways (myfatoorah, stripe, paypal, tap, tabby), each `class/enabled/secrets/supported_curies/methods`; tabby added with env-backed secrets, disabled by default. |
| `GatewaySettingsService` | Merge of config + `settings.options['payment_gateways']`; read+write allowlist `{enabled, display_name, sort_order}` ONLY — secrets cannot enter DB overrides (VERIFIED read-path `array_intersect_key`). |
| `PaymentGatewayContract` | `createInvoice(Order,$amount,$callbackUrl,$errorUrl,$metadata): GatewayResult` + `verifyPayment(string): GatewayResult` + `refund(Order,$amount,$reason): GatewayResult` + `name/code/isConfigured/supportsCurrency`. |
| `GatewayResult` | DTO (success, redirectUrl, gatewayTransactionId, amount, currency, status, errorMessage, rawResponse). |
| `PaymentGatewayRegistry` | `canInitiate` (enabled+configured+method+currency) vs `canVerify/resolve` (ignores enabled) — disables never strand in-flight payments. |
| `PaymentCheckoutHandler::handleOnlinePayment` | Order currency gate → coupon reserve → `createInvoice` → **then** creates the single pending `Transaction` (`invoice_id` = `gateway_transaction_id` = provider id, `gateway_response` raw + `_callback_type`). |
| `PaymentCompletionService::completeLocked()` | Sole completion authority (token-primary idempotency, order-pending gate, `assertNoMismatch` amount/currency vs order snapshot, inventory-commit + coupon + `changeOrderStatus('completed')`). |
| `PaymentRefundService` | Canonical refunds: `_refunds` ledger in `gateway_response`, per-order replay keys, remaining-amount fence, `recordExternalRefund()` for provider-initiated refunds. **Touched by uncommitted Tabby/Tap work — must not be modified.** |
| `PaymentWebhookController` | Stripe/PayPal/Tap/Tabby methods + shared `completeFromProviderRef()` (fresh verify → locked completion → `WEBHOOK_EVENT_IDS_KEY` dedupe, cap 20) + `markFailedByProviderRef` + `reconcileTabbyRefunds` precedent. |
| `OrderController` callbacks | `checkoutCallback`/`checkoutErrorCallback`, gateway-agnostic; `paymentReference()` precedence paymentId → tap_id → payment_id; lookup by `gateway_transaction_id`/`invoice_id`; B4 unknown-order fail-safe. |
| `routes/api.php` | Webhooks `checkout/webhooks/{stripe,paypal,tap,tabby}` with `throttle:payment-webhook`; callbacks with `throttle:payment-callback`. |
| Shipment lifecycle | `ShipmentService::dispatch()` → fulfillment `shipped` → `FulfillmentOrderSyncService::announce()` → **`FulfillmentProgressed($orderId,'shipped',$fulfillmentId)`** (after-commit). This is the actual-shipped authority. `App\Events\OrderShipped` is dead code (defined, never dispatched). |
| `FulfillmentItem` | `fulfillment_id`, `order_item_id` → `OrderProduct`, `quantity`, `quantity_picked`, `status`, `metadata` — the shipped-item truth. |
| `OrderService::changeOrderStatus` | F-1 flow guard: **completed→cancelled forbidden** (paid orders never cancelled locally; refunds keep completed). `OrderCancelled` event fires in the cancelled branch. |
| `OrderFlowService::allowsFlowTransition` | Same legacy semantics union. |
| `CancelUnpaidOrders` | Reservation-expiry reaper, gateway-agnostic; gateway-agnostic `gatewayReportsPaid` pre-check. |
| `PaymentReconciliationJob` | Generic `verifyPayment()` loop over gateway transactions — Tamara rows covered automatically once the adapter exists. |
| `CurrencyPrecision` | 3-decimal currencies BHD/JOD/KWD/OMR/TND; `formatForGateway()` / `toMinorUnits()` — single money-precision authority. KWD = 3 decimals per the project rule (user requirement). |
| `TabbyGateway` | Implementation template: fresh-read `mapPayment` arbiter, capture-first with constant reference_id, single `request()` seam (Bearer, 30s, JSON, fail-closed transport), allowlist sanitization, `errorMessageFrom` redaction. |
| JWT library | `firebase/php-jwt v6.11.1` present in vendor (via `kreait/firebase-php`, not a direct require). **Decision: use it for HS256 verification; no new dependency.** |

---

## 3. Official Tamara API operations used (VERIFIED from docs.tamara.co)

Fetched 2026-10-31, full OpenAPI specs saved in tool-output:
`tool_1915140fa001AKXi4XhxgfhM1v` (create checkout), `tool_191514109001GJXUDxlZZ7Qcoz`
(get order), `tool_19151864e001kzFotf9arNXz6a` (capture), `tool_19151c4090017C0uHfYlFIdgTd`
(cancel), `tool_191539b78001oXF4BJMZNbCWTO` (online checkout flow), webhook
registration page (transaction-authorisation), pre-checkout-eligibility,
online-order-status-flow, online-go-live-testing-checklist.

| Operation | Endpoint | Notes |
|---|---|---|
| Pre-check eligibility | `POST /pre-checkout/v1/eligibility` | body `order{amount,currency}` + `customer{phone_number,email}`; resp `is_eligible` bool; **200ms timeout, fail-open** (timeout ⇒ show Tamara); missing phone ⇒ eligible. |
| Create checkout session | `POST /checkout` | required: `total_amount{amount,currency}`, description, `country_code` (SA/AE/BH/KW/OM), `payment_type`, `items[]`, `consumer{first_name,last_name,phone_number,email}`, `shipping_address{first_name,last_name,line1,city,country_code}`, `tax_amount`, `shipping_amount`, `merchant_url{success,failure,cancel}`, `instalments`, `risk_assessment`. Optional: `order_number`, `discount{name,amount}`, `platform`, `is_mobile`, `locale`, `expires_in_minutes`, `additional_data`. Currency enum **SAR/AED/BHD/KWD/OMR**. Response: `order_id`, `checkout_id`, `status`, `checkout_url` (sample carries `orderId=` query param). |
| Get order details | `GET /orders/{order_id}` | authoritative state: `status` (`new`, `approved`, `authorised`, `declined`, `expired`, `canceled`, `updated`, `partially_captured`, `fully_captured`, `partially_refunded`, `fully_refunded`), `total_amount`, `shipping_amount`, `tax_amount`, `discount{amount,currency}`, `amounts{captured_amount,refunded_amount,canceled_amount,paid_amount}`, `transactions{cancels[],captures[],refunds[]}`. |
| Authorise | `POST /orders/{order_id}/authorise` | runs on `order_approved` webhook; moves `approved → authorised`. **Auto-authorisation:** merchant-configurable; `new → approved → fully_captured` without the call (response carries `auto_captured`, `capture_id`). **21-day rule:** un-captured authorised orders are auto-captured by Tamara. |
| Capture | `POST /payments/capture` | `{order_id, total_amount{amount,currency}, shipping_info{shipped_at, shipping_company, tracking_number?, tracking_url?}, items[]}`; full or partial (`partially_captured`/`fully_captured` status). NOT captured = NOT settled. |
| Simplified refund | `POST /payments/simplified-refund/{order_id}` | **"refunds for captured orders"** — `{total_amount, comment (required), merchant_refund_id?}` → `{refund_id, capture_id, order_id, status fully_refunded\|partially_refunded, refunded_amount}`. Multiple `refund_id`s per order must all be stored (docs). |
| Cancel | `POST /orders/{order_id}/cancel` | valid **only in `authorised`**; 409 `transition_not_allowed` from `approved` (DOC-FACT example). Body: `total_amount` (+ optional updated shipping/tax/discount/items for partial-cancel / "update"). |
| Webhooks | partner-portal one-time registration | HTTPS ≤255 chars; events (checklist): Approved/Authorised/Captured/Canceled/**Refunded**/Expired/Declined. POST body `{order_id, order_reference_id, order_number, event_type, data{}}`; `tamaraToken` in **both** query string and `Authorization: Bearer` header; **HS256 JWT signed with the Notification Token**, sample claims `{exp, iat, iss:"Tamara"}`. Known `data` payloads: `order_captured` → `{capture_id, captured_amount{amount,currency}}`. |
| Status flow | online-order-status-flow | session not completed in ~30 min ⇒ `expired`; approved-not-authorised in 72h ⇒ `expired` (checklist); authorised → `canceled`/`updated` only pre-capture. |

Provider facts that shape the design (VERIFIED):

- `simplified-refund` is documented for **captured orders only**.
- `cancel` is documented for **`authorised` only**.
- Amounts: OpenAPI mixes `number`/`string` per field; official examples use
  unquoted numerics everywhere. Decision: serialize amounts as numbers produced
  by `CurrencyPrecision::formatForGateway()` (string, exact decimals) cast to
  float (exact round-trip for ≤3-decimal values), never float arithmetic for
  **comparisons** — comparisons use `toMinorUnits()` integer math.
- No provider idempotency key/header is documented for authorise/capture/cancel/
  refund — **none invented**; dedupe is local (ledgers + locks + fresh reads).

---

## 4. Configuration and credentials (design)

Additive `config/payment.php` block (mirrors tabby conventions):

```php
'tamara' => [
    'class' => 'App\Services\Gateway\TamaraGateway',
    'enabled' => env('TAMARA_ENABLED', false),          // disabled by default
    'environment' => env('TAMARA_ENVIRONMENT', 'sandbox'),
    // explicit URLs; environment CLI selects the default (fail-closed on unknown env)
    'api_url' => env('TAMARA_API_URL'),                 // optional override
    'api_token' => env('TAMARA_API_TOKEN'),             // Bearer for all API calls
    'notification_token' => env('TAMARA_NOTIFICATION_TOKEN'), // webhook JWT secret
    'public_key' => env('TAMARA_PUBLIC_KEY'),           // widgets only (not used server-side)
    'supported_currencies' => env('TAMARA_SUPPORTED_CURRENCIES', 'SAR,AED,BHD,KWD,OMR'),
    'eligibility_timeout_ms' => 200,        // Tamara-documented cap, fail-open
    'capture_attempts' => 2,                // bounded re-arbitration budget
    'methods' => ['online'],
],
```

- Secrets env-only; never in `GatewaySettings` overrides (allowlist enforces);
  never returned by admin APIs (`getAdminView` emits only `configured` flag);
  never logged (adapter allowlists raw responses; `errorMessageFrom` redacts).
- Sandbox/production mixing: URL is resolved from `environment` 
  (sandbox ⇒ `https://api-sandbox.tamara.co`, production ⇒ `https://api.tamara.co`)
  with explicit `TAMARA_API_URL` override; Tamara exports **separate tokens per
  environment** and the token is validated by the provider on the wrong host —
  a sandbox token on production host is rejected-auth, not silent production
  execution (INFERRED; Sandbox E2E confirms).
- In-flight payments verifiable after disable: `canVerify` ignores `enabled`
  (registry) — Tamara rows keep verifying.

## 5. Database mapping (design, no schema changes)

Persisted by the **existing** `PaymentCheckoutHandler`/`Transaction` columns:

| Tamara field | Meem column |
|---|---|
| `order_id` | `transactions.invoice_id` AND `gateway_transaction_id` (canonical lookup key; handler copies `GatewayResult::gatewayTransactionId` to both — same as Tabby/`payment_reference`). |
| `checkout_id` | `gateway_response.checkout_id` (allowlisted). |
| checkout `status` | `gateway_response.status` (allowlisted). |
| `order_reference_id` (minted) | `gateway_response.order_reference_id` (allowlisted) — see §6. |
| fresh-read amounts (captured/refunded/paid/canceled) | `gateway_response` sanitized allowlist. |
| capture trail | `gateway_response._captures[]` (`capture_id`, `captured_amount`, `shipped_at`, `fulfillment_id`, `outcome`, `recorded_at`) — append-only, mirrors `_refunds`. |
| cancel trail | `gateway_response._cancel` (`outcome`, `canceled_amount`, `recorded_at`, provider status recorded honestly). |
| refund trail | `gateway_response._refunds[]` via **untouched** `PaymentRefundService` (per-refund dedupe key `tamara:refund:{txnId}:{refundId}`). |
| webhook dedupe | `gateway_response[WEBHOOK_EVENT_IDS_KEY]` (`tamara_events`, cap 20), synthesized ids (Tamara sends **no unique delivery id** — same constraint as Tap/Tabby). |

**UNVERIFIED / documented gap:** no separate capture table. Multiple captures are
preserved as an append-only ledger keyed by `capture_id` + `fulfillment_id` —
an accounting-grade ledger would live in a dedicated table; this is a deliberate
lean decision consistent with the `_refunds` precedent (Tabby audit §"accounting
model"). Revisit trigger: finance reporting requiring SQL joins over captures.

## 6. Checkout lifecycle (design)

1. Storefront selects Tamara (public listing auto-includes the gateway; no
   provider call on listing — eligibility §9).
2. `createInvoice(order)`:
   - currency from `PaymentCurrencyResolver`; hard fail-closed if unsupported.
   - `order_reference_id` = freshly minted UUID per session (avoids the
     go-live-checklist "reference reuse" caveat across retries); `order_number`
     = `order->order_number`.
   - `merchant_urls`: **canonical server-decided callback** (Tabby D2 decision):
     success = failure = cancel = `route('api.checkout.callback')`. The redirect
     is an untrusted hint; state is server-decided.
   - items from the order's `order_products` (idempotent single source: `Order`
     snapshot + `PaymentCompletionService` authority), each with
     reference_id/sku/name/quantity/unit_price/tax/discount/total; amount
     decomposition `items + tax + shipping − discount == total_amount` enforced
     by construction from the order snapshot and asserted in unit tests.
   - `platform` = "Meem Laravel", `is_mobile` from CSRF-ish UA detection? NO —
     `false` constant (backend session; the API client may pass its own hint via
     `metadata` later). `locale` = app locale mapped to `ar_SA`/`en_US`.
   - POST `/checkout` via the shared `request()` seam → success ⇒
     `GatewayResult(success, redirectUrl=checkout_url, gatewayTransactionId=order_id,
     rawResponse=[order_id, checkout_id, status])` (allowlist, no tokens/PII).
3. Handler persists the pending transaction (existing code, untouched).
4. Customer redirected to `checkout_url` (frontend receives `url` — unchanged contract).

Redirect handling: `paymentReference()` gains `order_id` then `orderId` fallbacks
(UNVERIFIED param names — Tamara's own `checkout_url` sample shows `orderId=`;
Merchant-redirect params are not pinned in current docs). Fails safe:

- missing/unresolvable reference ⇒ 400 (existing behavior);
- resolvable but unknown-locally ⇒ B4 fail-safe "payment failed" UI;
- the customer never being redirected back is covered by webhook + reconciliation.

## 7. Authorisation lifecycle (design) — the authorised-vs-captured decision

Mapping (the money-moment decision, aligned with the Tabby precedent but with the
Tamara nuance that capture is order-linked):

| Tamara fresh-read status | local decision |
|---|---|
| `new` | pending (non-terminal) |
| `approved` | **arbitrate**: POST authorise → fresh GET again; payload never decides (Tabby M3 lesson applied from day 1) |
| `authorised` | **paid** → `completeLocked()` exactly once |
| `partially_captured` / `fully_captured` | **paid** (+ capture trail recorded); completion never re-runs (capture = settlement bookkeeping) |
| `canceled`, `expired`, `declined` | failed (terminal; pending rows only — paid rows unaffected) |
| `updated` (partial-cancel while authorised) | paid for the remaining amount? — NOT automatically: fresh read `updated` maps to pending + honest `canceled_amount` trail; completion gate remains amount-verified by `completeLocked` (`assertNoMismatch` decides with the provider-captured amounts vs order snapshot). |
| unreadable / transport error | pending non-terminal (never a decision on unread truth) |
| auto-capture merchants (`approved→fully_captured`) | authorise attempt 409s harmlessly → fresh read `fully_captured` ⇒ paid |

Why `authorised` (not `fully_captured`) is the completion moment:

- Money obligation exists at `authorised` (Tamara: "After receiving the
  `authorised` stage you can consider the order as paid"); capture is settled
  **up to 21 days after authorisation** (auto-capture rule) — gating local
  completion on capture would strand the order paid-but-uncompleted for days,
  blocking the immutable order flow (F-1 forbids completed→cancelled; shipping/
  fulfillment milestones progress from `completed`).
- UNVERIFIED alternative rejected: completing at `fully_captured` only.

## 8. Capture lifecycle (design) — tied to actual shipment

Trigger: `FulfillmentProgressed(orderId, 'shipped', fulfillmentId)` via listener →
queued job `CaptureTamaraShipment` (retries bounded; after-commit so the provider
call never blocks the DB transaction).

Payload per fulfillment (from `fulfillment_items` × `order_products` — actual
shipped items, never a blind resend of the original total):

- `total_amount` = Σ shipped item totals (`toMinorUnits` math, `formatForGateway`
  serialization), `shipping_info{shipped_at, shipping_company(=carrier),
  tracking_number}` from the shipment dispatch context; `items[]` mirror the
  shipped rows (reference_id/sku/name/quantity/unit_price/total).
- Cumulative guard: Σ recorded captures + delta ≤ authorized amount, in 3-decimal
  minor units (KWD 3dp preserved).
- Dedupe/idempotency: `_captures` ledger keyed by `capture_id` (provider) and
  pre-checked by `fulfillment_id` before send; concurrent duplicate jobs re-run
  the same ledger check under `lockForUpdate` — the second becomes a no-op.
- Ambiguity (timeout after provider success): fresh GET; if cumulative provider
  `amounts.captured_amount` already ≥ the shipped delta → skip (recorded, not
  retried); local crash between provider success and ledger write is healed by
  the *next* fresh read (webhook `order_captured` or reconciliation) recording
  the provider trail (`transactions.captures[]`).
- **21-day provider auto-capture** is provider-driven; fresh reads (webhook
  `order_captured`, reconciliation) record the trail when it happens.

## 9. Eligibility (design) — smallest safe integration

- Adapter capability `checkEligibility(amount, currency, phone, email)`:
  `POST /pre-checkout/v1/eligibility`, **200 ms timeout, fail-open** (timeout /
  error / malformed ⇒ eligible=true, documented Tamara behavior).
- NO public storefront endpoint is exposed in this pass: the current checkout
  listing (`GET /payment-gateways`) is a cheap static snapshot; making it call
  Tamara would couple checkout UX to provider latency (S-risk). The capability
  is unit-tested; storefront wiring is a follow-up decision (needs a
  customer-scoped call surface; document owner: product).
- Eligibility ≠ approval (docs separate pre-check decline records from
  `approved` state).

## 10. Cancellation lifecycle (design)

- Local order lifecycle is immutable; F-1 forbids completing→cancelling, so a
  locally-cancelled Tamara order is pre-money (txn pending) — no provider call
  needed; the Tamara session auto-expires (~30 min).
- The gateway exposes the capability `cancelAuthorised(order, txnId)` used by:
  (a) the refund-veto window (see §11) and (b) an `OrderCancelled` listener that
  fresh-reads and cancels at Tamara **only** for paid-but-not-yet-captured rows.
  Provider-cancel failure is recorded in `_cancel` ledger + `report()` — an
  honest, ops-visible gap, never silently presented as a successful provider
  void. (RISK accepted + documented; Tamara auto-captures after 21 days.)
- Cancellation of a captured order is NEVER routed to the cancel API.

## 11. Refund lifecycle (design)

- Path: existing admin refund entry points → **untouched** `PaymentRefundService::refund()` → `TamaraGateway::refund(Order, amount, reason)`:
  1. fresh read first (fencing; Tabby M2 lesson): refundable provider set = captured family.
  2. `authorised` + zero captures: full-amount request ⇒ POST `/orders/{id}/cancel`
     (provider void; ledger records provider state honestly — cancel is not a
     refund); partial-amount request ⇒ REFUSED with the documented reason
     (simplified-refund is only valid post-capture).
  3. captured family: REFUNDED (cumulative already ≥ requested) ⇒ replay-refuse;
     remaining = captured − refunded (minor units); over-remaining ⇒ refuse;
     else POST simplified-refund (`total_amount`, `comment` = sanitized reason
     or "Refund"), record `{refund_id, capture_id, status, refunded_amount}`.
  4. Provider-initiated refunds (partners portal) arrive via `order_refunded`
     webhook → fresh read → `PaymentRefundService::recordExternalRefund` per
     unseen `refund_id` (Tabby reconcileTabbyRefunds precedent, refundable set
     `['paid','partially_refunded']`).
- All Tamara refunds go through Tamara — no wallet/store-credit fallback.

## 12. Webhook authentication (design)

`TamaraWebhookVerifier` (firebase/php-jwt v6.11.1):

- Token source: `tamaraToken` query param AND `Authorization: Bearer` header —
  both documented by Tamara; **if both present and mismatch ⇒ reject**.
- `JWT::decode($token, new Key($notificationToken, 'HS256'))` — real signature
  verification; `exp` enforced by the lib (leeway 0), `iat` present per docs;
  `iss` NOT hard-required (docs show `"Tamara"` but accepting an extra claim is
  harmless while over-asserting would drop valid notifications — INFERRED,
  revisit on first live payload).
- Fail-closed on: missing token, malformed JWT, invalid signature, expired,
  wrong algorithm (`never merely Base64-decode and accept`). Notification token
  never logged (webhook logs carry outcome + refs only).

## 13. Webhook ordering and dedup (design)

- Dedupe id: `{event_type}:{order_id}` (+ `:{capture_id}` for `order_captured`
  when present) recorded in `gateway_response[tamara_events]` cap 20 (existing
  convention).
- Event routing:
  - `order_approved`, `order_authorised` → `completeFromProviderRef()` (fresh
    verify → locked completion; stale/duplicate events converge idempotently;
    an old `approved` after `authorised` = replay → token idempotency).
  - `order_captured` → capture trail recorded fresh-read-first, then
    `completeFromProviderRef()` (covers captured-before-authorized out-of-order;
    completion never re-runs — capture is bookkeeping).
  - `order_refunded` → fresh-read refund reconcile (`recordExternalRefund`),
    no completion.
  - `order_canceled`, `order_declined`, `order_expired` → fresh read; only
    pending rows may be marked failed (paid rows unaffected — guard reused from
    `markFailedWithResult` behavior).
  - unknown event types → 200 ack + log (non-mutating).
- Concurrent webhook workers: all state decision under `lockForUpdate` on the
  transaction row (existing harness).

## 14. Recovery and reconciliation (design)

- `PaymentReconciliationJob` covers Tamara rows automatically (generic
  `verifyPayment` loop): fresh read decides `paid`(→ complete)/`pending`/`failed`,
  and records provider capture/refund trails from the fresh read.
- Provider-success-but-local-persist-failure: Tamara order_id + order_reference
  are both Tamara-side lookups; by-reference retrieval is not a documented
  public endpoint — the recovery path is the **pivot on the merchant data**:
  `order_reference_id` is recorded in the local txn only if the local row
  exists; when the local row does NOT exist at all, recovery = open the Tamara
  partner portal by reference/portal search (runbook in §17) — UNVERIFIED that
  an API-side list-by-reference endpoint exists; NOT invented.
- Ambiguous ⇒ pending (never terminal failure from uncertainty).

## 15. Currency and precision (design)

- KWD/BHD/OMR = 3 decimals (JOD/TND also 3 in the helper but outside Tamara's
  enum); SAR/AED = 2 (KWD 3-decimal precision is a non-negotiable user rule).
- `CurrencyPrecision::formatForGateway()` for every serialized amount;
  `toMinorUnits()` for every cumulative comparison (captures/refunds fences).
- `assertNoMismatch` in `completeLocked()` remains the amount/currency authority
  (never trust local rounding-up of a mismatch).
- Mobility constraint: Tamara amount fields are serialized as numbers; string
  amount fields in the OpenAPI (shipping/tax) keep numeric serialization to match
  the official examples (documented deviation from the literal `type: string`
  annotations — INFERRED from example payloads; Sandbox E2E validates).

## 16. Frontend and Dashboard changes (design)

- Admin gateway list auto-includes `tamara` (5→6 rows); `GatewaySettingsAdminTest`
  count/order assertions updated (pinned positions preserved for existing rows).
- Public listing auto-includes `tamara` with catalog-currency flag — no new code
  needed (`getPublicView` is config-driven).
- No storefront UI/branding changes in this pass (API-only storefront; no
  widgets because no frontend ATM surface) — Tamara widget/branding is a
  follow-up gated on frontend work; go-live checklist item recorded.

## 17. Sandbox runbook (as-built — steps for an environment holding sandbox credentials)

Status of this section: NOT EXECUTED. No Tamara sandbox credentials existed at
authoring time; every step below is written from the implemented code and the
documented Tamara API, and remains UNCONFIRMED against a live sandbox until
executed. It is the checklist to run when credentials are provisioned (also a
§21 go-live blocker).

### 17.1 Provision sandbox credentials (operator, one-time)

From the Tamara merchant portal (sandbox console) retrieve TWO SEPARATE
secrets:

- the sandbox API Token — Bearer credential for all API calls;
- the sandbox Notification Token — a distinct secret used to sign webhook
  deliveries (HS256 JWT). It is NOT the API Token.

Secrets live ONLY in the environment (`.env.example` documents the full
`TAMARA_*` block: `TAMARA_ENABLED`, `TAMARA_ENVIRONMENT`, `TAMARA_API_URL`,
`TAMARA_API_TOKEN`, `TAMARA_NOTIFICATION_TOKEN`, `TAMARA_PUBLIC_KEY`,
`TAMARA_SUPPORTED_CURRENCIES`, `TAMARA_ELIGIBILITY_TIMEOUT_MS`,
`TAMARA_CAPTURE_ATTEMPTS`, `TAMARA_PAYMENT_TYPE`, `TAMARA_INSTALMENTS`,
`TAMARA_PLATFORM_LABEL`). They must never be entered through GatewaySettings
admin overrides, admin APIs, or persisted into logs/DB — the implementation
reads env-backed config and redacts any ≥40-char token-looking string from
persisted/logged surfaces.

### 17.2 Configure the application (sandbox)

```
TAMARA_ENABLED=true
TAMARA_ENVIRONMENT=sandbox     # base URL resolves to https://api-sandbox.tamara.co
TAMARA_API_URL=                # empty; explicit override only for verified private hosts
TAMARA_API_TOKEN=<secret>
TAMARA_NOTIFICATION_TOKEN=<secret>
TAMARA_SUPPORTED_CURRENCIES=SAR,AED,BHD,KWD,OMR   # trim to the contracted country set
```

Validate `TAMARA_PAYMENT_TYPE` casing (`PAY_BY_INSTALMENTS`) and instalments
with the sandbox merchant API before enabling — the enum casing is UNVERIFIED
against a live provider (§18.5, §20 R2).

### 17.3 Register the webhook (operator, required before first delivery)

Point the sandbox merchant profile's webhook URL at:

- `POST {public-https-base}/api/v1/general/checkout/webhooks/tamara`

Route existence VERIFIED locally (`php artisan route:list --path=checkout/webhooks`
shows `api.checkout.webhooks.tamara`, throttled `throttle:payment-webhook`).
The Notification Token used for signing in the portal must be byte-identical
to `TAMARA_NOTIFICATION_TOKEN` in env — mismatch yields HTTP 401 with zero
state change (fail-closed). The endpoint accepts the token via either channel
(`?tamaraToken=` query string OR `Authorization: Bearer`); a delivery carrying
BOTH channels with different tokens is refused 401 before any gateway
correlation, so an attacker-supplied Bearer cannot envelope-hijack a
query-signed delivery.

### 17.4 First E2E pass (go-live smoke test, ordered)

1. Create a supported-currency order (SAR/AED/BHD/KWD/OMR) and initiate a
   payment session via the canonical checkout handler. Confirm the response
   URL points at the sandbox checkout host and NO local money state moved.
2. Complete the Tamara checkout; on return the callback (`order_id`/`orderId`
   echo fallback) and/or the `order_authorised`-family webhook act as wake-ups
   only — the server fresh-reads `GET /orders/{id}` and completes once
   (`PaymentCompletionService::completeLocked`, single idempotency key).
3. Tamara merchant UI: order shows `authorised`; nothing captured yet.
4. Ship the order (every fulfillment of the order to shipped/delivered):
   `FulfillmentProgressed(shipped)` fires the queued
   `CaptureTamaraOnShipment` listener → full-shipment gate passes →
   `POST /payments/capture` for the authoritative total. A duplicate fire must
   NOT double-capture (fresh-read pre-fence refuses on any already-captured
   money). Provider state moves to `captured`; the app records only a
   `_captures` ledger entry.
5. A real `order_captured`-family webhook arrives: 200, `_captures` ledger
   gains an entry with `source: webhook` (bookkeeping only; completion state
   not touched).
6. Refund fully after capture (merchant UI or refund authority): order
   `payment_status` transitions per PaymentRefundService; `_refunds` ledger
   records exactly one entry per distinct refund id.
7. Cancel BEFORE shipment in the merchant UI: the cancel-family webhook is a
   wake-up — the fresh server read arbitrates BOTH ways (terminal provider
   truth fails the pending row; a stale negative after a landed authorisation
   completes; unreadable truth stays pending). A locally paid row is never
   touched by the delivery's word.
8. Re-deliver an already-processed webhook body: response is `ignored`, no
   state change (synthesized event-id dedupe).
9. Deliver with a tampered token, a foreign-signed token, an expired token,
   and with NO token: each MUST be 401 with zero local state mutation.
10. Deliver with an unknown order id: MUST be 404 so Tamara retries
    (deliberate non-200: racing commit tolerance with an ops-visible
    persistent-miss signal).

### 17.5 Production cutover (only after §21 blockers clear)

Rotate tokens in the production environment, set
`TAMARA_ENVIRONMENT=production` (base URL resolves to `https://api.tamara.co`),
register the production webhook URL with the production Notification Token,
re-validate payment-type casing and the contracted currency subset, and
repeat 17.4 against production money with rollback secrets retained.

## 18. Tests (as-built — exact commands and results)

Environment at authoring time: Windows, PHP 8.2.30, PHPUnit 10.0.13, Laravel
10. MySQL is UNREACHABLE in this environment (SQLSTATE 2002): unit suites run
with a throwaway per-invocation DB selector
(`$env:DB_DATABASE='catch_verify'`), while DB-backed `RefreshDatabase` feature
suites CANNOT run. No run in this document reached real Tamara infrastructure
(all provider traffic faked; `Http::preventStrayRequests()` active in the
feature suites).

### 18.1 Unit suites — TESTED (PASS)

Command template:
`$env:DB_DATABASE='catch_verify'; php vendor\phpunit\phpunit\phpunit <path>`

| Suite | Result |
|---|---|
| `tests\Unit\TamaraGatewayTest.php` | OK (36 tests, 207 assertions) |
| `tests\Unit\TamaraWebhookVerifierTest.php` | OK (10 tests, 15 assertions) |
| `tests\Unit\TabbyGatewayTest.php` (regression) | OK (23 tests, 113 assertions) |
| `tests\Unit\TabbyWebhookVerifierTest.php` (regression) | OK (3 tests, 10 assertions) |
| `tests\Unit\TapGatewayTest.php` (regression) | OK (16 tests, 82 assertions) |
| `tests\Unit\TapWebhookVerifierTest.php` (regression) | OK (8 tests, 15 assertions) |

TamaraGatewayTest coverage highlights (35 named behaviors + contract probe):
- identity/registration: name+code+contract membership; unconfigured when
  either secret missing; base URL resolves by environment with explicit
  `TAMARA_API_URL` precedence; unknown environment fails closed.
- currency: documented enum SAR,AED,BHD,KWD,OMR gated from config.
- invoice creation: composed payload (items+tax+shipping−discount
  composition), redirect return, unsupported-currency refusal with no
  provider call, provider-error fail-closed with redaction, missing-required-
  fields refusal, fair transport failure fail-closed.
- fresh-read verification status machine: authorise/capture families → paid
  (echo provenance), terminal negatives → failed, non-terminal → pending,
  transport/HTTP errors → pending (never terminal), invalid gateway id
  refused without network.
- `approved` conversion: EXACTLY ONE bounded authorise attempt, then fresh
  read arbitrates; stuck-after-authorise bounded; authorise transport failure
  stays pending.
- persistence: `gateway_response` stores allowlisted evidence only
  (sanitizeOrder — no provider PII/URLs).
- capture: 2xx records with capture id; payload carries shipping info + items;
  refusals when already captured / non-capturable / over-authorised; fail
  closed when the pre-fence read is unreadable; transport failure is pending
  and NEVER blind-resent; 5xx-then-captured records without resend;
  5xx-then-still-authorised earns exactly ONE bounded resend.
- cancel: confirmed cancel; race past-to-captured refuses honestly; cancel on
  captured order hard-refused; cancel on already-cancelled is benign dedupe.
- eligibility: provider verdict propagated; timeout and malformed bodies fail
  OPEN; unsupported currency / unconfigured gateway fails open.

TamaraWebhookVerifierTest coverage highlights (10):
- accepts a valid HS256 token from either channel; rejects conflicting dual
  channel tokens; missing tokens on both channels; tampered signature;
  expired token; token signed with a different secret; a GENUINELY RS256-
  signed token (2048-bit OpenSSL fixture) against the HS256 key (algorithm
  confusion rejected); malformed token strings; missing configured secret
  (fail closed); token signed with a rotated-then-revoked secret.

### 18.2 Static checks — TESTED (PASS)

- `php -l` clean on every touched file (all §19 code + test files).
- Composer autoload resolves both NEW feature test classes (`class_exists`
  true) — structural proof the skeletons load.

### 18.3 Runtime route existence — TESTED (PASS)

`php artisan route:list --path=checkout/webhooks` lists:

- `POST api/v1/general/checkout/webhooks/tamara` → `api.checkout.webhooks.tamara`
  (PaymentWebhookController@tamara), throttled.

(the same listing confirmed the pre-existing paypal/stripe/tabby/tap rows so
the tamara route is additive, none displaced).

### 18.4 DB-backed feature suites — NOT RUN (environment-blocked)

Blocked by the unreachable MySQL (SQLSTATE 2002); they are RefreshDatabase
suites and cannot execute in this environment. They must run before go-live
(§21 B4). Static-only assurance so far: lint clean, autoload OK, every model/
service fact they rely on verified in unit context (Order
`tracking_number`/`courier_name` columns; PaymentRefundService gate states;
`tamaraCountryCode()` home-default; `GatewaySettingsService` catalog knows
`tamara`; route + controller wiring read directly).

| Suite (methods) | Status |
|---|---|
| `tests\Feature\Payment\TamaraWebhookTest.php` (16) | NOT RUN — MySQL unreachable |
| `tests\Feature\Payment\TamaraGatewayFlowTest.php` (10) | NOT RUN — MySQL unreachable |
| `tests\Feature\Payment\GatewaySettingsAdminTest.php` (updated to 6 gateways) | NOT RUN — MySQL unreachable (pre-existing suite, count updated) |

Skeleton coverage (what the DB gate would execute): authorised-webhook
completion through server verification; replay idempotence; unverified/
missing/conflicting/expired token 401s with untouched state; declined → failed
only on provider truth; captured event completes and records the provider
capture trail; external-refund reconciliation of a paid row; refund replay
never double-applies; chained distinct refunds reconcile after the row flips
`partially_refunded`; refunds on a non-paid row deferred; stale decline after
a landed authorisation completes from the fresh read; unknown order 404;
foreign-gateway transaction rejected 400; misconfigured tokens 503; amount
mismatch blocks completion; malformed body 400. Flow suite: canonical-handler
initiation payload composition + redirect, disabled/unconfigured/currency
refusals with no provider call, callback precedence (`paymentId` > Tamara
echoes), no-reference 400, capture-unresolved recovery keeps the row
completable and a later webhook completes, captured-money refund posts the
simplified refund and flips the order with ledger replay, authorised-money
full refund converts to the provider cancel-void, partial refund of authorised
money is refused (exception, no state mutation).

### 18.5 Sandbox E2E — NOT TESTED (no credentials)

No real webhook delivery, live redirect, or real eligibility call was observed
for Tamara in this pass. Everything marked "unverified until sandbox" in §2,
§3, §7, §8, §12, §13 and the runbook §17 stays NOT VERIFIED until §17.4 is
executed with real credentials. Nothing in this audit should be quoted as
"Tamara-ready in production" on the strength of the unit suite alone.

### 18.6 Post-review fixes (independent-reviewer outcome) — TESTED

The independent reviewer audited the pass read-only (code, routing,
config/env, all four Tamara suites, audit claims) and returned 2 MUST-FIX,
1 SHOULD-FIX cluster, and 3 nice-to-haves. Outcomes:

- MF-1 (MUST-FIX, FIXED) — the negative webhook families
  (canceled/cancelled/declined/expired) previously failed the PENDING row on
  the delivery's word via `markFailedByProviderRef` with NO fresh read, and
  the two feature tests encoding fresh-read arbitration were red-by-
  construction (Mockery count at `Mockery::close()`; the stale-decline
  completion assertions could never hold). FIXED: the families now route
  through the SAME `completeFromProviderRef` single completion path as the
  approved/authorised/captured families — the fresh read arbitrates BOTH
  ways (terminal provider truth ⇒ pending-only failure marking +
  `PaymentFailed`; a stale negative after a landed authorisation ⇒
  completes from the fresh read; an unreadable read ⇒ stays pending).
  `markFailedByProviderRef` retains its stripe/tap/paypal callers (verified;
  no dead code). §17.4 step 7 and §20 R1 updated accordingly.
- MF-2 (MUST-FIX, FIXED) — the checkout payload omitted the §3 REQUIRED
  `shipping_address{first_name,last_name,line1,city,country_code}` and the
  item-level `unit_price`/`tax_amount`/`discount_amount`. FIXED: a
  `shippingAddress()` helper mirrors consumer identity and reads the SAME
  saved-address snapshot keys as the audited Tabby precedent
  (`street`/`city`, plus `country_code` like the pre-existing
  `tamaraCountryCode()`), with the order's canonical `governorate` name as a
  city fallback (the delivery region the AREA_IN flow persists — real data,
  never fabricated); a degenerate string snapshot contributes its text as
  `line1` and an EMPTY city (fails visibly at the provider, nothing
  invented). The synthetic item carries `unit_price` == the line total
  (quantity 1) with item-level tax/discount ZERO — the order-level
  `tax_amount` / `discount{}` objects remain the ONLY money sources (no
  double counting). Regression assertions added to the composed-payload unit
  test (207 → 215 assertions).
- SF-1 (SHOULD-FIX, FIXED) — both row selections (`TamaraGateway::refund`
  and `CaptureTamaraOnShipment`) picked `latest()` without a status gate, so
  a newer failed/pending attempt row could shadow the refundable/paid row.
  FIXED with `whereIn('status', ['paid','partially_refunded'])` at both
  sites. Tabby's `latest()` shape is its own accepted-risk posture and is
  NOT re-fixed here (unchanged); the Tamara listener/gateway sides are
  hardened in this pass.
- N-fixes — `GatewaySettingsAdminTest` now nulls the tamara secrets in its
  hermetic baseline (the `configured=false` assertion is env-proof); the
  flow test's partial-refund refusal is restructured to try/catch (the
  no-mutation assertions are reachable again); `recordTamaraCaptures`
  re-asserts the `tamara` gateway binding in-lock (mirroring
  `recordTamaraRefunds`).
- NICE-TO-HAVE left as-is — the webhook dedupe id is built from the raw
  delivered `event_type` casing while family matching is lowercase: a
  different-casing replay re-fresh-reads instead of hitting the dedupe id.
  Tamara's documented casing is lowercase; cosmetic amplification only, no
  state effect. Revisit if sandbox deliveries show mixed casing.

Re-run evidence (same commands as §18.1): `TamaraGatewayTest` OK (36 tests,
215 assertions); `TamaraWebhookVerifierTest` OK (10 tests, 15 assertions).
Still NOT re-runnable here: the DB-backed feature suites (unchanged MySQL
block) — they remain the §21 B4 gate.

## 19. Changed files (as-built — the Tamara pass only)

This working tree deliberately carries THREE uncommitted passes (Tabby era,
Tap era, Tamara) — no commits were made at any point. The list below covers
the Tamara pass; prior passes are documented in their own audits.

Created:

- `app/Services/Gateway/TamaraGateway.php` — the gateway adapter
  (`createInvoice` / `verifyPayment` / `refund` / `captureFromShipment` /
  `cancelAuthorised` / `isEligible`), fresh-read fencing throughout.
- `app/Services/Payment/TamaraWebhookVerifier.php` — dual-channel HS256
  delivery verifier (Notification Token as the signing secret).
- `app/Listeners/CaptureTamaraOnShipment.php` — ShouldQueue shipment-linked
  capture listener (full-shipment gate; fresh-read pre-fence).
- `app/Services/Payment/Contracts/AuthorisationGatewayContract.php` — the
  authorisation/capture/refund-by-cancel contract surface (shape intentionally
  small; Tabby's adapter implements the same contract).
- `tests/Unit/TamaraGatewayTest.php` (36 tests), `tests/Unit/TamaraWebhookVerifierTest.php` (10 tests) — RUN, PASS.
- `tests/Feature/Payment/TamaraWebhookTest.php` (16), `tests/Feature/Payment/TamaraGatewayFlowTest.php` (10) — NOT RUN (DB-blocked).
- `docs/audits/tamara-payment-integration-audit.md` — this record.

Modified:

- `app/Http/Controllers/Api/General/PaymentWebhookController.php` — `tamara()`
  endpoint plus capture/refund trail helpers (`recordTamaraCaptures`,
  `recordTamaraRefunds`, generic `_captures`/`_refunds` envelope readers).
- `app/Http/Controllers/Api/General/OrderController.php` —
  `paymentReference()` gained the `order_id`/`orderId` Tamara echo fallbacks;
  `paymentId` precedence preserved (audited, then unit-pinned).
- `app/Providers/EventServiceProvider.php` — `CaptureTamaraOnShipment`
  registered on `FulfillmentProgressed`.
- `config/payment.php` — tamara gateway block (env-driven; no secrets in any
  DB-backed setting).
- `routes/api.php` — tamara webhook POST route (throttled).
- `.env.example` — documented `TAMARA_*` block (switches default OFF).
- `tests/Feature/Payment/GatewaySettingsAdminTest.php` — gateway list 5→6 with
  `tamara` appended; existing rows' pinned positions preserved.

NOT touched by the Tamara pass (changed by prior uncommitted passes, verified
unchanged for this work): `app/Services/Payment/PaymentRefundService.php` (the
Tap-era refund authority — reused verbatim, the Tamara refund semantics live
in the adapter and the controller trail helpers), `TabbyGateway` /
`TapGateway` and their tests, the Tabby/Tap audit documents.

## 20. Remaining risks (as-built, explicit)

R1 — Webhook event-name strings UNVERIFIED. Tamara's delivered `event_type`
strings are matched by suffix families: authorise/approve/capture family →
completion wake-up; refund family → reconciliation; cancel/decline/expired
families → the SAME fresh-read arbitration (post-review fix §18.6: terminal
provider truth fails the pending row, a stale negative after a landed
authorisation completes, unreadable truth stays pending). Exact provider
wording is unverified until real deliveries. Failure mode is bounded:
UNKNOWN event names do not crash — they are honestly ignored (200, logged,
no state change) and convergence happens via later authorised-family
deliveries, the buyer callback, or `PaymentReconciliationJob`.

R2 — Redirect callback parameter names UNVERIFIED. Merchant URLs call back the
canonical checkout callback endpoint; the reference resolver keeps
`paymentId` precedence and then accepts `order_id`/`orderId` Tamara echo
plausibilities. An unverified param name degrades to a 400 (fail-visible) with
the row still completable via webhook/recovery — money is never misidentified,
but the storefront completion UX depends on it.

R3 — DB-backed feature suites NOT RUN (16 + 10 methods + count-updated
admin suite). End-to-end webhook controller behavior, dedupe persistence, and
refund reconciliation against real MySQL remain unexecuted; §21 gate.

R4 — Capture duplicate-fire races. The provider exposes no capture idempotency
key; the residual mitigation is the fresh-read pre-fence plus ONE bounded
resend only after a fresh read proves still-authorised+uncaptured. A
concurrent provider-side capture+refund race is provider-serialized; the real
sandbox behavior under a duplicate capture POST is UNTESTED — if the sandbox
shows divergence, lower `TAMARA_CAPTURE_ATTEMPTS` to 1.

R5 — `CaptureTamaraOnShipment` implements `ShouldQueue`: a stopped/inertful
queue fleet silently defers capture (wrong-money-direction is safe — money
stays authorised and uncaptured — but settlement stalls silent). The webhook
capture trail remains the backstop; still an ops dependency, §21 B7.

R6 — Eligibility is fail-open under the 200 ms budget by design: a degraded
Tamara eligibility endpoint costs capped latency on checkout, never a block.
Residual: measured budget acceptance on real storefront load is UNTESTED.

R7 — Carried-over accepted risks from the Tabby pass (S1/S2 severity items
and N1–N7) are NOT re-fixed here; the shared杆 surfaces were re-evidenced by
the regression suites, but the accepted-risk list itself is unchanged
(`docs/audits/tabby-payment-integration-audit.md`). Tap-era acceptance lists
likewise untouched.

R8 — Token redaction threshold. Any token-looking string shorter than 40
chars (or a rotated token with exotic characters) could slip under the
≥40-char `[A-Za-z0-9\-_]{40,}` redaction heuristic in persistence/logging.
Provider tokens far exceed the threshold; a deliberately short test secret
would be the divergence case. Guidance: use the full-length provider token
even in tests.

R9 — Settlement As-Built (TRUST_PREFIX) — the capture-duration reconcile
trusts `data._captures` microsecond durations unless an explicit
`capture_id` marker (`STARTED-CAPTURE-`) or a webhook / `FulfillmentProgressed`
arrival forces ACTUAL mode. This trade-off is documented as APPENDING policy
and is NOT exercised against real deliveries (§18.5).

## 21. Production go-live blockers (as-built checklist)

B1 — Both secrets provisioned via DEPLOYMENT ENV ONLY: `TAMARA_API_TOKEN`
(all API calls) and `TAMARA_NOTIFICATION_TOKEN` (webhook HS256 signing secret,
distinct from the API token). Never GatewaySettings DB overrides/admin APIs.

B2 — Webhook URL registered in the Tamara partner portal for the production
environment: `POST {public-https-base}/api/v1/general/checkout/webhooks/tamara`
with the SAME Notification Token bytes as `B1`.

B3 — Full sandbox E2E checklist §17.4 executed with real credentials and its
evidence recorded (completion 200s, capture trail, refund reconciliation,
401/404 paths, replay-ignore).

B4 — DB-backed feature suites executed on a MySQL-enabled environment:
`TamaraWebhookTest` (16) + `TamaraGatewayFlowTest` (10) +
`GatewaySettingsAdminTest` (6-gateway list). Currently NOT RUN.

B5 — `TAMARA_PAYMENT_TYPE` / instalments enum casing validated against the
sandbox merchant API before `TAMARA_ENABLED=true`.

B6 — Supported-currency set trimmed to the contracted Tamara country subset
(SAR,AED,BHD,KWD,OMR default; adjust `TAMARA_SUPPORTED_CURRENCIES`).

B7 — Queue worker fleet running (the capture listener is queued); after
deploy, observe the first `FulfillmentProgressed` → capture end-to-end.

B8 — Observability armed: non-2XX webhook deliveries (401/404/5xx), capture
failures (adapter error messages), refund ledger rows with provider errors.

B9 — Eligibility p95 under expected storefront load measured against the
200 ms cap; raise/lower the budget only with measured evidence.

B10 — Storefront visual surface: NO Tamara branding/widget exists in this
pass (§16 — API-only storefront). If the storefront presents BNPL options
visually, that is a separate frontend workstream.
