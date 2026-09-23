# PAYMENT_ARCHITECTURE.md

> Final architecture (Master Payment Program). Status: IMPLEMENTED + TESTED (141+ payment tests green).
> Business rule (§63): **The Catalog Currency configured by the Admin is the authoritative
> customer transaction currency. The customer does not select the currency. The Base
> Currency is not the payment currency. For an existing Order: Payment Currency = Order
> Currency Snapshot. The Base Currency must never override the Order transaction currency.**

## 1. Method vs gateway (§7)

- Methods (business): `online` | `cod` | `pay_at_cashier`.
- Gateways (providers under `online`): `myfatoorah` (live) | `stripe` (adapter ready, sandbox BLOCKED) | `paypal` (adapter ready, sandbox BLOCKED).
- COD / pay-at-cashier are manual; settled only via `payments.mark_paid` holders or the legacy status path with the same permission (F-1 gate).

## 2. Request path

```
Checkout → OrderController::checkout → OrderService::addItemsInOrder (order pending,
inventory ACTIVE reservation, coupon revalidation)
  → PaymentCheckoutHandler (online|cod|cashier)
      → registry->canInitiate(code,'online',catalogCurrency)   [§14 gate, 422 fail-closed]
      → adapter->createInvoice → transactions row (pending)
Return / callback / webhook → provider verify → PaymentCompletionService::completeLocked
  (caller holds DB txn + lockForUpdate) → txn paid → order completed/payment-success →
  inventory commit → promotion finalize → coupon consume → invoice → events
```

## 3. Contract (additive, backward compatible)

`App\Services\Payment\Contracts\PaymentGatewayContract`:
`createInvoice / verifyPayment / refund / name / supportsCurrency` + `code()` + `isConfigured()`.
Adapters: `MyFatoorahGateway`, `StripeGateway` (Checkout Sessions, ISO-4217 minor units incl.
KWD×1000, zero-decimal list), `PayPalGateway` (order CAPTURE intent, capture-on-verify with
`PayPal-Request-Id`, COMPLETED-only success). All fail-closed, allowlisted `rawResponse`
(no PII/secrets), no business completion logic inside adapters (§19).

## 4. Registry + settings (§10-15)

- `PaymentGatewayRegistry::resolve(code)` (unknown → `UnsupportedGatewayException`);
  `canInitiate` = known + enabled + class + `isConfigured()` + method + currency;
  `canVerify` = known (ignores enabled — existing payments stay verifiable, §13).
- `PaymentGatewayFactory::make()` delegates to the registry (seam preserved for tests).
- `GatewaySettingsService`: `config/payment.php` merged with `settings.options['payment_gateways']`
  (allowlisted `{enabled,display_name,sort_order}` on read AND write). Secrets env-only.
- Admin: `GET/PUT /api/v1/admin/payment-gateways[/{code}]` (`view/update-settings`), per-gateway
  `configured`, supported currencies, catalog-support preview.

## 5. Currency authority (§3-6)

- `PaymentCurrencyResolver::forOrder()` → `catalog_currency_code` → legacy `currency_code` → live catalog → config default. Single payment path; no base fallback.
- `OrderCreationService::resolveCurrencySnapshot()`: `currency_code` = catalog; `total_price`
  rounded to currency exponent (`CurrencyPrecision`: 3dp BHD/JOD/KWD/OMR/TND, else 2dp);
  `converted_total_price` stays base. Old orders immutable on catalog switch (tested).
- Completion guards: amount×1000 symmetric + uppercase currency equality, fail-closed; test-host
  bypass requires apitest URL (both config trees) + explicit flag + local/testing env.

## 6. Completion + states (§19-24, §32)

- `PaymentCompletionService::completeLocked` (idempotency-token re-check → order-pending +
  duplicate detection → amount/currency/provider-ref → commit). Outcomes incl.
  `duplicate_hold` → `payment_reconciliation_results` row (reconcile-hold policy D-06, never
  second completion). Coupon refusal (`CouponConsumptionException`) and mismatch
  (`PaymentMismatchException`) roll back visibly.
- Transaction states: `pending → paid | failed | partially_refunded | refunded`. Order payment:
  `payment-pending → payment-success | payment-failed | payment-refunded`.
- Webhooks: Stripe (HMAC, `checkout.session.completed` + `payment_intent.payment_failed` with
  PI→session resolution, event-id dedupe cap 20) and PayPal (SDK verify, COMPLETED/DENIED/
  REFUNDED/REVERSED via refund ledger). MyFatoorah callback-only by design.

## 7. Manual + refunds (§28-29)

- `payments.mark_paid` (mark-paid routes), `payments.refund` (admin refund endpoint with
  paid-minus-ledger cap, currency equality, idempotency-key ledger, disabled-gateway block),
  `payments.verify`/`payments.reconcile` seeded. Actor/action/reason audit in
  `order_status_history`. F-1 gate in `changeOrderStatus`: completing an UNPAID order with an
  authenticated actor requires `payments.mark_paid` (system/provider-verified/zero-value paths
  exempt). Deploy: `php artisan db:seed --class=PermissionSeeder`.
- Zero-value online orders (D-05): complete without gateway call (paid txn + canonical path).

## 8. Persistence

`orders` (currency snapshot columns, payment/fulfillment states), `transactions`
(`gateway_transaction_id`, unique nullable `idempotency_key`, allowlisted
`gateway_response` + `_refunds` ledger cap 100 + `_webhook_event_ids` cap 20),
`payment_reconciliation_results`, `order_status_history` (immutable).

## 9. Known gaps (see FINAL_AUDIT)

Stripe/PayPal sandbox unverified (no creds); true parallel load untested; legacy
`packages/marvel/src/Payment/*` deprecated-not-removed; `charge.refunded` undocumented-by-design.
