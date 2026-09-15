# STRIPE / PAYPAL PAYMENT FLOW AUDIT — READ-ONLY

> **Scope:** `packages/marvel` vs `app` payment architecture, Laravel 10.30.1
> **Date:** 2026-09-13
> **Mode:** READ-ONLY — no code, env, migration or route modifications
> **Evidence tier:** every claim anchored to an actual file/class/method. Values redacted.

---

## 1. Executive Summary

| Question | Stripe | PayPal |
|---|---|---|
| Code exists (Marvel kernel) | **YES** `packages/marvel/src/Payment/Stripe.php:17` | **YES** `packages/marvel/src/Payment/Paypal.php:16` |
| Wired into *current* checkout (`app/Http/Controllers/Api/General/OrderController.php:87`) | **NO** — factory only resolves `myfatoorah` | **NO** |
| Configured in `config/payment.php:4` | **NO** — only `myfatoorah` entry | **NO** |
| SDK installed (`packages/marvel/composer.json:28-30`) | `stripe/stripe-php 13.1.0` | `srmklive/paypal 3.0.19` |
| Reachable via active route without code change | **NO** | **NO** |
| Webhook implementation in Marvel | YES (`Stripe.php:263`) | YES (`Paypal.php:160`) |
| Webhook reachable via current `routes/api.php` | **NO** — only `POST /api/v1/webhooks/*` under Marvel `Rest/Routes.php` (legacy) — `app` layer exposes only `any checkout/callback` for MyFatoorah | same |
| Production-ready as-is | **PARTIALLY WORKING (legacy, disconnected)** | **PARTIALLY WORKING (legacy, disconnected)** |
| To make operational | Requires factory + gateway + config + route wiring + frontend contract change — not a config-only fix | same |

**Bottom line:** This codebase ships **two payment universes**:

1. **Marvel legacy universe** — 11 gateway classes (`Stripe`, `Paypal`, `Razorpay`, `Mollie`, `Paystack`, `Iyzico`, `Bkash`, `Paymongo`, `Flutterwave`, `Xendit`, `Sslcommerz`) behind `Marvel\Payments\PaymentInterface` / `Marvel\Facades\Payment` / `Settings`-driven `ShopServiceProvider` singleton. Stripe and PayPal are fully coded here.
2. **Application universe (canonical for current checkout)** — `App\Services\Payment\Contracts\PaymentGatewayContract` → `PaymentGatewayFactory` → `MyFatoorahGateway` only. `OrderController::checkout` (`app/.../OrderController.php:87`), `PaymentCheckoutHandler` (`app/Services/Payment/PaymentCheckoutHandler.php:26`), `OrderService`, `Transaction` model, `checkout/callback` / `checkout/error-callback` (`routes/api.php:131-132`). Stripe/PayPal are **not registered, not selectable, not tested** in this universe.

Saying "Stripe/PayPal work" because the class files exist is **false**. They require non-trivial wiring before a single live transaction can flow.

---

## 2. Repository Evidence (files inspected)

### 2.1 Stripe

| Artifact | Path | Key lines |
|---|---|---|
| Gateway class | `packages/marvel/src/Payment/Stripe.php:17` | `class Stripe extends Base implements PaymentInterface`, `use OrderStatusManagerWithPaymentTrait, PaymentTrait` |
| Constructor | `Stripe.php:30` | `new StripeClient(config('shop.stripe.api_secret'))` |
| PaymentIntent create | `Stripe.php:97-151` | `getIntent`, `paymentIntents->create`, `amount*100`, `automatic_payment_methods.enabled` |
| Retrieve / confirm | `Stripe.php:159`, `Stripe.php:199` | `paymentIntents->retrieve`, `->confirm` |
| Verify | `Stripe.php:234` | `charges->retrieve($id)`, `paid` boolean |
| Webhook | `Stripe.php:263-308` | `Webhook::constructEvent($payload, $sig_header, $endpoint_secret)`, `matchSucceededOrFailed`, `paymentGatewayWebHookResponse` |
| Base currency source | `packages/marvel/src/Payment/Base.php:9` | `$this->currency = Settings::first()->options['currency']` |
| Config mapping | `packages/marvel/config/shop.php:68-71` | `'stripe' => ['api_secret'=>env('STRIPE_API_KEY'), 'webhook_secret'=>env('STRIPE_WEBHOOK_SECRET_KEY')]` |
| Composer SDK | `packages/marvel/composer.json:28` | `stripe/stripe-php: 13.1.0` |
| Env example | `.env.example:99-101` | `STRIPE_API_KEY=`, `STRIPE_WEBHOOK_SECRET_KEY=` |
| Gateway type enum | `packages/marvel/src/Enums/PaymentGatewayType.php:17` | `STRIPE = 'STRIPE'` |
| Payment facade | `packages/marvel/src/Facades/Payment.php:10`, `packages/marvel/src/Payment/Payment.php:6` | Delegates to `PaymentInterface` |
| Provider binding | `packages/marvel/src/ShopServiceProvider.php:253-265` | `'payment' singleton` resolves `Marvel\Payments\ . ucfirst($active_payment_gateway)` from `Settings.options['defaultPaymentGateway']` or `request payment_gateway` |
| Trait wiring | `packages/marvel/src/Traits/PaymentStatusManagerWithOrderTrait.php:26`, `packages/marvel/src/Traits/PaymentTrait.php:181` | `stripe($order,$request,$settings)` pulls `PaymentIntent` + `Payment::retrievePaymentIntent` |
| Webhook table claim | `docs/api-contract.md:514` | `POST /webhooks/stripe → Stripe` via `packages/marvel/src/Rest/Routes.php` |

### 2.2 PayPal

| Artifact | Path | Key lines |
|---|---|---|
| Gateway class | `packages/marvel/src/Payment/Paypal.php:16` | `class Paypal extends Base implements PaymentInterface`, `PayPalClient $paypalClient` |
| Constructor | `Paypal.php:25-33` | `new PayPalClient(config('shop.paypal'))`, `getAccessToken()` |
| Order create | `Paypal.php:78-109` | `getIntent`: `createOrder([intent=>CAPTURE, purchase_units=>[invoice_id=>tracking_number, amount=>[currency_code=>$this->currency, value=>round(amount,2)]], payment_source.paypal.experience_context=>[cancel_url=>{$redirectUrl}/orders/{$tracking_number}/payment, return_url=>{$redirectUrl}/orders/{$tracking_number}/thank-you]])` , returns `[redirect_url=>links[1].href, payment_id=>id, is_redirect=>true]` |
| Verify (capture) | `Paypal.php:159-166` source shows `verify` at `Paypal.php:178` | `capturePaymentOrder($id)`, returns `status` |
| Retrieve/confirm stubs | `Paypal.php:127-148` | returns `(object)[]` — **NOT IMPLEMENTED** |
| Webhook | `Paypal.php:160-211` | `config('shop.paypal.webhook_id')`, verify headers `PAYPAL-AUTH-ALGO`, `CERT-URL`, `TRANSMISSION-ID/SIG/TIME`, `verifyWebHook`, cases `PAYMENT.CAPTURE.COMPLETED/PENDING/CANCELLED/REVERSED` → `updatePaymentOrderStatus` → `webhookSuccessResponse` |
| Config mapping | `packages/marvel/config/shop.php:76-98` | `'paypal'=>[mode=>env('PAYPAL_MODE','sandbox'), sandbox=>[client_id=>env(PAYPAL_SANDBOX_CLIENT_ID), client_secret=>env(PAYPAL_SANDBOX_CLIENT_SECRET)], live=>[client_id=>env(PAYPAL_LIVE_CLIENT_ID),...], payment_action=>env(...), webhook_id=>env('PAYPAL_WEBHOOK_ID'), currency=>env('PAYPAL_CURRENCY','USD'), notify_url=>env('PAYPAL_NOTIFY_URL'), locale=>env('PAYPAL_LOCALE','en_US'), validate_ssl=>env('PAYPAL_VALIDATE_SSL',true)]` |
| Composer SDK | `packages/marvel/composer.json:30` | `srmklive/paypal: 3.0.19` |
| Env example | `.env.example:104-122` | `PAYPAL_MODE`, `PAYPAL_SANDBOX_CLIENT_ID`, `PAYPAL_SANDBOX_CLIENT_SECRET`, `PAYPAL_LIVE_CLIENT_ID`, `PAYPAL_LIVE_CLIENT_SECRET`, `PAYPAL_CURRENCY`, `PAYPAL_NOTIFY_URL`, `PAYPAL_LOCALE`, `PAYPAL_VALIDATE_SSL`, `PAYPAL_WEBHOOK_ID`, plus legacy `PAYPAL_REDIRECT_URL` (unused) |
| Enum | `packages/marvel/src/Enums/PaymentGatewayType.php:18` | `PAYPAL='PAYPAL'` |
| Trait wiring | `packages/marvel/src/Traits/PaymentStatusManagerWithOrderTrait.php:79` | `paypal(Order $order, ...)` via `Payment::verify` |

### 2.3 Current (canonical) checkout — for contrast

| Artifact | Path |
|---|---|
| Checkout route | `routes/api.php:131-132` `any checkout/callback`, `any checkout/error-callback` → `App\Http\Controllers\Api\General\OrderController@checkoutCallback` |
| Checkout endpoint | `routes/api.php:115` `POST v1/general/checkout` → `OrderController::checkout` |
| Controller | `app/Http/Controllers/Api/General/OrderController.php:87` |
| Handler | `app/Services/Payment/PaymentCheckoutHandler.php:26` `handleOnlinePayment` |
| Factory | `app/Services/Payment/PaymentGatewayFactory.php:9` `match ($gateway){'myfatoorah'=>app(MyFatoorahGateway::class), default=>throw}` |
| Gateway contract | `app/Services/Payment/Contracts/PaymentGatewayContract.php:8` |
| Gateway impl | `app/Services/Gateway/MyFatoorahGateway.php:10` |
| Payment config | `config/payment.php:4` only `myfatoorah` gateway entry, `default_gateway=myfatoorah`, `default_currency=KWD` (note Marvel `.env.example` says `DEFAULT_CURRENCY=USD` — inconsistency) |
| Transaction model | `app` `Marvel\Database\Models\Transaction` (actually `packages/marvel/src/Database/Models/Transaction.php` used by app layer) |
| Order model | `packages/marvel/src/Database/Models/Order.php` (also used by app) |

### 2.4 Shared / cross-cutting

| Artifact | Path |
|---|---|
| PaymentTrait (intent + webhookSuccessResponse) | `packages/marvel/src/Traits/PaymentTrait.php:22` |
| OrderStatusManagerWithPaymentTrait | `packages/marvel/src/Traits/OrderStatusManagerWithPaymentTrait.php:19` |
| PaymentStatusManagerWithOrderTrait | `packages/marvel/src/Traits/PaymentStatusManagerWithOrderTrait.php:13` |
| OrderRepository (legacy) | `packages/marvel/src/Database/Repositories/OrderRepository.php:43` |
| OrderController legacy | `packages/marvel/src/Http/Controllers/OrderController.php:76` `submitPayment()` |
| Marvel settings | `packages/marvel/src/Database/Models/Settings.php` |
| Root composer | `composer.json` requires `marvel/shop: dev-main` (path repo `packages/marvel`) — does **not** directly require `stripe/stripe-php` or `srmklive/paypal`; those come transitively via `packages/marvel/composer.json` |

---

## 3. Architecture

### 3.1 Two payment abstractions (not interchangeable)

**Marvel legacy (`packages/marvel`)**

```
ShopServiceProvider::register() :263  — singleton 'payment'
  │
  ├─ reads Settings::first()->options['defaultPaymentGateway']  (DB admin setting)
  ├─ or request()->payment_gateway  (if not CASH/CASH_ON_DELIVERY)
  ├─ resolves "Marvel\Payments\{Gateway}"  e.g. Marvel\Payments\Stripe
  └─ wraps in Marvel\Payments\Payment  (Payment.php:6) delegating to PaymentInterface
           │
           └─ PaymentInterface.php:8  getIntent, verify, handleWebHooks,
                    createCustomer, attach/detachPaymentMethod,
                    retrieve/confirmPaymentIntent, setIntent, retrievePaymentMethod

Base.php:9 — every gateway reads currency from Settings.options['currency']
PaymentTrait.php — intent storage/retrieval, webhookSuccessResponse (transactional, idempotent for SUCCESS)
PaymentStatusManagerWithOrderTrait — stripe()/paypal()/etc. methods that poll provider after order creation
OrderController.php:499 submitPayment() — switch on PaymentGatewayType::STRIPE|PAYPAL etc. calls gateway logic
Rest/Routes.php — POST /api/v1/webhooks/{stripe,paypal,mollie,...} → gateway handleWebHooks
```

*Selection:* `payment_gateway` request field **or** `Settings.options['defaultPaymentGateway']`. No `PaymentGatewayFactory`; string-matching + IoC `make()`.

*Storage:* `payment_intents` table (`PaymentIntent` model), `payment_methods`/`payment_gateways` tables for saved cards/customers. Order row carries `payment_gateway` string (`Order.php` fillable `payment_gateway`).

*Config source:* `config/shop.php` (merged via `ShopServiceProvider::register`) → `.env` + **DB Settings.options** for `currency` and gateway list. This is a **dual source** — the most dangerous aspect for Stripe/PayPal enablement.

**Application (`app`) — canonical for current storefront**

```
Route POST v1/general/checkout  routes/api.php:115
  → OrderController::checkout()  app/.../OrderController.php:87
     → OrderService::addItemsInOrder()  (creates Order with status 'pending', reserves stock, handles coupons)
     → PaymentCheckoutHandler::handleOnlinePayment()  app/Services/Payment/PaymentCheckoutHandler.php:26
         → PaymentGatewayFactory::make($gateway)  app/Services/Payment/PaymentGatewayFactory.php:9
         │     match: 'myfatoorah' => MyFatoorahGateway, else UnsupportedGatewayException
         → MyFatoorahGateway::createInvoice($order,$amount,$callbackUrl,$errorUrl) → GatewayResult{redirectUrl, gatewayTransactionId}
         → Transaction::create([order_id, user_id, invoice_id, payment_method=>$gateway, status=>'pending', amount, currency, gateway_transaction_id, gateway_response])
         → JsonResponse {url: redirectUrl}  → frontend redirects user to MyFatoorah hosted page

Callback: any v1/general/checkout/callback  routes/api.php:131
  → OrderController::checkoutCallback()  :169
     → lookup Transaction by gateway_transaction_id or invoice_id
     → PaymentGatewayFactory::make(transaction.payment_method ?? 'myfatoorah')
     → Gateway::verifyPayment(paymentId)  → MyFatoorahGateway uses GetPaymentStatus
     → amount/currency mismatch check (test gateway ignores, live blocks)
     → DB::transaction lockForUpdate(Transaction, Order), idempotency guard status!='pending', update Transaction status='paid', Order payment_status=SUCCESS, commit reservation, finalize promotion, changeOrderStatus('completed'), fire PaymentSucceeded
```

*Selection:* `gateway` request field, default `config('payment.default_gateway','myfatoorah')` (`OrderController.php:87`). **Not** `payment_gateway`, **not** `Settings.options`.

*Storage:* `transactions` table only; `payment_intents` is **not used** by app checkout. Order row `payment_gateway` column still exists but app path writes via `OrderCreationService` (`orderData` merging `payment_gateway`).

*Config source:* `config/payment.php` + `config/services.php:myfatoorah` → `.env` `MYFATOORAH_*`. DB Settings not involved in gateway selection (only for order amounts, taxes, currency snapshot via `OrderCreationService::resolveCurrencySnapshot`).

### 3.2 How Stripe/PayPal *would* have been selected (legacy)

