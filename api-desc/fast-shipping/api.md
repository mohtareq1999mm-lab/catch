# Fast Shipping API Reference

Base URL: `/api/v1`

---

## Admin Endpoints

### GET /fast-shipping/settings

Get fast shipping configuration.

**Authentication:** Required (Sanctum)
**Permission:** `view-fast-shipping`

**Response `200`:**

```json
{
    "enabled": true,
    "available": true,
    "duration_minutes": 120,
    "fee": 30,
    "opens_at": "08:00",
    "closes_at": "22:00",
    "available_again_at": null
}
```

| Field | Type | Description |
|-------|------|-------------|
| enabled | boolean | Global fast shipping toggle |
| available | boolean | Whether fast shipping is currently available (enabled + within hours) |
| duration_minutes | integer | Delivery duration in minutes |
| fee | float | Fast shipping fee |
| opens_at | string (H:i) | Working hours start |
| closes_at | string (H:i) | Working hours end |
| available_again_at | string (H:i) |null | Next available time (null if currently available) |

---

### PUT /fast-shipping/settings

Update fast shipping configuration.

**Authentication:** Required (Sanctum)
**Permission:** `update-fast-shipping`

**Request Body:**

```json
{
    "enabled": true,
    "duration_minutes": 90,
    "fee": 25,
    "start_hour": "09:00",
    "end_hour": "21:00"
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| enabled | boolean | sometimes | Global fast shipping toggle |
| duration_minutes | integer | sometimes | 1–1440 |
| fee | numeric | sometimes | Minimum 0 |
| start_hour | string (H:i) | sometimes | Format: H:i |
| end_hour | string (H:i) | sometimes | Format: H:i |

**Response `200`:**

```json
{
    "message": "Fast shipping settings updated successfully"
}
```

---

### PUT /governorates/{id}/fast-shipping

Toggle fast shipping availability for a governorate.

**Authentication:** Required (Sanctum)

**Request Body:**

```json
{
    "is_fast_shipping_enabled": true
}
```

**Response `200`:**

```json
{
    "id": 1,
    "name": "Cairo",
    "is_fast_shipping_enabled": true,
    "status": true
}
```

**Error `404`:**

```json
{
    "message": "Not Found"
}
```

---

### PUT /products/{id}/fast-shipping

Toggle fast shipping availability for a product.

**Authentication:** Required (Sanctum)
**Email Verified:** Required

**Request Body:**

```json
{
    "is_fast_shipping_available": true
}
```

**Response `200`:**

```json
{
    "id": 1,
    "name": "Product Name",
    "is_fast_shipping_available": true
}
```

---

## Public Endpoints

### GET /fast-shipping/status

Get fast shipping availability status (no auth required).

**Response `200`:**

```json
{
    "enabled": true,
    "available": true,
    "duration_minutes": 120,
    "fee": 30,
    "opens_at": "08:00",
    "closes_at": "22:00",
    "available_again_at": null
}
```

---

### GET /fast-shipping/products

List products eligible for fast shipping.

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| search | string | — | Search by name or description |
| limit | integer | 15 | Items per page (max 100) |
| page | integer | 1 | Page number |

**Response `200`:**

```json
{
    "data": [
        {
            "id": 1,
            "name": "Fast Shipping Product",
            "slug": "fast-shipping-product",
            "description": "Description",
            "price": 100,
            "is_fast_shipping_available": true,
            "categories": [],
            "variations": [],
            "flash_sales": [],
            "reviews_avg_rating": 4.5,
            "reviews_count": 10
        }
    ],
    "meta": {
        "current_page": 1,
        "last_page": 5,
        "per_page": 15,
        "total": 65
    }
}
```

---

### POST /fast-shipping/checkout

Create a fast shipping order.

**Authentication:** Required (Sanctum)

**Request Body:**

```json
{
    "name": "John Doe",
    "user_phone": "+201234567890",
    "user_email": "john@example.com",
    "address": {
        "street": "123 Main St",
        "city": "Cairo"
    },
    "governorate_id": 1,
    "selected_promotion_id": null,
    "selected_gift_product_id": null,
    "fulfillment_type": "delivery",
    "payment_method": "online",
    "gateway": "myfatoorah",
    "pickup_location_id": null,
    "notes": "Leave at door"
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| name | string | required | Customer name |
| user_phone | string | required | Customer phone |
| user_email | email | required | Customer email |
| address | array | required | Shipping address |
| governorate_id | integer | required | Must exist in governorates |
| selected_promotion_id | integer | nullable | Must exist in promotions |
| selected_gift_product_id | integer | nullable | Must exist in products |
| fulfillment_type | string | nullable | `delivery` or `pickup` |
| payment_method | string | nullable | `online`, `cod`, `pay_at_cashier` |
| gateway | string | nullable | Payment gateway (max 50 chars) |
| pickup_location_id | integer | required_if:fulfillment_type=pickup | Must exist in pickup_locations |
| notes | string | nullable | Order notes |

**Validation Rules:**

```php
'name' => ['required', 'string', 'max:255'],
'user_phone' => ['required', 'string', 'max:255'],
'user_email' => ['required', 'email', 'max:255'],
'address' => ['required', 'array'],
'notes' => ['nullable', 'string'],
'governorate_id' => ['required', 'integer', 'exists:governorates,id'],
'selected_promotion_id' => ['nullable', 'integer', 'exists:promotions,id'],
'selected_gift_product_id' => ['nullable', 'integer', 'exists:products,id'],
'fulfillment_type' => ['nullable', 'string', 'in:delivery,pickup'],
'payment_method' => ['nullable', 'string', 'in:online,cod,pay_at_cashier'],
'gateway' => ['nullable', 'string', 'max:50'],
'pickup_location_id' => ['nullable', 'integer', 'required_if:fulfillment_type,pickup', 'exists:pickup_locations,id'],
```

**Errors:**

- `400` — Cart not found, cart empty, no fast shipping items
- `422` — Validation failed, governorate not found, COD not available for pickup, invalid payment method
- `500` — Order creation failed

**Success Response `200`:**

Returns payment redirect or order confirmation depending on payment method.

---

### GET /fast-shipping/orders

List authenticated user's fast shipping orders.

**Authentication:** Required (Sanctum)

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| limit | integer | 15 | Items per page (max 100) |
| page | integer | 1 | Page number |

**Response `200`:**

```json
{
    "data": [
        {
            "id": 1,
            "tracking_number": "FAST-20240720-001",
            "shipping_method": "FAST",
            "total_price": 130,
            "fast_shipping_fee": 30,
            "eta": "2024-07-20T14:30:00.000000Z",
            "status": "pending",
            "orderItems": [
                {
                    "id": 1,
                    "product": {},
                    "productVariant": {}
                }
            ]
        }
    ],
    "meta": {
        "current_page": 1,
        "last_page": 3,
        "per_page": 15,
        "total": 32
    }
}
```
