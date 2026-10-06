# Phase 06 — Payment Lifecycle

## 1. Executive Verdict

**PASS WITH RESIDUAL RISKS.** The payment lifecycle is the deepest-hardened financial path: three gateways (MyFatoorah/Stripe/PayPal) behind a fail-closed registry (enabled/configured/method/currency gates), provider-signed webhooks with raw-body verification and replay dedupe, canonical token-idempotent completion shared by all entry points, currency-exponent-safe matching, a measured-deadlock-free refund service with ledger accounting, and 21 dedicated test files including bypass-gate, webhook-security, and concurrency suites. Of the manual's six problems, four are fixed (P6-C1 dual registration, P6-C2 error callback, P6-C4 COD auto-cancel via reaper, P6-C6 mismatch handling with reconcile holds); P6-C3 (stubbed refund reconciliation) remains open, and the cashier-QR flow was removed without a manual update. Residual risks: refund-reconciliation blindness, provider-side refunds bypassing the ledger, and unexecuted runtime proof.

## 2. Phase Objective

Per `PHASE-06-PAYMENT-LIFECYCLE.md` (source: `HEAD:docs/production-manual/PHASE-06-PAYMENT-LIFECYCLE.md`, 456 lines): document online (MyFatoorah), COD, and cashier flows, callback idempotency/tolerance, mobile duality, error callback, reconciliation job, gateway refunds with listeners, transaction tables, problems P6-C1..C6, and recommendations R6-1..R6-6.

## 3. Scope

**In scope:** gateway abstraction (factory/registry/contract/adapters), initiation gating, browser callbacks, Stripe/PayPal webhooks + verifiers, completion service (payment-side), reconciliation job, gateway settings admin, provider refund initiation + ledger, transaction model/columns, cashier-QR removal.

**Out of scope:** checkout orchestration (Phase 01), completion commit internals already covered (Phase 01 §PaymentCompletionService — referenced), refund lifecycle policy/inventory/credit notes (Phase 10), invoice generation (Phase 07).

## 4. What Was Supposed to Be Implemented

The manual claims: MyFatoorah-only online flow with hardcoded-EGP transactions; callback with `abs>0.01` amount check and default-currency check; mobile JSON duality; error callback with BUG-4 (always-failed); COD/cashier with hardcoded EGP + QR issuance (`CashierQrService`, `transaction-qr` endpoint); reconciliation job on `low` queue with stubbed refund comparison; gateway refunds via `MyFatoorahGateway::refund` + `RefundApproved` listeners; problems P6-C1..C6; recommendations R6-1..R6-6.

## 5. What Actually Exists

Superset with structural upgrades:

- **Three gateways**: `MyFatoorahGateway`, `StripeGateway`, `PayPalGateway` (`app/Services/Gateway/`) behind `PaymentGatewayFactory::make` (throws `UnsupportedGatewayException`) and `PaymentGatewayRegistry` (`canInitiate`: unknown/disabled/misconfigured/method_unsupported/currency_unsupported fail-closed; `canVerify`: known-class verifies even when disabled — in-flight payments stay verifiable).
- **Per-order currency + 3dp precision**: no hardcoded EGP anywhere in payment paths (`PaymentCurrencyResolver::forOrder`; `CurrencyPrecision::roundForCurrency/decimalsFor`; transactions widened to 3dp, migration `2026_09_27_000001`).
- **Callbacks**: as traced in Phase 01 (format allowlist, factory verification, token idempotency, x1000 comparison, provider-ref binding, D-06 holds, coupon-blocked recovery, B4 unknown-order fail-safe). BUG-4 fixed.
- **Webhooks** (`PaymentWebhookController`): Stripe raw-body signature construction, PayPal SDK transmission verification, replay dedupe (`_webhook_event_ids`, cap 20, pruned), response allowlists, unresolvable refs → ack-200-ignored (documented, prevents retry storms), `charge.refunded` intentionally unhandled (admin-initiated refunds only; provider-side refunds → manual reconciliation).
- **MyFatoorah has NO webhook by design** (browser-callback only; unsigned webhook would widen attack surface — class docblock).
- **Reconciliation**: `PaymentReconciliationJob` + `payments:reconcile` schedule; amount/currency/payment-status/order-status comparisons; **`compareRefundStatus` still stubbed `return false`** (P6-C3/R6-3 OPEN).
- **Refunds**: `PaymentRefundService::refund` (txn→order lock order per measured deadlock F-AUDIT-01; completed/delivered + payment-success gate; transaction-currency authority with drift logging; idempotency key; ledger read; `recordExternalRefund`, `ledgerRemaining`, `noteProviderRefund`); admin route (`api.php:296`, F-1 context).
- **Cashier QR removed**: no `CashierQrService`, no `transaction-qr` endpoint, no QR in the cashier response (order_id only); `transactions.uuid` auto-generated and retained; `qr_code_url` fillable but unwritten (dormant column).
- **Settings**: public gateway-availability snapshot (`PaymentGatewayController@index`) + admin gateway settings CRUD (`permission:update-settings`).
- **P6-C4 fixed**: reaper covers COD (7-day) + cashier/online (24h) with gateway pre-check. **P6-C1 fixed** (no Marvel registrations in app ESP). **P6-C5 mitigated**: OR-lookup retained but provider-ref binding in `completeLocked` prevents cross-row completion. **P6-C6 addressed**: mismatches fail closed with failed-marking + reconcile/D-06 surfacing.