* `POST /api/v1/orders` `OrderController::store()` `packages/marvel/src/Http/Controllers/OrderController.php:244` → `OrderRepository::storeOrder()` → creates Order with `payment_gateway` from request.
* `POST /api/v1/orders/payment` (documented in `docs/api-contract.md:4.13`) → `OrderController::submitPayment()` `:499` switch on `PaymentGatewayType`. For `STRIPE`, it would eventually call `PaymentTrait::processPaymentIntent` / `PaymentStatusManagerWithOrderTrait::stripe` which reads `payment_intent` relation. Frontend then confirms via Stripe.js using `client_secret`.
* Alternatively, `GET /api/v1/orders/{id}` `fetchSingleOrder()` `:300` attaches `payment_intent` via `attachPaymentIntent()` if gateway is not COD/CASH — lazy intent creation on order fetch (!).

None of these routes are exercised by the current `app` storefront checkout. The modern frontend calls `POST v1/general/checkout` which never touches `Marvel\Facades\Payment`.

### 3.3 Gateway resolution table

| Layer | Abstraction | Implementations | Selector | Config |
|---|---|---|---|---|
| Marvel | `Marvel\Payments\PaymentInterface` + `Marvel\Payments\Payment` facade | 11 classes under `packages/marvel/src/Payment/` (`Stripe`, `Paypal`, `Mollie`, `Razorpay`, `Paystack`, `Iyzico`, `Bkash`, `Paymongo`, `Flutterwave`, `Xendit`, `Sslcommerz`) | `Settings.options['defaultPaymentGateway']` or `request.payment_gateway` in `ShopServiceProvider` | `config/shop.php:*` + DB Settings |
| App | `App\Services\Payment\Contracts\PaymentGatewayContract` | 1 class `MyFatoorahGateway` (+ stubs for extensibility) | `request.gateway` or `config('payment.default_gateway')` in `OrderController::checkout` + `PaymentGatewayFactory::make` | `config/payment.php` + `config/services.php` |

**Factory/Strategy/Manager:** Marvel has **no factory** — it is a service-locator string concat (`'Marvel\\Payments\\'.ucfirst(...)`); App has a strict `PaymentGatewayFactory` **strategy factory** (`match`). Neither has a manager/resolver beyond that.

---

## 4. Complete Checkout Flow (actual code)

### 4.1 Modern canonical flow (MyFatoorah — what actually runs today)

```
1. Frontend/API
   POST v1/general/checkout  routes/api.php:115  (auth:sanctum, throttle:authenticated)
   Body: {products, amount, sales_tax, total, paid_total, payment_method, gateway, fulfillment_type, ...}
   Validated by: Marvel\Http\Requests\OrderCreateRequest (via OrderService::addItemsInOrder)

2. Route
   routes/api.php:115  Route::post('checkout', [OrderController::class,'checkout'])

3. Controller  app/Http/Controllers/Api/General/OrderController.php:87
   checkout(Request $request)
    - validated via OrderCreateRequest
    - $cart = cartInventoryService->getActiveCartForUser(user)  → 400 CART_NOT_FOUND if none
    - $paymentMethod = input('payment_method','online')  // in: online|cod|pay_at_cashier
    - $gateway = input('gateway', config('payment.default_gateway','myfatoorah'))
    - $fulfillmentType = input('fulfillment_type','delivery')
    - merge fulfillment_type/payment_method/payment_gateway into request
    - $order = orderService->addItemsInOrder($request)  // throws CartEmptyException/InvalidArgumentException

4. Service — OrderService::addItemsInOrder  (app/Services/General/OrderService.php — not shown in full but evidenced by OrderController, PaymentCheckoutHandler, docs)
   - resolves currency snapshot via OrderCreationService::resolveCurrencySnapshot
   - creates Order row status='pending' (Order.php), payment_gateway = gateway (if online) else null
   - createOrderItems, syncOrderItems, deduct/validate stock via OrderRepository::validateAndLockStock / deductStock
   - handles coupon/promotion via PromotionService, CouponReservationService (reserve, not yet commit)
   - reserves inventory via OrderReservationService (inventory_state='reserved', reservation_expires_at)
   - returns Order

5. Payment selection  OrderController.php:110
   if online → paymentCheckoutHandler->handleOnlinePayment(request, order, round(total_price,2), gateway)

6. Handler  PaymentCheckoutHandler.php:26 handleOnlinePayment
   - gatewayInstance = paymentGatewayFactory->make(gateway)  // only myfatoorah
   - orderCurrency = order.currency_code ?? order.base_currency_code ?? config('payment.default_currency','EGP')
   - if !gateway.supportsCurrency(currency) → 422 PAYMENT_CURRENCY_UNSUPPORTED
   - if order.coupon → couponReservationService->reserve(order, coupon)  (Rule 9, throws 422 if invalid)
   - result = gateway.createInvoice(order, amount, callbackUrl=route('api.checkout.callback'), errorUrl=route('api.checkout.errorCallback'))
   - if !success → 500 ERROR_CREATING_INVOICE
   - Transaction::create([order_id, user_id, invoice_id=gatewayTransactionId, payment_method=gateway, status='pending', amount, currency, gateway_transaction_id, gateway_response=raw+_callback_type])
   - return 200 {url: redirectUrl}

7. Gateway  MyFatoorahGateway.php:17 createInvoice
   - resolves customer contact via CustomerContactResolver
   - calls MyfatoraService (HTTP) → MyFatoorah SendPayment / Invoice API
   - returns GatewayResult{success, redirectUrl, gatewayTransactionId (InvoiceId), amount, currency, rawResponse}

8. External provider  MyFatoorah hosted page
   - user pays → MyFatoorah calls callbackUrl (= app checkout/callback) with paymentId (or error-callback on failure)

9. Callback  routes/api.php:131 any checkout/callback → OrderController::checkoutCallback :169
   - paymentId = query paymentId
   - transaction = Transaction::where(gateway_transaction_id=paymentId)->orWhere(invoice_id=paymentId)->first()
   - gatewayName = transaction.payment_method ?? 'myfatoorah'
   - gateway = factory.make(gatewayName)
   - result = gateway.verifyPayment(paymentId)  // MyFatoorahGateway: GetPaymentStatus, checks Data.InvoiceStatus==='Paid'

10. Verification  MyFatoorahGateway.php:80 verifyPayment
    - Key=paymentId, KeyType=PaymentId → GetPaymentStatus
    - success = (InvoiceStatus === 'Paid')  // evidence via docs: docs/front/checkout/payment-audit.md:188

11. Transaction/order update  OrderController.php:280-420
    - amount/currency mismatch detection (ignored on test gateway apitest.myfatoorah, blocks on live)
    - DB::transaction lockForUpdate Transaction+Order
    - idempotency: if order.status != 'pending' → return (no update)
    - Transaction.status='paid', gateway_response merged, paid_at=now()
    - Order payment_status=SUCCESS, paid_at=now()
    - orderReservationService->commit(lockedOrder)
    - orderService->finalizePromotionUsageAfterPayment
    - orderService->changeOrderStatus(invoice_id,'completed', emitPaymentSuccess=false)
    - after commit: event PaymentSucceeded  (listeners: SendPaymentSucceededNotification, GenerateInvoice, etc. via queue meem-high)
    - redirect (web) or JSON (mobile) to /{locale}/payment/success

12. Stock handling
    - At checkout: validateAndLockStock + inventory reservation (not yet decremented)
    - At callback success: OrderReservationService::commit  (physically deducts / commits stock)
    - At failure/error-callback: remains 'reserved' until expiry → CancelUnpaidOrders command releases

13. Cart handling
    - cart remains until order completion; on PaymentSucceeded, cart is cleared by downstream listener/service (not in this file, inferred from docs/order-state-machine)

14. Final response
    - checkout: 200 {url}
    - callback success: 302 to frontend /{locale}/payment/success?status=success&payment_id=&order_id=  (or JSON for mobile)
    - callback failure: Transaction status failed/reversed, PaymentFailed event, redirect to /payment/failed

```

### 4.2 Legacy Marvel flow (Stripe/PayPal — currently dormant)

```
1. Frontend (legacy / admin) POST /api/v1/orders  packages/marvel/src/Rest/Routes.php (via RestApiServiceProvider prefix api/v1)
   → OrderController::store()  packages/marvel/src/Http/Controllers/OrderController.php:244
     → DB::transaction → OrderRepository::storeOrder(request, settings)  :112
       → creates Order + OrderProducts, sets payment_gateway from request.payment_gateway
       → (does NOT create PaymentIntent yet)

2. Payment intent creation — two paths:
   a) GET /api/v1/orders/{id}  fetchSingleOrder() :300  if gateway not COD/CASH → attachPaymentIntent(orderParam)
      → PaymentTrait::attachPaymentIntent → PaymentIntent where tracking_number=...
   b) POST /api/v1/orders/payment  submitPayment() :499  switch(PaymentGatewayType)
      case STRIPE:  → PaymentStatusManagerWithOrderTrait::stripe(order, request, settings) :26
      case PAYPAL:  → ::paypal(...) :79

3. Gateway resolution  ShopServiceProvider.php:253
   → new Payment(app->make('Marvel\Payments\Stripe' or 'Paypal'))

4a. Stripe  Stripe.php:97 getIntent([amount, order_tracking_number, customer?])
     → stripe->paymentIntents->create([amount*100, currency=Settings.currency, description='Marvel Payment',
          automatic_payment_methods.enabled=true, metadata[order_tracking_number], customer?])
     → returns {client_secret, payment_id, is_redirect:false}
     → saved as PaymentIntent::create([order_id, tracking_number, payment_gateway=>'Stripe', payment_intent_info=>{...}])

   Frontend confirms via Stripe.js: stripe.confirmPayment({client_secret}) → 3DS if needed

4b. PayPal  Paypal.php:78 getIntent([amount, order_tracking_number, currency])
     → paypalClient->createOrder([intent=>CAPTURE, purchase_units=>[invoice_id=>tracking_number, amount=>[currency_code, value=>round(2)]],
          payment_source.paypal.experience_context=>[user_action=>PAY_NOW, payment_method_preference=>IMMEDIATE_PAYMENT_REQUIRED,
          cancel_url=>{SHOP_URL}/orders/{tracking}/payment, return_url=>{SHOP_URL}/orders/{tracking}/thank-you]])
     → returns {redirect_url=>links[1].href, payment_id=>id, is_redirect:true}
     → saved as PaymentIntent similarly
     → frontend redirects to redirect_url (PayPal approval)

5. Provider interaction  Stripe hosted 3DS / PayPal login+approve

6. Verification
   Stripe: PaymentStatusManagerWithOrderTrait::stripe() :48  retrievePaymentIntent(payment_id) → status switch succeeded→paymentSuccess, requires_action→processing, requires_payment_method→failed
           Also Stripe::verify() :234  charges->retrieve → paid boolean (used by legacy verify endpoints)
   PayPal: ::paypal() :95  Payment::verify(paymentId) → Paypal::verify() :178  paypalClient->capturePaymentOrder(id) → status;  "completed"→success, "payer_action_required"→processing

7. Webhook  (async, authoritative)
   Stripe: POST /api/v1/webhooks/stripe  (docs claim) → Stripe::handleWebHooks() :263
           constructs event from php://input + HTTP_STRIPE_SIGNATURE + shop.stripe.webhook_secret via Stripe\Webhook::constructEvent
           matchSucceededOrFailed() checks data.object.object=='charge', builds webhook_return_message (charge_status, payment_intent, amount, paid, payment_method_details.type, amount_captured, order_tracking_id=metadata.order_tracking_number)
           paymentGatewayWebHookResponse() → on succeeded→webhookSuccessResponse(order, PROCESSING, SUCCESS)
                             pending→save PENDING/AWAITING_FOR_APPROVAL + orderStatusManagementOnPayment
                             failed→save PENDING/FAILED

   PayPal: POST /api/v1/webhooks/paypal → Paypal::handleWebHooks() :160
           verifies via paypalClient->verifyWebHook([auth_algo, cert_url, transmission_id/sig/time, webhook_id, webhook_event])
           switch event_type PAYMENT.CAPTURE.COMPLETED→PROCESSING/SUCCESS, PENDING→PENDING/PENDING, CANCELLED→PENDING/FAILED, REVERSED→CANCELLED/REVERSAL
           → updatePaymentOrderStatus(trackingId=resource.invoice_id) → webhookSuccessResponse

8. Order update (both) via PaymentTrait::webhookSuccessResponse() :358
   if SUCCESS:
     DB::transaction lockForUpdate Order
     idempotency: if status in [completed,cancelled,refunded] → return
     update payment_status=SUCCESS, paid_at=now()
     commit inventory (OrderReservationService::commit) — idempotent via inventory_state
     finalize promotion (OrderService::finalizePromotionUsageAfterPayment)
     changeOrderStatus(null,'completed', emitPaymentSuccess=false) → triggers coupon redemption
     event PaymentSucceeded (after commit)
   else:
     simple update order_status/payment_status + children propagation + orderStatusManagementOnPayment

9. Stock/cart — same reservation/commit model as modern flow (shared OrderReservationService). Non-success webhook does NOT commit inventory.

```

**Critical divergence:** The modern `app` callback does **server-side verify + amount/currency check + lockForUpdate idempotency** before completing order. The legacy Marvel webhook does **signature verification** (Stripe) / **PayPal verification** but **no amount/currency cross-check** against Order.total_price in the webhook path (only in `PaymentStatusManagerWithOrderTrait` polling path indirectly). This is a gap.

---

## 5. Stripe — Complete Flow (Marvel legacy)

### A. Configuration

**File:** `packages/marvel/config/shop.php:68-71`

```php
'stripe' => [
    'api_secret'     => env('STRIPE_API_KEY'),
    'webhook_secret' => env('STRIPE_WEBHOOK_SECRET_KEY')
],
```

**Root `.env.example:99-101`**

```
STRIPE_API_KEY=
STRIPE_WEBHOOK_SECRET_KEY=
```

Plus a **dead/legacy duplicate key** at `shop.php:31` `'stripe_api_key' => env('STRIPE_API_KEY')` — same env, second config accessor, unused by `Stripe.php` (which reads `shop.stripe.api_secret`). `stripe_api_key` is **dead**.

`active_payment_gateway` and `DEFAULT_CURRENCY` also influence Stripe's currency via `Base.php:9` `Settings.options['currency']` rather than `shop.stripe.*`. Stripe does **not** read any other env vars; `PAYPAL_*` etc. are irrelevant.

**Required (code reads):**

| Var | Required? | Used | Config path |
|---|---|---|---|
| `STRIPE_API_KEY` | **YES** — `Stripe.php:33` `new StripeClient(config('shop.stripe.api_secret'))` throws AuthenticationException if missing | `shop.stripe.api_secret` | `shop.php:69` |
| `STRIPE_WEBHOOK_SECRET_KEY` | **YES for webhook**, optional for intent flow | `shop.stripe.webhook_secret` used only in `handleWebHooks:264` `constructEvent(...,$endpoint_secret)` | `shop.php:70` |

**Optional/dead:**

| Var | Status |
|---|---|
| `STRIPE_API_KEY` via `shop.stripe_api_key` | dead alias, not read by gateway |
| Any `STRIPE_*` besides the two above | `NOT FOUND` — no other Stripe env is read |

**DB vs env:** Currency comes from **DB** `settings.options['currency']` (`Base.php:9`), not `.env`. Webhook secret is `.env` only. `ACTIVE_PAYMENT_GATEWAY` in `.env.example:96` hints at legacy selection but modern `app` ignores it in favor of `config/payment.php:default_gateway`.

### B. Package

* **Package:** `stripe/stripe-php`
* **Version constraint:** `13.1.0` pinned (`packages/marvel/composer.json:28`)
* **Installed via:** `marvel/shop` path repo (`composer.json:58-62` `repositories.marvel/shop`), not direct root require. Transitive but guaranteed present if `packages/marvel` is installed.
* **Consuming class:** `Marvel\Payments\Stripe` `Stripe.php:8` `use Stripe\StripeClient`, `Stripe\Webhook`, `Stripe\Exception\*`
* **Alternative considered:** Do not recommend `laravel/cashier` — Stripe class already abstracts correctly; Cashier would be a migration, not a fix.

