# Sliders API

## Overview

The Sliders module manages promotional slider images displayed on the homepage. Each slider supports desktop/mobile images, translatable titles, sortable ordering, and product associations.

---

## Database Schema

### `sliders` Table

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | bigint | PK, AUTO_INCREMENT | Unique identifier |
| `title` | varchar(255) | NULLABLE | Translatable title |
| `slug` | varchar(255) | NOT NULL | Auto-generated from English title |
| `order` | int | NOT NULL | Sort order (via Spatie Sortable) |
| `status` | tinyint(1) | DEFAULT 0 | Active/inactive |
| `created_at` | timestamp | NULLABLE | Creation time |
| `updated_at` | timestamp | NULLABLE | Last update |
| `deleted_at` | timestamp | NULLABLE | Soft delete |

### `slider_product` Pivot Table

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | bigint | PK, AUTO_INCREMENT | Unique identifier |
| `slider_id` | bigint | FK → sliders.id, CASCADE | Slider reference |
| `product_id` | bigint | FK → products.id, CASCADE | Product reference |
| `created_at` | timestamp | NULLABLE | Creation time |
| `updated_at` | timestamp | NULLABLE | Last update |

**Unique constraint:** `(slider_id, product_id)`

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

### SliderResource

| Field | Type | Description |
|-------|------|-------------|
| `id` | int | Slider ID |
| `title` | string | Translated title |
| `slug` | string | URL slug |
| `status` | bool | Active status |
| `order` | int | Sort order |
| `image.desktop` | string | Desktop image URL |
| `image.mobile` | string | Mobile image URL |
| `products` | array|null | Associated products (only when loaded) |

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
    "title": {
        "en": "Summer Sale",
        "ar": "تخفيضات الصيف"
    },
    "slug": "summer-sale",
    "status": true,
    "order": 1,
    "image": {
        "desktop": "http://localhost:8000/public/storage/sliders/299/66ad3721-5f80-45f2-bd1d-8ffaab366a33.jpg",
        "mobile": "http://localhost:8000/public/storage/sliders/300/2d7d67ad-6840-453f-b898-7bc127989eb0.jpg"
    },
    "products": null
}
```

---

## Endpoints

### GET /sliders — List Sliders

**Purpose:** List all sliders with optional active filter and pagination.

**Method:** `GET`

**URL:** `/sliders`

**Authentication:** Required

**Permissions:** `view-slider`

**Query Parameters:**

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `page` | int | 1 | Page number |
| `per_page` | int | 15 | Results per page (alias: `limit`) |
| `limit` | int | 15 | Results per page (alias: `per_page`) |
| `active` | bool | false | Filter only active sliders |
| `order` | string | — | Field to sort by. Allowed: `id`, `title`, `slug`, `order`, `status`, `created_at`, `updated_at` |
| `sortedBy` | string | `asc` | Sort direction (`asc` or `desc`). Only applies when `order` is set. |

**Example Usage:**
```
GET /sliders?page=2&per_page=20              # Page 2, 20 per page
GET /sliders?page=1&limit=10                 # Page 1, 10 per page
GET /sliders?order=title&sortedBy=asc        # Alphabetical A-Z by title
GET /sliders?order=title&sortedBy=desc       # Alphabetical Z-A by title
GET /sliders?order=order&sortedBy=asc        # By display order (default)
GET /sliders?order=created_at&sortedBy=desc  # Newest first
GET /sliders?order=status&sortedBy=desc      # Active first
```

**Business Logic:**
1. If `?active=true`, applies `active()` scope (`where status = true`)
2. If `order` is a valid field, applies `orderBy($order, $sortedBy)`; otherwise uses Spatie `ordered()` scope
3. Paginates with given limit
4. Returns paginated `SliderResource` collection

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
                "status": true,
                "order": 1,
                "image": {
                    "desktop": "http://localhost:8000/public/storage/sliders/299/66ad3721-5f80-45f2-bd1d-8ffaab366a33.jpg",
                    "mobile": "http://localhost:8000/public/storage/sliders/300/2d7d67ad-6840-453f-b898-7bc127989eb0.jpg"
                }
            }
        ],
        "page": 1,
        "current_page": 1,
        "from": 1,
        "to": 15,
        "last_page": 1,
        "path": "https://api.example.com/sliders",
        "per_page": 15,
        "total": 1,
        "next_page_url": null,
        "prev_page_url": null,
        "last_page_url": "https://api.example.com/sliders?page=1",
        "first_page_url": "https://api.example.com/sliders?page=1"
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `view-slider` permission |

---

### POST /sliders — Create Slider

**Purpose:** Create a new slider with images and optional product associations.

**Method:** `POST`

**URL:** `/sliders`

**Authentication:** Required

**Permissions:** `create-slider`

**Request Body (multipart/form-data):**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `title` | object | No | `array`. Translatable object with `en` and `ar` keys |
| `title.en` | string | No | `string`, `sometimes`, unique translation (ignores self) |
| `title.ar` | string | No | `string`, `sometimes`, unique translation (ignores self) |
| `image_desktop` | file | No | `image`, `sometimes`, `mimes:jpeg,png,jpg,gif`, `max:2048` |
| `image_mobile` | file | No | `image`, `sometimes`, `mimes:jpeg,png,jpg,gif`, `max:2048` |
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
    "status": 1,
    "products": [2, 11, 14]
}
```

