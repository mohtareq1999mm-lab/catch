# COMMERCE PAYMENT ARCHITECTURE (Phase 1 Lock)

> Status: LOCKED. Extends: `docs/payment-flow.md`, `docs/payment-lifecycle.md`,
> `docs/financial-integrity.md`, `docs/cms-endpoints/payment-system-architecture-review.md`.
> Verified: `PaymentGatewayContract` (29L), `PaymentGatewayFactory` (18L),
> `PaymentCheckoutHandler` (online/cod/cashier creators), `OrderController:169-558`.

## 1. Current payment architecture

`PaymentCheckoutHandler` → `PaymentGatewayFactory::make($gateway)` → `PaymentGatewayContract`
(`createInvoice/verifyPayment/refund/name/supportsCurrency`; no authorize/capture/void — invoice
model). Each checkout creates a `transactions` row (`payment_method`, `gateway_transaction_id`,
`status pending/paid/failed`, `idempotency_key`, `paid_at`). Callback re-verifies server-side
(`verifyPayment`), enforces amount×1000 + currency under `lockForUpdate`, commits inventory via
canonical `changeOrderStatus(completed)` (idempotent no-op on repeat).

## 2. Provider classification (VERIFIED)

- LIVE: `myfatoorah` (only factory adapter), `cod` (manual mark-paid), `pay_at_cashier` (manual QR).
- TEST ONLY: test-gateway (`base_url` contains `apitest`; bypass B5 to be env-hardened in P1).
- DEAD/LEGACY: Marvel `Payment/*` (Stripe, PayPal, Paystack, Flutterwave, Iyzico) — no routes.
- FUTURE: Stripe, PayPal, Gateway-B — each a new adapter, never a workflow rewrite.

## 3. Target: PaymentAttempt model (formalize existing `transactions` rows)

```text
Order (1) → PaymentAttempts (N): {provider, provider_tx_id unique, amount, currency,
normalized_status, idempotency_key unique, attempts, last_error}
```

Normalized states: `pending → processing → authorized → paid | failed | cancelled | refunded |
partially_refunded`. Provider webhooks map provider-specific statuses → normalized via adapter.
Rule: **at most one `paid` attempt per order**, enforced by the commit conditional claim
(`active→committed`); a second success is an idempotent replay, never a second capture.
Provider switching = new attempt row; resume uses latest pending attempt.

## 4. Provider contract (target — extends current interface, additive only)

```php
createPayment(order, amount, currency, returnUrls) → PaymentCreateResult
verify(txId) → GatewayResult            // server-side, never trust client flags/amounts
refund(order, amount, reason) → GatewayResult
void(txId) → GatewayResult              // where provider supports; else UnsupportedOperation
getStatus(txId) → normalized status
handleCallback(payload) → normalized event  // per-provider webhook adapter
```

## 5. New-provider recipe (acceptance criterion §46)

New `XyzGateway implements PaymentGatewayContract` + config + webhook adapter + mapping tests.
`OrderService`, inventory, fulfillment, picking, packing, shipment: ZERO changes (verified by
contract-conformance test + a full checkout→delivery run with provider stubbed).

## 6. Callback/webhook flow

Validate format → resolve attempt by provider tx id (locked) → `verify()` server-side →
idempotency-key check → amount/currency re-check → normalized outcome → commit/fail paths.
Webhooks: signature verify (where provider offers) → dedupe by provider event id → same pipeline.
Fixes in P1: B4 (unknown order → failure/unknown, never success UI), B5 (env-gated bypass),
M2 (clear-or-carry idempotency key on coupon-block with documented retry path).

## 8. Payment method matrix (normative — §7)

| Method | Status | Reserve | Payment state | Commit | Fulfillment release | Picking | Cancel/expiry | Refund | Who transitions |
|---|---|---|---|---|---|---|---|---|---|
| Online (myfatoorah, future) | LIVE | checkout (active) | pending→success/failed via verify | callback success → commit | on success+commit | after release | fail/expire → release + cancel | provider refund → payment-refunded + restore | system/gateway; supervisor cancel |
| COD | LIVE | checkout (active, 7d) | pending until delivery/cash collection | mark-paid → completed → commit | on active (deferred) | after release | expire → release + cancel; refuse-at-door → paid-cancel path | n/a pre-capture; post-capture like online | courier collects; `update-order-status` marks paid |
| Pay-at-cashier | LIVE | checkout (active, 24h) | pending until cashier scan/pay | mark-paid → completed → commit | on active (deferred) | after release | expire → release + cancel | like COD | cashier + supervisor |
| Test gateway | TEST ONLY | same as online | same | same (P1: env-gated) | same | same | same | n/a | system |

## 9. Refunds

`refund()` → provider call OUTSIDE row locks → on success: transaction `refunded/partial`,
order `payment-refunded`, paid-cancel path triggers `InventoryRestoreService::restore`.
Partial refunds accumulate; sum(refunded) ≤ paid enforced.
