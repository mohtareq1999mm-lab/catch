# Request Flows — Checkout Module

## Response Envelope

Every API response (except 422 validation errors) uses:

```json
{ "status": 200, "message": "...", "success": true, "data": {} }
```

422 validation errors return the raw Laravel validator errors object (no envelope).

---

## Flow 1: Eligible Promotions

```
GET /checkout/promotions [auth:sanctum]
  → OrderController@eligiblePromotions()
  → OrderService::eligiblePromotionsForUser()
    → getCartUser(): Cart (SCHEDULED items + flash_sales)
    → Cart exists + has items?
      ├─ NO → Response: 400 CART_NOT_FOUND
      │        { "status": 400, "message": "Cart not found", "success": false }
      └─ YES → PromotionService::eligiblePromotionsPayload($cart)
    → Response: 200
       {
         "status": 200, "message": "Data fetched successfully", "success": true,
         "data": {
           "eligible_promotions": [
             { "id": 1, "type": "buy_x_get_y", "title": "Buy 2 Get 1 Free", "code": "BUY2GET1", "discount": 100.0, "gift_items": [] }
           ]
         }
       }
```

Request body: none.

---

## Flow 2: Checkout Online

```
POST /checkout [auth:sanctum]
Request body:
  {
    "name": "John Doe", "user_phone": "+1-555-0123", "user_email": "john@example.com",
    "address": { "street": "123 Main St" }, "notes": "Leave at door",
    "payment_method": "online", "gateway": "myfatoorah",
    "fulfillment_type": "delivery", "governorate_id": 1,
    "pickup_location_id": null, "selected_promotion_id": null, "selected_gift_product_id": null,
    "type": "web"
  }
  → OrderCreateRequest validation
    → Required: name, user_phone, user_email, address
    → governorate_id required when fulfillment_type=delivery
    → pickup_location_id required when fulfillment_type=pickup
  → getActiveCartForUser → exists? YES
  → ensureCartReservation (lock + sync)
  → COD+pickup? NO
  → OrderService::addItemsInOrder()
    ┌─ DB::transaction
    │  Cart::lockForUpdate (SCHEDULED items)
    │  refreshCartItemPrices (ProductPricingService)
    │  Coupon: lock + validate
    │  calculateCheckoutTotals(promotion, coupon)
    │  minimumOrderAmount check (subtotal vs settings)
    │  Resolve shipping (governorate → price)
    │  OrderCreationService::createOrder
    │  OrderCreationService::createOrderItems
    │  finalizeOrder, finalizeInventory(SCHEDULED)
    └─ DB::commit
  → handleOnlinePayment($order, $amount, $gateway)
    → Gateway::createInvoice(order, amount, callback, error)
    → Transaction::create(status=pending)
  → Response: 200
     { "status": 200, "message": "Checkout successful", "success": true, "data": { "url": "https://gateway.com/pay/..." } }
```

---

## Flow 3: Checkout COD

```
POST /checkout [auth:sanctum]
Request body:
  {
    "name": "John Doe", "user_phone": "+1-555-0123", "user_email": "john@example.com",
    "address": { "street": "123 Main St" }, "notes": "Leave at door",
    "payment_method": "cod",
    "fulfillment_type": "delivery", "governorate_id": 1,
    "selected_promotion_id": null, "selected_gift_product_id": null
  }
  → ... same order creation ...
  → handleCodPayment($order)
    → Transaction::create(payment_method=cod, status=pending)
    → finalizeInventory
  → Response: 200
     {
       "status": 200,
       "message": "Your order has been placed. You will pay upon delivery.",
       "success": true,
       "data": { "order_id": 1 }
     }
```

COD + pickup is rejected **before** order creation:
```
  → COD + pickup? YES
    → Response: 422
       { "status": 422, "message": "COD is not available for pickup. Use pay_at_cashier instead.", "success": false }
```

---

## Flow 4: Checkout Pay at Cashier

