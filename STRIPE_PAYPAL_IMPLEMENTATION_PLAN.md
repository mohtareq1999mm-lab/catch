# STRIPE + PAYPAL — FULL IMPLEMENTATION PLAN (READ-ONLY)

> Scope: make Stripe and PayPal work through the **current canonical checkout** (`app/Http/Controllers/Api/General/OrderController.php:87` → `PaymentCheckoutHandler` → `PaymentGatewayFactory` → `*Gateway` → provider) while preserving MyFatoorah, avoiding duplication of Marvel, and fixing webhook/security gaps.
> Mode: **READ-ONLY** — no code/env/migration/route modification in this phase; every claim is evidence-anchored.
> Evidence date: 2026-09-13

---

## 1. Executive Summary

This project ships two payment universes:

* **Marvel universe** (`packages/marvel/src/Payment/*`, `PaymentInterface.php:8`, `Base.php:9`, `Stripe.php:17`, `Paypal.php:16`, `PaymentTrait.php:22`, `ShopServiceProvider.php:220`) — 11 gateways fully coded, wired via `ShopServiceProvider` singleton `payment` that selects a gateway from `request.payment_gateway` or `Settings.options['defaultPaymentGateway']`. Stripe creates `PaymentIntent` and returns `client_secret`; PayPal creates `PayPal Order (CAPTURE)` and returns `redirect_url`. Webhooks are implemented and do signature verification.
* **App universe (canonical for storefront)** (`app/Services/Payment/Contracts/PaymentGatewayContract.php:8`, `PaymentGatewayFactory.php:9`, `PaymentCheckoutHandler.php:16`, `Gateway/MyFatoorahGateway.php:10`, `app/Http/Controllers/Api/General/OrderController.php:87`, `routes/api.php:115,131`, `config/payment.php:4`) — only `MyFatoorahGateway` is registered. `PaymentGatewayFactory::make()` throws `UnsupportedGatewayException` for any other string. `payment.php` declares `default_gateway=myfatoorah` and a single `gateways.myfatoorah` block.

Consequence: `STRIPE_API_KEY` being set does not make `POST v1/general/checkout` with `gateway=stripe` succeed — it throws before touching Stripe SDK. The legacy `POST api/v1/orders` path could still mint a Stripe `PaymentIntent` but the current Next.js storefront never calls that route.

The correct move is **Option C — App adapters around existing Marvel implementations**. Marvel owns the vendor-tied SDK work (`stripe/stripe-php 13.1.0`, `srmklive/paypal 3.0.19` from `packages/marvel/composer.json:28-30`) and has already-handled signature verification, amount scaling, `PayPal-Request-Id`, and customer scaffolding. Reimplementing those in `app` would duplicate ~700 lines and create a second security surface; moving them out of Marvel would violate the vendored-kernel constraint (`AGENTS.md §21.2`). An `app` `StripeGateway`/`PaypalGateway` that **implements `PaymentGatewayContract`** and delegates creation/verify/refund to the Marvel SDK class (or reuses its client) keeps ownership intact, makes `PaymentGatewayFactory` the single gate, and lets Stripe/PayPal ride the same `PaymentCheckoutHandler` → `Transaction` → `checkoutCallback` → `lockForUpdate` → `OrderReservationService::commit` → `OrderService::finalizePromotionUsageAfterPayment` → `changeOrderStatus('completed')` → `PaymentSucceeded` path that MyFatoorah already proves.

This plan describes exactly what to create, what to modify, what to leave alone, how webhooks become idempotent and amount-checked, how the frontend contract stays backward-compatible, and how to roll out with `myfatoorah` as the safe default.

---

## 2. Current Architecture

### 2.1 Ownership map (from prior read inventory)

| Layer | File | Owner | Canonical? |
|---|---|---|---|
| Marvel gateway contract | `packages/marvel/src/Payment/PaymentInterface.php:8` | Marvel | Legacy only |
| Marvel base (currency from DB) | `packages/marvel/src/Payment/Base.php:9` (`Settings::first()->options['currency']`) | Marvel | Legacy |
| Marvel `Stripe` | `packages/marvel/src/Payment/Stripe.php:17` | Marvel | Legacy |
| Marvel `Paypal` (`PayPalClient srmklive`) | `packages/marvel/src/Payment/Paypal.php:16` | Marvel | Legacy |
| `Payment` facade wrapper | `packages/marvel/src/Payment/Payment.php:6` + `Facades/Payment` | Marvel | Legacy |
| Traits (`PaymentTrait`, `PaymentStatusManagerWithOrderTrait`, `OrderStatusManagerWithPaymentTrait`) | `packages/marvel/src/Traits/*` | Marvel | Shared (webhook success is reused) |
| Models (`Order`, `Transaction`, `PaymentIntent`, `PaymentGateway`, `Settings`) | `packages/marvel/src/Database/Models/*` | Marvel | Shared (`Transaction` is used by App) |
| Shop config | `packages/marvel/config/shop.php:68,76` | Marvel | Legacy |
| `ShopServiceProvider` gateway locator | `packages/marvel/src/ShopServiceProvider.php:220` (`'payment'` singleton, string concat `Marvel\Payments\{Gateway}`) | Marvel | Legacy |
| Legacy routes | `packages/marvel/src/Rest/Routes.php` (`api/v1` prefix via `RestApiServiceProvider.php:13`) | Marvel | Legacy; webhooks `api/v1/webhooks/*` per `docs/api-contract.md:20` |
| App contract | `app/Services/Payment/Contracts/PaymentGatewayContract.php:8` | **App** | **Canonical** |
| App factory | `app/Services/Payment/PaymentGatewayFactory.php:9` | **App** | **Canonical** |
| App handler | `app/Services/Payment/PaymentCheckoutHandler.php:16` | **App** | **Canonical** |
| App MyFatoorah impl | `app/Services/Gateway/MyFatoorahGateway.php:10` | **App** | **Canonical** |
| App checkout controller | `app/Http/Controllers/Api/General/OrderController.php:87` | **App** | **Canonical** |
| App routes | `routes/api.php:115` `POST v1/general/checkout`, `:131` `any v1/general/checkout/callback|error-callback` | **App** | **Canonical** |
| App payment config | `config/payment.php:4` (`default_gateway=myfatoorah`, single gateway block) | **App** | **Canonical** |
| Infra config | `config/services.php:26` (`services.myfatoorah`) + `.env.example` | **App** | Canonical |

### 2.2 How selection works today

* **App path:** `OrderController::checkout` reads `input('gateway', config('payment.default_gateway','myfatoorah'))` (`OrderController.php:87`) → `PaymentCheckoutHandler::handleOnlinePayment` calls `factory->make($gateway)` → only `'myfatoorah'` matches; anything else `throw UnsupportedGatewayException` → handler returns `422`. Then `gateway->createInvoice(order, amount, callbackUrl, errorUrl)` where `callbackUrl=route('api.checkout.callback')` (`PaymentCheckoutHandler.php:38-41`, `OrderController.php:131`).

* **Marvel path:** `ShopServiceProvider::register` builds `'payment'` singleton by reading `request.payment_gateway` or `Settings.options['defaultPaymentGateway']` (`ShopServiceProvider.php:220`) and instantiating `Marvel\Payments\{Ucfirst}` via `app->make`. Legacy `POST api/v1/orders` (`Rest/Routes.php`) and `GET api/v1/orders/{id}` call `OrderRepository::storeOrder` then `PaymentTrait::processPaymentIntent` / `attachPaymentIntent`.

### 2.3 Data artifacts

* **App transaction:** `Transaction` model (`packages/marvel/src/Database/Models/Transaction.php`) with `order_id, invoice_id, gateway_transaction_id, payment_method, status {pending|paid|failed}, amount, currency, gateway_response, paid_at`. Created in `PaymentCheckoutHandler.php:52` (`status='pending'`), verified in `OrderController.php:185` (`verifyPayment`), completed in `:345` inside `DB::transaction lockForUpdate` with `status='paid'`.

* **Marvel intent:** `PaymentIntent` model (`packages/marvel/src/Database/Models/PaymentIntent.php`) with `order_id, tracking_number, payment_gateway, payment_intent_info JSON`. Created in `PaymentTrait::savePaymentIntent` (`PaymentTrait.php:161`). Checkout response returns intent content directly.

---

## 3. Current Stripe Flow

```
Frontend (if legacy were used)
  ↓  POST api/v1/orders {payment_gateway: STRIPE, products, total}
Marvel Rest Route api/v1/orders  (Rest/Routes.php)
  → Marvel OrderController::store  (OrderController.php:244)
    → OrderRepository::storeOrder  (create Order pending)
  → PaymentTrait::processPaymentIntent / attachPaymentIntent
    → Payment::getIntent via ShopServiceProvider 'payment'
      → Marvel Stripe::getIntent  (Stripe.php:97)
        amount = round(order.paid_total - wallet,2)*100
        currency = Base.currency = Settings.options['currency']
        stripe->paymentIntents->create{
          amount, currency, description 'Marvel Payment',
          automatic_payment_methods.enabled=true,
          metadata.order_tracking_number,
          customer? }  → {client_secret, payment_id, is_redirect:false}
      → PaymentIntent::create{order_id, tracking_number, payment_gateway Stripe, payment_intent_info}
  ← return intent; frontend calls stripe.confirmCardPayment(client_secret)
Stripe API → charge lifecycle
  ↓  webhook POST api/v1/webhooks/stripe  (docs/api-contract.md:514)
    Stripe::handleWebHooks  (Stripe.php:263)
      Webhook::constructEvent(payload=$_SERVER['HTTP_STRIPE_SIGNATURE']? actually file_get_contents php://input, payload+sig_header+endpoint_secret shop.stripe.webhook_secret)
        → matchSucceededOrFailed (checks data.object.object=='charge')
        → paymentGatewayWebHookResponse (switch charge_status)
          succeeded → webhookSuccessResponse(order, PROCESSING, SUCCESS)
            DB::transaction lockForUpdate, idempotency if status in {completed,cancelled,refunded} return
            update payment_status=SUCCESS, paid_at
            OrderReservationService::commit
            OrderService::finalizePromotionUsageAfterPayment
            OrderService::changeOrderStatus(...'completed', false)
            event PaymentSucceeded
          pending → PENDING/AWAITING_FOR_APPROVAL
          failed  → PENDING/FAILED
  Poll path also exists: PaymentStatusManagerWithOrderTrait::stripe (polls retrievePaymentIntent status)
```

Evidence: `Stripe.php:8 StripeClient`, `:30 construct config shop.stripe.api_secret`, `:97-137 create`, `:263 handleWebHooks`, `:316 paymentGatewayWebHookResponse`, `PaymentStatusManagerWithOrderTrait.php:26 stripe()`.

---

## 4. Current PayPal Flow

```
Frontend (legacy)
  ↓  POST api/v1/orders {payment_gateway: PAYPAL}
Marvel route → OrderRepository::storeOrder (create Order pending)
  → Paypal::getIntent  (Paypal.php:78)
    PayPal-Request-Id = Str::uuid()
    paypalClient->createOrder{
      intent CAPTRE, purchase_units[{invoice_id=tracking_number, amount{currency_code=Base.currency, value=round(amount,2)}, description "Order From "+app.name}],
      payment_source.paypal.experience_context{user_action PAY_NOW, payment_method_preference IMMEDIATE_PAYMENT_REQUIRED,
        cancel_url "{SHOP_URL}/orders/{tracking}/payment",
        return_url "{SHOP_URL}/orders/{tracking}/thank-you"} } // Paypal.php:86-96 SHOP_URL=shop.shop_url
      → {redirect_url=links[1].href, payment_id=id, is_redirect=true}
    → PaymentIntent::create
  ← return redirect; frontend redirects user to redirect_url
PayPal approval page
  → approve → redirect to return_url (thank-you) — cancel → cancel_url (payment)
  Poll:
    PaymentStatusManagerWithOrderTrait::paypal  → Payment::verify(paymentId) → Paypal::verify → paypalClient->capturePaymentOrder(id)
      status completed → paymentSuccess  payer_action_required → paymentProcessing
  Webhook:
    POST api/v1/webhooks/paypal → Paypal::handleWebHooks (Paypal.php:160)
      verifyData {auth_algo, cert_url, transmission_id/sig/time, webhook_id config shop.paypal.webhook_id, webhook_event=request->all()}
      paypalClient->verifyWebHook → verification_status MUST be SUCCESS else 400 exit
      switch event_type: PAYMENT.CAPTURE.COMPLETED → PROCESSING/SUCCESS
                       PAYMENT.CAPTURE.PENDING    → PENDING/PENDING
                       PAYMENT.CAPTURE.CANCELLED  → PENDING/FAILED
                       PAYMENT.CAPTURE.REVERSED   → CANCELLED/REVERSAL
        → updatePaymentOrderStatus: trackingId=resource.invoice_id → Order where tracking_number
          → webhookSuccessResponse (same as Stripe — lock, idempotency, inventory, promotion, completed)
```

Evidence: `Paypal.php:11 srmklive PayPal`, `:25 constructor getAccessToken`, `:78 getIntent`, `:160 handleWebHooks`, `:245 updatePaymentOrderStatus`, `shop.php:76 paypal config`.

---

## 5. Current MyFatoorah Flow (canonical — working)

```
Frontend
  ↓ POST v1/general/checkout  (routes/api.php:115, auth:sanctum)  {payment_method online, gateway myfatoorah, products}
OrderController::checkout  (app/OrderController.php:87)
  validated OrderCreateRequest; cart looked up via CartInventoryService
  gateway = input('gateway', config('payment.default_gateway','myfatoorah')) // myfatoorah
  order = OrderService::addItemsInOrder(request) // pending order + reservation (inventory_state reserved), coupon reserved
  if online → PaymentCheckoutHandler::handleOnlinePayment(request, order, amount=round(total_price,2), gateway)
    gatewayInstance = PaymentGatewayFactory::make(gateway) // MyFatoorahGateway (payment/Gateway/MyFatoorahGateway.php:10)
    orderCurrency = order.currency_code ?? order.base_currency_code ?? config('payment.default_currency','EGP')
    supportsCurrency? else 422
    coupon reserve?
    result = MyFatoorahGateway::createInvoice(order, amount, callbackUrl=route('api.checkout.callback'), errorUrl=route('api.checkout.errorCallback'))
      MyfatoraService->createInvoice{InvoiceValue, CustomerName, NotificationOption LNK, DisplayCurrencyIso, MobileCountryCode, CustomerMobile, CustomerEmail via CustomerContactResolver, language, CallBackUrl, ErrorUrl}
      → GatewayResult{success, redirectUrl=Data.InvoiceURL, gatewayTransactionId=Data.InvoiceId, rawResponse}
    if !success → 500
    Transaction::create{order_id, user_id, invoice_id=gatewayTransactionId, payment_method=gateway, status pending, amount, currency, gateway_transaction_id, gateway_response+_callback_type}
    return {url: redirectUrl} // ApiResponse wrapper
Frontend redirects to redirectUrl (MyFatoorah hosted page)
  → pay → MyFatoorah calls ANY v1/general/checkout/callback?paymentId=InvoiceId (routes/api.php:131)
    OrderController::checkoutCallback (OrderController.php:169)
      transaction = where gateway_transaction_id|invoice_id = paymentId
      gatewayName = transaction.payment_method ?? myfatoorah
      gateway->verifyPayment(paymentId) via MyFatoorahGateway::verifyPayment → myfatoraService->checkInvoice{Key, KeyType PaymentId} → InvoiceStatus==='Paid'?
        → GatewayResult{success=isPaid, gatewayTransactionId, amount, currency, status paid|failed}
      amount/currency mismatch vs order? test gateway (apitest.myfatoorah) logs only; live: blocks + warning log, marks failure, PaymentFailed event, redirect failed
      DB::transaction lockForUpdate(Transaction,Order) idempotency: if lockedOrder.status!=='pending' return
        Transaction status paid, gateway_response merged, paid_at
        Order payment_status SUCCESS, paid_at
        OrderReservationService::commit(lockedOrder) // deduct once
        OrderService::finalizePromotionUsageAfterPayment(lockedOrder)
        OrderService::changeOrderStatus(invoice_id,'completed', emit=false)
        processed=true
      if processed event PaymentSucceeded(order.fresh())
      response: if callbackType mobile → JSON {status success, payment_id, order_id} else redirect {APP_URL_FRONTEND}/{locale}/payment/success?
  Error callback (routes/api.php:132 checkout/error-callback) mirrors verify + failure branch, DON'T commit inventory, PaymentFailed if needed, redirect/payment/failed.

Post-success: queues meem-high listeners (GenerateInvoice, SendPaymentSucceededNotification), PaymentReconciliationJob, CancelUnpaidOrders.
```

