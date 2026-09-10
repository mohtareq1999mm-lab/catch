# Coupons API

## Overview

The Coupons module manages discount coupons with support for **percentage** and **fixed_rate** discount types. Each coupon features auto-generated unique codes, date validity windows, usage limits, translatable names, media images, and product-specific applicability.

---

## Database Schema

### `coupons` Table

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | bigint | PK, AUTO_INCREMENT | Unique identifier |
| `code` | varchar(255) | UNIQUE, NOT NULL | Auto-generated unique code |
| `name` | json | NOT NULL | Translatable name |
| `slug` | varchar(255) | NOT NULL | Auto-generated from English name |
| `discount_type` | enum | `fixed_rate` or `percentage` | Type of discount |
| `discount` | decimal(8,3) | NULLABLE | Discount value |
| `max_discount_amount` | decimal(10,2) | NULLABLE | Cap for percentage discounts |
| `start_date` | date | NOT NULL | Validity start |
| `end_date` | date | NOT NULL | Validity end |
| `limiter` | integer | NULLABLE | Max usage count (null = unlimited) |
| `used` | integer | DEFAULT 0 | Current usage count |
| `status` | tinyint(1) | DEFAULT true | Active/inactive |
| `border_color` | varchar(255) | NULLABLE | Display border color |
| `borderless` | tinyint(1) | DEFAULT false | Display without border |
| `created_at` | timestamp | NULLABLE | Creation time |
| `updated_at` | timestamp | NULLABLE | Last update |

### Pivot Tables

**`coupon_product`** — which products a coupon applies to:
| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `coupon_id` | bigint | FK → coupons.id, CASCADE | Coupon reference |
| `product_id` | bigint | FK → products.id, CASCADE | Product reference |

**`coupon_usages`** — tracks user usage per order:
| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | bigint | PK, AUTO_INCREMENT | Unique identifier |
| `coupon_id` | bigint | FK → coupons.id | Coupon reference |
| `user_id` | bigint | FK → users.id | User reference |
| `order_id` | bigint | FK → orders.id | Order reference |
| `used_at` | timestamp | | When used |

**`coupon_shop`** — which shops a coupon belongs to:
| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `coupon_id` | bigint | FK → coupons.id, CASCADE | Coupon reference |
| `shop_id` | bigint | FK → shops.id, CASCADE | Shop reference |

---

## Response Envelope

All endpoints return:

```json
{
    "status": 200,
    "message": "Translated message string",
    "success": true,
    "data": {}
}
```

---

## Resource Structure

### CouponResource

| Field | Type | Description |
|-------|------|-------------|
| `id` | int | Coupon ID |
| `code` | string | Auto-generated unique code (`{slug}_{10_random_uppercase}`) |
| `name` | string | Translated name |
| `image.desktop` | string|null | Desktop image URL |
| `image.mobile` | string|null | Mobile image URL |
| `borderColor` | string|null | Border display color |
| `borderless` | bool | Whether coupon displays without border |
| `discount` | decimal | Discount value |
| `discount_type` | string | Localized type ("Percentage discount" / "Fixed discount") |
| `max_discount_amount` | decimal|null | Max cap for percentage discounts |
| `start_date` | date | Validity start (`Y-m-d`) |
| `end_date` | date | Validity end (`Y-m-d`) |
| `limiter` | int|null | Max usage count |
| `used` | int | Current usage count |
| `status` | bool | Active/inactive |
| `is_valid` | bool | Runtime validity check (status + dates + limiter) |
| `created_at` | string | ISO 8601 timestamp |

---

## Endpoints

### GET /coupons — List Coupons

**Purpose:** List all coupons with optional filtering, search, and pagination.

**Method:** `GET`

**URL:** `/coupons`

**Authentication:** Optional (public reads available)

**Permissions:** `view-coupons`

**Query Parameters:**

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `page` | int | 1 | Page number |
| `per_page` | int | 15 | Results per page (alias: `limit`) |
| `limit` | int | 15 | Results per page (alias: `per_page`) |
| `search` | string | — | Search in `name` (translatable) or `code` |
| `active` | bool | — | Filter valid coupons only (status + dates + limiter) |
| `inactive` | bool | — | Filter invalid/expired coupons only |
| `order` | string | — | Field to sort by. Allowed: `id`, `code`, `name`, `discount`, `discount_type`, `start_date`, `end_date`, `limiter`, `used`, `status`, `created_at`, `updated_at` |
| `sortedBy` | string | `asc` | Sort direction (`asc` or `desc`). Only applies when `order` is set. |
| `type` | string | — | Filter by coupon type |

