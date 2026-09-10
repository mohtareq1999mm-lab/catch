# Checkout Module — Frontend Flow Guide

## Overview — The Full User Journey

```
User
  │
  ├── 1. Browse products → add to cart
  ├── 2. View cart
  ├── 3. Start checkout
  │       ├── Choose fulfillment: Delivery OR Pickup
  │       └── Choose payment: Online / COD / Pay at Cashier
  ├── 4. Place order → POST /checkout
  ├── 5. Handle result
  │       ├── Online       → redirect to payment gateway
  │       ├── COD          → show success screen
  │       └── Pay at Cashier → show success screen
  └── 6. Track order → GET /orders
```

---

## Step 1 — Cart Management

All cart endpoints are at `/api/v1/cart` (NOT under `v1/general`).

### Endpoints

| Action | Method | Endpoint | Description |
|--------|--------|----------|-------------|
| View cart | GET | `/api/v1/cart` | List user's carts with items |
| Add item | POST | `/api/v1/cart` | Add single product to cart |
| Bulk add | POST | `/api/v1/cart/bulk-items` | Add multiple items at once |
| Update item | PUT | `/api/v1/cart/update-item` | Change quantity or variant |
| Remove item | DELETE | `/api/v1/cart/delete-item/{itemId}` | Remove one item |
| Clear cart | DELETE | `/api/v1/cart/delete-items` | Remove all items |

### Cart Item Fields (for POST/PUT)

```json
{
  "product_id": 1,
  "product_variant_id": null,
  "quantity": 2,
  "shipping_method": "scheduled"
}
```

`shipping_method`: `"scheduled"` or `"fast"`

### Cart Response Fields

```json
{
  "id": 1,
  "items": [
    {
      "id": 1,
      "product_id": 1,
      "product_variant_id": null,
      "quantity": 2,
      "unit_price": 100.00,
      "total_price": 200.00,
      "product": { "id": 1, "name": "...", "slug": "...", "image": "..." },
      "product_variant": null
    }
  ],
  "total_price": 200.00,
  "coupon": null,
  "applied_promotion": null
}
```

### Coupon Application

Apply a coupon via `POST /api/v1/general/coupons/apply` (auth required).

The coupon discount is stored on the cart and used during checkout.

### State Handling

| State | Behavior |
|-------|----------|
| **Loading** | Skeleton product list |
| **Empty cart** | "Your cart is empty" + link to shop |
| **Adding item** | Button spinner on product card |
| **Updating qty** | Debounced input, spinner on total |
| **Removing item** | Confirm dialog, then remove |
| **Coupon error** | Inline error under coupon input |

---

## Step 2 — Load Checkout Prerequisites

Before showing the checkout form, fetch these resources in parallel:

```
┌─────────────────────────────────────────────────────┐
│  Parallel fetches                                   │
│                                                     │
│  GET /api/v1/general/checkout/promotions            │
│  GET /api/v1/general/governorates (if delivery)     │
│  GET /api/v1/general/pickup-locations (if pickup)   │
│  GET /api/v1/cart (already have from cart page)     │
└─────────────────────────────────────────────────────┘
```

### GET /checkout/promotions

**Auth:** `auth:sanctum`

Returns eligible promotions and gift products for the user's current cart.

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "eligible_promotions": [
      { "id": 1, "type": "buy_x_get_y", "title": "Buy 2 Get 1 Free", "code": "BUY2GET1", "discount": 100.0, "gift_items": [] }
    ]
  }
}
```

If no cart exists: `400 { "status": 400, "message": "Cart not found", "success": false }`

### GET /api/v1/general/governorates

**Auth:** None (public)

See `api-desc/front/governorate/frontend.md` for full details.

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": [
    { "id": 1, "name": "Cairo", "country_id": 1, "status": true, "is_fast_shipping_enabled": true }
  ]
}
```

### GET /api/v1/general/pickup-locations

**Auth:** None (public)

See `api-desc/front/pickLocation/frontend.md` for full details.

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": [
    { "id": 1, "store_name": "Downtown Branch", "address": "...", ... }
  ]
}
```

---

## Step 3 — Choose Fulfillment & Payment

The checkout has two independent dimensions:

```
            ┌──────────────┬──────────────────┬──────────────────┐
            │              │    Delivery       │     Pickup       │
