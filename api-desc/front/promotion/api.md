# API Documentation - Promotion Feature

## Endpoints

---

### 1. List Promotions (Public)

**GET** `/api/v1/general/promotions`

**Purpose:** Retrieve active promotions for the shop frontend.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | No |

#### Query Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `with_product` | `boolean` | No | Include associated products in response |

#### Success Response (200)

```json
{
    "data": [
        {
            "id": 1,
            "name": "Summer Special 20% Off",
            "slug": "summer-special-20-off",
            "status": true,
            "image": {
                "desktop": "https://cdn.example.com/promotions/summer-desktop.jpg",
                "mobile": "https://cdn.example.com/promotions/summer-mobile.jpg"
            },
            "products": [
                { "id": 1, "name": "Product Name", "slug": "product-slug", "status": true, "image": {} }
            ]
        }
    ]
}
```

---

### 2. Get Promotion by Slug (Public)

**GET** `/api/v1/general/promotions/{slug}`

**Purpose:** Retrieve a single promotion with its associated products.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | No |

#### Success Response (200)

```json
{
    "data": {
        "id": 1,
        "name": "Summer Special 20% Off",
        "slug": "summer-special-20-off",
        "status": true,
        "image": { "desktop": "...", "mobile": "..." },
        "products": [...]
    }
}
```

#### Error Responses

| Status | Condition |
|--------|-----------|
| 404 | Slug not found |

---

### 3. Eligible Promotions (Checkout)

**GET** `/api/v1/general/checkout/promotions`

**Purpose:** Retrieve promotions eligible for the current user's cart.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Guard | `sanctum` |

#### Success Response (200)

```json
{
    "data": [
        {
            "id": 1,
            "name": "Summer Special 20% Off",
            "code": "SUMMER20",
            "type": "price",
            "type_amount": "percentage",
            "discount": 20,
            "value": 20,
            "max_discount_amount": 500,
            "minimum_order_amount": 1000,
            "is_eligible": true
        }
    ]
}
```

---

### 4. List Promotions (Admin)

**GET** `/api/v1/promotions`

**Purpose:** Retrieve paginated list of all promotions for admin management.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Guard | `sanctum` |
| Permission | `view-promotion` |

#### Query Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `page` | `integer` | No | Page number |
| `search` | `string` | No | Search by name, code |
| `type` | `string` | No | Filter by `price` or `quantity` |
| `status` | `boolean` | No | Filter by active/inactive |

#### Success Response (200)

```json
{
    "data": [
        {
            "id": 1,
            "name": "Summer Special 20% Off",
            "slug": "summer-special-20-off",
            "type": "price",
            "type_amount": "percentage",
            "discount": 20,
            "value": 20,
            "code": "SUMMER20",
            "minimum_order_amount": 1000,
            "max_discount_amount": 500,
            "required_quantity": null,
            "apply_to": "all_products",
            "limiter": 100,
            "usage": 45,
            "status": true,
            "is_valid": true,
            "start_at": "2026-08-01T00:00:00+00:00",
            "end_at": "2026-08-31T23:59:59+00:00",
            "image": { "desktop": "...", "mobile": "..." },
            "products": [],
            "gift_products": [],
            "created_at": "2026-07-15T10:00:00+00:00"
        }
    ],
    "meta": { "current_page": 1, "last_page": 2, "per_page": 15, "total": 20 }
}
```

---

### 5. Create Promotion (Admin)

**POST** `/api/v1/promotions`

**Purpose:** Create a new promotion with discount or gift configuration.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Guard | `sanctum` |
| Permission | `create-promotion` |