**Example Usage:**
```
GET /coupons?page=2&per_page=20                       # Page 2, 20 per page
GET /coupons?active=1                                  # Valid coupons only
GET /coupons?search=summer                             # Search name/code
GET /coupons?order=discount&sortedBy=desc              # Biggest discount first
GET /coupons?order=created_at&sortedBy=desc            # Newest first
GET /coupons?type=fixed_rate&status=1                  # Active fixed-rate coupons
```

**Business Logic:**
1. Builds query via `fetchCoupons()` helper
2. If `active=true`, applies `valid()` scope (status=true, within dates, under limiter)
3. If `inactive=true`, applies `invalid()` scope
4. If `search`, searches `name` (translatable) or `code` with LIKE
5. If `order` is a valid field, applies `orderBy($order, $sortedBy)`
6. Default ordering: `updated_at DESC` (global scope on model)
7. Paginates with given limit
8. Returns paginated `CouponResource` collection

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": {
        "data": [
            {
                "id": 1,
                "code": "summer_sale_A1B2C3D4E5",
                "name": "Summer Sale",
                "image": {
                    "desktop": "https://cdn.example.com/storage/coupons/1/desktop.jpg",
                    "mobile": "https://cdn.example.com/storage/coupons/1/mobile.jpg"
                },
                "borderColor": "#ff0000",
                "borderless": false,
                "discount": "20.000",
                "discount_type": "Percentage discount",
                "max_discount_amount": "50.00",
                "start_date": "2024-01-01",
                "end_date": "2024-12-31",
                "limiter": 100,
                "used": 0,
                "status": true,
                "is_valid": true,
                "created_at": "2024-01-01T00:00:00+00:00"
            }
        ],
        "page": 1,
        "current_page": 1,
        "from": 1,
        "to": 15,
        "last_page": 1,
        "path": "https://api.example.com/coupons",
        "per_page": 15,
        "total": 5,
        "next_page_url": null,
        "prev_page_url": null,
        "last_page_url": "https://api.example.com/coupons?page=1",
        "first_page_url": "https://api.example.com/coupons?page=1"
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `view-coupons` permission |

---

### POST /coupons — Create Coupon

**Purpose:** Create a new discount coupon.

**Method:** `POST`

**URL:** `/coupons`

**Authentication:** Required

**Permissions:** `create-coupon`

**Request Body (multipart/form-data):**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `name` | object | **Yes** | Translatable array |
| `name.*` | string | **Yes** | Unique translation |
| `discount` | numeric | **Yes** | `numeric`, `min:0` |
| `discount_type` | string | **Yes** | `in:fixed_rate,percentage` |
| `max_discount_amount` | numeric | Required if `discount_type=percentage` | `numeric`, `min:1` |
| `start_date` | date | **Yes** | `date_format:Y-m-d` |
| `end_date` | date | **Yes** | `date_format:Y-m-d`, `after_or_equal:start_date` |
| `limiter` | int | No | `integer`, `min:0` |
| `status` | mixed | No | `in:1,0` (default: true) |
| `border_color` | string | No | `string`, `max:50` |
| `borderless` | mixed | No | `in:1,0` (default: false) |
| `image-desktop` | file | **Yes** | `image`, `mimes:jpeg,png,jpg,webp` |
| `image-mobile` | file | **Yes** | `image`, `mimes:jpeg,png,jpg,webp` |

**Example Request (multipart/form-data):**
```json
{
    "name": {
        "en": "Summer Sale",
        "ar": "تخفيضات الصيف"
    },
    "discount": 20,
    "discount_type": "percentage",
    "max_discount_amount": 50,
    "start_date": "2024-06-01",
    "end_date": "2024-08-31",
    "limiter": 100,
    "border_color": "#ff0000"
}
```
Images are sent as file fields (`image-desktop`, `image-mobile`) in the multipart form-data.

**Business Logic:**
1. Validates via `CouponRequest` (name uniqueness, discount type enum, date ordering)
2. Code auto-generated: `{slugified_en_name}_{10_random_uppercase_chars}`
3. Slug auto-generated from English name on `saving` event
4. Uploads desktop and mobile images via `MediaManager` trait on `coupons` collection
5. Uses database transaction for atomicity
6. Returns created coupon resource