### C. Checkout (what the code actually does)

* **PaymentIntent?** **YES** — `Stripe::getIntent()` `:97` calls `stripe->paymentIntents->create([...])`. **No Checkout Session**, **no Charge creation** directly. Metadata tracks `order_tracking_number`.
* **Amount sent:** `round($amount,2) * 100` (`Stripe.php:105`) — **cents/minor-unit conversion done correctly**. `$amount` originates from `PaymentTrait::createPaymentIntent()` `:189` `amount = $order->paid_total - intval($order?->wallet?->amount)`. Note `paid_total` is the order's `total_price` (see `OrderRepository::storeOrder`); tax/shipping/discounts are already baked into `total_price` via repo logic, so **tax/shipping/discounts are included implicitly**, not recalculated per gateway. Server-side amount is used — frontend amount is **not** trusted at this stage (good).
* **Currency sent:** `$this->currency` from `Base.php:9` = `Settings.options['currency']`. Not per-order currency snapshot — uses global shop currency. In modern `app` layer, currency snapshot logic (`OrderCreationService`) does not affect legacy Stripe; legacy Stripe ignores `order.currency_code`.
* **Frontend vs backend handling:** Backend creates intent and returns `client_secret` + `payment_id` with `is_redirect=false`. **Frontend must confirm** via Stripe.js (`confirmPayment` / `handleCardPayment`). Backend does **not** confirm automatically. `confirmPaymentIntent()` `:199` exists for manual confirmation (used by saved-card flows via `attachPaymentMethodToCustomer`).
* **IDs generated:** Stripe generates `pi_...` (payment_intent) + `client_secret` (`intent->client_secret`). Stored in `payment_intents.payment_intent_info` JSON (`PaymentTrait::savePaymentIntent` `:161`).
* **Where stored:** `payment_intents` (`PaymentIntent` model `PaymentIntent.php:10`) with `order_id`, `tracking_number`, `payment_gateway='Stripe'`, `payment_intent_info` JSON.
* **Recalculation:** No fresh recalculation at `getIntent` time — amount comes from already-persisted `order.paid_total`. Good: no frontend manipulation.

### D. Success determination

**Authoritative source:** **Both webhook and polling** exist; webhook claims to be authoritative but polling is the fallback actually wired to user flow.

1. **Webhook (authoritative if configured):** `Stripe.php:263` `Webhook::constructEvent` verifies signature, then `paymentGatewayWebHookResponse()` with `charge_status==='succeeded'` → `webhookSuccessResponse(... SUCCESS)` which transactionally completes order (commit inventory, finalize promotion, emit `PaymentSucceeded`). Requires `STRIPE_WEBHOOK_SECRET_KEY` + dashboard webhook pointing to `POST /api/v1/webhooks/stripe`.

2. **Polling via `retrievePaymentIntent`:** `PaymentStatusManagerWithOrderTrait::stripe()` `:26` retrieves intent by `payment_id` and switches on `status`: `succeeded→paymentSuccess`, `requires_action→paymentProcessing`, `requires_payment_method→paymentFailed`. This is called from legacy `submitPayment` flows and is **synchronous**, not webhook-dependent.

3. **Legacy `verify()`:** `Stripe.php:234` `charges->retrieve($id)->paid` — checks charge `paid` boolean, **not** intent status. Used as a third verification path (less correct — charges vs intents mismatch).

No frontend callback alone is trusted; all three paths hit Stripe API. Webhook is the only path that atomically commits inventory/promotion with idempotency guard.

### E. Failure handling

Trace what the code does for each outcome:

| Stripe outcome | Marvel code | Order | PaymentIntent / Transaction | Stock | Cart |
|---|---|---|---|---|---|
| `requires_payment_method` / `paymentFailed()` `:368` | `OrderStatus=FAILED, PaymentStatus=FAILED`, `orderStatusManagementOnPayment` | saved as `order-failed` / `payment-failed` | `payment_intents` row remains (not deleted) | **NOT committed** — reservation remains `reserved`, will expire via `CancelUnpaidOrders` (if sharing reservation service) else manual |
| `requires_action` / `paymentProcessing()` `:340` | `PROCESSING` / `PROCESSING` | intent stays | not committed | remains reserved |
| `pending` webhook (`Stripe.php:285`) | `PENDING` / `AWAITING_FOR_APPROVAL` + `orderStatusManagementOnPayment` | pending await | not committed | reserved |
| `failed` webhook (`:298`) | `PENDING` / `FAILED` | failed | not committed | reserved |
| Cancelled/expired/declined (no explicit case) | falls through polling `requires_payment_method` → failed, or no webhook → stays pending until manual poll | pending/failed | as above | reserved |
| Retry | `PaymentTrait::processPaymentIntent()` `:47` — if gateway changed and intent not exists, creates new `PaymentIntent` and optionally deletes old (`deleteOlderPaymentIntent`) if `recall_gateway` flag set. Same order reuses; new intent row. No duplicate order. | new intent | old reservation still valid | cart already empty (order exists) |

`paymentAwaitingForApproval`, `paymentFailed`, etc. all call `orderStatusManagementOnPayment` which dispatches `OrderStatusChanged` / wallet handling via `OrderStatusManagerWithPaymentTrait`.

### F. Webhook

**Route:** `POST /api/v1/webhooks/stripe` claimed in `docs/api-contract.md:514` under `packages/marvel/src/Rest/Routes.php` with `api` middleware. **Verified existence:** `docs/api-contract.md:20` maps `api/v1/webhooks → packages/marvel/src/Rest/Routes.php`. The file is large (464 lines) and the webhook registration is not inline grep-visible as `Route::post('webhooks/stripe'` due to truncated read, but the documentation + the `Stripe::handleWebHooks` implementation confirm intent. Runtime verification via `php artisan route:list | grep webhooks` is **not performed** in this read-only audit (no command execution) — listed as **NOT VERIFIED** below.

**Controller/middleware:** No dedicated controller; `ShopServiceProvider` singleton + trait method `handleWebHooks` is invoked directly by the route closure/controller (pattern seen in other Marvel webhooks). No `auth` middleware — `api` only.

**Signature verification:** **YES** — `Stripe.php:264` `Webhook::constructEvent($payload, $sig_header, $endpoint_secret)` where `$payload=@file_get_contents('php://input')`, `$sig_header=$_SERVER['HTTP_STRIPE_SIGNATURE']`, `$endpoint_secret=config('shop.stripe.webhook_secret')`. On failure: `http_response_code(400); exit();` (raw exit, not Laravel response — note).

**Event types handled:** Not `event.type` but **`matchSucceededOrFailed()`** `:390` checks `data.object.object=='charge'` then `paymentGatewayWebHookResponse()` switches on **`charge_status`** (`succeeded|pending|failed`) only. So only charge events are processed; `payment_intent.succeeded` is **NOT** handled (gap — modern Stripe recommends `payment_intent` events).

**Idempotency:** `PaymentTrait::webhookSuccessResponse()` `:358` — for SUCCESS, `DB::transaction lockForUpdate`, early return if `status in [completed,cancelled,refunded]`, commit is idempotent via `OrderReservationService::commit` check on `inventory_state`. Non-success path checks `checkOrderStatusIsFinal()` before updating. So **idempotent via DB lock + state guard**.

**DB updates:** see success handling above. Order `order_status`, `payment_status`, `paid_at`, children propagation; inventory commit; promotion finalize; `changeOrderStatus('completed')`; event dispatch.

**If not found:** Would be `NO STRIPE WEBHOOK IMPLEMENTATION FOUND` — **but it IS found**, as above.

---

## 6. PayPal — Complete Flow

### A. Configuration

**File:** `packages/marvel/config/shop.php:76-97`

```php
'paypal' => [
  'mode'          => env('PAYPAL_MODE','sandbox'),
  'sandbox'       => ['client_id'=>env('PAYPAL_SANDBOX_CLIENT_ID'), 'client_secret'=>env('PAYPAL_SANDBOX_CLIENT_SECRET')],
  'live'          => ['client_id'=>env('PAYPAL_LIVE_CLIENT_ID'), 'client_secret'=>env('PAYPAL_LIVE_CLIENT_SECRET')],
  'payment_action'=> env('PAYPAL_PAYMENT_ACTION','Sale'),
  'webhook_id'    => env('PAYPAL_WEBHOOK_ID'),
  'currency'      => env('PAYPAL_CURRENCY','USD'),
  'notify_url'    => env('PAYPAL_NOTIFY_URL',''),
  'locale'        => env('PAYPAL_LOCALE','en_US'),
  'validate_ssl'  => env('PAYPAL_VALIDATE_SSL',true),
]
```

**Env vars (all from `.env.example:104-122`):**

| Var | Used? | Purpose |
|---|---|---|
| `PAYPAL_MODE` | YES — `config('shop.paypal.mode')` → `PayPalClient` selects sandbox vs live base URL | `sandbox`|`live` |
| `PAYPAL_SANDBOX_CLIENT_ID` | YES if mode=sandbox | client id |
| `PAYPAL_SANDBOX_CLIENT_SECRET` | YES if sandbox | secret |
| `PAYPAL_LIVE_CLIENT_ID` | YES if live | |
| `PAYPAL_LIVE_CLIENT_SECRET` | YES if live | |
| `PAYPAL_CURRENCY` | **YES but overridden** — `Paypal.php` actually uses `Base.currency` (`Settings.options['currency']`) not `config('shop.paypal.currency')`; the config key is set but **not read by gateway class** (gap) |
| `PAYPAL_WEBHOOK_ID` | YES — `Paypal.php:162` `config('shop.paypal.webhook_id')`, required for `verifyWebHook` |
| `PAYPAL_NOTIFY_URL` | set but **NOT read** by `Paypal.php` — dead |
| `PAYPAL_LOCALE` | set but **NOT read** by `Paypal.php` — dead |
| `PAYPAL_VALIDATE_SSL` | passed to `PayPalClient` via config, used by SDK |
| `PAYPAL_PAYMENT_ACTION` | passed to SDK, but `getIntent` hardcodes `intent=>CAPTURE` — action not used in createOrder |
| `PAYPAL_REDIRECT_URL` (`.env.example:119`) | **dead** — `Paypal.php` builds its own `cancel_url`/`return_url` from `config('shop.shop_url')` |
| `MOLLIE_*`, `STRIPE_*` etc. | irrelevant for PayPal |

**Required:** `PAYPAL_MODE` + the matching `CLIENT_ID`/`CLIENT_SECRET` pair + `PAYPAL_WEBHOOK_ID` (for webhook). `PAYPAL_CURRENCY` is effectively **not required** (ignored), but should be set for SDK completeness.

**Config file mapping:** `packages/marvel/config/shop.php:76` — **not** `config/payment.php` and **not** `config/services.php` and **not** `config/paypal.php` (no such file).

**DB vs env:** Currency from DB (`Settings.options['currency']` via `Base`), mode/creds/webhook_id from `.env`.

### B. Package

* **Package:** `srmklive/paypal`
* **Version constraint:** `3.0.19` (`packages/marvel/composer.json:30`)
* **Config class:** `PayPalClient = Srmklive\PayPal\Services\PayPal` (`Paypal.php:11`)
* **Marvel integration:** `Marvel\Payments\Paypal` implements `PaymentInterface`; instantiated via `ShopServiceProvider` singleton same as Stripe.

### C. Checkout

```
Customer selects PayPal (legacy request payment_gateway='PAYPAL')
 → Marvel OrderController::store → Order created (pending, payment_gateway='PAYPAL')
 → submitPayment or fetchSingleOrder triggers PaymentTrait::createPaymentIntent
   → Payment::getIntent([amount, order_tracking_number, currency=Base.currency]) → Paypal::getIntent
     → paypalClient->setRequestHeader('PayPal-Request-Id', Str::uuid())  // idempotency key
     → paypalClient->createOrder([
           intent=>CAPTURE,
           purchase_units=>[[invoice_id=>tracking_number, amount=>[currency_code, value=>round(amount,2)], description=>"Order From ".config('app.name')]],
           payment_source=>['paypal'=>['experience_context'=>[user_action=>PAY_NOW, payment_method_preference=>IMMEDIATE_PAYMENT_REQUIRED,
                          cancel_url=>"{SHOP_URL}/orders/{tracking}/payment", return_url=>"{SHOP_URL}/orders/{tracking}/thank-you"]]]])
     → returns {redirect_url: links[1].href, payment_id: id, is_redirect:true}
     → saved to payment_intents as order tracking maps to PayPal invoice_id

 → Frontend redirects user to redirect_url (PayPal approval page)
 → User approves → PayPal redirects to return_url = {SHOP_URL}/orders/{tracking}/thank-you  (SHOP_URL = config('shop.shop_url') = env('SHOP_URL'))
 → If user cancels → cancel_url = {SHOP_URL}/orders/{tracking}/payment

 → On return, legacy flow would call paypal() poll:
     PaymentStatusManagerWithOrderTrait::paypal() :79
       retrieve PaymentIntent.payment_id → Payment::verify(paymentId) → Paypal::verify() :178
       → paypalClient->capturePaymentOrder(id)
       → if status "completed" → paymentSuccess, "payer_action_required"→paymentProcessing
     Note: verify() does the CAPTURE server-side — the return alone does not mean paid; capture result does.

```

**PayPal API ops:** `createOrder` (CAPTURE intent), `capturePaymentOrder` on verify, `verifyWebHook` on webhook. No explicit `authorize` — only `CAPTURE`.

**Amount/currency:** `round(amount,2)` with no cents conversion (PayPal expects decimal string), `currency=>$this->currency` (DB). Same server-side order amount; tax/shipping/discounts baked into total.

### D. Success

Authoritative is **capture result**, not return URL.

* `paypal()` poll: `verify()` → `capturePaymentOrder` → `status` lowercased → `"completed"` → `paymentSuccess()` (`OrderStatus::PROCESSING, PaymentStatus::SUCCESS` + `orderStatusManagementOnPayment`).
* Webhook: `PAYMENT.CAPTURE.COMPLETED` → `webhookSuccessResponse(order, PROCESSING, SUCCESS)` → transactional completion (same as Stripe).

Returning from PayPal **does not** equal success; if capture fails, it stays processing/pending.

### E. Failure / cancellation

| Event | Code | Order/Payment | Stock/Cart |
|---|---|---|---|
| User cancels at PayPal | `cancel_url` visited; no capture → no webhook → order stays `pending`/`payment-pending` | `paypal()` not called, or if called, `capture` not completed → no status change | reserved |
| PayPal rejects / `capturePaymentOrder` throws | `verify()` throws `HttpException(400, SOMETHING_WENT_WRONG_WITH_PAYMENT)` → caller must handle | remains pending (or handled as failed if exception bubbles to status manager) | reserved |
| `PAYMENT.CAPTURE.CANCELLED` webhook | `OrderStatus::PENDING, PaymentStatus::FAILED` `:198` | failed | not committed |
| `PAYMENT.CAPTURE.PENDING` | `PENDING/PENDING` | pending | not committed |
| `PAYMENT.CAPTURE.REVERSED` | `CANCELLED/REVERSAL` | reversal — note `PaymentStatus::REVERSAL` exists | order cancelled, stock already committed earlier? reversal handling does NOT restore inventory explicitly — gap |
| `verify` returns `status='payer_action_required'` | `paymentProcessing` → `PROCESSING/PROCESSING` | awaiting | reserved |
| Capture fails / pending | stays pending until webhook or manual poll | pending | reserved |
| Callback repeated | `webhookSuccessResponse` idempotency guards prevent double-complete | idempotent | — |
| Other `verify` statuses (`declined`, etc.) | not explicitly handled — falls through with no status change (bug) | remains pending | reserved |

### F. Webhooks

**Route:** `POST /api/v1/webhooks/paypal` via `docs/api-contract.md:515`, `packages/marvel/src/Rest/Routes.php` (same caveat as Stripe — not individually grepped but documented and implemented).

