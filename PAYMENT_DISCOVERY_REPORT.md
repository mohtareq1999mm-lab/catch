# PAYMENT_DISCOVERY_REPORT.md

> Phase 0 output (re-created 2026-09-26 after unrelated workspace cleanup removed the original;
> findings unchanged, all since remediated — see FINAL_AUDIT defect register).
> Labels: VERIFIED FROM CODE | INFERRED | NOT VERIFIED LIVE. No app code changed in Phase 0.

## 1. Live payment path (VERIFIED FROM CODE)

- `POST /api/v1/general/checkout` (sanctum) → `OrderController::checkout` →
  `OrderService::addItemsInOrder` (order `pending`, ACTIVE inventory reservation, coupon
  revalidation) → `PaymentCheckoutHandler` (online/cod/cashier).
- Online: `PaymentGatewayFactory::make($gateway)` — only `myfatoorah` arm, else 422;
  `supportsCurrency` gate; coupon reserve; `createInvoice`; txn `pending`; `{url}`.
- `ANY checkout/callback` + `/error-callback` (public, `throttle:payment-callback`) → txn lookup
  → server-side `verifyPayment` → `DB::transaction` + `lockForUpdate` (idempotency-key primary,
  order-status secondary, amount×1000 + currency fail-closed) → paid + `payment-success` +
  inventory commit + promotion + `changeOrderStatus(completed)` + `PaymentSucceeded`.
- Mark-paid routes under `permission:update-order-status` → same canonical path.
- Routes verified live via `php artisan route:list --path=checkout` (7 routes).

## 2. Currency gap (VERIFIED FROM CODE)

- `getEffectiveCode()` = catalog when selection disabled (default), else user pref/header.
- Snapshot `currency_code` = EFFECTIVE; payment read `currency_code ?? base ?? default` at 5 sites.
- Finding: with selection enabled, customer-chosen display currency became payment currency
  (§3/§4 violation); no single resolver; base fallback chain (§6 violation). → FIXED Phase 2.

## 3. Classification

- CORRECT (kept): server verify-wins; fail-closed guards; unknown-order fail-safe; two-layer
  idempotency; promo→coupon→tax→shipping; coupon fail-closed; reserve→commit/release; error-callback completes-if-paid.
- NEEDS-MOD (all done): catalog resolver/snapshot; registry + CanInitiate/CanVerify; refund
  validation; response allowlist; mark-paid permission; zero-value 500; contract surface.
- MISSING (all built): Stripe/PayPal adapters, webhooks, settings admin, completion service,
  duplicate policy, `gateway_transaction_id` handling.
- DEAD/LEGACY (untouched, deprecated): `packages/marvel/src/Payment/*` (Stripe Charges-era,
  PayPal needs absent SDK); no routes/factory refs; SDKs absent from `vendor/`.
- DANGEROUS (handled with phase plan): snapshot semantics, callback locking, legacy deletion
  (not done), SDK installs (done, user-approved).
- BLOCKED (still): live DB/schema check via running app, sandbox runs, full-suite baseline.

## 4. Target architecture → BUILT (§8/§62)

Checkout → Method Resolver → Gateway Registry → Adapter → Provider → verify →
`PaymentCompletionService` → txn/order → inventory → coupon/promo → invoice → events/notifications/reconciliation.

## 5. Key evidence pointers

- Factory single-arm: `PaymentGatewayFactory.php`; fallback chain: `PaymentCheckoutHandler.php:43`
  (pre-fix); refund shape: `MyFatoorahGateway.php:179-186` (pre-fix); legacy verify via Charges:
  `packages/marvel/src/Payment/Stripe.php:234-255`; SDK absence: composer.json + empty
  `vendor/stripe`, `vendor/srmklive` globs (pre-install).

## 6. Business rule (§63, engraved in PAYMENT_ARCHITECTURE.md)

> The Catalog Currency configured by the Admin is the authoritative customer transaction
> currency. The customer does not select the currency. The Base Currency is not the payment
> currency. For an existing Order: Payment Currency = Order Currency Snapshot. The Base
> Currency must never override the Order transaction currency.

## 7. Decisions needed → ALL RESOLVED (user answers 2026-09-26)

D-01 catalog-always | D-02/D-03 install SDKs, sandbox when keys arrive | D-04 settings.options |
D-05 zero-value completes w/o gateway | D-06 duplicate reconcile-hold.
