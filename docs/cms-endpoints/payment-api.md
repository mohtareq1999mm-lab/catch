# Payment API

Base path: `/api/v1/general`

---

## POST `/checkout`

Create order with payment for **scheduled delivery**.

### Auth
- `auth:sanctum`, `check-email`

### Who uses it
- Customer (web/mobile) placing a scheduled delivery order

### Services invoked (in order)
1. `CartInventoryService::getActiveCartForUser()` — finds active cart
2. `CartInventoryService::ensureCartReservation()` — locks inventory, syncs reservation
3. `OrderService::calcInvoicePrice()` — calculates promotion + coupon discounts
4. `OrderService::addItemsInOrder()` — creates order + items via `OrderCreationService`
5. `OrderCreationService::finalizeOrder()` — fires `OrderCreated` event (queued)
6. `PaymentCheckoutHandler::handleOnlinePayment()` or `handleCodPayment()` or `handleCashierQrPayment()`

### Queued listeners
- `OrderCreated` event → `SendNewOrderNotification` listener (queued via `ShouldQueue`)

### Request body

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| name | string | yes | Customer name |
| user_phone | string | yes | Customer phone |
| user_email | email | yes | Customer email |
| address | array | yes | Shipping address |
| notes | string | no | Order notes |
| selected_promotion_id | integer | no | Promotion ID |
| selected_gift_product_id | integer | no | Gift product ID |
| type | string | no | `mobile` or `web` |
| fulfillment_type | string | no | `delivery` or `pickup` (default: `delivery`) |
| payment_method | string | no | `online`, `cod`, or `pay_at_cashier` (default: `online`) |
| gateway | string | no | Gateway name (default: `myfatoorah`) |
| pickup_location_id | integer | conditional | Required if `fulfillment_type=pickup` |

### Flow
1. Gets active cart, ensures inventory reserved
2. Calculates price
3. Validates COD+pickup is not allowed
4. Creates order via `OrderService::addItemsInOrder` → `OrderCreationService::createOrder()` + `createOrderItems()` + `finalizeOrder()`
5. `OrderCreated` event dispatched (queued → sends new order notification)
6. Routes to payment handler based on `payment_method`

### Usage
```bash
curl -X POST http://localhost:8000/api/v1/general/checkout \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Ahmed",
    "user_phone": "01000000000",
    "user_email": "ahmed@example.com",
    "address": {"city": "Cairo", "street": "Main St"},
    "payment_method": "online",
    "gateway": "myfatoorah",
    "fulfillment_type": "delivery"
  }'
```

### Response (online payment)
```json
{
  "message": "Checkout Successful",
  "data": {
    "url": "https://myfatoorah.com/paymentpage..."
  }
}
```
Redirect customer to `url` for payment.

### Response (COD)
```json
{
  "message": "checkout.cod_success",
  "data": {
    "order_id": 123
  }
}
```
Order created. Admin marks paid via `/orders/{id}/mark-cod-paid`.

### Response (pay_at_cashier)
```json
{
  "message": "Checkout Successful",
  "data": {
    "order_id": 123,
    "transaction_uuid": "550e8400-e29b-41d4-a716-446655440000",
    "qr_code": "data:image/svg+xml;base64,..."
  }
}
```
Customer shows QR at store (from `qr_code` base64 data URI) or fetches it fresh via `GET /transactions/{transaction_uuid}/qr`. Admin marks paid via `/orders/{id}/mark-paid`.

### Errors
- `400` — Cart not found, cart reservation failed
- `422` — Invalid data, COD not available for pickup
- `500` — Price calculation failed, order creation failed

---

## POST `/checkout/fast`

Create order with payment for **fast shipping**.

### Auth
- `auth:sanctum`

### Who uses it
- Customer using fast shipping

### Services invoked (in order)
1. `CartInventoryService::getActiveCartForUser()`
2. `CartInventoryService::ensureCartReservation()`
3. `FastShippingService::createFastOrder()` — validates governorate, calculates totals + fee + ETA, creates order via `OrderCreationService`
4. `OrderCreationService::finalizeOrder()` — fires `OrderCreated` event (queued)
5. `PaymentCheckoutHandler::handleOnlinePayment()` or `handleCodPayment(FAST)` or `handleCashierQrPayment(FAST)`

### Queued listeners
- `OrderCreated` event → `SendNewOrderNotification` listener (queued via `ShouldQueue`)

### Request body

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| name | string | yes | Customer name |
| user_phone | string | yes | Customer phone |
| user_email | email | yes | Customer email |
| address | array | yes | Shipping address |
| governorate_id | integer | yes | Governorate ID |
| notes | string | no | Order notes |
| selected_promotion_id | integer | no | Promotion ID |
| selected_gift_product_id | integer | no | Gift product ID |
| fulfillment_type | string | no | `delivery` or `pickup` (default: `delivery`) |
| payment_method | string | no | `online`, `cod`, or `pay_at_cashier` (default: `online`) |
| gateway | string | no | Gateway name (default: `myfatoorah`) |
| pickup_location_id | integer | conditional | Required if `fulfillment_type=pickup` |

