# Promotions API

## Overview

Endpoints for managing promotional offers. Promotions support percentage discounts, fixed-rate discounts, and gift-based promotions with product/variant associations.

## Authentication

All endpoints require authentication via Sanctum. Tokens are obtained via login endpoints.

---

## Endpoints

### 1. List Promotions

**Endpoint**

`GET /promotions`

**Permissions**

| Permission | Role |
|---|---|
| `view-promotion` | Super Admin / Store Owner / Staff |

**Query Parameters**

| Parameter | Type | Required | Description |
|---|---|---|---|
| `limit` | integer | No | Items per page (default: 15) |
| `page` | integer | No | Page number |
| `search` | string | No | LIKE search on `name`, `code`, and `type` fields |
| `status` | boolean | No | Filter by active status (`true`/`false`) |
| `type` | string | No | Filter by promotion type (`price`, `quantity`) |
| `type_amount` | string | No | Filter by discount type (`fixed_rate`, `percentage`, `gift`) |
| `order_by` | string | No | Column to sort by (default: `created_at`) |
| `sort` | string | No | Sort direction `asc` or `desc` (default: `desc`) |

**Example URLs**

```
GET /promotions?limit=10&page=1
GET /promotions?search=summer&order_by=name&sort=asc
GET /promotions?type=price&type_amount=percentage&status=true
GET /promotions?search=ALLX&limit=20&page=1
GET /promotions?order_by=created_at&sort=desc
```

**Success Response (200)**

```json
{
    "success": true,
    "message": "Data fetched successfully",
    "data": {
        "data": [
            {
                "id": 1,
                "name": {"en": "Summer Special 20% Off", "ar": "عرض الصيف خصم 20%"},
                "slug": "summer-special-20-off",
                "type": "Price discount",
                "discount_type": "percentage",
                "value": 20,
                "discount": 20,
                "code": "ALLXK8M2P9",
                "minimum_order_amount": 500,
                "required_quantity": 2,
                "apply_to": "all_products",
                "products": [],
                "gift_products": [],
                "image": "https://...",
                "start_at": "2026-06-12T00:00:00+00:00",
                "end_at": "2026-08-21T00:00:00+00:00",
                "status": true,
                "is_valid": true,
                "created_at": "2026-06-22T00:00:00+00:00"
            }
        ],
        "page": 1,
        "current_page": 1,
        "from": 1,
        "to": 15,
        "last_page": 1,
        "path": "http://example.com/promotions",
        "per_page": 15,
        "total": 5,
        "next_page_url": "",
        "prev_page_url": "",
        "last_page_url": "http://example.com/promotions?page=1",
        "first_page_url": "http://example.com/promotions?page=1"
    }
}
```

**Error Responses**

| Code | Description |
|---|---|
| 401 | Unauthenticated |
| 403 | Forbidden - missing `view-promotion` permission |

---

### 2. Get Single Promotion

**Endpoint**

`GET /promotions/{id}`

**Permissions**

| Permission | Role |
|---|---|
| `view-promotion` | Super Admin / Store Owner / Staff |

**Path Parameters**

| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | integer | Yes | Promotion ID |

**Success Response (200)**

```json
{
    "success": true,
    "message": "Data fetched successfully",
    "data": {
        "id": 1,
        "name": {"en": "Summer Special 20% Off", "ar": "عرض الصيف خصم 20%"},
        "slug": "summer-special-20-off",
        "type": "Price discount",
        "discount_type": "percentage",
        "value": 20,
        "discount": 20,
        "code": "ALLXK8M2P9",
        "minimum_order_amount": 500,
        "required_quantity": 2,
        "apply_to": "all_products",
        "products": [],
        "gift_products": [],
        "image": null,
        "start_at": "2026-06-12T00:00:00+00:00",
        "end_at": "2026-08-21T00:00:00+00:00",
        "status": true,
        "is_valid": true,
        "created_at": "2026-06-22T00:00:00+00:00"
    }
}
```