**Controller/middleware:** `Paypal::handleWebHooks()` `:160` — `api` middleware only.

**Verification:** **YES** — builds `verifyData` from headers `PAYPAL-AUTH-ALGO`, `CERT-URL`, `TRANSMISSION-ID`, `TRANSMISSION-SIG`, `TRANSMISSION-TIME` + `webhook_id` + `webhook_event=request->all()` then `paypalClient->verifyWebHook(verifyData)` checks `verification_status==='SUCCESS'` else `400 exit`. On `SignatureVerificationError` → `400 exit`. Requires `PAYPAL_WEBHOOK_ID` to be set, otherwise immediately `400`.

**Event types handled:**

| PayPal event | OrderStatus | PaymentStatus |
|---|---|---|
| `PAYMENT.CAPTURE.COMPLETED` | `PROCESSING` | `SUCCESS` |
| `PAYMENT.CAPTURE.PENDING` | `PENDING` | `PENDING` |
| `PAYMENT.CAPTURE.CANCELLED` | `PENDING` | `FAILED` |
| `PAYMENT.CAPTURE.REVERSED` | `CANCELLED` | `REVERSAL` |

No `CHECKOUT.ORDER.*`, no `PAYMENT.CAPTURE.DENIED/REFUNDED`; those would be ignored.

**Idempotency:** Same `webhookSuccessResponse` path as Stripe.

**If missing:** Would be `NO PAYPAL WEBHOOK IMPLEMENTATION FOUND` — **but it exists**.

---

## 7. Order Lifecycle (state machine from actual code)

### 7.1 Order creation timing

* **Created BEFORE payment** in both universes.
* Marvel legacy: `OrderRepository::storeOrder()` `packages/marvel/src/Database/Repositories/OrderRepository.php:112` creates `Order` row inside `DB::transaction` *before* any intent. Payment intent is created *after* persistence, on demand via `fetchSingleOrder`/`submitPayment`.
* App modern: `OrderService::addItemsInOrder()` (evidence `OrderController.php:103`) creates `Order` with `status='pending'` *before* `PaymentCheckoutHandler::handleOnlinePayment`. `Transaction` is created after gateway `createInvoice`, but `Order` already exists.

### 7.2 States that actually exist

**`OrderStatus` enum** `packages/marvel/src/Enums/OrderStatus.php:12`

```
PENDING = 'order-pending'
PROCESSING = 'order-processing'
COMPLETED = 'order-completed'
CANCELLED = 'order-cancelled'
REFUNDED = 'order-refunded'
FAILED = 'order-failed'
AT_LOCAL_FACILITY, OUT_FOR_DELIVERY, READY_FOR_PICKUP (fulfillment)
```

**`PaymentStatus` enum** `packages/marvel/src/Enums/PaymentStatus.php:8`

```
PENDING = 'payment-pending'
PROCESSING = 'payment-processing'
SUCCESS = 'payment-success'
FAILED = 'payment-failed'
REVERSAL = 'payment-reversal'
REFUNDED = 'payment-refunded'
CASH_ON_DELIVERY, CASH, WALLET, AWAITING_FOR_APPROVAL
```

**`Order.status` column** (`Order.php:14` fillable `status`, plus legacy `order_status` accessor duality). The model has both `status` and legacy `order_status`/`payment_status` duality handled via `SyncOrderStatusColumn` etc.

**State diagram (proved transitions):**

```
          checkout success
Order created (status=pending, payment_status=pending)
   │
   ├─► Stripe poll: succeeded → order_status=PROCESSING, payment_status=SUCCESS → via webhookSuccessResponse → status=completed
   │                  requires_action → PROCESSING/PROCESSING (still pending webhook)
   │                  requires_payment_method → FAILED/FAILED
   │
   ├─► Stripe webhook: succeeded → transactional SUCCESS → status=completed
   │                   pending  → PENDING/AWAITING_FOR_APPROVAL
   │                   failed   → PENDING/FAILED
   │
   ├─► PayPal poll: completed → PROCESSING/SUCCESS
   │                payer_action_required → PROCESSING/PROCESSING
   │
   ├─► PayPal webhook: CAPTURE.COMPLETED → PROCESSING/SUCCESS → completed
   │                   CAPTURE.PENDING    → PENDING/PENDING
   │                   CAPTURE.CANCELLED  → PENDING/FAILED
   │                   CAPTURE.REVERSED   → CANCELLED/REVERSAL
   │
   ├─► MyFatoorah callback verify success → transactional completed (via OrderController::checkoutCallback) → status=completed
   │                         fail → PaymentFailed event, status stays pending, transaction failed
   │
   ├─► COD / pay_at_cashier → status stays pending, transaction pending, staff later markCodAsPaid/markCashierPaid → status=completed
   │
   └─► CancelUnpaidOrders (scheduled) or manual cancel → status=cancelled, payment_status=cancelled, stock released
```

Only `pending`, `processing`, `completed`, `cancelled`, `failed`, `refunded` are observed as `status` values in `PaymentTrait::webhookSuccessResponse` idempotency check (`in_array [completed,cancelled,refunded]`). `failed` is used by `paymentFailed()` but not in the idempotency guard (gap — `failed` order could be re-completed? Should be). `PaymentStatus` values ride alongside.

### 7.3 Answers to the 14 questions (evidence-backed)

1. **When is Order created?** Before payment. Both stacks.
2. **Before vs after payment?** Before.
3. **When does stock decrease?** Not on order creation. `OrderRepository::validateAndLockStock` `:268` and `deductStock` `:329` exist in legacy but modern path reserves (`OrderReservationService` → `inventory_state='reserved'`). **Commit on payment success** (`OrderController::checkoutCallback` `:359` `orderReservationService->commit`, `PaymentTrait::webhookSuccessResponse` `:393` `commit`). Failure/pending leaves reservation until expiry.
4. **When does cart get deleted?** After successful commit, via downstream `OrderService::changeOrderStatus` / cart clearing listener. Not in the webhook file itself; cart row is not deleted in `webhookSuccessResponse` — it is emptied by cart service on order completion.
5. **What happens after successful online payment?** Transaction `paid`, Order `status=completed`, `payment_status=SUCCESS`, `paid_at=now()`, inventory committed, promotion finalized, coupon consumed, `PaymentSucceeded` event, invoice generation, email, frontend cache webhook.
6. **After failed online payment?** Transaction `failed`, Order `payment_status=FAILED` (legacy) or Transaction `failed` + `PaymentFailed` event (modern). Order remains `pending` (modern) or `pending/FAILED` (legacy). Stock stays reserved. Coupon/promotion **not** consumed.
7. **Can user retry payment?** Yes — `PaymentTrait::processPaymentIntent` `:47` handles `recall_gateway` (change gateway). Modern flow: user can re-POST `checkout` (creates new Transaction for same Order? Actually `OrderService::addItemsInOrder` creates a new Order; there is no reuse — each checkout creates a new Order). Legacy: same Order can get a new `PaymentIntent` via `processPaymentIntent`.
8. **Retry creates new Order?** Modern: YES — each `POST checkout` creates a new Order (no order reuse; `findPendingOrderForUser` exists but `checkout` always calls `addItemsInOrder`). Legacy: NO — reuses same Order, creates new `PaymentIntent` row.
9. **Reuses same Order?** Legacy yes, modern no. Inconsistent.
10. **How is duplicate payment prevented?** Modern: `Transaction` has `uuid` unique + `gateway_transaction_id` + `DB::transaction lockForUpdate` on both Transaction and Order + `status!='pending'` guard. Legacy: `payment_intents` idempotency via `PayPal-Request-Id: Str::uuid()` per create, plus `webhookSuccessResponse` `lockForUpdate` + `in_array(..., completed/cancelled/refunded)` guard. Amount/currency mismatch also blocks modern path.
11. **Callback called twice?** Idempotent: both modern `checkoutCallback` and legacy `webhookSuccessResponse` early-return if Order already `completed/cancelled/refunded` and locks row. Duplicate `capture` / `confirm` would be deduplicated by gateway (Stripe idempotency, PayPal Request-Id) and by local DB guard.
12. **User closes browser after paying?** MyFatoorah: MyFatoorah still calls `callbackUrl` server→server (not browser). Legacy Stripe: user confirms via Stripe.js; if they close before confirm, intent stays `requires_action` → never completes; webhook may never fire for incomplete intent. Legacy PayPal: if user approves but closes before redirect/capture, webhook `CAPTURE.COMPLETED` still fires → order can complete without browser. Stripe webhook can also complete without browser if charge succeeds.
13. **Provider success but callback never reaches app?** Modern: order stays `pending`, transaction `pending`. Reconciliation via `PaymentReconciliationJob` (`app/Jobs/PaymentReconciliationJob.php:18`) can later `verifyWithGateway` and complete. Legacy: similar — `OrderStatusManagerWithOrderTrait::stripe/paypal` polling can catch, plus webhook retry by Stripe/PayPal. If none fire, manual mark-paid or cron `CancelUnpaidOrders` eventually cancels after timeout (`config/payment.php: order_timeout_hours`).
14. **Stock on that lost-callback case?** Remains `reserved` until `reservation_expires_at` then released by `CancelUnpaidOrders` or reconciliation commit (if later verified). No silent deduction.

---

## 8. Stripe vs PayPal Comparison

| Behavior | Stripe | PayPal |
|---|---|---|
| **Gateway class** | `Marvel\Payments\Stripe` `packages/marvel/src/Payment/Stripe.php:17` | `Marvel\Payments\Paypal` `packages/marvel/src/Payment/Paypal.php:16` |
| **Implements** | `Marvel\Payments\PaymentInterface` via `Base` | same |
| **SDK / package** | `stripe/stripe-php 13.1.0` (`packages/marvel/composer.json:28`) | `srmklive/paypal 3.0.19` (`:30`) |
| **Configuration** | `shop.stripe.api_secret` → `STRIPE_API_KEY`, `shop.stripe.webhook_secret` → `STRIPE_WEBHOOK_SECRET_KEY` (`shop.php:68`) | `shop.paypal` array → `PAYPAL_MODE`, `PAYPAL_SANDBOX_CLIENT_ID/SECRET`, `PAYPAL_LIVE_CLIENT_ID/SECRET`, `PAYPAL_WEBHOOK_ID`, `PAYPAL_CURRENCY` (ignored), `PAYPAL_NOTIFY_URL` (dead), `PAYPAL_LOCALE` (dead), `PAYPAL_VALIDATE_SSL` (`shop.php:76`) |
| **Currency source** | DB `Settings.options['currency']` via `Base` | same (`Base`) — `shop.paypal.currency` is dead |
| **Payment creation** | `PaymentIntent` via `paymentIntents->create` (`:127`) with `amount*100`, `automatic_payment_methods`, `metadata.order_tracking_number`, optional `customer` | PayPal **Order** via `PayPalClient->createOrder` `CAPTURE` (`:86`) with `invoice_id=tracking_number`, `amount.value=round(2)`, `experience_context.cancel/return_url` |
| **Redirect required** | **NO** `is_redirect=false` (`:137`) — frontend confirms with `client_secret` (Stripe.js). 3DS may redirect internally. | **YES** `is_redirect=true` (`:102`) — `links[1].href` PayPal approval URL |
| **Callback** | NOT a redirect — Stripe.js confirm + optional `return_url` (not set in this code; `cancel/return` not configured for Stripe). Success determined by intent status, not URL. | `cancel_url = {SHOP_URL}/orders/{tracking}/payment`, `return_url = {SHOP_URL}/orders/{tracking}/thank-you` (`:90`); both shop frontend URLs |
| **Webhook** | YES — `Stripe.php:263` `Webhook::constructEvent` with `HTTP_STRIPE_SIGNATURE`, event `charge` object, cases `succeeded/pending/failed` | YES — `Paypal.php:160` `verifyWebHook` with 5 PayPal headers + `webhook_id`, cases `PAYMENT.CAPTURE.*` |
| **Verification** | `verify($id)` → `charges->retrieve($id)->paid` boolean (`:239`); plus `retrievePaymentIntent` status check | `verify($id)` → `capturePaymentOrder($id)` (`:185`), status string |
| **Capture** | Automatic via PaymentIntent confirm; charges auto-captured (no separate capture call) | **Explicit** `capturePaymentOrder` on verify; webhook `CAPTURE.COMPLETED` after capture |
| **Success handling** | `paymentSuccess → OrderStatus::PROCESSING, PaymentStatus::SUCCESS → orderStatusManagementOnPayment` or `webhookSuccessResponse → transactional completed` | same pattern, plus webhook `COMPLETED` → `PROCESSING/SUCCESS` |
| **Failure handling** | `paymentFailed → FAILED/FAILED`, `paymentProcessing → PROCESSING/PROCESSING`; webhook `pending/failed` cases | `CANCELLED→PENDING/FAILED`, `PENDING→PENDING/PENDING`, `REVERSED→CANCELLED/REVERSAL`; unverified/declined not handled |
| **Retry** | `processPaymentIntent` with `recall_gateway` flag; new `PaymentIntent` for same Order | same mechanism (gateway-agnostic trait) |
| **Order state** | `pending → processing/completed or pending/failed or pending/awaiting` | `pending → processing/completed or pending/failed or pending/pending or cancelled/reversal` |
| **Stock behavior** | commit on SUCCESS webhook/poll, not on pending/failed | same; reversal does NOT restore committed stock (gap) |
| **Cart behavior** | cleared after `changeOrderStatus('completed')` | same |
| **Transaction ID** | `payment_id = pi_...` + `client_secret` (`:130`), stored in `payment_intents.payment_intent_info` | `payment_id = PayPal Order ID` (`order["id"]`), stored as `payment_intent_info.payment_id` + `redirect_url` |
| **Currency handling** | Uses global `Settings.currency`, not per-order snapshot | same (inherits bug) |
| **Amount handling** | `round(amount,2)*100` minor units, server `paid_total` minus wallet | `round(amount,2)` decimal, same source |
| **Idempotency** | Intent: none (but Stripe idempotency key not set; PayPal uses `PayPal-Request-Id: uuid`); Webhook: DB `lockForUpdate` + status guard | same; PayPal create has `PayPal-Request-Id: uuid` |
| **Saved-card support** | YES — `createCustomer`, `attach/detachPaymentMethodToCustomer`, `retrievePaymentMethod`, `setIntent` (setup_intent) | stubs (`createCustomer` returns `[]`, etc.) — **NOT IMPLEMENTED** |
| **Active in modern checkout** | **NOT FOUND** — `PaymentGatewayFactory` does not list `stripe` | **NOT FOUND** — same |

---

## 9. Frontend / API Contract

### 9.1 Current storefront (MyFatoorah) — what the frontend actually sends today

**POST `v1/general/checkout`** `routes/api.php:115` `auth:sanctum`

Request (validated by `OrderCreateRequest` → `OrderService::addItemsInOrder`):

```json
{
  "products": [{"product_id": 1, "order_quantity": 2, "unit_price": 50.0, "subtotal": 100.0}],
  "amount": 100.0,
  "sales_tax": 5.0,
  "delivery_fee": 10.0,
  "total": 115.0,
  "paid_total": 115.0,
  "payment_gateway": "CASH_ON_DELIVERY",       // legacy doc field — modern uses payment_method+gateway
  "payment_method": "online",                  // app layer: in: online|cod|pay_at_cashier, default online — OrderController.php:86
  "gateway": "myfatoorah",                     // app layer: string max 50, default config('payment.default_gateway') — OrderController.php:87
  "fulfillment_type": "delivery",              // in: delivery|pickup, default delivery
  "billing_address": {}, "shipping_address": {},
  "coupon_id": null, "shop_id": null
}
```

Actual required by `app` layer:

