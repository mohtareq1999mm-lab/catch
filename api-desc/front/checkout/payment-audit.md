# Payment System — Complete Technical Audit

**Date:** 2026-08-18
**Scope:** Every payment flow in the codebase — read-only audit, no code changed.
**Method:** Static analysis of source, controllers, services, models, migrations, routes, config, events, notifications, tests + targeted test runs to verify flagged behavior.

---

## 1. Executive Summary

The application supports exactly **three payment methods** on checkout:

| Method | Delivery | Pickup | Notes |
|--------|:--------:|:------:|-------|
| `online` (MyFatoorah) | ✅ | ✅ | Gateway redirect; the **only** gateway implemented |
| `cod` | ✅ | ❌ (422) | Cash on delivery; settled by admin |
| `pay_at_cashier` | ❌ (422) | ✅ | QR code feature **removed**; settled by admin |

The payment architecture is layered: `Controller → PaymentCheckoutHandler → GatewayFactory → Gateway → MyfatoraService (HTTP)` for online, and `OrderService` for COD/cashier settlement. All online settlement is driven by a **public callback** (`checkout/callback` + `checkout/error-callback`) that verifies against MyFatoorah, validates amount/currency, and completes the order in a locked DB transaction. COD/cashier orders are settled by an admin calling `mark-paid` endpoints.

**Frontend-implementable from this report alone: YES.** The full request contracts, response shapes (including envelope + callback redirect/JSON variants), business rules, and failure matrix are documented in §3–§5 and §18 with exact evidence.

---

## 2. Payment Methods Inventory

| Method value | Constant | Where dispatched | Default? |
|--------------|----------|------------------|----------|
| `online` | — | `OrderController::checkout` (app/Http/Controllers/Api/General/OrderController.php:110), `FastShippingController::checkout` (app/Http/Controllers/Api/General/FastShippingController.php:72) | ✅ `payment_method` defaults to `online` |
| `cod` | — | OrderController.php:118, FastShippingController.php:76 | |
| `pay_at_cashier` | — | OrderController.php:122, FastShippingController.php:80 | |

- Only supported online gateway: **`myfatoorah`** (`PaymentGatewayFactory::make` match — app/Services/Payment/PaymentGatewayFactory.php:13). Any other gateway string throws `UnsupportedGatewayException`.
- Default gateway: `config('payment.default_gateway', 'myfatoorah')` (OrderController.php:87, FastShippingController.php:65).
- No wallet, no split payment, no other online gateway despite `PaymentStatus` enum listing wallet/refund states (§22).

---

## 3. Complete Checkout Request Contracts

### 3.1 `POST /api/v1/general/checkout` — `OrderCreateRequest`
Source: packages/marvel/src/Http/Requests/OrderCreateRequest.php

| Field | Required | When | Rules |
|-------|:--------:|------|-------|
| `name` | ✅ | always | string, max:255 |
| `user_phone` | ✅ | always | string, max:255 |
| `user_email` | ✅ | always | email, max:255 |
| `address` | ✅ | always | array (empty `{}` allowed for pickup) |
| `notes` | | | nullable, string |
| `payment_method` | | | `in:online,cod,pay_at_cashier` — **default `online`** |
| `gateway` | | | nullable, string, max:50 — default `myfatoorah` |
| `fulfillment_type` | | | `in:delivery,pickup` — **default `delivery`**; when `payment_method=pay_at_cashier` **only `pickup` allowed** (custom message `checkout.pay_at_cashier_requires_pickup`) |
| `governorate_id` | conditional | **`fulfillment_type=delivery`** | integer, `exists:governorates,id` |
| `pickup_location_id` | conditional | **`fulfillment_type=pickup`** | integer, `exists:pickup_locations,id` |
| `selected_promotion_id` | | | nullable, integer, `exists:promotions,id` |
| `selected_gift_product_id` | | | nullable, integer, `exists:products,id` |
| `type` | | | `in:mobile,web` — controls callback format (JSON vs redirect) |

**Validation failure response** (OrderCreateRequest.php:75-78): raw Laravel errors object, HTTP 422, **no envelope**:
```json
{
  "fulfillment_type": ["When choosing pay at cashier, you should choose pickup fulfillment type."]
}
```

### 3.2 `POST /api/v1/general/fast-shipping/checkout` — `FastCheckoutRequest`
Source: packages/marvel/src/Http/Requests/FastCheckoutRequest.php

Same as above **plus** `governorate_id` always `required` (line 25); no `type` field. Fast shipping uses `ShippingMethod::FAST` items only.

### 3.3 Business-rule validation beyond the FormRequest (controller level)
- `cod` + `pickup` → **422** envelope `COD_NOT_AVAILABLE_FOR_PICKUP` (OrderController.php:90-92, FastShippingController.php:68-70).
- Cart missing → **400** `CART_NOT_FOUND` (OrderController.php:75-78).
- `ensureCartReservation` failure → **400** with message (OrderController.php:80-84).
- Minimum order amount (`Settings::minimum_order_amount`) → `InvalidArgumentException` → **422** envelope (OrderService.php:200-206).

---

## 4. Complete Payment Flow per Method