**Error Responses**

| Code | Description |
|---|---|
| 401 | Unauthenticated |
| 403 | Forbidden - missing `view-promotion` permission |
| 404 | Promotion not found |

---

### 3. Create Promotion

**Endpoint**

`POST /promotions`

**Permissions**

| Permission | Role |
|---|---|
| `create-promotion` | Super Admin / Store Owner |

**Request Body (multipart/form-data)**

| Field | Type | Required | Description |
|---|---|---|---|
| `name` | object (translated) | Yes | `{"en": "...", "ar": "..."}` |
| `type` | string | Yes | `price` or `quantity` |
| `type_amount` | string | Yes | `fixed_rate`, `percentage`, or `gift` |
| `discount` | numeric | Conditional | Required unless `type=quantity` AND gift products are provided |
| `max_discount_amount` | numeric | Conditional | **Required** when `type_amount=percentage` |
| `required_quantity_type` | integer | Conditional | Required when `type=quantity` |
| `minimum_order_amount` | numeric | Conditional | Required when `type!=quantity` |
| `apply_to` | string | **Required** | `all_products` or `specific_products` |
| `product_ids` | array | Conditional | **Required** when `apply_to=specific_products`, **prohibited** when `apply_to=all_products` |
| `gift_products` | array | Conditional | **Required** when `type_amount=gift`, min:1 |
| `limiter` | integer | No | Max usage limit |
| `start_at` | date | No | Must be before or equal to today |
| `end_at` | date | No | Must be after or equal to start_at |
| `status` | boolean | No | `0` or `1` |
| `image_desktop` | file | Yes | JPEG, PNG, JPG, WEBP |
| `image_mobile` | file | Yes | JPEG, PNG, JPG, WEBP |

**Validation Rules**

| Rule | Logic |
|---|---|
| `name` | required, array, unique translation per locale |
| `image_desktop` | required, image, mimes: jpeg,png,jpg,webp |
| `image_mobile` | required, image, mimes: jpeg,png,jpg,webp |
| `type` | required, must be in `PromotionType` enum (`price`, `quantity`) |
| `type_amount` | required, must be in `PromotionMountType` enum (`fixed_rate`, `percentage`, `gift`) |
| `apply_to` | **required**, in: `all_products`, `specific_products` |
| `product_ids` | `requiredIf:apply_to=specific_products`, `prohibitedIf:apply_to=all_products`, array |
| `product_ids.*` | exists:products,id |
| `gift_products` | `required_if:type_amount,gift`, array, min:1 |
| `gift_products.*.product_id` | required_with:gift_products, exists:products,id |
| `gift_products.*.product_variant_id` | nullable, exists:product_variants,id |
| `gift_products.*.quantity` | sometimes, integer, min:1 |
| `discount` | numeric, min:0, required unless `type=quantity` with gift products |
| `max_discount_amount` | **required_if:type_amount,percentage**, numeric, min:1 |
| `required_quantity_type` | integer, min:1, required when `type=quantity` |
| `minimum_order_amount` | numeric, min:0, required when `type!=quantity` |
| `limiter` | sometimes, integer, min:1 |
| `start_at` | sometimes, date, before_or_equal:today |
| `end_at` | sometimes, date, after_or_equal:start_at |
| `status` | sometimes, in: `0`, `1` |

**Business Rules**

- A unique promo `code` is auto-generated on creation.
- Slug is auto-generated from the name.
- If `gift_product_ids` or `gift_products` are provided, the variant must belong to the selected product.
- Images are stored via Spatie Media Library in collections: `promotions-desktop`, `promotions-mobile`.

**Success Response (201)**

```json
{
    "success": true,
    "message": "Promotion created successfully",
    "data": { ... }
}
```

**Error Responses**