| Field | Source | Required | Value |
|---|---|---|---|
| `payment_method` | `OrderController.php:86` | optional, default `online` | `online`\|`cod`\|`pay_at_cashier` |
| `gateway` | `:87` | optional if `payment_method=online`, default `myfatoorah` | must be `myfatoorah` — else `UnsupportedGatewayException` → 422 |
| `fulfillment_type` | `:88` | optional, default `delivery` | `delivery`\|`pickup` (if `pay_at_cashier` only `pickup` allowed) |
| `products` / `amount` / `total` etc. | `OrderCreateRequest` + `OrderRepository::storeOrder` | required (legacy doc) | amounts are **recomputed server-side** from cart + promotions + coupons; frontend amounts are validated but authoritative total is `order.total_price` |

Success response (online):

```json
{ "success": true, "message": "CHECKOUT_SUCCESSFUL", "data": { "url": "https://api.myfatoorah.com/.../PaymentUrl" } }
```

Failure: 422 `PAYMENT_CURRENCY_UNSUPPORTED` or `Unsupported gateway`, 500 `ERROR_CREATING_INVOICE`.

**Callback** (gateway → backend, not frontend):

* `ANY v1/general/checkout/callback?paymentId=...` and `ANY v1/general/checkout/error-callback?paymentId=...` `routes/api.php:131-132` — **public** (`api` middleware only), `paymentId` is MyFatoorah `InvoiceId`. Backend verifies via `GetPaymentStatus`.

* No Stripe `client_secret` or PayPal approval code is expected by this endpoint.

### 9.2 Legacy Stripe (if it were wired)

**POST `api/v1/orders`** `OrderController::store` `packages/marvel/src/Http/Controllers/OrderController.php:244`

```json
{
  "payment_gateway": "STRIPE",
  "amount": 100, "total": 115, "paid_total": 115,
  "products": [...], "billing_address": {}, "shipping_address": {}
}
```

Then either:

* `GET /api/v1/orders/{tracking_number}` → response includes `payment_intent: {payment_intent_info: {client_secret, payment_id}}` plus order. Frontend uses `client_secret` with Stripe.js:

```js
stripe.confirmCardPayment(client_secret, {payment_method: {card, billing_details}})
```

* Or `POST /api/v1/orders/payment` → `submitPayment` with `{tracking_number, payment_gateway: 'STRIPE'}` triggers `PaymentTrait::processPaymentIntent` → creates `PaymentIntent` if not exists, returns intent.

Success: intent `status=succeeded` polled or webhook `charge_status=succeeded`. Frontend must poll or rely on webhook to know order completed. No redirect URL.

Payload actually needed to **verify**: `tracking_number` + `payment_gateway` in request body; verification reads `PaymentIntent.payment_id` + calls `stripe->paymentIntents->retrieve`.

### 9.3 Legacy PayPal (if it were wired)

Same `POST /api/v1/orders` with `"payment_gateway": "PAYPAL"`.

Then `Payment::getIntent` returns:

```json
{ "redirect_url": "https://www.paypal.com/checkoutnow?token=...", "payment_id": "8RU...", "is_redirect": true }
```

Frontend **must redirect** to `redirect_url`. User approves, PayPal redirects to:

* `cancel_url = {SHOP_URL}/orders/{tracking}/payment`
* `return_url = {SHOP_URL}/orders/{tracking}/thank-you` (`Paypal.php:90` `config('shop.shop_url')`)

On return, legacy frontend would call verification (poll) — `submitPayment` or polling via `paypal()` trait — which does `capturePaymentOrder`. No separate `approval` endpoint; capture is server-side.

**Crucial:** returning to `return_url` ≠ paid. Only `capture` `status==='completed'` means paid.

### 9.4 Stored identifiers

| Provider | ID field | Example value | Where stored |
|---|---|---|---|
| MyFatoorah | `invoice_id` / `gateway_transaction_id` (int) | `1234567` | `transactions.invoice_id`, `gateway_transaction_id` (`Transaction.php:12`) |
| Stripe | `payment_id` (`pi_...`) + `client_secret` (`pi_..._secret_...`) | `pi_3Qr...` | `payment_intents.payment_intent_info` JSON |
| PayPal | `payment_id` (PayPal Order ID) | `8RU...` | `payment_intents.payment_intent_info.payment_id`, `redirect_url` |

---

## 10. Environment / Production Configuration (derived from actual routes/config)

### 10.1 Stripe — Required (code-read)

- `STRIPE_API_KEY` → `config('shop.stripe.api_secret')` → `StripeClient` constructor — **missing → every Stripe call throws AuthenticationException**.
- `STRIPE_WEBHOOK_SECRET_KEY` → `config('shop.stripe.webhook_secret')` — required **iff** webhooks used; without it `Webhook::constructEvent` will fail signature verification (endpoint_secret null → invalid).
- `SHOP_URL` → `config('shop.shop_url')` (`shop.php:15` `env('SHOP_URL')`) — used by PayPal `cancel/return_url`, not Stripe directly but frontend return handling may need it.
- DB `settings.options['currency']` — must be a valid Stripe currency (e.g., `USD`, `KWD`). `Base.php:9` reads it; if null/missing, Stripe intent creation sends `currency=null` → `InvalidRequestException`.
- `DEFAULT_CURRENCY` (`.env.example:97` `env('DEFAULT_CURRENCY','USD')`) — **not** read by Stripe directly but sets default for `settings` seeding.

### 10.2 Stripe — Optional (code supports but not required)

- No other `STRIPE_*` env is read. `ACTIVE_PAYMENT_GATEWAY` (`.env:96`) influences legacy `Settings` fallback selection but not Stripe client.
- `payment_action`, `notify_url`, etc. are PayPal-only.

### 10.3 PayPal — Required (code-read)

- `PAYPAL_MODE` → `config('shop.paypal.mode')` → must be `sandbox` or `live` (`shop.php:80`). Empty/invalid → PayPal SDK defaults to `live` (comment).
- `PAYPAL_SANDBOX_CLIENT_ID` + `PAYPAL_SANDBOX_CLIENT_SECRET` **if mode=sandbox**.
- `PAYPAL_LIVE_CLIENT_ID` + `PAYPAL_LIVE_CLIENT_SECRET` **if mode=live**.
- `PAYPAL_WEBHOOK_ID` → `config('shop.paypal.webhook_id')` — required for webhook verification; missing → every webhook returns 400.
- `SHOP_URL` → `config('shop.shop_url')` — builds `cancel_url`/`return_url`; if empty, PayPal order creation sends `cancel_url="/orders/.../payment"` (relative, likely rejected by PayPal API).
- DB `settings.options['currency']` (same as Stripe).

### 10.4 PayPal — Optional / dead

- `PAYPAL_CURRENCY` (`shop.php:91`) — set but **not read by `Paypal` class** (uses `Base.currency` instead) — optional/dead.
- `PAYPAL_NOTIFY_URL`, `PAYPAL_LOCALE`, `PAYPAL_VALIDATE_SSL`, `PAYPAL_PAYMENT_ACTION`, `PAYPAL_REDIRECT_URL` — either passed to SDK but not used in `createOrder`, or dead.
- `PAYPAL_WEBHOOK_ID` is optional if you never use webhooks (poll-only).

### 10.5 Host / URL / infra requirements (derived from routes)

**Localhost**

* `APP_URL=http://localhost` (`/.env.example:4`)
* `SHOP_URL` must be set to frontend origin (e.g., `http://localhost:3000`) — otherwise PayPal `cancel/return_url` are broken.
* `config('app.app_url_frontend')` used in `OrderController::checkoutCallback` `:362` for redirect `/{locale}/payment/success|failed` — must be frontend URL.
* `MOLLIE_WEBHOOK_URL` etc. not needed for Stripe/PayPal.
* For Stripe CLI local testing: `stripe listen --forward-to localhost:8000/api/v1/webhooks/stripe` (route prefix `api/v1` per `RestApiServiceProvider`). Current code reads raw `php://input` + `$_SERVER['HTTP_STRIPE_SIGNATURE']` — works behind Stripe CLI.
* PayPal local: ngrok or equivalent for `SHOP_URL` to be publicly reachable (PayPal requires HTTPS public `return_url` for live; sandbox allows http but discouraged). Webhook URL must be `https://{ngrok}/api/v1/webhooks/paypal` (if Marvel webhook route is used).
* HTTPS: not enforced locally, but Stripe webhooks in `shop.php` expect real signature; PayPal `validate_ssl` should be `false` locally? default `true`.
* Frontend URLs: backend `config('shop.shop_url')` and `config('app.app_url_frontend')` must match actual frontend dev server; mismatch → redirect loops / 404 after PayPal approval.
* Queue: not required for legacy webhook path synchronously, but `PaymentSucceeded` listeners are queued (`SendPaymentSucceededNotification` etc. on `meem-high`/`catch-high`). Without queue worker, invoice/email not sent but order still completes.
* Cache/config: after changing `.env`, run `php artisan config:clear` / `optimize:clear` — especially `Settings::first()` cached via `Cache`.

**Production**

* `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://{backend domain}`
* `SHOP_URL=https://{shop frontend domain}` (e.g., `https://shop.example.com`) — must be HTTPS; PayPal requires HTTPS `return_url` in live mode.
* `STRIPE_API_KEY` = **live** key (`sk_live_...`), `STRIPE_WEBHOOK_SECRET_KEY` = live webhook secret (`whsec_...`) matching dashboard webhook.
* `PAYPAL_MODE=live`, `PAYPAL_LIVE_CLIENT_ID/SECRET` live creds, `PAYPAL_WEBHOOK_ID` live webhook id.
* Webhook URLs: `https://{backend}/api/v1/webhooks/stripe` and `.../paypal` must be HTTPS, publicly reachable, not behind auth.
* `APP_URL_FRONTEND` (config `app.app_url_frontend`) must be HTTPS frontend.
* Queue worker: **required** — at least `queue:work --queue=meem-high,meem-medium` / `catch-high` depending on env `QUEUE_HIGH`. Without it, post-payment side effects (invoice PDF, emails, frontend cache invalidation) never run.
* `MAIL_*`, `PUSHER_*` etc. not directly payment-gating but affect notifications.

**Derived callback/webhook URLs (do not invent — from route definitions):**

| Purpose | Method | Path | File | Full URL pattern |
|---|---|---|---|---|
| Modern online callback (MyFatoorah) | `ANY` | `v1/general/checkout/callback?paymentId=` | `routes/api.php:131` | `{APP_URL}/api/v1/general/checkout/callback` (+ `error-callback`) |
| Legacy webhook — Stripe | `POST` | `v1/webhooks/stripe` (claimed) | `packages/marvel/src/Rest/Routes.php` via `RestApiServiceProvider` prefix `api/v1` | `{APP_URL}/api/v1/webhooks/stripe` |
| Legacy webhook — PayPal | `POST` | `v1/webhooks/paypal` (claimed) | same | `{APP_URL}/api/v1/webhooks/paypal` |
| PayPal order cancel | `GET` (redirect) | `orders/{tracking}/payment` on frontend (`SHOP_URL`) | `Paypal.php:90` | `{SHOP_URL}/orders/{tracking_number}/payment` |
| PayPal order return | `GET` (redirect) | `orders/{tracking}/thank-you` on frontend | same | `{SHOP_URL}/orders/{tracking_number}/thank-you` |
| MyFatoorah success redirect | `GET` | `/{locale}/payment/success?payment_id=&order_id=` on frontend | `OrderController.php:362` | `{APP_URL_FRONTEND}/{locale}/payment/success` |
| MyFatoorah failure redirect | `GET` | `/{locale}/payment/failed?payment_id=&message=` | same | `{APP_URL_FRONTEND}/{locale}/payment/failed` |

---

## 11. External Dashboard Configuration

### 11.1 Stripe Dashboard

* **API credentials:** In https://dashboard.stripe.com/apikeys — copy **Secret key** (`sk_test_...` for test, `sk_live_...` for live) → `.env STRIPE_API_KEY`. No publishable key needed server-side (frontend uses publishable key via `client_secret` flow — not in backend `.env`).

* **Test vs live mode:** Toggle at top-right of dashboard. Backend `STRIPE_API_KEY` must match mode; webhook secret must match mode. The code has **no test/live switch** (`PAYPAL_MODE` analogue does not exist for Stripe) — mode is implied by which key you gave.

* **Return / cancel URLs:** **NOT configured in dashboard** for PaymentIntent flow. `return_url` is set client-side via Stripe.js `confirmPayment` or via intent creation metadata (this code does not set `return_url` server-side). No dashboard step needed.

* **Webhook URLs:**

  1. Go to **Developers → Webhooks → Add endpoint**.
  2. URL: `https://{backend}/api/v1/webhooks/stripe` (local: via Stripe CLI `stripe listen --forward-to localhost:8000/api/v1/webhooks/stripe`).
  3. Copy **Signing secret** `whsec_...` → `.env STRIPE_WEBHOOK_SECRET_KEY`.
  4. Events to send: at minimum **`charge.succeeded`**, **`charge.failed`**, **`charge.pending`** — because `matchSucceededOrFailed` only inspects `charge` object and `paymentGatewayWebHookResponse` switches on `charge_status` (`succeeded|pending|failed`). Optionally `payment_intent.*` events are **ignored** by this code, so do not rely on them. Include: `charge.succeeded`, `charge.failed`, `charge.pending` (or `charge.*` for coverage).

* **Allowed domains / origins:** Stripe Dashboard → **Settings → Allowed domains** only for Checkout Sessions; not needed for PaymentIntent via API. No CORS needed (API is server-to-server).

### 11.2 PayPal Developer Dashboard

* **API credentials:** https://developer.paypal.com/dashboard/applications — create App (Business sandbox + Live). Copy **Client ID** and **Secret** → `.env` `PAYPAL_SANDBOX_CLIENT_ID/SECRET` (when `PAYPAL_MODE=sandbox`) or `PAYPAL_LIVE_CLIENT_ID/SECRET` (when `live`). **Never** mix.

* **Mode switch:** `.env PAYPAL_MODE=sandbox` (dev) vs `live` (prod). SDK `srmklive/paypal` picks base URL accordingly. Dashboard must have matching app for each mode.

* **Return / cancel URLs:** **NOT set in dashboard** for this Order API — they are per-order `experience_context.return_url/cancel_url` sent by `Paypal.php:90` (`{SHOP_URL}/orders/{tracking}/...`). Dashboard **App → Return URL** is for legacy Express Checkout; not authoritative here but should still be set to `{SHOP_URL}` for completeness. No strict validation — PayPal will accept the per-order URLs as long as they are HTTPS (live) and match allowed origins.

* **Webhook URLs:**

  1. **Dashboard → My Apps → Webhooks → Add Webhook**.
  2. URL: `https://{backend}/api/v1/webhooks/paypal` (must be HTTPS, publicly reachable).
  3. Events to subscribe: `PAYMENT.CAPTURE.COMPLETED`, `PAYMENT.CAPTURE.PENDING`, `PAYMENT.CAPTURE.CANCELLED`, `PAYMENT.CAPTURE.REVERSED` — the four handled in `Paypal.php:199-215`.
  4. After creation, dashboard shows **Webhook ID** (e.g., `5AB...`) → `.env PAYPAL_WEBHOOK_ID`. The code verifies against this id via `verifyWebHook`.
  5. For sandbox local testing, use ngrok-forwarded URL or PayPal **Webhook Simulator**.

* **Base URL:** implicit — sandbox `https://api-m.sandbox.paypal.com`, live `https://api-m.paypal.com`. No env needed.

* **Sandbox vs Live:** `.env PAYPAL_MODE` must align with credential pair; dashboard webhook id must match mode. `PAYPAL_PAYMENT_ACTION` is `Sale` by default; this code ignores it for order creation but keep as `Sale`.

---

## 12. Local Testing Flow

### 12.1 Stripe (PaymentIntent — Marvel legacy)

**Because Stripe is not wired to modern checkout, you cannot test it via `POST v1/general/checkout` without code changes. Test via legacy route or by temporarily wiring `PaymentGatewayFactory`.**

**Steps if you want to verify the Marvel implementation directly:**

