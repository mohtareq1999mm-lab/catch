# Brands API

## Overview

The Brands module manages product brands with translatable names/details, desktop/mobile images, and product associations.

---

## Database Schema

### `brands` Table

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | bigint | PK, AUTO_INCREMENT | Unique identifier |
| `name` | text | NOT NULL | Translatable name (JSON) |
| `slug` | varchar(255) | NOT NULL | Auto-generated from English name |
| `details` | text | NULLABLE | Translatable description (JSON) |
| `status` | tinyint(1) | DEFAULT true | Active/inactive |
| `created_at` | timestamp | NULLABLE | Creation time |
| `updated_at` | timestamp | NULLABLE | Last update |
| `deleted_at` | timestamp | NULLABLE | Soft delete |

### `brand_product` Pivot Table

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | bigint | PK, AUTO_INCREMENT | Unique identifier |
| `brand_id` | bigint | FK → brands.id, CASCADE | Brand reference |
| `product_id` | bigint | FK → products.id, CASCADE | Product reference |

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

### BrandResource

| Field | Type | Description |
|-------|------|-------------|
| `id` | int | Brand ID |
| `name` | string | Translated name |
| `slug` | string | URL slug |
| `image.desktop` | string|null | Desktop image URL |
| `image.mobile` | string|null | Mobile image URL |
| `details` | string | Translated description |
| `status` | bool | Active status |
| `products` | array | Associated products (only when relation loaded) |

**Product object within `products`:**
| Field | Type | Description |
|-------|------|-------------|
| `id` | int | Product ID |
| `name` | string | Product name |
| `slug` | string | Product slug |
| `status` | bool | Product active status |
| `image.thumbnail` | string | Product thumbnail URL |

**Example:**
```json
{
    "id": 1,
    "name": "Nike",
    "slug": "nike",
    "image": {
        "desktop": "http://localhost:8000/public/storage/brands/1/desktop.jpg",
        "mobile": "http://localhost:8000/public/storage/brands/1/mobile.jpg"
    },
    "details": "Sportswear brand",
    "status": true,
    "products": null
}
```

---

## Endpoints

### GET /brands — List Brands

**Purpose:** List all brands with optional filtering, sorting, and pagination.

**Method:** `GET`

**URL:** `/brands`

**Authentication:** Required

**Permissions:** `view-brands`

**Query Parameters:**

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `page` | int | 1 | Page number |
| `per_page` | int | 15 | Results per page (alias: `limit`) |
| `limit` | int | 15 | Results per page (alias: `per_page`) |
| `active` | bool | — | Filter active brands |
| `inactive` | bool | — | Filter inactive brands |
| `search` | string | — | Search by name (translatable) |
| `order` | string | — | Field to sort by. Allowed: `id`, `name`, `slug`, `status`, `created_at`, `updated_at` |
| `sortedBy` | string | `asc` | Sort direction (`asc` or `desc`). Only applies when `order` is set. |

**Example Usage:**
```
GET /brands?page=2&per_page=20          # Page 2, 20 per page
GET /brands?order=name&sortedBy=asc     # Alphabetical A-Z
GET /brands?order=name&sortedBy=desc    # Alphabetical Z-A
GET /brands?search=nike                 # Search by name
GET /brands?active=true                 # Only active brands
```

**Business Logic:**
1. Applies optional filters (active, inactive, search)
2. If `order` is a valid field, applies `orderBy($order, $sortedBy)`
3. Paginates with given limit
4. Returns paginated `BrandResource` collection

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
                "name": "Nike",
                "slug": "nike",
                "image": {
                    "desktop": "http://localhost:8000/public/storage/brands/1/desktop.jpg",
                    "mobile": "http://localhost:8000/public/storage/brands/1/mobile.jpg"
                },
                "details": "Sportswear brand",
                "status": true
            }
        ],
        "page": 1,
        "current_page": 1,
        "from": 1,
        "to": 15,
        "last_page": 1,
        "path": "https://api.example.com/brands",
        "per_page": 15,
        "total": 1,
        "next_page_url": null,
        "prev_page_url": null,
        "last_page_url": "https://api.example.com/brands?page=1",
        "first_page_url": "https://api.example.com/brands?page=1"
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `view-brands` permission |

---

### POST /brands — Create Brand

**Purpose:** Create a new brand with images and optional product associations.

**Method:** `POST`

**URL:** `/brands`

**Authentication:** Required

**Permissions:** `create-brand`

**Request Body (multipart/form-data):**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `name` | object | **Yes** | Translatable array |
| `name.*` | string | **Yes** | `string`, unique translation |
| `image-desktop` | file | **Yes** | `mimes:jpeg,png,jpg,gif,svg`, `max:2048` |
| `image-mobile` | file | **Yes** | `mimes:jpeg,png,jpg,gif,svg`, `max:2048` |
| `details` | object | No | Translatable array |
| `details.*` | string | No | `string`, `min:3`, `max:2500` |
| `status` | int | No | `in:1,0` |
| `products` | array | No | Array of product IDs |
| `products.*` | int | No | `exists:products,id` |