├───────────┼──────────────┼──────────────────┼──────────────────┤
│  Online   │     ✅       │     ✅           │     ✅           │
│  COD      │     ✅       │     ✅           │     ❌           │
│  Cashier  │     ✅       │     ❌           │     ✅           │
└───────────┴──────────────┴──────────────────┴──────────────────┘
```

### Rules

| Fulfillment | Available Payments | Requirements |
|-------------|-------------------|--------------|
| **Delivery** | Online, COD | `governorate_id`, `address`, `name`, `phone` |
| **Pickup** | Online, Pay at Cashier | `pickup_location_id` (no address needed) |

### What NOT to Show

| Scenario | Hide / Disable |
|----------|---------------|
| Pickup selected | Hide COD option entirely |
| Delivery selected | Hide "Pay at Cashier" option entirely |
| Fast shipping items | (fast shipping has its own separate flow) |

---

## Step 4 — Submit Order

### POST /api/v1/general/checkout

**Auth:** `auth:sanctum`

### Request Body

```json
{
  "name": "John Doe",
  "user_phone": "+201234567890",
  "user_email": "john@example.com",
  "address": { "street": "12 Main St", "building": "5", "apartment": "3" },
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

### Validation Rules — Required & When Required

| Field | Required | When required | Rules |
|-------|----------|---------------|-------|
| name | ✅ Always | — | string, max:255 |
| user_phone | ✅ Always | — | string, max:255 |
| user_email | ✅ Always | — | email, max:255 |
| address | ✅ Always | — | array (empty `{}` allowed for pickup) |
| notes | Optional | — | nullable, string |
| payment_method | Optional | — | in:online,cod,pay_at_cashier — **default `online`** |
| gateway | Optional | Only for `payment_method=online` | string, max:50 — **default `myfatoorah`** |
| fulfillment_type | Optional | — | in:delivery,pickup — **default `delivery`**; **only `pickup` allowed when `payment_method=pay_at_cashier`** |
| governorate_id | Conditional | **Required when `fulfillment_type=delivery`** | integer, exists:governorates,id |
| pickup_location_id | Conditional | **Required when `fulfillment_type=pickup`** | integer, exists:pickup_locations,id |
| selected_promotion_id | Optional | — | nullable, integer, exists:promotions,id |
| selected_gift_product_id | Optional | — | nullable, integer, exists:products,id |
| type | Optional | — | in:web,mobile — controls callback format |

### Compatibility Matrix

| Payment | Delivery | Pickup |
|---------|:--------:|:------:|
| online | ✅ | ✅ |
| cod | ✅ | ❌ 422 |
| pay_at_cashier | ❌ 422 (validation) | ✅ |

### Responses by Payment Method

**Online Payment — 200:**
```json
{
  "status": 200,
  "message": "Checkout successful",
  "success": true,
  "data": {
    "url": "https://sandbox.myfatoorah.com/pay/INV-123"
  }
}
```
→ Frontend redirects user to this URL.

**COD — 200:**
```json
{
  "status": 200,
  "message": "Your order has been placed. You will pay upon delivery.",
  "success": true,
  "data": {
    "order_id": 42
  }
}
```
→ Frontend shows success page with order number.

**Pay at Cashier — 200:**
```json
{
  "status": 200,
  "message": "Checkout successful",
  "success": true,
  "data": {
    "order_id": 42
  }
}
```
→ Frontend shows success screen with order number. No QR code is returned or displayed; payment is settled when the cashier marks the order paid at the branch.

### Error Responses

**400 — Cart not found:**
```json
{ "status": 400, "message": "Cart not found", "success": false }
```

**422 — COD + Pickup (business rule, envelope):**
```json
{ "status": 422, "message": "COD is not available for pickup. Use pay_at_cashier instead.", "success": false }
```

**422 — Pay at Cashier + Delivery (validation, raw errors object):**
```json
{
  "fulfillment_type": ["When choosing pay at cashier, you should choose pickup fulfillment type."]
}
```

**422 — Field validation errors (raw errors object, no envelope):**
```json
{
  "name": ["The name field is required."],
  "address": ["The address field is required."],
  "governorate_id": ["The selected governorate id is invalid."]
}
```

**422 — Minimum order amount (business rule):**
```json
{ "status": 422, "message": "Minimum order amount is 100", "success": false }
```

**500 — Order creation failed:**
```json
{ "status": 500, "message": "Error adding items to order", "success": false }
```

**401 — Unauthenticated:** `{ "message": "Unauthenticated." }`

---

## Step 5 — Handle Post-Checkout by Payment Type

### Online Payment Flow

```
POST /checkout → 200 { url }
     │
     ▼
Redirect user to payment gateway URL
     │
     ▼
User completes payment on gateway page
     │
     ├── Success → Gateway redirects to /callback?paymentId=GTX123
     │              │
     │              ▼
     │         Backend verifies payment
     │         ┌─────────────────────────────────────┐
     │         │ 1. Find transaction                 │
     │         │ 2. Gateway::verifyPayment(paymentId) │
     │         │ 3. Validate amount/currency          │
     │         │ 4. If mismatch → cancel order        │
     │         │ 5. If valid → complete order         │
     │         └─────────────────────────────────────┘
     │              │
     │              ▼
     │         Redirect: /payment/success?status=success&message=Payment successful&payment_id=GTX123&order_id=42
     │         (mobile: 200 { "status":"success", "message":"Payment successful", "payment_id":"GTX123", "order_id":42 })
     │
     └── Failure → Gateway redirects to /error-callback?paymentId=GTX123
                   │
                   ▼
              Redirect: /payment/failed?status=failed&error=Payment failed&payment_id=GTX123
              (mobile: 400 { "status":"failed", "error":"Payment failed", "payment_id":"GTX123" })
```

**Callback details:**
- `ANY /api/v1/general/checkout/callback` — **public**, requires query `paymentId` (missing → `400 { "message": "Missing payment ID" }`).
- `ANY /api/v1/general/checkout/error-callback` — **public**, requires query `paymentId`.
- Web (`type=web`, default): 302 redirect to the frontend success/failure page.
- Mobile (`type=mobile`): JSON response instead of redirect (see payloads above).

**Frontend Responsibilities:**
- Show loading spinner during redirect
- Handle the callback URLs in your frontend router (`/payment/success`, `/payment/failed`)
- On success page: show order number, order details, "View Order" button
- On failure page: show error message, "Retry Payment" button (retry = start checkout again)

### COD Flow

```
POST /checkout → 200 { order_id }
     │
     ▼
Show success screen: "Order Placed! Pay when delivered."
     │
     ▼
Wait delivery → pay driver → status updates via admin
```

**Frontend Responsibilities:**
- Show order confirmation page with order number
- No redirect needed
- Order says "Payment pending" until driver collects

### Pay at Cashier Flow

```
POST /checkout → 200 { order_id }
     │
     ▼
Show success screen: "Order Placed! Pay at the store."
     │
     ▼
Customer visits the store and pays the cashier
     │
     ▼
Cashier marks order paid (backend /cashier/{orderId}/mark-paid)
     │
     ▼
Order status → completed, payment_status → payment-cash
```

**Frontend Responsibilities:**
- Show order confirmation page with order number
- No QR code is generated or displayed — there is nothing to scan
- Order says "Payment pending" until the cashier settles it
- Update the payment badge once the order reaches `payment-cash`

---

## Step 6 — Pay at Cashier Behavior

The QR code feature was removed. Pay at Cashier no longer generates or displays any QR code, and there is no re-fetch endpoint.

### What Happens Instead

1. Checkout returns only `{ order_id }`.
2. The order stores a `pay_at_cashier` transaction (pending) so it can be located by the cashier in the admin panel.
3. The cashier marks the order paid via `POST /api/v1/general/checkout/cashier/{orderId}/mark-paid` (admin, `permission:update-order-status`).
4. Once settled, the order's `payment_status` becomes `payment-cash`.

### Confirmation Screen Content

```
┌──────────────────────────┐
│      Order Placed!       │
│                          │
│  Order: ORD-00000042     │
│  Pay at the store.       │
│  Amount: EGP 250.00      │
│                          │
│  Status: Pending Payment │
│                          │
│  [Back to Orders]        │
└──────────────────────────┘
```

### Mark-Paid Endpoints (Admin)

Both endpoints are for the admin panel, **not** the customer app. They settle pending payment transactions.

**`POST /api/v1/general/checkout/cod/{orderId}/mark-paid`** — mark COD order paid.
**`POST /api/v1/general/checkout/cashier/{orderId}/mark-paid`** — mark Pay at Cashier order paid.

- **Auth:** `auth:sanctum` + `permission:update-order-status`
- **Request body:** none
- **Response 200:** `{ "status": 200, "message": "Payment successful", "success": true }`
- **Response 422:** `{ "status": 422, "message": "No pending COD transaction found." }` (or `"No pending Pay at Cashier transaction found."`)
- **Response 401 / 403 / 404:** unauthenticated / missing permission / order not found

---

## Step 7 — Track Orders

### GET /api/v1/general/orders

**Auth:** `auth:sanctum`

Returns paginated list of the user's orders.

**Response:**
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
      "items": [...]
    }
  ],
  "meta": { "current_page": 1, "last_page": 5, "total": 42 }
}
```

### Order Status Values (for progress timeline)

```
order-pending
     ↓