1. **Env setup**

   ```
   STRIPE_API_KEY=sk_test_...
   STRIPE_WEBHOOK_SECRET_KEY=whsec_...  # from stripe listen or dashboard test webhook
   SHOP_URL=http://localhost:3000
   APP_URL=http://localhost:8000
   DEFAULT_CURRENCY=USD     # or KWD — must match Stripe-supported currency
   ACTIVE_PAYMENT_GATEWAY=STRIPE  # influences Settings fallback
   ```

   Ensure `settings` table `options->currency` matches (`tinker: Settings::first()->options['currency']`). If seed has `KWD`, Stripe test for `KWD` may not be supported — use `USD` for Stripe testing or update `settings`.

2. **Install / clear**

   ```
   composer install   # ensures stripe/stripe-php 13.1.0 + srmklive/paypal present via marvel/shop
   php artisan config:clear
   php artisan cache:clear
   ```

   No queue worker required for intent creation, but required for post-success listeners if you go all the way to webhook.

3. **Stripe CLI (recommended for local webhook)**

   ```
   stripe login
   stripe listen --forward-to localhost:8000/api/v1/webhooks/stripe
   # copy whsec_... to STRIPE_WEBHOOK_SECRET_KEY, then config:clear
   ```

4. **Create order (legacy)**

   ```
   POST /api/v1/orders
   Authorization: Bearer {sanctum token}
   Body: { "payment_gateway": "STRIPE", "products": [...], "amount": 10, "total": 10, "paid_total": 10, ... }
   → returns order with tracking_number e.g., TRACK-123
   ```

5. **Fetch payment intent (legacy pattern)**

   ```
   GET /api/v1/orders/{tracking_number}
   Authorization: Bearer {token}
   → response includes payment_intent.payment_intent_info {client_secret, payment_id}
   ```

   Alternatively `POST /api/v1/orders/payment` with `tracking_number` + `payment_gateway=STRIPE` triggers `processPaymentIntent` → same result.

6. **Frontend Stripe.js confirm (test card)**

   Use test card `4242 4242 4242 4242`, exp `12/34`, cvc `123`, zip `12345`.

   ```js
   stripe.confirmCardPayment(client_secret, {payment_method: {card, billing_details: {name, email}}}})
   ```

   Possible statuses: `succeeded`, `requires_action` (test 3DS card `4000 0025 0000 3155`), `requires_payment_method` (declined `4000 0000 0000 9995`).

7. **Backend poll (simulate submitPayment verification)**

   `PaymentStatusManagerWithOrderTrait::stripe` would `retrievePaymentIntent(payment_id)` and switch; you can mimic via directly calling Stripe API or hitting whatever legacy verify endpoint exists. Expect:

   * `succeeded` → `order_status=order-processing`, `payment_status=payment-success` (then webhook will further to `completed`)
   * `requires_action` → `processing/processing`
   * `requires_payment_method` → `failed/payment-failed`

   Or wait for webhook:

   ```
   stripe trigger charge.succeeded  # or actually complete the PaymentIntent
   → POST /api/v1/webhooks/stripe with Stripe-Signature header
   ```

8. **Expected states**

   | Stage | Order `status` | `payment_status` | `payment_intent` / Transaction | Stock | Cart |
   |---|---|---|---|---|---|
   | After order create | `order-pending` | `payment-pending` | `payment_intents` row `Stripe` | reserved | still has items |
   | After `requires_action` poll | `order-processing` | `payment-processing` | same intent, status processing | reserved | same |
   | After `succeeded` webhook | `order-completed` (via `changeOrderStatus`) | `payment-success` | `paid_at` set, `Transaction`? legacy path has no `transactions` row — order status only; modern path would have Transaction `paid`. Legacy Stripe does NOT create modern `transactions` row. | **committed** (deducted) | cleared |
   | After `requires_payment_method` / declined | `order-failed` | `payment-failed` | intent stays | reserved → expires | still has items |
   | Amount/currency mismatch | legacy webhook does NOT check → would still succeed (gap) | succeeded | — | committed even if amount wrong — **risk** |

9. **Curl example (legacy intent fetch)**

   ```
   curl -s -H "Authorization: Bearer $TOKEN" http://localhost:8000/api/v1/orders/TRACK-123 | jq .payment_intent
   ```

   Expected: `{payment_gateway:"Stripe", payment_intent_info:{client_secret:"pi_..._secret_...", payment_id:"pi_..."}}`

### 12.2 PayPal (sandbox — legacy)

1. **Env**

   ```
   PAYPAL_MODE=sandbox
   PAYPAL_SANDBOX_CLIENT_ID=...
   PAYPAL_SANDBOX_CLIENT_SECRET=...
   PAYPAL_WEBHOOK_ID=...            # from sandbox webhook, or leave blank to skip webhook test
   PAYPAL_CURRENCY=USD              # present but ignored — set USD anyway
   SHOP_URL=http://localhost:3000   # or https ngrok for return_url to work
   PAYPAL_VALIDATE_SSL=true
   ```

   For cancel/return to work locally, `SHOP_URL` must be reachable by the browser (not necessarily by PayPal server for redirect — but webhook must be public). Use ngrok forward for `api/v1/webhooks/paypal` if testing webhook.

2. **Clear + Stripe CLI analogue**

   ```
   php artisan config:clear
   # optional: PayPal webhook simulator or ngrok
   ngrok http 8000
   # set SHOP_URL to ngrok https? but cancel/return are frontend URLs, webhook URL must match ngrok
   # In dashboard sandbox app, add webhook URL: https://{ngrok}/api/v1/webhooks/paypal with events CAPTURE.*
   ```

3. **Create order**

   ```
   POST /api/v1/orders  {payment_gateway:"PAYPAL", ...}  → tracking_number
   ```

4. **Get redirect**

   ```
   GET /api/v1/orders/{tracking}  → payment_intent includes {redirect_url, payment_id, is_redirect:true}
   # or POST /api/v1/orders/payment with tracking_number+payment_gateway
   ```

5. **Browser redirect**

   Open `redirect_url` → PayPal sandbox login (buyer sandbox account from developer.paypal.com). Approve → redirected to `return_url = {SHOP_URL}/orders/{tracking}/thank-you` → legacy frontend should then trigger capture.

6. **Server capture**

   Legacy `paypal()` trait → `verify(paymentId)` → `capturePaymentOrder` → status `completed`? If `completed`, order → `processing/success`; if `payer_action_required` → processing.

   Webhook path: after capture, PayPal fires `PAYMENT.CAPTURE.COMPLETED` → `POST /api/v1/webhooks/paypal` (verified via `verifyWebHook`) → same transactional completion.

7. **Expected**

   | Stage | Order status | Payment status | Stock | Cart |
   |---|---|---|---|---|
   | Created | pending | pending | reserved | not cleared |
   | After approve + capture `completed` | processing→completed | success | committed | cleared |
   | After cancel (`PAYMENT.CAPTURE.CANCELLED`) | pending | failed | reserved | not cleared |
   | After `PAYMENT.CAPTURE.PENDING` | pending | pending | reserved | not cleared |
   | User closes browser before capture | pending | pending | reserved until timeout | not cleared |

8. **Sandbox test accounts:** create Facilitator + Buyer accounts in developer.paypal.com. No real money.

### 12.3 MyFatoorah (for contrast — currently working path, useful to compare)

1. `MYFATOORAH_API_KEY` + `MYFATOORAH_BASE_URL=https://apitest.myfatoorah.com/v2/` (test) in `config/services.php` via `MYFATOORAH_API_KEY/BASE_URL`.
2. `POST v1/general/checkout` with `payment_method=online,gateway=myfatoorah` → returns `url`.
3. Redirect to `url`, pay with test card (MyFatoorah docs: `512345...` success `100` scenarios).
4. Callback `any v1/general/checkout/callback?paymentId=...` auto-verifies via `GetPaymentStatus`.
5. No webhook needed.

---

## 13. Production Configuration (how it changes)

| Aspect | Local / Test | Production / Live |
|---|---|---|
| **Stripe key** | `sk_test_...` | `sk_live_...` (`STRIPE_API_KEY`) — rotate immediately if test key leaks |
| **Stripe webhook secret** | `whsec_...` from `stripe listen` or dashboard test endpoint | `whsec_live_...` from dashboard live endpoint `https://{backend}/api/v1/webhooks/stripe` |
| **Stripe webhook URL** | `localhost:8000/api/v1/webhooks/stripe` via Stripe CLI | `https://{backend}/api/v1/webhooks/stripe` — HTTPS, no trailing slash, no auth |
| **Stripe currency** | may be `USD` for test convenience; ensure `settings.options['currency']` matches | must match real shop currency; if `KWD` + Stripe not supporting `KWD`, Stripe will 400 — choose gateway per currency or add fallback (current code does not) |
| **PayPal mode** | `PAYPAL_MODE=sandbox`, `PAYPAL_SANDBOX_*` | `PAYPAL_MODE=live`, `PAYPAL_LIVE_*` |
| **PayPal creds** | Sandbox app creds | Live app creds (different Client ID/Secret, submit for approval) |
| **PayPal webhook** | ngrok `https://{id}.ngrok.io/api/v1/webhooks/paypal` or simulator | `https://{backend}/api/v1/webhooks/paypal` — HTTPS required; ID goes to `PAYPAL_WEBHOOK_ID` live |
| **PayPal return URLs** | `http://localhost:3000/orders/.../thank-you` (sandbox allows http) | `https://{shop}/orders/.../thank-you` — live requires HTTPS |
| **SHOP_URL** | `http://localhost:3000` | `https://{shop}` |
| **APP_URL / app_url_frontend** | `http://localhost:8000` / `http://localhost:3000/{locale}` | `https://{backend}` / `https://{shop}/{locale}` |
| **HTTPS** | optional | **mandatory** for webhooks + PayPal live return + Stripe webhook signature (Stripe CLI aside) |
| **Frontend** | localhost Next.js | production Next.js on `SHOP_URL` / `app_url_frontend` |
| **Backend** | `php artisan serve` or Sail | Docker/Sail/production nginx + TLS cert |
| **Queue** | `QUEUE_CONNECTION=database`/`sync` often | `database` or `redis` with workers: `php artisan queue:work --queue=meem-high,meem-medium` (see `docs/audits/QUEUE_CONFIGURATION_REFACTOR_AUDIT.md`). At least 2 procs for `meem-high` (payment, invoice, notifications). Without workers PaymentSucceeded side-effects silently stall. Supervisor config in `deploy/supervisor/laravel-worker-catch-high.conf`. |
| **Cache/config** | `config:clear` after change | `config:cache` + `route:cache` + cache flush for `Settings` (`Cache::put('cached_settings_*', ...)` TTL 86400 in `Settings.php:60` — must clear cache after updating settings/currency) |
| **Workers** | not needed for manual poll | required — see above + `PaymentReconciliationJob` and `CancelUnpaidOrders` scheduled tasks |
| **Env file** | `.env` test keys, `SHOP_URL` localhost | `.env` live keys, rotated, never committed; webhook secrets never logged |

---

## 14. Existing Test Coverage

**Test files discovered:** No `tests/` directory listing returned `Access is denied` via `ctx_read`; filesystem scan indicates no runnable `tests/*Payment*` etc. in this path (container restriction). Search across indexed files (`ctx_search`) shows **no Stripe-specific or PayPal-specific feature/unit tests**:

* No `StripeTest`, `PaypalTest`, `PaymentTraitTest`, `CheckoutStripeTest`, `PaypalWebhookTest`.
* `docs/production-manual/PHASE-16-GAP-ANALYSIS.md:267` explicitly: *“~150 tests exist; MISSING: concurrency...”* and `api-desc/currency/CURRENCY_TEST_COVERAGE` lists `PaymentCurrencyTest` (currency sourcing for MyFatoorah, not Stripe/PayPal).
* Found tests that touch payment generically but **not** Stripe/PayPal:

| Test file (found via docs/search) | Verifies | NOT covered |
|---|---|---|
| `tests/Feature/OrderStatusLifecycleTest.php` (docs: `api-desc/front/order/test-cases.md:7`) — 15 tests 45 assertions | Order lifecycle `pending→completed→delivered` + invoice contract | Not Stripe/PayPal; MyFatoorah path only |
| `tests/Feature/Currency/PaymentCurrencyTest.php` (`api-desc/currency/test-cases.md:14`) | MyFatoorah invoice/refund currency, `compareCurrency` | Stripe/PayPal amount/currency handling |
| `tests/Feature/PaymentReconciliationTest.php:672` (mentioned in `api-desc/currency/bug-report.md:76`) | `PaymentReconciliationJob::compareCurrency` null guard | Stripe/PayPal reconciliation |
| `tests/Feature/PaymentCallbackStressTest` (9 fail, 401 on callback GET) (`api-desc/currency/test-cases.md:79`) | MyFatoorah callback idempotency, stress | Stripe/PayPal callbacks |
| `tests/Feature/WebhookPaymentCompletionTest.php:19` (found via symbol search) | **Webhook payment completion** — likely MyFatoorah + legacy? | Stripe/PayPal signature verification, duplicate webhook, amount mismatch |
| `tests/Unit/WebhookResponseTest.php:8`, `WebhookSignatureTest.php:8` | Frontend cache webhook HMAC, not payment webhooks | payment webhooks |

**Missing high-risk tests (none found, required before enabling Stripe/PayPal):**

* Successful Stripe PaymentIntent `succeeded` → order `completed`, stock committed, idempotency on second webhook.
* Failed Stripe `requires_payment_method` → order `failed`, stock NOT committed.
* Pending Stripe `requires_action` / `pending` webhook.
* Successful PayPal `CAPTURE.COMPLETED` → completed.
* Cancelled PayPal `CAPTURE.CANCELLED` / `REVERSED` handling.
* Duplicate callback (call `handleWebHooks` twice with same payload) → no double increment/stock double-deduct.
* Duplicate webhook (race: two concurrent webhook deliveries) → only one completion.
* Amount mismatch (order total 100 but Stripe charge 99) → should block/reject (currently not checked in Marvel webhooks — gap).
* Currency mismatch.
* Retry: recall_gateway creates new intent without double-order.
* Order already paid, new payment attempt → rejection.
* Callback after cancellation → no re-open.
* Provider success but app callback failure (DB error) → reconciliation recovers.

**Coverage verdict:** **Uncovered** for Stripe/PayPal critical paths. Any production enablement must add these before go-live.

---

## 15. Security Audit

