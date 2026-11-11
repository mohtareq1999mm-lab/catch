# Guest API — Frontend Integration Guide & Reference

> **Base URL:** `/api/v1/general/guest`  
> **Target Audience:** Frontend Developers (Web / Mobile / Next.js / Vue / React)  
> **Auth Model:** Cookie-based (`meem_guest` HttpOnly) + CSRF header (`X-CSRF-Token`)

---

## 📌 Non-Negotiable Frontend Rules

1. **Credentials Required:** Always send requests with `withCredentials: true` (e.g., `axios.defaults.withCredentials = true` or `fetch(url, { credentials: 'include' })`).
2. **HttpOnly Cookie:** The browser handles the `meem_guest` session cookie automatically; JavaScript cannot and must not attempt to read or modify it.
3. **CSRF Protection:** Every state-modifying request (`POST`, `PUT`, `DELETE`) **must** include the header:  
   `X-CSRF-Token: <csrf_token>` (obtained from `POST /guest/session`).
4. **Idempotency Protection:** For `checkout` and `payment`, include a unique `Idempotency-Key: <uuid-v4>` header to protect against double orders or duplicate charges on network retry.
5. **Authoritative Backend Pricing:** Never send prices, totals, taxes, or discounts from the frontend; the backend recalculates all values authoritatively.

---

## 🚀 Summary Table (One-Sentence Business Purpose)

| # | Method | Endpoint | Business Purpose (One Sentence) |
|---|---|---|---|
| 1 | `POST` | `/guest/session` | Initializes a new guest shopping session, issuing secure cookies and a CSRF token for checkout without registration. |
| 2 | `DELETE` | `/guest/session` | Revokes the current guest session and clears all session cookies from the client browser. |
| 3 | `GET` | `/guest/cart` | Retrieves the active guest cart with all items, calculated totals, and server-validated pricing. |
| 4 | `POST` | `/guest/cart/items` | Adds a new product to the guest cart with real-time stock and shipping method validation. |
| 5 | `PUT` | `/guest/cart/items` | Updates the quantity of an existing cart item by incrementing or decrementing. |
| 6 | `DELETE` | `/guest/cart/items/{itemId}` | Removes a specific item from the guest cart and automatically recalculates the cart total. |
| 7 | `DELETE` | `/guest/cart` | Clears all items from the guest cart in a single atomic operation. |
| 8 | `POST` | `/guest/orders/track` | Publicly tracks order status and delivery timeline using a secure tracking token without requiring authentication. |
| 9 | `POST` | `/guest/orders/cancel` | Cancels an eligible pending guest order and releases reserved stock and coupons using a one-time cancellation token. |
| 10 | `GET` | `/guest/orders/{orderId}` | Displays full order and invoice details on the confirmation page for the authenticated session owner. |
| 11 | `POST` | `/guest/orders/{orderId}/payment` | Initiates online gateway payment by generating a provider checkout URL, or locks the order as Cash on Delivery. |
| 12 | `POST` | `/guest/orders/{orderId}/access-tokens` | Re-issues public tracking and cancellation tokens for the session owner if the confirmation page was refreshed. |
| 13 | `POST` | `/guest/checkout` | Transforms the guest cart into a formal order linked to the buyer's shipping address and contact details. |

---

## 📖 Endpoint Details & Contracts

---

### 1. Initialize Guest Session

* **Business Purpose:** Initializes a new guest shopping session, issuing secure cookies and a CSRF token for checkout without registration.
* **Method & URL:** `POST /api/v1/general/guest/session`
* **Headers:**  
  `Content-Type: application/json`  
  `Origin: https://your-frontend.com`
* **Request Body:** None `{}`