| Code | Description |
|---|---|
| 400 | Could not create the resource |
| 401 | Unauthenticated |
| 403 | Forbidden - missing `create-promotion` permission |
| 422 | Validation error |

---

### 4. Update Promotion

**Endpoint**

`PUT /promotions/{id}`

**Permissions**

| Permission | Role |
|---|---|
| `update-promotion` | Super Admin / Store Owner / Staff |

**Request Body (multipart/form-data)**

All fields are optional (`sometimes`). Note: image keys use **hyphens** in update vs **underscores** in create.

| Field | Type | Required | Description |
|---|---|---|---|
| `name` | object (translated) | No | `{"en": "...", "ar": "..."}` |
| `type` | string | No | `price` or `quantity` |
| `type_amount` | string | No | `fixed_rate`, `percentage`, or `gift` |
| `discount` | numeric | No | |
| `max_discount_amount` | numeric | No | |
| `required_quantity_type` | integer | No | |
| `minimum_order_amount` | numeric | No | |
| `apply_to` | string | No | `all_products` or `specific_products` (`nullable`) |
| `product_ids` | array | No | **Prohibited** when `apply_to=all_products` |
| `gift_product_ids` | array | No | Array of gift product IDs (min:1) |
| `gift_products` | array | No | Array of `{product_id, product_variant_id, quantity}` |
| `limiter` | integer | No | |
| `start_at` | date | No | Must be before or equal to today |
| `end_at` | date | No | Must be after or equal to start_at |
| `status` | boolean | No | `0` or `1` |
| `image-desktop` | file | No | JPEG, PNG, JPG, WEBP (hyphen key) |
| `image-mobile` | file | No | JPEG, PNG, JPG, WEBP (hyphen key) |

**Validation Rules**

| Rule | Logic |
|---|---|
| `name` | sometimes, array, unique translation per locale |
| `image-desktop` | sometimes, image, mimes: jpeg,png,jpg,webp |
| `image-mobile` | sometimes, image, mimes: jpeg,png,jpg,webp |
| `type` | sometimes, in: `price`, `quantity` |
| `type_amount` | sometimes, in: `fixed_rate`, `percentage`, `gift` |
| `apply_to` | nullable, in: `all_products`, `specific_products` |
| `product_ids` | sometimes, array, `prohibitedIf:apply_to=all_products` |
| `product_ids.*` | exists:products,id |
| `gift_product_ids` | sometimes, array, min:1 |
| `gift_product_ids.*` | exists:products,id |
| `gift_products` | sometimes, array, min:1 |
| `gift_products.*.product_id` | required_with:gift_products, exists:products,id |
| `gift_products.*.product_variant_id` | nullable, exists:product_variants,id |
| `gift_products.*.quantity` | sometimes, integer, min:1 |
| `discount` | sometimes, numeric, min:0, required unless `type=quantity` with gift products |
| `max_discount_amount` | sometimes, numeric, min:1 |
| `required_quantity_type` | sometimes, integer, min:1 |
| `minimum_order_amount` | sometimes, numeric, min:0, required when `type!=quantity` |
| `limiter` | nullable, integer, min:1 |
| `start_at` | nullable, date, before_or_equal:today |
| `end_at` | nullable, date, after_or_equal:start_at |
| `status` | sometimes, in: `0`, `1` |

Key differences from Create:
- All fields use `sometimes` or `nullable` instead of `required`
- `product_ids` not required when `apply_to=specific_products` (user may only update other fields)
- `gift_products` not `required_if:type_amount,gift` (user may only update other fields)
- `max_discount_amount` not `required_if:type_amount,percentage`
- `gift_product_ids` field exists in update only (not in create)
- Image field keys use hyphens (`image-desktop`, `image-mobile`) vs underscores in create
- `max_discount_amount`: `sometimes` instead of `required_if:type_amount,percentage`
- `discount`: `sometimes` prefix added but same `requiredIf` logic
- `minimum_order_amount`: `sometimes` prefix added but same `requiredIf` logic
- `gift_product_ids`: only in update (`sometimes`), not in create
- Image field keys use hyphens (`image-desktop`, `image-mobile`) vs underscores in create

