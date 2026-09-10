# Checkout Module — Frontend Integration Guide

Base URL: `/api/v1/general`

## Response Envelope

```json
{
  "status": 200,
  "message": "Checkout successful",
  "success": true,
  "data": {}
}
```

Validation errors (422) return the raw Laravel validator errors object (no envelope):
```json
{ "field": ["error message"] }
```

---

### 1. GET /api/v1/general/checkout/promotions — Eligible Promotions

**Authentication:** Required (`auth:sanctum`)

**Request:** No body.

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
        "gift_items": []
      }
    ]
  }
}
```

**Response 400 (no cart / empty cart):**
```json
{ "status": 400, "message": "Cart not found", "success": false }
```

**Response 401:** `{ "message": "Unauthenticated." }`

---

### 2. POST /api/v1/general/checkout — Place Order

**Authentication:** Required (`auth:sanctum`)

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
| payment_method | Optional | — | in:`online`,`cod`,`pay_at_cashier` — **default `online`** |
| gateway | Optional | Only for `payment_method=online` | string, max:50 — **default `myfatoorah`** |
| fulfillment_type | Optional | — | in:`delivery`,`pickup` — **default `delivery`**; **only `pickup` allowed when `payment_method=pay_at_cashier`** |
| governorate_id | Conditional | **Required when `fulfillment_type=delivery`** | integer, exists:governorates,id |
| pickup_location_id | Conditional | **Required when `fulfillment_type=pickup`** | integer, exists:pickup_locations,id |
| selected_promotion_id | Optional | — | nullable, integer, exists:promotions,id |
| selected_gift_product_id | Optional | — | nullable, integer, exists:products,id |
| type | Optional | — | in:`web`,`mobile` — controls callback format |

#### Compatibility Matrix

| Payment | Delivery | Pickup |
|---------|:--------:|:------:|
| online | ✅ | ✅ |
| cod | ✅ | ❌ 422 |
| pay_at_cashier | ❌ 422 (validation) | ✅ |

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

**200 — COD:**
```json
{
  "status": 200,
  "message": "Your order has been placed. You will pay upon delivery.",
  "success": true,
  "data": { "order_id": 1 }
}
```

**200 — Pay at Cashier:**
```json
{
  "status": 200,
  "message": "Checkout successful",
  "success": true,
  "data": { "order_id": 1 }
}
```
No QR code / `transaction_uuid` in the response.

**400 — Cart not found:**
```json
{ "status": 400, "message": "Cart not found", "success": false }
```

**400 — Cart reservation failed:**
```json
{ "status": 400, "message": "<error>", "success": false }
```

**422 — COD + pickup (business rule):**
```json
{ "status": 422, "message": "COD is not available for pickup. Use pay_at_cashier instead.", "success": false }
```

**422 — pay_at_cashier + delivery (validation):**
```json
{ "fulfillment_type": ["When choosing pay at cashier, you should choose pickup fulfillment type."] }
```

**422 — Field validation errors (raw):**
```json
{
  "name": ["The name field is required."],
  "address": ["The address field is required."],
  "governorate_id": ["The selected governorate id is invalid."]
}
```

**422 — Minimum order / business rule:**
```json
{ "status": 422, "message": "Minimum order amount is 100", "success": false }
```

**500 — Server error:**
```json
{ "status": 500, "message": "Error adding items to order", "success": false }
```

**401:** `{ "message": "Unauthenticated." }`

---

### 3. POST /api/v1/general/checkout/cod/{orderId}/mark-paid — Mark COD Paid

**Auth:** `auth:sanctum` + `permission:update-order-status`

**Request:** No body.

**Response 200:**
```json
{ "status": 200, "message": "Payment successful", "success": true }
```

**Response 422 (no pending COD transaction):**
```json
{ "status": 422, "message": "No pending COD transaction found.", "success": false }
```

**Response 401 / 403 / 404:** unauthenticated / missing permission / order not found.

---

### 4. POST /api/v1/general/checkout/cashier/{orderId}/mark-paid — Mark Cashier Paid

Settles a Pay at Cashier order. This is how payment is completed (no QR).

**Auth:** `auth:sanctum` + `permission:update-order-status`

**Request:** No body.

**Response 200:**
```json
{ "status": 200, "message": "Payment successful", "success": true }
```

**Response 422 (no pending cashier transaction):**
```json
{ "status": 422, "message": "No pending Pay at Cashier transaction found.", "success": false }
```

**Response 401 / 403 / 404:** unauthenticated / missing permission / order not found.

---

### 5. ANY /api/v1/general/checkout/callback — Payment Callback

**Auth:** None (public)

**Request:** Query (or posted) `paymentId` — **required**.

**Web flow (default) — Response 302:** Redirect to
`{app_url_frontend}/{locale}/payment/success?status=success&message=Payment successful&payment_id=GTX123&order_id=42`
or on failure:
`{app_url_frontend}/{locale}/payment/failed?status=failed&message=Payment failed&payment_id=GTX123`

**Mobile flow (`type=mobile`) — success:**
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

**Mobile flow — failure/mismatch:**
```json
{
  "status": 400,
  "message": "Payment failed",
  "success": false,
  "data": { "status": "failed", "message": "Payment failed", "payment_id": "GTX123" }
}
```

**400 (missing paymentId):**
```json
{ "status": 400, "message": "Missing payment ID", "success": false }
```

---

### 6. ANY /api/v1/general/checkout/error-callback — Error Callback

**Auth:** None (public)

**Request:** Query (or posted) `paymentId` — **required**.

**Web flow (default) — Response 302:** Redirect to
`{app_url_frontend}/{locale}/payment/failed?status=failed&error=Payment failed&payment_id=GTX123`
(or success page if the gateway actually succeeded).

**Mobile flow (`type=mobile`) — failure:**
```json
{
  "status": 400,
  "message": "Payment failed",
  "success": false,
  "data": { "status": "failed", "error": "Payment failed", "payment_id": "GTX123" }
}
```

**Mobile flow — success:**
```json
{
  "status": 200,
  "message": "Checkout successful",
  "success": true,
  "data": { "status": "success", "message": "Payment successful", "payment_id": "GTX123" }
}
```

**400 (missing paymentId):**
```json
{ "status": 400, "message": "Missing payment ID", "success": false }
```

---

### 7. GET /api/v1/general/orders — List Orders

**Auth:** `auth:sanctum`

**Request:** No body. Query params: `page`, `per_page`, sorting/filters.

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
  "meta": { "current_page": 1, "last_page": 5, "total": 42 }
}
```