#### Success Response `200 OK`
```json
{
  "status": 200,
  "message": "Guest session is ready.",
  "success": true,
  "data": {
    "guest": {
      "expires_at": "2026-11-18T10:00:00+00:00"
    },
    "csrf_token": "a1b2c3d4e5f6...",
    "cookies": {
      "session_cookie": "meem_guest",
      "csrf_cookie": "meem_guest_csrf",
      "csrf_header": "X-CSRF-Token",
      "path": "/api",
      "domain": null,
      "same_site": "lax",
      "secure": true,
      "http_only": true,
      "ttl_days": 7
    }
  }
}
```
* **Frontend Action:** Store `data.csrf_token` in application state (memory) and pass it as the `X-CSRF-Token` header on all subsequent `POST`, `PUT`, and `DELETE` requests.

---

### 2. Revoke Guest Session

* **Business Purpose:** Revokes the current guest session and clears all session cookies from the client browser.
* **Method & URL:** `DELETE /api/v1/general/guest/session`
* **Headers:**  
  `X-CSRF-Token: <csrf_token>`
* **Request Body:** None

#### Success Response `200 OK`
```json
{
  "status": 200,
  "message": "Guest session revoked.",
  "success": true
}
```

---

### 3. Get Guest Cart

* **Business Purpose:** Retrieves the active guest cart with all items, calculated totals, and server-validated pricing.
* **Method & URL:** `GET /api/v1/general/guest/cart`
* **Headers:** None (credentials only)
* **Request Body:** None

#### Success Response `200 OK` (Empty Cart)
```json
{
  "status": 200,
  "message": "Data Fetched Successfully",
  "success": true,
  "data": {
    "cart": null
  }
}
```

#### Success Response `200 OK` (Cart With Items)
```json
{
  "status": 200,
  "message": "Data Fetched Successfully",
  "success": true,
  "data": {
    "cart": {
      "id": 150,
      "total": 350.00,
      "sub_total": 350.00,
      "items": [
        {
          "id": 412,
          "product_id": 12,
          "product_variant_id": null,
          "quantity": 2,
          "unit_price": 175.00,
          "sub_total": 350.00,
          "shipping_method": "scheduled",
          "product": {
            "id": 12,
            "name": "Classic T-Shirt",
            "image": "https://example.com/images/product.jpg"
          }
        }
      ]
    }
  }
}
```

---

### 4. Add Item to Guest Cart

* **Business Purpose:** Adds a new product to the guest cart with real-time stock and shipping method validation.
* **Method & URL:** `POST /api/v1/general/guest/cart/items`
* **Headers:**  
  `Content-Type: application/json`  
  `X-CSRF-Token: <csrf_token>`

#### Request Body
```json
{
  "item": {
    "product_id": 12,
    "quantity": 2,
    "product_variant_id": null,
    "shipping_method": "scheduled"
  }
}
```
*(Note: Variable products require an explicit `product_variant_id`)*.

#### Success Response `201 Created`
```json
{
  "status": 201,
  "message": "Cart Created Successfully",
  "success": true,
  "data": {
    "cart": {
      "id": 150,
      "total": 350.00,
      "items": [ ... ]
    }
  }
}
```

#### Common Error `422 Unprocessable Entity`
```json
{
  "status": 422,
  "message": "This product is unavailable or out of stock.",
  "success": false
}
```

---

### 5. Update Item Quantity in Cart

* **Business Purpose:** Updates the quantity of an existing cart item by incrementing or decrementing.
* **Method & URL:** `PUT /api/v1/general/guest/cart/items`
* **Headers:**  
  `Content-Type: application/json`  
  `X-CSRF-Token: <csrf_token>`

#### Request Body
```json
{
  "item": {
    "product_id": 12,
    "quantity": 1,
    "product_variant_id": null,
    "shipping_method": "scheduled",
    "operation": "increment"
  }
}
```
*(Supported `operation` values: `"increment"` or `"decrement"`)*.

#### Success Response `200 OK`
```json
{
  "status": 200,
  "message": "Cart Updated Successfully",
  "success": true,
  "data": {
    "cart": {
      "id": 150,
      "total": 525.00,
      "items": [ ... ]
    }
  }
}
```

