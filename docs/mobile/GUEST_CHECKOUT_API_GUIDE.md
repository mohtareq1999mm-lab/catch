# Guest Checkout & Cart API Guide — Mobile Native (iOS & Android)

**Audience:** Mobile App Developers (Flutter / React Native / Swift / Kotlin)  
**API Version:** v1  
**Base URL:** `https://<api-domain>/api/v1/guest`  
**Authentication Paradigm:** Bearer Guest Token (`Authorization: Bearer <token>`). **NO COOKIES, NO CSRF REQUIRED.**

---

## 1. Core Architectural Concepts for Mobile

1. **Bearer Transport (Stateless Credential):**
   - Mobile native clients do **NOT** use cookies.
   - You must call `POST /guest/mobile/session` on app cold-start when no guest session exists.
   - The returned `token` must be stored securely (e.g., Flutter `flutter_secure_storage`, Android `EncryptedSharedPreferences`, iOS `Keychain`).
   - Every subsequent request must include: `Authorization: Bearer <token>`.

2. **No CSRF Token Required:**
   - Because native apps do not rely on ambient browser cookies, CSRF protection is automatically bypassed when the `Authorization: Bearer` header is present.

3. **Origin Header:**
   - Native mobile HTTP clients should omit the `Origin` header. If sent, it must match an allowed CORS origin.

4. **Never Send Calculated Monetary Values:**
   - Never send `price`, `subtotal`, `discount`, or `total` in any request. The backend pricing engine calculates authoritative totals.

5. **Token Storage & Persistence:**
   - When a guest completes checkout, save `order_number`, `tracking_token`, and `cancellation_token` in local storage associated with the order.

---

## 2. End-to-End Mobile Flow Diagram

```
[Cold Start: No Token]
       │
       ▼
1. POST /mobile/session  ───► Save Bearer token in Secure Storage
       │
       ▼
2. GET /cart             ───► Fetch current cart state (returns empty if new)
       │
       ▼
3. POST /cart/items      ───► Add products to cart
   PUT /cart/items       ───► Increment / decrement quantities
   DELETE /cart/items/{id}──► Remove item
       │
       ▼
4. POST /checkout        ───► Place Order (returns order, tracking_token, cancellation_token)
       │
       ├─────────────────────────────────┐
       ▼ (If Online Payment)             ▼ (If COD)
5. POST /orders/{id}/payment       Order is placed directly!
   (Redirect to WebView/Gateway)         │
       │                                 ▼
       └──────────────────────────► 6. GET /orders/{id} (Confirmation Screen)
                                         │
                                         ▼
                            [Later from anywhere / Deep Link]
                            7. POST /orders/track (with tracking_token)
                            8. POST /orders/cancel (with cancellation_token)
```

---

## 3. Endpoints Specification

---

### 1. Open Mobile Guest Session
- **Method & Path:** `POST /api/v1/guest/mobile/session`
- **What It Does (in two sentences):** Initializes a new guest identity in the database and issues an opaque Bearer guest token for mobile native clients. If a valid, usable guest session credential is already sent in the header, the backend rejects it with `409 Conflict` to prevent accidental credential duplication.
- **Where to Go Next (Flow):** Save the returned `data.token` securely in the app's local storage. Then, navigate the user to browse products or call `GET /guest/cart` to verify cart state.

**Headers:**
```http
Accept: application/json
Content-Type: application/json
```
*(Do NOT send `Authorization` header here).*

**Request Body:**
- Empty JSON object `{}`.

**Response (201 Created):**
```json
{
  "success": true,
  "message": "Guest session is ready.",
  "data": {
    "guest": {
      "expires_at": "2026-11-24T10:00:00+02:00"
    },
    "token": "10|mobile_guest_token_abc123xyz...",
    "token_type": "Bearer",
    "cart": null
  }
}
```

**Common Errors:**
- `409 Conflict`: `{"success": false, "message": "A usable guest session was presented. Reuse it or rotate the token explicitly."}` — Sent when an active Bearer token was included in the request.

---

### 2. Rotate Mobile Guest Session Token
- **Method & Path:** `POST /api/v1/guest/mobile/session/rotate`
- **What It Does (in two sentences):** Immediately invalidates the current mobile Bearer token and issues a fresh replacement for the same guest identity and cart. This allows the mobile app to refresh the credential periodically without losing items currently in the cart.
- **Where to Go Next (Flow):** Overwrite the previously stored token in secure storage with `data.token`. Continue normal app flow seamlessly.