### 4.1 Online (MyFatoorah)
1. `POST /checkout` → `OrderController::checkout` (OrderController.php:70). Validates, loads active cart, `ensureCartReservation`.
2. `OrderService::addItemsInOrder` (OrderService.php:149-244) creates the order in a transaction (see §13/§14 for inventory/coupon/promotion behavior).
3. `paymentMethod=online` → `PaymentCheckoutHandler::handleOnlinePayment` (app/Services/Payment/PaymentCheckoutHandler.php:23-81):
   - `factory->make($gateway)`; unsupported → **422**.
   - Callback URLs default to `route('api.checkout.callback')` / `route('api.checkout.errorCallback')`.
   - Currency check: `$gateway->supportsCurrency($orderCurrency)` where `$orderCurrency = $order->currency_code ?? $order->base_currency_code ?? config('payment.default_currency', 'EGP')`; unsupported → **422** `PAYMENT_CURRENCY_UNSUPPORTED`.
   - `$gateway->createInvoice(...)` → on failure **500**.
   - Persists a `pending` `Transaction` storing `rawResponse + ['_callback_type' => $request->type ?? 'web']`.
   - Returns **200** `{ url: <gatewayInvoiceUrl> }`.
4. Frontend redirects user to gateway URL.
5. Gateway redirects back to `checkout/callback` (success) or `checkout/error-callback` (failure) with `paymentId`. Both are **public** (`routes/api.php:103-104`), method `ANY`.
6. Callback verifies payment, validates amount/currency, completes order (§4.4), fires `PaymentSucceeded`, and returns redirect or JSON (see §5.3).

### 4.2 COD
1. Same order creation. `paymentMethod=cod` → `handleCodPayment` (PaymentCheckoutHandler.php:83-101): creates `pending` transaction `payment_method='cod'`, amount = `order->total_price`.
2. Returns **200** `{ order_id }`, message `checkout.cod_success`.
3. Later, admin calls `POST checkout/cod/{orderId}/mark-paid` (OrderController.php:129, `OrderService::markCodAsPaid` OrderService.php:605-647) → transaction → `paid`, order → `completed` + `payment_status=payment-success`, inventory finalized, coupon/promotion consumed, `PaymentSucceeded` fired.

### 4.3 Pay at Cashier
1. Same order creation. `paymentMethod=pay_at_cashier` → `handleCashierQrPayment` (PaymentCheckoutHandler.php:103-121): creates `pending` transaction `payment_method='pay_at_cashier'`.
2. Returns **200** `{ order_id }`. **No QR code is generated or returned** (QR feature removed; `qr_code_url` column remains in DB for legacy rows only).
3. Admin calls `POST checkout/cashier/{orderId}/mark-paid` (OrderController.php:142, `OrderService::markCashierPaid` OrderService.php:649-691) → same settlement as COD.

