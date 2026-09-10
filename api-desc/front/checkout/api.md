# API Reference — Checkout Module

Base URL: `/api/v1/general`

## Response Envelope

All authenticated API responses use the `ApiResponse` envelope:

```json
{
  "status": 200,
  "message": "Checkout successful",
  "success": true,
  "data": {}
}
```

- `status` — HTTP status code
- `message` — translated, user-facing message
- `success` — `true`/`false`
- `data` — present only when non-empty

**Validation errors (422)** do NOT use the envelope. They return the raw Laravel validator errors object:

```json
{
  "name": ["The name field is required."],
  "governorate_id": ["The governorate id field is required when fulfillment type is delivery."]
}
```

---

### GET /api/v1/general/checkout/promotions

List eligible promotions and gift items for the user's cart.

**Auth:** `auth:sanctum`

**Request:** No body. No query params.

**Response 200:**
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "eligible_promotions": [
      {
        "id": 1,
        "type": "buy_x_get_y",
        "title": "Buy 2 Get 1 Free",
        "code": "BUY2GET1",
        "discount": 100.00,
        "gift_items": [
          { "id": 20, "name": "Free Item", "thumbnail": "...", "price": 0.00, "quantity": 1 }
        ]
      }
    ]
  }
}
```

**Response 400 (no cart / empty cart):**
```json
{ "status": 400, "message": "Cart not found", "success": false }
```

**Response 401 (unauthenticated):** `{ "message": "Unauthenticated." }`

---

### POST /api/v1/general/checkout

Place order. Supports `online`, `cod`, `pay_at_cashier`.

**Auth:** `auth:sanctum`

#### Request Body

```json
{
  "name": "John Doe",
  "user_phone": "+1-555-0123",
  "user_email": "john@example.com",
  "address": { "street": "123 Main St", "building": "5", "apartment": "3" },
  "notes": "Leave at door",
  "payment_method": "online",
  "gateway": "myfatoorah",
  "fulfillment_type": "delivery",
  "governorate_id": 1,
  "pickup_location_id": null,
  "selected_promotion_id": null,
  "selected_gift_product_id": null,
  "type": "web"
}
```

#### Field Rules — Required & When Required

| Field | Required | When required | Rules |
|-------|----------|---------------|-------|
| name | ✅ Always | — | string, max:255 |
| user_phone | ✅ Always | — | string, max:255 |
| user_email | ✅ Always | — | email, max:255 |
| address | ✅ Always | — | array (empty `{}` allowed for pickup) |
| notes | Optional | — | nullable, string |
| payment_method | Optional | — | in:`online`,`cod`,`pay_at_cashier`. **Default `online`** if omitted |
| gateway | Optional | Only used for `payment_method=online` | string, max:50. **Default** `config('payment.default_gateway')` = `myfatoorah` |
| fulfillment_type | Optional | — | in:`delivery`,`pickup`. **Default `delivery`** if omitted. **If `payment_method=pay_at_cashier`, only `pickup` is allowed** (delivery is rejected) |
| governorate_id | Conditional | **Required when `fulfillment_type=delivery`** | integer, exists:governorates,id |
| pickup_location_id | Conditional | **Required when `fulfillment_type=pickup`** | integer, exists:pickup_locations,id |
| selected_promotion_id | Optional | — | nullable, integer, exists:promotions,id |
| selected_gift_product_id | Optional | — | nullable, integer, exists:products,id |
| type | Optional | — | in:`web`,`mobile` (controls callback format). Default `web` |

#### Fulfillment × Payment Compatibility

| Payment | Delivery | Pickup |
|---------|:--------:|:------:|
| online | ✅ | ✅ |
| cod | ✅ | ❌ → 422 |
| pay_at_cashier | ❌ → 422 validation | ✅ |

#### Responses

**200 — Online Payment:**
```json
{
  "status": 200,
  "message": "Checkout successful",
  "success": true,
  "data": { "url": "https://sandbox.myfatoorah.com/pay/INV-123" }
}
```
Frontend redirects the user to this URL.

**200 — COD:**
```json
{
  "status": 200,
  "message": "Your order has been placed. You will pay upon delivery.",
  "success": true,
  "data": { "order_id": 42 }
}
```

**200 — Pay at Cashier:**
```json
{
  "status": 200,
  "message": "Checkout successful",
  "success": true,
  "data": { "order_id": 42 }
}
```
No QR code or `transaction_uuid` is returned.

**400 — Cart not found (no active cart):**
```json
{ "status": 400, "message": "Cart not found", "success": false }
```

**400 — Cart reservation failed:**
```json
{ "status": 400, "message": "<reservation error message>", "success": false }
```

**422 — COD + pickup (business rule, envelope):**
```json
{ "status": 422, "message": "COD is not available for pickup. Use pay_at_cashier instead.", "success": false }
```

**422 — pay_at_cashier + delivery (validation, raw errors):**
```json
{
  "fulfillment_type": ["When choosing pay at cashier, you should choose pickup fulfillment type."]
}
```

**422 — Validation errors (raw errors object):**
```json
{
  "name": ["The name field is required."],
  "address": ["The address field is required."],
  "governorate_id": ["The selected governorate id is invalid."]
}
```

**422 — Minimum order amount not met (`InvalidArgumentException`):**
```json
{ "status": 422, "message": "Minimum order amount is 100", "success": false }
```
Checked against **subtotal** (pre-discount). Also 422 for other business-rule `InvalidArgumentException`s.

**422 — Unsupported gateway / currency (online):**
```json
{ "status": 422, "message": "<gateway error>", "success": false }
```

**500 — Order creation failed:**
```json
{ "status": 500, "message": "Error adding items to order", "success": false }
```

**500 — Online order total ≤ 0 / gateway invoice / transaction creation failed:**
```json
{ "status": 500, "message": "Failed to create order, please try again", "success": false }
```

**401 (unauthenticated):** `{ "message": "Unauthenticated." }`

---

### POST /api/v1/general/checkout/cod/{orderId}/mark-paid

Mark a COD order as paid. Admin only.

**Auth:** `auth:sanctum` + `permission:update-order-status`

**Request:** No body.

**Response 200:**
```json
{ "status": 200, "message": "Payment successful", "success": true }
```

**Response 404 (order not found):** standard Laravel 404.

**Response 422 (no pending COD transaction):**
```json
{ "status": 422, "message": "No pending COD transaction found.", "success": false }
```

**Response 401 / 403:** unauthenticated / missing `update-order-status` permission.

---

### POST /api/v1/general/checkout/cashier/{orderId}/mark-paid

Mark a Pay at Cashier order as paid. Admin only. This is how the cashier settles the order — no QR involved.

**Auth:** `auth:sanctum` + `permission:update-order-status`

**Request:** No body.

**Response 200:**
```json
{ "status": 200, "message": "Payment successful", "success": true }
```

**Response 404 (order not found):** standard Laravel 404.

**Response 422 (no pending cashier transaction):**
```json
{ "status": 422, "message": "No pending Pay at Cashier transaction found.", "success": false }
```

**Response 401 / 403:** unauthenticated / missing `update-order-status` permission.

---

### ANY /api/v1/general/checkout/callback

Gateway success callback (MyFatoorah redirects the user here).

**Auth:** None (public)

**Request:** Query parameter `paymentId` (or posted `paymentId`) is **required**.

**Response 400 (missing paymentId):**
```json
{ "status": 400, "message": "Missing payment ID", "success": false }
```

**Response (web, default):** 302 Redirect to
`{app_url_frontend}/{locale}/payment/success?status=success&message=Payment successful&payment_id=GTX123&order_id=42`
or on failure:
`{app_url_frontend}/{locale}/payment/failed?status=failed&message=Payment failed&payment_id=GTX123`

**Response (mobile, `type=mobile` or stored callback type) — success:**
```json
{
  "status": 200,
  "message": "Checkout successful",
  "success": true,
  "data": {
    "status": "success",
    "message": "Payment successful",
    "payment_id": "GTX123",
    "order_id": 42
  }
}
```

**Response (mobile) — failure / mismatch:**
```json
{
  "status": 400,
  "message": "Payment failed",
  "success": false,
  "data": {
    "status": "failed",
    "message": "Payment failed",
    "payment_id": "GTX123"
  }
}
```

**Response 500 (unsupported gateway):** `{ "status": 500, "message": "Payment gateway is unavailable", "success": false }`

---

### ANY /api/v1/general/checkout/error-callback

Gateway failure callback (MyFatoorah redirects the user here when the payment is cancelled/rejected).

**Auth:** None (public)

**Request:** Query parameter `paymentId` (or posted `paymentId`) is **required**.

**Response 400 (missing paymentId):**
```json
{ "status": 400, "message": "Missing payment ID", "success": false }
```

**Response (web, default):** 302 Redirect to
`{app_url_frontend}/{locale}/payment/failed?status=failed&error=Payment failed&payment_id=GTX123`
(or to success page if the gateway actually reports success).

**Response (mobile, `type=mobile`) — failure:**
```json
{
  "status": 400,
  "message": "Payment failed",
  "success": false,
  "data": {
    "status": "failed",
    "error": "Payment failed",
    "payment_id": "GTX123"
  }
}
```

**Response (mobile) — success (gateway reports payment actually succeeded):**
```json
{
  "status": 200,
  "message": "Checkout successful",
  "success": true,
  "data": {
    "status": "success",
    "message": "Payment successful",
    "payment_id": "GTX123"
  }
}
```

---

### GET /api/v1/general/orders

Paginated list of the authenticated user's orders.

**Auth:** `auth:sanctum`

**Request:** No body. Optional query params: `page`, `per_page`, `order_by`, `order`, plus filters supported by `paginateForUser`.

**Response 200:**
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": [
    {
      "id": 42,
      "order_number": "ORD-00000042",
      "status": "order-pending",
      "payment_status": "payment-pending",
      "payment_method": "online",
      "fulfillment_type": "delivery",
      "total_price": 250.00,
      "created_at": "2026-07-23T12:00:00+00:00",
      "items": []
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 5,
    "total": 42
  }
}
```