### Flow
Same as regular checkout but uses `ShippingMethod::FAST` for inventory finalization.

### Usage
```bash
curl -X POST http://localhost:8000/api/v1/general/checkout/fast \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Ahmed",
    "user_phone": "01000000000",
    "user_email": "ahmed@example.com",
    "address": {"city": "Cairo", "street": "Main St"},
    "governorate_id": 1,
    "payment_method": "cod",
    "fulfillment_type": "delivery"
  }'
```

### Responses
Same as `/checkout` (online, COD, pay_at_cashier).

---

## GET `/checkout/callback?paymentId=xxx`

Online payment success callback from MyFatoorah.

### Auth
- None (gateway redirect)

### Who uses it
- MyFatoorah redirects the customer here after payment

### Services invoked (in order)
1. `PaymentGatewayFactory::make('myfatoorah')` → resolves `MyFatoorahGateway`
2. `MyFatoorahGateway::verifyPayment(paymentId)` → calls `MyfatoraService::checkInvoice()` (HTTP POST)
3. `OrderService::changeOrderStatus()` — updates order status, transaction status, fires `OrderStatusChanged` (queued)
4. `CartInventoryService::finalizeItemsByShippingMethod()` (on success) — deducts stock
5. `CartInventoryService::releaseCart()` (on failure) — releases reserved stock
6. Event dispatches

### Queued listeners
| Event | Listener | When |
|-------|----------|------|
| `PaymentSuccess` | `SendPaymentSuccessNotification` (queued) | On payment success |
| `PaymentFailed` | `SendPaymentFailedNotification` (queued) | On payment failure |
| `OrderStatusChanged` | `SendOrderStatusChangedNotification` (queued) | Always (order status updated) |
| `OrderCancelled` | `ProductInventoryRestore` (queued) + `SendOrderCancelledNotification` (queued) | On payment failure (pending → cancelled) |

### Flow
1. Verifies payment with MyFatoorah via `paymentId`
2. Updates transaction status (`paid` or `failed`)
3. If success: changes order to `completed`, finalizes inventory, fires:
   - `OrderStatusChanged` (queued → SMS/email notification)
   - `PaymentSuccess` (queued → payment success notification)
4. If failure: cancels order, releases cart inventory, fires:
   - `OrderStatusChanged` (queued → SMS/email notification)
   - `OrderCancelled` (queued → inventory restore + cancellation notification)
   - `PaymentFailed` (queued → payment failed notification)

### Redirect (success)
`{frontend_url}/{locale}/payment/success?status=success&message=...&payment_id=xxx&order_id=xxx`

### Redirect (failure)
`{frontend_url}/{locale}/payment/failed?status=failed&message=...&payment_id=xxx`

### Mobile response (`?type=mobile`)
```json
{
  "message": "Checkout Successful",
  "data": {
    "status": "success",
    "payment_id": "xxx",
    "order_id": 123
  }
}
```

---

## GET `/checkout/error?paymentId=xxx`

Online payment error/cancel callback from MyFatoorah.

### Auth
- None (gateway redirect)

### Who uses it
- MyFatoorah redirects when user cancels or an error occurs

### Services invoked (in order)
1. `PaymentGatewayFactory::make('myfatoorah')`
2. `MyFatoorahGateway::verifyPayment(paymentId)`
3. `OrderService::changeOrderStatus()` — updates order to `cancelled`, fires `OrderStatusChanged`
4. `CartInventoryService::releaseCart()` — releases reserved stock

### Queued listeners
| Event | Listener | When |
|-------|----------|------|
| `OrderStatusChanged` | `SendOrderStatusChangedNotification` (queued) | Always |
| `OrderCancelled` | `ProductInventoryRestore` (queued) + `SendOrderCancelledNotification` (queued) | On cancellation |
| `PaymentFailed` | `SendPaymentFailedNotification` (queued) | On payment failure |

### Flow
1. Verifies payment with MyFatoorah
2. Marks transaction as `failed`
3. Cancels order, releases cart inventory
4. Fires:
   - `OrderStatusChanged` (queued)
   - `OrderCancelled` (queued)
   - `PaymentFailed` (queued)

### Redirect
`{frontend_url}/{locale}/payment/failed?status=failed&error=...&payment_id=xxx`

### Mobile response (`?type=mobile`)
```json
{
  "message": "Payment Failed",
  "data": {
    "status": "failed",
    "error": "...",
    "payment_id": "xxx"
  }
}
```

