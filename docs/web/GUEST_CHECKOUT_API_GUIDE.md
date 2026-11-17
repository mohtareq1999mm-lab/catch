# Guest Checkout & Cart API Guide — Web Frontend (Next.js / React / Vue)

**Audience:** Web Frontend Developers (Next.js / React / Vue / Nuxt / Svelte)  
**API Version:** v1  
**Base URL:** `https://<api-domain>/api/v1/guest`  
**Authentication Paradigm:** Server-Managed `HttpOnly` Cookies + Double-Submit CSRF. **NO BEARER TOKENS FOR WEB.**

---

## 1. Core Architectural Concepts for Web

1. **Credentialed Requests (`withCredentials: true`):**
   - The browser automatically sends and receives the `meem_guest` cookie.
   - You must enable credentials on every request:
     ```javascript
     // Axios
     axios.defaults.withCredentials = true;

     // Fetch API
     fetch(url, { credentials: 'include', ... });
     ```

2. **Cookie Names & Accessibility:**
   | Cookie Name | HttpOnly | Accessible via JavaScript? | Purpose |
   |---|---|---|---|
   | `meem_guest` | **Yes** | **No** (Secure/HttpOnly) | Authoritative session credential. JavaScript cannot read it. |
   | `meem_guest_csrf` | No | Yes | CSRF token cookie for double-submit verification. |

3. **CSRF Protection on State-Changing Requests:**
   - Every `POST`, `PUT`, and `DELETE` on protected guest endpoints requires the **`X-CSRF-Token`** header.
   - The value is obtained from `data.csrf_token` returned by `POST /guest/session`, or by reading the `meem_guest_csrf` cookie.
   - `GET` requests do not require CSRF.

4. **CORS & Domain Setup:**
   - Frontend domain must be present in `CORS_ALLOWED_ORIGINS` (e.g. `http://localhost:3000` or `https://meem.com`).
   - If the backend is on a subdomain (e.g., `api.meem.com` and `meem.com`), cookies work seamlessly with `SameSite=Lax`.
   - If using completely different registrable domains, use a **same-site reverse proxy/BFF** (e.g. Next.js rewrites `/api/*` to the backend) so cookies remain first-party.

5. **Authoritative Backend Pricing:**
   - Never send client-calculated `price`, `subtotal`, or `total`. The backend pricing service recalculates all totals.

---

## 2. End-to-End Web Frontend Flow Diagram

```
[User visits website as Guest]
             │
             ▼
1. POST /guest/session  ──────► Initializes cookies (meem_guest, meem_guest_csrf)
                                Returns data.csrf_token (store in memory/state)
             │
             ▼
2. GET /guest/cart      ──────► Checks existing cart items (returns null if empty)
             │
             ▼
3. POST /guest/cart/items ────► Add items to cart (requires X-CSRF-Token)
   PUT /guest/cart/items  ────► Increment / Decrement quantity
   DELETE /guest/cart/items/{id} Remove item
             │
             ▼
4. POST /guest/checkout ──────► Creates Order (requires X-CSRF-Token + idempotency)
             │                  Returns order, tracking_token, cancellation_token
             │
       ┌─────┴─────────────────────────┐
       ▼ (If Online Payment)           ▼ (If Cash On Delivery)
5. POST /guest/orders/{id}/payment     Order confirmed directly!
   (Redirect to payment gateway URL)           │
       │                                       ▼
       └────────────────────────► 6. GET /guest/orders/{id}
                                     Render "Thank You / Success" Page
                                               │
                                               ▼
                              [Later / Customer opens Tracking Link]
                              7. POST /guest/orders/track (with token from query param)
                              8. POST /guest/orders/cancel (if customer cancels)
```

---

## 3. Endpoints Specification

---

### 1. Open or Resume Web Guest Session
- **Method & Path:** `POST /api/v1/guest/session`
- **What It Does (in two sentences):** Initializes a fresh guest identity by queuing `HttpOnly` session cookies on the browser response, or refreshes existing cookies without extending the 7-day retention period. It returns the `csrf_token` that the frontend must attach as a header to all subsequent mutating requests.
- **Where to Go Next (Flow):** Store the returned `data.csrf_token` in memory (React Context / Pinia / Redux store). Next, call `GET /guest/cart` to load any persisted cart items into your UI state.

**Headers:**
```http
Accept: application/json
Content-Type: application/json
Origin: https://meem.com
```

**Request Body:**
- Empty JSON object `{}`.

**Response (200 OK):**
```json
{
  "success": true,
  "message": "Guest session is ready.",
  "data": {
    "guest": {
      "expires_at": "2026-11-24T10:00:00+02:00"
    },
    "csrf_token": "a4f8b9c1d2e3f4...",
    "cookies": {
      "name": "meem_guest",
      "csrf_name": "meem_guest_csrf",
      "path": "/api",
      "same_site": "lax",
      "secure": true
    }
  }
}
```
*Note: The response will include `Set-Cookie` headers for `meem_guest` and `meem_guest_csrf`.*

---