| Finding | Severity | Location | Detail |
|---|---|---|---|
| **No amount/currency verification in Marvel webhooks** | **P0** | `Stripe.php:316` `paymentGatewayWebHookResponse`, `Paypal.php:245` `updatePaymentOrderStatus` → `webhookSuccessResponse` | `webhookSuccessResponse` does not compare `intent.amount` / `charge.amount_captured` vs `order.total_price` nor currency. An attacker or provider mis-issue could complete an order for underpayment. Modern MyFatoorah path correctly checks `result.amount` vs `order.total_price` and `currency` (`OrderController.php:330-370`). Marvel path **omits** this. |
| **Trusting frontend amount for Stripe/PayPal create** — `createPaymentIntent` uses `order.paid_total` (good), but `OrderRepository::storeOrder` accepts `paid_total`/`total` from request (`OrderRepository.php:112` — not re-computed per gateway). If client can set `paid_total` lower than real total and then call Stripe intent with that lower amount, Stripe charges less but order still marked success. Requires verify of amount server-side against recomputed total before intent. | **P0** | `OrderRepository.php:112` `storeOrder`, `PaymentTrait.php:189` | Mitigated partly by `OrderRepository` recalculates? Check shows it trusts request amounts (needs full read). **Assume P0 pending full audit of `storeOrder` amount validation.** |
| **Missing idempotency key on Stripe create** | **P1** | `Stripe.php:127` `paymentIntents->create` — no `idempotency_key` header | Stripe API may create duplicate intents on retry. PayPal correctly sets `PayPal-Request-Id: uuid` (`Paypal.php:86`). Stripe path should set `Idempotency-Key`. |
| **`http_response_code(400); exit();` pattern** | **P1** | `Stripe.php:277-282`, `Paypal.php:181-183,190` | Raw `exit()` bypasses Laravel middleware, logging, exception reporting, and prevents graceful JSON error. Use `abort(400)` / `response()->json`. Also `$_SERVER['HTTP_STRIPE_SIGNATURE']` may be undefined → PHP notice. |
| **PayPal `invoice_id` equals tracking_number without verification** | **P1** | `Paypal.php:89` `invoice_id: order_tracking_number`, `updatePaymentOrderStatus:250` `resource["invoice_id"]` | Any PayPal webhook with forged `invoice_id` that matches a valid tracking_number could update order status, if webhook signature is bypassed (e.g., missing `webhook_id`). Requires valid `webhook_id` + SDK verification, but missing `webhook_id` path returns 400 — okay. Still, `tracking_number` enumeration risk. |
| **Webhook secrets exposed in logs?** | **P0** | `Stripe.php`, `Paypal.php` — no logging found, but `config('shop.*')` could be logged if debug dumps settings | Verify no `Log::info(config('shop.stripe'))` exists; current grep shows none. Keep `STRIPE_API_KEY` never logged. |
| **`Settings.options['currency']` as single global** | **P1** | `Base.php:9` | Race: admin changes currency mid-checkout → Stripe/PayPal charged in different currency than order snapshot. Should lock order currency snapshot (modern `OrderCreationService` does; legacy does not). |
| **No rate limiting on webhooks** | **P2** | `Rest/Routes.php` `api` middleware only | Webhooks should be `throttle:webhook` + IP allowlist (Stripe/PayPal IP ranges). Currently any `api` caller (60/min globally) can hit webhooks. |
| **No replay/idempotency table for webhooks** | **P1** | `webhookSuccessResponse` guard is state-based, not event-id based | If Stripe sends two different events for same intent (e.g., `charge.succeeded` + `payment_intent.succeeded` both mapped to same order), second may be processed as if new if first left order `processing` not `completed`. Need webhook event-id dedup table. Modern `Transaction` has unique `uuid` but not webhook event id. |
| **Order ID / tracking_number manipulation via `fetchOrderByTrackingNumber` OR logic** | **P1** | `PaymentTrait.php:212` `where('id',"=",tracking_number)->orWhere('tracking_number',...)` | Attacker can enumerate order `id` integers or `tracking_number` strings. Authorization check only in `OrderController` wrappers, not in `PaymentTrait` trait itself. Direct call to trait methods without auth gate could mutate wrong order. |
| **Currency manipulation via request `payment_gateway` case sensitivity** | **P2** | `ShopServiceProvider.php:259` `ucfirst(strtolower(...))` | Normalizes, but an invalid `payment_gateway` falls back to `settings.defaultPaymentGateway` silently (`catch`). Could mask injection attempt — logs not emitted. |
| **PayPal `verification_status !== "SUCCESS"` uses `http_response_code(400); exit()`** | **P2** | `Paypal.php:178` | Same raw exit issue; also no logging of failed verification. |
| **Missing HTTPS assumption for PayPal return URLs** | **P1** | `Paypal.php:90` `config('shop.shop_url')` | If `SHOP_URL` is http in production, PayPal live may reject or downgrade; also enables MITM on redirect payload. Enforce https in prod config. |
| **User accessing another user's order/payment** | **P0** | `PaymentTrait`, `fetchOrderByTrackingNumber`, `attachPaymentIntent` — no ownership check | Legacy `fetchSingleOrder` `:300` does ownership check (`customer_id` vs user, super_admin, shop permission), but `processPaymentIntent` and `webhookSuccessResponse` do not. A user could poll/complete another user's order if they guess `tracking_number`. Modern `OrderController::checkoutCallback` checks `Transaction->user_id` only via lookup, not authorization; any `paymentId` validates. |
| **Secret exposure via `.env.example`** | **P3** | `.env.example` — keys present but blank | Okay (redacted), but ensure real `.env` not committed. |

**Overall risk:** Marvel webhook path is **P0-critical** for amount/currency mismatch and order-authorization gaps. Modern MyFatoorah path mitigated many of these (amount check, lock, idempotency); Marvel path did not port those fixes.

---

## 16. Marvel vs App Ownership

| Component | File | Owner | Used By | Notes |
|---|---|---|---|---|
| `Stripe` gateway | `packages/marvel/src/Payment/Stripe.php` | **Marvel** | `Marvel\Facades\Payment`, `PaymentTrait`, `PaymentStatusManagerWithOrderTrait`, `ShopServiceProvider` | Full Stripe PaymentIntent + webhook + customer/card |
| `Paypal` gateway | `packages/marvel/src/Payment/Paypal.php` | **Marvel** | same | PayPal Order + capture + webhook |
| All other gateways (Mollie, Razorpay, Paystack, Iyzico, Bkash, Paymongo, Flutterwave, Xendit, Sslcommerz) | `packages/marvel/src/Payment/*` | **Marvel** | same | 11 total |
| `PaymentInterface` | `packages/marvel/src/Payment/PaymentInterface.php:8` | **Marvel** | All gateways | Contract for Marvel universe |
| `Payment` (facade wrapper) | `packages/marvel/src/Payment/Payment.php:6` | **Marvel** | `ShopServiceProvider` singleton | Delegates to `PaymentInterface` impl |
| `Base` (currency from Settings) | `packages/marvel/src/Payment/Base.php:9` | **Marvel** | All gateways | |
| `PaymentTrait` (intent storage, webhookSuccessResponse transactional) | `packages/marvel/src/Traits/PaymentTrait.php:22` | **Marvel** | `OrderController`, `Stripe`, `Paypal`, others | Shared, contains transactional idempotency fix (recent) |
| `PaymentStatusManagerWithOrderTrait` | `packages/marvel/src/Traits/PaymentStatusManagerWithOrderTrait.php:13` | **Marvel** | `OrderController` | Polling verifiers for each gateway |
| `OrderStatusManagerWithPaymentTrait` | `packages/marvel/src/Traits/OrderStatusManagerWithPaymentTrait.php:19` | **Marvel** | Above | |
| `Order` model | `packages/marvel/src/Database/Models/Order.php` | **Marvel** (shared) | Both `app` and `marvel` | Single source — `app` imports it |
| `PaymentIntent` model | `packages/marvel/src/Database/Models/PaymentIntent.php` | **Marvel** | Marvel checkout only | **Not used** by `app` checkout |
| `PaymentMethod` / `PaymentGateway` models | `packages/marvel/src/Database/Models/PaymentMethod.php`, `PaymentGateway.php` | **Marvel** | Marvel saved-card flows | Not used by `app` |
| `Transaction` model | `packages/marvel/src/Database/Models/Transaction.php` | **Marvel** (model lives there) **but owned by `app` flow** | `app/Http/Controllers/Api/General/OrderController`, `PaymentCheckoutHandler` | Canonical transaction for modern checkout |
| `OrderRepository` | `packages/marvel/src/Database/Repositories/OrderRepository.php` | **Marvel** | `Marvel\Http\Controllers\OrderController` | Legacy order create |
| `OrderController` (legacy) | `packages/marvel/src/Http/Controllers/OrderController.php:76` | **Marvel** | `Rest/Routes.php` `api/v1` | `store`, `fetchSingleOrder`, `submitPayment` |
| `OrderController` (modern storefront) | `app/Http/Controllers/Api/General/OrderController.php` | **App** | `routes/api.php` `v1/general` | **CANONICAL** for current checkout |
| `PaymentGatewayFactory` | `app/Services/Payment/PaymentGatewayFactory.php:9` | **App** | `OrderController`, `PaymentCheckoutHandler`, `PaymentReconciliationJob`, `CancelUnpaidOrders` | Only `myfatoorah` arm |
| `PaymentGatewayContract` | `app/Services/Payment/Contracts/PaymentGatewayContract.php` | **App** | Factory, Gateways | |
| `MyFatoorahGateway` | `app/Services/Gateway/MyFatoorahGateway.php` | **App** | Factory | Sole online implementation |
| `PaymentCheckoutHandler` | `app/Services/Payment/PaymentCheckoutHandler.php:16` | **App** | `OrderController::checkout`, `FastShippingController` | |
| `MyfatoraService` (HTTP client) | `app/Services/MyfatoraService.php` (inferred) | **App** | `MyFatoorahGateway` | |
| `OrderService` / `OrderCreationService` | `app/Services/General/OrderService.php`, `app/Services/Checkout/OrderCreationService.php` | **App** | `OrderController` | Creates orders with currency snapshot |
| `config/payment.php` | `config/payment.php` | **App** | `OrderController`, `PaymentCheckoutHandler` | Only `myfatoorah` |
| `config/shop.php` | `packages/marvel/config/shop.php` | **Marvel** | `Stripe`, `Paypal`, all legacy gateways, `Base` | Contains all 11 gateways' env mappings |
| `config/services.php` | `config/services.php` | **App** | `MyFatoorahGateway` via `config('services.myfatoorah')` | |
| `config/laravel-omnipay.php` | `packages/marvel/config/laravel-omnipay.php` | **Marvel** | Omnipay driver (unused by current Stripe/Paypal — they use direct SDKs) | dead for Stripe/PayPal |
| `routes/api.php` | `routes/api.php` | **App** | Frontend checkout + callbacks | |
| `packages/marvel/src/Rest/Routes.php` | `packages/marvel/src/Rest/Routes.php` | **Marvel** | Admin CRUD + `webhooks/*` | Legacy webhook endpoints |
| `ShopServiceProvider` | `packages/marvel/src/ShopServiceProvider.php` | **Marvel** | Boot, config merge, `payment` singleton | Gateway selection via Settings |
| `composer.json` | `composer.json` (root) + `packages/marvel/composer.json` | Both | Dependency declaration | SDKs are in marvel composer |

**Marvel ownership ratio:** Payment domain is **~90% Marvel** by code volume (11 gateways, 3 traits, models, repository, controller, service provider, config). **App owns the single gateway that actually runs** (`MyFatoorahGateway`) plus the thin factory/handler that bypasses Marvel entirely.

**Why not move Marvel into `app`?** `docs/architecture` + `AGENTS.md §21.2` says Marvel is vendored kernel — do not move for style alone. Stripe/PayPal should stay in Marvel but be **bridged** via an App `PaymentGatewayContract` adapter rather than duplicated.

---

## 17. Findings by Severity

**P0 — Payment/security critical (must fix before enabling):**

* F-P0-1 Marvel webhooks do not verify `amount`/`currency` vs `order.total_price` — underpayment can complete order. `packages/marvel/src/Payment/Stripe.php:316` + `Paypal.php:245` + `PaymentTrait.php:358`.
* F-P0-2 Legacy `storeOrder` may trust frontend `paid_total` without server recompute — needs audit; `OrderRepository.php:112`.
* F-P0-3 Order ID/tracking_number enumeration + no ownership check in `PaymentTrait::fetchOrderByTrackingNumber` `:209` and webhook path can mutate arbitrary orders.
* F-P0-4 Secrets never to be logged — verify no dump of `shop.stripe`/`shop.paypal` in logs; raw `exit()` bypasses logging anyway — need proper audit.

**P1 — Production correctness:**

* F-P1-1 No Stripe `Idempotency-Key` on `paymentIntents->create` (`Stripe.php:127`).
* F-P1-2 No amount/currency guard for PayPal webhook `PAYMENT.CAPTURE.COMPLETED` — promotes even if PayPal amount ≠ order.
* F-P1-3 `reversal` (`Paypal.php:214`) does not restore inventory — committed stock stays deducted on reversed payment.
* F-P1-4 Global `Settings.options['currency']` race — Stripe/PayPal use global, not order snapshot.
* F-P1-5 Webhook has no event-id dedup table — `charge.succeeded` ×2 or `payment_intent.succeeded` + `charge.succeeded` double-fire risk (mitigated by `lockForUpdate` but not by event-id).
* F-P1-6 No `throttle`/`IP allowlist` on `/webhooks/*` (only `api` middleware).
* F-P1-7 Legacy `failed` order status not in idempotency guard (`PaymentTrait.php:374` checks only `completed,cancelled,refunded`) → failed order could be re-completed by late webhook.
* F-P1-8 `$_SERVER['HTTP_STRIPE_SIGNATURE']` direct access may be undefined → notice + 500 instead of 400.
* F-P1-9 `is_redirect` contract inconsistency — Stripe returns `false`, PayPal `true`; frontend must branch but no shared spec.

**P2 — Important improvement:**

* F-P2-1 `http_response_code(400); exit();` should be Laravel response (`abort`).
* F-P2-2 PayPal config `currency` key dead, `notify_url` dead — confusion.
* F-P2-3 `stripe_api_key` dead alias in `shop.php:31`.
* F-P2-4 `SHOP_URL` must be HTTPS in prod but no validation.
* F-P2-5 No queue throttling / supervisor tuning for webhook burst.

**P3 — Minor:**

* F-P3-1 Documentation references `GET /orders/checkout/verify` etc. do not match actual `submitPayment` method name — drift.
* F-P3-2 `laravel-omnipay` config present but unused for Stripe/PayPal (they use direct SDKs) — legacy.

---

## 18. Exact Required Actions (to make them work)

> **No action is “just set .env”. Wiring plus code changes are mandatory. Do not attempt live transactions until all P0 are addressed.**

### Code changes

1. **Create App-side gateway adapters — `app/Services/Gateway/StripeGateway.php` + `PaypalGateway.php` implementing `PaymentGatewayContract`** (`createInvoice`, `verifyPayment`, `refund`, `name`, `supportsCurrency`). Adapter delegates to `Marvel\Payments\Stripe` / `Paypal` or re-uses their `StripeClient`/`PayPalClient` logic, but conforms to `GatewayResult` DTO used by `OrderController::checkoutCallback`. Do NOT duplicate intent logic in two places — wrap Marvel's `getIntent/verify`.

2. **Register in `PaymentGatewayFactory.php:11`** — add `match` arms:
   ```php
   'stripe'  => app(StripeGateway::class),
   'paypal'  => app(PaypalGateway::class),
   'myfatoorah' => app(MyFatoorahGateway::class),
   ```

3. **Extend `config/payment.php`** — add `gateways.stripe` + `gateways.paypal` blocks (class, supported_currencies, etc.) and ensure `default_gateway` default still `myfatoorah` (no breaking change). Alternatively keep Stripe/PayPal config in `config/shop.php` and have the adapter read `shop.*` — either works but `config/payment.php` is where `PaymentCheckoutHandler` reads `supported_currencies`.

4. **Teach `PaymentCheckoutHandler::handleOnlinePayment`** to handle `is_redirect` vs `client_secret` response. Current handler expects `GatewayResult.redirectUrl` only. For Stripe, need to return `client_secret` + `payment_id` to frontend instead of redirect. Add branching:
   * if `gateway === 'stripe'` → return `{client_secret, payment_id}` (no redirect)
   * if `paypal` → return `{redirect_url, payment_id, is_redirect:true}` (as Marvel does)
   * if `myfatoorah` → existing `redirectUrl`

   Update frontend contract accordingly.

5. **Fix Marvel webhooks to verify amount/currency** — in `PaymentTrait::webhookSuccessResponse` or each gateway's `paymentGatewayWebHookResponse` / `updatePaymentOrderStatus`, add before `DB::transaction`:
   ```php
   if (abs($gatewayAmount - $order->total_price) > 0.01 || $gatewayCurrency !== $order->currency_code ?? $order->base_currency_code)
       { Log::warning(...); return response()->json(...,422); }
   ```

   Amount source: Stripe `intent.amount /100`, `charge.amount_captured /100`; PayPal `resource.amount.value`. Requires passing amount/currency from webhook payload into `webhookSuccessResponse`.

6. **Add authorization check** in webhook/poll paths or ensure `tracking_number` is unguessable (already UUID-like `ORD-...` but still guessable). Require `order.user_id` check or HMAC on webhook/ poll.

7. **Add Stripe `Idempotency-Key`** header on `paymentIntents->create` (use `order.tracking_number` or `Str::uuid()`).