**Response 401:** `{ "message": "Unauthenticated." }`

---

### 8. GET /api/v1/general/orders/invoice/{uuid} — Order Invoice

**Auth:** `auth:sanctum`

**Response 200:** `CustomerInvoiceResource` payload.

**Response 403 (not your invoice):** `{ "status": 403, "message": "Not authorized", "success": false }`

**Response 404:** not found.

---

## Frontend Usage

### State Handling

| State | Behavior |
|-------|----------|
| **Form loading** | Skeleton form |
| **Promotions loading** | Spinner on promotion section |
| **No promotions** | Hide section |
| **Submitting** | Button spinner, fields disabled |
| **COD success** | Order confirmation page |
| **Online success** | Redirect/open payment URL |
| **Cashier success** | Order confirmation page — no QR code |
| **Min order not met** | Show banner "Minimum order amount is X" |
| **Validation error** | Inline errors |
| **Callback success** | Success page with order ID |
| **Callback failed** | Failure page with error |

### What to Send When (checkout form)

- Always send: `name`, `user_phone`, `user_email`, `address`.
- Payment `online`: send `gateway` (defaults to `myfatoorah`).
- Delivery selected: send `governorate_id`.
- Pickup selected: send `pickup_location_id`; `governorate_id` is not required.
- `pay_at_cashier` selected: force `fulfillment_type=pickup` and send `pickup_location_id`; do not allow delivery.
- `cod` selected: force `fulfillment_type=delivery`; do not allow pickup.
- Mobile apps: send `type=mobile` so callbacks return JSON instead of redirects.
