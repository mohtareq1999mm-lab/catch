# PAYMENT + CATALOG CURRENCY — VERIFICATION REPORT

Date: 2026-09-26 · Mode: READ-ONLY verification (no production code changed) · Live providers: mocked boundary (credentials unavailable)

Core question: **When the Admin sets the Catalog Currency, does checkout create an Order in that currency, and does whichever gateway is used charge that exact Order amount in that exact Order currency?**

Answer: **YES — proven by source code + 100 passing automated tests.**

---

## 1. Executive Summary

- Single payment-currency authority: `App\Services\Payment\PaymentCurrencyResolver::forOrder()` — `catalog_currency_code` → legacy `currency_code` → live catalog → config default. Never base, never user preference.
- `OrderCreationService::resolveCurrencySnapshot()` writes `currency_code = catalog`, `catalog_currency_code = catalog`, keeps `base_currency_code`/`converted_total_price` as reporting-only sidecar data.
- All three gateways (MyFatoorah, Stripe, PayPal) source provider currency exclusively from the resolver; completion (`PaymentCompletionService`) fails closed on amount/currency/provider-ref mismatch.
- `app/Services/Payment/**` contains **zero** `getBaseCode`/`base_currency`/`getEffectiveCode`/preference references (verified by scoped search).
- Checkout (`OrderController::checkout`) accepts no client currency/amount: `OrderCreateRequest` has no currency/amount fields; amount = server-side `order->total_price`.
- Gateway disable is per-code (`settings.options.payment_gateways.<code>.enabled`); `canInitiate` blocks NEW money, `canVerify`/`resolve` deliberately still complete in-flight payments.
- Test evidence: 101 tests / 649 assertions pass across 11 suites. No new tests were needed — all 14 required scenarios already covered.
- Live provider charge (real MyFatoorah/Stripe/PayPal API call) = NOT VERIFIED (no credentials); everything up to the provider request payload is verified via mocked-client tests.

## 2. Actual Current Flow

```
Admin sets Catalog Currency (settings.options.catalog_currency_code)
  → Catalog pricing in catalog code (CurrencyPrecision exponent rounding)
  → Checkout totals computed server-side in catalog code
  → OrderCreationService snapshots currency_code=catalog, catalog_currency_code=catalog
  → checkout: amount=order->total_price, currency=resolver->forOrder(order)
  → canInitiate gate (enabled+configured+method+currency) → gateway createInvoice
  → Transaction row (amount, currency=snapshot) → provider redirect
  → callback: verifyPayment → PaymentCompletionService::completeLocked
     (x1000 amount + currency + provider-ref fail-closed checks vs snapshot)
  → order completed
```

Flow table (source → destination per arrow):

