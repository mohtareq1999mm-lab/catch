# Categories API

## Overview

The Categories module manages hierarchical product categories with parent/child relationships. Each category supports translatable names/details, desktop/mobile images, product associations, and shop associations.

---

## Database Schema

### `categories` Table

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | bigint | PK, AUTO_INCREMENT | Unique identifier |
| `name` | varchar(255) | NOT NULL, UNIQUE | Translatable name (stored as JSON via Spatie HasTranslations) |
| `slug` | varchar(255) | NOT NULL | Auto-generated from English name |
| `details` | text | NULLABLE | Translatable description |
| `parent_id` | bigint | FK → categories.id, RESTRICT ON DELETE | Parent category |
| `is_featured` | tinyint(1) | DEFAULT false | Featured flag (toggle via categories/feature endpoint) |
| `status` | tinyint(1) | DEFAULT true | Active/inactive |
| `created_at` | timestamp | NULLABLE | Creation time |
| `updated_at` | timestamp | NULLABLE | Last update |
| `deleted_at` | timestamp | NULLABLE | Soft delete |

### `category_product` Pivot Table

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | bigint | PK, AUTO_INCREMENT | Unique identifier |
| `product_id` | bigint | FK → products.id, CASCADE | Product reference |
| `category_id` | bigint | FK → categories.id, CASCADE | Category reference |

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

### CategoryResource

| Field | Type | Description |
|-------|------|-------------|
| `id` | int | Category ID |
| `name` | string | Translated name |
| `slug` | string | URL slug |
| `parent_id` | int|null | Parent category ID |
| `level` | int | Hierarchy level (0 = root) |
| `details` | string | Translated description (absent if empty) |
| `is_featured` | bool | Featured flag |
| `image.desktop` | string|null | Desktop image URL |
| `image.mobile` | string|null | Mobile image URL |
| `products_count` | int | Count of associated products |
| `children` | array | Child categories (only when relation loaded + non-empty) |
| `products` | array | Associated products (only when relation loaded) |

**Product object within `products`:**
| Field | Type | Description |
|-------|------|-------------|
| `id` | int | Product ID |
| `name` | string | Product name |
| `slug` | string | Product slug |
| `status` | bool | Product active status |
| `image.thumbnail` | string | Product thumbnail URL |

**Children object:**
| Field | Type | Description |
|-------|------|-------------|
| `id` | int | Child category ID |
| `name` | string | Translated name |
| `slug` | string | URL slug |

---

## Endpoints

### GET /categories — List Categories

**Purpose:** List all categories with optional filtering and pagination.

**Method:** `GET`

**URL:** `/categories`

**Authentication:** Required (admin)

**Permissions:** `view-categories`

**Query Parameters:**

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `page` | int | 1 | Page number |
| `per_page` | int | 15 | Results per page (alias: `limit`) |
| `limit` | int | 15 | Results per page (alias: `per_page`) |
| `parent` | bool | — | Filter only root categories (parent_id IS NULL) |
| `exceptSelf` | int | — | Exclude category by ID |
| `active` | bool | — | Filter active categories |
| `inactive` | bool | — | Filter inactive categories |
| `search` | string | — | Search by name (translatable) |
| `feature-category` | bool | false | When true, orders by `products_count` descending (top categories first) |
| `order` | string | — | Field to sort by. Allowed: `id`, `name`, `slug`, `products_count`, `created_at`, `updated_at`, `level` |
| `sortedBy` | string | `asc` | Sort direction (`asc` or `desc`). Only applies when `order` is set. |

**Example Usage:**
```
GET /categories?page=2&per_page=20               # Page 2, 20 per page
GET /categories?page=1&limit=10                  # Page 1, 10 per page
GET /categories?order=name&sortedBy=asc          # Alphabetical A-Z
GET /categories?order=name&sortedBy=desc         # Alphabetical Z-A
GET /categories?order=products_count&sortedBy=desc # Most products first
GET /categories?order=created_at&sortedBy=desc    # Newest first
GET /categories?order=id&sortedBy=asc             # Oldest first
```