## 6. Architecture

```
INITIATION: checkout → PaymentCheckoutHandler
  → registry.canInitiate(gateway, method, orderCurrency) [enabled+configured+method+currency]
  → factory.make (adapter) → adapter.supportsCurrency double-gate
  → coupon reserve → adapter.createInvoice → Transaction::create(pending, per-order currency, uuid, _callback_type)
VERIFY/COMPLETE (browser callbacks + signed webhooks):
  lookup (gateway_transaction_id OR invoice_id) → factory.make (verify allowed when disabled)
  → adapter.verifyPayment → PaymentCompletionService::completeLocked
    (token → pending → amount x1000 → currency → provider-ref → commit →
     changeOrderStatus → events)
  → mismatch → failed marking + PaymentFailed; coupon-blocked → recovery txn + reconcile
  → webhooks: signature verify → replay dedupe → same service; unsupported → 200 ignored
REFUND (admin): PaymentRefundService::refund (locked txn+order, gates, ledger, idempotency key)
  → provider refund → RefundApproved → inventory/credit-note/rating listeners (Phase 10)
RECONCILE: payments:reconcile → per-txn gateway re-verify → mismatch rows (refund comparison stubbed)
SETTINGS: merged config+settings definitions; public snapshot; admin CRUD
```

## 7. Complete Execution Flow

Initiation/callback flows verified in Phase 01 §7; payment-specific additions:

1. **Gateway selection**: client `gateway` string → registry definition → enabled/configured/method/currency gates → 422 `PAYMENT_GATEWAY_UNAVAILABLE` / `PAYMENT_CURRENCY_UNSUPPORTED` (no provider call on refusal).
2. **MyFatoorah**: `SendPayment` invoice → redirect URL; `GetPaymentStatus` verification on callback (shopper returns with paymentId).
3. **Stripe**: session-based; `checkout.session.completed` → `completeFromProviderRef` (session id); `payment_intent.payment_failed` → intent→session resolution via API → failed marking; others ignored-200.
4. **PayPal**: transmission verification → capture/completion via provider ref; idempotency covered by `PayPalCaptureIdempotencyTest` (static).
5. **COD/cashier**: pending rows at checkout; paid only via `payments.mark_paid` mark-paid (F-1); expiry via reaper (24h cashier/online, 7d COD).
6. **Zero-value**: local completion without provider (D-05; gateway adapter still resolved for currency-support check).
7. **Refund**: admin → `refund(order, amount, reason, idempotencyKey, actorId)` → gates → provider call → ledger + `RefundApproved` fan-out (Phase 10 for effects).
8. **Reconcile**: scheduled/manual job re-verifies non-failed gateway transactions; records `payment_reconciliation_results` (incl. D-06 duplicate holds from completion).

## 8. Business Rules