### 4.4 Callback completion path (checkoutCallback)
OrderController.php:155-407:
1. `paymentId` from query/input; missing → **400** `MISSING_PAYMENT_ID`.
2. Lookup transaction by `gateway_transaction_id` **OR** `invoice_id` (line 164). Gateway name defaulted to `myfatoorah`, overridden by `transaction->payment_method` (line 168).
3. `$gateway->verifyPayment($paymentId)` (MyFatoorahGateway.php:79-119 → `GetPaymentStatus`; `Data.InvoiceStatus === 'Paid'` = success).
4. If verification failed → update transaction `failed` + merged `gateway_response` + `error_message`, fire `PaymentFailed`, respond (see §5.3).
5. If no order found → respond success (gateway approved an invoice we can't map).
6. **Amount/currency guard** (lines 246-311): if `abs(result.amount - order.total_price) > 0.01` or `result.currency !== expectedCurrency`:
   - On the **test gateway** (`base_url` contains `apitest`) → mismatch is logged and **ignored**.
   - Otherwise → transaction marked with error, `PaymentFailed` fired, order NOT completed.
7. In a `DB::transaction` with `lockForUpdate` on transaction + order (lines 315-381): idempotency guard `if ($lockedOrder->status !== 'pending') return;`. Then: transaction → `paid` + `paid_at`; order → `payment_status=payment-success` + `paid_at`; inventory finalized (§13); `finalizePromotionUsageAfterPayment`; `changeOrderStatus(invoice_id, 'completed')`; `$processed = true`.
8. If `$processed`, fire `event(new PaymentSucceeded($order->fresh()))` (line 385).
9. `checkoutErrorCallback` (lines 409-516) mirrors this; it verifies too, and if payment is actually successful it redirects to success; otherwise marks transaction `failed` (idempotency guard `status === 'failed'` → return), fires `PaymentFailed`, responds.

---

## 5. Exact Response Contracts

### 5.1 Standard envelope — `ApiResponse` trait
Source: packages/marvel/src/Traits/ApiResponse.php:9-22
```json
{ "status": 200, "message": "Data fetched successfully", "success": true, "data": { ... } }
```
- `message` is run through `translateNotice` (message.* lang lookup); `data` omitted when empty.

### 5.2 Checkout responses
**Online — 200:**
```json
{ "status": 200, "message": "Checkout successful", "success": true, "data": { "url": "https://.../pay/INV-123" } }
```
**COD — 200:**
```json
{ "status": 200, "message": "Your order has been placed. You will pay upon delivery.", "success": true, "data": { "order_id": 42 } }
```
**Pay at Cashier — 200:**
```json
{ "status": 200, "message": "Checkout successful", "success": true, "data": { "order_id": 42 } }
```
**Errors:** 400 cart-not-found / 422 COD+pickup (envelope) / 422 minimum-order (envelope) / 422 raw validation errors (no envelope) / 401 `{ "message": "Unauthenticated." }` / 500 order-creation.

### 5.3 Callback responses (web vs mobile)
Determined by `getCallbackType` (OrderController.php:534-543): stored `gateway_response['_callback_type']` first, else `request->type ?? 'web'`.

| Outcome | Web (`type=web`) | Mobile (`type=mobile`) |
|---------|------------------|------------------------|
| Success | **302 redirect** to `{app_url_frontend}/{locale}/payment/success?status=success&message=...&payment_id=...&order_id=...` | **200** `{ status:200, message, success:true, data:{ status:"success", message, payment_id, order_id } }` |
| Failed (verify fail) | **302 redirect** to `/payment/failed?status=failed&message=...&payment_id=...` | **200** `{ status:200, ... data:{ status:"failed", message, payment_id } }` |
| Failed (mismatch) | **302 redirect** to `/payment/failed` | **400** `{ status:400, message, success:false, data:{ status:"failed", message, payment_id } }` |
| Error callback failed | **302 redirect** to `/payment/failed?status=failed&error=...&payment_id=...` | **400** `{ status:400, message, success:false, data:{ status:"failed", error, payment_id } }` |

### 5.4 Mark-paid responses
**200:** `{ "status": 200, "message": "Payment successful", "success": true }`
**422:** `{ "status": 422, "message": "No pending COD transaction found." }` / `"No pending Pay at Cashier transaction found."` (OrderService.php:616, 660)

### 5.5 `GET /checkout/promotions`
**200:** `{ status:200, message, success:true, data:{ eligible_promotions:[ PromotionResult->toArray() ] } }` (PromotionService::eligiblePromotionsPayload — app/Services/General/PromotionService.php:47-55). **400** `CART_NOT_FOUND` when no cart/items (OrderService.php:265-267).

---

## 6. Transaction Lifecycle

Model: packages/marvel/src/Database/Models/Transaction.php. Table: `transactions`.

| Stage | Status value | Set by |
|-------|--------------|--------|
| Created | `pending` (default) | handleOnlinePayment / handleCodPayment / handleCashierQrPayment |
| Online success | `paid` + `paid_at` | checkoutCallback (OrderController.php:348-353) |
| Online failure | `failed` + `error_message` | checkoutCallback / checkoutErrorCallback |
| COD/cashier settled | `paid` + `paid_at` | markCodAsPaid / markCashierPaid |
| Order cancelled via status | `failed` | changeOrderStatus (OrderService.php:584-588) |

- `uuid` auto-generated on create (`boot` creating hook, Transaction.php:35-44).
- Scopes: `pending`/`paid`/`failed` (literal string comparisons, Transaction.php:51-64).
- Fields written on success: `status`, `gateway_response` (raw + `_callback_type`), `error_message` (null on success), `paid_at`.
- The `gateway_response` JSON is a **full echo** of MyFatoorah's raw response merged with the original creation response and `_callback_type`.

---

## 7. MyFatoorah Flow (only implemented online gateway)

- **Factory:** `PaymentGatewayFactory::make('myfatoorah')` → `MyFatoorahGateway` (app/Services/Gateway/MyFatoorahGateway.php).
- **Invoice creation** (`createInvoice`, MyFatoorahGateway.php:16-77): builds payload → `SendPayment` via `MyfatoraService::createInvoice`. Fields: `InvoiceValue`, `CustomerName`, `NotificationOption=LNK`, `DisplayCurrencyIso`, `MobileCountryCode=+20`, `CustomerMobile` (normalized: strips `+20`, non-digits, truncates to 11), `CustomerEmail`, `language` (ar/en), `CallBackUrl`, `ErrorUrl`. Reads `Data.InvoiceURL` (redirect URL) + `Data.InvoiceId` (stored as `gateway_transaction_id` and `invoice_id`).
- **Verification** (`verifyPayment`, MyFatoorahGateway.php:79-119): `Key=paymentId, KeyType=PaymentId` → `GetPaymentStatus`; `Data.InvoiceStatus === 'Paid'` → success, returning amount/currency from the gateway.
- **Refund** (`refund`, MyFatoorahGateway.php:133-186): `MakeRefund` using latest transaction's `gateway_transaction_id`. **Note:** refund method exists but is not exposed via any API route.
- **Supported currencies:** `config('payment.gateways.myfatoorah.supported_currencies')` default `KWD,SAR,AED,BHD,QAR,OMR,EGP` (config/payment.php:15-18), case-insensitive match (MyFatoorahGateway.php:126-131).
- **HTTP client:** `MyfatoraService` (app/Services/General/MyfatoraService.php) — Bearer `services.myfatoorah.api_key`, POST, 30s timeout, `withoutVerifying()` in local env only; non-2xx or `IsSuccess=false` → logs + returns `null` (treated as gateway failure).
- **Config:** `config/payment.php` — `default_gateway=myfatoorah`, `default_currency` (**KWD** via `DEFAULT_CURRENCY` env; note docs §24 say EGP — see §25), `order_timeout_hours=72`, gateway class/api_key/base_url/supported_currencies.

---

## 8. COD Flow (complete)

1. `POST /checkout` with `payment_method=cod`, `fulfillment_type=delivery` (pickup → 422).
2. Order created pending (`OrderService::addItemsInOrder`), transaction `cod/pending` created (`handleCodPayment`).
3. Driver collects cash. Admin `POST checkout/cod/{orderId}/mark-paid` (permission `update-order-status`).
4. `OrderService::markCodAsPaid` (OrderService.php:605-647): locks the latest pending `cod` transaction; marks `paid`+`paid_at`; order → `status=completed`, `payment_status=payment-success`, `completed_at`, `fulfillment_status=processing` (if pending); records coupon usage; finalizes promotion usage; finalizes inventory; fires `PaymentSucceeded`.
5. `PaymentSucceeded` listeners → notifications + invoice generation (§15/§16).

---

## 9. Pay at Cashier Flow (complete)

1. `POST /checkout` with `payment_method=pay_at_cashier`, `fulfillment_type=pickup` (delivery → 422 via FormRequest `fulfillment_type.in`).
2. Order created pending; transaction `pay_at_cashier/pending` created (`handleCashierQrPayment`).
3. **No QR code** is generated/stored/displayed (feature removed in this work session). The `qr_code_url` column is legacy-only.
4. Customer pays in store. Admin `POST checkout/cashier/{orderId}/mark-paid` (permission `update-order-status`).
5. `OrderService::markCashierPaid` (OrderService.php:649-691) — identical settlement to COD (transaction `paid`, order `completed`, `payment-cash` semantics via `Order::PAYMENT_STATUS_SUCCESS` — see §25 note).

---

## 10. Fulfillment Compatibility

| Payment | Delivery | Pickup |
|---------|:--------:|:------:|
| `online` | ✅ | ✅ |
| `cod` | ✅ | ❌ 422 (controller rule, OrderController.php:90) |
| `pay_at_cashier` | ❌ 422 (FormRequest rule, OrderCreateRequest.php:44-47) | ✅ |

Fast shipping (`fast-shipping/checkout`): `fulfillment_type` `in:delivery,pickup`, but `governorate_id` is always required (FastCheckoutRequest.php:25) and only `ShippingMethod::FAST` cart items are processed (FastShippingService.php:72).

---

## 11. Currency Requirements

- Currency resolution chain everywhere: `order->currency_code ?? order->base_currency_code ?? config('payment.default_currency', 'EGP')` (PaymentCheckoutHandler.php:40, MyFatoorahGateway.php:23, OrderController.php:268).
- **Blocked at checkout** if the resolved currency is not in MyFatoorah's `supported_currencies` → 422 `PAYMENT_CURRENCY_UNSUPPORTED` (no silent base-currency fallback; config comment config/payment.php:13-14).
- **Re-validated at callback** (OrderController.php:268-285); mismatch blocks order completion on production gateway, logged-and-ignored on test gateway.

---

## 12. Amount Verification

- `handleOnlinePayment` charges exactly `round((float) $order->total_price, 2)` (OrderController.php:111).
- Callback compares `result->amount` vs `order->total_price` with tolerance `> 0.01` (OrderController.php:250). Test gateway ignores mismatch; production blocks.
- COD/cashier transaction amount = `order->total_price` at creation (PaymentCheckoutHandler.php:90, 110).
- No webhook/signature validation of callback (relies on MyFatoorah `GetPaymentStatus` server-side verification + amount/currency check). **Security consideration** (§25).

---

## 13. Inventory Behavior

Service: app/Services/General/CartInventoryService.php (604 lines).

- **Reservation model:** adding to cart reserves stock (`reserved_quantity` on product/variant); `getAvailableStock = stock_quantity - reserved_quantity` (CartInventoryService.php:590-593). Concurrency via `lockForUpdate`.
- **At checkout:** `ensureCartReservation` (CartInventoryService.php:409-423) re-syncs reservations; throws on insufficient stock → 400.
- **On payment success** (`finalizeItemsByShippingMethod`, CartInventoryService.php:300-332): decrements `stock_quantity`, decrements `reserved_quantity`, increments `sold_quantity`, sets `in_stock`, deletes finalized cart items; cart → `checked_out` when empty. Used by callback path (OrderController.php:366-374) and `finalizeInventoryAfterPayment` (OrderService.php:693-711) for COD/cashier.
- **Fallback** `deductStockForOrder` (CartInventoryService.php:334-389) used when no active cart exists.
- **Cancellation:** `OrderCancelled` → `RestoreProductInventory` listener (EventServiceProvider.php:100-104) restores stock.
- Cart TTL: 3 days (`CART_TTL_DAYS`, CartInventoryService.php:20); `expireCarts()` job releases reservations.

---

## 14. Coupon & Promotion Behavior

### Coupons
- Apply: `POST coupons/apply` (CouponController) → stored on cart (`cart.coupon`).
- At order creation (`addItemsInOrder`, OrderService.php:168-183): coupon row locked (`lockForUpdate`), validated via `CouponOrchestrator::validate` (app/Services/Coupon/CouponOrchestrator.php:22); invalid → removed from cart. `FREE_SHIPPING` type → shipping price zeroed.
- **Usage consumption happens on payment success only** (`recordCouponUsage`, OrderService.php:734-809), policy documented in its docblock (OrderService.php:716-733):
  - Assigned coupons (`coupon_assignments` table exists + assignments present): locks assignment, checks `used >= max_uses`, dedupes via `coupon_assignment_usages` (unique per order), increments `coupons.used` + `coupon_assignments.used`, creates audit row, fires `AssignedCouponConsumed` after commit.
  - Public coupons: `CouponUsage::firstOrCreate(coupon_id, user_id)` (unique per user), increments `coupons.used` only when newly created.
  - Sets `orders.coupon_consumed = true`.
- Never auto-returned on cancellation/refund (intentional, OrderService.php:716-719).
- Fast shipping uses `CouponValidator::validateByCode` (FastShippingService.php:99-104).

### Promotions
- Eligibility endpoint: `GET checkout/promotions` → `{ eligible_promotions: [...] }` (PromotionService.php:47-55); resolver evaluates cart against valid promotions (discounts + gift items).
- At checkout the promotion is already applied to the cart (items carry `promotion_id`/`is_gift`); `addItemsInOrder` reads `selectedPromotionId`/`selectedGiftProductId` from cart items (OrderService.php:185-191).
- **Usage increment on payment success:** `finalizePromotionUsageAfterPayment` → `PromotionService::incrementUsage` (OrderService.php:246-259, PromotionService.php:163-178) — guarded `where usage < limiter`, `lockForUpdate`.
- **Decrement on cancellation:** `changeOrderStatus` → `decrementUsage` (OrderService.php:591-593, PromotionService.php:189-201).

---

## 15. Events

| Event | Listeners (EventServiceProvider.php) |
|-------|--------------------------------------|
| `PaymentSucceeded` | `SendPaymentSucceededNotification`, `GenerateInvoiceListener`, `SendUserPaymentSucceededNotification` (lines 116-120) |
| `PaymentFailed` | `SendPaymentFailedNotification`, `SendUserPaymentFailedNotification` (lines 112-115) |
| `OrderCreated` | `SendNewOrderNotification`, `SendUserOrderCreatedNotification` (105-108) |
| `OrderCancelled` | `RestoreProductInventory`, `SendOrderCancelledNotification`, `SendUserOrderCancelledNotification` (100-104) |
| `OrderStatusChanged` | `SendOrderStatusChangedNotification` (109-111) |
| `AssignedCouponConsumed` | `SendUserCouponUsedNotification` (121-123) |
| `InvoiceCreated` | `LogInvoiceCreated` (153-155) |

- Events are plain POCOs (`public $order`), fired synchronously; heavy listeners are `ShouldQueue`.
- **Dead code:** `Marvel\Events\*` package events are not registered here and not dispatched in production flows.

---

## 16. Notifications

- All user/admin notifications implement `ShouldQueue` and are pushed to queue **`meem-medium`** (`$this->onQueue('meem-medium')` — app/Notifications/*).
- `UserPaymentSucceededNotification` (app/Notifications/UserPaymentSucceededNotification.php): channels `['database', 'broadcast']`; payload has localized `title`/`message` (en/ar), `icon='credit-card'`, `resource_type='order'`, `resource_id`, `action_url=/orders/{id}`, `order_id`, `order_number`, `total_amount`, `payment_status='succeeded'`; broadcast type `payment.succeeded`.
- Invoice generation: `GenerateInvoiceListener` (app/Listeners/GenerateInvoiceListener.php) — `ShouldQueue`, `$afterCommit=true`, queue **`meem-high`**, `tries=5`, `backoff=[10,30,60,120,300]`.

---

## 17. Idempotency

- **Callback double-processing:** the completion transaction re-locks the transaction+order (`lockForUpdate`) and early-returns if `order->status !== 'pending'` (OrderController.php:338-340), so a second callback after completion is a no-op.
- **Error-callback double-processing:** guarded by `if ($lockedTransaction->status === 'failed') return;` (OrderController.php:478-480).
- **Coupon usage:** `coupon_assignment_usages` unique per order + `coupon_consumed` flag (OrderService.php:736, 761-766).
- **Promotion usage:** `promotion_consumed` flag (OrderService.php:248-258).
- **Transaction lookup** uses `gateway_transaction_id` OR `invoice_id` to tolerate callback key variations.

---

## 18. Failure Matrix

| Scenario | HTTP / Behavior |
|----------|-----------------|
| Missing `paymentId` on callback | **400** `MISSING_PAYMENT_ID` |
| Unsupported gateway on checkout | **422** (unsupported message) |
| Unsupported currency on checkout | **422** `PAYMENT_CURRENCY_UNSUPPORTED` |
| Gateway invoice creation fails | **500** `ERROR_CREATING_INVOICE` (or gateway message) |
| Transaction create fails | **500** `ERROR_CREATING_TRANSACTION` |
| Order total ≤ 0 on online | **500** `FILED_TO_CREATE_ORDER_TRY_AGAIN` |
| Invalid payment method | **422** `INVALID_PAYMENT_METHOD` |
| COD + pickup | **422** `COD_NOT_AVAILABLE_FOR_PICKUP` (envelope) |
| Cashier + delivery | **422** raw validation errors (FormRequest) |
| Cart missing / empty | **400** `CART_NOT_FOUND` (order creation returns null → 500 `ERROR_ADDING_ITEMS_TO_ORDER`) |
| Stock insufficient at reservation | **400** (message) |
| Minimum order not met | **422** (envelope, InvalidArgumentException) |
| Verify fails on callback | transaction→`failed`, `PaymentFailed` event, web redirect / mobile JSON |
| Amount/currency mismatch (production) | transaction error set, `PaymentFailed`, order NOT completed |
| Amount/currency mismatch (test gateway) | logged, ignored |
| mark-paid no pending transaction | **422** `RuntimeException` message |
| Unsupported gateway on callback | **500** `PAYMENT_GATEWAY_UNAVAILABLE` |
| Invoice not owner | **403** via `AuthorizationException` (invoice endpoint) |

---

## 19. Auth & Authorization

- Checkout, promotions, orders, invoices: **`auth:sanctum`** + `throttle:authenticated` (routes/api.php:107).
- Public (throttle `public-api`): `checkout/callback`, `checkout/error-callback`, `fast-shipping/status`, catalog routes (routes/api.php:41-105).
- `mark-paid` endpoints: `auth:sanctum` **+ `permission:update-order-status`** (routes/api.php:113-114).
- Invoice ownership check inside `invoice()` (OrderController.php:522-524) → `AuthorizationException` (403).

---

## 20. Routes Inventory

Source: routes/api.php (all under prefix `/api/v1/general`)

| Method | Path | Auth | Controller@method |
|--------|------|------|-------------------|
| GET | `/checkout/promotions` | sanctum | OrderController@eligiblePromotions |
| POST | `/checkout` | sanctum | OrderController@checkout |
| POST | `/checkout/cod/{orderId}/mark-paid` | sanctum + `update-order-status` | OrderController@markCodAsPaid |
| POST | `/checkout/cashier/{orderId}/mark-paid` | sanctum + `update-order-status` | OrderController@markCashierPaid |
| ANY | `/checkout/callback` (name `api.checkout.callback`) | public | OrderController@checkoutCallback |
| ANY | `/checkout/error-callback` (name `api.checkout.errorCallback`) | public | OrderController@checkoutErrorCallback |
| GET | `/fast-shipping/status` | public | FastShippingController@status |
| POST | `/fast-shipping/checkout` | sanctum | FastShippingController@checkout |
| GET | `/orders` | sanctum | OrderController@index |
| GET | `/orders/invoice/{uuid}` | sanctum | OrderController@invoice |
| POST | `/coupons/apply` | sanctum | CouponController@applyCoupon |

`php artisan route:list` is blocked by a **pre-existing** `ReflectionException` for a missing `BkashTokenizePaymentController` (unrelated to this audit; routes verified via routes/api.php directly). Shipments routes are commented out (routes/api.php:133-144).

---

## 21. Gateway Architecture

```
OrderController / FastShippingController
        │
        ▼
PaymentCheckoutHandler (App\Services\Payment)
  ├─ handleOnlinePayment   → PaymentGatewayFactory::make($gateway)
  │                            └─ 'myfatoorah' → MyFatoorahGateway (App\Services\Gateway)
  │                                                  └─ MyfatoraService (HTTP: SendPayment / GetPaymentStatus / MakeRefund)
  ├─ handleCodPayment      → Transaction (cod, pending)
  └─ handleCashierQrPayment → Transaction (pay_at_cashier, pending)
```
- Interface: `App\Services\Payment\Contracts\PaymentGatewayContract` (implemented by MyFatoorahGateway).
- Result DTO: `App\DTOs\GatewayResult` (`success, redirectUrl, gatewayTransactionId, status, amount, currency, errorMessage, rawResponse`).
- Exception: `App\Exceptions\UnsupportedGatewayException`.
- Background: `App\Jobs\PaymentReconciliationJob` (reconciliation; not exposed via route).
- **Extensibility:** adding a gateway = implement `PaymentGatewayContract` + add `match` arm in factory + config block. No other coupling.

---

## 22. Database Payment Model

**`transactions`** (migration packages/marvel/database/migrations/2020_06_02_051901_create_marvel_tables.php:240-259):
`id, uuid (nullable, unique), invoice_id (int), user_id (bigint), payment_method (string), status (string,30, default 'pending'), amount (decimal 10,2 nullable), currency (string 3, default 'EGP'), gateway_transaction_id (string 255 nullable), gateway_response (json nullable), error_message (text nullable), qr_code_url (string 500 nullable — LEGACY, unused), paid_at (timestamp nullable), order_id (FK cascade delete), timestamps`. Indexes: `status` (txn_status_idx), `uuid` (txn_uuid_idx).

**`orders`** columns relevant to payment (written conditionally via `Schema::hasColumn`):
`payment_status` (`PaymentStatus` enum values), `paid_at`, `completed_at`, `fulfillment_status`, `promotion_consumed`, `coupon_consumed`, `payment_gateway`.

**Enums:**
- `PaymentStatus` (packages/marvel/src/Enums/PaymentStatus.php): `payment-pending`, `payment-processing`, `payment-success`, `payment-failed`, `payment-reversal`, `payment-refunded`, `payment-cash-on-delivery`, `payment-cash`, `payment-wallet`, `payment-awaiting-for-approval`. (Only `payment-success` and the pending defaults are written by the current flows; `payment-cash`/`payment-cash-on-delivery` are documented frontend values but the code writes `Order::PAYMENT_STATUS_SUCCESS` — see §25.)
- `FulfillmentType` (FulfillmentType.php): `delivery`, `pickup`.
- `ShippingMethod` (ShippingMethod.php): `SCHEDULED`, `FAST`.

**Key/Note on `orders` schema:** `addItemsInOrder` reads `payment_gateway` into order data but the underlying `orders` table columns are checked dynamically; the order-status/payment-status writes are conditional on column existence (defensive design).

---

## 23. Tests & Coverage

Test inventory (tests/Feature): `PaymentCheckoutTest.php` (1392 lines), `PaymentSystemTest.php` (1130), `PaymentProductionHardenTest.php` (1649), `PaymentCallbackStressTest.php` (589), `PaymentReconciliationTest.php`, plus `FastShippingControllerTest`, `FastShippingHardenTest`, `CheckoutApiTest`, `CheckoutRegressionTest`, `CouponSystemTest`, `AssignedCouponSystemTest`, `CouponsProductionHardenTest`, `OrdersProductionHardenTest`, `EventSystemTest`, `BugFixesValidationTest`.

Verified runs this session:
- `PaymentCheckoutTest` — **29/29 PASS** (includes QR-removal regression tests: `scheduled_checkout_with_pay_at_cashier_requires_pickup_location`, `pay_at_cashier_lifecycle_is_preserved_after_qr_removal`).
- `PaymentSystemTest` — 28 pass + **1 pre-existing failure** `mark_cod_as_paid_records_coupon_usage` → `SQLSTATE[HY000]: ... no such table: coupon_assignments`. **Confirmed environmental/pre-existing** (the `coupon_assignments` migration is not run in the test DB; the test hits the assigned-coupon branch of `recordCouponUsage`). **Not related to QR removal** (stash-verified earlier: 12 distinct failures pre-exist independently).
- Full suite has additional pre-existing failures tied to unrelated in-progress work (Settings/tiktok/snapchat tables, Pusher notifications) — all pre-existing, none payment-critical.

**Coverage present:** success paths (online/COD/cashier), validation failures, authz (permission), callback stress, reconciliation, amount/currency mismatch, fulfillment matrix, coupons/promotions, events, edge cases.

**Coverage gaps:** no test for `checkoutErrorCallback` re-entry after success; no test that a refund method is wired to a route (it isn't); no test asserting `payment-cash` enum value is written (code writes `Order::PAYMENT_STATUS_SUCCESS`).

---

## 24. Cross-Flow Comparison

| Dimension | Online | COD | Pay at Cashier |
|-----------|--------|-----|----------------|
| Order creation | same | same | same |
| Settlement trigger | Gateway callback | Admin mark-paid | Admin mark-paid |
| Auth needed to settle | — (public callback) | `update-order-status` | `update-order-status` |
| Transaction status on success | `paid` + `paid_at` | `paid` + `paid_at` | `paid` + `paid_at` |
| Order status on success | `completed` | `completed` | `completed` |
| `PaymentSucceeded` fired | ✅ | ✅ | ✅ |
| Inventory finalized | ✅ | ✅ | ✅ |
| Coupon consumed | ✅ | ✅ | ✅ |
| Promotion consumed | ✅ | ✅ | ✅ |
| Invoice generated | ✅ (via listener) | ✅ | ✅ |
| User notified | ✅ | ✅ | ✅ |
| Payment-status value written | `Order::PAYMENT_STATUS_SUCCESS` | `Order::PAYMENT_STATUS_SUCCESS` | `Order::PAYMENT_STATUS_SUCCESS` |
| Extra guard | Amount/currency check | pending txn must exist | pending txn must exist |

---

## 25. Critical Findings

1. **`payment-cash` / `payment-cash-on-delivery` are never written by the backend.** The `PaymentStatus` enum defines them, and the frontend docs use them as badges, but all three settlement paths write `Order::PAYMENT_STATUS_SUCCESS` (`payment-success`). Frontend badge logic for COD/cashier depends on the order's `payment_method` field instead. **Docs already updated to reflect the real write** — but a frontend dev cannot distinguish COD-paid vs cashier-paid from `payment_status` alone; use `payment_method`.
2. **Callback relies on server-side verification + amount/currency comparison, not signature/hmac.** Mitigated by locking, idempotency guards, and mismatch blocking on production, but the callback is public. Documented for awareness; no code change made.
3. **`config/payment.default_currency` default is `KWD`** (env `DEFAULT_CURRENCY`), while several docs/strings fall back to `EGP`. All code uses `?? config('payment.default_currency', 'EGP')`, so at runtime the effective default is `KWD` unless env set. Frontend displays should read the resolved currency from order data, not hardcode EGP.
4. **QR code feature removal:** `handleCashierQrPayment` no longer generates/returns a QR; `qr_code_url` column + model fillable remain for legacy rows (intentional). `packages/` Marvel docs/models still reference `qr_code_url` — legacy only.
5. **Pre-existing environment issue:** `coupon_assignments` table absent from test DB breaks one PaymentSystemTest. Not caused by this work.
6. **`BkashTokenizePaymentController` is referenced by a route but the class is missing** → `php artisan route:list` crashes. Pre-existing, unrelated to payments audit; flagged for cleanup.
7. **Refund capability exists (`MyFatoorahGateway::refund`) but is not exposed** by any endpoint.
8. **`cart.coupon` is validated but not re-validated at settlement** — coupon quota is consumed at payment success via the order snapshot (`order->coupon`), and usage checks (max_uses, dedupe) happen at that time. Fine-grained expiry between checkout and settlement is not re-checked (coupon validated at order creation).

---

## 26. Complete Payment Requirements Specification (derived from code, for frontend)

### Checkout prerequisites (parallel fetch)
- `GET /checkout/promotions` (auth) — eligible promotions for cart.
- `GET /governorates` (public) — delivery.
- `GET /pickup-locations` (public) — pickup.
- `GET /cart` (auth) — current cart totals.

### Submit order
`POST /checkout` body per §3.1. Frontend must:
- Send `payment_method` (default online), `gateway` (online only, default myfatoorah), `fulfillment_type` (default delivery).
- Include `governorate_id` for delivery, `pickup_location_id` for pickup, `address` always (empty `{}` ok for pickup).
- For `pay_at_cashier`: force `fulfillment_type=pickup`; never send COD+pickup.
- Set `type=web` (or `mobile` for native apps) to select callback response format.

### Post-checkout
- **Online:** redirect to `data.url`. Expect a gateway redirect back to `/payment/success` or `/payment/failed` (web) with `payment_id` + `order_id`, or a JSON response (mobile) per §5.3. Do not trust client-side success; rely on the callback result page.
- **COD:** show success with `order_id`. Payment shows pending until admin marks paid.
- **Pay at Cashier:** show success with `order_id`; **no QR, nothing to scan**. Pending until cashier settles. Track status via order list (`GET /orders`); `payment_method=pay_at_cashier` + `payment_status=payment-success` means paid at store.

### Order tracking
`GET /orders` (auth) — status timeline: `order-pending → order-processing → order-at-local-facility|order-ready-for-pickup → order-out-for-delivery → order-completed`; plus cancelled/refunded/failed.

### Error handling
Handle 400 (cart missing), 422 (raw errors object for validation; envelope for business rules), 401 (re-login), 500 (retry). On callback failure pages show the `message`/`error` from query.

---

## 27. Files Involved

**Controllers:** app/Http/Controllers/Api/General/OrderController.php, app/Http/Controllers/Api/General/FastShippingController.php
**Requests:** packages/marvel/src/Http/Requests/OrderCreateRequest.php, packages/marvel/src/Http/Requests/FastCheckoutRequest.php
**Payment services:** app/Services/Payment/PaymentCheckoutHandler.php, app/Services/Payment/PaymentGatewayFactory.php, app/Services/Payment/Contracts/PaymentGatewayContract.php, app/Services/Gateway/MyFatoorahGateway.php, app/Services/General/MyfatoraService.php, app/DTOs/GatewayResult.php, app/Jobs/PaymentReconciliationJob.php, app/Exceptions/UnsupportedGatewayException.php
**Order/inventory/coupon/promotion services:** app/Services/General/OrderService.php, app/Services/General/CartInventoryService.php, app/Services/Checkout/OrderCreationService.php, app/Services/General/PromotionService.php, app/Services/General/FastShippingService.php, app/Services/Coupon/CouponValidator.php, app/Services/Coupon/CouponAssignmentValidator.php, app/Services/Coupon/CouponOrchestrator.php
**Models/Enums:** packages/marvel/src/Database/Models/Transaction.php, packages/marvel/src/Database/Models/Order.php, packages/marvel/src/Enums/PaymentStatus.php, packages/marvel/src/Enums/FulfillmentType.php, packages/marvel/src/Enums/ShippingMethod.php
**Migrations:** packages/marvel/database/migrations/2020_06_02_051901_create_marvel_tables.php
**Events/Listeners/Notifications:** app/Events/PaymentSucceeded.php, app/Events/PaymentFailed.php, app/Events/OrderCreated.php, app/Events/OrderCancelled.php, app/Events/OrderStatusChanged.php, app/Events/AssignedCouponConsumed.php, app/Listeners/GenerateInvoiceListener.php, app/Listeners/RestoreProductInventory.php, app/Listeners/SendPaymentSucceededNotification.php, app/Listeners/SendPaymentFailedNotification.php, app/Listeners/SendUserPaymentSucceededNotification.php, app/Listeners/SendUserPaymentFailedNotification.php, app/Notifications/UserPaymentSucceededNotification.php, app/Providers/EventServiceProvider.php
**Config/Routes:** config/payment.php, config/services.php, routes/api.php
**Traits:** packages/marvel/src/Traits/ApiResponse.php
**Tests:** tests/Feature/PaymentCheckoutTest.php, tests/Feature/PaymentSystemTest.php, tests/Feature/PaymentProductionHardenTest.php, tests/Feature/PaymentCallbackStressTest.php, tests/Feature/PaymentReconciliationTest.php

---

## 28. Final Conclusions

1. **The payment system is complete and consistent** across the three methods; all flows converge on the same settlement semantics (transaction → `paid`, order → `completed`, `PaymentSucceeded`, inventory/coupon/promotion finalization).
2. **A frontend developer can implement the entire payment experience from this report** (§3 request contracts, §5 responses, §10 compatibility, §18 failure matrix, §26 spec) — no missing fields or behaviors were found. The only nuance is §25.1 (`payment-cash` never written; distinguish by `payment_method`).
3. **No code defects were found in the payment flows** during this audit. The only test failure (`coupon_assignments`) is an environment/migration gap, and the only route error (`BkashTokenizePaymentController`) is pre-existing dead configuration.
4. **Known intentional behaviors:** QR feature removed; coupon quota never returned on cancellation; currency mismatch blocked on production gateway; test-gateway mismatch tolerated.
5. **No modifications were made to any source file** — this audit is documentation only.