# API Reference — Cart

> Source of truth: `packages/marvel/src/Rest/Routes.php` (lines 149-157), `CartController.php`,
> `CartRepository.php`, `CartInventoryService.php`, `CartCreateRequest.php`, `CartUpdateRequest.php`,
> `CartResource.php`, `CartItemResource.php`.

---

## Common Behavior

- **Base URL**: `/api/v1` (registered in `RestAPIServiceProvider.php:29-31`)
- **Authentication**: `auth:sanctum` on every cart route (Bearer token)
- **Rate limit**: `throttle:cart` — 20 req/min per user (fallback IP)
- **Error shape (business errors)**: `{ "status": 400, "message": "...", "success": false }`
- **Validation error shape (422)**: raw Laravel field-error object (from `failedValidation()` in Form Requests or inline `$request->validate()`)
- **One cart per user**: `carts.user_id` has a UNIQUE index, so a user can only ever have ONE cart row.

---

## Endpoints

---

### GET /api/v1/cart

List the authenticated user's cart (paginated).

**Authentication**: `auth:sanctum`

**Query Parameters**:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| page | int | 1 | Page number |
| limit | int | 15 | Items per page (controller reads `$request->limit ?? 15`) |

> Note: the controller reads the `limit` query parameter. `per_page` is **not** read by this endpoint.

**Response 200**:
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "data": [
      {
        "id": 1,
        "user_id": 3,
        "coupon": {
          "id": 5,
          "name": "Save 10%",
          "slug": "save-10",
          "code": "SAVE10",
          "image": { "desktop": null, "mobile": null },
          "borderColor": null,
          "borderless": false
        },
        "coupon_code": "SAVE10",
        "status": "active",
        "reserved_at": "2026-07-28T10:00:00.000000Z",
        "expires_at": "2026-07-31T10:00:00.000000Z",
        "total_items": 3,
        "total_quantity": 5,
        "subtotal": 1499.97,
        "total_price": 1499.97,
        "coupon_discount": 149.99,
        "total_after_coupon": 1349.98,
        "normal_items_count": 2,
        "fast_items_count": 1,
        "normal_items": [
          {
            "id": 1,
            "product_id": 10,
            "product_variant_id": null,
            "quantity": 2,
            "price": 499.99,
            "total_price": 999.98,
            "attributes": null,
            "shipping_method": "SCHEDULED",
            "promotion_id": null,
            "discount_amount": 0,
            "is_gift": false,
            "product": {
              "id": 10,
              "name": "Wireless Headphones",
              "slug": "wireless-headphones",
              "thumbnail": "https://cdn.example.com/products/thumbnail.jpg"
            }
          }
        ],
        "fast_items": [],
        "has_eligible_promotion": false
      }
    ],
    "page": 1,
    "current_page": 1,
    "from": 1,
    "to": 15,
    "last_page": 1,
    "path": "http://localhost:8000/api/v1/cart",
    "per_page": 15,
    "total": 1,
    "next_page_url": null,
    "prev_page_url": null,
    "last_page_url": "http://localhost:8000/api/v1/cart?page=1",
    "first_page_url": "http://localhost:8000/api/v1/cart?page=1"
  }
}
```

**Quick Test**:
```bash
curl -X GET "http://localhost:8000/api/v1/cart?page=1&limit=15" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

**Business Rules**:
- Returns only carts where `user_id = authenticated user`.
- Due to the UNIQUE `carts.user_id` index, this endpoint can return **at most 1 cart** for a user.
- Eager loads: `items.product`, `items.productVariant.attributeProducts.attributeValue.attribute`.
- `coupon` object is built by `CouponResource` only when a coupon code resolves to a Coupon row; otherwise null.
- `coupon_discount` and `total_after_coupon` are computed at serialization time by `CouponCalculator` (not stored).
- Items are split into `normal_items` (SCHEDULED) and `fast_items` (FAST).
- `has_eligible_promotion` is computed via `PromotionService::hasEligiblePromotion()`.

---

### POST /api/v1/cart

Add an item to the cart.