**Headers:**
```http
Accept: application/json
Content-Type: application/json
Authorization: Bearer <current_mobile_token>
```

**Request Body:**
- Empty JSON object `{}`.

**Response (200 OK):**
```json
{
  "success": true,
  "message": "Guest session token has been rotated.",
  "data": {
    "token": "11|new_rotated_mobile_token_xyz...",
    "token_type": "Bearer",
    "expires_at": "2026-11-24T10:00:00+02:00"
  }
}
```

---

### 3. Revoke Mobile Guest Session (Logout / Reset)
- **Method & Path:** `DELETE /api/v1/guest/mobile/session`
- **What It Does (in two sentences):** Permanently marks the mobile guest identity as revoked and destroys the token on the server. Any future requests with this token will fail with 401 Unauthorized.
- **Where to Go Next (Flow):** Delete the token from mobile secure storage. If the user continues browsing as a guest, call `POST /guest/mobile/session` again to start fresh.

**Headers:**
```http
Accept: application/json
Authorization: Bearer <mobile_token>
```

**Request Body:**
- None.

**Response (200 OK):**
```json
{
  "success": true,
  "message": "Guest session has been revoked.",
  "data": null
}
```

---

### 4. Get Guest Cart
- **Method & Path:** `GET /api/v1/guest/cart`
- **What It Does (in two sentences):** Retrieves the authoritative server-calculated cart for the authenticated guest identity, including recalculated item prices, tax, and totals. If the guest has no active items or no token has been opened yet, it gracefully returns `200 OK` with `cart: null` instead of failing.
- **Where to Go Next (Flow):** Render the Cart screen. If `data.cart` is null or `items` is empty, show the "Empty Cart" UI; otherwise show items and proceed to the Checkout button.

**Headers:**
```http
Accept: application/json
Authorization: Bearer <mobile_token>
```

**Request Body:**
- None.

**Response (200 OK — With Items):**
```json
{
  "success": true,
  "message": "Data fetched successfully",
  "data": {
    "cart": {
      "id": 45,
      "total": 1250.00,
      "subtotal": 1200.00,
      "shipping_cost": 50.00,
      "items": [
        {
          "id": 112,
          "product_id": 15,
          "product_variant_id": 4,
          "quantity": 2,
          "unit_price": 600.00,
          "subtotal": 1200.00,
          "shipping_method": "scheduled",
          "product": {
            "id": 15,
            "name": "Sample Product"
          }
        }
      ]
    }
  }
}
```

**Response (200 OK — Empty Cart):**
```json
{
  "success": true,
  "message": "Data fetched successfully",
  "data": {
    "cart": null
  }
}
```

---

### 5. Add Item to Guest Cart
- **Method & Path:** `POST /api/v1/guest/cart/items`
- **What It Does (in two sentences):** Adds a product and optional variant into the guest's persistent cart, validating stock availability and acceptable shipping methods. It recalculates the updated cart state and returns the fresh cart structure.
- **Where to Go Next (Flow):** Update the local cart state/badge counter in the UI, show a success snackbar ("Added to Cart"), and allow the user to either continue shopping or navigate to the Cart screen.

**Headers:**
```http
Accept: application/json
Content-Type: application/json
Authorization: Bearer <mobile_token>
```

**Request Body:**
```json
{
  "item": {
    "product_id": 15,
    "quantity": 2,
    "product_variant_id": 4,
    "shipping_method": "scheduled",
    "attributes": []
  }
}
```

| Field | Type | Required | Description & Validation |
|---|---|---|---|
| `item.product_id` | integer | Yes | Must exist in `products` table. |
| `item.quantity` | integer | Yes | Minimum 1, maximum capped by `cart.max_item_quantity` (default 100). |
| `item.product_variant_id` | integer | Optional | Must exist and belong to the same `product_id`. |
| `item.shipping_method` | string | Yes | Allowed values: `"scheduled"` or `"fast"`. |
| `item.attributes` | array | Optional | Custom product attributes. |

**Response (201 Created):**
```json
{
  "success": true,
  "message": "Cart created successfully",
  "data": {
    "cart": {
      "id": 45,
      "total": 1250.00,
      "items": [ ... ]
    }
  }
}
```

**Common Errors:**
- `422 Unprocessable Entity`: Out of stock, invalid variant, or unallowed shipping method.

---