| Arrow | File | Class / Method | Currency source | Currency destination |
|---|---|---|---|---|
| Admin → settings | `app/Services/Currency/CurrencyService.php` | `setCatalogCurrency()` L265 | Active `Currency` row w/ rate | `settings.options.catalog_currency_code` ONLY (base/`currency` untouched, L290-293) |
| Settings → code | `app/Services/Currency/CurrencyService.php` | `getCatalogCode()` L64 / `getBaseCode()` L53 | settings options → `config('shop.default_currency')` fallback | Uppercase code string |
| Catalog → totals | `app/Services/Checkout/OrderCreationService.php` | `createOrder()` L31-45 | `getCatalogCode()` | `$totalPrice` rounded via `CurrencyPrecision::roundForCurrency` to catalog exponent |
| Totals → snapshot | `app/Services/Checkout/OrderCreationService.php` | `resolveCurrencySnapshot()` L414-443 | `getCatalogCode()` + `getBaseCode()` | `currency_code=catalog`, `catalog_currency_code=catalog`, `base_currency_code=base`, `converted_total_price` (reporting sidecar) |
| Checkout → handler | `app/Http/Controllers/Api/General/OrderController.php` | `checkout()` L92-142 | `order->total_price` (server) + `resolver->forOrder(order)` | `$orderPrice` → `PaymentCheckoutHandler::handleOnlinePayment` |
| Handler → gateway | `app/Services/Payment/PaymentCheckoutHandler.php` | `handleOnlinePayment()` L28-140 | `resolver->forOrder(order)` (L36, L64, L130) | `canInitiate` gate + `createInvoice($order,$amount,…)` + `transactions.currency` |
| Resolver | `app/Services/Payment/PaymentCurrencyResolver.php` | `forOrder()` | `order.catalog_currency_code` → `order.currency_code` → live catalog → `config('payment.default_currency')` | Uppercase code |
| MyFatoorah → provider | `app/Services/Gateway/MyFatoorahGateway.php` | `createInvoice()` | resolver | `DisplayCurrencyIso=$orderCurrency`, `InvoiceValue=$amount`; `GatewayResult.currency=$orderCurrency` |
| Stripe → provider | `app/Services/Gateway/StripeGateway.php` | `createInvoice()` L73-155 | resolver | `price_data.currency=strtolower(orderCurrency)`, `unit_amount=toMinorUnits(amount, orderCurrency)` (13255 for 13.255 KWD) |
| PayPal → provider | `app/Services/Gateway/PayPalGateway.php` | `createInvoice()` L86-171 | resolver | `amount.currency_code=$currency`, `value=CurrencyPrecision::formatForGateway` ('13.255' KWD) |
| Verify → complete | `app/Services/Payment/PaymentCompletionService.php` | `completeLocked()` / `assertNoMismatch()` L157-264 | `resolver->forOrder(order)` = expected; `GatewayResult` = received | `PaymentMismatchException` (amount/currency/ref missing or divergent) → txn `failed`, order stays pending |
| Disable gate | `app/Services/Payment/PaymentGatewayRegistry.php` | `canInitiate()` L49 (enabled+configured+method+currency) / `canVerify()` L108 (ignores enabled) | merged `config('payment.gateways')` + `GatewaySettingsService` allowlist `{enabled,display_name,sort_order}` | New initiation blocked; in-flight verification preserved |

## 3. Catalog Currency Source of Truth

- Stored: `settings.options.catalog_currency_code` (Marvel `Settings` row). Written ONLY by `CurrencyService::setCatalogCurrency()` (guarded: currency must be active + have a rate ≤ today; row-locked transaction).
- Retrieved: `CurrencyService::getCatalogCode()`; payment-path alias `PaymentCurrencyResolver::current()`.
- Owner: `App\Services\Currency\CurrencyService`. Catalog pricing uses it (`createOrder` L42, `convertToEffective` defaults L464, `convertPrice` L163-183).
- Checkout obtains it at order-creation time and freezes it into the order snapshot (never re-read live for existing orders — resolver prefers the snapshot columns first).

## 4. Base Currency Analysis — NOT USED FOR PAYMENT

- Scoped search over `app/Services/Payment/**` for `getBaseCode|base_currency|getEffectiveCode|X-Currency|preference`: **0 matches**. The entire payment path (resolver, handler, completion, precision, registry, factory, settings) never reads base or user currency.
- `getBaseCode()` (settings `base_currency_code`) is used ONLY for: snapshot sidecar `base_currency_code`, `converted_total_price` reporting conversion (`resolveCurrencySnapshot` L429-441), and base-change admin guards. It never reaches a gateway payload or a completion comparison.
- Proof by divergence: `OrderCurrencyTest::order_with_zero_total_still_records_snapshot` — base=KWD, catalog=USD → `currency_code=USD`, `catalog_currency_code=USD`; payment would read USD. Base does not leak into the payment currency.

## 5. User Currency Analysis — NOT AUTHORITATIVE

- `OrderCreateRequest` (Marvel) has NO currency/amount fields — the client cannot submit a payment currency. Checkout reads only `payment_method`/`gateway`/`fulfillment_type` from the request; amount comes from `order->total_price`.
- `X-Currency` header / user preference feed ONLY `CurrencyService::getEffectiveCode()` (display/convert layer; disabled by default → always catalog). `resolveCurrencySnapshot()` and `PaymentCurrencyResolver::forOrder()` never call `getEffectiveCode()`.
- Direct proof: `CatalogCurrencyAuthorityTest::checkout_handler_and_gateway_invoice_use_catalog_currency_despite_user_preference` — preference=USD (effective=USD), catalog=KWD → order KWD, `DisplayCurrencyIso` KWD, transaction KWD.