order-processing
     ↓
order-at-local-facility OR order-ready-for-pickup
     ↓
order-out-for-delivery
     ↓
order-completed
```

Also: `order-cancelled`, `order-refunded`, `order-failed`

### Payment Status Values (computed)

| Status | Meaning |
|--------|---------|
| `payment-pending` | Not paid yet |
| `payment-processing` | Payment in progress |
| `payment-success` | Paid successfully |
| `payment-failed` | Payment failed |
| `payment-cash-on-delivery` | COD (will be collected) |
| `payment-cash` | Paid at cashier |

### Orders Screen

```
┌──────────────────────────────────────────────┐
│  Orders                                      │
│                                              │
│  ┌────────────────────────────────────────┐  │
  │  │ ORD-00000042         Jul 23, 2026      │  │
  │  │ Status: Pending                        │  │
  │  │ Payment: Pending (Pay at Cashier)      │  │
  │  │ Total: EGP 250.00                      │  │
  │  │                              [Details] │  │
  │  └────────────────────────────────────────┘  │
  │  ┌────────────────────────────────────────┐  │
  │  │ ORD-00000041         Jul 22, 2026      │  │
  │  │ Status: Delivered                      │  │
  │  │ Payment: Paid (Online)                 │  │
  │  │ Total: EGP 150.00                      │  │
  │  │                              [Details] │  │
  │  └────────────────────────────────────────┘  │
  └──────────────────────────────────────────────┘