### 6. Update Item Quantity in Guest Cart
- **Method & Path:** `PUT /api/v1/guest/cart/items`
- **What It Does (in two sentences):** Increases or decreases the quantity of an existing item in the guest cart using atomic operations (`increment` or `decrement`). If a decrement drops quantity to 0, the item is removed from the cart.
- **Where to Go Next (Flow):** Update the quantities and recalculate order summary in the Cart view.

**Headers:**
```http
Accept: application/json
Content-Type: application/json
Authorization: Bearer <mobile_token>
```

**Request Body:**
```json
{
  "item": {
    "product_id": 15,
    "quantity": 1,
    "product_variant_id": 4,
    "operation": "increment"
  }
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `item.product_id` | integer | Yes | Product ID being modified. |
| `item.quantity` | integer | Yes | Delta quantity (usually `1`). |
| `item.product_variant_id` | integer | Optional | Variant ID if the product has variants. |
| `item.operation` | string | Yes | Allowed values: `"increment"` or `"decrement"`. |

**Response (200 OK):**
```json
{
  "success": true,
  "message": "Cart updated successfully",
  "data": {
    "cart": {
      "id": 45,
      "total": 1850.00,
      "items": [ ... ]
    }
  }
}
```

---

### 7. Remove Specific Item from Cart
- **Method & Path:** `DELETE /api/v1/guest/cart/items/{itemId}`
- **What It Does (in two sentences):** Deletes a specific cart line item by its primary cart item ID (`itemId`). It recalculates and returns the remaining cart contents.
- **Where to Go Next (Flow):** Remove the item row from the Cart list and update the cart badge.

**Headers:**
```http
Accept: application/json
Authorization: Bearer <mobile_token>
```

**Request Body:**
- None.

**Response (200 OK):**
```json
{
  "success": true,
  "message": "Cart item deleted successfully",
  "data": {
    "cart": {
      "id": 45,
      "total": 650.00,
      "items": [ ... ]
    }
  }
}
```

---

### 8. Clear Entire Guest Cart
- **Method & Path:** `DELETE /api/v1/guest/cart`
- **What It Does (in two sentences):** Purges all items from the current guest's cart in a single atomic call. It resets the cart balance to zero while keeping the guest session alive.
- **Where to Go Next (Flow):** Display the empty cart view and offer a "Start Shopping" button.

**Headers:**
```http
Accept: application/json
Authorization: Bearer <mobile_token>
```

**Request Body:**
- None.

**Response (200 OK):**
```json
{
  "success": true,
  "message": "Cart deleted successfully",
  "data": {
    "cart": {
      "id": 45,
      "total": 0,
      "items": []
    }
  }
}
```

---

### 9. Guest Checkout (Create Order)
- **Method & Path:** `POST /api/v1/guest/checkout`
- **What It Does (in two sentences):** Converts the guest's cart into a real order, validates dynamic flow fields and delivery addresses, and reserves inventory. It empties the cart and issues both a `tracking_token` and a `cancellation_token` in the response.
- **Where to Go Next (Flow):**
  - **If `payment.method === 'online'`:** Immediately call `POST /orders/{orderId}/payment` to fetch the online payment gateway URL and open it in an In-App Browser / WebView.
  - **If `payment.method === 'cod'`:** Save the `tracking_token` locally and navigate straight to the Order Confirmation / Success screen.

**Headers:**
```http
Accept: application/json
Content-Type: application/json
Authorization: Bearer <mobile_token>
X-Idempotency-Key: <unique-uuid-v4>
```
*(Passing `X-Idempotency-Key` is strongly recommended to prevent double-charging if connection drops).*

**Request Body:**
```json
{
  "name": "John Doe",
  "user_phone": "01012345678",
  "user_email": "john.doe@example.com",
  "fulfillment_type": "delivery",
  "payment_method": "online",
  "gateway": "myfatoorah",
  "governorate_id": 1,
  "address": {
    "street": "El-Tahrir St",
    "city": "Cairo",
    "building": "12A",
    "details": "Floor 3, Apt 5"
  },
  "shipping_type": "local",
  "notes": "Call before arrival"
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `name` | string | Yes | Customer recipient name. |
| `user_phone` | string | Yes | Contact phone number. |
| `user_email` | string | Optional | Email for order invoice and tracking link. |
| `fulfillment_type` | string | Yes | Allowed values: `"delivery"` or `"pickup"`. |
| `payment_method` | string | Yes | Allowed values: `"online"` or `"cod"`. |
| `gateway` | string | Required if online | Gateway code: `"myfatoorah"`, `"tap"`, `"stripe"`, `"tabby"`, `"tamara"`. |
| `governorate_id` | integer | Required if delivery | Governorate ID for shipping calculation. |
| `address` | object | Required if delivery | Delivery address details (`street`, `city`, etc.). |
| `pickup_location_id` | integer | Required if pickup | ID of store pickup location. |
| `shipping_type` | string | Optional | Default `"local"` (or `"international"`). |
| `flow_code` | string | Optional | Specific shipping workflow code. |
| `notes` | string | Optional | Order notes for delivery. |

**Response (201 Created):**
```json
{
  "success": true,
  "message": "Checkout successfully",
  "data": {
    "order": {
      "id": 105,
      "order_number": "MEEM-2026-105",
      "total_price": 1250.00,
      "status": "pending",
      "payment_status": "pending"
    },
    "tracking_token": "trk_abc123456789...",
    "cancellation_token": "cnc_xyz987654321...",
    "payment": {
      "method": "online",
      "gateway": "myfatoorah",
      "next": "initiate_payment"
    },
    "idempotent_replay": false
  }
}
```

---

### 10. Initiate Guest Payment
- **Method & Path:** `POST /api/v1/guest/orders/{orderId}/payment`
- **What It Does (in two sentences):** Initializes a payment session with the selected payment gateway for an order owned by this guest session. It returns the gateway redirect URL or authorization payload needed to process the payment.
- **Where to Go Next (Flow):** Open the returned `redirect_url` in an In-App Browser (e.g. Flutter `flutter_inappwebview` or `url_launcher`). Listen for payment callback / webhook, then poll `GET /orders/{orderId}` to verify that `payment_status` becomes `success`.

**Headers:**
```http
Accept: application/json
Content-Type: application/json
Authorization: Bearer <mobile_token>
X-Idempotency-Key: <unique-uuid-v4>
```

**Request Body:**
```json
{
  "payment_method": "online",
  "gateway": "myfatoorah"
}
```

**Response (200 OK):**
```json
{
  "success": true,
  "message": "Checkout successfully",
  "data": {
    "order_id": 105,
    "redirect_url": "https://portal.myfatoorah.com/payment/...",
    "payment_method": "online",
    "gateway": "myfatoorah"
  }
}
```

---

### 11. View Guest Order Details
- **Method & Path:** `GET /api/v1/guest/orders/{orderId}`
- **What It Does (in two sentences):** Fetches the full details of an order strictly verified against the caller's guest identity to prevent IDOR attacks. It also returns a boolean `can_cancel` flag indicating whether the order is currently eligible for cancellation.
- **Where to Go Next (Flow):** Render the Order Details / Confirmation screen showing items, status, and the "Cancel Order" button if `can_cancel` is true.

**Headers:**
```http
Accept: application/json
Authorization: Bearer <mobile_token>
```

**Response (200 OK):**
```json
{
  "success": true,
  "message": "Data fetched successfully",
  "data": {
    "order": {
      "id": 105,
      "order_number": "MEEM-2026-105",
      "total_price": 1250.00,
      "status": "pending",
      "payment_status": "pending",
      "order_items": [ ... ]
    },
    "can_cancel": true
  }
}
```

---

### 12. Refresh Order Access Tokens
- **Method & Path:** `POST /api/v1/guest/orders/{orderId}/access-tokens`
- **What It Does (in two sentences):** Re-issues the `tracking_token` and `cancellation_token` for an existing order owned by this guest session if the client lost them. If an admin has revoked guest tracking on this order, it will not reissue the tokens.
- **Where to Go Next (Flow):** Update locally stored tokens and refresh tracking links.

**Headers:**
```http
Accept: application/json
Authorization: Bearer <mobile_token>
```

**Response (200 OK):**
```json
{
  "success": true,
  "message": "Data fetched successfully",
  "data": {
    "order_id": 105,
    "tracking_token": "trk_abc123456789...",
    "cancellation_token": "cnc_xyz987654321...",
    "tracking_revoked": false,
    "tracking_expires_at": "2026-12-17T10:00:00+02:00"
  }
}
```

---

### 13. Public Order Tracking (Token-Authenticated)
- **Method & Path:** `POST /api/v1/guest/orders/track`
- **What It Does (in two sentences):** Allows tracking order shipment progress from anywhere (including when opened from a push notification, SMS, or deep link) using only the private `tracking_token`. It returns the current shipment milestones and event timeline without exposing sensitive financial info or cancellation tokens.
- **Where to Go Next (Flow):** Render the public Tracking & Timeline screen in the mobile app.

**Headers:**
```http
Accept: application/json
Content-Type: application/json
```
*(Bearer token is optional; the tracking token in the body is the authoritative credential).*

**Request Body:**
```json
{
  "token": "trk_abc123456789..."
}
```

**Response (200 OK):**
```json
{
  "success": true,
  "message": "Order tracking retrieved.",
  "data": {
    "order": {
      "order_number": "MEEM-2026-105",
      "status": "processing",
      "payment_status": "success",
      "fulfillment_status": "processing",
      "created_at": "2026-11-17T10:00:00+02:00",
      "last_updated_at": "2026-11-17T10:30:00+02:00"
    },
    "timeline": [
      {
        "status": "pending",
        "description": "Order placed",
        "timestamp": "2026-11-17T10:00:00+02:00"
      },
      {
        "status": "processing",
        "description": "Payment confirmed",
        "timestamp": "2026-11-17T10:05:00+02:00"
      }
    ],
    "tracking_expires_at": "2026-12-17T10:00:00+02:00"
  }
}
```

---

### 14. Request Cancellation OTP
- **Method & Path:** `POST /api/v1/guest/orders/cancel/request-otp`
- **What It Does (in two sentences):** Validates the guest's cancellation token, verifies order eligibility, and dispatches a one-time verification code (OTP) to the customer's phone number on file. In local development (`LOCAL_OTP_ENABLED=true`), external SMS is bypassed and returns `otp_id: "local-verification-id"` where code `123456` is accepted.
- **Where to Go Next (Flow):** Open the OTP Verification modal/screen, display the masked phone number, and prompt the user to enter the OTP code.

**Headers:**
```http
Accept: application/json
Content-Type: application/json
```

**Request Body:**
```json
{
  "token": "cnc_xyz987654321...",
  "phone_number": "01012345678"
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `token` | string | Yes | Secret cancellation token issued at checkout. |
| `phone_number` | string | Optional | Customer phone number to match against the order for extra verification. |

**Response (200 OK):**
```json
{
  "status": 200,
  "success": true,
  "message": "Verification code has been sent to your phone.",
  "data": {
    "otp_id": "local-verification-id",
    "provider": "local",
    "phone": "010****5678"
  }
}
```

**Common Errors:**
- `404 Not Found`: Invalid or non-existent cancellation token.
- `409 Conflict`: An online payment attempt is currently unresolved.
- `422 Unprocessable Entity`: Token expired, token already used, order not in cancellable state, order has no phone on file, or phone number mismatch.
- `429 Too Many Requests`: Throttle limit exceeded.

---

### 15. Confirm Order Cancellation with OTP
- **Method & Path:** `POST /api/v1/guest/orders/cancel`
- **What It Does (in two sentences):** Verifies the customer's OTP code and permanently cancels the guest order, releasing warehouse stock and revoking the single-use cancellation token. If 5 failed verification attempts occur, the token is temporarily locked out with `429 Too Many Requests` to prevent brute-force attacks.
- **Where to Go Next (Flow):** Show an "Order Successfully Cancelled" confirmation screen and navigate the user back to the home/catalog screen.

**Headers:**
```http
Accept: application/json
Content-Type: application/json
```

**Request Body:**
```json
{
  "token": "cnc_xyz987654321...",
  "code": "123456",
  "otp_id": "local-verification-id"
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `token` | string | Yes | Secret cancellation token. |
| `code` | string | Yes | OTP code received by SMS (or `123456` in development). |
| `otp_id` | string | Optional | OTP request identifier returned from the `request-otp` step. |

**Response (200 OK):**
```json
{
  "status": 200,
  "success": true,
  "message": "Order cancelled successfully.",
  "data": {
    "order": {
      "order_number": "MEEM-2026-105",
      "status": "cancelled"
    }
  }
}
```

**Common Errors:**
- `404 Not Found`: Invalid or non-existent cancellation token.
- `422 Unprocessable Entity`: Invalid or expired OTP code, token already used, or order in non-cancellable status.
- `429 Too Many Requests`: Lockout triggered after 5 consecutive failed code attempts.

---

## 4. Endpoints NOT to Use on Mobile
- `POST /guest/session` -> **Web Only** (sets HttpOnly cookies).
- `DELETE /guest/session` -> **Web Only** (clears browser cookies).
