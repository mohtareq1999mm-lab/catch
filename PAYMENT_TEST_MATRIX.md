# PAYMENT_TEST_MATRIX.md

> Result codes: PASS (executed green) | PRE-EXISTING-FAIL (legacy suite, cause noted) |
> BLOCKED (no sandbox creds). Payment dir: **149 passed** (`php artisan test tests/Feature/Payment`).
> Forensic extras: parallel claim race 1-winner/1-loser (MySQL processes); deadlock 1213
> reproduced pre-fix → both-commit post-fix; MyFatoorah sandbox connectivity+key LIVE-VERIFIED
> (read-only probe, no charge).

## Per-gateway matrix (§44)

| Case | MyFatoorah | Stripe | PayPal |
|---|---|---|---|
| enabled/disabled/configured/misconfigured | PASS (GatewayRegistryTest) | PASS (StripeGatewayTest) | PASS (PayPalGatewayTest) |
| success / failure / cancel / pending | PASS (PaymentCompletionTest) | PASS (mocked) | PASS (mocked) |
| wrong amount / wrong currency | PASS (×1000 + catalog) | PASS (tamper tests) | PASS (tamper tests) |
| duplicate + concurrent callback | PASS (PaymentCompletionTest, Concurrency) | PASS (webhook replay) | PASS (webhook replay) |
| refund / duplicate refund | PASS (validated status) | PASS (succeeded-only) | PASS (COMPLETED-only) |
| webhook valid/forged/replay | n/a (callback-only) | PASS (HMAC) | PASS (SDK verify) |
| live sandbox | BLOCKED | BLOCKED | BLOCKED |

## Method matrix (COD / cashier)

create → pending → mark-paid → completion; unauthorized → 403; duplicate → 422; wrong order → 404;
F-1 legacy-path gate (4 tests); zero-value online (no gateway). All PASS.

## Currency matrix (§45)

Base/catalog combos (EGP×KWD/SAR, USD×KWD), catalog switch KWD→SAR (old stays, new moves),
3-decimal KWD 13.255 end-to-end (snapshot→Stripe 13255→PayPal '13.255'), refund after switch in
original currency. All PASS (`CatalogCurrencyAuthorityTest`, `CurrencyPrecisionTest`, Currency dir).

## Disable test (§46)

`GatewayDisableTest`: create-while-enabled → disable → verify completes → new initiation 422. PASS.

## Admin settings (§47)

list/enable/disable/order/display-name/config-validation/unsupported-currency/403/audit/secrets-absent. PASS (10).

## Failure injection (§48)

Provider timeout/500/malformed, DB rollback on coupon refusal, **lock order unified
Transaction→Order everywhere (forensic: 1213 deadlock reproduced pre-fix → both-commit
post-fix)**. PASS.

## Refund cross-path (forensic)

Admin paid-minus-ledger cap + Marvel approval claim/cap/shared-note; double-approval and
over-refund rejected; gateway failure releases claim. PASS (`MarvelRefundInterplayTest` 4/4).
Full Marvel approval needs marketplace schema (`orders.parent_id`, `wallets`) absent from
migrations and live dev DB — pre-existing gap, fails safely, documented in final audit.

## Financial integrity (§49) / lifecycle (§50)

Provider == order == txn (amount + currency) for all gateways incl. USD-pref override proof;
full pending→paid→completed map (payment/fulfillment/inventory/coupon/invoice/event). PASS.

## Regression (§60)

Green: Payment 149, Currency 190 (+5 pre-existing LogActivityJob-drift fails), AdminOrder 58,
CartLifecycle 38, CheckoutApi 13, PendingRedesign 16, AssignedCoupon 49, CouponSystem 23,
GiftRecon 8, Currency snapshot suites.
PRE-EXISTING-FAIL (hand-built legacy schemas, causes verified unrelated to payment logic):
PaymentProductionHardenTest, PaymentCheckoutTest, PaymentSystemTest, OrderStatusLifecycleTest,
EventSystemTest, WebhookPaymentCompletionTest (view drift), RateMode/MandatoryGate (audit signature).