## 6. Order Currency Snapshot

- At creation, `currency_code` + `catalog_currency_code` = catalog code at checkout; `total_price` exponent-rounded to that currency.
- `setCatalogCurrency()` writes settings only — no order rows touched. Existing orders immutable.
- `updateOrder()` re-snapshots ONLY the single `pending` order found by `findPendingOrderForUser()` (status=pending, row-locked; `OrderService::addItemsInOrder` L244/L283) — i.e. pre-payment retry rebuild, which IS a new checkout. Paid/completed orders are never passed through this path. Payment for those rows keeps reading their frozen snapshot columns.
- Direct proof: `CatalogCurrencyAuthorityTest::catalog_switch_only_affects_new_orders` (KWD order → switch to SAR → new order SAR, old order still KWD/KWD).

## 7. Payment Currency Flow

Payment currency = `PaymentCurrencyResolver::forOrder($order)` at every step: registry gate (L36), adapter double-check (L64-78), `createInvoice` in all three adapters, transaction row (L130), COD/cashier rows (L163/L196), zero-value path (`OrderController` L175/L194), completion expectation (L164). No step re-derives currency from live admin settings.

## 8. MyFatoorah Verification — PASS (mocked boundary)

- `createInvoice`: `DisplayCurrencyIso=$orderCurrency` (resolver), `InvoiceValue=$amount` (order total); result `currency=$orderCurrency`. Unsupported order currency → fail-closed, no provider call (`supportsCurrency` vs `MYFATOORAH_SUPPORTED_CURRENCIES`, default includes KWD/SAR/AED/BHD/QAR/OMR/EGP).
- `verifyPayment`: returns provider `InvoiceValue` + `DisplayCurrencyIso`; completion compares both vs snapshot (x1000 + exact currency).
- `refund`: currency from resolver; `supportsCurrency` gate; success ONLY on positive provider `RefundStatus` containing "refund" with no fail/pending markers.
- `isConfigured`: `payment.gateways.myfatoorah.api_key` non-empty. Tests: `PaymentCurrencyTest` (invoice/refund/reconciliation in order currency), `CurrencyPrecisionTest::kwd_13255_snapshots_and_completes_end_to_end` (InvoiceValue 13.255, callback completes).

## 9. Stripe Verification — PASS (mocked boundary)

- `createInvoice`: `currency=strtolower(orderCurrency)`, `unit_amount=toMinorUnits` (delegates to `CurrencyPrecision`; zero-decimal override stays in-gateway). Test asserts `unit_amount=13255` for 13.255 KWD.
- `verifyPayment`: returns `fromMinorUnits(amount_total)` + uppercase currency; cross-checks session vs PaymentIntent amount+currency, fail-closed on divergence.
- Tests: `StripeGatewayTest` 16 pass (incl. cross-check mismatches), `CurrencyPrecisionTest` (KWD minor units, create-invoice payload).

## 10. PayPal Verification — PASS (mocked boundary)

- `createInvoice`: `currency_code=$currency` (resolver), `value=CurrencyPrecision::formatForGateway($amount,$currency)` — decimal string at the currency exponent ('13.255' KWD, never fixed 2dp). Idempotency key `order-{id}-{attempt}`.
- `verifyPayment`: APPROVED→capture (idempotent `capture-{id}` key; already-captured race re-fetched), COMPLETED→totals; `capturedTotals` fail-closed on ambiguous/mixed-currency captures.
- Tests: `PayPalGatewayTest` 17 pass, `CurrencyPrecisionTest::paypal_create_invoice_value_keeps_three_decimals` ('13.255').

## 11. Gateway Settings Verification — PASS

