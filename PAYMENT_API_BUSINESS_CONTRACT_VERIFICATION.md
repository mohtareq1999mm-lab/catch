# Final Status

```text
PASS WITH GAPS
```

The business contract was fully derived from source. "Gaps" = endpoints
checked for but proven absent (documented as NOT FOUND), plus runtime
behavior not executed (discovery-only task, no code changes).

---

## Verified

* `POST /api/v1/general/checkout` — route, Sanctum auth, controller,
  validation fields, defaults (`online` / `myfatoorah`), all request
  variations (stripe/paypal/myfatoorah/cod/cashier/mobile).
* Frontend sends no amount/currency — proven (absent from validation
  rules; never read by controller/services).
* Success shapes — online `data.url`; COD/cashier/zero-value
  `data.order_id`; envelope `{status,message,success,data?}`.
* Error shapes/codes — 400/401/403/404/422/429/500/503 cases listed with
  exact messages from language files and code.
* `checkout/callback` + `checkout/error-callback` — methods, public
  access, throttles, `paymentId`/`type` validation, verify-then-complete,
  web-redirect vs mobile-JSON, error-callback still completes paid orders.
* No customer verification endpoint exists — verification is internal
  (adapters called by callbacks/webhooks only).
* `webhooks/stripe` + `webhooks/paypal` — signature verification, handled
  events, dedupe/replay safety, terminal 200 acks.
* MyFatoorah has no webhook — proven by design comment + absent route.
* Admin `GET` list + `PUT {code}` update — routes, permissions, accepted
  fields (`enabled`, `display_name`, `sort_order`), response shapes,
  404/422 cases.
* No gateway CREATE endpoint — proven (no POST route; service only
  updates overrides of registered codes).
* No public payment-methods endpoint — proven (route + resource search).
* Enable/disable semantics — initiate blocked (422) vs in-flight verify
  unaffected (registry split + callback/webhook resolution path).
* Gateway availability conditions (exist/enabled/configured/online
  method/currency) — from registry gate + handler mapping.
* Amount/currency authority — backend order total; catalog-currency
  snapshot; mismatch rejects; X-Currency display-only.
* Old orders keep their saved currency after catalog change — from
  snapshot-on-write + resolver precedence.
* Staff `mark-paid` (COD/cashier) and admin `refund` endpoints exist but
  are permission-gated (not customer endpoints).
* Retry reuses the pending order — from pending-order lookup + update
  path.
* Unpaid-order timeouts (24h online / 7d COD) — from payment config;
  provider re-check before cancel — from cancel command.

## Not Found

* Public/customer payment-methods or gateway-availability endpoint.
* Gateway CREATE endpoint (only update of existing gateways exists).
* MyFatoorah webhook endpoint.
* Customer-facing payment verification endpoint.
* Any accepted `amount`/`currency` request field on checkout.

## Not Verified

* Runtime execution (no tests run; discovery-only task).
* Fast-shipping checkout payment behavior (route exists; flow untraced).
* Legacy Marvel cart-verify route registration (controller exists).
* Arabic message wordings (English inspected).
* Migration-by-migration column audit (models/services used as source).
* Live provider / sandbox end-to-end behavior.
* Environment-specific values (keys, live currency lists, frontend URL).

## Important Findings

1. **One endpoint does everything** — checkout creates/updates the order
   AND starts payment; no separate "pay for order" call exists.
2. **Mobile is a request flag, not an endpoint** — `"type": "mobile"` at
   checkout switches callbacks from 302 redirects to JSON.
3. **Verify-failed mobile callback returns HTTP 200 with
   `success: true`** but `data.status: "failed"` — frontend must read the
   inner status, not just HTTP/success.
4. **Error-callback can still complete the order** if the provider
   confirms payment — frontend must treat its success landing as real.
5. **Disabling a gateway never strands in-flight payments** — they still
   verify/complete.
6. **Validation errors (422) use a different body shape** (raw field
   errors) than all other responses (envelope).
7. **Zero-value online orders complete without any provider** and return
   `order_id` without `url` — frontend must not expect a redirect URL.
8. Amount/currency mismatch is a hard reject (order stays pending) — a
   support case, not a silent retry.

## Files Examined

```text
routes/api.php (payment + admin routes)
app/Providers/RouteServiceProvider.php (api prefix)
app/Providers/AppServiceProvider.php (throttles)
app/Http/Controllers/Api/General/OrderController.php (checkout, callbacks)
app/Http/Controllers/Api/General/PaymentWebhookController.php (webhooks)
app/Http/Controllers/Api/Admin/PaymentGatewaySettingsController.php
app/Http/Controllers/Api/Admin/PaymentRefundController.php
app/Http/Requests/Payment/UpdateGatewaySettingsRequest.php
app/Http/Requests/Payment/RefundOrderRequest.php
packages/marvel/src/Http/Requests/OrderCreateRequest.php (checkout fields)
packages/marvel/src/Http/Requests/CheckoutVerifyRequest.php (legacy cart check)
packages/marvel/src/Http/Controllers/CheckoutController.php (legacy)
packages/marvel/src/Rest/Routes.php (route search)
app/Services/Payment/PaymentCheckoutHandler.php (initiation + gates)
app/Services/Payment/PaymentGatewayRegistry.php (availability conditions)
app/Services/Payment/PaymentGatewayFactory.php (verify-path seam)
app/Services/Payment/GatewaySettingsService.php (enable/disable store)
app/Services/Payment/PaymentCurrencyResolver.php (currency authority)
app/Services/Payment/PaymentCompletionService.php (completion + mismatch)
app/Services/Payment/CurrencyPrecision.php (decimals)
app/Services/Gateway/StripeGateway.php, PayPalGateway.php, MyFatoorahGateway.php
app/Services/Payment/Contracts/PaymentGatewayContract.php
app/Services/Checkout/OrderCreationService.php (totals + snapshot)
app/Services/General/OrderService.php (pending-order reuse)
app/Services/Currency/CurrencyService.php + UserCurrencyPreferenceService.php
app/DTOs/GatewayResult.php
config/payment.php (gateways, defaults, timeouts)
packages/marvel/src/Traits/ApiResponse.php (envelope)
packages/marvel/src/Database/Models/Transaction.php + Order.php (statuses)
packages/marvel/src/Enums/PaymentStatus.php
packages/marvel/config/constants.php (message keys)
resources/lang/en/message.php (messages)
app/Console/Commands/CancelUnpaidOrders.php (unpaid-order handling)
tests/Feature/Payment/* + tests/Feature/Currency/* (behavior map)
```

```text
Production code changed: NO (documentation only)
Documents generated:
- PAYMENT_API_BUSINESS_CONTRACT.md
- PAYMENT_API_BUSINESS_CONTRACT_VERIFICATION.md (this file)
Prior related document (unchanged): PAYMENT_API_FRONTEND_INTEGRATION.md
```