Evidence: `OrderController.php:87,169`, `PaymentCheckoutHandler.php:16,26`, `MyFatoorahGateway.php:17,80`, `config/payment.php:4` + `config/services.php:26`, `Transaction` model, `PaymentReconciliationJob.php`.

---

## 6. Architectural Gap

| Question | Answer |
|---|---|
| **Where do the architectures diverge?** | At `PaymentGatewayFactory::make` (App) vs `ShopServiceProvider['payment']` string concat (Marvel). MyFatoorah route is a leaf of App factory; Stripe/PayPal are leaves of Marvel factory. There is no edge App→Marvel. |
| **Why DISCONNECTED?** | `PaymentGatewayFactory.php:9` `match` only contains `'myfatoorah'`; `PaymentCheckoutHandler.php:26` assumes `createInvoice` + `redirectUrl` contract; `config/payment.php:12` only declares `myfatoorah`; legacy `api/v1/orders` store not called by storefront. |
| **Labeling the flow (from §4 prompt):** | `POST v1/general/checkout` through `OrderService::addItemsInOrder` is **CANONICAL** (used in prod). `PaymentGatewayFactory → MyFatoorahGateway` is **CANONICAL**. `PaymentGatewayFactory → ??? (stripe/paypal)` is **BLOCKED**. Marvel `POST api/v1/orders` → `OrderRepository::storeOrder` → `Stripe::getIntent`/`Paypal::getIntent` is **LEGACY**. `Transaction` creation is **CANONICAL**; `PaymentIntent` creation is **LEGACY**. Webhooks `POST api/v1/webhooks/*` exist as **LEGACY/DISCONNECTED**; `POST v1/general/checkout/callback` is **CANONICAL**. |
| **What would break if we just flipped config?** | Nothing: no config flag controls `PaymentGatewayFactory`. Even if `default_gateway=stripe`, factory still throws. |
| **Duplication risk** | Reimplementing Stripe `StripeClient` calls or PayPal `PayPalClient->createOrder/capture` in `app` would duplicate `Stripe.php:127` & `Paypal.php:86` and the `PayPal-Request-Id`/signature verification already proved there. |
| **Security debt to pay before enable** | Webhooks lack amount/currency cross-check (`Stripe.php:316`, `Paypal.php:245` → `PaymentTrait::webhookSuccessResponse:358`). App callback already has it (`OrderController.php:275`). Must port that guard. Also `ShopServiceProvider` trusts `request.payment_gateway` without validation; App factory must validate. |
| **Webhooks are the second disconnect** | App exposes generic `checkout/callback` that verifies via `Gateway::verifyPayment` (MyFatoorah). Marvel webhooks are provider-signed **push** (`Stripe::handleWebHooks`, `Paypal::handleWebHooks`) on `api/v1/webhooks/*`. After wiring Stripe/PayPal into App, you need **both**: provider-signed webhooks for asynchronous settlement **and** a unified verification path in `OrderController::checkoutCallback` or dedicated webhook controllers that reuse the same transactional completion. |

Current shape is therefore `CANONICAL (MyFatoorah)` + `LEGACY (Stripe+PayPal)` with no adapter.

---

## 7. Architecture Options

### Option A — Move completely from Marvel into `app`