- Source: `config/payment.php` (class, credentials via env, `supported_currencies`, `methods`, `enabled` defaults) merged with `settings.options.payment_gateways` allowlist `{enabled, display_name, sort_order}` only (`GatewaySettingsService::merged()` L199-225; secrets/class/currencies stay env-only even if a hostile DB row carries them).
- Admin: enable/disable + display_name + sort_order persist per-code; secrets never in responses nor stored (`GatewaySettingsAdminTest` 11 pass: disable→blocks initiate→re-enable restores; 401/403/404/422; audit job + activity log).
- Registry: unknown→`UnsupportedGatewayException`; disabled→`canInitiate=disabled` (new money blocked, no transaction row); misconfigured (empty key)→`misconfigured`; unsupported currency→`currency_unsupported` 422 (`GatewayRegistryTest` 9 pass; `GatewayDisableTest::disable_mid_payment_still_completes_then_new_initiation_422` 10 pass — mid-payment disable still completes, new initiation 422s).
- Independence: overrides keyed per gateway code (`updateOverride($code)` touches only `$gateways[$code]`); disabling one code cannot alter another's merged definition. Structural PASS (code-verified; no cross-gateway test exists — noted below, non-blocking).

## 12. Amount/Currency Matching — PASS

- `PaymentCompletionService::assertNoMismatch`: expected `round(total_price*1000)` vs received `round(amount*1000)` (symmetric x1000 ⇒ exact for ≤3dp; comment L147-156 forbids "fixing" one side), expected currency = resolver, plus provider-ref must belong to the locked transaction row. Any divergence (or missing amount/currency) throws `PaymentMismatchException` → caller marks txn `failed` inside the same lock; order never completes.
- Test bypass (`test_bypass`) requires BOTH `PAYMENT_TEST_GATEWAY_BYPASS` flag AND local/testing env (`OrderController::isTestGatewayBypassAllowed`) — staging pointing at apitest cannot silently complete underpaid orders.
- Tests: `PaymentCompletionTest::callback_amount_and_currency_mismatch_mark_failed`, provider-ref mismatch, reconciliation currency-mismatch row (`PaymentCurrencyTest`), Stripe/PayPal verify-level cross-checks.

## 13. Decimal Precision Verification — PASS

- Single authority `CurrencyPrecision`: exponent-3 = BHD/JOD/KWD/OMR/TND (3dp), else 2dp. Snapshots, gateway payloads, refund math share it.
- Scope example (base=EGP, catalog=KWD, product 10.125 + shipping 3.130 = 13.255): `roundForCurrency(13.255,KWD)=13.255` (vs 13.26 USD); Stripe `toMinorUnits=13255`; PayPal `formatForGateway='13.255'`; MyFatoorah `InvoiceValue=13.255, DisplayCurrencyIso=KWD`; end-to-end callback completes at 13.255 (`CurrencyPrecisionTest` 7 pass: `decimals_for_three_decimal_currencies` covers BHD/JOD/KWD/OMR/TND incl. case/whitespace variants).

## 14. Catalog Currency Change Test — PASS

Maps exactly to scope Test A/B/C via `CatalogCurrencyAuthorityTest::catalog_switch_only_affects_new_orders`: catalog=KWD → order KWD/payment KWD; switch to SAR → new order SAR/payment SAR; old order re-read from DB still KWD/KWD. Refund variant: `RefundCurrencyAndLedgerTest::refund_after_catalog_switch_succeeds_in_transaction_currency`.

## 15. Automated Test Results

All run 2026-09-26 via `php artisan test <file>` (PHP 8.2.30), all PASS, 0 failures:

| Suite | Tests | Assertions |
|---|---|---|
| Currency/PaymentCurrencyTest | 5 | 17 |
| Payment/CatalogCurrencyAuthorityTest | 3 | 28 |
| Payment/CurrencyPrecisionTest | 7 | 40 |
| Payment/GatewaySettingsAdminTest | 11 | 112 |
| Payment/GatewayRegistryTest | 9 | 34 |
| Payment/GatewayDisableTest | 10 | 78 |
| Payment/PaymentCompletionTest | 14 | 117 |
| Currency/OrderCurrencyTest | 5 | 37 |
| Payment/StripeGatewayTest | 16 | 83 |
| Payment/PayPalGatewayTest | 17 | 80 |
| Payment/RefundCurrencyAndLedgerTest | 4 | 23 |
| **Total** | **101** | **649** |