| # | Rule | Implementation | Bypass? |
|---|---|---|---|
| R1 | Disabled/misconfigured/currency-unsupported gateways refuse initiation, but still verify in-flight payments | Registry `canInitiate` vs `canVerify` split | No |
| R2 | Completion requires token-absent + pending + amount/currency/ref match (fail-closed) | `completeLocked` | B5 test bypass only (triple-gated) |
| R3 | Webhooks never trust parsed bodies (Stripe raw-body verify; PayPal SDK verify) | Verifiers + controller pre-checks | No |
| R4 | Unresolvable provider events ack-200-ignored (no retry storms, no state change) | Webhook controller | No |
| R5 | Provider-side refunds never mutate state (admin-initiated only) | `charge.refunded` unhandled by documented decision | Manual reconciliation (see F-02) |
| R6 | Refunds only on completed/delivered + payment-success, in transaction currency, ledger-checked, idempotent | `PaymentRefundService::refund` | No |
| R7 | COD/cashier finalize only via `payments.mark_paid` holders | Route + F-1 gate | No |
| R8 | Expired unpaid orders cancel via reaper with gateway pre-check (never blind) | `CancelUnpaidOrders` | No |

## 9. Source of Truth / Authorities

- **Gateway availability**: `PaymentGatewayRegistry` + `GatewaySettingsService` (merged config+settings definitions).
- **Completion**: `PaymentCompletionService` (all entry points).
- **Refund initiation + ledger**: `PaymentRefundService` (idempotency keys, remaining-refundable accounting).
- **Reconciliation findings**: `payment_reconciliation_results` (mismatch + D-06 rows; refund type unwritten — stub).
- **Transaction identity**: `gateway_transaction_id`/`invoice_id` correlation + auto `uuid` + `idempotency_key` token discipline.
- **Dormant**: `qr_code_url` (unwritten), Marvel `PaymentSuccess` (unregistered), cashier-QR issuance (removed).

## 10. Database Impact

`transactions` (uuid auto, `idempotency_key` `2026_09_25_000001`, 3dp `amount` `2026_09_27_000001`, `_callback_type/_coupon_blocked_*/_webhook_event_ids` in `gateway_response`, `qr_code_url` dormant, `paid_at`, `error_message` sanitized); `payment_reconciliation_results` (`2026_07_12_000001`, +`duplicate_payment` type via D-06); refunds/ledger rows (Phase 10 tables); gateway settings storage (settings-backed definitions). No destructive migrations.

## 11. API Surface

| Method | URI | Auth | Handler | Notes |
|---|---|---|---|---|
| GET | `/payment-gateways` | public | `PaymentGatewayController::index` | availability snapshot (enabled/configured/currencies) |
| GET/POST | `/checkout/callback`, `/checkout/error-callback` | public + throttle | `OrderController` | browser flows (Phase 01) |
| POST | `/checkout/webhooks/stripe`, `/checkout/webhooks/paypal` | public + throttle (signature-verified) | `PaymentWebhookController` | signed events; 200-ignored unknowns |
| GET/PUT | `/v1/admin/payment-gateways…` | sanctum + settings perms | `PaymentGatewaySettingsController` | enable/configure gateways |
| POST | admin refund endpoint (api.php:296) | sanctum + financial perm (F-1) | refund controller → `PaymentRefundService` | idempotent, ledger-checked |

## 12. Authentication & Authorization

Browser callbacks are intentionally public (gateway redirects) with format/throttle/verification guards. Webhook authenticity is cryptographic (per-provider secrets), not session-based. Admin gateway settings require settings permissions; refunds require the financial payment permission (F-1). Test bypass requires apitest URLs + flag + non-production env (BypassGateTest pins statically).

## 13. Validation

Gateway code allowlisted via registry definitions (unknown → 422, never provider-reaching); currency/method gated pre-initiation; paymentId format allowlist; webhook payloads validated post-signature (type allowlist, reference presence); refund amount validated against ledger remaining + transaction currency; error/callback `type` allowlisted.

## 14. Transactions

Initiation creates the pending row outside any long transaction (gateway call precedes row creation — no phantom rows on provider failure... note: row created AFTER successful invoice response, correct order). Completion holds locks across token→commit in one transaction; refund holds txn→order locks in one transaction (deadlock-ordered); reconcile is read-mostly with per-row mismatch inserts; webhook dedupe list updates ride the completion transaction.

## 15. Concurrency