**Business Logic:**
1. Builds query with `products_count`
2. If `feature-category=true`, applies `orderByDesc('products_count')`
3. If `order` is a valid field, applies `orderBy($order, $sortedBy)`
4. Applies optional filters (parent, self-exclude, active, inactive, search)
5. Paginates with given limit
6. Returns paginated `CategoryResource` collection

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
                "name": "Fruits & Vegetables",
                "slug": "fruits-vegetables",
                "parent_id": null,
                "level": 0,
                "image": {
                    "desktop": "https://cdn.example.com/storage/categories/1/desktop.jpg",
                    "mobile": "https://cdn.example.com/storage/categories/1/mobile.jpg"
                },
                "products_count": 45,
                "children": [
                    {
                        "id": 2,
                        "name": "Fresh Fruits",
                        "slug": "fresh-fruits"
                    }
                ],
                "products": null
            }
        ],
        "page": 1,
        "current_page": 1,
        "from": 1,
        "to": 15,
        "last_page": 1,
        "path": "https://api.example.com/categories",
        "per_page": 15,
        "total": 1,
        "next_page_url": null,
        "prev_page_url": null,
        "last_page_url": "https://api.example.com/categories?page=1",
        "first_page_url": "https://api.example.com/categories?page=1"
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `view-categories` permission |

---

### POST /categories — Create Category

**Purpose:** Create a new category with optional images, shops, and product associations.

**Method:** `POST`

**URL:** `/categories`

**Authentication:** Required

**Permissions:** `create-category`

**Request Body (multipart/form-data):**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `name` | object | **Yes** | Translatable array |
| `name.*` | string | **Yes** | `string`, unique translation |
| `parent_id` | int | No | `integer`, `exists:categories,id` |
| `details` | string | No | `string`, `min:3`, `max:2500` |
| `image-desktop` | file | **Yes** | `mimes:jpeg,png,jpg,gif,svg`, `max:2048` |
| `image-mobile` | file | **Yes** | `mimes:jpeg,png,jpg,gif,svg`, `max:2048` |
| `products` | array | No | Array of product IDs |
| `products.*` | int | No | `exists:products,id` |

**Example Request (multipart/form-data):**
```json
{
    "name": {
        "en": "Fresh Fruits",
        "ar": "فواكه طازجة"
    },
    "parent_id": 1,
    "details": "Fresh seasonal fruits",
    "products": [2, 11, 14]
}
```
Images are sent as file fields (`image-desktop`, `image-mobile`) in the multipart form-data.