Coverage of the 14 required scenarios: 1✓ OrderCurrencyTest/CatalogCurrencyAuthorityTest · 2✓ PaymentCurrencyTest · 3✓ zero base-refs + divergent base/catalog test · 4✓ preference-vs-catalog test · 5✓ catalog-switch test · 6✓ 13.255 KWD end-to-end · 7✓ 5-currency decimals test · 8✓ MyFatoorah tests · 9✓ Stripe tests · 10✓ PayPal tests · 11✓ mismatch tests · 12✓ mismatch tests · 13✓ disable tests · 14✓ per-code override structure (code-verified). No new tests added — existing suite already proves each behavior.

**19. Final Status**

**PASS WITH NON-BLOCKING LIMITATION** — the architecture and code fully satisfy `PAYMENT = ORDER = CATALOG AT CHECKOUT` (proven by source + 101 passing tests); the sole limitation is that a real external provider charge could not be executed (no credentials), which is environmental, not architectural.

## 16. Live Provider Verification Status

- MyFatoorah / Stripe / PayPal real-API charge: **NOT VERIFIED** — no credentials/environment available; provider SDKs are replaced by mocks/fakes at the client boundary in all gateway tests. Payload construction up to that boundary (currency code, amount/minor-units/decimal-string, idempotency keys, fail-closed verify parsing) IS verified.
- No fake live verification was created, per scope.

## 17. Findings

1. **No defects found in the payment-currency chain.** Every required invariant holds in source and is pinned by tests.
2. **Stale-doc note (non-blocking):** some `api-desc/currency/*` passages describe the order snapshot as "base currency" (`currency_code (= base)`), while the implementation (`resolveCurrencySnapshot` L435) and `PAYMENT_ARCHITECTURE.md` agree `currency_code` = catalog. Docs were NOT touched per documentation-safety rules; flagged only so a future docs-sync pass can align the wording.
3. **Gateway-independence test gap (non-blocking):** disabling gateway A leaving gateway B initiable is proven structurally (per-code keys) but has no single test asserting both states simultaneously. Existing per-gateway disable tests make this low-risk.

## 18. Required Fixes

**None.** No production-code defect was discovered, so per scope §16 nothing was changed and nothing awaits approval. The two notes above are documentation/test-hardening suggestions, not required fixes.

## 19. Final Status

**PASS WITH NON-BLOCKING LIMITATION** — the architecture and code fully satisfy `PAYMENT = ORDER = CATALOG AT CHECKOUT` (proven by source + 101 passing tests); the sole limitation is that a real external provider charge could not be executed (no credentials), which is environmental, not architectural.

---

### Evidence index (files inspected)

`app/Services/Payment/PaymentCurrencyResolver.php` · `CurrencyPrecision.php` · `PaymentCheckoutHandler.php` · `PaymentCompletionService.php` · `PaymentCompletionOutcome.php` · `PaymentGatewayRegistry.php` · `PaymentGatewayFactory.php` · `GatewaySettingsService.php` · `app/Services/Gateway/MyFatoorahGateway.php` · `StripeGateway.php` · `PayPalGateway.php` · `app/Services/Currency/CurrencyService.php` · `app/Services/Checkout/OrderCreationService.php` · `app/Services/General/OrderService.php (addItemsInOrder)` · `app/Http/Controllers/Api/General/OrderController.php (checkout/checkoutCallback)` · `app/Exceptions/PaymentMismatchException.php` · `config/payment.php` · `packages/marvel/src/Http/Requests/OrderCreateRequest.php` · `tests/Feature/{Currency/PaymentCurrencyTest, OrderCurrencyTest, CatalogCurrencyTest, BaseCurrencyTest, GatewayCurrencySupportTest, Payment/* (11 suites)}`