**Response 401 (unauthenticated):** `{ "message": "Unauthenticated." }`

---

### GET /api/v1/general/orders/invoice/{uuid}

Fetch one of the user's order invoices by UUID.

**Auth:** `auth:sanctum`

**Request:** No body. `{uuid}` in the path.

**Response 200:** `CustomerInvoiceResource` payload.

**Response 403 (not your invoice):** `{ "status": 403, "message": "Not authorized", "success": false }`

**Response 404 (not found):** standard Laravel 404.

---

### POST /api/v1/general/fast-shipping/checkout

Fast shipping checkout. Same payment routing as `/checkout` but the order uses `shipping_method=fast` and `governorate_id` is always required.

**Auth:** `auth:sanctum`

#### Request Body

```json
{
  "name": "John Doe",
  "user_phone": "+1-555-0123",
  "user_email": "john@example.com",
  "address": { "street": "123 Main St" },
  "notes": "Leave at door",
  "payment_method": "online",
  "gateway": "myfatoorah",
  "fulfillment_type": "delivery",
  "governorate_id": 1,
  "pickup_location_id": null,
  "selected_promotion_id": null,
  "selected_gift_product_id": null
}
```

#### Field Rules

| Field | Required | When required | Rules |
|-------|----------|---------------|-------|
| name | ✅ Always | — | string, max:255 |
| user_phone | ✅ Always | — | string, max:255 |
| user_email | ✅ Always | — | email, max:255 |
| address | ✅ Always | — | array |
| notes | Optional | — | nullable, string |
| governorate_id | ✅ Always | — | integer, exists:governorates,id |
| payment_method | Optional | — | in:`online`,`cod`,`pay_at_cashier`. Default `online` |
| gateway | Optional | Only for online | string, max:50. Default `myfatoorah` |
| fulfillment_type | Optional | — | in:`delivery`,`pickup`. Default `delivery` |
| pickup_location_id | Conditional | **Required when `fulfillment_type=pickup`** | integer, exists:pickup_locations,id |
| selected_promotion_id | Optional | — | nullable, integer, exists:promotions,id |
| selected_gift_product_id | Optional | — | nullable, integer, exists:products,id |