**Authentication**: `auth:sanctum`

**Request Body**:

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| item | object | required | Item data container |
| item.product_id | int | required | Valid product ID |
| item.quantity | int | required | Quantity (min: 1) |
| item.product_variant_id | int | sometimes | Valid variant ID (required for variable products) |
| item.attributes | array | sometimes | Custom attribute values |
| item.shipping_method | string | required | `SCHEDULED` or `FAST` |

**Validation Rules** (`CartCreateRequest`):

| Field | Rules |
|-------|-------|
| item | required, array, min:1 |
| item.product_id | required, integer, exists:products,id |
| item.quantity | required, integer, min:1 |
| item.product_variant_id | sometimes, nullable, integer, exists:product_variants,id |
| item.attributes | sometimes, array |
| item.shipping_method | required, string, in:SCHEDULED,FAST,scheduled,fast |

**Request Body (JSON)**:
```json
{
  "item": {
    "product_id": 10,
    "quantity": 2,
    "shipping_method": "SCHEDULED"
  }
}
```

**Response 201** (`CartResource::make($cart)`):
```json
{
  "status": 201,
  "message": "Cart created successfully",
  "success": true,
  "data": {
    "id": 1,
    "user_id": 3,
    "coupon": null,
    "coupon_code": null,
    "status": "active",
    "reserved_at": "2026-07-28T10:00:00.000000Z",
    "expires_at": "2026-07-31T10:00:00.000000Z",
    "total_items": 1,
    "total_quantity": 2,
    "subtotal": 999.98,
    "total_price": 999.98,
    "coupon_discount": 0,
    "total_after_coupon": 999.98,
    "normal_items_count": 1,
    "fast_items_count": 0,
    "normal_items": [],
    "fast_items": [],
    "has_eligible_promotion": false
  }
}
```

**Response 400** (business error, from `CartRepository::persistCart()` / `CartInventoryService`):
```json
{
  "status": 400,
  "message": "Quantity exceeds available stock.",
  "success": false
}
```

**Response 422** (validation):
```json
{
  "item.quantity": ["The item.quantity must be at least 1."],
  "item.shipping_method": ["The selected item.shipping_method is invalid."]
}
```

**Quick Test**:
```bash
# Add a simple product to cart
curl -X POST "http://localhost:8000/api/v1/cart" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"item": {"product_id": 10, "quantity": 2, "shipping_method": "SCHEDULED"}}'

# Add a variant product to cart
curl -X POST "http://localhost:8000/api/v1/cart" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"item": {"product_id": 15, "product_variant_id": 3, "quantity": 1, "shipping_method": "FAST"}}'
```

**Business Rules** (`CartRepository::persistCart('add')` → `CartInventoryService::incrementItem/reserveItem`):
- Runs in a `DB::transaction` with `lockForUpdate` on the cart row and the product/variant inventory row.
- Prices are **snapshotted** at reservation time via `ProductPricingService::calculateProductCurrentPrice()` / `calculateVariantCurrentPrice()`.
- Adding the same product + variant + shipping method increments the existing item's quantity (mode `add`).
- Reuses the existing cart (UNIQUE `user_id`) or creates one with `status = active`.
- Inventory is reserved immediately (`reserved_quantity += delta`).
- Cart receives a 3-day TTL (`expires_at = now() + 3 days`).
- FAST shipping requires `product.is_fast_shipping_available === true` (otherwise 400 `FAST_SHIPPING_PRODUCT_NOT_ELIGIBLE`).
- Variable products must send `product_variant_id` (otherwise 400 `INVALID_ITEM_DATA`).
- On success `revalidatePromotion()` clears any stale promotion/discount on cart items.
- Exceptions are converted to `400`; missing user to `401` (`HttpException`).

---

### GET /api/v1/cart/{id}

Get a specific cart by ID.

**Authentication**: `auth:sanctum`

**Path Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| id | int | Cart ID (numeric only — route has `->whereNumber('id')`) |