**Business Logic:**
1. Validates via `SliderCreateRequest`
2. Creates slider record with `title` (translatable), `slug` (auto-generated from English title), `order` (auto-set by Sortable), `status`
3. Uploads and attaches desktop/mobile images via Spatie MediaLibrary
4. If `products` array provided, syncs pivot relationships via `slider_product` table
5. Returns created slider with loaded products

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Slider created successfully",
    "success": true,
    "data": {
        "id": 2,
        "title": {
            "en": "New Promotion",
            "ar": "تخفيضات الصيف"
        },
        "slug": "new-promotion",
        "status": true,
        "order": 2,
        "image": {
            "desktop": "https://cdn.example.com/storage/sliders/299/66ad3721-5f80-45f2-bd1d-8ffaab366a33.jpg",
            "mobile": "https://cdn.example.com/storage/sliders/300/2d7d67ad-6840-453f-b898-7bc127989eb0.jpg"
        },
        "products": [
            {
                "id": 2,
                "name": "Romaine Lettuce",
                "slug": "romaine-lettuce-380Hw",
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
| 403 | Missing `create-slider` permission |
| 422 | Validation failure |
| 500 | Server error |

---

### GET /sliders/{id} — Show Slider

**Purpose:** Fetch a single slider by ID.

**Method:** `GET`

**URL:** `/sliders/{id}`

**Authentication:** Required

**Permissions:** `view-slider`

**Business Logic:**
1. Finds slider by ID
2. Loads associated products
3. Returns slider resource

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": {
        "id": 1,
        "title": {
            "en": "Summer Sale",
            "ar": "تخفيضات الصيف"
        },
        "slug": "summer-sale",
        "status": true,
        "order": 1,
        "image": {
            "desktop": "https://cdn.example.com/storage/sliders/299/66ad3721-5f80-45f2-bd1d-8ffaab366a33.jpg",
            "mobile": "https://cdn.example.com/storage/sliders/300/2d7d67ad-6840-453f-b898-7bc127989eb0.jpg"
        },
        "products": [
            {
                "id": 2,
                "name": "Romaine Lettuce",
                "slug": "romaine-lettuce-380Hw",
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
| 403 | Missing `view-slider` permission |
| 404 | Slider not found |

---

### PUT /sliders/{id} — Update Slider

**Purpose:** Update an existing slider's fields, images, and product associations.

**Method:** `PUT`

**URL:** `/sliders/{id}`

**Authentication:** Required

**Permissions:** `update-slider`

**Request Body (multipart/form-data):**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `title` | object | **Yes** | Translatable object with `en` and `ar` keys |
| `title.en` | string | **Yes** | `string`, unique translation (ignores self) |
| `title.ar` | string | **Yes** | `string`, unique translation (ignores self) |
| `image_desktop` | file | No | `image`, `mimes:jpeg,png,jpg,gif`, `max:2048` |
| `image_mobile` | file | No | `image`, `mimes:jpeg,png,jpg,gif`, `max:2048` |
| `status` | int | No | `in:0,1` |
| `products` | array | No | Array of product IDs |
| `products.*` | int | No | `exists:products,id` |

**Example Request:**
```json
{
    "title": {
        "en": "Updated Sale Title",
        "ar": "عنوان التخفيضات المحدث"
    },
    "status": 1,
    "products": [5, 21, 35]
}
```

**Business Logic:**
1. Validates via `SliderUpdateRequest`
2. Finds existing slider by ID
3. Updates slider record fields
4. If new desktop/mobile image provided, replaces existing media
5. If `products` array provided, syncs pivot relationships (replaces all existing)
6. Returns updated slider with loaded products

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Slider updated successfully",
    "success": true,
    "data": {
        "id": 1,
        "title": {
            "en": "Summer Sale",
            "ar": "تخفيضات الصيف"
        },
        "slug": "summer-sale",
        "status": true,
        "order": 1,
        "image": {
            "desktop": "https://cdn.example.com/storage/sliders/299/66ad3721-5f80-45f2-bd1d-8ffaab366a33.jpg",
            "mobile": "https://cdn.example.com/storage/sliders/300/2d7d67ad-6840-453f-b898-7bc127989eb0.jpg"
        },
        "products": [
            {
                "id": 2,
                "name": "Romaine Lettuce",
                "slug": "romaine-lettuce-380Hw",
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
| 403 | Missing `update-slider` permission |
| 404 | Slider not found |
| 422 | Validation failure |

---

### DELETE /sliders/{id} — Delete Slider

**Purpose:** Soft-delete a slider.

**Method:** `DELETE`

**URL:** `/sliders/{id}`

**Authentication:** Required

**Permissions:** `delete-slider`

**Business Logic:**
1. Finds slider by ID
2. Soft-deletes via `SoftDeletes` trait (`deleted_at` set)
3. Pivot records in `slider_product` are preserved

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Slider deleted successfully",
    "success": true
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `delete-slider` permission |
| 404 | Slider not found |

---

### PATCH /sliders/change-status — Toggle Slider Status

**Purpose:** Toggle a slider's active status.

**Method:** `PATCH`

**URL:** `/sliders/change-status`

**Authentication:** Required

**Permissions:** `update-slider`

**Request Body:**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `id` | int | **Yes** | `exists:sliders,id` |

**Example Request:**
```json
{
    "id": 1
}
```

**Business Logic:**
1. Finds slider by ID
2. Toggles `status` field (`true → false` or `false → true`)
3. Returns updated slider

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Slider status changed successfully",
    "success": true,
    "data": {
        "id": 1,
        "title": {
            "en": "Summer Sale",
            "ar": "تخفيضات الصيف"
        },
        "slug": "summer-sale",
        "status": false,
        "order": 1,
        "image": {
            "desktop": "https://cdn.example.com/storage/sliders/299/66ad3721-5f80-45f2-bd1d-8ffaab366a33.jpg",
            "mobile": "https://cdn.example.com/storage/sliders/300/2d7d67ad-6840-453f-b898-7bc127989eb0.jpg"
        },
        "products": [
            {
                "id": 2,
                "name": "Romaine Lettuce",
                "slug": "romaine-lettuce-380Hw",
                "status": 1,
                "image": {
                    "thumbnail": "https://cdn.example.com/storage/products/403/af61c53c-21e0-4077-b431-2ca302402830.avif"
                }
            }
        ]
    }
}

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `update-slider` permission |
| 422 | Validation failure (missing `id`) |
| 500 | Server error |

---

### PUT /sliders/reorder — Reorder Sliders

**Purpose:** Set a custom order for multiple sliders.

**Method:** `PUT`

**URL:** `/sliders/reorder`

**Authentication:** Required

**Permissions:** `update-slider`

**Request Body:**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `sliders` | array | **Yes** | Array of slider IDs |
| `sliders.*` | int | **Yes** | `exists:sliders,id` |

**Example Request:**
```json
{
    "sliders": [3, 1, 2]
}
```

**Business Logic:**
1. Validates slider IDs exist
2. Calls `setNewOrder()` (Spatie Sortable) to reorder by the given sequence
3. The `order` column is updated based on position in the array

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Sliders reordered successfully",
    "success": true
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `update-slider` permission |
| 422 | Validation failure (invalid `sliders` array) |
| 500 | Server error |

---

## Route Definitions

All routes are in `packages/marvel/src/Rest/Routes.php`, loaded via `RestAPIServiceProvider::loadRoutes()` with prefix `/api/v1` and `api` middleware group.

### Duplicate Route Registration

`GET /sliders` is registered **twice**:
1. Line 254 — `apiResource('sliders', SliderController::class, ['only' => ['index']])` — **no route middleware**
2. Line 494 — `Route::apiResource('sliders', SliderController::class)` — inside `auth:sanctum` + `verified` group

Laravel resolves the **first** registration (line 254). The `permission:view-slider` controller middleware (SliderController line 22) enforces authentication and authorization regardless of which route is matched. Behavior is identical in both cases. This is **Technical Debt** — a redundant registration, not a production bug.

| Method | URI | Controller | Action | Route Middleware | Controller Middleware | Permission | Source Line |
|--------|-----|------------|--------|-----------------|----------------------|------------|-------------|
| GET | `/sliders` | `SliderController` | `index` | None (resolves line 254; also at line 494 behind `auth:sanctum`, `email.verified`) | `permission:view-slider` | `view-slider` | Lines 254, 494 |
| GET | `/sliders/{slider}` | `SliderController` | `show` | `auth:sanctum`, `email.verified` | `permission:view-slider` | `view-slider` | Line 494 |
| POST | `/sliders` | `SliderController` | `store` | `auth:sanctum`, `email.verified` | `permission:create-slider` | `create-slider` | Line 494 |
| PUT | `/sliders/{slider}` | `SliderController` | `update` | `auth:sanctum`, `email.verified` | `permission:update-slider` | `update-slider` | Line 494 |
| DELETE | `/sliders/{slider}` | `SliderController` | `destroy` | `auth:sanctum`, `email.verified` | `permission:delete-slider` | `delete-slider` | Line 494 |
| PATCH | `/sliders/change-status` | `SliderController` | `changeStatus` | `auth:sanctum`, `email.verified` | `permission:update-slider` | `update-slider` | Line 491 |
| PUT | `/sliders/reorder` | `SliderController` | `reorder` | `auth:sanctum`, `email.verified` | `permission:update-slider` | `update-slider` | Line 492 |

---

## Permissions Map

| Permission Enum | String | Applied To |
|----------------|--------|------------|
| `VIEW_SLIDER` | `view-slider` | `index`, `show` |
| `CREATE_SLIDER` | `create-slider` | `store` |
| `UPDATE_SLIDER` | `update-slider` | `update`, `changeStatus`, `reorder` |
| `DELETE_SLIDER` | `delete-slider` | `destroy` |

---

## Media Library Collections

| Collection Name | Usage | Uploaded Via |
|----------------|-------|-------------|
| `slider-image-desktop` | Desktop image (create) | `createSlider` |
| `slider-image-mobile` | Mobile image (create) | `createSlider` |
| `sliders-desktop` | Desktop image (update) | `updateSlider` |
| `sliders-mobile` | Mobile image (update) | `updateSlider` |

---

## Model Features

- **Sortable:** Uses Spatie `SortableTrait` with `order` column; auto-sets order on create
- **Translatable:** `title` field is translatable via Spatie `HasTranslations`
- **SoftDeletes:** Records are soft-deleted
- **MediaLibrary:** Images managed via Spatie MediaLibrary
- **Relations:** `BelongsToMany` with `Product` via `slider_product` pivot

---

## Dependencies

| Class | Type | File |
|-------|------|------|
| `SliderController` | Controller | `packages/marvel/src/Http/Controllers/SliderController.php` |
| `SliderRepository` | Repository | `packages/marvel/src/Database/Repositories/SliderRepository.php` |
| `Slider` | Model | `packages/marvel/src/Database/Models/Slider.php` |
| `SliderResource` | Resource | `packages/marvel/src/Http/Resources/SliderResource.php` |
| `SliderCreateRequest` | Form Request | `packages/marvel/src/Http/Requests/SliderCreateRequest.php` |
| `SliderUpdateRequest` | Form Request | `packages/marvel/src/Http/Requests/SliderUpdateRequest.php` |
| `Permission` | Enum | `packages/marvel/src/Enums/Permission.php` |

---

## Notes

- Images are uploaded via `multipart/form-data` (not JSON)
- The `slug` is auto-generated from the English translation of `title` on `saving` event
- `products` sync replaces all existing pivot associations — send full desired list
- Slider ordering uses Spatie `eloquent-sortable`; the `order` column is managed automatically
- The `title` field returns a translated string on `GET /sliders` (index) and the full translation object on all other routes

---

## Regression Fixes

| Fix | Description | Status |
|-----|-------------|--------|
| Missing permission middleware on `changeStatus` and `reorder` | `UPDATE_SLIDER` permission middleware was not applied to these endpoints. Added `$this->middleware("permission:".Permission::UPDATE_SLIDER)->only(["update", "changeStatus", "reorder"])` in `SliderController` constructor. | **Fixed** |
| Missing English translations for slider messages | `MESSAGE.SLIDER_CREATED_SUCCESSFULLY`, `MESSAGE.SLIDER_UPDATED_SUCCESSFULLY`, `MESSAGE.SLIDER_DELETED_SUCCESSFULLY`, `MESSAGE.SLIDER_STATUS_CHANGED`, `MESSAGE.SLIDERS_REORDERED_SUCCESSFULLY` were missing in `resources/lang/en/message.php`. Added all five translations. | **Fixed** |
| `reorder` method caught `ValidationException` and returned 500 | Validation was inside a broad `try/catch(\Exception)` block causing 500 instead of 422. Moved `$request->validate()` outside the try-catch. | **Fixed** |
| Test `assertJsonIsString` used unavailable method | `assertJsonIsString` does not exist in this Laravel version. Replaced with `assertIsString()`. | **Fixed** |
| Test `test_create_slider_with_product_association` missing `title.ar` | The request validation requires both `title.en` and `title.ar`. Added `title.ar` to the test payload. | **Fixed** |

---

## Architecture Notes

- The Marvel `SliderController` handles all CRUD + `changeStatus` + `reorder` operations for admin users
- All admin routes are inside the `auth:sanctum` + `email.verified` group (Routes.php line 452); role-based access is NOT enforced at route level — controller-level permission middleware handles authorization
- Permission middleware is enforced at the controller level via Spatie `permission` middleware
- The `Get /sliders` route is registered twice (lines 254 and 494) — Laravel resolves the first; this is harmless technical debt
- The Application-layer classes (`app/Http/Controllers/Api/General/SliderController`, `app/Services/General/SliderService`, `app/Http/Resources/Slider/SliderResource`) have no registered routes and are **unused** by the current API
- Slider uses SoftDeletes — records are soft-deleted and excluded from queries automatically

---

## Testing Coverage

The test suite (`tests/Feature/SliderApiTest.php`) covers the following categories:

| Category | Covered |
|----------|---------|
| CRUD — List, Show, Create, Update, Delete | ✔ |
| Validation — Missing title, missing images, invalid input | ✔ |
| Authorization — User without permission gets 403 | ✔ |
| Authentication — Unauthenticated user gets 401 | ✔ |
| Pagination — `?per_page=` and page metadata | ✔ |
| Filtering — `?active=true` filter | ✔ |
| Status toggle — `changeStatus` active/inactive | ✔ |
| Reorder — `reorder` with valid/invalid IDs | ✔ |
| Response Structure — JSON shape assertions | ✔ |
| Translation serialization — title object on show, string on index | ✔ |
| Resource serialization — image, status, order fields | ✔ |
| Database persistence — soft delete, cascading | ✔ |
| Edge cases — 404 for nonexistent IDs, empty list | ✔ |
| Soft delete — deleted sliders not listed, 404 on show | ✔ |
| Product relations — create slider with product association | ✔ |

---

## Known Constraints

- Route-level middleware is `auth:sanctum` + `email.verified`; controller-level permission middleware (`view-slider`, `create-slider`, `update-slider`, `delete-slider`) handles authorization
- Slider images are replaced on update if a new file is provided; no partial image update is possible
- Product sync on update replaces all existing pivot associations — partial sync is not supported
- The `order` column is auto-managed by Spatie Sortable on create; manual order assignment on create is not supported
- As of the current implementation, Slider records do not support soft-delete restoration via the API