8. **Add event-id dedup table** (`webhook_events` with `provider`, `event_id` unique) or guard by `payment_intent` idempotency in `payment_intents` JSON.

9. **Include `failed` in `webhookSuccessResponse` idempotency guard** (`in_array(... 'failed')`).

10. **Replace `http_response_code()+exit` with `abort(400, ...)` or `response()->json` + `return`** and guard `$_SERVER['HTTP_STRIPE_SIGNATURE']` via `$request->header('Stripe-Signature')`.

11. **Verify `Rest/Routes.php` actually exposes `POST /webhooks/stripe|paypal`** — if not, add route group:
    ```php
    Route::prefix('webhooks')->group(fn()=>{ Route::post('stripe', fn(Request $r)=>app(Stripe::class)->handleWebHooks($r)); ... });
    ```
    Ensure `api` middleware, no auth, `throttle:webhook`.

12. **Add PayPal `mode` validation** — throw 500 if `mode` invalid or creds missing, rather than defaulting to live.

13. **Write missing tests** (see §14 list) — gate on `PaymentCallbackStressTest`, `PaymentReconciliationTest`, and new `StripePaypalWebhookTest`.

### `.env` changes (names only, values redacted)

**For Stripe:**
```
STRIPE_API_KEY=                # sk_test_... (local) / sk_live_... (prod)
STRIPE_WEBHOOK_SECRET_KEY=     # whsec_... (stripe listen or dashboard)
SHOP_URL=                      # https://{frontend} — used for any return_url if you add one
APP_URL_FRONTEND=              # https://{frontend}/{locale} fallback
DEFAULT_CURRENCY=              # must match settings.options['currency'] and be Stripe-supported
```

**For PayPal:**
```
PAYPAL_MODE=sandbox            # or live in prod
PAYPAL_SANDBOX_CLIENT_ID=      # if sandbox
PAYPAL_SANDBOX_CLIENT_SECRET=
PAYPAL_LIVE_CLIENT_ID=         # if live
PAYPAL_LIVE_CLIENT_SECRET=
PAYPAL_WEBHOOK_ID=             # from dashboard webhook
PAYPAL_CURRENCY=USD            # currently ignored by Paypal.php — set anyway
SHOP_URL=                      # https://{frontend} — builds cancel/return_url
PAYPAL_VALIDATE_SSL=true       # false only locally if self-signed
```

**For both (shared):**
```
# No change needed to composer.json — SDKs already present via packages/marvel/composer.json
# But if you want App-side wiring, ensure config/payment.php default_gateway still myfatoorah to avoid breaking existing checkout
```

### Database / admin configuration

* In **admin** `settings` table (`options` JSON), ensure `options->currency` is correct and consistent with `DEFAULT_CURRENCY` env. Change via **Admin → Settings** or `tinker`:
  ```php
  $s = Settings::first(); $o=$s->options; $o['currency']='USD'; $s->options=$o; $s->save(); Cache::forget('cached_settings_en');
  ```
  Stripe does not support all currencies (e.g., `KWD` — verify dashboard). If shop must support `KWD`, disable Stripe for that currency via `supported_currencies` in `config/payment.php`.

* For legacy selection path, `settings.options['defaultPaymentGateway']` and `options['paymentGateway']` array must list `Stripe` / `Paypal` as available gateways if `ShopServiceProvider` is to resolve them. Check `Settings::first()->options['paymentGateway']`.

* No migration needed — `payment_intents`, `orders.payment_gateway`, `transactions` tables already exist.

### Stripe Dashboard configuration

* **API keys** → `.env` as above.
* **Webhook endpoint** → `POST https://{backend}/api/v1/webhooks/stripe` with events `charge.succeeded`, `charge.failed`, `charge.pending` (or `charge.*`). Copy signing secret → `STRIPE_WEBHOOK_SECRET_KEY`.
* For **Checkout Sessions** (not used here, but if you later migrate) add `checkout.session.completed`.

### PayPal Dashboard configuration

* Create **REST API app** in `developer.paypal.com` for both sandbox and live.
* Copy Client ID/Secret → `.env` per mode.
* **Webhook** → `POST https://{backend}/api/v1/webhooks/paypal` with events `PAYMENT.CAPTURE.COMPLETED`, `PENDING`, `CANCELLED`, `REVERSED`. Copy webhook ID → `PAYPAL_WEBHOOK_ID`.
* Ensure **Return URL** fallback set to `{SHOP_URL}` (not critical but good).
* For local, set webhook to `https://{ngrok}/api/v1/webhooks/paypal` and set `SHOP_URL` to ngrok-forwarded frontend or public frontend.

### Local testing

* Follow §12 steps for each provider after code changes. At minimum:
  1. `php artisan config:clear && cache:clear`
  2. Stripe: `stripe login && stripe listen --forward-to localhost:8000/api/v1/webhooks/stripe` → verify webhook hits.
  3. PayPal: ngrok or Webhook Simulator → verify `verifyWebHook` passes.
  4. `POST v1/general/checkout` with `payment_method=online,gateway=stripe|paypal` → assert response contains `client_secret` (stripe) or `redirect_url` (paypal).
  5. Complete provider payment with test credentials.
  6. Assert `Transaction`/`PaymentIntent` updated, `Order` → `completed`, stock committed, `PaymentSucceeded` event fired (check `orders` row, `transactions` row, `order_status_history`).

### Production deployment

1. Merge code changes (gateway adapters, factory, config, handler branching, webhook fixes) with **feature flag** per gateway (keep `myfatoorah` default).
2. Set `.env` live keys (Stripe live, PayPal live + live webhook id + HTTPS `SHOP_URL`).
3. Run `php artisan config:cache route:cache` and `Cache::forget` for settings cache.
4. Verify `GET /api/v1/webhooks/stripe` not GET — must be POST only (security).
5. Create live webhooks in both dashboards pointing to `https://{backend}/api/v1/webhooks/*` with correct secrets.
6. Ensure queue workers: `supervisorctl reread && update`, at least 2 procs for `meem-high`. Verify `queue:failed` monitoring.
7. Run `PaymentReconciliationJob` scheduled (e.g., `schedule->job(new PaymentReconciliationJob)->everyFiveMinutes()`).
8. Run `CancelUnpaidOrders` scheduled for reservation expiry.
9. Do live test transaction for small amount (e.g., $1) for each provider, verify `order completed`, `payment_success`, invoice generated, `paid_at` set.
10. Set up alerting on `PaymentFailed`, webhook 400s, amount mismatch warnings.

---

## 19. Blockers (what prevents correct operation TODAY)

* B-1 **Factory blocks Stripe/PayPal** — `PaymentGatewayFactory::make` throws `UnsupportedGatewayException` for any gateway ≠ `myfatoorah` (`app/Services/Payment/PaymentGatewayFactory.php:11`). **No code path can even create a Stripe/PayPal intent via modern checkout.**
* B-2 **`config/payment.php` has no stripe/paypal entry** — even if factory allowed them, `supportsCurrency` and handler currency check would have no config.
* B-3 **No App route for Stripe/PayPal intent** — `POST v1/general/checkout` always goes through `PaymentCheckoutHandler` which only knows `myfatoorah`. Legacy `POST api/v1/orders` + `submitPayment` are not invoked by modern frontend.
* B-4 **Webhook route unverified / may not be hit by provider** — legacy webhooks under `api/v1/webhooks/*` require `RestApiServiceProvider` prefix and correct `api` middleware; if provider forwards to `https://{backend}/api/v1/webhooks/stripe` but route is not registered (e.g., provider not booted due to cache), signature verification never happens.
* B-5 **Amount/currency mismatch not checked in Marvel webhooks** — even if wiring existed, underpayment could complete order (P0).
* B-6 **No idempotency dedup for Stripe create** — retry could create duplicate PaymentIntents.
* B-7 **Currency source mismatch** — `Base` reads global `Settings.currency` not per-order snapshot; if shop sells in `KWD` but Stripe/PayPal checkout expects `USD`, intent creation may send unsupported currency.
* B-8 **PayPal `SHOP_URL` may be empty** — `.env.example` shows `SHOP_URL=` blank; `Paypal.php:90` then builds `cancel_url="/orders/.../payment"` — PayPal API will reject empty host.

---

## 20. Risks (payment/order/security)

* **Financial loss:** Underpayment via amount mismatch (P0) + no refund path for Marvel gateways (no `refund` impl for Stripe/PayPal in `Marvel\Payments\*`; refund only exists for MyFatoorah `GatewayContract::refund`).
* **Stock inconsistency:** Inventory committed on webhook success without amount check; `REVERSED` webhook leaves stock deducted.
* **Order hijack:** Tracking-number enumeration + weak webhook auth if `webhook_id` blank (PayPal returns 400 early, but still probing).
* **Double completion:** Without event-id dedup, two concurrent webhooks could both pass `lockForUpdate` guard if one leaves status `processing` not `completed`.
* **Frontend mismatch:** Stripe expects `client_secret` flow but modern handler returns `url` — frontend would break until contract updated.
* **Support burden:** Two checkout flows (legacy vs modern) sharing same `Order` table but different state semantics (`pending` vs `completed` vs `processing`) will cause admin dashboard confusion.

---

## 21. Unknown / Not Verified (read-only limits)

* **Route existence for `POST /api/v1/webhooks/stripe|paypal`** — implementation class exists and docs claim route, but this audit did **not** execute `php artisan route:list` nor boot the app. **Could still be missing if `Rest/Routes.php` was edited or provider disabled.** Must verify via `route:list | grep webhooks`.
* **`OrderRepository::storeOrder` amount validation** — full method body not re-read with amount recomputation check; claim that it trusts `paid_total` is **inferred** from signature and legacy docs, not 100% proved without reading `OrderRepository.php:112-259` full source (truncated via ctx_read map). Needs line-by-line verification before declaring P0-2 confirmed.
* **`Rest/Routes.php` webhook registration method signature** — file truncated at 464 lines; webhook route block is near EOF (likely 400+). Could not fetch lines 350-464 due to read limits. Verify manually.
* **Frontend URL config key** — used as `config('app.app_url_frontend')` in `OrderController::checkoutCallback:362` but no `config/app.php` entry was found in search — may be env `APP_FRONTEND_URL` or `SHOP_URL`. Not verified.
* **Stripe `payment_intent` vs `charge` event type** — `Stripe.php:390` checks `data.object.object=='charge'`; modern Stripe best practice is `payment_intent.succeeded`. If Stripe dashboard only sends `payment_intent` events, webhook will always return `200` without update (silent failure). Dashboard event selection (§11.1) mitigates but not proved live.
* **PayPal SDK `validate_ssl` behavior on `PayPal-Request-Id` replay** — `srmklive/paypal 3.0.19` behavior for duplicate `Request-Id` not inspected.
* **Tests directory access** — `tests/` read denied (OS error 5) prevented direct `ls tests/Feature` enumeration; coverage claims are via docs/search not direct file listing.
* **`.env` live values** — only `.env.example` inspected; real `.env` not read (and must not be — secrets redacted). Operator must confirm live file has no blank keys.
* **`settings` table actual values** — `Settings::first()->options['currency']` etc. not queried (no DB). Must confirm via tinker before Stripe/PayPal enable.
* **Queue/scheduler registration** — `app/Console/Kernel.php` schedule for `PaymentReconciliationJob` / `CancelUnpaidOrders` not re-verified with exact cron expression.
* **TLS / ngrok viability for PayPal sandbox** — `PAYPAL_VALIDATE_SSL` true may fail for self-signed local; not tested.

---

## 22. Final Verdict

### CURRENT STATUS

* **Stripe:** `PARTIALLY WORKING` — legacy Marvel class is complete and SDK installed, but **DISCONNECTED** from the canonical checkout the app actually uses. Code exists, configured-via-env possible, tested **NO**, production verified **NO**. Would work only if legacy `POST /api/v1/orders` flow is used, or after App bridge (factory + gateway adapter + handler + config + webhook route) is added.

* **PayPal:** `PARTIALLY WORKING` — same status. Includes additional `SHOP_URL` dependency for redirect URLs that is often blank locally. Capture/verify + webhook both coded, SDK installed, but same disconnect from modern checkout. Poll stubs for `retrievePaymentIntent`/`confirmPaymentIntent` are empty, but `createOrder`/`capturePaymentOrder` + webhook cover the happy path.

Neither is `READY` or `READY WITH CONFIGURATION` (config alone is insufficient). Neither is `BROKEN` (code is internally correct apart from P0 gaps). Neither is `NOT IMPLEMENTED` (implementation is substantial). Correct label is **`PARTIALLY WORKING (legacy, requires wiring)`**.

### EXACT REASON

* Root cause is **architectural drift**: the product migrated storefront checkout from Marvel (`api/v1/orders`) to App (`v1/general/checkout` with `MyFatoorahGateway`) but left Marvel payment gateways unwrapped. `PaymentGatewayFactory` whitelist + `config/payment.php` whitelist + `PaymentCheckoutHandler` redirect-only contract jointly block Stripe/PayPal without code changes. Webhooks/auth/amount gaps add further P0 blockers before safe enable.

### WHAT I NEED TO DO — NUMBERED CHECKLIST

**Code (mandatory):** §18.1–13 — adapter gateways, factory arms, `config/payment.php` entries, handler branching for `client_secret` vs redirect, webhook amount/currency check + auth + event-id dedup + throttle, idempotency key, `failed` guard, SDK provider fix.

**`.env`:** set `STRIPE_API_KEY`, `STRIPE_WEBHOOK_SECRET_KEY` and/or `PAYPAL_MODE`, `PAYPAL_*_CLIENT_ID/SECRET`, `PAYPAL_WEBHOOK_ID`, `SHOP_URL`, `APP_URL_FRONTEND` (values redacted — names only).

**Database/admin:** ensure `settings.options['currency']` matches supported currency; add gateway entries to `options['paymentGateway']` if legacy flow used; clear `cached_settings_*`.

**Stripe Dashboard:** api key, live webhook `https://{backend}/api/v1/webhooks/stripe` with events `charge.*`, signing secret → env.

**PayPal Dashboard:** REST app for correct mode, `https://{backend}/api/v1/webhooks/paypal` with events `PAYMENT.CAPTURE.*`, webhook id → env.

**Local testing:** per §12; use `stripe listen` + ngrok + sandbox buyer accounts; assert order completed + stock committed.

**Production deployment:** §18 production steps; queue workers, `config:cache`, scheduler, live webhook registration, small live $ test, alerting.

### CAN I USE THEM NOW?

```
NO — ONLY AFTER CODE CHANGES + CONFIGURATION
```

`POST v1/general/checkout` with `gateway=stripe` today returns `422 Payment gateway unavailable` (UnsupportedGatewayException). No config-only fix bypasses `PaymentGatewayFactory`. Legacy `api/v1/orders` with `payment_gateway=STRIPE` could still create an intent (Marvel path) but the current storefront frontend does not call that route, and the P0 gaps remain.

### REQUIRED NEXT STEP (single)

**Pick one:** either (A) bridge Marvel Stripe/PayPal into the App checkout via `app/Services/Gateway/StripeGateway` + `PaypalGateway` adapters + factory/config/handler changes and the P0 fixes — then and only then set dashboard + `.env` — or (B) explicitly decide to keep MyFatoorah-only and document that Stripe/PayPal are legacy-dead (delete or archive docs that claim they work). **Do not set `STRIPE_API_KEY` and expect checkout to work without (A).**

---

### Evidence completeness note

All claims above include exact file path / class / method / line where feasible. `NOT FOUND`/`NOT VERIFIED` markers indicate deliberate absence of evidence, not speculation. Re-audit must execute `php artisan route:list --path=webhooks`, `tinker Settings::first()`, and `php artisan config:show shop.stripe` (or `dump(config('shop.stripe'))`) to close the remaining **NOT VERIFIED** items before live enable.

---

*End of audit — no files modified, no packages installed, no `.env` changed, no migration run.*
