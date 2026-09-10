# API Documentation - Category Feature

## Endpoints

---

### 1. List Categories (Public)

**GET** `/api/v1/general/categories`

**Purpose:** Retrieve paginated list of active categories for the shop frontend.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | No |
| Guard | None |

#### Query Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `page` | `integer` | No | Page number (default: 1) |
| `search` | `string` | No | Search by category name (translatable) |
| `parentOnly` | `boolean` | No | Return only top-level categories (parent_id IS NULL) |
| `channel` | `string` | No | Channel filter |

#### Success Response (200)

```json
{
    "data": [
        {
            "id": 1,
            "name": "Face",
            "slug": "face",
            "image": {
                "desktop": "https://cdn.example.com/categories/face-desktop.jpg",
                "mobile": "https://cdn.example.com/categories/face-mobile.jpg"
            },
            "products_count": 120
        }
    ],
    "meta": {
        "current_page": 1,
        "last_page": 3,
        "per_page": 15,
        "total": 42
    }
}
```

---

### 2. Get Category by Slug (Public)

**GET** `/api/v1/general/categories/{slug}`

**Purpose:** Retrieve a single category with its child categories and products.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | No |
| Guard | None |

#### Path Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `slug` | `string` | Yes | Category slug |

#### Success Response (200)

```json
{
    "data": {
        "id": 1,
        "name": "Face",
        "slug": "face",
        "image": {
            "desktop": "https://cdn.example.com/categories/face-desktop.jpg",
            "mobile": "https://cdn.example.com/categories/face-mobile.jpg"
        },
        "products_count": 120,
        "details": "All face products including cleansers, moisturizers, and serums",
        "children": [
            {
                "id": 10,
                "name": "Cleansers",
                "slug": "cleansers",
                "image": {
                    "desktop": "https://cdn.example.com/categories/cleansers-desktop.jpg",
                    "mobile": "https://cdn.example.com/categories/cleansers-mobile.jpg"
                },
                "products_count": 35
            }
        ],
        "products": [
            {
                "id": 1,
                "name": "Hydrating Cleanser",
                "slug": "hydrating-cleanser",
                "status": true,
                "thumbnail": "https://cdn.example.com/products/cleanser-thumb.jpg"
            }
        ]
    }
}
```

#### Error Responses

| Status | Condition | Body |
|--------|-----------|------|
| 404 | Slug not found | `{ "message": "Not Found" }` |

---

### 3. List Categories (Admin)

**GET** `/api/v1/categories`

**Purpose:** Retrieve paginated list of all categories (including inactive) for admin management.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Guard | `sanctum` |
| Permission | `view-categories` |

#### Query Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `page` | `integer` | No | Page number |
| `limit` | `integer` | No | Items per page |
| `search` | `string` | No | Search by name |
| `parent` | `integer` | No | Filter by parent_id |
| `is_featured` | `boolean` | No | Filter by featured status |
| `status` | `boolean` | No | Filter by active/inactive |
| `orderBy` | `string` | No | Sort field (e.g., `level`) |

#### Success Response (200)

```json
{
    "data": [
        {
            "id": 1,
            "name": "Face",
            "slug": "face",
            "parent_id": null,
            "level": 1,
            "image": { "desktop": "...", "mobile": "..." },
            "is_featured": true,
            "products_count": 120,
            "status": true,
            "children": [
                {
                    "id": 10,
                    "name": "Cleansers",
                    "slug": "cleansers",
                    "products_count": 35,
                    "image": { "desktop": "...", "mobile": "..." }
                }
            ]
        }
    ],
    "meta": { "current_page": 1, "last_page": 5, "per_page": 15, "total": 72 }
}
```

---

### 4. Create Category (Admin)

**POST** `/api/v1/categories`

**Purpose:** Create a new product category.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Guard | `sanctum` |
| Permission | `create-category` |

#### Request Parameters (multipart/form-data)

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `name[en]` | `string` | Yes | English name |
| `name[ar]` | `string` | No | Arabic name |
| `image-desktop` | `file` | Yes | Desktop image (jpeg/png/jpg/gif/svg, max 2MB) |
| `image-mobile` | `file` | Yes | Mobile image (jpeg/png/jpg/gif/svg, max 2MB) |
| `slug` | `string` | No | URL slug (auto-generated from English name if omitted) |
| `parent_id` | `integer` | No | Parent category ID |
| `details[en]` | `string` | No | English description (min:3, max:2500) |
| `details[ar]` | `string` | No | Arabic description |
| `products[]` | `array` | No | Array of product IDs to associate |

