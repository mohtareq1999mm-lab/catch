# PAYMENT_FLOW.md

> Runtime flows. All amounts = `orders.total_price` (catalog currency, exponent-rounded).
> Provider verification always wins over browser/client signals (§20, §27).

## Online — MyFatoorah

1. `POST /api/v1/general/checkout` `{payment_method:online, gateway:myfatoorah}` → pending order + `PaymentCheckoutHandler::handleOnlinePayment` → `canInitiate` → coupon reserve → `SendPayment` invoice (`DisplayCurrencyIso` = catalog) → txn `pending` → `{url}`.
2. Customer pays at provider → provider redirects to `ANY checkout/callback?paymentId=…` (or error-callback).
3. Server `verifyPayment` (`GetPaymentStatus` → `Paid`) → locked commit (§19 pipeline) → redirect success/failed UI (mobile: JSON).

## Online — Stripe

1. Same checkout with `gateway:stripe` (requires `STRIPE_ENABLED=true` + `STRIPE_SECRET_KEY` + catalog currency in `STRIPE_SUPPORTED_CURRENCIES`) → Checkout Session → txn `pending` (`gateway_transaction_id` = `cs_*`) → `{url}`.
2. `success_url` returns to callback with `{CHECKOUT_SESSION_ID}` → `verifyPayment` (session `payment_status=paid` + PaymentIntent amount/currency cross-check) → canonical completion. `cancel_url` → error-callback → verify-first (paid wins, §27).
3. Webhook `checkout.session.completed` → same pipeline (HMAC, event dedupe). `payment_intent.payment_failed` → failed marking (PI→session resolution).

## Online — PayPal

1. Same checkout with `gateway:paypal` (requires `PAYPAL_ENABLED=true` + mode creds) → PayPal order (CAPTURE) → approve URL → txn `pending`. PayPal appends `token` on return.
2. Callback `verifyPayment(orderId)`: APPROVED → capture (idempotency `PayPal-Request-Id: capture-{id}, already-captured recovery); COMPLETED → success → canonical completion.
3. Webhook `PAYMENT.CAPTURE.COMPLETED/DENIED/REFUNDED/REVERSED` (SDK-verified) → completion / failed / external-refund ledger.

## COD / pay-at-cashier

Checkout → pending order + pending txn → courier/cashier collects → permissioned
`POST …/cod|/cashier/{orderId}/mark-paid` (`payments.mark_paid`, optional reason) → canonical
completion. No provider, no webhook.

## Zero-value online

`round(total) <= 0` → currency-support check → paid zero txn (`gateway_response.zero_value`) →
canonical completion, no provider call. Returns `{order_id}` 200.

## Failure / edge ledger

- Verify says unpaid / amount×1000 or currency mismatch / unknown order → txn `failed` (+`PaymentFailed`), order stays pending, coupon retryable (M2), never success UI.
- Replay (callback/webhook/duplicate) → idempotent: single completion, `PaymentSucceeded` once.
- Second distinct provider payment on completed order → `duplicate_hold` + reconciliation row.
- Coupon consumed-fails → `failed` + token rotation (ops retryable), surfaced in reconciliation.
- Refund (admin): validate → provider refund → ledger + `partially_refunded/refunded` + full-refund side effects (restore + coupon release, no promotion reversal).