| Case | Protection | Classification |
|---|---|---|
| Duplicate callbacks/webhooks | token-first + locks + replay-dedupe list | STRONGLY REASONED |
| Refund vs callback overlap | global txn→order lock order (measured 1213 fix, F-AUDIT-01) | STRONGLY REASONED |
| Double refund | idempotency key + ledger remaining + REFUNDED gate | STRONGLY REASONED |
| Concurrent gateway disable vs in-flight payment | verify-path exemption (in-flight still completes) | STRONGLY REASONED |
| True parallel proof | `PaymentConcurrencyTest`, `PayPalCaptureIdempotencyTest`, `BypassGateTest`, `PaymentWebhookTest`, `PaymentSecurityTest` | UNPROVEN — NOT EXECUTED |

## 16. Async / Queues / Events

Completion events (Phase 01/05); refund events (`RefundApproved`, `RefundProcessed`) with inventory/credit-note/timeline listeners (Phase 10); reconciliation as scheduled job (`payments:reconcile` + `PaymentReconciliationJob`); gateway webhook processing is synchronous (fast: verify → lock → complete) with queue fan-out after commit; coupon outbox publisher adjacent (Phase 03).

## 17. Error Handling

Provider exceptions at initiation → reported + reservation released + 500 without phantom rows; verification failures → 500 `PAYMENT_GATEWAY_UNAVAILABLE` (no state change); signature failures → 400 (no state change); unresolvable refs → 200 ignored + logged (documented); mismatch → failed marking + event (never silent); refund provider refusal → `RuntimeException` (fail-closed, no partial ledger); reconcile gateway errors → recorded, not thrown.

## 18. Security

- Raw-body Stripe verification; PayPal SDK verification; secrets never logged (order/txn/gateway/event/result only).
- Response allowlists on stored `gateway_response`; `_webhook_event_ids` capped/pruned.
- No unsigned webhook surface (MyFatoorah deliberately excluded).
- Throttles on all public payment endpoints.
- Amount/currency/ref fail-closed; test bypass triple-gated + statically tested.
- Sanitized user-facing strings; error payloads avoid internals.
- `PaymentSecurityTest` exists statically (webhook forgery, ref confusion, bypass-gate cases — unverified at runtime).

## 19. Performance

Synchronous provider calls (initiation + verification) are outside DB transactions; completion transaction is lock-tight and short; reconcile cursors lazily over non-failed gateway transactions (bounded by gateway traffic); webhook work is minimal pre-queue; refund holds two row locks across one provider call (necessary; deadlock-ordered).

## 20. Tests & Verification

21 files: `PaymentCompletionTest`, `PaymentConcurrencyTest`, `PaymentWebhookTest`, `PaymentSecurityTest`, `BypassGateTest`, `GatewayRegistryTest`, `GatewayDisableTest`, `GatewaySettingsAdminTest`, `PublicPaymentGatewaysTest`, `CatalogCurrencyAuthorityTest`, `CurrencyPrecisionTest`, `PaymentRedirectContractTest`, `StripeGatewayTest`, `PayPalGatewayTest`, `PayPalCaptureIdempotencyTest`, `StripeFailureCorrelationTest`, `RefundCurrencyAndLedgerTest`, `ExternalRefundTest`, `MarvelRefundInterplayTest`, `MarvelStatusAuthorityTest`, `MarvelStatusReachabilityTest`. **None executed** (environment).

## 21. Edge Cases

Covered: disabled gateway mid-flight (verifies, cannot initiate); currency catalog switch mid-refund (txn-currency authority + drift log); zero-value orders (D-05); unknown-order callbacks (B4 fail-safe); duplicate provider payments (D-06 hold); coupon-blocked completions (recovery + reconcile); gateway timeout at initiation (no row, reservation released); webhook for already-completed (replay path); intent-without-session (API resolution or ignore-200); non-pending reaper candidates (skipped); already-refunded (gate).

## 22. Potential Bugs