**Business Logic:**
1. Validates via `CategoryCreateRequest`
2. Generates slug from English name
3. Creates category with hierarchy level (auto-set by `CategoryHierarchyService`)
4. Uploads desktop/mobile images if provided
5. Syncs product associations if provided
6. Returns created category with loaded products

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Category created successfully",
    "success": true,
    "data": {
        "id": 5,
        "name": "Fresh Fruits",
        "slug": "fresh-fruits",
        "parent_id": 1,
        "level": 1,
        "image": {
            "desktop": "https://cdn.example.com/storage/categories/5/desktop.jpg",
            "mobile": "https://cdn.example.com/storage/categories/5/mobile.jpg"
        },
        "products_count": 3,
        "products": [
            {
                "id": 2,
                "name": "Romaine Lettuce",
                "slug": "romaine-lettuce-380Hw",
                "status": 1,
                "image": {
                    "thumbnail": "https://cdn.example.com/storage/products/403/thumbnail.jpg"
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
| 403 | Missing `create-category` permission |
| 422 | Validation failure |
| 500 | Server error |

---

### GET /categories/{id} — Show Category

**Purpose:** Fetch a single category by ID with parent, children, and products.

**Method:** `GET`

**URL:** `/categories/{id}`

**Authentication:** Required

**Permissions:** `view-categories`

**Business Logic:**
1. Finds category by ID with `parent`, `products` relations loaded
2. Loads direct children via `CategoryHierarchyService`
3. Returns category resource

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": {
        "id": 1,
        "name": "Fruits & Vegetables",
        "slug": "fruits-vegetables",
        "parent_id": null,
        "level": 0,
        "image": {
            "desktop": "https://cdn.example.com/storage/categories/1/desktop.jpg",
            "mobile": "https://cdn.example.com/storage/categories/1/mobile.jpg"
        },
        "products_count": 45,
        "children": [
            {
                "id": 2,
                "name": "Fresh Fruits",
                "slug": "fresh-fruits"
            }
        ],
        "products": [
            {
                "id": 2,
                "name": "Romaine Lettuce",
                "slug": "romaine-lettuce-380Hw",
                "status": 1,
                "image": {
                    "thumbnail": "https://cdn.example.com/storage/products/403/thumbnail.jpg"
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
| 403 | Missing `view-categories` permission |
| 404 | Category not found |

---

### PUT /categories/{id} — Update Category

**Purpose:** Update an existing category's fields, images, shop associations, and product associations.

**Method:** `PUT`

**URL:** `/categories/{id}`

**Authentication:** Required

**Permissions:** `update-category`

**Request Body (multipart/form-data):**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `name` | object | No | Translatable array |
| `name.*` | string | No | `string`, unique translation (ignores self) |
| `parent_id` | int | No | `integer`, `exists:categories,id`, no circular ref |
| `details` | string | No | `string`, `min:3`, `max:2500` |
| `image-desktop` | file | No | `image`, `mimes:jpeg,png,jpg,gif,svg`, `max:2048` |
| `image-mobile` | file | No | `image`, `mimes:jpeg,png,jpg,gif,svg`, `max:2048` |
| `products` | array | No | Array of product IDs |
| `products.*` | int | No | `exists:products,id` |

**Example Request:**
```json
{
    "name": {
        "en": "Organic Fruits",
        "ar": "فواكه عضوية"
    },
    "parent_id": 1,
    "products": [2, 11, 14, 21]
}
```

**Business Logic:**
1. Validates via `CategoryUpdateRequest` (includes circular reference check for parent_id)
2. Updates category fields
3. Updates images if provided
4. Syncs shop associations
5. Syncs product associations
6. Returns updated category with loaded products

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Category updated successfully",
    "success": true,
    "data": {
        "id": 5,
        "name": "Organic Fruits",
        "slug": "organic-fruits",
        "parent_id": 1,
        "level": 1,
        "image": {
            "desktop": "https://cdn.example.com/storage/categories/5/desktop.jpg",
            "mobile": "https://cdn.example.com/storage/categories/5/mobile.jpg"
        },
        "products_count": 4,
        "products": [
            {
                "id": 2,
                "name": "Romaine Lettuce",
                "slug": "romaine-lettuce-380Hw",
                "status": 1,
                "image": {
                    "thumbnail": "https://cdn.example.com/storage/products/403/thumbnail.jpg"
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
| 403 | Missing `update-category` permission |
| 404 | Category not found |
| 422 | Validation failure |

---

### DELETE /categories/{id} — Delete Category

**Purpose:** Soft-delete a category.

**Method:** `DELETE`

**URL:** `/categories/{id}`

**Authentication:** Required

**Permissions:** `delete-category`

**Business Logic:**
1. Finds category by ID
2. Soft-deletes via `SoftDeletes` trait
3. Catches `QueryException` and throws `CANNOT_DELETE_CATEGORY_WITH_ASSOCIATED_RESOURCES` if category has associated resources (children, products, shops)

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Category deleted successfully",
    "success": true
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `delete-category` permission |
| 404 | Category not found |
| 409 | Cannot delete category with existing associated resources |

---

### GET /featured-categories — Featured Categories

**Purpose:** Fetch top categories by product count with their products.

**Method:** `GET`

**URL:** `/featured-categories`

**Authentication:** None

**Permissions:** None

**Query Parameters:**

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `limit` | int | 3 | Number of featured categories |

**Business Logic:**
1. Queries categories with `products` relation and `products_count`
2. Orders by `products_count` descending
3. Limits to given count
4. Returns collection with loaded products

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": [
        {
            "id": 1,
            "name": "Fruits & Vegetables",
            "slug": "fruits-vegetables",
            "parent_id": null,
            "level": 0,
            "image": {
                "desktop": "https://cdn.example.com/storage/categories/1/desktop.jpg",
                "mobile": "https://cdn.example.com/storage/categories/1/mobile.jpg"
            },
            "products_count": 45,
            "products": [
                {
                    "id": 2,
                    "name": "Romaine Lettuce",
                    "slug": "romaine-lettuce-380Hw",
                    "status": 1,
                    "image": {
                        "thumbnail": "https://cdn.example.com/storage/products/403/thumbnail.jpg"
                    }
                }
            ]
        }
    ]
}
```

---

### PUT /categories/feature — Toggle Featured Category

**Purpose:** Toggle the `is_featured` flag on a single category. Flips `NOT is_featured` atomically.

**Method:** `PUT`

**URL:** `/categories/feature`

**Authentication:** Required

**Permissions:** `update-category`

**Request Body (JSON):**

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `id` | int | **Yes** | `integer`, `exists:categories,id` |

**Example Request:**
```json
{
    "id": 5
}
```

**Business Logic:**
1. Validates single `id` with `exists:categories,id` constraint
2. Finds the category, flips `is_featured` to `! is_featured`, saves
3. Returns success message

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Category feature toggled successfully",
    "success": true
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Missing `update-category` permission |
| 422 | Validation failure (invalid IDs or non-array input) |

---

## Route Definitions

```php
// Public routes
Route::apiResource('categories', CategoryController::class, ['only' => ['index', 'show']]);
Route::get('featured-categories', 'Marvel\Http\Controllers\CategoryController@fetchFeaturedCategories');

// Admin routes (auth + permissions)
Route::put('categories/feature', [CategoryController::class, 'addOrRemoveCategoryFromFeature']);
Route::apiResource('categories', CategoryController::class);
```

Source: `packages/marvel/src/Rest/Routes.php`

---

## Permissions Map

| Permission Enum | String | Applied To |
|----------------|--------|------------|
| `VIEW_CATEGORIES` | `view-categories` | `index`, `show` |
| `CREATE_CATEGORY` | `create-category` | `store` |
| `UPDATE_CATEGORY` | `update-category` | `update`, `addOrRemoveCategoryFromFeature` |
| `DELETE_CATEGORY` | `delete-category` | `destroy` |

---

## Model Features

- **Translatable:** `name` and `details` fields (Spatie `HasTranslations`)
- **SoftDeletes:** Records are soft-deleted
- **MediaLibrary:** Images managed via Spatie MediaLibrary
- **Hierarchy:** Parent/child relationships with auto-managed `level` via `CategoryHierarchyService`
- **Slug:** Auto-generated from English name on `saving` event; handles legacy JSON-encoded slugs on `retrieved`
- **Relations:**
  - `BelongsToMany` with `Product` via `category_product` pivot
  - `BelongsToMany` with `Shop` via `category_shop` pivot
  - `HasMany` children (self-referential)
  - `BelongsTo` parent (self-referential)

---

## Dependencies

| Class | Type | File |
|-------|------|------|
| `CategoryController` | Controller | `packages/marvel/src/Http/Controllers/CategoryController.php` |
| `CategoryRepository` | Repository | `packages/marvel/src/Database/Repositories/CategoryRepository.php` |
| `Category` | Model | `packages/marvel/src/Database/Models/Category.php` |
| `CategoryResource` | Resource | `packages/marvel/src/Http/Resources/CategoryResource.php` |
| `CategoryCollection` | Resource Collection | `packages/marvel/src/Http/Resources/CategoryCollection.php` |
| `ChildrenCategoryResource` | Resource | `packages/marvel/src/Http/Resources/ChildrenCategoryResource.php` |
| `CategoryCreateRequest` | Form Request | `packages/marvel/src/Http/Requests/CategoryCreateRequest.php` |
| `CategoryUpdateRequest` | Form Request | `packages/marvel/src/Http/Requests/CategoryUpdateRequest.php` |
| `CategoryHierarchyService` | Service | `app/Services/General/CategoryHierarchyService.php` |
| `Permission` | Enum | `packages/marvel/src/Enums/Permission.php` |

---

## Notes

- The `parent_id` column has `restrictOnDelete` — categories with children cannot be deleted
- Categories use a **hierarchy service** (`CategoryHierarchyService`) that auto-manages the `level` field based on parent depth
- The `name` and `details` fields are translatable — send as `{"en": "...", "ar": "..."}` objects
- `products` sync replaces all existing pivot associations — send full desired list
- The `slug` is auto-generated from the English translation of `name` and is read-only
- `products_count` uses `withCount` for efficient counting without loading the relation
- Pivot records in `category_product` are preserved on soft delete