---

### 6. Remove Single Item From Cart

* **Business Purpose:** Removes a specific item from the guest cart and automatically recalculates the cart total.
* **Method & URL:** `DELETE /api/v1/general/guest/cart/items/{itemId}`
* **Headers:**  
  `X-CSRF-Token: <csrf_token>`
* **Request Body:** None

#### Success Response `200 OK`
```json
{
  "status": 200,
  "message": "Cart Updated Successfully",
  "success": true,
  "data": {
    "cart": {
      "id": 150,
      "total": 0.00,
      "items": []
    }
  }
}
```

---

### 7. Clear Entire Cart

* **Business Purpose:** Clears all items from the guest cart in a single atomic operation.
* **Method & URL:** `DELETE /api/v1/general/guest/cart`
* **Headers:**  
  `X-CSRF-Token: <csrf_token>`
* **Request Body:** None

#### Success Response `200 OK`
```json
{
  "status": 200,
  "message": "Cart Cleared Successfully",
  "success": true,
  "data": {
    "cart": null
  }
}
```

---

### 8. Public Order Tracking (No Session Required)

* **Business Purpose:** Publicly tracks order status and delivery timeline using a secure tracking token without requiring authentication.
* **Method & URL:** `POST /api/v1/general/guest/orders/track`
* **Headers:**  
  `Content-Type: application/json`  
  *(Public endpoint: no cookies or CSRF token required)*

#### Request Body
```json
{
  "token": "trk_9a8b7c6d5e4f3a2b1c0d..."
}
```

#### Success Response `200 OK`
```json
{
  "status": 200,
  "message": "Order tracked successfully.",
  "success": true,
  "data": {
    "order": {
      "order_number": "ORD-00009001",
      "status": "processing",
      "payment_status": "payment-pending",
      "fulfillment_status": "pending",
      "created_at": "2026-11-11T13:00:00Z",
      "last_updated_at": "2026-11-11T13:15:00Z"
    },
    "timeline": [
      {
        "timestamp": "2026-11-11T13:00:00Z",
        "title": "Order Created",
        "description": "Order received and pending verification"
      }
    ],
    "tracking_expires_at": null
  }
}
```
*(Privacy Rule: PII such as customer name, phone number, address, and purchased item details are stripped from this public response)*.

---

### 9. Public Order Cancellation (No Session Required)

* **Business Purpose:** Cancels an eligible pending guest order and releases reserved stock and coupons using a one-time cancellation token.
* **Method & URL:** `POST /api/v1/general/guest/orders/cancel`
* **Headers:**  
  `Content-Type: application/json`

#### Request Body
```json
{
  "token": "cnc_1f2e3d4c5b6a7b8c9d0e..."
}
```

#### Success Response `200 OK`
```json
{
  "status": 200,
  "message": "Order cancelled successfully.",
  "success": true,
  "data": {
    "order": {
      "order_number": "ORD-00009001",
      "status": "cancelled"
    }
  }
}
```

#### Common Error `422 Unprocessable Entity`
```json
{
  "status": 422,
  "message": "This cancellation link has already been used.",
  "success": false
}
```

---

### 10. Get Guest Order Details (Session Required)

* **Business Purpose:** Displays full order and invoice details on the confirmation page for the authenticated session owner.
* **Method & URL:** `GET /api/v1/general/guest/orders/{orderId}`
* **Headers:** Credentials + `X-CSRF-Token`
* **Request Body:** None

#### Success Response `200 OK`
```json
{
  "status": 200,
  "message": "Order retrieved successfully.",
  "success": true,
  "data": {
    "order": {
      "id": 9001,
      "order_number": "ORD-00009001",
      "status": "pending",
      "total": 350.00,
      "customer_contact": "+201000000001",
      "customer_name": "Ahmed Mohamed",
      "shipping_address": {
        "street": "10 Tahrir St",
        "city": "Cairo"
      },
      "products": [ ... ]
    },
    "can_cancel": true
  }
}
```