### Finding F-01
- Severity: MEDIUM
- Type: BUSINESS LOGIC
- Status: PROVEN
#### Finding
`compareRefundStatus()` is still an unconditional `return false` — gateway-vs-local refund divergence is never detected by reconciliation. Manual P6-C3/R6-3 remain open.
#### Evidence
`app/Jobs/PaymentReconciliationJob.php:233-236`; manual §§Reconciliation Logic(7)/P6-C3/R6-3.
#### Why it matters
A provider-side refund (chargeback, dashboard refund) with no local ledger entry is invisible to automated oversight; the manual already flags this as silent divergence.
#### Current behavior
No refund comparison; `charge.refunded` webhooks also ignored-200 (documented) → two blind spots compound.
#### Recommended future action
Implement refund comparison against the refund ledger + alert on provider-only refunds (the documented manual-reconciliation path needs an actual detector).
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-02
- Severity: LOW
- Type: BUSINESS LOGIC
- Status: PROVEN (documented decision, residual exposure)
#### Finding
Provider-side refunds intentionally mutate nothing and are only "reported for manual reconciliation" — but with F-01's stub, the reporting path is log-only. The decision is sound (never auto-mutate on unsigned/third-party state); the detection is missing.
#### Evidence
`PaymentWebhookController` charge.refunded note (verified in code comments); F-01.
#### Why it matters
Chargebacks are the highest-risk refund vector and the least observed.
#### Current behavior
Log-only awareness.
#### Recommended future action
Same as F-01 plus a chargeback alert listener when Stripe dispute webhooks are added.
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-03
- Severity: LOW
- Type: DOCUMENTATION
- Status: PROVEN
#### Finding
The manual's cashier-QR flow (QR generation, `transaction-qr` endpoint, QR in response) no longer exists: `handleCashierQrPayment` returns `order_id` only; no QR service/endpoint found; `qr_code_url` is an unwritten column.
#### Evidence
`PaymentCheckoutHandler.php:175-206`; repository-wide search for `CashierQr|transaction-qr|generateBase64DataUri` returns nothing; `Transaction.php` fillable retains `qr_code_url`.
#### Why it matters
Cashier operations following the manual will look for QR tooling that does not exist; the in-store payment UX is unspecified.
#### Current behavior
Order-id-based manual mark-paid (functional, QR-less).
#### Recommended future action
Either restore QR issuance or document its removal + the current cashier UX; drop or reuse `qr_code_url`.
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-04
- Severity: INFO
- Type: DOCUMENTATION
- Status: PROVEN
#### Finding
Manual P6-C1, P6-C2, P6-C4, P6-C6 are fixed; P6-C5 is mitigated by provider-ref binding. The manual is otherwise MyFatoorah-only and predates Stripe/PayPal, the registry, per-order currency, 3dp precision, D-05/D-06, webhook security, and the refund service.
#### Evidence
As traced throughout this phase; manual §§A–C, P6-C1/C2/C4/C5/C6, R6-4 (reaper now covers COD/cashier).
#### Why it matters
Record fixed items; the manual needs a multi-gateway rewrite.
#### Current behavior
Correct implementation; stale manual.
#### Recommended future action
Rewrite Phase 6 around the registry + three gateways (on explicit docs request).
> NOTE: This audit is READ-ONLY. No fix was applied.

### Finding F-05
- Severity: MEDIUM
- Type: TEST GAP
- Status: PROVEN (gap) / UNPROVEN (runtime)
#### Finding
The payment path has the strongest test bench in the repo (21 files incl. security/concurrency/webhook/bypass suites) and none of it was executed here. TRUE PARALLEL CONCURRENCY NOT PROVEN for callbacks, webhooks, or refund-vs-callback overlap.
#### Evidence
Test listing verified; execution impossible (MySQL-only).
#### Why it matters
Money movement without runtime proof is the top residual risk of the whole audit.
#### Current behavior
Well-constructed; unproven.
#### Recommended future action
Execute the full Payment suite + callback/webhook stress suites against real MySQL in CI; record results; add refund-vs-callback overlap test if absent.
> NOTE: This audit is READ-ONLY. No fix was applied.

## 23. Potential Failure Scenarios