---

## POST `/orders/{orderId}/mark-cod-paid`

Admin marks a COD order as paid.

### Auth
- `auth:sanctum`, `permission:update-order-status`, `check-email`

### Who uses it
- Admin or staff when customer pays cash on delivery

### Services invoked
- `OrderService::markCodAsPaid($order)`

### Queued listeners
| Event | Listener |
|-------|----------|
| `PaymentSuccess` | `SendPaymentSuccessNotification` (queued) |

### Flow
1. Finds pending COD transaction for the order
2. Marks transaction `paid`, sets `paid_at`
3. Changes order to `completed`, records coupon usage
4. Fires `PaymentSuccess` event (queued → sends SMS/email)

### Usage
```bash
curl -X POST http://localhost:8000/api/v1/general/orders/123/mark-cod-paid \
  -H "Authorization: Bearer {admin-token}" \
  -H "Accept: application/json"
```

### Response
```json
{
  "message": "Payment successful"
}
```

### Errors
- `404` — Order not found
- `422` — No pending COD transaction found

---

## POST `/orders/{orderId}/mark-paid`

Admin marks a pay-at-cashier order as paid.

### Auth
- `auth:sanctum`, `permission:update-order-status`, `check-email`

### Who uses it
- Admin or staff when customer pays at store cashier

### Services invoked
- `OrderService::markCashierPaid($order)`

### Queued listeners
| Event | Listener |
|-------|----------|
| `PaymentSuccess` | `SendPaymentSuccessNotification` (queued) |

### Flow
1. Finds pending `pay_at_cashier` transaction for the order
2. Marks transaction `paid`, sets `paid_at`
3. Changes order to `completed`, records coupon usage
4. Fires `PaymentSuccess` event (queued)

### Usage
```bash
curl -X POST http://localhost:8000/api/v1/general/orders/123/mark-paid \
  -H "Authorization: Bearer {admin-token}" \
  -H "Accept: application/json"
```

### Response
```json
{
  "message": "Payment successful"
}
```

### Errors
- `404` — Order not found
- `422` — No pending pay_at_cashier transaction found

---

## GET `/transactions/{uuid}/qr`

Return a fresh QR code SVG for a transaction. Generated on-the-fly every request — never stored.

### Auth
- `auth:sanctum`, `check-email`

### Who uses it
- Customer or store cashier retrieving QR for a pay-at-cashier transaction

### Services invoked
- `CashierQrService::generateSvg($transaction)` — generates SVG via `chillerlan/php-qrcode`

### How to get the transaction UUID

**Option 1 — From checkout response:** When you checkout with `pay_at_cashier`, the response includes `transaction_uuid`:
```json
{
  "data": {
    "order_id": 123,
    "transaction_uuid": "550e8400-e29b-41d4-a716-446655440000",
    "qr_code": "data:image/svg+xml;base64,..."
  }
}
```

**Option 2 — From order list:** `GET /orders` now returns `transactions` with each order:
```json
{
  "data": [
    {
      "id": 123,
      "fulfillment_type": "pickup",
      "payment_method": "pay_at_cashier",
      "transactions": [
        {
          "id": 1,
          "uuid": "550e8400-e29b-41d4-a716-446655440000",
          "payment_method": "pay_at_cashier",
          "status": "pending",
          "amount": 100.00
        }
      ]
    }
  ]
}
```

### Flow
1. Looks up transaction by UUID
2. Verifies the transaction's order belongs to the authenticated user (`order.user_id === auth()->id()`)
3. Generates a fresh QR SVG and returns it with `Content-Type: image/svg+xml`

### Usage
```bash
curl http://localhost:8000/api/v1/general/transactions/550e8400-e29b-41d4-a716-446655440000/qr \
  -H "Authorization: Bearer {token}"
```

### Response
Raw SVG markup. Display as an image:
```html
<img src="http://localhost:8000/api/v1/general/transactions/550e8400-e29b-41d4-a716-446655440000/qr"
     alt="Payment QR Code" />
```

### Errors
- `404` — Transaction not found
- `403` — Unauthorized (not your transaction)

---

## GET `/orders`

List authenticated user's orders.

### Auth
- `auth:sanctum`, `check-email`

### Who uses it
- Customer viewing their orders

### Services invoked
- `OrderService::paginateForUser($request)` — paginated orders with relations