#### Success Response (201)

```json
{
    "data": {
        "id": 73,
        "name": "New Category",
        "slug": "new-category",
        "parent_id": null,
        "level": 1,
        "status": true,
        "is_featured": false,
        "products_count": 0,
        "image": { "desktop": "...", "mobile": "..." },
        "details": "Category description"
    }
}
```

#### Error Responses

| Status | Condition |
|--------|-----------|
| 422 | Validation failure (missing name, invalid image type, etc.) |
| 401 | Unauthenticated |
| 403 | Forbidden (missing permission) |

---

### 5. Get Category (Admin)

**GET** `/api/v1/categories/{id}`

**Purpose:** Retrieve a single category by ID.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Guard | `sanctum` |
| Permission | `view-categories` |

#### Success Response (200)

Returns the same structure as the create response, including `children`, `parent`, and `products`.

#### Error Responses

| Status | Condition |
|--------|-----------|
| 404 | Category not found |

---

### 6. Update Category (Admin)

**PUT** `/api/v1/categories/{id}`

**Purpose:** Update an existing category (partial updates supported).

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Guard | `sanctum` |
| Permission | `update-category` |

#### Request Parameters (multipart/form-data)

Same as create but all fields optional. Additional field:

| Parameter | Type | Description |
|-----------|------|-------------|
| `status` | `string` | `0` (inactive) or `1` (active) |

**Circular reference protection:** Changing `parent_id` validates that the new parent is not a descendant of the current category.

#### Success Response (200)

Returns updated category resource.

---

### 7. Delete Category (Admin)

**DELETE** `/api/v1/categories/{id}`

**Purpose:** Soft-delete a category. Child categories remain (parent_id is preserved). Products remain (pivot is preserved for soft-deleted categories).

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Guard | `sanctum` |
| Permission | `delete-category` |

#### Success Response (200)

```json
{
    "message": "Category deleted successfully"
}
```

#### Error Responses

| Status | Condition |
|--------|-----------|
| 404 | Already deleted or not found |

---

### 8. Featured Categories (Public)

**GET** `/api/v1/featured-categories`

**Purpose:** Retrieve featured categories sorted by product count (descending).

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | No |

#### Success Response (200)

```json
{
    "data": [
        {
            "id": 1,
            "name": "Face",
            "slug": "face",
            "image": { "desktop": "...", "mobile": "..." },
            "products_count": 120
        }
    ]
}
```

---

### 9. Toggle Featured (Admin)

**PUT** `/api/v1/categories/feature`

**Purpose:** Toggle the `is_featured` flag on a category.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Guard | `sanctum` |
| Permission | `update-category` |

#### Request Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `id` | `integer` | Yes | Category ID |

#### Success Response (200)

```json
{
    "message": "Category featured toggled successfully"
}
```

---

### 10. Dashboard Analytics

**GET** `/api/v1/dashboard/category-stats` — Top/bottom categories by product count
**GET** `/api/v1/dashboard/categories` — Per-category analytics with revenue data

Both require `auth:sanctum` and have throttling.
Cached for 5 minutes.

---

## Resource Structure

### CategoryResource (Admin Index)

| Field | Type | Description |
|-------|------|-------------|
| `id` | `integer` | Primary key |
| `name` | `string` | Translated name |
| `slug` | `string` | URL slug |
| `parent_id` | `integer|null` | Parent category ID |
| `level` | `integer` | Depth in hierarchy (root = 1) |
| `image` | `object` | `{ desktop: string, mobile: string }` |
| `is_featured` | `boolean` | Featured flag |
| `products_count` | `integer` | Number of products |
| `status` | `boolean` | Active/inactive |
| `children` | `array` | Child categories (when loaded) |
| `details` | `string|null` | Description (excluded on index route) |
| `products` | `array` | Associated products (when loaded) |

## Business Rules

1. **Hierarchy:** Level is auto-calculated: root = 1, child = parent.level + 1
2. **Cycle Detection:** Cannot set parent to self or any descendant
3. **Slug Auto-generation:** If slug not provided, generated from English name
4. **Soft Delete:** Category is soft-deleted; child categories are NOT deleted
5. **Pivot Preservation:** Product-category pivot is preserved on soft delete
6. **Details Exclusion:** `details` field is omitted in index responses to keep payload lightweight
7. **Media Lifecycle:** Update replaces old images; soft delete preserves media; force delete removes all
8. **Activity Logging:** All CRUD operations are logged via Observer + queued job