#### Request Parameters (multipart/form-data)

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `name[en]` | `string` | Yes | English name |
| `name[ar]` | `string` | Yes | Arabic name |
| `image-desktop` | `file` | Yes | Desktop image (jpeg/png/jpg/gif, max 2MB) |
| `image-mobile` | `file` | Yes | Mobile image (jpeg/png/jpg/gif, max 2MB) |
| `type` | `string` | Yes | `price` or `quantity` |
| `type_amount` | `string` | Yes | `fixed_rate`, `percentage`, or `gift` |
| `discount` | `number` | Conditional | Required if not gift |
| `max_discount_amount` | `number` | Conditional | Required if type_amount=percentage |
| `minimum_order_amount` | `number` | Conditional | Required if type≠quantity |
| `apply_to` | `string` | Yes | `all_products` or `specific_products` |
| `product_ids[]` | `array` | Conditional | Required if apply_to=specific_products |
| `gift_products` | `array` | Conditional | Required if type_amount=gift |
| `gift_products[0][product_id]` | `integer` | Conditional | Gift product ID |
| `gift_products[0][product_variant_id]` | `integer` | No | Gift variant ID |
| `gift_products[0][quantity]` | `integer` | No | Gift quantity (default: 1) |
| `limiter` | `integer` | No | Max usage count |
| `start_at` | `date` | No | Start date |
| `end_at` | `date` | No | End date (after start_at) |
| `status` | `boolean` | No | Active/inactive |
| `required_quantity_type` | `integer` | Conditional | Required if type=quantity |

#### Success Response (201)

Returns created promotion with all fields.

#### Error Responses

| Status | Condition |
|--------|-----------|
| 422 | Validation failure (missing fields, invalid type, etc.) |
| 401 | Unauthenticated |
| 403 | Forbidden (missing permission) |

---

### 6. Get Promotion (Admin)

**GET** `/api/v1/promotions/{promotion}`

**Purpose:** Retrieve a single promotion by ID.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Permission | `view-promotion` |

---

### 7. Update Promotion (Admin)

**PUT** `/api/v1/promotions/{promotion}`

**Purpose:** Update an existing promotion (partial updates supported).

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Permission | `update-promotion` |

**Note:** All fields from create become optional. Name unique check ignores current promotion.

---

### 8. Delete Promotion (Admin)

**DELETE** `/api/v1/promotions/{promotion}`

**Purpose:** Delete a promotion.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Permission | `delete-promotion` |

#### Success Response (200)

```json
{
    "message": "Promotion deleted successfully"
}
```

---

## Resource Structure

### Admin PromotionResource

| Field | Type | Description |
|-------|------|-------------|
| `id` | `integer` | Primary key |
| `name` | `string` | Translated name |
| `slug` | `string` | URL slug |
| `type` | `string` | `price` or `quantity` |
| `type_amount` | `string` | `fixed_rate`, `percentage`, or `gift` |
| `discount` | `float` | Discount value |
| `code` | `string` | Unique promotion code |
| `minimum_order_amount` | `float` | Minimum cart total to qualify |
| `required_quantity` | `integer` | Required quantity for quantity type |
| `apply_to` | `string` | `all_products` or `specific_products` |
| `limiter` | `integer` | Max usage count |
| `usage` | `integer` | Current usage count |
| `status` | `boolean` | Active/inactive |
| `is_valid` | `boolean` | Computed validity (status + dates + limiter) |
| `start_at` | `string (ISO8601)` | Validity start |
| `end_at` | `string (ISO8601)` | Validity end |
| `image` | `object` | `{ desktop: string, mobile: string }` |
| `products` | `array` | Associated products (when loaded) |
| `gift_products` | `array` | Gift products with pivot data (when loaded) |
| `created_at` | `string (ISO8601)` | Creation timestamp |

### Public PromotionResource

| Field | Type | Description |
|-------|------|-------------|
| `id` | `integer` | Primary key |
| `name` | `string` | Translated name |
| `slug` | `string` | URL slug |
| `status` | `boolean` | Active/inactive |
| `image` | `object` | `{ desktop: string, mobile: string }` |
| `products` | `array` | Associated products (when loaded) |

## Business Rules

1. **Eligibility Order:** Promotion discount is calculated BEFORE coupon discount
2. **Percentage Cap:** Percentage promotions can have `max_discount_amount` cap
3. **Fixed Floor:** Fixed rate discount cannot reduce item below 0
4. **Gift Stock:** Gift products must be in stock; out-of-stock gifts are excluded
5. **Usage Limit:** `usage` counter incremented on order creation, respects `limiter`
6. **Decrement Floor:** `decrementUsage()` never goes below 0
7. **Cart Modification:** Adding/removing cart items clears applied promotion
8. **Unique Codes:** Promotion codes are auto-generated and unique at DB level