### 2. Revoke Web Guest Session (Logout / Clear)
- **Method & Path:** `DELETE /api/v1/guest/session`
- **What It Does (in two sentences):** Marks the current guest identity as permanently revoked on the server and issues expired cookies to wipe them from the browser. It prevents any further cart or order modifications using this session.
- **Where to Go Next (Flow):** Clear your frontend cart state and redirect the user to the home page or catalog.

**Headers:**
```http
Accept: application/json
X-CSRF-Token: <csrf_token>
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
*Note: Response sends expired `Set-Cookie` headers clearing `meem_guest`.*

---

### 3. Fetch Guest Cart
- **Method & Path:** `GET /api/v1/guest/cart`
- **What It Does (in two sentences):** Fetches the active cart and recalculated line totals associated with the guest session cookie. If the user has not added any items or has no active session, it returns `200 OK` with `cart: null` without triggering any authentication errors.
- **Where to Go Next (Flow):** Update the cart dropdown or cart drawer state in your navigation bar. If `cart === null`, show the empty cart message.

**Headers:**
```http
Accept: application/json
```
*(Browser automatically sends `meem_guest` cookie).*

**Request Body:**
- None.

**Response (200 OK — Cart With Items):**
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

---

### 4. Add Item to Guest Cart
- **Method & Path:** `POST /api/v1/guest/cart/items`
- **What It Does (in two sentences):** Adds a selected product and optional variant to the guest cart, checking warehouse inventory and valid shipping methods. It re-queues the session cookie to ensure active retention and returns the updated cart contents.
- **Where to Go Next (Flow):** Trigger a side drawer opening or toast notification ("Product added to cart"), and update the cart item count in the header.

**Headers:**
```http
Accept: application/json
Content-Type: application/json
X-CSRF-Token: <csrf_token>
```

**Request Body:**
```json
{
  "item": {
    "product_id": 15,
    "quantity": 1,
    "product_variant_id": 4,
    "shipping_method": "scheduled",
    "attributes": []
  }
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `item.product_id` | integer | Yes | Valid product ID. |
| `item.quantity` | integer | Yes | Quantity (1 to 100). |
| `item.product_variant_id` | integer | Optional | Variant ID matching the product. |
| `item.shipping_method` | string | Yes | Value: `"scheduled"` or `"fast"`. |
| `item.attributes` | array | Optional | Custom product attributes. |

**Response (201 Created):**
```json
{
  "success": true,
  "message": "Cart created successfully",
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

### 5. Update Item Quantity in Cart
- **Method & Path:** `PUT /api/v1/guest/cart/items`
- **What It Does (in two sentences):** Increments or decrements an existing item quantity by atomic steps. If an item quantity reaches zero through decrement, it is automatically removed from the cart.
- **Where to Go Next (Flow):** Update item subtotal and cart total summary on the Cart page in real-time.

**Headers:**
```http
Accept: application/json
Content-Type: application/json
X-CSRF-Token: <csrf_token>
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
| `item.product_id` | integer | Yes | Target product ID. |
| `item.quantity` | integer | Yes | Quantity increment/decrement step (e.g. 1). |
| `item.product_variant_id` | integer | Optional | Variant ID if applicable. |
| `item.operation` | string | Yes | Value: `"increment"` or `"decrement"`. |

**Response (200 OK):**
```json
{
  "success": true,
  "message": "Cart updated successfully",
  "data": {
    "cart": {
      "id": 45,
      "total": 1250.00,
      "items": [ ... ]
    }
  }
}
```

---

### 6. Remove Specific Item from Cart
- **Method & Path:** `DELETE /api/v1/guest/cart/items/{itemId}`
- **What It Does (in two sentences):** Deletes a single item row from the cart using the cart item's ID. It recalculates the order summary and returns the remaining items.
- **Where to Go Next (Flow):** Remove the item row from the Cart table with an animation and update the price breakdown.

**Headers:**
```http
Accept: application/json
X-CSRF-Token: <csrf_token>
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
      "total": 600.00,
      "items": [ ... ]
    }
  }
}
```

---

### 7. Clear Cart
- **Method & Path:** `DELETE /api/v1/guest/cart`
- **What It Does (in two sentences):** Removes all products from the guest cart at once while maintaining the user's active session and cookies. It returns the cleared cart object.
- **Where to Go Next (Flow):** Switch the Cart page view to an "Your cart is empty" placeholder.

**Headers:**
```http
Accept: application/json
X-CSRF-Token: <csrf_token>
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

### 8. Guest Checkout (Create Order)
- **Method & Path:** `POST /api/v1/guest/checkout`
- **What It Does (in two sentences):** Finalizes the guest checkout by validating the recipient information, shipping address, and chosen payment method, then committing the order into the database. It releases the cart and returns the created order alongside secret tokens for tracking and cancellation.
- **Where to Go Next (Flow):**
  - **If `payment.method === 'online'`:** Immediately call `POST /guest/orders/{orderId}/payment` to receive the payment gateway redirect URL and route the window to it (`window.location.href = redirectUrl`).
  - **If `payment.method === 'cod'`:** Route to the `/order-confirmation/{orderId}` screen.

**Headers:**
```http
Accept: application/json
Content-Type: application/json
X-CSRF-Token: <csrf_token>
X-Idempotency-Key: <unique-uuid-v4>
```

**Request Body:**
```json
{
  "name": "Ahmed Mohamed",
  "user_phone": "01012345678",
  "user_email": "ahmed@example.com",
  "fulfillment_type": "delivery",
  "payment_method": "online",
  "gateway": "myfatoorah",
  "governorate_id": 1,
  "address": {
    "street": "El-Nasr Road",
    "city": "Nasr City",
    "building": "24",
    "details": "Apt 12"
  },
  "shipping_type": "local",
  "notes": "Deliver during work hours"
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `name` | string | Yes | Full recipient name. |
| `user_phone` | string | Yes | Active phone number. |
| `user_email` | string | Optional | Email to receive invoice and tracking links. |
| `fulfillment_type` | string | Yes | Value: `"delivery"` or `"pickup"`. |
| `payment_method` | string | Yes | Value: `"online"` or `"cod"`. |
| `gateway` | string | Required if online | Gateway identifier (`myfatoorah`, `tap`, `stripe`, etc.). |
| `governorate_id` | integer | Required if delivery | Governorate ID for shipping. |
| `address` | object | Required if delivery | Detailed address object. |
| `pickup_location_id` | integer | Required if pickup | Store pickup location ID. |
| `shipping_type` | string | Optional | Default `"local"` (or `"international"`). |
| `notes` | string | Optional | Delivery instructions. |

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

### 9. Initiate Order Payment
- **Method & Path:** `POST /api/v1/guest/orders/{orderId}/payment`
- **What It Does (in two sentences):** Triggers payment session generation with the selected payment provider for the guest's pending order. It returns the gateway redirect URL that the browser must redirect to for completing card/wallet entry.
- **Where to Go Next (Flow):** Redirect the browser via `window.location.href = data.redirect_url`. The gateway will redirect back to your configured callback URL after payment completion.

**Headers:**
```http
Accept: application/json
Content-Type: application/json
X-CSRF-Token: <csrf_token>
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

### 10. View Order Details (Confirmation Screen)
- **Method & Path:** `GET /api/v1/guest/orders/{orderId}`
- **What It Does (in two sentences):** Loads the full details of an order strictly owned by the guest cookie session, preventing unauthorized access to other customers' orders. It also indicates whether the order can still be cancelled (`can_cancel: true/false`).
- **Where to Go Next (Flow):** Render the Thank You / Order Confirmation page. Show the order summary, delivery timeline, and a "Cancel Order" button if `can_cancel` is true.

**Headers:**
```http
Accept: application/json
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

**Errors:**
- `404 Not Found`: Order does not exist or was placed by another guest session.

---

### 11. Reissue Order Access Tokens (On Refresh)
- **Method & Path:** `POST /api/v1/guest/orders/{orderId}/access-tokens`
- **What It Does (in two sentences):** Re-issues the `tracking_token` and `cancellation_token` for an owned order when the confirmation page is refreshed or re-visited. It ensures the frontend can display the tracking URL without storing the tokens in insecure cookies.
- **Where to Go Next (Flow):** Render the copyable tracking link and store the tokens in session storage for the current tab.

**Headers:**
```http
Accept: application/json
X-CSRF-Token: <csrf_token>
```

**Request Body:**
- None.

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

### 12. Public Order Tracking (Token in Body)
- **Method & Path:** `POST /api/v1/guest/orders/track`
- **What It Does (in two sentences):** Resolves order tracking info and delivery milestones for a guest order using only the private `tracking_token` passed in the request body. Because the token is sent in the POST body rather than the URL, it is kept safe from web proxy and access log capture.
- **Where to Go Next (Flow):** Render the public Track Order page (`/track-order`) showing the order status badge and the fulfillment timeline.

**Headers:**
```http
Accept: application/json
Content-Type: application/json
```
*(No cookies or sessions required).*

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

### 13. Public Order Cancellation (Token in Body)
- **Method & Path:** `POST /api/v1/guest/orders/cancel`
- **What It Does (in two sentences):** Cancels the guest order permanently using the single-use `cancellation_token`. It locks the order, releases reserved warehouse stock, and marks the token as used so it cannot be executed twice.
- **Where to Go Next (Flow):** Display an "Order Cancelled" notification message and redirect the user back to the store homepage.

**Headers:**
```http
Accept: application/json
Content-Type: application/json
```

**Request Body:**
```json
{
  "token": "cnc_xyz987654321..."
}
```

**Response (200 OK):**
```json
{
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

**Errors:**
- `404 Not Found`: Invalid cancellation token.
- `422 Unprocessable Entity`: Token already used, token expired, or order already in non-cancellable state (e.g. `processing` / `delivered`).

---

## 4. Endpoints NOT to Use on Web
- `POST /guest/mobile/session` -> **Mobile Native Only** (returns raw Bearer token; web must use cookies).
- `POST /guest/mobile/session/rotate` -> **Mobile Native Only**.
- `DELETE /guest/mobile/session` -> **Mobile Native Only**.