```
POST /checkout [auth:sanctum]
Request body:
  {
    "name": "John Doe", "user_phone": "+1-555-0123", "user_email": "john@example.com",
    "address": { "street": "123 Main St" },
    "payment_method": "pay_at_cashier",
    "fulfillment_type": "pickup", "pickup_location_id": 1,
    "selected_promotion_id": null, "selected_gift_product_id": null
  }
  → OrderCreateRequest validation
    → fulfillment_type must be "pickup" (delivery → 422 validation error)
    → pickup_location_id required (exists:pickup_locations,id)
  → ... same order creation ...
  → handleCashierQrPayment($order)
    → Transaction::create(payment_method=pay_at_cashier, status=pending)
    → finalizeInventory
  → Response: 200
     { "status": 200, "message": "Checkout successful", "success": true, "data": { "order_id": 1 } }
```

> No QR code is generated. The order is settled later by the cashier via `Flow 6` (`markCashierPaid`).

pay_at_cashier + delivery is rejected by validation:
```
  → Response: 422
     { "fulfillment_type": ["When choosing pay at cashier, you should choose pickup fulfillment type."] }
```

---

## Flow 5: Payment Callback

```
ANY /callback?paymentId=GTX123 (public)
  → paymentId present?
    ├─ NO → Response: 400 { "status": 400, "message": "Missing payment ID", "success": false }
    └─ YES → continue
  → Find Transaction (gateway_transaction_id OR invoice_id)
  → Gateway::verifyPayment(paymentId)
  → GatewayResult:
    ├─ success=true
    ├─ gatewayTransactionId, amount, currency
    └─ rawResponse
  → Update transaction
  → Amount/currency mismatch?
    ├─ YES: cancel order, release cart
    │        web → redirect /payment/failed?status=failed&message=...&payment_id=GTX123
    │        mobile → 400 { "status":400, "message":"Payment failed", "success":false, "data":{ "status":"failed", "message":"...", "payment_id":"GTX123" } }
    └─ NO:
      → finalizeItemsByShippingMethod(cart, SCHEDULED)
      → changeOrderStatus(invoice_id, 'completed')
      → event(PaymentSucceeded)
      → web → redirect /payment/success?status=success&message=Payment successful&payment_id=GTX123&order_id=42
      → mobile → 200 { "status":200, "message":"Checkout successful", "success":true, "data":{ "status":"success", "message":"Payment successful", "payment_id":"GTX123", "order_id":42 } }
```

---

## Flow 6: Mark COD/Cashier Paid

```
POST /cod/{id}/mark-paid [auth:sanctum + permission:update-order-status]
POST /cashier/{id}/mark-paid [auth:sanctum + permission:update-order-status]
Request body: none
  → Order::findOrFail(id)  (404 if missing)
  → markCodAsPaid($order) or markCashierPaid($order)
    ┌─ DB::transaction
    │  Transaction::lockForUpdate (payment_method, status=pending)
    │  → update: status=paid, paid_at=now
    │  Order::update: status=completed
    │  recordCouponUsage
    │  event(PaymentSucceeded)
    └─ DB::commit
  → No pending transaction?
    → 422 { "status": 422, "message": "No pending COD transaction found." / "No pending Pay at Cashier transaction found.", "success": false }
  → Response: 200
     { "status": 200, "message": "Payment successful", "success": true }
```

---

## Flow 7: Fast Shipping Checkout

```
POST /fast-shipping/checkout [auth:sanctum]
Request body:
  {
    "name": "John Doe", "user_phone": "+1-555-0123", "user_email": "john@example.com",
    "address": { "street": "123 Main St" },
    "payment_method": "online", "gateway": "myfatoorah",
    "fulfillment_type": "delivery", "governorate_id": 1,
    "pickup_location_id": null, "selected_promotion_id": null, "selected_gift_product_id": null
  }
  → FastCheckoutRequest validation
    → Required: name, user_phone, user_email, address, governorate_id (always)
    → pickup_location_id required when fulfillment_type=pickup
  → ensureCartReservation
  → FastShippingService::createFastOrder($request) (shipping_method=fast)
  → Payment routing (same as Flows 2-4):
    → online → 200 { data: { url } }
    → cod → 200 { data: { order_id } }
    → pay_at_cashier → 200 { data: { order_id } }
  → COD+pickup → 422
```
