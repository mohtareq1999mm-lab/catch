# API Documentation - Fast Shipping Feature

## Endpoints

---

### 1. Get Fast Shipping Status

**GET** `/api/v1/general/fast-shipping/status`

**Purpose:** Check if fast shipping is available, current hours, fee, and ETA.

#### Success Response (200)

```json
{
    "enabled": true,
    "available": true,
    "duration_minutes": 120,
    "fee": 15.00,
    "opens_at": "08:00",
    "closes_at": "22:00",
    "available_again_at": "2026-07-21T08:00:00Z"
}
```

---

### 2. List Fast Shipping Products

**GET** `/api/v1/general/fast-shipping/products`

**Purpose:** Paginated list of products eligible for fast shipping.

#### Query Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `search` | `string` | Full-text search |
| `limit` | `integer` | Per page (default 30, max 100) |

#### Success Response (200)

Returns `ProductMiniResource` collection (same as public listing).

---

### 3. Fast Shipping Checkout

**POST** `/api/v1/general/fast-shipping/checkout`

**Purpose:** Create an order for fast shipping delivery.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Guard | `sanctum` |

#### Request Body

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `name` | `string` | Yes | Customer name |
| `phone` | `string` | Yes | Customer phone |
| `email` | `string` | Yes | Customer email |
| `address` | `string` | Yes | Delivery address |
| `governorate_id` | `integer` | Yes | Governorate for delivery |
| `payment_method` | `string` | Yes | COD, online, cashier |

#### Success Response (201)

```json
{
    "success": true,
    "message": "Order placed successfully",
    "data": { "order_id": 1, "total": 114.99 }
}
```

---

### 4. Get Fast Orders

**GET** `/api/v1/general/fast-shipping/orders`

**Purpose:** Retrieve authenticated user's fast shipping orders.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | Yes |

---

### 5. Admin: Get/Update Settings

**GET** `/api/v1/fast-shipping/settings` — View config
**PUT** `/api/v1/fast-shipping/settings` — Update enabled, duration_minutes, fee, hours

---

### 6. Admin: Toggle Fast Shipping

**PUT** `/api/v1/products/{id}/fast-shipping` — Toggle product eligibility
**PUT** `/api/v1/governorates/{id}/fast-shipping` — Toggle governorate enablement

---

## Query Parameters (Products)

| Parameter | Type | Description |
|-----------|------|-------------|
| `search` | `string` | Search by name, description |
| `limit` | `integer` | Per page (max 100, default 30) |
| `page` | `integer` | Page number |

## Business Rules

1. **Eligibility:** Product must have `is_fast_shipping_available = true`
2. **Governorate:** Delivery governorate must have `is_fast_shipping_enabled = true`
3. **Hours:** Orders only accepted within configurable working hours (default 08:00-22:00)
4. **Mixed Cart:** Cart cannot contain both fast and regular shipping items
5. **Fee:** Configurable fast shipping fee added to order total
6. **ETA:** Current time + `duration_minutes`
7. **Product Lock:** Inventory locked during checkout with `lockForUpdate()`