Copy `Stripe.php`, `Paypal.php`, `Base.php` into `app/Services/Gateway/`, change namespace to `App\`, patch `config/shop.php` references, update `ShopServiceProvider` to not bind them. Ownership becomes `app`.

*Pros:* Single namespace, easier for app team to own.
*Cons:* Violates vendored-kernel rule, duplicates future Marvel upgrades, creates a fork that must be manually rebased, breaks the existing legacy admin route that still uses `Marvel\Payments\Stripe` by FQN string. High migration cost, low benefit. **Discard.**

### Option B — Reimplement independently in `app`

Write new `App\Services\Gateway\StripeGateway` and `PaypalGateway` from scratch against `stripe/stripe-php` and `srmklive/paypal`, ignoring `packages/marvel/src/Payment/*`.

*Pros:* Clean separation, no Marvel coupling.
*Cons:* Duplicates ~350 lines of Stripe `StripeClient` handling + `PayPalClient` token/order creation already tested in Marvel, duplicates webhook signature verification (and would likely reintroduce the same P0 gaps differently), creates two sources of truth for provider behavior. Violates **REUSE BEFORE CREATE** (`AGENTS.md §PHASE 2`). **Discard.**

### Option C — App adapters around existing Marvel implementations (recommended)

Create **thin App adapters** (`app/Services/Gateway/StripeGateway`, `PaypalGateway`) that **implement `PaymentGatewayContract`** (`PaymentGatewayContract.php:8`) and **delegate** to the existing Marvel classes (`Marvel\Payments\Stripe`, `Marvel\Payments\Paypal`) or their underlying SDK clients (`Stripe\StripeClient`, `Srmklive\PayPal\Services\PayPal`). The adapter is where `PaymentGatewayFactory` dispatch, `config/payment.php` lookup, currency validation, `CustomerContactResolver` bridging, and error translation happen; the Marvel class remains the provider-specific implementation detail. Webhook endpoints are added under `routes/api.php` (or `api/v1` group) that verify provider signature then call the same canonical order-completion service as MyFatoorah.

*Pros:* Reuses proven SDK wiring and webhook verification; preserves Marvel ownership; satisfies `PaymentGatewayFactory` abstraction already used by queues/jobs (`PaymentReconciliationJob`, `CancelUnpaidOrders`); single gateway addition path for future providers (`AGENTS.md §21.7 strangler modernization`); no copy-paste; testable behind contract.
*Cons:* Adapters must translate between `PaymentIntent`-centric Marvel responses (`{client_secret,is_redirect}`) and `GatewayResult` (`{redirectUrl,gatewayTransactionId}`). Minor impedance mismatch — solved by extending `GatewayResult`.

**Discard A/B — choose C.**

### Option D — Adapter + domain events bus / outbox

Like C but adds outbox table and domain events between gateway and order. Overkill for the immediate goal and not evidenced in current repo (no outbox pattern for payments). Can be layered later. **Defer.**

---

## 8. Recommended Architecture — Rationale Summary

**RECOMMENDED: Option C — App `PaymentGatewayContract` adapters over Marvel SDK implementations.**

Why this fits repository evidence:

* `App\Services\Payment\Contracts\PaymentGatewayContract` is the **only contract** referenced by `OrderController`, `PaymentCheckoutHandler`, `PaymentReconciliationJob`, `CancelUnpaidOrders`, and the upcoming tests — Marvel `PaymentInterface` is never imported in `app`. Adding gateways that do not satisfy the App contract would require a second code path in every caller.
* `packages/marvel/src/ShopServiceProvider.php:220` already proves the Marvel classes work and are configuration-driven; wrapping them avoids reimplementing amount scaling (`round*100`), `automatic_payment_methods`, `PayPal-Request-Id`, and webhook `verifyWebHook` logic that `STRIPE_PAYPAL_PAYMENT_FLOW_AUDIT.md §5-6` already audited.
* `config/payment.php:4` is the App source of truth for supported gateways/currencies — adapters let `shop.php:68,76` remain Marvel legacy while App config governs selection (no duplication if adapter reads `shop.*` as fallback).
* Backward compatibility: MyFatoorah stays default (`payment.php:6 default_gateway myfatoorah`), existing orders/transactions unchanged, legacy `api/v1/orders` still functions for admin tools; new gateways are additive behind `PaymentGatewayFactory`.

Responsibility split under C:

* **Marvel class** (`packages/marvel/src/Payment/Stripe|Paypal`): SDK construction (`new StripeClient(config shop.stripe.api_secret)`, `new PayPalClient(config shop.paypal)`), raw provider calls (`paymentIntents->create/retrieve`, `createOrder/capturePaymentOrder`), raw webhook signature math (`Stripe\Webhook::constructEvent`, `paypalClient->verifyWebHook`).
* **App adapter** (`app/Services/Gateway/StripeGateway` / `PaypalGateway`): satisfies `PaymentGatewayContract`, validates currency against `order.currency_code ?? order.base_currency_code ?? config payment.default_currency`, maps `Order→provider payload`, creates idempotency key if Marvel doesn't, translates provider response to `GatewayResult` (incl. Stripe's `client_secret`), handles `refund`, surfaces `name()`/`supportsCurrency()`, and never logs secrets.

---

## 9. Target Architecture (validated against repo)

```
                    POST v1/general/checkout  (routes/api.php:115)
                               │
                    OrderController::checkout (app:87)
                     OrderService::addItemsInOrder → Order pending
                     PaymentCheckoutHandler::handleOnlinePayment
                               │
                     PaymentGatewayFactory::make(gateway)  (app:9)
                               │
              ┌────────────────┼────────────────┐
              ▼                ▼                ▼
   MyFatoorahGateway    StripeGateway     PaypalGateway      ← app/Services/Gateway/*  (CREATE)
     (EXISTING)         (ADAPTER)         (ADAPTER)
        │                  │                 │
        ▼                  ▼                 ▼
  MyfatoraService    Marvel\Payments\Stripe  Marvel\Payments\Paypal  ← REUSED
        │            StripeClient / PayPalClient (srmklive)
        ▼                  ▼                 ▼
    GatewayResult     GatewayResult     GatewayResult   ← extended shape (see §13)
    (redirectUrl)  (client_secret+id)  (approvalUrl)
        │                  │                 │
        └──────────────────┴─────────────────┘
                           │
                PaymentCheckoutHandler → Transaction::create pending
                    {gateway_response includes provider id + client_secret|redirect_url + amount/currency}
                           │
                JSON {url|client_secret, payment_id, gateway} → Frontend
                           │
              ┌────────────┼────────────┐
              ▼            ▼            ▼
         Stripe 3DS   PayPal approve  MyFatoorah pay
              │            │            │
              └────────────┼────────────┘
                           │
            Webhooks + callbacks → backend verification
                           │
           api/v1/webhooks/stripe  (Stripe webhook controller)
           api/v1/webhooks/paypal  (PayPal webhook controller)
           v1/general/checkout/callback (MyFatoorah + fallback verify via factory)
                           │
               verifyPayment(verify via SDK) + amount/currency guard
                           │
               PaymentCompletionService (NEW) — single transactional commit:
                 lock Transaction + Order (FOR UPDATE), idempotency on status/webhook event_id,
                 amount & currency validation, commit inventory, finalize promotion/coupon,
                 status=completed, paid_at, transaction=paid, event PaymentSucceeded
```

Ownership per node:

| Node | Owner | Responsibility |
|---|---|---|
| `PaymentGatewayContract` | App | `createInvoice(Order, amount, callbackUrl, errorUrl) GatewayResult`, `verifyPayment(id) GatewayResult`, `refund(...) GatewayResult`, `name()`, `supportsCurrency()` — all gateways must satisfy. `GatewayResult` (`app/DTOs/GatewayResult.php:8`) is the only DTO controllers/handlers/jobs import. |
| `PaymentGatewayFactory` | App | `make(string $gateway)` case-insensitive, validates `config('payment.gateways.*.class')` exists, throws `UnsupportedGatewayException` with 422 mapping. Default `config('payment.default_gateway')` is `myfatoorah` — preserved. |
| `StripeGateway` | App (adapter) | Implements contract; deps: `Marvel\Payments\Stripe` (or `Stripe\StripeClient` directly), `config('shop.stripe')`, `CustomerContactResolver` if needed (Stripe rarely needs phone). Maps `Order→getIntent` (`amount*100`, currency from `Order` snapshot not `Base.currency`, metadata order_tracking_number, customer if `order.user_id`), catches `Stripe\Exception\*` → `GatewayResult{success:false, errorMessage}`, on success → `GatewayResult{success:true, redirectUrl:null, gatewayTransactionId=payment_intent.id, status pending, rawResponse + client_secret}`. `verifyPayment` retrieves `paymentIntents->retrieve` and classifies `succeeded/processing/failed`. `supportsCurrency` checks `config('payment.gateways.stripe.supported_currencies')` (fallback to `shop.paypal`-like logic). |
| `PaypalGateway` | App (adapter) | Similar delegation to `Marvel\Payments\Paypal` or its `PayPalClient`; ensures `PayPal-Request-Id: Str::uuid()` (already in Marvel `Paypal.php:86`), maps `invoice_id=order.tracking_number`, validates `SHOP_URL` present, translates `createOrder` links to `redirectUrl`, `verifyPayment` → `capturePaymentOrder` → `completed/failed`. |
| `Marvel\Payments\*` | Marvel | Unchanged, but not invoked directly by controllers after — only via adapters. Keeps SDK construction + webhook math in one place. |
| `PaymentCheckoutHandler` | App (modify) | Now handles both `redirectUrl` and `client_secret` branches; always creates `Transaction` pending with `gateway_transaction_id` and stores `gateway_response {…client_secret?}` for polling/webhook; preserves coupon reservation + currency check. |
| `Transaction` | Shared model | New `gateway_transaction_id` semantics: Stripe `pi_...`, PayPal `OrderID`, MyFatoorah `InvoiceId`. Unique index on `gateway_transaction_id` (or composite) is advisable — see §18. |
| Webhook controllers | App (create) | `app/Http/Controllers/Api/Webhooks/StripeWebhookController` + `PaypalWebhookController` — verify signature (Stripe `Webhook::constructEvent` with `shop.stripe.webhook_secret`; PayPal `verifyWebHook` + webhook_id), load order via `resource.invoice_id`/`metadata.order_tracking_number`, validate amount/currency, check `WebhookEvent` dedup table, `DB::transaction lockForUpdate` on Order, call `PaymentCompletionService`. Fallback: `OrderController::checkoutCallback` can also call `factory->make(gateway)->verifyPayment` for manual polling. |
| `PaymentCompletionService` | App (create) | Single transactional commit used by **all** gateways (MyFatoorah callback + Stripe webhook + PayPal webhook). Owns `DB::transaction { lockForUpdate Transaction+Order, check status pending, update transaction paid, order payment_status SUCCESS paid_at, OrderReservationService::commit, OrderService::finalizePromotionUsageAfterPayment, changeOrderStatus('completed',false), emit PaymentSucceeded }`. Port MyFatoorah's existing `OrderController::checkoutCallback` logic into this service so fixes apply everywhere. |

DTOs:

* `GatewayResult.php:8` fields: `success bool, redirectUrl ?string, gatewayTransactionId ?string, amount ?float, currency ?string, status ?string, errorMessage ?string, rawResponse ?array`. **Extension:** allow `clientSecret ?string`, `isRedirect bool`, `paymentId` alias. Or add `metadata: array` bag so Stripe's `client_secret` can travel without breaking existing MyFatoorah callers (they ignore extra keys).

Error handling: `GatewayException` → handler returns `500` on provider error; `422` on unsupported gateway/currency/missing config; `401` on webhook signature failure.

Return values: adapters never throw on expected provider decline — they return `GatewayResult{success:false}` with `errorMessage` and `rawResponse` for logging. Only missing config / invalid gateway throws.

Verification responsibilities: adapter does both creation **and** verification; webhook controller reuses `verifyPayment` internally but also checks webhook event signature **first**.

Webhook responsibilities: verify provider signature, dedup by event_id, load order, validate amount/currency against `order.total_price` and `order.currency_code`, lock, commit idempotently.

---

## 10. Exact File Changes

| File | Action | Why | Risk | Details |
|---|---|---|---|---|
| `app/Services/Gateway/StripeGateway.php` | **CREATE** | Adapter that makes Marvel `Stripe` satisfy `PaymentGatewayContract` so `PaymentGatewayFactory` + `PaymentCheckoutHandler` can treat Stripe like any other gateway | P1 | Implement `createInvoice(Order, float amount, string cb, string err): GatewayResult` (maps amount→`*100`, order snapshot currency, calls Marvel `Stripe::getIntent` or raw `StripeClient`; handles `Stripe\Exception\*` → GatewayResult failure; returns client_secret+payment_id), `verifyPayment(string id): GatewayResult` (retrieve intent, classify status, expose amount/currency), `refund` (forward to Stripe `refunds->create`), `name/supportCurrency`. Inject Marvel `Stripe` via `app(Marvel\Payments\Stripe::class)` or construct `StripeClient` directly with `config shop.stripe`. |
| `app/Services/Gateway/PaypalGateway.php` | **CREATE** | Same for PayPal | P1 | Delegate to `Marvel\Payments\Paypal::getIntent` / `verify` / PayPal SDK; ensure `SHOP_URL` not blank else throw; `createInvoice` returns `redirectUrl+payment_id`; `verifyPayment` calls `capturePaymentOrder`; add `refund` via `refundCapturedPayment`. |
| `app/Services/Payment/PaymentCompletionService.php` | **CREATE** | Single transactional completion used by all providers — removes duplication between `OrderController::checkoutCallback` and Marvel `webhookSuccessResponse`; enforces amount/currency check, lock, idempotency, inventory, promotion, event | **P0** | Method `complete(Order, Transaction, GatewayResult)` or `completeByGatewayTransactionId(string id, GatewayResult)` with: `DB::transaction{ lockForUpdate(Transaction.where gateway_transaction_id|invoice_id or PaymentIntent), lockForUpdate(Order), check status===pending else return alreadyCompleted, validate amount (±0.01) & currency (allow test skip if apitest), update Transaction paid/paid_at/gateway_response merge, update Order payment_status/success/paid_at, OrderReservationService::commit, OrderService::finalizePromotionUsageAfterPayment, OrderService::changeOrderStatus(...completed,false), emit PaymentSucceeded(order.fresh()) }`. Backed by contract and events already used by `OrderController.php:315`. |
| `app/Services/Payment/PaymentGatewayFactory.php` | **MODIFY** | Was `match myfatoorah`; must resolve `stripe`,`paypal` | **P0 if wrong, P2 if correct** | Add `'stripe' => app(StripeGateway::class), 'paypal' => app(PaypalGateway::class)` (case-insensitive: `strtolower(trim($gateway))`). Keep default throw `UnsupportedGatewayException`. Optionally read `config('payment.gateways.*.class')` registry instead of hardcoded match. Preserve `default_gateway=myfatoorah` in fallback. |
| `app/Services/Payment/PaymentCheckoutHandler.php` | **MODIFY** | Handler currently assumes every `GatewayResult` has `redirectUrl` and always creates `Transaction` with invoice style | **P0** | Branch on gateway type: Stripe → expect `gatewayTransactionId` + `rawResponse[client_secret]` (or `clientSecret` on result), still create `Transaction pending` with `gateway_response = {client_secret,...raw, _callback_type}`; PayPal → `redirectUrl + payment_id is_redirect true`. Return response that includes `url` **or** `client_secret` — see §13 for additive contract. Also switch currency source from inline fallback to `OrderCreationService::resolveCurrencySnapshot` or keep `order.currency_code ?? base_currency ?? default_currency` but ensure it matches `supported_currencies`. Keep coupon reservation logic unchanged. |
| `app/Http/Controllers/Api/General/OrderController.php` | **MODIFY** | Callers of `PaymentCheckoutHandler` plus webhook/callbacks need to route to correct gateway and reuse completion | P1 | Refactor `checkoutCallback/checkoutErrorCallback` to delegate to `PaymentCompletionService` instead of inline `DB::transaction` duplication; broaden `transaction` lookup to include `payment_intents` style `gateway_transaction_id` (pi_); ensure `handleOnlinePayment` return shape is forwarded correctly. Keep `index/show/eligiblePromotions/markCodAsPaid/markCashierPaid` unchanged. |
| `app/Http/Controllers/Api/Webhooks/StripeWebhookController.php` | **CREATE** | Dedicated webhook endpoint for Stripe push events — Marvel `Stripe::handleWebHooks` does raw `exit()` and `$_SERVER` read, not Laravel-idiomatic nor testable | **P0** | `__invoke(Request $r)` → verify `Stripe\Webhook::constructEvent(payload=file_get_contents('php://input') or $r->getContent(), sig_header=$r->header('Stripe-Signature') ?? $_SERVER['HTTP_STRIPE_SIGNATURE'], secret=config('shop.stripe.webhook_secret'))` → catch `UnexpectedValueException/SignatureVerificationException` → `Log::warning` + `abort(400)`. Parse event `data.object.object==='charge'` path or `payment_intent` variants; load order by `metadata.order_tracking_number` or `resource.invoice_id`; call `StripeGateway::verifyPayment` to re-verify status; check `WebhookEvent` dedup; validate amount/currency; call `PaymentCompletionService`. Return `200`. No `exit()`. |
| `app/Http/Controllers/Api/Webhooks/PaypalWebhookController.php` | **CREATE** | Dedicated PayPal webhook; Marvel `Paypal::handleWebHooks` uses `http_response_code+exit` | **P0** | Mirror Stripe; build `verifyData {auth_algo,cert_url,transmission_id/sig/time, webhook_id, webhook_event}` from headers + `request->all()`; call `paypalClient->verifyWebHook` (delegate to Marvel Paypal or directly to SDK); validate `verification_status==='SUCCESS'`; switch `event_type` 4 cases; same dedup + completion. |
| `app/DTOs/GatewayResult.php` | **MODIFY** | Current shape has `redirectUrl` only; Stripe needs `clientSecret` without breaking MyFatoorah callers | P1 | Add `public readonly ?string $clientSecret = null, public readonly bool $isRedirect = true, public readonly ?array $metadata = null` or at minimum `?string clientSecret`. Keep existing fields. Existing MyFatoorah consumers ignore new fields. Alternatively store client_secret inside `rawResponse` + set special key `_stripe_client_secret`. Explicit field is cleaner. |
| `config/payment.php` | **MODIFY** | Was single-gateway; needs explicit Stripe/PayPal blocks so factory + `supportsCurrency` can read it | **P0** | Add: `'gateways' => ['myfatoorah'=>[...existing...], 'stripe'=>['class'=>StripeGateway::class,'api_key'=>env('STRIPE_API_KEY'),'webhook_secret'=>env('STRIPE_WEBHOOK_SECRET_KEY'),'supported_currencies'=>array_filter(explode(',',env('STRIPE_SUPPORTED_CURRENCIES','USD,EUR,GBP')))], 'paypal'=>[...webhook_id/mode/currencies...]]` OR keep shop.php single source and map to payment gateways. Keep `default_gateway=myfatoorah`, `default_currency`, `order_timeout_hours`. Ensure `config:cache` compatibility. |
| `config/services.php` | **NO CHANGE** | `services.myfatoorah` already used by `MyFatoorahGateway.php:26`; Stripe/PayPal belong in `config/payment.php` or `shop.php` — not `services` | P3 | Document that services remaining for MyFatoorah only. |
| `packages/marvel/config/shop.php` | **NO CHANGE** (unless adding new env keys) | Already declares `stripe {api_secret, webhook_secret}` and `paypal {mode,sandbox,live,webhook_id,...}` used by Marvel classes; adapters will read these as fallback so `config/payment.php` and `shop.php` must stay consistent, not duplicate. If new keys like `STRIPE_SUPPORTED_CURRENCIES` are env-driven, add them here **or** in `payment.php` but not both. Preferred: keep raw SDK config in `shop.php`, App supported_currencies in `payment.php`. | P2 | If added, keep `shop.php:68 stripe.api_secret env(STRIPE_API_KEY)` as-is; do not add third copy. |
| `.env.example` | **CONFIG ONLY** | Must document variables actually read — see §12 | P2 | Already documents `STRIPE_API_KEY`, `STRIPE_WEBHOOK_SECRET_KEY`, `PAYPAL_MODE`, `PAYPAL_*` etc (`/.env.example:99,104-122`). Ensure after wiring: `STRIPE_SUPPORTED_CURRENCIES` (if used), `PAYPAL_WEBHOOK_ID` is listed (already), `SHOP_URL` documented as required for PayPal return URLs. No secrets added — names only. |
| `routes/api.php` | **ROUTE CHANGE** (additive) | Needs webhook routes under App control + unified callback branch | **P1** | Add: `Route::post('webhooks/stripe', [StripeWebhookController::class,'__invoke'])->middleware(['api','throttle:webhook'])->name('webhook.stripe')` and `paypal` similarly. Keep existing `any checkout/callback` and `checkout/error-callback` (they delegate to `PaymentCompletionService` after factory routing). Optionally keep legacy `api/v1/webhooks/*` behind Marvel but **deprecate** — new routes are the canonical ones. No checkout route change (`POST v1/general/checkout` stays). |
| `packages/marvel/src/Rest/Routes.php` | **NO CHANGE** | Legacy webhooks `api/v1/webhooks/*` remain for backward compat but new `routes/api.php` endpoints are primary; do not delete until verified no legacy frontend depends | P2 | Document as legacy/deprecated. |
| `packages/marvel/src/Payment/Stripe.php` | **NO CHANGE** (critical) | Adapter delegates instead of forking; keep Marvel source intact | — | Fixing amount/currency gap is done in `PaymentCompletionService`, not by patching Marvel directly (avoids fork). If Marvel's `handleWebHooks` remains used, it will still be dead code; new controller is the replacement. |
| `packages/marvel/src/Payment/Paypal.php` | **NO CHANGE** | Same | — |  |
| `packages/marvel/src/Traits/PaymentTrait.php` | **NO CHANGE** (or surgical) | Contains recent `webhookSuccessResponse` transactional fix that adapters can reuse if they call it, but new completion service is the canonical path; do not edit unless fixing `failed` guard | P1 | If fixing, change `in_array(..., [completed,cancelled,refunded])` to include `failed` — but this belongs in App completion. Prefer not touching Marvel. |
| `packages/marvel/src/ShopServiceProvider.php` | **NO CHANGE** | Still provides legacy `payment` singleton for `api/v1/orders` flow; App factory coexists | — |  |
| `app/Models` / migrations — `transactions`, `webhook_events` | **MIGRATION OPTIONAL→REQUIRED** | `transactions` may need `client_secret` is in `gateway_response` so no column. `WebhookEvent` table for event-id dedup **is** new (see §18). If amount/currency guard needs `order.currency_code` it already exists (`Marvel\Database\Models\Order`). No order column change. | P1 | Create `webhook_events` (`provider enum stripe|paypal|myfatoorah`, `event_id string unique`, `payload json`, `processed_at nullable`, index `provider+event_id`). Alternatively reuse `payment_intents` uniquely — but webhook events need separate table. |
| `app/Services/General/OrderService.php` + `Checkout/OrderCreationService.php` | **NO CHANGE** | Provide order snapshot/currency already reused by MyFatoorah (`payment-audit.md` documents `order.currency_code ?? base_currency ?? config`); adapters should call same. | — |  |
| `app/Services/Inventory/OrderReservationService.php` | **NO CHANGE** | Canonical reservation (`reserved`→`committed`), already called by completion service; no provider-specific logic | — |  |
| `app/Jobs/PaymentReconciliationJob.php` | **MODIFY (extension)** | Currently reconciles MyFatoorah by re-`verifyPayment`; should support stripe/paypal verification | P2 | Add `gatewayName = $transaction->payment_method` dispatch; call correct gateway's `verifyPayment` for pending transactions past threshold (reuse `PaymentGatewayFactory`). |
| `app/Console/Commands/CancelUnpaidOrders.php` | **NO CHANGE** | Generic cancellation by `status=pending` + `reservation_expires_at` — provider-agnostic | — |  |
| `tests/*` | **TEST** (new) | See §25 matrix | P0 | Factory, checkout, webhook, idempotency, amount/currency mismatch, reversal, etc. |
| `docs/*` (`api-contract.md`, `payment-audit.md`, etc.) | **DOCUMENTATION** | After implementation, update `docs/api-contract.md` checkout response example to show `client_secret` branch, document env/webhook URLs, update `STIPE_PAYPAL_PAYMENT_FLOW_AUDIT.md` successor. Do not update until explicit `Update API File` command (`AGENTS.md §PHASE 17`). | P3 |  |

Total invasive changes: **2 adapters + 1 completion service + 2 webhook controllers** (CREATE), **config/payment.php + factory + handler + DTO** (MODIFY), **2 webhook routes** (ROUTE CHANGE), **1 optional migration** (webhook_events). Marvel payments untouched.

---

## 11. Factory Plan

```
PaymentGatewayFactory::make(string $gateway): PaymentGatewayContract
```

**Contract reading:** `MyFatoorahGateway` (`MyFatoorahGateway.php:80`) shows `verifyPayment` uses `checkInvoice(Key, KeyType PaymentId)` and returns `GatewayResult{success:isPaid}` — same contract expected from stripe/paypal adapters.

**Factory after:**

```php
final class PaymentGatewayFactory {
  public function make(string $gateway): PaymentGatewayContract {
    $key = strtolower(trim($gateway));
    return match($key) {
      'myfatoorah' => app(MyFatoorahGateway::class),
      'stripe'     => app(StripeGateway::class),
      'paypal'     => app(PaypalGateway::class),
      default      => throw new UnsupportedGatewayException($gateway),
    };
  }
}
```

Or registry-driven:

```php
$map = config('payment.gateways'); // ['myfatoorah'=>['class'=>MyFatoorahGateway::class], 'stripe'=>['class'=>StripeGateway::class], ...]
$entry = $map[$key] ?? throw ...
return app($entry['class']);
```

Decision: registry-driven is preferable (single config source, easier test mock), but hardcoded `match` is acceptable given only three entries. Either passes as long as **case-insensitive** (evidence: `OrderController.php:87` does not normalize, so factory must).

**Naming convention:** lower-snake/hyphen is already used (`'myfatoorah'`); expose `'stripe'`, `'paypal'` (not `'STRIPE'`/`'PAYPAL'` enum values `PaymentGatewayType.php:17` upper-case). Gateways will lowercase before compare. API field is `gateway` (not `payment_gateway`) per `OrderController.php:87` (`input('gateway', config('payment.default_gateway'))`) — preserve `gateway`. Legacy Marvel uses `payment_gateway` (`payment_gateway=STRIPE` uppercase) — do not reuse that key on modern checkout.

**Validation:** if `gateway` not in `config('payment.gateways')` or `match` miss → `UnsupportedGatewayException` (`app/Exceptions/UnsupportedGatewayException.php`) caught by `PaymentCheckoutHandler.php:28` → `apiResponse(422)`. Preserve behavior.

**Unsupported behavior:** 422 with `PAYMENT_GATEWAY_UNAVAILABLE` or `message.ERROR.PAYMENT_PROVIDER_UNSUPPORTED` (consistent with `PaymentCheckoutHandler`).

**Default gateway:** `config('payment.default_gateway','myfatoorah')` (`config/payment.php:6`). **Unchanged.** `OrderController::checkout` uses `$gateway = input('gateway', config('payment.default_gateway','myfatoorah'))`; if client omits `gateway` → still myfatoorah. Zero-risk rollout.

**DI:** adapters bound in container as singletons? No — transient via `app()->make` is fine (Marvel clients hold API secret). No special provider needed beyond default.

---

## 12. Configuration Plan

### Where each config lives

| Config | File | Why there |
|---|---|---|
| `default_gateway`, `default_currency`, `order_timeout_hours`, `gateways` map | `config/payment.php:4` | **App** — read by `OrderController`, `PaymentCheckoutHandler`, `PaymentGatewayFactory`, `CancelUnpaidOrders`, `PaymentReconciliationJob`. Single source for factory registry + `supported_currencies`. |
| `services.myfatoorah` (`api_key`, `base_url`) | `config/services.php:26` | **App** — already consumed by `MyFatoorahGateway.php:10` via `MyfatoraService`. Leave; `payment.php` gateway block references it. |
| `shop.stripe` (`api_secret`, `webhook_secret`) + `shop.paypal` (`mode`, `sandbox`, `live`, `webhook_id`, `currency`, `notify_url`, `validate_ssl`) | `packages/marvel/config/shop.php:68,76` | **Marvel** — already read by `Marvel\Payments\Stripe.php:30` (`shop.stripe.api_secret`) and `Paypal.php:25` (`shop.paypal`). Adapters should read **both**: first `config('payment.gateways.stripe.api_key') ?? config('shop.stripe.api_secret')` so migration off `shop.*` is reversible. Do not duplicate raw secrets — alias in `payment.php` env calls is optional. |
| `shop.shop_url`, `shop.dashboard_url` | `packages/marvel/config/shop.php:13` | **Marvel** — PayPal `cancel/return_url` (`Paypal.php:86`). Must be set (`SHOP_URL`) or PayPal `createOrder` will be rejected. App `app.app_url_frontend` is used by `OrderController::checkoutCallback` for redirect — keep both. |
| `.env` canonical keys | `.env.example:99,104` | **Both** — `STRIPE_API_KEY`, `STRIPE_WEBHOOK_SECRET_KEY`, `PAYPAL_MODE`, `PAYPAL_SANDBOX_CLIENT_ID/SECRET`, `PAYPAL_LIVE_CLIENT_ID/SECRET`, `PAYPAL_WEBHOOK_ID`, `SHOP_URL`, `APP_URL_FRONTEND`, `DEFAULT_CURRENCY`, etc. Already listed; verify after wiring that no key is blank when provider is actually selected (factory should early-throw `RuntimeException` mapping to 500 if key blank). |
| `STRIPE_SUPPORTED_CURRENCIES`, `PAYPAL_SUPPORTED_CURRENCIES` | NEW env, e.g., in `config/payment.php` `supported_currencies` explode | **App** — like `myfatoorah.supported_currencies` (`payment.php:12` already parses `MYFATOORAH_SUPPORTED_CURRENCIES`). Allows per-gateway currency gating at checkout (`supportsCurrency`). No DB change. |

### Duplicate avoidance

* Do not define `STRIPE_API_KEY` in two places — `shop.stripe.api_secret` and `payment.gateways.stripe.api_key` should **alias** same `env('STRIPE_API_KEY')`. Pick one canonical read (adapter checks `payment.*` then falls back to `shop.*`).

* `paypal.currency` in `shop.php:76` (`PAYPAL_CURRENCY`) is currently dead (Paypal uses `Base.currency`). New `payment.gateways.paypal.supported_currencies` should replace it. Keep dead key for backward compat but document as ignored.

* `DEFAULT_CURRENCY` is `USD` in `shop.php:30` but `payment.php:6` falls back to `EGP` for MyFatoorah (`config('payment.default_currency','EGP')`); this inconsistency should be reconciled to `USD` or `KWD` matching `Settings.options['currency']` — mark as config debt, do not fix silently.

### Config caching

* `ShopServiceProvider.php:215` merges `shop.php` via `mergeConfigFrom`; `config:cache` will bake both. Changing `.env` after cache requires `php artisan config:clear` / `optimize:clear`. Push alert to runbook.

---

## 13. Checkout Response Contract

### Current `GatewayResult` DTO (`app/DTOs/GatewayResult.php:8`)

```php
class GatewayResult {
  public function __construct(
    public readonly bool $success,
    public readonly ?string $redirectUrl = null,
    public readonly ?string $gatewayTransactionId = null,
    public readonly ?float $amount = null,
    public readonly ?string $currency = null,
    public readonly ?string $status = null,
    public readonly ?string $errorMessage = null,
    public readonly ?array $rawResponse = null,
  ) {}
}
```

`PaymentCheckoutHandler.php:52` currently translates `GatewayResult.redirectUrl` → `ApiResponse 200 {url: response.url}`. Web verification: `OrderController::checkoutCallback` expects `gatewayTransactionId` == `InvoiceId`.

### What Marvel gateways actually return (verified against repo)

* **Stripe `Stripe::getIntent` (`Stripe.php:97-140`)** returns `['client_secret'=>..., 'payment_id'=>pi_..., 'is_redirect'=>false]`. No redirect URL. Amount is `round(amount,2)*100` minor units.
* **PayPal `Paypal::getIntent` (`Paypal.php:101`)** returns `['redirect_url'=>links[1].href, 'payment_id'=>order id, 'is_redirect'=>true]`.
* **MyFatoorah `MyFatoorahGateway::createInvoice` (`MyFatoorahGateway.php:52`)** returns `GatewayResult{redirectUrl=Data.InvoiceURL, gatewayTransactionId=Data.InvoiceId}`.

### Target shape (backward-compatible, additive)

Extend `GatewayResult` with optional Stripe fields so existing MyFatoorah callers ignore them:

```php
class GatewayResult {
  // ...existing...
  public readonly ?string $clientSecret = null, // Stripe PI client_secret
  public readonly bool $isRedirect = true,      // false for Stripe (JS confirm)
  public readonly ?string $paymentId = null,    // alias for gatewayTransactionId for PayPal clarity
}
```

Alternatively add `public readonly ?array $metadata = null` and put `client_secret` there. Explicit `clientSecret` is clearer.

`PaymentCheckoutHandler::handleOnlinePayment` after `gateway->createInvoice` will:

```php
$transaction = Transaction::create([..., 'gateway_response'=>['client_secret'=>$result->clientSecret, ...$result->rawResponse, '_callback_type'=>$request->type ?? 'web']]);

if ($gatewayName==='stripe' && $result->clientSecret) {
  return $this->apiResponse(CHECKOUT_SUCCESSFUL, 200, true, [
    'client_secret' => $result->clientSecret,
    'payment_id'    => $result->gatewayTransactionId,
    'gateway'       => 'stripe',
    'is_redirect'   => false,
  ]);
}
if ($gatewayName==='paypal') {
  return $this->apiResponse(CHECKOUT_SUCCESSFUL, 200, true, [
    'url'          => $result->redirectUrl,
    'payment_id'   => $result->gatewayTransactionId,
    'gateway'      => 'paypal',
    'is_redirect'  => true,
  ]);
}
// myfatoorah fallback — unchanged {url}
return $this->apiResponse(CHECKOUT_SUCCESSFUL, 200, true, ['url'=>$result->redirectUrl]);
```

**Critical:** existing MyFatoorah frontend that expects `{url}` still gets `{url}` from the default branch. New fields are **additive**. Frontend that does not know Stripe will not be called with `gateway=stripe` (factory default protects it). Content-type and `success:true` remain.

For PayPal the response is already `is_redirect true` with `url` — aligns with MyFatoorah so may even be `url` vs `redirect_url` alias; normalize to `url`.

Transaction persistence: Stripe's `client_secret` never needed beyond the checkout response (frontend consumes immediately); store `payment_id` in `transactions.gateway_transaction_id` and keep `rawResponse` for audit; PayPal's `redirect_url` not needed post-redirect.

Amount/currency echoed back in `GatewayResult` are from provider; `PaymentCheckoutHandler` validates `amount` against `order.total_price` after `verifyPayment` but not after `createInvoice` (create just trusts order amount — acceptable).

---

## 14. Stripe Target Flow (step → implementation → evidence)

```
1. Checkout POST v1/general/checkout  (routes/api.php:115)
   → OrderController::checkout (app:87)  EXISTING: validates, cart lookup, OrderService::addItemsInOrder pending
   → PaymentCheckoutHandler::handleOnlinePayment(order, round(total_price,2), gateway='stripe')
     EXISTING check: orderCurrency = order.currency_code ?? base_currency ?? default_currency
     NEW: gateway=stripe → factory->make('stripe') → StripeGateway (CREATE)

2. StripeGateway::createInvoice(Order, amount, callbackUrl, errorUrl)
   NEW: currency = order.currency_code ?? ... (NOT Base.currency — uses order snapshot, fixing Stripe's Base bug)
   NEW: validate ShopServiceProvider's Settings.currency fallback no longer needed
   NEW: if !supportsCurrency(currency) → GatewayResult{success:false, error PAYMENT_CURRENCY_UNSUPPORTED}
   NEW: idempotency key = order.tracking_number or Str::uuid() if Marvel Stripe doesn't set one (Marvel currently has none; PayPal does)
   REUSED: Marvel\Payments\Stripe::getIntent([
              amount, order_tracking_number=order.tracking_number, customer? if user authenticated → createPaymentCustomer path
              // Stripe.php:111 extracts via extract($data)
           ])
     Marvel: stripe->paymentIntents->create{ amount=Math.round(amount*100), currency, description 'Marvel Payment',
              automatic_payment_methods.enabled=true, metadata.order_tracking_number, customer? }
     Returns {client_secret, payment_id pi_...} (Stripe.php:130)
   NEW (adapter): map to GatewayResult{success:true, gatewayTransactionId=payment_id,
                clientSecret=client_secret, isRedirect:false, status pending,
                rawResponse: {client_secret, payment_id, amount, currency},
                amount, currency}

3. PaymentCheckoutHandler persists Transaction
   EXISTING pattern: Transaction::create{order_id, user_id, invoice_id=gatewayTransactionId,
                   payment_method=stripe, status pending, amount, currency,
                   gateway_transaction_id, gateway_response {client_secret, raw, _callback_type}}
   NEW: _callback_type = request->type ?? web (already in handler) so later callback can decide JSON vs redirect

4. Handler JSON → Frontend
   NEW: ApiResponse 200 {client_secret, payment_id, gateway:'stripe', is_redirect:false}
   EXISTING wrapper: success:true, message CHECKOUT_SUCCESSFUL

5. Frontend Stripe.js  (NEW — app frontend)
   const {client_secret} = resp.data;
   const {error, paymentIntent} = await stripe.confirmCardPayment(client_secret, {payment_method:{card}});
   // 3DS challenge if requires_action handled by Stripe.js automatically
   On success paymentIntent.status === 'succeeded' — frontend may immediately GET /orders/{tracking} to show success,
   but **authoritative completion is still webhook**, not JS callback

6. Provider: Stripe captures automatically (no extra capture)

7. Webhook push: POST api/v1/webhooks/stripe → StripeWebhookController (CREATE)
   Middleware api + throttle:webhook (NEW)
   Verify: payload = request->getContent(), sig_header = request->header('Stripe-Signature') (fix for $_SERVER), secret=config('shop.stripe.webhook_secret')
            Stripe\Webhook::constructEvent(payload, sig_header, secret) → throws on bad sig/ payload
            else event type: check data.object.object==='charge' OR payment_intent variations
            Lookup order: metadata.order_tracking_number or payment_intent id → order
            Validate amount (event.amount/100 vs order.total_price ±0.01) and currency vs order snapshot — block if mismatch (NEW guard)
            Dedup: WebhookEvent upsert {provider stripe, event_id=event.id} unique
            Delegate to StripeGateway::verifyPayment(payment_intent.id) to re-fetch authoritative status (defense in depth)
              StripeGateway::verifyPayment(id) REUSED: stripe->paymentIntents->retrieve(id) → status switch
                succeeded → GatewayResult{success:true, status paid}
                requires_action / requires_payment_method → {success:false, status failed/processing}
            If success and amount OK → PaymentCompletionService::complete(order, transaction, result)
              (see §9 completion spec)
            Return 200

8. Verification alternative: OrderController::checkoutCallback poll (EXISTING path reused)
   Frontend may call any checkout/callback?paymentId=pi_...? Adapter routes verify via StripeGateway::verifyPayment instead of MyFatoorah.

9. Order completion (NEW completion service via EXISTING primitives)
   DB::transaction lockForUpdate(Transaction, Order), idempotency status!==pending abort,
   Transaction status='paid', paid_at, gateway_response merged,
   Order payment_status='payment-success' (Marvel\Enums\PaymentStatus::SUCCESS), paid_at,
   OrderReservationService::commit(order) (deduct inventory once — idempotent via inventory_state),
   OrderService::finalizePromotionUsageAfterPayment(order) (increment coupon/promotion usage once),
   OrderService::changeOrderStatus(order.id,'completed', emit=false) + event PaymentSucceeded(order.fresh())
   Queues: GenerateInvoiceListener, SendPaymentSucceededNotification — already on meem-high.

Ownership tags:
  steps 2/7 delegation to Marvel\Payments\Stripe = REUSED
  steps 2/3/7 gateway mapping + Transaction + completion = NEW/App
  step 5 frontend JS = NEW/frontend
  step 8 callback reuse = EXISTING refactored to completion service
Security per step: step 2 idempotency key (new), step 7 signature + amount/currency + event dedup (fixes P0), step 9 lock + already-completed guard (existing).
```

---

## 15. PayPal Target Flow

```
1. Checkout POST v1/general/checkout (same as Stripe)
   → PaymentCheckoutHandler::handleOnlinePayment with gateway='paypal'
   EXISTING: currency validation against paypal supported_currencies
   NEW: factory->make('paypal') → PaypalGateway (CREATE)

2. PaypalGateway::createInvoice(Order, amount, callbackUrl, errorUrl, metadata)
   NEW: validate SHOP_URL non-blank else GatewayResult failure (fixes B-8)
   REUSED: Marvel Paypal::getIntent equivalent — but via adapter so amount respects order snapshot:
     paypalClient->setRequestHeader('PayPal-Request-Id', Str::uuid()) // Marvel Paypal.php:86 already does
     paypalClient->createOrder{
       intent CAPTURE,
       purchase_units[{invoice_id=order.tracking_number, amount{currency_code=order.snapshotCurrency, value=round(amount,2)}}],
       payment_source.paypal.experience_context{user_action PAY_NOW, payment_method_preference IMMEDIATE_PAYMENT_REQUIRED,
         cancel_url SHOP_URL+"/orders/{tracking}/payment", return_url SHOP_URL+"/orders/{tracking}/thank-you"}
     } → {redirect_url=links[1].href, payment_id=id is_redirect true}
   NEW: translate to GatewayResult{success:true, redirectUrl, gatewayTransactionId=payment_id, isRedirect:true}

3. Transaction pending (same as Stripe, status pending, payment_method paypal)

4. Handler JSON → Frontend
   ApiResponse 200 {url: redirectUrl, payment_id, gateway:'paypal', is_redirect:true}
   Frontend: window.location = url  (PayPal approval page)

5. Buyer approves (or cancels) on PayPal
   Approve → PayPal redirects GET {SHOP_URL}/orders/{tracking}/thank-you?token=...&PayerID=...
   Cancel  → GET {SHOP_URL}/orders/{tracking}/payment?token=...

   Frontend thank-you route may auto-call GET checkout/callback?paymentId=OrderID or rely on webhook
   Adapter supports both: return_url hit does NOT mean paid — capture still required

6. Capture + webhook
   Webhook POST api/v1/webhooks/paypal → PaypalWebhookController (CREATE)
     REUSED: gather headers {PAYPAL-AUTH-ALGO,CERT-URL,TRANSMISSION-ID/SIG/TIME} + webhook_id + event body
     paypalClient->verifyWebHook(verifyData) → must be SUCCESS (Paypal.php:160-175)
     Switch event_type:
       PAYMENT.CAPTURE.COMPLETED → PROCESSING/SUCCESS (after completion)
       PAYMENT.CAPTURE.PENDING   → PENDING/PENDING (stay pending, notify pending)
       PAYMENT.CAPTURE.CANCELLED → PENDING/FAILED
       PAYMENT.CAPTURE.REVERSED  → CANCELLED/REVERSAL (see §19 behavior — require inventory decommit plan)
     Dedup via WebhookEvent{paypal, event.id} unique
     Validate order identity: invoice_id via resource.invoice_id → Order tracking_number (Paypal.php:250)
     Validate amount (resource.amount.value vs order.total_price)
     Validate currency vs order snapshot
     For COMPLETED: call PaypalGateway::verifyPayment(invoice_id?)  → REUSED Paypal::verify → paypalClient->capturePaymentOrder(id)
                   (Paypal verify already captures if needed; webhook may arrive before or after capture — idempotent capture is ok)
     → PaymentCompletionService::complete if success

   Poll path: POST/GET checkout/callback?paymentId=PayPalOrderId → OrderController::checkoutCallback → factory->make(paypal)->verifyPayment
               → GatewayResult{success:status==='COMPLETED'|...} → completion service

7. Completion same as Stripe via PaymentCompletionService (lock, Transaction paid, Order completed, reservation commit, promotion finalize, PaymentSucceeded)

8. Cancel path: webhook CANCELLED → Order payment_status FAILED, status stays pending (Marvel PaymentStatus::PENDING? actually FAILED per switch), DO NOT commit inventory; coupon reservation remains ACTIVE → CancelUnpaidOrders will eventually release reservation + reclaim coupon.

Ownership:
  Step 2 delegation to Marvel Paypal::getIntent/ PayPalClient = REUSED
  Steps 2-7 adapter + Transaction + completion = NEW
  Step 5 frontend redirect handling = NEW but trivial (already the shop thank-you page)
  Step 6 signature validation = REUSED Marvel logic re-wrapped in Laravel controller (remove exit())
```

---

## 16. Webhook Design (critical — fixes every P0)

### Current Marvel webhooks (audited)

* **Stripe `Stripe.php:263 handleWebHooks`** — reads `php://input` + `$_SERVER['HTTP_STRIPE_SIGNATURE']`, verifies via `Stripe\Webhook::constructEvent(payload,sig,endpoint_secret=config shop.stripe.webhook_secret)` (`Stripe.php:268`). On throw `http_response_code(400); exit();`. Then `matchSucceededOrFailed` checks `data.object.object==='charge'` and builds `webhook_return_message {charge_status, payment_intent, amount, paid, payment_method_details.type, amount_captured, order_tracking_id=metadata.order_tracking_number}` → `paymentGatewayWebHookResponse` switch `succeeded/pending/failed`. **No amount/currency check** against order. Routes `POST api/v1/webhooks/stripe` via `Rest/Routes.php` (docs `api-contract.md:514`), `api` middleware.

* **PayPal `Paypal.php:160 handleWebHooks`** — collects 5 PayPal headers + `webhook_id` + event body, calls `paypalClient->verifyWebHook(verifyData)` where `shop.paypal.webhook_id`; on `verification_status!=='SUCCESS'` or `SignatureVerificationError` `http_response_code(400); exit();`. Switch `event_type` 4 values → `updatePaymentOrderStatus(resource.invoice_id→tracking, OrderStatus, PaymentStatus)` → `webhookSuccessResponse` (`PaymentTrait.php:358`). **Same lack** of amount/currency cross-check.

* **Success completion `PaymentTrait.php:358 webhookSuccessResponse`** — when `payment_status===SUCCESS`, does `DB::transaction lockForUpdate Order` idempotency (`status in completed,cancelled,refunded→return`), updates `payment_status=SUCCESS, paid_at`, commits inventory, finalizes promotion, `changeOrderStatus(...completed,false)`, emits `PaymentSucceeded`. Other statuses do simple `order_status/payment_status` update without lock. Uses `Schema::hasColumn` guards. Strong but missing amount/currency gate before it.

### Target webhook architecture

**Routes (additive, App-owned):**

```php
// routes/api.php
Route::prefix('v1')->middleware(['api'])->group(function(){
  Route::post('webhooks/stripe',  StripeWebhookController::class)->middleware('throttle:webhook')->name('api.webhooks.stripe');
  Route::post('webhooks/paypal',  PaypalWebhookController::class)->middleware('throttle:webhook')->name('api.webhooks.paypal');
});
```

Keep legacy `api/v1/webhooks/*` via Marvel but mark deprecated; new routes are registered under plain `api` not `api/v1` prefix so `RouteServiceProvider` vs `RestApiServiceProvider` dual registration is unambiguous. Cross-check with `Rest/Routes.php:14`.

**Middleware:** `api` + custom `throttle:webhook` (separate bucket). No `auth:sanctum`. Future: add IP allowlist middleware that reads `STRIPE_WEBHOOK_IPS`/`PAYPAL_WEBHOOK_IPS` CIDR — optional.

**Signature verification (do NOT invent — reuse SDK):**

* Stripe: inject `StripeWebhookVerifier` or call `Stripe\Webhook::constructEvent($payload=$request->getContent(), $sig=$request->header('Stripe-Signature'), $secret=config('shop.stripe.webhook_secret') ?? config('payment.gateways.stripe.webhook_secret'))`. Handle both `Stripe-Signature` header and legacy `$_SERVER['HTTP_STRIPE_SIGNATURE']`. On exception → `Log::warning('stripe webhook: invalid signature', ['event_id'=>...])` + `abort(400,'Invalid signature')`.

* PayPal: reconstruct `verifyData` exactly as `Paypal.php:163` (from headers + `$request->all()`) then delegate to `app(Marvel\Payments\Paypal::class)->paypalClient->verifyWebHook` or SDK directly; if provider `verification_status !== SUCCESS` → abort 400.

**Event validation:**

* Check `type` starts with `charge.`/`payment_intent.` (Stripe) or `PAYMENT.CAPTURE.`/`CHECKOUT.ORDER.` (PayPal). Reject unknown with 200 but logged (prevent infinite retry). Current Marvel checks `data.object.object==='charge'` — keep that gate but also support `payment_intent.succeeded` mapping to same completion.

**Order lookup:**

* Stripe: `orderTrackingNumber = data.object.metadata.order_tracking_number ?? data.object.payment_intent.metadata...` falling back to `charge.metadata`. Query `Order where tracking_number`. If not found → `Log::error 404` + return 200 with `unknown_order` metric (do not throw).
* PayPal: `trackingId = resource.invoice_id` (already used `Paypal.php:250`) → `Order where tracking_number`. Validate `tracking_number` looks like `ORD-` prefix to prevent integer enumeration.

**Amount & currency validation (NEW, before completion):**

```php
$providerAmount = stripe: (charge.amount ?? charge.amount_captured)/100; paypal: (float)resource.amount.value;
$providerCurrency = strtoupper(stripe: charge.currency ?? payment_intent.currency; paypal: resource.amount.currency_code);
$orderAmount = (float)$order->total_price; // or order.total_price snapshot; do NOT use request amount
$orderCurrency = strtoupper($order->currency_code ?? $order->base_currency_code ?? config('payment.default_currency','EGP'));

if (abs($providerAmount - $orderAmount) > 0.01) { Log::warning('amount mismatch', compact(...)); return abort(422,'Amount mismatch') or mark failed? }
// MyFatoorah already aborts + PaymentFailed for mismatch on live (OrderController.php:275) — replicate.
// On apitest/sandbox skip or log only (keep MyFatoorah pattern: test gateway ignores, live blocks).
```

If mismatch, do **not** call `PaymentCompletionService`; instead update `Transaction error_message=amount mismatch`, emit `PaymentFailed`, return `422` (provider will retry — but 4xx stops retry; decide). Stripe treats 4xx as no retry? Check: Stripe retries webhooks on non-2xx. So amount mismatch should be `200` with logged metric but terminal failure, not 422, to stop retry? MyFatoorah's mismatch does `400` path? Need consistency — recommend `200` with `Log::warning` and mark Transaction failed.

**Deduplication / idempotency:**

* Table `webhook_events` (see §18) with `unique(provider, event_id)`. On each webhook, `WebhookEvent::firstOrCreate([provider, event_id], ['payload'=>..., 'processed_at'=>null])`; if `wasRecentlyCreated===false` and `processed_at` already set → log `duplicate_event` metric + return `200 duplicate_ignored`.
* Fallback: `Transaction.gateway_transaction_id` unique + `Order.status!==pending` lock guard already exists (`OrderController.php:345` + `PaymentTrait.php:373`). Keep both.

**Replay handling:** Dedup table stops replay entirely. Provider signature replay without fresh `Stripe-Signature` timestamp would fail `constructEvent` timestamp tolerance (default 5 min) — no extra action.

**Duplicate event handling:** Same as above — early `200` before touching order.

**Failed-order handling:** `PaymentCompletionService` checks `Order.status` is `pending` (or `payment_status` pending) before committing. If order already `failed`/`cancelled`/`refunded`, skip commit, update Transaction to failed, log metric, return 200.

**Transaction locking & inventory/promotion:** Delegated to `PaymentCompletionService` (single place, see §9). Webhook never duplicates `OrderReservationService::commit` because `OrderReservationService` is idempotent via `inventory_state` check.

### P0/P1 issue × fix mapping

| Issue | Current | Why dangerous | Target | Where |
|---|---|---|---|---|
| Amount/currency unverified | none | underpayment completes order | guard before completion; mismatch → Transaction failed, no commit, alert | webhook controller + completion service |
| Raw `exit()` | `http_response_code(400); exit()` (`Stripe 277`, `Paypal 181`) | bypasses middleware, untrackable | `abort(400/422)` with Laravel response | new controllers |
| `$_SERVER['HTTP_STRIPE_SIGNATURE']` direct | `Stripe 267` | undefined index notice | `$request->header('Stripe-Signature')` with fallback | controller |
| No event-id dedup | none | double commit | `webhook_events` unique | migration + controller |
| No `throttle:webhook` | only `api` | DoS / replay flood | add `throttle:webhook` + optional IP | routes/api.php |
| No amount gate for legacy polling | *also* none | same via manual verify path | same guard in `PaymentCompletionService` invoked from polling too | service |
| `failed` not in idempotency | `PaymentTrait 373` | failed→completed via late webhook | add `failed` to completed set or distinct check | completion service |

---

## 17. Payment Security

### Stripe

* **Webhook signature verification** — reuse `Stripe\Webhook::constructEvent` with `shop.stripe.webhook_secret` (`shop.php:70`) + tolerance window. NEVER skip. Controller returns `400 Invalid signature` without touching DB.
* **Amount** — compare `charge.amount_captured/100` or `intent.amount/100` vs `order.total_price` with `±0.01` tolerance before commit. On live, block; on `apitest`/`test` Stripe may use wire fake amount — log only (mirror `OrderController.php:275 isTestGateway` pattern if needed).
* **Currency** — same comparison uppercased.
* **Payment intent ID** — must match `Transaction.gateway_transaction_id` or `PaymentIntent.payment_intent_info.payment_id`; on mismatch abort.
* **Event ID** — dedup via `webhook_events.event_id` (Stripe `event.id` e.g., `evt_...`), unique(provider, event_id). Rejections are 200 to stop provider retry vs 400 — choose 200+log.
* **Idempotency key** — Stripe create should send `Idempotency-Key: tracking_number` or `X-Idempotency-Key` header via `StripeClient` request options (implement in adapter).
* **Replay** — Stripe timestamp in signature handles 5-min replay window; plus dedup.
* **Duplicate events** — dedup table.
* **Secret handling** — `STRIPE_API_KEY`/`STRIPE_WEBHOOK_SECRET_KEY` never logged, never put in `gateway_response` (store only `client_secret` which is already public to frontend but expiring).

### PayPal

* **Webhook verification** — `PayPalClient->verifyWebHook(verifyData)` with 5 headers + `webhook_id` (`shop.php:90`) (`Paypal.php:160`). Must be `SUCCESS`; blank `webhook_id` should 400 in non-test mode.
* **Webhook ID** — stored in `.env PAYPAL_WEBHOOK_ID`; adapter throws config error if blank in prod (`PAYPAL_MODE live`).
* **Event ID** — PayPal `event.id` dedup (same table).
* **Capture ID / Order ID** — `resource.id` (capture) vs `resource.supplementary_data.related_ids.order_id` distinction; always compare `gatewayTransactionId`/`invoice_id` maps to Order, not arbitrary.
* **Amount/currency** — as above.
* **Replay/duplicate** — same dedup.
* **Reversal/refund** — handled as terminal (`REVERSED`→`CANCELLED/REVERSAL`). Do not auto-refund; surface refund failure. `PaypalGateway::refund` must verify reversal amount matches original.

### Orders

* **Order ownership** — `OrderController::checkoutCallback` currently does `Transaction where gateway_transaction_id` without ownership check; webhook does `Order where tracking_number`. Must gate on `order.user_id === transaction.user_id` or at least ensure `transaction.user_id` cannot be spoofed. Adapters do not expose cross-user lookup — webhooks are provider-signed so track number enumeration is mitigated by signature, but polling (`checkout/callback?paymentId=...`) must authorize: check `Transaction.user_id === auth()->id()` or require `tracking_number` ownership.
* **Tracking number enumeration** — rate-limit `checkout/callback` (`throttle:checkout`) and webhook (`throttle:webhook`); dedup + lock prevents state mutation even if enumerated.
* **Unauthorized mutation** — completion service requires `FOR UPDATE` lock; completed orders skip commit; failed orders do not re-commmit without explicit status reset.
* **Already-completed** — early return in completion service if `order.status in [completed,cancelled,refunded]` (add `failed` after decision).
* **Failed-order re-completion** — same guard + metric `late_webhook_after_failure` alert.

---

## 18. Order State Handling

### Current states (from `Marvel\Enums\PaymentStatus.php` + `OrderStatus.php`)

Enumerated in §15 of `STRIPE_PAYPAL_PAYMENT_FLOW_AUDIT.md`: `order-pending|processing|completed|cancelled|refunded|failed` + payment shadows `payment-pending|processing|success|failed|reversal|refunded|cash|cash-on-delivery|awaiting_for_approval`. `Order.php` uses `status` (main) + legacy `order_status`/`payment_status`.

### Mapping → completion service

| Outcome | `Transaction.status` | `Order.status` | `Order.payment_status` | `paid_at` | Next action |
|---|---|---|---|---|---|
| Successful gateway verify / webhook COMPLETED + amount OK | `paid` | `completed` (`changeOrderStatus('completed')`) | `payment-success` | `now()` | `OrderReservationService::commit`, `finalizePromotionUsageAfterPayment`, `PaymentSucceeded` |
| Stripe `requires_action` / PayPal `payer_action_required` / `pending` | stay `pending` | `pending` or `processing` (Marvel: `order-processing` variant) | `payment-processing` / `awaiting_for_approval` | null | keep reservation, wait |
| Declined / `requires_payment_method` / `CANCELLED` | `failed` | remain `pending` (modern) or `failed` (legacy `paymentFailed()`) | `payment-failed` | null | NOT commit, reservation stays |
| `REVERSED` (PayPal) | `failed` (or model `reversed` if added) | `cancelled` | `payment-reversal` | null (already paid) | See §20: ideally decommit inventory + reverse promotion — requires NEW handling |
| `REFUNDED` (Stripe `charge.refunded`, PayPal `PAYMENT.CAPTURE.REFUNDED`) | new `refunded` row or `failed`→`refunded` | `refunded` | `payment-refunded` | keep original | `refund()` via adapter, `ManageVendorBalance` on `payment-failed/cancelled` path (`OrderStatusManagerWithPaymentTrait.php:155`) |
| Duplicate webhook | `paid` already | `completed` already | stays success | unchanged | return 200 duplicate_ignored, metric, no state change |
| Late webhook after failure | Transaction already `failed` → leave failed, log late, may optionally transition `failed→paid` ONLY if re-verify shows actual paid and amount OK (+ admin alert) — policy decision | — | — |
| Webhook after cancellation (`status=cancelled`) | leave failed | remain cancelled | remain | null | 200 ignored |

Refund path: call `StripeGateway::refund` / `PaypalGateway::refund` (adapters forward to `refunds->create` / `refundCapturedPayment`) which returns `GatewayResult`; transaction history not automatically adjusted — needs `Transaction` `refunded` record + `Order` `status=refunded`.

---

## 19. Inventory Safety

### Current reservation/commit

Evidence: `Marvel\Database\Repositories\OrderRepository.php:268 validateAndLockStock` + `329 deductStock` in legacy, but modern path uses `app/Services/Inventory/OrderReservationService` (referenced `OrderController.php:159,345`). Lifecycle per `payment-audit.md`/`order-state-machine.md`:

* **Reserve:** at `OrderService::addItemsInOrder` sets `Order.inventory_state='reserved'` + `inventory_reserved_at`/`reservation_expires_at`.
* **Commit:** at payment success, `OrderReservationService::commit(lockedOrder)` inside `DB::transaction lockForUpdate` (`OrderController:351` MyFatoorah; `PaymentTrait:393` webhook). `commit` is **idempotent** via `inventory_state` check (if already `committed` skip).
* **Release:** `CancelUnpaidOrders` or expiry job sets `inventory_state='restored'` and returns stock.

### Required for Stripe/PayPal

* **Exactly-once commit:** funnel all success completions through **one** `PaymentCompletionService::complete` that wraps `FOR UPDATE` lock + idempotency (`status !== pending` or `inventory_state==='committed'`). Duplicate webhook or concurrent callback + webhook both serialize on the row lock; second caller returns early without calling `commit` again.

* **Failed payments do not commit:** both `Transaction status=failed` and order failure paths must **not** call `commit`. `PaymentCompletionService` only calls commit on `GatewayResult.success === true && amount OK`.

* **Reversal:** PayPal `REVERSED` currently would still have previously `committed` inventory. Need decommit step: if order already `completed` and `payment_status===SUCCESS` then `REVERSED` arrives, call `OrderReservationService::restore` or explicit compensation + `LogActivity`. This is a **new branch** — if not implemented for v1, document `REVERSED` as needing manual ops (do not auto-decommit).

* **Locking:** `OrderController:345` pattern `Transaction lockForUpdate` then `lockedOrder = transaction.order()->lockForUpdate()` — replicate in completion service. Webhook controllers should not touch `PaymentIntent` rows that App checkout doesn't create — they lock `Transaction`.

---

## 20. Promotion / Coupon Safety

Evidence: `app/Services/Payment/PaymentCheckoutHandler.php:36` `couponReservationService->reserve(order, coupon)` before `createInvoice`; `OrderController:355` `OrderService::finalizePromotionUsageAfterPayment(lockedOrder)` inside completion transaction; `OrderService::finalizePromotionUsageAfterPayment` increments `PromotionService::incrementUsage` and `OrderService::recordCouponUsage` (per `payment-audit.md:273`).

* **Reserve:** at checkout (before invoice), same for all gateways — idempotent per `order.coupon`.

* **Finalize:** **only** inside `PaymentCompletionService` on success, inside the same `DB::transaction` + lock. `finalizePromotionUsageAfterPayment` is already idempotent via `promotion_consumed` guard (per service doc). Stripe/PayPal must call **the same** method — no provider-specific promotion logic.

* **Failure:** reservation remains `coupon_reservation.status=reserved` until consumed or released by `CancelUnpaidOrders`/timeout. Webhook `CANCELLED/FAILED` must NOT finalize.

---

## 21. Idempotency Design

### Payment creation

| Provider | Current | Target |
|---|---|---|
| Stripe `PaymentIntent` | No `Idempotency-Key` (`Stripe.php:127` plain `paymentIntents->create`) | Adapter sends `Idempotency-Key: order.tracking_number` (or `order.id:gateway:amount`) via `StripeClient` request options `['idempotency_key' => ...]`. Also add DB unique `transactions.gateway_transaction_id` so a second checkout for same order cannot double-create. |
| PayPal Order | `PayPal-Request-Id: Str::uuid()` per call (`Paypal.php:86`) — already idempotent per request | Keep; also make deterministic per order if retry (`tracking_number` hash) vs uuid-per-call (paypal will treat two different ids as distinct — need consistent id per order attempt). Choose tracking_number-based uuid `Str::uuid5` over `Str::uuid`. |

Schema support: `PaymentIntent.payment_intent_info.payment_id` today is JSON; creation idempotency is provider-side. No migration needed beyond adding `idempotency_key` in adapter.

### Webhook processing

* **Dedup by `event_id`:** Stripe `event.id` (`evt_...`), PayPal `event.id` (`WH-...`), MyFatoorah no push event (callback `paymentId` dedup by `gateway_transaction_id`). Need `webhook_events` table so repeated deliveries (Stripe retries on non-200 for 72h, PayPal similar) do not double-process.

**Migration — `webhook_events`**

```
CREATE TABLE webhook_events (
  id BIGINT PK,
  provider VARCHAR(30) NOT NULL,  -- 'stripe'|'paypal'|'myfatoorah'
  event_id VARCHAR(255) NOT NULL,
  event_type VARCHAR(100),
  order_id BIGINT NULL FK orders,
  tracking_number VARCHAR NULL,
  payload JSON NOT NULL,
  signature_header VARCHAR NULL,
  processed_at TIMESTAMP NULL,
  created_at TIMESTAMP, updated_at TIMESTAMP,
  UNIQUE(provider, event_id),
  INDEX(provider, event_type),
  INDEX(order_id)
)
```

Retention: events are small; keep 90 days then prune via scheduled `PruneWebhookEventsJob`. Indexes enable dedup check `SELECT 1 FOR UPDATE` inside webhook transaction.

If migration is deferred, stripe/paypal dedup could reuse `PaymentIntent` JSON but `webhook_events` is strongly preferred — mark **REQUIRED** for production webhook safety, **NOT REQUIRED** for local smoke tests.

Transaction-side dedup: existing `transactions(gateway_transaction_id)` uniqueness + `Order.status` lock guard already handles idempotency even without webhook table, but event_id table handles the malicious/duplicate-with-different-amount cases before touching order.

---

## 22. Error Handling

| Scenario | Mapping | HTTP | Retryable? | Behavior |
|---|---|---|---|---|
| `PaymentGatewayFactory` unsupported gateway | `UnsupportedGatewayException` | `422 PAYMENT_GATEWAY_UNAVAILABLE` | no | handler returns `apiResponse(422)` (preserve `PaymentCheckoutHandler.php:28`). |
| `supportsCurrency()==false` | gateway check | `422 PAYMENT_CURRENCY_UNSUPPORTED` with `currency` placeholder | no | same as MyFatoorah (`MyFatoorahGateway.php:18`/`PaymentCheckoutHandler.php:41`). |
| Missing `STRIPE_API_KEY` / `PAYPAL_*` / `PAYPAL_WEBHOOK_ID` in live mode | `RuntimeException` at gateway construction or webhook verify | `500` checkout vs `400` webhook | no (config error) | fail fast with log `Log::critical('payment config missing', [gateway])`, do not leak secret. |
| Provider API transport failure (`Stripe\Exception\ApiConnectionException`, PayPal Guzzle timeout) | adapter catches, returns `GatewayResult{success:false, error='No response from gateway'}` | `500` checkout (provider) | yes (retryable by client) | `PaymentCheckoutHandler` returns 500 `ERROR_CREATING_INVOICE`; `verifyPayment` same pattern `MyFatoorahGateway:87`. |
| `PaymentIntent`/`Order` invalid request (bad currency, amount 0) | caught `InvalidRequestException` → GatewayResult failure | `422`/`400` | no | map to `apiResponse(422, e.message)` filtered (strip card number). |
| Checkout amount `<=0` | guard `round(total_price,2)<=0` → `500 FILED_TO_CREATE_ORDER_TRY_AGAIN` if still zero | 500 | no | `OrderController.php:96` already checks. |
| Malformed webhook (invalid JSON, missing fields) | webhook controller validates → `Log::warning` | `400` webhook invalid | no (provider will retry — but 400 stops retry) | return `400` if signature check fails; return `200` if JSON valid but unknown type (stop retry). |
| Webhook invalid signature | Stripe `constructEvent` throw / PayPal `verifyWebHook !== SUCCESS` | `401` or `400 Invalid signature` | no | same as Marvel (`Stripe 277`, `Paypal 181`) but via `abort`. |
| Unknown order (`tracking_number` not found) | lookup `first()` null | `200 unknown_order` (not 404) | no | log `warning` with event_id, metric `payment.webhook.unknown_order`. Return 200 to prevent retry storm. |
| Amount mismatch / currency mismatch | validation before completion | MyFatoorah live: `PaymentFailed` + `redirect failed` (400-like); webhook: update Transaction `error_message='Amount mismatch'`, metric `amount_mismatch`, Transaction failed, no commit, alert (do not commit) | no (terminal) but audit | replicate MyFatoorah `OrderController:275` test/live split: test gateway logs only, live blocks. Webhook returns `200` with logged mismatch to prevent retry. |
| Duplicate event (`webhook_events` duplicate) | unique hit | `200 duplicate_ignored` | no | early return. |
| Already-completed order | `status in [completed,cancelled,refunded,failed]` | `200 already_completed` | no | completion service early return. |
| Failed order + late success | ambiguous | `200 late_success_after_failure` | no | log critical + metric; optionally allow transition if policy permits (requires human). |
| Provider `REVERSED`/`refund failed` | provider refund API returns `false` | `500` on refund | yes | `Gateway::refund` returns `GatewayResult{success:false}`; controller returns 500 with reason. |
| `OrderReservationService::commit` throws (stock race) | exception inside `DB::transaction` | `500` checkout/callback | yes (retryable) | outer `try/catch` `Log::error`, rollback, metric `inventory.commit.failed`, return 500. |

Uniform envelope: `ApiResponse` (`Marvel\Traits\ApiResponse` / plus app) with `success`, `message` translation key, `data`, `errors`.

---

## 23. Database / Migration Impact

| Change | Required? | DDL |
|---|---|---|
| New `webhook_events` table for provider event deduplication | **REQUIRED** for webhook production safety | See §21 DDL: `provider, event_id UNIQUE`, `processed_at`, indexes. Small table, no FK strict needed. |
| Unique on `transactions.gateway_transaction_id` (if not already) | **REQUIRED if missing** | `UNIQUE(gateway_transaction_id, payment_method)` or at least unique on `gateway_transaction_id` where not null; check `database/migrations/*_create_transactions_table.php` existing index. |
| New column `transactions.client_secret` | **NOT REQUIRED** — store inside `gateway_response` JSON (`_stripe_client_secret`) |  |
| Change `orders.payment_status` enum values | **NOT REQUIRED** — `payment-awaiting_for_approval` etc. already there; `REVERSED` → `payment-reversal` already (`PaymentStatus::REVERSAL`) |  |
| Change `orders.status` | **NOT REQUIRED** |  |
| Add `stripe`-specific `payment_intents` columns | **NOT REQUIRED** — `payment_intents` unused by App path; adapters use `transactions` only |  |
| Add `paypal_webhook_id` column | **NOT REQUIRED** — env + `shop.paypal.webhook_id` already |  |
| Index on `orders.tracking_number` | **ALREADY EXISTS** (implicit unique in flows) — verify but not new |  |

No order/product migration; no money precision migration (existing `amount DECIMAL 10,2` suffices for USD adapt; for Stripe `amount*100` stays internal, not persisted).

---

## 24. Frontend Impact

**Current frontend contract (MyFatoorah)** (`api-desc/front/checkout/payment-audit.md` + `routes/api.php:115` response):

* Request: `POST v1/general/checkout {payment_method online, gateway myfatoorah, fulfillment_type..., products...}` — only `myfatoorah` ever sent.
* Response: `200 {success:true, message:'CHECKOUT_SUCCESSFUL', data:{url: InvoiceURL}}`. Frontend does `window.location = data.url`.
* Callback: `GET {APP_URL_FRONTEND}/{locale}/payment/success?payment_id=&order_id=` or `failed?...`.

**After — backward compatible additive contract (from §13):**

* Request: same shape, but `gateway` now may be `'stripe'` or `'paypal'` (query `eligiblePromotions` unchanged). `gateway` max 50 chars validation stays.
* Response:
  * `gateway=myfatoorah` → **unchanged** `{url}`.
  * `gateway=paypal` → also `{url, payment_id, gateway:'paypal', is_redirect:true}` — field `url` still present, so existing redirect logic still works if branch checks `is_redirect`. **Backward-compatible.**
  * `gateway=stripe` → `{client_secret, payment_id, gateway:'stripe', is_redirect:false}` plus optionally `url:null`. Existing MyFatoorah consumers that blindly do `location = data.url` would get `null` — but they never select `stripe` (default stays `myfatoorah`), so breakage is **zero** for current clients. New Stripe code path must branch on `is_redirect===false` and call `stripe.confirmCardPayment(client_secret, {payment_method:{card}})`.

**Does Stripe require frontend code?** **YES** — PaymentIntent flow requires Stripe.js confirm on client (except if using Stripe Checkout Sessions, which this repo does not). Must add `stripe-js` integration on `POST /checkout` success when `gateway stripe`: render card element and call `confirmCardPayment`. Alternative (no frontend) is to migrate Stripe to Checkout Sessions that do redirect — but Marvel's `Stripe.php:97` uses PaymentIntent, so adapter follows that; frontend JS is required.

**Does PayPal require frontend?** **Minimal** — approval redirect: `window.location = data.url` (same as MyFatoorah). Plus thank-you vs payment polling. No SDK JS needed if using Order redirect flow. Could optionally use PayPal Buttons JS but this repo's flow is redirect.

**Are API response contracts backward-compatible?** **Yes** — `data.url` remains for every redirect gateway; new fields `client_secret` etc. are extra keys on new gateway branch. Old clients that pin `gateway=myfatoorah` never see new shape. No break of MyFatoorah.

**Frontend env/config:** Needs `STRIPE_PUBLISHABLE_KEY` (pk_test_/pk_live_) not present in backend `.env.example` — add `MIX_STRIPE_PUBLISHABLE_KEY`/`NEXT_PUBLIC_STRIPE_PUBLISHABLE_KEY` naming per stack (this repo uses `MIX_*` for Pusher per `.env.example:172`). Document as frontend env.

---

## 25. Testing Matrix

Every cell is a **feature test** (HTTP) except unit rows; assertions include DB state (`transactions`, `orders`, `webhook_events`, `orders inventory_state`, promotion `promotion_consumed/coupon_consumed`, events fired).

### Factory

| # | Case | Request | Expected |
|---|---|---|---|
| F1 | `make('myfatoorah')` | `PaymentGatewayFactory::make('myfatoorah')` | `instanceof MyFatoorahGateway` |
| F2 | `make('stripe')` (case `Stripe`, ` STRIPE `) | lower trim | `StripeGateway` |
| F3 | `make('paypal')` (`PayPal`, `paypal`) | lower | `PaypalGateway` |
| F4 | unsupported `make('unknown')` | match default | throws `UnsupportedGatewayException` → 422 `PAYMENT_GATEWAY_UNAVAILABLE` |
| F5 | missing config `gateway class blank` | config filtered | 500 logged |

### Checkout (authenticated)

| # | gateway | Fulfillment | Ops | Assertions |
|---|---|---|---|---|
| C1 | stripe | delivery | `POST v1/general/checkout {gateway stripe}` with cart | 200 `client_secret+payment_id`, `transactions` pending stripe, `order pending`, inventory RESERVED |
| C2 | paypal | delivery | same `gateway paypal` | 200 `url`+`payment_id`, pending paypal |
| C3 | myfatoorah regression | delivery | `gateway myfatoorah` | 200 `url`, pending myfatoorah (existing behavior) |
| C4 | stripe | bad currency | order currency not in stripe supported_currencies | 422 `PAYMENT_CURRENCY_UNSUPPORTED` |
| C5 | paypal missing SHOP_URL | paypal create fails, GatewayResult failure | 422/500 `missing SHOP_URL` |
| C6 | invalid gateway | `gateway=unknown` | 422 unsupported |
| C7 | checkout after cart consumed | double checkout fast | second 400 `CART_NOT_FOUND` |

### Stripe

| # | Case | Trigger | Order/Transaction/Stock/Promotion assertions |
|---|---|---|---|
| S1 | PaymentIntent `succeeded` path | create → confirm via Stripe test 4242 → poll `retrievePaymentIntent` succeeded | order → completed, payment_success, `Transaction paid`, stock committed, promotion finalized, `PaymentSucceeded` fired |
| S2 | `requires_action` (3DS test 4000002500003155) | challenge | order processing/processing, not committed |
| S3 | `requires_payment_method` declined (4000000000009995) | fail | order failed/payment_failed, not committed |
| S4 | webhook `charge.succeeded` valid sig | `POST webhooks/stripe` signed with `STRIPE_WEBHOOK_SECRET_KEY`, event charge | completed (if not already), `webhook_events` recorded |
| S5 | webhook invalid signature | bad `Stripe-Signature` | 401 no state change |
| S6 | amount mismatch (order 100, charge 99) | forged charge amount | 200 logged mismatch, Transaction failed, order stays pending, no commit |
| S7 | currency mismatch (order KWD vs charge USD) | forged | same |
| S8 | duplicate webhook same `event.id` | send twice | second 200 duplicate_ignored, no double commit |
| S9 | late webhook after already-failed order | fail then succeeded webhook | 200 late_success_after_failure, configurable do-not-reopen |
| S10 | already-completed order + replay | completed then same event | 200 already_completed, no side effect |
| S11 | confirmation idempotency: two creates for same order | `POST checkout` twice with same idempotency key | one `pi_`, second returns same or error depending on Stripe behavior + local dedup |

### PayPal

| # | Case | Trigger |
|---|---|---|
| P1 | order creation returns approval URL | checkout paypal → 200 `url` links[1].href |
| P2 | webhook `PAYMENT.CAPTURE.COMPLETED` valid | `POST webhooks/paypal` with valid headers+webhook_id → verifyWebHook SUCCESS → completed |
| P3 | webhook `PAYMENT.CAPTURE.CANCELLED` | pending/failed |
| P4 | webhook `REVERSED` after completed | cancelled/reversal — see decommit policy |
| P5 | invalid webhook signature | PayPal AUTH headers bad → 400 |
| P6 | amount mismatch | same guard |
| P7 | currency mismatch | same |
| P8 | duplicate event id | duplicate_ignored |
| P9 | `PAYMENT.CAPTURE.PENDING` | stays pending |
| P10 | capture via `verifyPayment` poll (`checkout/callback?paymentId=OrderId`) | poll paypal sandbox OrderId → completed |
| P11 | cancel_url visited (buyer cancel) | stays pending, cancel metric |

### Order lifecycle / cross-cutting

| # | Case | Assertions |
|---|---|---|
| O1 | Successful payment → status, paid_at, inventory committed once, promotion/coupon finalized, Invoice generated | DB checks + queue fake |
| O2 | Failed keeps pending/failed, inventory NOT committed, promotion NOT finalized, cart not cleared |
| O3 | Duplicate webhook does not double-increment promotion/inventory | `lockForUpdate` + `wasRecentlyCreated` guard on webhook_events |
| O4 | `PaymentFailed` vs `PaymentSucceeded` events fired correctly |
| O5 | MyFatoorah regression: amount/currency mismatch blocking on live, log-only on test (existing test `PaymentCallbackStressTest` extended) |
| O6 | Missing gateway 500→ correct message without leaking secret |

All webhook tests must use provider signature helpers: Stripe `Stripe\Webhook::generateTestHeaderString` (test) / `payload+secret`; PayPal mock `verifyWebHook` to return SUCCESS for valid + 400 else.

---

## 26. Runtime Certification

### Local (prove in sandbox before any production push)

```
1. config clear
   php artisan config:clear && cache:clear
   confirm Settings::first()->options['currency'] matches DEFAULT_CURRENCY usage (USD for Stripe sandbox)

2. queue setup
   QUEUE_CONNECTION=database, php artisan queue:work --queue=meem-high,meem-medium --tries=3 --timeout=1200
   or ./sail artisan queue:work ...

3. Stripe CLI
   stripe login
   stripe listen --forward-to localhost:8000/api/v1/webhooks/stripe  # matches new route prefix
   copy whsec_... to /tmp/env.STRIPE_WEBHOOK_SECRET_KEY then config:clear

4. PayPal sandbox + ngrok
   APP_URL=http://localhost:8000  SHOP_URL=http://localhost:3000  (sandbox allows http)
   For webhooks: ngrok http 8000 → https://{id}.ngrok.io
   In developer.paypal.com sandbox app add webhook https://{id}.ngrok.io/api/v1/webhooks/paypal with CAPTURE.* events
   copy Webhook ID → PAYPAL_WEBHOOK_ID

5. Checkout stash
   artisan tinker: create user/cart/product fixtures (or use existing seed)
   POST v1/general/checkout → assert respective shapes
   Stripe: with Test card 4242... succeed, 4000 0025 0000 3155 3DS, 4000 0000 0000 9995 fail
   PayPal: approve with buyer sandbox account

6. Webhook forwarding
   For Stripe via CLI automatic; for PayPal via ngrok.

7. Verify DB + queue
   SELECT * FROM transactions WHERE gateway_transaction_id LIKE 'pi_%' / 'WH-%' ; SELECT * FROM orders WHERE tracking_number ; SELECT * FROM webhook_events ;
   Storage: inventory_state transition reserved→committed, promotion_consumed, paid_at, status completed.
   Queue: php artisan queue:failed (should be 0); dispatch PaymentSucceeded ok.

8. Matrix: run automated tests (§25) locally; focus F/C/S/P/O tables.

9. Frontend
   Stripe: NEXT_PUBLIC_STRIPE_PUBLISHABLE_KEY=pk_test_...  npm run dev → card element confirm
   PayPal: redirect assert.
```

### Production (single gateway at a time)

```
1. config:cache & route cache
   php artisan config:cache && route:cache
   verify: php artisan route:list --path=webhooks | grep stripe|paypal ; php artisan config:show shop.stripe (tinker)

2. queue workers
   supervisor: deploy/supervisor/laravel-worker-catch-high.conf (or meem-high) with 2 procs, --sleep=3 --tries=3 --max-time=3600
   verify: supervisorctl status ; queue:monitor

3. scheduler
   cron * * * * * php /srv/artisan schedule:run
   PaymentReconciliationJob + CancelUnpaidOrders scheduled (every 5 min per Kernel)

4. provider dashboards
   Stripe: API keys live sk_live + Create webhook https://{backend}/api/v1/webhooks/stripe events charge.* → copy whsec_live_ → .env
   PayPal: App live Client ID/Secret → .env PAYPAL_MODE=live PAYPAL_LIVE_* PAYPAL_WEBHOOK_ID live; webhook https://{backend}/api/v1/webhooks/paypal CAPTURE.* events

5. HTTPS + ShopURL
   SHOP_URL https://shop.prod, APP_URL_FRONTEND https://shop.prod/{locale}, backend TLS valid

6. Small-value live transaction (canary)
   POST v1/general/checkout gateway=stripe amount=1.00 real card (Stripe test card doesn't work live — use real visa with 3DS or small real paypal sandbox? For live gateways use minimal real charge and refund immediately)
   PayPal similar with 1 EGP.
   Assert: order completed, invoice pdf, webhook_events, paid_at.

7. Monitoring (see §28)
   Watch: amount_mismatch counter, payment.verification failure, webhook 4xx rate, queue failed.

8. Rollback
   Per-gateway feature flag: set config('payment.gateways.stripe.enabled', false) → PaymentGatewayFactory throws 503/422 again; fallback to myfatoorah. No DB rollback needed.

Do NOT run destructive artisan like migrate:fresh, queue:flush as certification.
```

---

## 27. Production Rollout (safe, myfatoorah as default)

```
Phase 0 — Current state (today)
  Only myfatoorah live; stripe/paypal disconnected. No config change.

Phase 1 — Code wiring (no user-facing change)
  Land adapters + factory arms + completion service + DTO + handler branching behind env flag PAYMENT_STRIPE_ENABLED/PAYPAL_ENABLED=false.
  Gate: PR review + CI green (factory+checkout unit tests).

Phase 2 — Security/idempotency hardening
  Webhook signature + amount/currency guards + webhook_events migration + FOR UPDATE service.
  Gate: webhook invalid sig / amount mismatch / duplicate test suite green.

Phase 3 — Automated tests
  Full §25 matrix green, myfatoorah regression proven.
  Gate: coverage threshold; PaymentCallbackStressTest extended for new gateways.

Phase 4 — Stripe sandbox (if exists) certification
  Stripe CLI + ngrok local cert (§26). Fix any currency mismatch (USD vs KWD).
  Gate: local S1,S4–S11 green; tinker spot-check on webhook_events.

Phase 5 — PayPal sandbox
  Verify redirect + capture + webhook COMPLETED and REVERSED paths.
  Gate: P2,P4 green; SHOP_URL non-blank.

Phase 6 — Production configuration (gated)
  Deploy infra: config/payment.php live gateways with enabled=false, dashboard live webhooks (disabled) staged.
  No behavior change yet; myfatoorah continues.

Phase 7 — Stripe production enable (canary)
  Flip PAYMENT_STRIPE_ENABLED=true on one node, monitor webhook 4xx/5xx, run small live $1 transaction and refund.
  Gate: completion metrics healthy, average latency < MyFatoorah, 0 failed webhooks for 1h.

Phase 8 — PayPal production enable (canary)
  Same pattern, separate flag.
  Gate: approval URL latency ok, capture success rate same as sandbox.

Rollback at any gated phase: flip flag false or `php artisan config:clear` with previous release; `PaymentGatewayFactory` immediately reverts to 422 for that gateway; no DB migration to reverse.
```

---

## 28. Observability (what to log/watch, never secrets)

**Log / emit (structured JSON, key=value):**

* `gateway={myfatoorah|stripe|paypal}` — every payment path.
* `order_id, tracking_number, transaction.id, gateway_transaction_id` (`pi_`, PayPal OrderID, InvoiceId).
* `provider_payment_id` (Stripe `pi_/ch_`, PayPal `capture id`).
* `webhook.event_id, event_type` (Stripe `evt_...`, PayPal `WH-...`).
* `amount_expected, amount_provider, currency_expected, currency_provider, mismatch_delta`.
* `transition {pending→completed|failed|reversed}` and `payment_status`.
* `result {success|failed} + rawResponse summary (status, InvoiceStatus)` not full payload.
* Metrics: `payment.callback.verification {success|failed}`, `payment.webhook {received|verified|duplicate|unknown_order|amount_mismatch|late_success}`, `inventory.commit {ok|already_committed|failed}`, `promotion.finalize {ok|already_consumed}`.
* Queue: `payment_reconciliation {checked|recovered}`.
* Failure context: `retry_after`, `http_status` for provider error without leaking key.

**Monitor/alert:**

* `payment.webhook.400` spike → webhook secret rotation or provider change.
* `amount_mismatch` >0 → hard alert (P0).
* `duplicate_event` rate → normal at provider retry level; alert if >2× retry count.
* `queue:failed` for GenerateInvoiceListener / webhook jobs.
* Dashboard: revenue by `gateway` dimension.

**NEVER log:**

* `STRIPE_API_KEY`, `STRIPE_WEBHOOK_SECRET_KEY`, `PAYPAL_*_SECRET`, `PayPal access_token`, `Stripe\Webhook secret`, full card details (PAN, CVC), raw `Authorization` header, entire encrypted payload before verification (log truncated summary), payer email only at debug level.

---

## 29. Documentation Impact (post-implementation — do not touch until `Update API File`)

* `docs/api-contract.md` §Checkout & Orders (current `POST v1/general/checkout` response shows only `{url}`) → add stripe/paypal branches (`client_secret` vs `url`) and `gateway` enum (`myfatoorah|stripe|paypal`).
* `api-desc/front/checkout/payment-audit.md` (if kept) → branch description or mark MyFatoorah-only with pointer.
* `STRIPE_PAYPAL_PAYMENT_FLOW_AUDIT.md` (§RECOMMENDED keep) → companion update referencing new routes.
* `README.md` / deployment doc → env table: `STRIPE_API_KEY`, `STRIPE_WEBHOOK_SECRET_KEY`, `STRIPE_SUPPORTED_CURRENCIES`, `PAYPAL_MODE`, `PAYPAL_SANDBOX/LIVE`, `PAYPAL_WEBHOOK_ID`, `SHOP_URL`.
* `docs/api/stripe.md` + `docs/api/paypal.md` new module docs (if `docs/api/{module}.md` pattern used for Phase 18) — include `UPDATED` example from implementation, not existing.
* `deploy/supervisor/*` worker docs → note webhook queue grouping.
* Frontend integration doc (`api-desc/.../frontend.md`) → new `stripe-js` section and PayPal approve-redirect diagram.

---

## 30. Risk Register

| ID | Risk | Severity | Probability | Mitigation | Blocking? |
|---|---|---|---|---|---|
| R-P0-1 | Underpayment accepted (amount/currency not checked in webhook) → revenue loss | P0 | High if no fix | Amount/currency validation before completion; webhook_events dedup; live-only block + alert | **YES** |
| R-P0-2 | Order hijack via tracking_number enumeration on callback/webhook | P0 | Medium | Signature gate (provider-signed) for webhook; callback requires auth + ownership check (`transaction.user_id===auth`) | **YES** |
| R-P1-1 | Duplicate webhook double-commit inventory/promotion | P1 | High (Stripe/PayPal retry on non-2xx) | `webhook_events` unique + `FOR UPDATE` lock + completion service idempotency | YES |
| R-P1-2 | Stripe no Idempotency-Key → duplicate PaymentIntent on retry → double charge risk | P1 | Medium | Send Idempotency-Key in adapter | no block initially, but fix before same-order retry |
| R-P1-3 | Currency mismatch: shop is KWD but Stripe/PayPal not supporting KWD | P1 | High in this shop | `supported_currencies` gate at checkout 422; test with KWD live card path | YES |
| R-P1-4 | `REVERSED` after `COMPLETED` leaves stock deducted | P1 | Low | Policy: manual refund + `restore` job or mark as manual-ops queue | no immediate block |
| R-P1-5 | PayPal `SHOP_URL` blank → PayPal `createOrder` 400 for every checkout | P1 | Medium locally | Adapter throws early 422 with clear message; CI env validation | YES locally |
| R-P2-1 | `failed` not in idempotency guard → failed→completed via late webhook | P2 | Medium | Include `failed` in status guard or record explicit terminal check | no |
| R-P2-2 | `$_SERVER['HTTP_STRIPE_SIGNATURE']` undefined → 500 | P2 | Low | Use request header | no |
| R-P3-1 | `config:cache` stale after env change → webhook secret stale → 400s | P3 | Medium ops | Runbook `config:clear` + `Settings` cache clear on deploy | no |

---

## 31. Exact Implementation Order (WHY-before-next)

**1. DTO & contract preparation (WHY first: nothing compiles without it)**

Extend `app/DTOs/GatewayResult.php:8` with `clientSecret, isRedirect`. Keeps downstream handler compilation flexible. If skipped, adapters for Stripe cannot type-check.

**2. Gateway adapters + config stubs (WHY second: factory + handler depend on adapters)**

Create `StripeGateway.php` + `PaypalGateway.php` that implement `PaymentGatewayContract` and delegate to `Marvel\Payments\*`. Add `config/payment.php` gateway blocks (still disabled). Gate: adapter unit tests `supportsCurrency`/`name`.

**3. Factory (WHY before handler: handler calls factory)**

Modify `PaymentGatewayFactory.php:9` to resolve stripe/paypal. Gate: factory `UnsupportedGatewayException` tests still pass.

**4. Handler branching (WHY before controller: checkout path triggers handler)**

Modify `PaymentCheckoutHandler.php:26` to branch redirect vs client_secret and always create `Transaction pending`. Re-run MyFatoorah regression.

**5. Completion service + refund stubs (WHY before webhooks: webhooks delegate here)**

Create `PaymentCompletionService.php` — extracts `DB::transaction lockForUpdate` + guards into one place. Refactor `OrderController::checkoutCallback` (MyFatoorah) to call it first; prove MyFatoorah still completes via callback. Gate: myfatoorah callback regression.

**6. Webhook controllers + routes + migration (WHY after completion: they call it)**

Create `StripeWebhookController` + `PaypalWebhookController`, `webhook_events` migration, `routes/api.php` webhook endpoints `throttle:webhook`. Gate: invalid sig tests.

**7. Security/idempotency hardening (WHY after wiring but before enable: fixes P0 only testable on wired path)**

Add amount/currency validation, event-id dedup, `failed` guard, `throttle`. Gate: amount mismatch + duplicate matrix.

**8. Order/inventory/promotion finalization audit (WHY after: needs wired path)**

Verify `OrderReservationService::commit` idempotency and `finalizePromotionUsageAfterPayment` same path for all gateways; adjust reversal handling. Gate: inventory tests.

**9. Tests (WHY after: code exists)**

Write full §25 matrix (factory, checkout, stripe, paypal, order). Gate: CI green.

**10. Runtime verification (WHY after tests: proves real provider secret/routing)**

Local sandbox (§26) Stripe CLI + ngrok + real checkout → webhook. Gate: evidence of `webhook_events` + `Transaction paid` + `Order completed`.

**11. Production rollout (WHY last: config/dashboards only after local proven)**

Per §27 phase 6-8 flag flip, dashboard webhooks, small live charge, monitor, queue. Gate: Definition of Done (§32).

Skipping order, e.g., writing webhooks before completion service, would duplicate transactional code between MyFatoorah callback and webhooks and force later re-factor; putting config last would not allow tests to run; putting frontend before adapter would have no API to consume.

---

## 32. Definition of Done

**Stripe is NOT DONE unless:**

* `PaymentGatewayFactory::make('stripe')` returns `StripeGateway` without throw.
* `POST v1/general/checkout {gateway stripe}` creates `Transaction pending` with `gateway_transaction_id pi_...` and returns `200 {client_secret, payment_id, is_redirect false}` (evidence: request logs + DB).
* Frontend with `Stripe.js` can `confirmCardPayment(client_secret)` and complete 3DS.
* Stripe webhook `POST api/v1/webhooks/stripe` with valid `Stripe-Signature` is verified via `Stripe\Webhook::constructEvent` (evidence: 200 on valid, 401/400 on invalid, signature helper used in tests).
* `payment_id`, `event_id`, `amount`, `currency` all validated vs `Order` snapshot before commit (evidence: amount-mismatch test leaves order pending).
* Duplicate `event.id` is ignored (second `200 duplicate_ignored`, no double order completion).
* Order `status completed`, `payment_status payment-success`, `paid_at` set, `Transaction status paid`, `inventory_state committed` exactly once, `promotion_consumed`/`coupon_consumed` true, `PaymentSucceeded` event fired (evidence: DB + `Event::fake` tests + queue job executed).
* Failure path (`requires_payment_method`, declined card) results in `Transaction failed`, order not completed, inventory not committed.
* Production-like runtime test (Stripe test 4242 + webhook via STRIPE_CLI or live small charge) passes and is recorded.
* MyFatoorah regression: `POST v1/general/checkout {gateway myfatoorah}` still completes via `checkout/callback` path and tests still green.

**PayPal is NOT DONE unless same checklist with PayPal specifics:**

* Factory resolves `paypal`, checkout returns `url` + `payment_id` `is_redirect true`.
* `SHOP_URL` validation enforced.
* Browser approval flows to `return_url` → capture → webhook `PAYMENT.CAPTURE.COMPLETED` verified via `PayPalClient->verifyWebHook` with `PAYPAL_WEBHOOK_ID`.
* Same amount/currency/duplicate/already-completed guards.
* Reversal/refund path documented (even if manual).

**Overall:** migration exists (if webhook_events), docs updated per §29, runbooks contain webhook URLs + secret names, queue workers + scheduler proven live.

---

## 33. Open Questions / Unverified Evidence (must close before coding)

* **Q1 Route registration of new webhooks** — `RestApiServiceProvider.php:13` registers `api/v1` group and `RouteServiceProvider` registers `routes/api.php` under `api` — does `php artisan route:list --path=webhooks` show `api/v1/webhooks/stripe|paypal` already or `api/v1/webhooks`? UNVERIFIED without runtime `route:list`; new routes should be `api/v1/webhooks/stripe` or `api/webhooks/stripe` — decide after list.

* **Q2 `OrderRepository::storeOrder` amount contract** — full recompute vs trust `paid_total` (P0-2) requires line-by-line read of `OrderRepository.php:112-259` full source which was map-truncated in ctx. Verify before trusting amount.

* **Q3 Shop frontend vs App frontend URL key** — `OrderController.php:362` uses `config('app.app_url_frontend')` but `.env.example` only shows `SHOP_URL` + `DASHBOARD_URL`; is `app.app_url_frontend` defined in `config/app.php`? UNVERIFIED grep.

* **Q4 Stripe Checkout Sessions vs PaymentIntent** — this plan follows existing `Stripe.php:97` PaymentIntent. If product decision is redirect-only, alternative is Stripe Checkout Sessions (less frontend JS) — confirm stakeholder intent before locking frontend scope.

* **Q5 PayPal `currency` mapping** — `Paypal.php:86` uses `Base.currency` not `order.snapshot`; target plan switches to order snapshot — does PayPal sandbox accept `KWD`? UNVERIFIED; may need to gate PayPal to `USD` subset and instruct KWD→USD fallback policy or keep PayPal disabled for KWD orders.

* **Q6 Decimal handling** — Stripe persists `*100` ints but `Order.total_price` is decimal; webhook amount must be divided. PayPal `round(amount,2)` is string/decimal — ensure comparison tolerance ±0.01 covers both.

* **Q7 `payment_intents` vs `transactions`** — Adapter currently uses `transactions` only; legacy admin tools querying `payment_intents` may need migration/dual-write. Verify whether admin dashboard reads `payment_intents` for order detail.

* **Q8 `failed` guard inclusion** — `PaymentTrait.php:373` missing `failed` may be intentional (failed orders allowed to be re-completed) — check business rule before adding to guard.

---

## 34. Final Architecture Decision

```
RECOMMENDED ARCHITECTURE: App PaymentGatewayContract adapters over existing Marvel SDK implementations
  App (factory → handler → controller) owns selection, validation, transaction persistence, and the single canonical completion service;
  Marvel (Stripe/PayPal classes) owns provider SDK construction and raw webhook signature math, wrapped via app/gateway/StripeGateway + PaypalGateway.

WHY:
  Reuses already-correct SDK wiring (stripe/stripe-php 13.1.0, srmklive/paypal verifyWebHook, amount scaling, PayPal-Request-Id) without duplicating ~700 LOC;
  satisfies the only contract callers ever import (PaymentGatewayContract) so MyFatoorah, Stripe and PayPal share one checkout handler, one Transaction table, one callback/webhook path, one inventory+promotion completion, and one test suite;
  preserves Marvel vendored-kernel ownership (no move, no fork);
  keeps default_gateway myfatoorah and additive backward compatibility.

WILL CHANGE:
  App layer only — extend GatewayResult, create StripeGateway/PaypalGateway adapters, PaymentCompletionService, Stripe/PayPal webhook controllers, webhook_events migration, config/payment.php entries (with fallback to shop.*), routes/api.php webhooks, factory match arms, handler branching for client_secret vs redirect, hardening (amount/currency + dedup + idempotency + throttle).
  Tests and docs (payment matrix, env table, dashboard steps).

WILL NOT CHANGE:
  Existing MyFatoorah flow, Order/Transaction models, legacy POST api/v1/orders path still works for admin, packages/marvel/src/Payment/Stripe.php + Paypal.php + PaymentInterface + Base + ShopServiceProvider not edited, legacy api/v1/webhooks remain (deprecated), orders already completed, queue workers / scheduler shape only extended not replaced, shop.php secrets single source.

PRODUCTION BLOCKERS BEFORE ENABLE:
  PaymentGatewayFactory whitelist + config missing (immediate code blocker), webhook amount/currency verification missing (P0), event-id dedup missing (P1), adapter + handler `client_secret` vs `url` branching absent, missing webhook routes; PayPal requires non-blank SHOP_URL and webhook_id in live.

IMPLEMENTATION RISK: MEDIUM
  Technical risk HIGH if wiring is attempted provider-by-provider without the single completion service (duplicate logic), but with the adapter+completion design and gated flag rollout behind comprehensive tests the residual risk is LOW-MEDIUM — three additive components, no cross-provider state machine change, MyFatoorah regression easily provable.

READY FOR IMPLEMENTATION: YES — subject to closing UNVERIFIED items Q1–Q8 above with `route:list` + `tinker Settings` + business confirmation of KWD-vs-USD/Stripe-vs-Checkout preference.
```

---

*End of plan — no source modified, no env changed, no migration created, no secrets disclosed; every decision is anchorable to the file:line evidence cited above.*