**Success Response (201):**
```json
{
    "status": 201,
    "message": "Coupon created successfully",
    "success": true,
    "data": {
        "id": 1,
        "code": "summer_sale_A1B2C3D4E5",
        "name": "Summer Sale",
        "image": {
            "desktop": "https://cdn.example.com/storage/coupons/1/desktop.jpg",
            "mobile": "https://cdn.example.com/storage/coupons/1/mobile.jpg"
        },
        "borderColor": "#ff0000",
        "borderless": false,
        "discount": "20.000",
        "discount_type": "Percentage discount",
        "max_discount_amount": "50.00",
        "start_date": "2024-06-01",
        "end_date": "2024-08-31",
        "limiter": 100,
        "used": 0,
        "status": true,
        "is_valid": true,
        "created_at": "2024-06-01T00:00:00+00:00"
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `create-coupon` permission |
| 422 | Validation failure |
| 500 | Server error |

---

### GET /coupons/{id} — Show Coupon

**Purpose:** Fetch a single coupon by ID or code.

**Method:** `GET`

**URL:** `/coupons/{id}`

**Authentication:** Optional

**Permissions:** `view-coupons`

**Query Parameters:**
| Field | Type | Description |
|-------|------|-------------|
| `language` | string | Language code for translation |

**Business Logic:**
1. Finds coupon by `id` or by `code` (first match)
2. Returns `CouponResource`

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": {
        "id": 1,
        "code": "summer_sale_A1B2C3D4E5",
        "name": "Summer Sale",
        "image": {
            "desktop": "https://cdn.example.com/storage/coupons/1/desktop.jpg",
            "mobile": "https://cdn.example.com/storage/coupons/1/mobile.jpg"
        },
        "borderColor": "#ff0000",
        "borderless": false,
        "discount": "20.000",
        "discount_type": "Percentage discount",
        "max_discount_amount": "50.00",
        "start_date": "2024-06-01",
        "end_date": "2024-08-31",
        "limiter": 100,
        "used": 0,
        "status": true,
        "is_valid": true,
        "created_at": "2024-06-01T00:00:00+00:00"
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `view-coupons` permission |
| 404 | Coupon not found |

---

### PUT /coupons/{id} — Update Coupon

**Purpose:** Update an existing coupon's fields and images.

**Method:** `PUT`

**URL:** `/coupons/{id}`

**Authentication:** Required

**Permissions:** `update-coupon`

**Request Body (multipart/form-data):**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `name` | object | No | Translatable array |
| `name.*` | string | No | Unique translation (ignores self) |
| `discount` | numeric | No | `numeric`, `min:0` |
| `discount_type` | string | No | `in:fixed_rate,percentage` |
| `max_discount_amount` | numeric | Required if `discount_type=percentage` | `numeric`, `min:1` |
| `start_date` | date | No | `date_format:Y-m-d` |
| `end_date` | date | No | `date_format:Y-m-d`, `after_or_equal:start_date` |
| `limiter` | int | No | `integer`, `min:0` |
| `status` | mixed | No | `in:1,0` |
| `border_color` | string | No | `string`, `max:50` |
| `borderless` | mixed | No | `in:1,0` |
| `image-desktop` | file | No | `image`, `mimes:jpeg,png,jpg,webp` |
| `image-mobile` | file | No | `image`, `mimes:jpeg,png,jpg,webp` |

**Example Request:**
```json
{
    "name": {
        "en": "Winter Sale",
        "ar": "تخفيضات الشتاء"
    },
    "discount": 30,
    "limiter": 200
}
```

**Business Logic:**
1. Validates via `UpdateCouponRequest` (all fields optional, name unique ignores self)
2. Updates coupon fields
3. Updates desktop/mobile images if provided (replaces existing)
4. Uses database transaction for atomicity
5. Returns updated coupon resource

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Coupon updated successfully",
    "success": true,
    "data": {
        "id": 1,
        "code": "summer_sale_A1B2C3D4E5",
        "name": "Winter Sale",
        "image": {
            "desktop": "https://cdn.example.com/storage/coupons/1/desktop.jpg",
            "mobile": "https://cdn.example.com/storage/coupons/1/mobile.jpg"
        },
        "borderColor": "#ff0000",
        "borderless": false,
        "discount": "30.000",
        "discount_type": "Percentage discount",
        "max_discount_amount": "50.00",
        "start_date": "2024-06-01",
        "end_date": "2024-08-31",
        "limiter": 200,
        "used": 0,
        "status": true,
        "is_valid": true,
        "created_at": "2024-06-01T00:00:00+00:00"
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `update-coupon` permission |
| 404 | Coupon not found |
| 422 | Validation failure |

---

### DELETE /coupons/{id} — Delete Coupon

**Purpose:** Delete a coupon.

**Method:** `DELETE`

**URL:** `/coupons/{id}`

**Authentication:** Required

**Permissions:** `delete-coupon`

**Business Logic:**
1. Finds coupon by ID
2. Deletes the record (hard delete)
3. Catches `ModelNotFoundException` and throws `NOT_FOUND`

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Coupon deleted successfully",
    "success": true
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `delete-coupon` permission |
| 404 | Coupon not found |

---

### POST /coupons/verify — Verify Coupon Code

**Purpose:** Verify a coupon code against a subtotal amount.

**Method:** `POST`

**URL:** `/coupons/verify`

**Authentication:** None

**Note:** Commented out in the controller; uses GraphQL via `verifyCoupon` mutation instead.

**Request Body:**
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `code` | string | **Yes** | Coupon code |
| `sub_total` | number | **Yes** | Cart subtotal to verify against |

---

### POST /coupons/add-to-cart — Apply Coupon to Cart

**Purpose:** Apply a coupon code to the current user's active cart.

**Method:** `POST`

**URL:** `/coupons/add-to-cart`

**Authentication:** Required

**Request Body (JSON):**
| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `code` | string | **Yes** | `string`, `exists:coupons,code` |

**Business Logic:**
1. Validates code exists in `coupons` table
2. Finds coupon and checks `isValid()` (status, date range, usage limit)
3. Checks user has a cart with items
4. If cart already has a valid coupon applied, returns error
5. Updates cart's `coupon` field with the code
6. Tracks usage via `coupon_usages` table

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Coupon added to cart successfully",
    "success": true
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 400 | Coupon not valid / cart empty / coupon already applied |
| 401 | Unauthenticated |
| 404 | Coupon code not found |

---

### POST /approve-coupon — Approve Coupon

**Purpose:** Approve a vendor-created coupon for public use.

**Method:** `POST`

**URL:** `/approve-coupon`

**Authentication:** Required

**Permissions:** `super_admin`

**Request Body (JSON):**
| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `id` | integer | **Yes** | Coupon ID to approve |

**Business Logic:**
1. Finds coupon by ID
2. Sets `is_approve` to `true`
3. Returns updated coupon resource

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Coupon updated successfully",
    "success": true,
    "data": { ... }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `super_admin` permission |
| 404 | Coupon not found |

---

### POST /disapprove-coupon — Disapprove Coupon

**Purpose:** Reject/disapprove a vendor-created coupon.

**Method:** `POST`

**URL:** `/disapprove-coupon`

**Authentication:** Required

**Permissions:** `super_admin`

**Request Body (JSON):**
| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `id` | integer | **Yes** | Coupon ID to disapprove |

**Business Logic:**
1. Finds coupon by ID
2. Sets `is_approve` to `false`
3. Returns updated coupon resource

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Coupon updated successfully",
    "success": true,
    "data": { ... }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `super_admin` permission |
| 404 | Coupon not found |

---

## Route Definitions

```php
// Public routes (no auth)
Route::apiResource('coupons', CouponController::class, ['only' => ['index', 'show']]);
Route::post('coupons/verify', [CouponController::class, 'verify']);