**Response 200**:
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": { "id": 1, "user_id": 3, "status": "active", "total_price": 999.98 }
}
```

**Response 401** (token invalid/absent): standard Sanctum 401.
**Response 403** (cart belongs to another user — `AuthorizationException(NOT_AUTHORIZED)`):
```json
{
  "status": 403,
  "message": "Not authorized",
  "success": false
}
```
**Response 404** (`findOrFail`): standard 404.

**Quick Test**:
```bash
curl -X GET "http://localhost:8000/api/v1/cart/1" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

**Business Rules**:
- Authorization: `cart.user_id` MUST equal the authenticated user id, otherwise `403` is thrown.
- Eager loads the same relations as `index`.

---

### PUT /api/v1/cart/update-item

Update an item's quantity. The `operation` field selects absolute (`set`) vs incremental behavior.

**Authentication**: `auth:sanctum`

**Request Body**:

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| item | object | required | Item data container |
| item.product_id | int | required | Product ID to update |
| item.quantity | int | required | Quantity (min: 1) |
| item.product_variant_id | int | sometimes | Variant ID (for variable products) |
| item.attributes | array | sometimes | Custom attributes |
| item.shipping_method | string | sometimes | If omitted, preserves existing item's method |
| item.operation | string | required | `increment` or `decrement` |

**Validation Rules** (`CartUpdateRequest`):

| Field | Rules |
|-------|-------|
| item | required, array, min:1 |
| item.product_id | required_with:item, integer, exists:products,id |
| item.quantity | required_with:item, integer, min:1 |
| item.product_variant_id | sometimes, nullable, integer, exists:product_variants,id |
| item.attributes | sometimes, array |
| item.shipping_method | sometimes, string, in:SCHEDULED,FAST,scheduled,fast |
| item.operation | required, string, in:increment,decrement |

**Request Body (JSON)**:
```json
{
  "item": {
    "product_id": 10,
    "quantity": 5,
    "operation": "increment"
  }
}
```

**Response 200**:
```json
{
  "status": 200,
  "message": "Cart updated successfully",
  "success": true,
  "data": { "id": 1, "total_price": 2499.95 }
}
```

**Response 400** (business error): `{ "status": 400, "message": "Quantity exceeds available stock.", "success": false }`

**Response 422** (validation, e.g. missing `operation`): field-error object.

**Quick Test**:
```bash
curl -X PUT "http://localhost:8000/api/v1/cart/update-item" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"item": {"product_id": 10, "quantity": 5, "operation": "increment"}}'
```

**Business Rules**:
- `operation=increment` → `CartInventoryService::incrementItem()` (existing qty + quantity).
- `operation=decrement` → `CartInventoryService::decrementItem()`; if the resulting quantity drops below 1 the item is deleted and its reserved stock released; if it was the last item the coupon is cleared.
- `CartRepository::persistCart('set')` preserves the existing item's `shipping_method` when omitted (only for the existing item match).
- Adjusts inventory delta (increase → reserve more; decrease → release excess).
- Promotion is revalidated after every update.

---

### DELETE /api/v1/cart/delete-item/{itemId}

Remove a single item from the cart.

**Authentication**: `auth:sanctum`

**Path Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| itemId | int | Cart item ID |

**Response 200**:
```json
{ "status": 200, "message": "Cart item deleted successfully", "success": true }
```

**Response 400** (no cart / not owner / item not found / inventory release failure):
```json
{ "status": 400, "message": "Failed to delete cart item", "success": false }
```

**Quick Test**:
```bash
curl -X DELETE "http://localhost:8000/api/v1/cart/delete-item/1" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

**Business Rules**:
- Releases reserved inventory back to available stock (`releaseItem($item, true)`).
- If the deleted item was the last, the coupon is cleared.
- `revalidatePromotion()` runs after deletion.
- Cart `total_price` is recalculated as `sum(items.total_price)`.

---

### DELETE /api/v1/cart/delete-items

Clear the entire cart.

**Authentication**: `auth:sanctum`

**Request Body** (optional):

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| confirm | boolean | sometimes | Required if cart has a coupon applied |

**Response 200**:
```json
{ "status": 200, "message": "Cart deleted successfully", "success": true }
```

**Response 200** (warning — coupon applied without confirm):
```json
{
  "status": 200,
  "message": "This cart has a coupon applied. Please confirm to proceed with deletion.",
  "success": true
}
```

**Response 400** (ownership mismatch): `{ "status": 400, "message": "Failed to delete cart item", "success": false }`
**Response 404** (no cart): `{ "status": 404, "message": "Cart not found", "success": false }`

**Quick Test**:
```bash
# Clear cart (no coupon — works directly)
curl -X DELETE "http://localhost:8000/api/v1/cart/delete-items" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"

