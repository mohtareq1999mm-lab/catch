# Banners API

## Overview

The Banners module manages promotional banner images displayed on the homepage. Each banner supports desktop/mobile images, translatable title and description, sortable ordering, and product associations.

---

## Database Schema

### `banners` Table (Marvel migration: `2020_06_02_051901_create_marvel_tables.php`)

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | bigint | PK, AUTO_INCREMENT | Unique identifier |
| `title` | string | NOT NULL | Translatable title |
| `slug` | string | NOT NULL | Auto-generated from English title |
| `description` | text | NULLABLE | Translatable description |
| `order` | int | NOT NULL | Sort order (via Spatie Sortable) |
| `status` | tinyint(1) | DEFAULT 0 | Active/inactive |
| `created_at` | timestamp | NULLABLE | Creation time |
| `updated_at` | timestamp | NULLABLE | Last update |
| `deleted_at` | timestamp | NULLABLE | Soft delete |

### `banner_product` Pivot Table (Migration: `2026_06_23_000001_create_banner_product_table.php`)

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | bigint | PK, AUTO_INCREMENT | Unique identifier |
| `banner_id` | bigint | FK → banners.id, CASCADE | Banner reference |
| `product_id` | bigint | FK → products.id, CASCADE | Product reference |
| `created_at` | timestamp | NULLABLE | Creation time |
| `updated_at` | timestamp | NULLABLE | Last update |

**Unique constraint:** `(banner_id, product_id)`

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

### BannerResource (Marvel)

| Field | Type | Description |
|-------|------|-------------|
| `id` | int | Banner ID |
| `title` | string | Translated title (current locale) |
| `slug` | string | URL slug |
| `description` | string | Translated description (current locale) |
| `image.desktop` | string | Desktop image URL |
| `image.mobile` | string | Mobile image URL |
| `status` | bool | Active status |
| `products` | array|null | Associated products (only when loaded) |

**Example:**
```json
{
    "id": 1,
    "title": "Summer Sale",
    "slug": "summer-sale",
    "description": "Best deals this summer",
    "image": {
        "desktop": "http://localhost:8000/public/storage/banners/299/66ad3721-5f80-45f2-bd1d-8ffaab366a33.jpg",
        "mobile": "http://localhost:8000/public/storage/banners/300/2d7d67ad-6840-453f-b898-7bc127989eb0.jpg"
    },
    "status": true,
    "products": null
}
```

---

## Endpoints

### GET /banners — List Banners

**Purpose:** List all banners with product relations and sorting.

**Method:** `GET`

**URL:** `/banners`

**Authentication:** Required

**Permissions:** `view-banners`

**Query Parameters:**

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `page` | int | 1 | Page number |
| `limit` | int | 15 | Results per page |
| `active` | bool | false | Filter only active banners |

**Business Logic:**
1. Eager-loads `products` relation
2. If `?active=true`, applies `active()` scope (`where status = true`)
3. Orders by Spatie `ordered()` scope (by `order` column)
4. Paginates with given limit
5. Returns paginated `BannerResource` collection

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
                "title": "Summer Sale",
                "slug": "summer-sale",
                "description": "Best deals this summer",
                "image": {
                    "desktop": "http://localhost:8000/public/storage/banners/299/66ad3721-5f80-45f2-bd1d-8ffaab366a33.jpg",
                    "mobile": "http://localhost:8000/public/storage/banners/300/2d7d67ad-6840-453f-b898-7bc127989eb0.jpg"
                },
                "status": true
            }
        ],
        "page": 1,
        "current_page": 1,
        "from": 1,
        "to": 15,
        "last_page": 1,
        "path": "https://api.example.com/banners",
        "per_page": 15,
        "total": 1,
        "next_page_url": null,
        "prev_page_url": null,
        "last_page_url": "https://api.example.com/banners?page=1",
        "first_page_url": "https://api.example.com/banners?page=1"
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `view-banners` permission |

---

### POST /banners — Create Banner

**Purpose:** Create a new banner with images and optional product associations.

**Method:** `POST`

**URL:** `/banners`

**Authentication:** Required