**Example Request:**
```json
{
    "name": {
        "en": "Nike",
        "ar": "نايك"
    },
    "details": {
        "en": "Sportswear brand",
        "ar": "ماركة ملابس رياضية"
    },
    "status": 1,
    "products": [2, 11, 14]
}
```

**Business Logic:**
1. Validates via `BrandCreateRequest`
2. Generates slug from English name
3. Creates brand record
4. Uploads desktop/mobile images
5. Syncs product associations if provided
6. Returns created brand with loaded products

**Success Response (201):**
```json
{
    "status": 201,
    "message": "Brand created successfully",
    "success": true,
    "data": {
        "id": 5,
        "name": "Nike",
        "slug": "nike",
        "image": {
            "desktop": "http://localhost:8000/public/storage/brands/5/desktop.jpg",
            "mobile": "http://localhost:8000/public/storage/brands/5/mobile.jpg"
        },
        "details": "Sportswear brand",
        "status": true,
        "products": [
            {
                "id": 2,
                "name": "Romaine Lettuce",
                "slug": "romaine-lettuce-380Hw",
                "status": 1,
                "image": {
                    "thumbnail": "http://localhost:8000/public/storage/products/403/thumbnail.jpg"
                }
            }
        ]
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `create-brand` permission |
| 422 | Validation failure |
| 500 | Server error |

---

### GET /brands/{param} — Show Brand

**Purpose:** Fetch a single brand by ID or slug with associated products.

**Method:** `GET`

**URL:** `/brands/{param}` — accepts numeric ID or string slug

**Authentication:** Required

**Permissions:** `view-brands`

**Business Logic:**
1. If `{param}` is numeric — looks up by `id`
2. If `{param}` is a string — looks up by `slug`
3. Eager loads `products` relation
4. Returns brand resource

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": {
        "id": 1,
        "name": "Nike",
        "slug": "nike",
        "image": {
            "desktop": "http://localhost:8000/public/storage/brands/1/desktop.jpg",
            "mobile": "http://localhost:8000/public/storage/brands/1/mobile.jpg"
        },
        "details": "Sportswear brand",
        "status": true,
        "products": [
            {
                "id": 2,
                "name": "Romaine Lettuce",
                "slug": "romaine-lettuce-380Hw",
                "status": 1,
                "image": {
                    "thumbnail": "http://localhost:8000/public/storage/products/403/thumbnail.jpg"
                }
            }
        ]
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `view-brands` permission |
| 404 | Brand not found |

---

### PUT /brands/{id} — Update Brand

**Purpose:** Update an existing brand's fields, images, and product associations.

**Method:** `PUT`

**URL:** `/brands/{id}`

**Authentication:** Required

**Permissions:** `update-brand`

**Request Body (multipart/form-data):**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `name` | object | No | Translatable array |
| `name.*` | string | No | `string`, unique translation (ignores self) |
| `image-desktop` | file | No | `mimes:jpeg,png,jpg,gif,svg`, `max:2048` |
| `image-mobile` | file | No | `mimes:jpeg,png,jpg,gif,svg`, `max:2048` |
| `details` | object | No | Translatable array |
| `details.*` | string | No | `string`, `min:3`, `max:2500` |
| `status` | int | No | `in:1,0` |
| `products` | array | No | Array of product IDs |
| `products.*` | int | No | `exists:products,id` |

**Example Request:**
```json
{
    "name": {
        "en": "Adidas",
        "ar": "أديداس"
    },
    "status": 1,
    "products": [5, 21, 35]
}
```

**Business Logic:**
1. Validates via `BrandUpdateRequest`
2. Updates brand fields
3. Updates images if provided
4. Syncs product associations
5. Returns updated brand with loaded products

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Brand updated successfully",
    "success": true,
    "data": {
        "id": 1,
        "name": "Adidas",
        "slug": "adidas",
        "image": {
            "desktop": "http://localhost:8000/public/storage/brands/1/desktop.jpg",
            "mobile": "http://localhost:8000/public/storage/brands/1/mobile.jpg"
        },
        "details": "Sportswear brand",
        "status": true,
        "products": [
            {
                "id": 5,
                "name": "Running Shoes",
                "slug": "running-shoes",
                "status": 1,
                "image": {
                    "thumbnail": "http://localhost:8000/public/storage/products/403/thumbnail.jpg"
                }
            }
        ]
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `update-brand` permission |
| 404 | Brand not found |
| 422 | Validation failure |

---

### DELETE /brands/{id} — Delete Brand

**Purpose:** Delete a brand.

**Method:** `DELETE`

**URL:** `/brands/{id}`

**Authentication:** Required

**Permissions:** `delete-brand`

**Business Logic:**
1. Finds brand by ID
2. Deletes record

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Brand deleted successfully",
    "success": true
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `delete-brand` permission |
| 404 | Brand not found |

---

### PUT /brands/reorder — Reorder Brands

**Purpose:** Set a custom order for multiple brands using Sortable.

**Method:** `PUT`

**URL:** `/brands/reorder`

**Authentication:** Required

**Permissions:** `update-brand`

**Request Body:**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `brands` | array | **Yes** | Array of brand IDs |
| `brands.*` | int | **Yes** | `exists:brands,id` |

**Example Request:**
```json
{
    "brands": [3, 1, 2]
}
```

**Business Logic:**
1. Validates brand IDs exist
2. Calls `setNewOrder()` (Spatie Sortable) to reorder by the given sequence
3. The `order` column is updated based on position in the array

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Brands reordered successfully",
    "success": true
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `update-brand` permission |
| 422 | Validation failure (invalid `brands` array) |
| 500 | Server error |

---

## Route Definitions

All routes are in `packages/marvel/src/Rest/Routes.php`, loaded via `RestAPIServiceProvider::loadRoutes()` with prefix `/api/v1` and `api` middleware group.

### Duplicate Route Registration

`GET /brands` and `GET /brands/{brand}` are registered **twice**:
1. Line 190 — `apiResource('brands', BrandController::class, ['only' => ['index', 'show']])` — **no route middleware**
2. Line 644 — `Route::apiResource('brands', BrandController::class)` — inside `auth:sanctum` + `verified` group

Laravel resolves the **first** registration (line 190). Authorization is enforced by the **controller middleware** `permission:view-brands` on both `index` and `show` (lines 23-24 of `BrandController.php`), which blocks unauthenticated (401) and unauthorized (403) requests. The duplicate registration produces identical behavior in both cases.

**Classification:** Technical Debt — redundant registration, not a production bug.

### Actual Route Table

| Method | URI | Registered At | Route Middleware | Controller Middleware |
|--------|-----|---------------|-----------------|----------------------|
| GET | `/brands` | Lines 190, 644 | None (line 190 resolves first) | `permission:view-brands` |
| GET | `/brands/{brand}` | Lines 190, 644 | None (line 190 resolves first) | `permission:view-brands` |
| POST | `/brands` | Line 644 | `auth:sanctum`, `verified` | `permission:create-brand` |
| PUT | `/brands/{brand}` | Line 644 | `auth:sanctum`, `verified` | `permission:update-brand` |
| DELETE | `/brands/{brand}` | Line 644 | `auth:sanctum`, `verified` | `permission:delete-brand` |
| PUT | `/brands/reorder` | Line 643 | `auth:sanctum`, `verified` | `permission:update-brand` |

---

## Permissions Map

| Permission Enum | String | Applied To |
|----------------|--------|------------|
| `VIEW_BRANDS` | `view-brands` | `index`, `show` |
| `CREATE_BRAND` | `create-brand` | `store` |
| `UPDATE_BRAND` | `update-brand` | `update`, `reorder` |
| `DELETE_BRAND` | `delete-brand` | `destroy` |

---

## Media Library Collections

| Collection Name | Usage | Uploaded Via |
|----------------|-------|-------------|
| `brands-desktop` | Desktop image | `saveBrand` / `updateBrand` |
| `brands-mobile` | Mobile image | `saveBrand` / `updateBrand` |

---

## Model Features

- **Translatable:** `name` and `details` fields (Spatie `HasTranslations`)
- **Sortable:** Uses Spatie `SortableTrait` with `order` column; auto-sets order on create
- **MediaLibrary:** Images managed via Spatie MediaLibrary
- **Slug:** Auto-generated from English name on `saving` event
- **Relations:**
  - `BelongsToMany` with `Product` via `brand_product` pivot

---

## Dependencies

| Class | Type | File |
|-------|------|------|
| `BrandController` (Marvel) | Controller | `packages/marvel/src/Http/Controllers/BrandController.php` |
| `BrandRepository` | Repository | `packages/marvel/src/Database/Repositories/BrandRepository.php` |
| `Brand` | Model | `packages/marvel/src/Database/Models/Brand.php` |
| `BrandResource` (Marvel) | Resource | `packages/marvel/src/Http/Resources/BrandResource.php` |
| `BrandResource` (App) | Resource | `app/Http/Resources/Brand/BrandResource.php` |
| `BrandCreateRequest` | Form Request | `packages/marvel/src/Http/Requests/BrandCreateRequest.php` |
| `BrandUpdateRequest` | Form Request | `packages/marvel/src/Http/Requests/BrandUpdateRequest.php` |
| `BrandService` | Service | `app/Services/General/BrandService.php` |
| `BrandObserver` | Observer | `app/Observers/BrandObserver.php` |
| `BrandSeeder` | Seeder | `database/seeders/BrandSeeder.php` |
| `BrandProductSeeder` | Seeder | `database/seeders/BrandProductSeeder.php` |
| `HasChannelFilter` | Trait | `app/Traits/HasChannelFilter.php` |
| `Permission` | Enum | `packages/marvel/src/Enums/Permission.php` |

---

## Notes

- Images are uploaded via `multipart/form-data` (not JSON)
- The `slug` is auto-generated from the English translation of `name` on `saving` event
- `products` sync replaces all existing pivot associations — send full desired list