#### Responses

Same envelopes as `POST /checkout`:
- **200 online:** `{ "data": { "url": "https://gateway.com/pay/123" } }`
- **200 COD:** `{ "data": { "order_id": 1 } }` (message = "Your order has been placed. You will pay upon delivery.")
- **200 cashier:** `{ "data": { "order_id": 1 } }` (message = "Checkout successful")
- **400:** cart not found / reservation failure
- **422:** COD+pickup, validation errors, `InvalidArgumentException`
- **500:** order creation / invoice / transaction failure

---

## Business Rules
- Requires an active cart with items
- **Minimum Order Amount:** If `settings.options.minimumOrderAmount > 0`, it is enforced against the **subtotal** (total price before discounts, promotions, coupons, or flash sales). This ensures the minimum reflects raw cart value.
- Prices recalculated in real-time at checkout
- Inventory finalized immediately for COD/cashier, on callback for online
- Order stores immutable pricing snapshots
- Coupon quota consumed only on successful payment (never returned)
- COD not available for pickup
- Pay-at-cashier requires pickup (delivery rejected with validation error)
- Pay-at-cashier does not return a QR code or `transaction_uuid` — only `order_id`; payment is settled by the cashier via `/cashier/{orderId}/mark-paid`
- Mobile clients get JSON instead of redirect (`type=mobile`)
- `payment_method` defaults to `online`; `fulfillment_type` defaults to `delivery` when omitted