**Permissions:** `create-banners`

**Request Body (multipart/form-data):**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `title` | object | **Yes** | Translatable array |
| `title.en` | string | **Yes** | `string`, `min:3`, `max:255`, unique translation |
| `title.ar` | string | **Yes** | `string`, `min:3`, `max:255`, unique translation |
| `description` | object | **Yes** | Translatable array |
| `description.en` | string | No | `nullable`, `string`, `min:5`, `max:500`, unique translation |
| `description.ar` | string | No | `nullable`, `string`, `min:5`, `max:500`, unique translation |
| `image_desktop` | file | **Yes** | `image`, `mimes:jpeg,png,jpg,gif`, `max:2048` |
| `image_mobile` | file | **Yes** | `image`, `mimes:jpeg,png,jpg,gif`, `max:2048` |
| `status` | int | No | `in:0,1` |
| `products` | array | No | Array of product IDs |
| `products.*` | int | No | `exists:products,id` |

**Example Request:**
```json
{
    "title": {
        "en": "Summer Sale",
        "ar": "تخفيضات الصيف"
    },
    "description": {
        "en": "Best deals this summer",
        "ar": "أفضل العروض هذا الصيف"
    },
    "status": 1,
    "products": [2, 11, 14]
}
```

**Business Logic:**
1. Validates via `BannerCreateRequest`
2. Creates banner record with `title` (translatable), `description` (translatable), `slug` (auto-generated from English title), `order` (auto-set by Sortable), `status`
3. Uploads and attaches desktop/mobile images via Spatie MediaLibrary
4. If `products` array provided, syncs pivot relationships via `banner_product` table
5. Returns created banner with loaded products

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Banner created successfully",
    "success": true,
    "data": {
        "id": 2,
        "title": "Summer Sale",
        "slug": "summer-sale",
        "description": "Best deals this summer",
        "image": {
            "desktop": "https://cdn.example.com/storage/banners/299/66ad3721-5f80-45f2-bd1d-8ffaab366a33.jpg",
            "mobile": "https://cdn.example.com/storage/banners/300/2d7d67ad-6840-453f-b898-7bc127989eb0.jpg"
        },
        "status": true,
        "products": [
            {
                "id": 2,
                "name": "Product Name",
                "slug": "product-name",
                "status": 1,
                "image": {
                    "thumbnail": "https://cdn.example.com/storage/products/403/af61c53c-21e0-4077-b431-2ca302402830.avif"
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
| 403 | Missing `create-banners` permission |
| 422 | Validation failure |

---

### GET /banners/{id} — Show Banner

**Purpose:** Fetch a single banner by ID.

**Method:** `GET`

**URL:** `/banners/{id}`

**Authentication:** Required

**Permissions:** `view-banners`

**Business Logic:**
1. Finds banner by ID
2. Returns banner resource (products are not loaded unless requested by the resource)

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": {
        "id": 1,
        "title": "Summer Sale",
        "slug": "summer-sale",
        "description": "Best deals this summer",
        "image": {
            "desktop": "https://cdn.example.com/storage/banners/299/66ad3721-5f80-45f2-bd1d-8ffaab366a33.jpg",
            "mobile": "https://cdn.example.com/storage/banners/300/2d7d67ad-6840-453f-b898-7bc127989eb0.jpg"
        },
        "status": true
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `view-banners` permission |
| 404 | Banner not found |

---

### PUT /banners/{id} — Update Banner

**Purpose:** Update an existing banner's fields, images, and product associations.

**Method:** `PUT`

**URL:** `/banners/{id}`

**Authentication:** Required

**Permissions:** `update-banners`

**Request Body (multipart/form-data):**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `title` | object | No | `array` |
| `title.en` | string | No | `sometimes`, `string`, `min:3`, `max:255`, unique translation (ignores self) |
| `title.ar` | string | No | `sometimes`, `string`, `min:3`, `max:255`, unique translation (ignores self) |
| `description` | object | No | `array` |
| `description.en` | string | No | `nullable`, `string`, `min:10`, `max:500`, unique translation (ignores self) |
| `description.ar` | string | No | `nullable`, `string`, `min:10`, `max:500`, unique translation (ignores self) |
| `image_desktop` | file | No | `image`, `sometimes`, `mimes:jpeg,png,jpg,gif`, `max:2048` |
| `image_mobile` | file | No | `image`, `sometimes`, `mimes:jpeg,png,jpg,gif`, `max:2048` |
| `status` | int | No | `in:0,1` |
| `products` | array | No | Array of product IDs |
| `products.*` | int | No | `exists:products,id` |

**Business Logic:**
1. Validates via `BannerUpdateRequest`
2. Finds existing banner by ID
3. Updates banner record fields
4. If new desktop/mobile image provided, replaces existing media via `updateSingleImage`
5. If `products` array provided, syncs pivot relationships (replaces all existing)
6. Returns updated banner with loaded products

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Banner updated successfully",
    "success": true,
    "data": {
        "id": 1,
        "title": "Updated Sale",
        "slug": "updated-sale",
        "description": "Updated description",
        "image": {
            "desktop": "https://cdn.example.com/storage/banners/299/66ad3721-5f80-45f2-bd1d-8ffaab366a33.jpg",
            "mobile": "https://cdn.example.com/storage/banners/300/2d7d67ad-6840-453f-b898-7bc127989eb0.jpg"
        },
        "status": true
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `update-banners` permission |
| 404 | Banner not found |
| 422 | Validation failure |

---

### DELETE /banners/{id} — Delete Banner

**Purpose:** Soft-delete a banner.

**Method:** `DELETE`

**URL:** `/banners/{id}`

**Authentication:** Required

**Permissions:** `delete-banners`

**Business Logic:**
1. Finds banner by ID
2. Soft-deletes via `SoftDeletes` trait (`deleted_at` set)
3. Pivot records in `banner_product` are preserved

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Banner deleted successfully",
    "success": true
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `delete-banners` permission |
| 404 | Banner not found |

---

### POST /banner/change-status — Toggle Banner Status

**Purpose:** Toggle a banner's active status.

**Method:** `POST`

**URL:** `/banner/change-status`

**Authentication:** Required

**Permissions:** `update-banners`

**Request Body:**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `id` | int | **Yes** | `exists:banners,id` |

**Example Request:**
```json
{
    "id": 1
}
```

**Business Logic:**
1. Validates banner ID exists
2. Toggles `status` field (`true → false` or `false → true`)
3. Returns updated banner

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Banner status changed successfully",
    "success": true,
    "data": {
        "id": 1,
        "title": "Summer Sale",
        "slug": "summer-sale",
        "description": "Best deals this summer",
        "image": {
            "desktop": "https://cdn.example.com/storage/banners/299/66ad3721-5f80-45f2-bd1d-8ffaab366a33.jpg",
            "mobile": "https://cdn.example.com/storage/banners/300/2d7d67ad-6840-453f-b898-7bc127989eb0.jpg"
        },
        "status": false
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `update-banners` permission |
| 422 | Validation failure (missing `id`) |

---

### POST /banner/reorder — Reorder Banners

**Purpose:** Set a custom order for multiple banners.

**Method:** `POST`

**URL:** `/banner/reorder`

**Authentication:** Required

**Permissions:** `update-banners`

**Request Body:**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `banners` | array | **Yes** | Array of banner IDs |
| `banners.*` | int | **Yes** | `exists:banners,id` |

**Example Request:**
```json
{
    "banners": [3, 1, 2]
}
```

**Business Logic:**
1. Validates banner IDs exist
2. Calls `setNewOrder()` (Spatie Sortable) to reorder by the given sequence
3. The `order` column is updated based on position in the array

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Banners reordered successfully",
    "success": true
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `update-banners` permission |
| 422 | Validation failure (invalid `banners` array) |
| 500 | Server error |

---

## Route Definitions

All routes are in `packages/marvel/src/Rest/Routes.php`, loaded via `RestAPIServiceProvider::loadRoutes()` with prefix `/api/v1` and `api` middleware group.

### Duplicate Route Registration

`GET /banners` and `GET /banners/{banner}` are registered **twice**:
1. Line 251 — `apiResource('banners', BannerController::class, ['only' => ['index', 'show']])` — **no route middleware**
2. Line 493 — `Route::apiResource('banners', BannerController::class)` — inside `auth:sanctum` + `verified` group

Laravel resolves the **first** registration (line 251). The `permission:view-banners` controller middleware (BannerController line 20) enforces authentication and authorization regardless of which route is matched. Behavior is identical in both cases. This is **Technical Debt** — a redundant registration, not a production bug.

| Method | URI | Controller | Action | Route Middleware | Controller Middleware | Permission | Source Line |
|--------|-----|------------|--------|-----------------|----------------------|------------|-------------|
| GET | `/banners` | `BannerController` | `index` | None (resolves line 251; also at line 493 behind `auth:sanctum`, `email.verified`) | `permission:view-banners` | `view-banners` | Lines 251, 493 |
| GET | `/banners/{banner}` | `BannerController` | `show` | None (resolves line 251; also at line 493 behind `auth:sanctum`, `email.verified`) | `permission:view-banners` | `view-banners` | Lines 251, 493 |
| POST | `/banners` | `BannerController` | `store` | `auth:sanctum`, `email.verified` | `permission:create-banners` | `create-banners` | Line 493 |
| PUT | `/banners/{banner}` | `BannerController` | `update` | `auth:sanctum`, `email.verified` | `permission:update-banners` | `update-banners` | Line 493 |
| DELETE | `/banners/{banner}` | `BannerController` | `destroy` | `auth:sanctum`, `email.verified` | `permission:delete-banners` | `delete-banners` | Line 493 |
| POST | `/banner/change-status` | `BannerController` | `changeStatus` | `auth:sanctum`, `email.verified` | `permission:update-banners` | `update-banners` | Line 489 |
| POST | `/banner/reorder` | `BannerController` | `reorder` | `auth:sanctum`, `email.verified` | `permission:update-banners` | `update-banners` | Line 490 |

---

## Permissions Map

| Permission Enum | String | Applied To |
|----------------|--------|------------|
| `VIEW_BANNERS` | `view-banners` | `index`, `show` |
| `CREATE_BANNERS` | `create-banners` | `store` |
| `UPDATE_BANNERS` | `update-banners` | `update`, `changeStatus`, `reorder` |
| `DELETE_BANNERS` | `delete-banners` | `destroy` |

---

## Media Library Collections

| Collection Name | Usage |
|----------------|-------|
| `banners-desktop` | Desktop image (create and update) |
| `banners-mobile` | Mobile image (create and update) |

---

## Model Features

- **Sortable:** Uses Spatie `SortableTrait` with `order` column; auto-sets order on create
- **Translatable:** `title` and `description` fields are translatable via Spatie `HasTranslations`
- **SoftDeletes:** Records are soft-deleted
- **MediaLibrary:** Images managed via Spatie MediaLibrary
- **Relations:** `BelongsToMany` with `Product` via `banner_product` pivot

---

## Dependencies

| Class | Type | File |
|-------|------|------|
| `BannerController` | Controller | `packages/marvel/src/Http/Controllers/BannerController.php` |
| `BannerRepository` | Repository | `packages/marvel/src/Database/Repositories/BannerRepository.php` |
| `Banner` | Model | `packages/marvel/src/Database/Models/Banner.php` |
| `BannerResource` | Resource | `packages/marvel/src/Http/Resources/BannerResource.php` |
| `BannerCreateRequest` | Form Request | `packages/marvel/src/Http/Requests/BannerCreateRequest.php` |
| `BannerUpdateRequest` | Form Request | `packages/marvel/src/Http/Requests/BannerUpdateRequest.php` |
| `Permission` | Enum | `packages/marvel/src/Enums/Permission.php` |

---

## Notes

- Images are uploaded via `multipart/form-data` (not JSON)
- The `slug` is auto-generated from the English translation of `title` on `saving` event
- `products` sync replaces all existing pivot associations — send full desired list
- Banner ordering uses Spatie `eloquent-sortable`; the `order` column is managed automatically
- `description.en` and `description.ar` validation requires min:10 characters on update, but min:5 on create

---

## Regression Fixes

| Fix | Description | Status |
|-----|-------------|--------|
| Missing permission middleware on `changeStatus` and `reorder` | `UPDATE_BANNERS` permission middleware was not applied to these endpoints. Added `$this->middleware("permission:".Permission::UPDATE_BANNERS)->only(["update", "changeStatus", "reorder"])` in `BannerController` constructor. | **Fixed** |
| `update()` leaked exception messages | `$e->getMessage()` was returned in the API response on error, exposing internal error details. Replaced with generic `COULD_NOT_UPDATE_THE_RESOURCE`. | **Fixed** |
| `reorder` method caught `ValidationException` and returned 500 | Validation was inside a broad `try/catch(\Exception)` block causing 500 instead of 422. Moved `$request->validate()` outside the try-catch. | **Fixed** |
| Missing English translations for banner messages | `MESSAGE.BANNER_CREATED_SUCCESSFULLY`, `MESSAGE.BANNER_UPDATED_SUCCESSFULLY`, `MESSAGE.BANNER_DELETED_SUCCESSFULLY`, `MESSAGE.BANNER_STATUS_CHANGED`, `MESSAGE.BANNERS_REORDERED_SUCCESSFULLY` were missing in `resources/lang/en/message.php`. Added all five translations. | **Fixed** |
| Marvel `BannerResource` missing `slug` field | The API response for banners did not include the `slug` field which is stored in the database and used for slug-based lookup. Added `'slug' => $this->slug`. | **Fixed** |

---

## Architecture Notes

- The Marvel `BannerController` handles all CRUD + `changeStatus` + `reorder` operations for admin users
- All admin routes are inside the `auth:sanctum` + `email.verified` group (Routes.php line 452); role-based access is NOT enforced at route level — controller-level permission middleware handles authorization
- Permission middleware is enforced at the controller level via Spatie `permission` middleware
- `GET /banners` and `GET /banners/{banner}` are registered twice (lines 251 and 493) — Laravel resolves the first; this is harmless technical debt
- The Application-layer classes (`app/Http/Controllers/Api/General/BannerController`, `app/Services/General/BannerService`, `app/Http/Resources/Banner/BannerResource`) have no registered routes and are **unused** by the current API
- Banner uses SoftDeletes — records are soft-deleted and excluded from queries automatically

---

## Testing Coverage

The test suite (`tests/Feature/BannerApiTest.php`) covers the following categories:

| Category | Covered |
|----------|---------|
| CRUD — List, Show, Create, Update, Delete | ✔ |
| Validation — Missing title, missing images | ✔ |
| Authorization — User without permission gets 403 for all endpoints | ✔ |
| Authentication — Unauthenticated user gets 401 for all endpoints | ✔ |
| Status toggle — `changeStatus` active/inactive | ✔ |
| Reorder — `reorder` with valid/invalid IDs | ✔ |
| Response Structure — JSON shape assertions | ✔ |
| Translation serialization — title translates per locale | ✔ |
| Resource serialization — image, status, slug fields | ✔ |
| Database persistence — soft delete, product association | ✔ |
| Edge cases — 404 for nonexistent IDs, empty list | ✔ |
| Soft delete — deleted banners not listed | ✔ |
| Product relations — create banner with product association | ✔ |
| Mass Assignment protection — blocked `id` field | ✔ |

---

## Known Constraints

- Route-level middleware is `auth:sanctum` + `email.verified`; controller-level permission middleware (`view-banners`, `create-banners`, `update-banners`, `delete-banners`) handles authorization
- Banner images are replaced on update if a new file is provided; no partial image update is possible
- Product sync on update replaces all existing pivot associations — partial sync is not supported
- The `order` column is auto-managed by Spatie Sortable on create; manual order assignment on create is not supported
- As of the current implementation, Banner records do not support soft-delete restoration via the API
- `changeStatus` and `reorder` use POST instead of PATCH/PUT for HTTP methods (legacy design, maintained for backward compatibility)