# Clear cart with coupon — requires confirm
curl -X DELETE "http://localhost:8000/api/v1/cart/delete-items" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"confirm": true}'
```

**Business Rules**:
- `CartController::destroy()` returns `COUPON_DELETE_CART_WARNING` with **HTTP 200 and `success: true`** when a coupon is applied and `confirm` is not true (clients must check the message, not the status code).
- `releaseCart($cart, true)` releases all reserved inventory, deletes all items, resets `total_price = 0` and clears `expires_at`/`reserved_at`.

---

### POST /api/v1/cart/bulk-items

Bulk add multiple items to the cart. **Non-atomic** — each item is processed independently.

**Authentication**: `auth:sanctum`

**Request Body**:

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| items | array | required | Array of item objects |
| items.*.product_id | int | required | Valid product ID |
| items.*.quantity | int | required | Quantity (min: 1) |
| items.*.product_variant_id | int | sometimes | Variant ID |
| items.*.shipping_method | string | sometimes | `SCHEDULED` or `FAST` (defaults to SCHEDULED) |

**Validation Rules** (inline in `pluckItemsToCart`):

| Field | Rules |
|-------|-------|
| items | required, array |
| items.*.product_id | required, integer |
| items.*.quantity | required, integer, min:1 |
| items.*.product_variant_id | nullable, integer |
| items.*.shipping_method | nullable, string, in:scheduled,fast,SCHEDULED,FAST |

**Request Body (JSON)**:
```json
{
  "items": [
    { "product_id": 10, "quantity": 2, "shipping_method": "SCHEDULED" },
    { "product_id": 15, "product_variant_id": 3, "quantity": 1, "shipping_method": "FAST" }
  ]
}
```

**Response 201**:
```json
{
  "status": 201,
  "message": "Cart created successfully",
  "success": true,
  "data": {
    "cart": { "id": 1, "user_id": 3, "status": "active", "total_price": 2499.97 },
    "skipped_product_ids": [],
    "failed_items": []
  }
}
```

**Response 201** (with skipped products and per-item failures):
```json
{
  "status": 201,
  "message": "Cart created successfully",
  "success": true,
  "data": {
    "cart": { "id": 1, "user_id": 3, "status": "active", "total_price": 999.98 },
    "skipped_product_ids": [99, 100],
    "failed_items": [
      { "product_id": 15, "product_variant_id": 3, "reason": "Quantity exceeds available stock." }
    ]
  }
}
```

> Note: when ALL items fail, `cart` is `null`.

**Quick Test**:
```bash
curl -X POST "http://localhost:8000/api/v1/cart/bulk-items" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"items": [{"product_id": 10, "quantity": 2, "shipping_method": "SCHEDULED"}, {"product_id": 15, "quantity": 1, "shipping_method": "FAST"}]}'
```

**Business Rules** (Rev 3 behavior):
- Non-existent or soft-deleted products are **silently skipped** and their IDs returned in `skipped_product_ids`.
- Each valid item is processed individually through `CartRepository::storeCart()` in a **try/catch** — there is **no outer DB transaction and no rollback**.
- Items that fail at runtime (e.g., stock exceeded, FAST ineligible, missing variant) are captured per-item in `failed_items` with `product_id`, `product_variant_id`, `reason`; processing continues with the remaining items.
- `shipping_method` defaults to `SCHEDULED` when omitted.
- The response returns the fully loaded cart under `data.cart`.