### Usage
```bash
curl http://localhost:8000/api/v1/general/orders?limit=15 \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

### Response
```json
{
  "id": 123,
  "order_number": "ORD-00000123",
  "status": "pending",
  "subtotal": 100.00,
  "discount": 10.00,
  "total": 90.00,
  "fulfillment_type": "delivery",
  "payment_method": "online",
  "payment_gateway": "myfatoorah",
  "pickup_location_id": null,
  "created_at": "2026-07-08T12:00:00Z",
  "order_items": [...]
}
```

---

## GET `/check-card-payment`

Test credit card info for MyFatoorah sandbox.

### Auth
- None

### Who uses it
- Developer testing payments in sandbox mode

### Response
```json
{
  "CardNumber": "2223000000000007",
  "CardExpiryMonthand year": "01/39",
  "CardCVV": "100"
}
```

---

## Artisan Commands

### `php artisan orders:cancel-unpaid`

Cancels unpaid pending orders that exceeded the timeout.

**Schedule:** Hourly (`withoutOverlapping`)

**Config:** `config('payment.order_timeout_hours', 72)` hours

**Services invoked:**
- `Order` model query (cursor-based)
- `Transaction` model update

**Queued listeners:**
| Event | Listener |
|-------|----------|
| `PaymentFailed` | `SendPaymentFailedNotification` (queued) |

**Flow:**
1. Finds orders where `status='pending'` AND `created_at <= now() - timeout`
2. For each: cancels order, fails pending transactions, fires `PaymentFailed`

---

### `php artisan cart:expire`

Expires abandoned carts and releases reserved stock.

**Schedule:** Hourly (`withoutOverlapping`)

**Config:** Hardcoded `CART_TTL_DAYS = 3`

**Services invoked:**
- `CartInventoryService::expireCarts()`

**Flow:**
1. Finds carts where `status='active'` AND `expires_at <= now()`
2. For each: releases reserved stock, deletes items, sets `status='expired'`

---

## Complete Queue Map

All async work is handled via Laravel queued events. Here is every queued path:

| Trigger | Event | Listener | Queue | Purpose |
|---------|-------|----------|-------|---------|
| Checkout (all methods) | `OrderCreated` | `SendNewOrderNotification` | default | Notify admin of new order |
| Payment success (all methods) | `PaymentSuccess` | `SendPaymentSuccessNotification` | default | SMS + email to customer/vendor |
| Payment failure (all methods) | `PaymentFailed` | `SendPaymentFailedNotification` | default | SMS + email to customer/vendor |
| Order status change | `OrderStatusChanged` | `SendOrderStatusChangedNotification` | default | SMS + email on status update |
| Order cancelled (completed→cancelled) | `OrderCancelled` | `ProductInventoryRestore` | default | Restore finalized stock |
| Order cancelled (any transition to cancelled) | `OrderCancelled` | `SendOrderCancelledNotification` | default | SMS + email cancellation notice |
| CancelUnpaidOrders command | `PaymentFailed` | `SendPaymentFailedNotification` | default | SMS + email |

**All events implement `ShouldQueue`** — they are dispatched synchronously but handled asynchronously by the queue worker.

---

## Service Dependency Map

```
Controller
├── CartInventoryService        (reserve, release, finalize, expire cart items)
├── OrderService                (create order, calc price, change status, mark paid)
│   ├── PromotionService        (apply promotions, calculate discounts)
│   └── OrderCreationService    (persist order + order items, fire OrderCreated)
├── FastShippingService         (fast order creation, governorate validation, ETA)
│   ├── FastShippingRepository  (shipping rules, fees, validation)
│   ├── PromotionService
│   └── OrderCreationService
├── PaymentCheckoutHandler      (orchestrate payment method flow)
│   ├── PaymentGatewayFactory   (resolve gateway by name)
│   │   └── MyFatoorahGateway   (createInvoice, verifyPayment)
│   │       └── MyfatoraService (HTTP client for MyFatoorah API)
│   ├── CashierQrService        (generate QR SVG via chillerlan/php-qrcode)
│   └── CartInventoryService
└── PaymentGatewayFactory       (also used directly in callbacks)
    └── MyFatoorahGateway
        └── MyfatoraService
```

---

## Business Rules

- **COD + Pickup** is not allowed. Returns `422` with message: `"COD is not available for pickup. Use pay_at_cashier instead."`
- **Pickup** requires `pickup_location_id` (validates against `resources` table)
- **Online payment** creates a pending transaction before redirecting to MyFatoorah
- **COD and Cashier** finalize inventory immediately at checkout
- **Online payment** finalizes inventory in the callback (after successful payment)
- **Unpaid pending orders** are cancelled automatically after `config('payment.order_timeout_hours', 72)` hours via the `orders:cancel-unpaid` artisan command (runs hourly)
- **QR codes** are generated on-the-fly via `chillerlan/php-qrcode` when requested (no disk storage, no DB URL). At checkout the SVG is returned as a base64 data URI; the `GET /transactions/{uuid}/qr` endpoint returns fresh SVG each request.
- **Currency** is configurable via `config('payment.default_currency', 'EGP')`
- **Default gateway** is configurable via `config('payment.default_gateway', 'myfatoorah')`