// Authenticated routes (auth:sanctum)
Route::post('coupons/add-to-cart', [CouponController::class, 'addCouponToCart']);

// Vendor dashboard routes (auth + shop ownership)
Route::apiResource('coupons', CouponController::class, ['only' => ['update']]);

// Admin routes (auth + permissions)
Route::apiResource('coupons', CouponController::class, ['only' => ['store', 'destroy']]);
Route::post('approve-coupon', [CouponController::class, 'approveCoupon']);
Route::post('disapprove-coupon', [CouponController::class, 'disApproveCoupon']);
```

Source: `packages/marvel/src/Rest/Routes.php`

---

## Permissions Map

| Permission Enum | String | Applied To |
|----------------|--------|------------|
| `VIEW_COUPONS` | `view-coupons` | `index`, `show` |
| `CREATE_COUPON` | `create-coupon` | `store` |
| `UPDATE_COUPON` | `update-coupon` | `update` |
| `DELETE_COUPON` | `delete-coupon` | `destroy` |
| `SUPER_ADMIN` | `super-admin` | `approveCoupon`, `disApproveCoupon` |

---

## Model Features

- **Translatable:** `name` field (Spatie `HasTranslations`)
- **MediaLibrary:** Images managed via Spatie MediaLibrary (`coupons-desktop`, `coupons-mobile` collections)
- **Code:** Auto-generated on `creating` event: `{slugified_en_name}_{10_random_uppercase_chars}`
- **Slug:** Auto-generated from English name on `saving` event
- **Global Scope:** Ordered by `updated_at` descending
- **Discount Calculation:** `calcPrice($price)` method — handles percentage (with cap) and fixed_rate
- **Validity Check:** `isValid()` method — checks status, date range, and usage limiter
- **Scopes:** `valid()` and `invalid()` for query filtering
- **Relations:**
  - `BelongsToMany` with `Product` via `coupon_product` pivot
  - `BelongsToMany` with `Shop` via `coupon_shop` pivot
  - `BelongsToMany` with `User` via `coupon_usages` pivot (with `order_id`, `used_at`)
  - `HasMany` with `Order` via `coupon_id`
  - `HasMany` with `CouponUsage`

---

## Dependencies

| Class | Type | File |
|-------|------|------|
| `CouponController` | Controller | `packages/marvel/src/Http/Controllers/CouponController.php` |
| `CouponRepository` | Repository | `packages/marvel/src/Database/Repositories/CouponRepository.php` |
| `Coupon` | Model | `packages/marvel/src/Database/Models/Coupon.php` |
| `CouponResource` | Resource | `packages/marvel/src/Http/Resources/CouponResource.php` |
| `CouponRequest` | Form Request (Create) | `packages/marvel/src/Http/Requests/CouponRequest.php` |
| `UpdateCouponRequest` | Form Request (Update) | `packages/marvel/src/Http/Requests/UpdateCouponRequest.php` |
| `DiscountType` | Enum | `packages/marvel/src/Enums/DiscountType.php` |
| `Permission` | Enum | `packages/marvel/src/Enums/Permission.php` |
| `CouponService` | Service (public) | `app/Services/General/CouponService.php` |
| `ProductPricingService` | Service | `packages/marvel/src/Services/Pricing/ProductPricingService.php` |
| `CouponQuery` | GraphQL Query | `packages/marvel/src/GraphQL/Queries/CouponQuery.php` |
| `CouponMutator` | GraphQL Mutation | `packages/marvel/src/GraphQL/Mutations/CouponMutator.php` |
| `Coupon GraphQL Schema` | GraphQL | `packages/marvel/src/GraphQL/Schema/models/coupon.graphql` |

---

## Translations

| Key | English | Arabic |
|-----|---------|--------|
| `MESSAGE.CREATED_COUPON_SUCCESSFULLY` | Coupon created successfully | تم إنشاء القسيمة بنجاح |
| `MESSAGE.UPDATED_COUPON_SUCCESSFULLY` | Coupon updated successfully | تم تحديث القسيمة بنجاح |
| `MESSAGE.DELETED_COUPON_SUCCESSFULLY` | Coupon deleted successfully | تم حذف القسيمة بنجاح |
| `MESSAGE.COUPON_ADDED_TO_CART_SUCCESSFULLY` | Coupon added to cart successfully | تمت إضافة القسيمة إلى السلة بنجاح |
| `MESSAGE.COUPON_APPLIED_SUCCESSFULLY` | Coupon applied successfully | تم تطبيق القسيمة بنجاح |
| `ERROR.INVALID_COUPON_CODE` | Invalid coupon code | رمز القسيمة غير صالح |
| `ERROR.COUPON_NOT_FOUND` | Coupon not found | القسيمة غير موجودة |

---

## Notes

- The `code` is auto-generated and read-only — it cannot be set via API
- The `slug` is auto-generated from the English translation of `name` and is read-only
- `discount_type` must be one of the `DiscountType` enum values: `fixed_rate` or `percentage`
- `max_discount_amount` is **required** when `discount_type=percentage`, ignored otherwise
- `start_date` and `end_date` use `Y-m-d` format — time component is not considered
- `image-desktop` and `image-mobile` are **required** on create, optional on update
- The `is_approve` field is used for vendor coupon moderation but is **commented out** in the migration — enabled only if the column exists
- Coupon verification (`POST /coupons/verify`) is commented out in the controller — use GraphQL `verifyCoupon` mutation instead

---

## Logic Review Findings

| Issue | Severity | Status |
|-------|----------|--------|
| `approveCoupon`/`disApproveCoupon` returned raw model instead of `apiResponse` | Medium | **Fixed** |
| `approveCoupon`/`disApproveCoupon` checked `Permission::SUPER_ADMIN` instead of role | Low | Not fixed (functionally works if permission exists) |
| Missing `order`/`sortedBy` query params in `fetchCoupons` | Low | **Fixed** |
| `is_approve` column migration is commented out | Low | Not fixed (column must be added manually if needed) |
| Unused imports (`Svg\Tag\Rect`, `JsonResponse`, `Collection`, etc.) | Low | Not fixed (cosmetic) |
| `verify()` method commented out | Low | Not fixed (GraphQL handles it) |
| `updateCoupon()` method commented out with legacy code | Low | Not fixed |