---

### 11. Initiate Order Payment

* **Business Purpose:** Initiates online gateway payment by generating a provider checkout URL, or locks the order as Cash on Delivery.
* **Method & URL:** `POST /api/v1/general/guest/orders/{orderId}/payment`
* **Headers:**  
  `Content-Type: application/json`  
  `X-CSRF-Token: <csrf_token>`  
  `Idempotency-Key: <uuid-v4>` (strongly recommended)

#### Request Body (Online Payment)
```json
{
  "payment_method": "online",
  "gateway": "myfatoorah"
}
```

#### Request Body (Cash on Delivery)
```json
{
  "payment_method": "cod"
}
```

#### Success Response `200 OK` (Online Payment)
```json
{
  "status": 200,
  "message": "Payment initiated successfully.",
  "success": true,
  "data": {
    "url": "https://portal.myfatoorah.com/payment/...",
    "order_id": 9001,
    "payment_method": "online",
    "gateway": "myfatoorah"
  }
}
```
* **Frontend Action:** Immediately redirect the browser window to `data.url`.

#### Success Response `200 OK` (Cash On Delivery)
```json
{
  "status": 200,
  "message": "Payment initiated successfully.",
  "success": true,
  "data": {
    "order_id": 9001,
    "payment_method": "cod",
    "gateway": null
  }
}
```

---

### 12. Re-issue Public Access Tokens

* **Business Purpose:** Re-issues public tracking and cancellation tokens for the session owner if the confirmation page was refreshed.
* **Method & URL:** `POST /api/v1/general/guest/orders/{orderId}/access-tokens`
* **Headers:**  
  `X-CSRF-Token: <csrf_token>`
* **Request Body:** None

#### Success Response `200 OK`
```json
{
  "status": 200,
  "message": "Tokens retrieved.",
  "success": true,
  "data": {
    "tracking_token": "trk_9a8b7c6d5e4f3a2b1c0d...",
    "tracking_revoked": false,
    "cancellation_token": "cnc_1f2e3d4c5b6a7b8c9d0e...",
    "tracking_expires_at": null
  }
}
```

---

### 13. Guest Checkout (Create Order)

* **Business Purpose:** Transforms the guest cart into a formal order linked to the buyer's shipping address and contact details.
* **Method & URL:** `POST /api/v1/general/guest/checkout`
* **Headers:**  
  `Content-Type: application/json`  
  `X-CSRF-Token: <csrf_token>`  
  `Idempotency-Key: <uuid-v4>` (strongly recommended)

#### Request Body
```json
{
  "name": "Ahmed Mohamed",
  "user_phone": "+201000000001",
  "user_email": "ahmed.guest@example.com",
  "address": {
    "street": "10 Tahrir St",
    "city": "Cairo"
  },
  "governorate_id": 1,
  "city_id": 2,
  "fulfillment_type": "delivery",
  "payment_method": "online",
  "gateway": "myfatoorah",
  "shipping_type": "local",
  "notes": "Please call before arrival"
}
```

#### Success Response `201 Created`
```json
{
  "status": 201,
  "message": "Checkout successful",
  "success": true,
  "data": {
    "order": {
      "id": 9001,
      "order_number": "ORD-00009001",
      "status": "pending",
      "total": 350.00
    },
    "tracking_token": "trk_9a8b7c6d5e4f3a2b1c0d...",
    "cancellation_token": "cnc_1f2e3d4c5b6a7b8c9d0e...",
    "payment": {
      "method": "online",
      "gateway": "myfatoorah",
      "next": "initiate_payment"
    },
    "idempotent_replay": false
  }
}
```
* **Frontend Workflow:**
  1. Present `tracking_token` and `cancellation_token` to the customer on the success screen so they can retain them.
  2. If `payment.method === "online"`, immediately proceed to call `POST /guest/orders/{orderId}/payment` to retrieve the gateway redirect URL.
