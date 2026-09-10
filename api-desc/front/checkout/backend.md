# Checkout Module — Backend Architecture

## Endpoints

| Method | URL | Auth | Permission | Purpose |
|--------|-----|------|------------|---------|
| GET | `/api/v1/general/checkout/promotions` | auth:sanctum | — | List eligible promotions |
| POST | `/api/v1/general/checkout` | auth:sanctum | — | Place order |
| POST | `/api/v1/general/checkout/cod/{orderId}/mark-paid` | auth:sanctum | update-order-status | Mark COD paid |
| POST | `/api/v1/general/checkout/cashier/{orderId}/mark-paid` | auth:sanctum | update-order-status | Mark cashier paid |
| ANY | `/api/v1/general/checkout/callback` | Public | — | Payment callback |
| ANY | `/api/v1/general/checkout/error-callback` | Public | — | Error callback |

## Middleware

| Endpoint | auth:sanctum | permission | Named route |
|----------|:---:|:---:|:---:|
| GET /checkout/promotions | ✓ | — | — |
| POST /checkout | ✓ | — | — |
| POST /cod/{id}/mark-paid | ✓ | update-order-status | — |
| POST /cashier/{id}/mark-paid | ✓ | update-order-status | — |
| ANY /callback | — | — | api.checkout.callback |
| ANY /error-callback | — | — | api.checkout.errorCallback |

## Request Flows

All responses use the envelope `{ "status", "message", "success", "data? }`. 422 validation errors return the raw validator errors object.

### Eligible Promotions
```
GET /checkout/promotions  (request body: none)
  → OrderService::eligiblePromotionsForUser()
    → getCartUser() → Cart with SCHEDULED items
    → Cart missing/empty? → 400 { message: "Cart not found" }
    → PromotionService::eligiblePromotionsPayload($cart)
  → Response: 200 { data: { eligible_promotions: [{ id, type, title, code, discount, gift_items }] } }
```

### Checkout — Request Body (all payment methods)
```
POST /checkout
{
  name: "John Doe", user_phone: "+1-555-0123", user_email: "john@example.com",
  address: { street: "123 Main St" }, notes: "Leave at door",       // name/phone/email/address ALWAYS required
  payment_method: "online|cod|pay_at_cashier",                        // optional, default "online"
  gateway: "myfatoorah",                                              // optional, only used for online, default "myfatoorah"
  fulfillment_type: "delivery|pickup",                                // optional, default "delivery"
  governorate_id: 1,                                                  // REQUIRED when fulfillment_type=delivery
  pickup_location_id: 1,                                              // REQUIRED when fulfillment_type=pickup
  selected_promotion_id: null, selected_gift_product_id: null,        // optional
  type: "web|mobile"                                                  // optional, default "web" (controls callback format)
}
```

### Checkout (Online)
```
POST /checkout { payment_method: "online" }
  → OrderCreateRequest validation
  → ensureCartReservation (lock + sync)
  → COD+pickup check
  → OrderService::addItemsInOrder()
    → DB::transaction
      → Cart::lockForUpdate (SCHEDULED items)
      → refreshCartItemPrices (real-time)
      → Validate coupon (lock row)
      → Calculate totals (promotion + coupon + shipping)
      → Enforce minimumOrderAmount (against subtotal, pre-discount)
      → OrderCreationService::createOrder (snapshot pricing)
      → createOrderItems, finalizeOrder
      → finalizeItemsByShippingMethod(SCHEDULED)
    → PaymentCheckoutHandler::handleOnlinePayment
      → Gateway::createInvoice
      → Transaction::create(status=pending)
    → Response: 200 { data: { url } }
```

### Checkout (COD)
```
POST /checkout { payment_method: "cod" }
  → COD + pickup? → 422 { message: "COD is not available for pickup. Use pay_at_cashier instead." }
  → ... same order creation ...
  → PaymentCheckoutHandler::handleCodPayment
    → Transaction::create(payment_method=cod, status=pending)
    → finalizeInventory
  → Response: 200 { data: { order_id }, message: "Your order has been placed. You will pay upon delivery." }
```

### Checkout (Pay at Cashier)
```
POST /checkout { payment_method: "pay_at_cashier", fulfillment_type: "pickup", pickup_location_id: 1 }
  → Validation enforces fulfillment_type=pickup (delivery → 422 validator error)
  → ... same order creation ...
  → PaymentCheckoutHandler::handleCashierQrPayment
    → Transaction::create(payment_method=pay_at_cashier, status=pending)
    → finalizeInventory
  → Response: 200 { data: { order_id } }   (no QR / transaction_uuid)
```

### Mark COD/Cashier Paid
```
POST /cod/{id}/mark-paid   |   POST /cashier/{id}/mark-paid   (request body: none)
  → Order::findOrFail  (404 if missing)
  → markCodAsPaid (or markCashierPaid)
    → Lock pending transaction
    → No pending transaction? → 422 { message: "No pending COD/Cashier transaction found." }
    → Update: transaction=paid, order=completed
    → recordCouponUsage
    → event(PaymentSucceeded)
  → Response: 200 { message: "Payment successful" }
```

> Pay-at-cashier orders are located by the cashier in the admin panel using `order_id`. No QR code is generated or scanned.

### Payment Callback
```
ANY /callback?paymentId=X  (request body: none, public)
  → paymentId missing? → 400 { message: "Missing payment ID" }
  → Find transaction
  → Gateway::verifyPayment
  → Amount/currency mismatch check
  → Success:
      → finalize inventory, order=completed, event(PaymentSucceeded)
      → web: redirect /payment/success?status=success&message=Payment successful&payment_id=X&order_id=42
      → mobile: 200 { data: { status:"success", message:"Payment successful", payment_id:X, order_id:42 } }
  → Failure:
      → cancel order, release cart, event(PaymentFailed)
      → web: redirect /payment/failed?status=failed&message=...&payment_id=X
      → mobile: 400 { data: { status:"failed", message:"...", payment_id:X } }
```

## Key Classes

| Class | Responsibility |
|-------|----------------|
| `OrderController` | HTTP entry points (6 methods) |
| `OrderService` | Order orchestration, pricing, status management |
| `OrderCreationService` | Order + order items persistence |
| `PaymentCheckoutHandler` | Online/COD/cashier payment routing |
| `PaymentGatewayFactory` | Gateway resolution by name |

## Model: Order

Key columns: user_id, name, user_phone, user_email, address (json), fulfillment_type, payment_method, governorate_id, pickup_location_id, price, shipping_price, total_price, coupon, coupon_discount, promotion_id, promotion_discount, status.

Status machine: pending → processing → completed → delivered. Cancelled from any state.

## Model: Transaction

Key columns: order_id, user_id, uuid (unique, auto-generated), invoice_id, gateway_transaction_id, payment_method, status (pending/paid/failed), amount, currency, gateway_response (json), error_message, paid_at.

## Pricing Order

1. Refresh cart prices (real-time via ProductPricingService)
2. Apply promotion (PromotionService::applySelectedPromotion)
3. Apply coupon (CouponCalculator)
4. Calculate shipping (governorate-based, free shipping thresholds)
5. Free shipping from coupon (if discount_type=free_shipping)
6. finalTotal = (subtotal - promo_discount - coupon_discount) + shipping

## Events

| Event | Fired When |
|-------|-----------|
| OrderCreated | After order insert |
| PaymentSucceeded | Callback / mark-paid success |
| PaymentFailed | Callback failure / mismatch |
| OrderStatusChanged | Any status transition |
| OrderCancelled | Status → cancelled |
| AssignedCouponConsumed | After coupon usage committed |