- **Gateway outage at initiation**: 422/500 without rows or holds; customer retries with another gateway (availability snapshot guides).
- **Gateway outage at verification**: callback 500s without state change; provider/queue retries replay safely (token + dedupe).
- **Secret rotation misconfiguration**: webhooks 400 (logged `misconfigured`); in-flight browser payments unaffected (different path).
- **Catalog currency switch mid-cycle**: initiation uses current catalog; verification/completion uses per-order resolution; refunds use transaction currency (drift-tolerant).
- **Double provider charge**: D-06 hold + reconcile row; single completion.
- **Reaper vs COD collection race**: 7-day TTL + gateway pre-check (COD has no gateway artifact; pre-check skips non-online rows safely).

## 24. Documentation Drift

Manual accurate for: three-method framing, pending-transaction start, mark-paid mechanics (modulo permission rename), reconciliation shape (minus refund stub status), `RefundApproved` listener roles. Drifted: currency handling (hardcoded EGP → per-order + 3dp), amount tolerance (0.01 → x1000), completion internals (inline → `PaymentCompletionService`), error callback (BUG-4 fixed), reaper coverage (R6-4 done), gateway count (1 → 3 + registry/settings), webhooks (absent → full signed implementation), refund service (absent → ledgered idempotent), cashier QR (present → removed), transaction columns (uuid/idempotency/3dp), R6-6 (moot — column exists), mobile contract details (persisted `_callback_type`).

## 25. Dependencies

- **Depends on**: Phase 01 (initiation call sites), Phase 05 (completion target authority), currency services, gateway providers (MyFatoorah/Stripe/PayPal SDKs), settings store.
- **Consumed by**: Phase 01 (payment routing), Phase 07 (paid invoices), Phase 09 (release on payment), Phase 10 (refund initiation + ledger), Phase 12 (payment notifications), Phase 15 (timeline payment stages).
- **Shared tables**: `transactions`, `orders` (payment markers), `payment_reconciliation_results`, refunds ledger.
- **Shared services**: `PaymentCheckoutHandler`, `PaymentCompletionService`, `PaymentGatewayFactory/Registry`, `PaymentRefundService`, verifiers, `GatewaySettingsService`.

## 26. Out of Scope

Gateway SDK internals, provider dashboard operations, payout/settlement to shops/vendors, commission accounting, invoice rendering, fulfillment release mechanics, refund policy/credit notes (Phase 10).

## 27. Residual Risks

1. Refund reconciliation blind (F-01) + provider-refund manual path (F-02).
2. Runtime proof absent across 21 suites (F-05).
3. Cashier UX unspecified after QR removal (F-03).
4. Manual is single-gateway and pre-registry (F-04).

## 28. Evidence / Source Files

Manual: `HEAD:docs/production-manual/PHASE-06-PAYMENT-LIFECYCLE.md` (456 lines, temp extract). Code: `app/Services/Payment/{PaymentCheckoutHandler,PaymentCompletionService,PaymentCompletionOutcome,PaymentGatewayFactory,PaymentGatewayRegistry,PaymentCurrencyResolver,CurrencyPrecision,PaymentRefundService,StripeWebhookVerifier,PayPalWebhookVerifier,GatewaySettingsService}.php`; `app/Services/Gateway/{MyFatoorahGateway,StripeGateway,PayPalGateway}.php`; `app/Http/Controllers/Api/General/{OrderController (callbacks),PaymentWebhookController (full),PaymentGatewayController}.php`; `app/Http/Controllers/Api/Admin/PaymentGatewaySettingsController.php`; `app/Jobs/PaymentReconciliationJob.php` (`:233-236` stub); `app/Console/Kernel.php:50` (reconcile schedule); `Transaction.php` (uuid/idempotency/fillable); `routes/api.php:118-160,254-256,296`; `config/payment.php` + settings definitions (referenced). Tests (21 files, listed, not executed). Migrations: `2026_07_08_000002`, `2026_07_12_000001` (reconcile table), `2026_09_25_000001` (idempotency_key), `2026_09_27_000001` (3dp widen).

## 29. Final Assessment

**Verdict: PASS WITH RESIDUAL RISKS.**

The payment lifecycle is financially disciplined — fail-closed gating, signed webhooks, idempotent completion, ledgered refunds, and layered recovery — and four of six documented problems are fixed. It cannot reach PASS because refund reconciliation is still stubbed (a genuine oversight blind spot), runtime proof is absent, and the manual predates the multi-gateway architecture. No blocking defect found in money movement itself.