```

Pay at Cashier orders display a "Payment pending (Pay at Cashier)" badge. There is no QR button — payment is settled by the cashier at the store.

---

## Step 8 — Error & Edge Case Handling

### Online Payment

| Scenario | Frontend Action |
|----------|----------------|
| Checkout returns URL | Redirect to URL |
| User returns from gateway success | Show success page |
| User returns from gateway failure | Show failure page with retry |
| Callback redirect to /payment/success | Parse `order_id` from query, show details |
| Callback redirect to /payment/failed | Show error message |
| Payment gateway timeout | Show "Payment timed out, try again" |

### COD

| Scenario | Frontend Action |
|----------|----------------|
| Checkout returns order_id | Show success screen |
| Order never marked paid | Shows "pending" status forever |
| Order cancelled by admin | Status changes to cancelled |

### Pay at Cashier

| Scenario | Frontend Action |
|----------|----------------|
| Checkout returns order_id | Show success screen |
| Order never marked paid | Shows "pending" status until cashier settles it |
| Order cancelled by admin | Status changes to cancelled |
| Payment completed | Badge updates to "paid at store" (`payment-cash`) |

### General Errors

| Status | Meaning | Frontend Action |
|--------|---------|----------------|
| 400 | Cart not found | Redirect to cart page |
| 422 | Validation error | Show inline field errors |
| 500 | Server error | Show "Something went wrong, try again" |
| 401 | Unauthenticated | Redirect to login |

---

## UX Recommendations

- **Disable checkout button** while request is processing
- **Prevent double-submit** — track a `submitting` state
- **Refresh order status** every 30 seconds on order details screen
- **Handle expired sessions** — if checkout returns 401, save form data to sessionStorage and redirect to login
- **Mobile vs Web** — set `type: "mobile"` for mobile apps (callback returns JSON instead of redirect)
- **Governorates dropdown** — cache locally for the session, they rarely change
- **Pickup locations dropdown** — cache for 5 minutes

---

## Complete API Reference Table

| # | Method | Endpoint | Auth | Purpose |
|---|--------|----------|------|---------|
| 1 | GET | `/api/v1/general/checkout/promotions` | sanctum | Eligible promotions |
| 2 | POST | `/api/v1/general/checkout` | sanctum | Place order (online/COD/cashier) |
| 3 | POST | `/api/v1/general/checkout/cod/{orderId}/mark-paid` | sanctum + update-order-status | Mark COD paid (admin) |
| 4 | POST | `/api/v1/general/checkout/cashier/{orderId}/mark-paid` | sanctum + update-order-status | Mark cashier paid (admin) |
| 5 | ANY | `/api/v1/general/checkout/callback` | Public | Gateway success callback |
| 6 | ANY | `/api/v1/general/checkout/error-callback` | Public | Gateway failure callback |
| 7 | GET | `/api/v1/general/fast-shipping/status` | Public | Fast shipping availability |
| 8 | POST | `/api/v1/general/fast-shipping/checkout` | sanctum | Fast shipping checkout |
| 9 | GET | `/api/v1/general/orders` | sanctum | List user's orders |
| 10 | GET | `/api/v1/general/orders/invoice/{uuid}` | sanctum | Order invoice |
| 11 | GET | `/api/v1/general/governorates` | Public | Governorates dropdown |
| 12 | GET | `/api/v1/general/pickup-locations` | Public | Pickup locations dropdown |

---

## Related Documentation

| Module | File |
|--------|------|
| Governorates | `api-desc/front/governorate/frontend.md` |
| Pickup Locations | `api-desc/front/pickLocation/frontend.md` |
| Cart API | `packages/marvel/src/Http/Controllers/CartController.php` |
| Backend flow | `api-desc/front/checkout/flow.md` |
| Backend details | `api-desc/front/checkout/backend.md` |
| API reference | `api-desc/front/checkout/api.md` |
| Full payment audit | `api-desc/front/checkout/payment-audit.md` |