**Success Response (200)**

```json
{
    "success": true,
    "message": "Promotion updated successfully",
    "data": { ... }
}
```

**Error Responses**

| Code | Description |
|---|---|
| 400 | Could not update the resource |
| 401 | Unauthenticated |
| 403 | Forbidden - missing `update-promotion` permission |
| 404 | Promotion not found |
| 422 | Validation error |

---

### 5. Delete Promotion

**Endpoint**

`DELETE /promotions/{id}`

**Permissions**

| Permission | Role |
|---|---|
| `delete-promotion` | Super Admin / Store Owner |

**Path Parameters**

| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | integer | Yes | Promotion ID |

**Success Response (200)**

```json
{
    "success": true,
    "message": "Promotion deleted successfully"
}
```

**Error Responses**

| Code | Description |
|---|---|
| 400 | Could not delete the resource |
| 401 | Unauthenticated |
| 403 | Forbidden - missing `delete-promotion` permission |
| 404 | Promotion not found |

---

## Database Impact

| Table | Relation | Type |
|---|---|---|
| `promotions` | Main table | CRUD |
| `promotion_product` | Many-to-many with `products` | Sync |
| `promotion_gift_products` | Many-to-many with `products` (with pivot: quantity, product_variant_id) | Sync |
| `media` | Spatie Media Library | Images |

## Resource Structure

| Field | Type | Description |
|---|---|---|
| `id` | integer | Primary key |
| `name` | object | Translated name `{en, ar}` |
| `slug` | string | URL slug |
| `type` | string | Localized type name (`typeByLang()`) |
| `discount_type` | string | `fixed_rate`, `percentage`, or `gift` |
| `value` | float | Discount value |
| `discount` | float | Same as value (synced) |
| `code` | string | Auto-generated unique code |
| `minimum_order_amount` | float | Min order to apply |
| `required_quantity` | integer | Min quantity required |
| `apply_to` | string | `all_products` or `specific_products` |
| `products` | array | When loaded |
| `gift_products` | array | When loaded (with pivot: quantity, product_variant_id) |
| `image` | string | First media URL from `promotions` collection |
| `start_at` | ISO 8601 | Promotion start |
| `end_at` | ISO 8601 | Promotion end |
| `status` | boolean | Active/inactive |
| `is_valid` | boolean | Computed: status + date range + usage < limiter |
| `created_at` | ISO 8601 | Creation timestamp |

## Model Scopes

| Scope | Description |
|---|---|
| `active()` | `where('status', true)` |
| `valid()` | Active + within date range + usage < limiter |
| `search($field, $term)` | LIKE search on given field |

## Dependencies

| Component | Path |
|---|---|
| Controller | `packages/marvel/src/Http/Controllers/PromotionController.php` |
| Create Request | `packages/marvel/src/Http/Requests/PromotionRequest.php` |
| Update Request | `packages/marvel/src/Http/Requests/UpdatePromotionRequest.php` |
| Resource | `packages/marvel/src/Http/Resources/PromotionResource.php` |
| Repository | `packages/marvel/src/Database/Repositories/PromotionRepository.php` |
| Model | `packages/marvel/src/Database/Models/Promotion.php` |
| Type Enum | `packages/marvel/src/Enums/PromotionType.php` |
| Mount Type Enum | `packages/marvel/src/Enums/PromotionMountType.php` |
| Permission Enum | `packages/marvel/src/Enums/Permission.php` |
| Seeder | `database/seeders/PromotionSeeder.php` |
| Translations (EN) | `resources/lang/en/message.php` |
| Translations (AR) | `resources/lang/ar/message.php` |
| Constants | `packages/marvel/config/constants.php` |
