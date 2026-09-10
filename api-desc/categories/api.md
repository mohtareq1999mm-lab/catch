# API Reference — Category

---

## Admin Endpoints

---

### GET /api/v1/categories

Paginated list of categories with filtering, sorting, and hierarchy support.

**Authentication**: `auth:sanctum`, permission: `view-categories`

**Query Parameters**:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| page | int | 1 | Page number |
| per_page | int | 15 | Items per page (alias: limit) |
| search | string | - | Search by name (LIKE, supports translatable fields) |
| active | bool | - | Filter by status=true |
| inactive | bool | - | Filter by status=false |
| parent | bool | - | If true, returns only root categories (parent_id IS NULL) |
| exceptSelf | int | - | Exclude category by ID (useful when editing to prevent self-parent) |
| feature-category | bool | - | Filter by is_featured=true |
| order | string | - | Sort column (id, name, slug, products_count, created_at, updated_at, level) |
| sortedBy | string | asc | Sort direction (asc, desc) |

**Response 200**:
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "data": [
      {
        "id": 3,
        "name": "Men",
        "slug": "men",
        "parent_id": null,
        "level": 1,
        "image": {
          "desktop": "https://example.com/storage/categories/desktop-image.jpg",
          "mobile": "https://example.com/storage/categories/mobile-image.jpg"
        },
        "is_featured": false,
        "products_count": 25,
        "status": true
      }
    ],
    "page": 1,
    "current_page": 1,
    "from": 1,
    "to": 15,
    "last_page": 2,
    "path": "http://example.com/api/v1/categories",
    "per_page": 15,
    "total": 20,
    "next_page_url": "http://example.com/api/v1/categories?page=2",
    "prev_page_url": "",
    "last_page_url": "http://example.com/api/v1/categories?page=2",
    "first_page_url": "http://example.com/api/v1/categories?page=1"
  }
}
```

**Quick Test**:
```bash
# List all categories (page 1, 15 per page)
curl -X GET "http://example.com/api/v1/categories?page=1&per_page=15" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"

# Search categories by name
curl -X GET "http://example.com/api/v1/categories?search=men" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"

# Filter root categories only, sorted by level ascending
curl -X GET "http://example.com/api/v1/categories?parent=true&order=level&sortedBy=asc" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"

# Filter featured categories
curl -X GET "http://example.com/api/v1/categories?feature-category=true" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

**Business Rules**:
- Active and inactive filters are mutually exclusive (both true = empty result)
- `exceptSelf` is typically used in category edit forms to exclude the current category from the parent dropdown (preventing self-parent selection)
- `feature-category` filters categories where `is_featured = true`
- `details` field is omitted in the index response (only included in show)
- `children` and `products` are not loaded in the index response

---

### POST /api/v1/categories

Create a new category.

**Authentication**: `auth:sanctum`, permission: `create-category`

**Request Body** (multipart/form-data):

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| name | object | required | Translatable name (e.g., `{"en": "Men", "ar": "رجال"}`) |
| image-desktop | file | required | Desktop image (jpeg,png,jpg,gif,svg, max 2MB) |
| image-mobile | file | required | Mobile image (jpeg,png,jpg,gif,svg, max 2MB) |
| parent_id | int | sometimes | Parent category ID for hierarchy |
| details | string | sometimes | Plain text description (min:3, max:2500 characters) |
| products | array | sometimes | Array of product IDs to associate |
| products.* | int | sometimes | Valid product ID (exists:products,id) |

**Validation Rules**:
| Field | Rules |
|-------|-------|
| name | required, array |
| name.* | required, string, unique_translation:categories,name |
| image-desktop | required, file, mimes:jpeg,png,jpg,gif,svg, max:2048 |
| image-mobile | required, file, mimes:jpeg,png,jpg,gif,svg, max:2048 |
| parent_id | nullable, integer, exists:categories,id |
| details | sometimes, string, min:3, max:2500 |
| products | sometimes, array |
| products.* | integer, exists:products,id |

**Request Body (JSON)**:
```json
{
  "name": {
    "en": "Accessories",
    "ar": "إكسسوارات"
  },
  "details": "Browse our wide range of accessories.",
  "parent_id": 1,
  "products": [5, 12, 18]
}
```

> **Note:** `image-desktop` and `image-mobile` are file fields — they must be sent as `multipart/form-data`, not included in the JSON body. The JSON above covers all non-file fields.

**Response 200**:
```json
{
  "status": 200,
  "message": "Category created successfully",
  "success": true,
  "data": {
    "id": 21,
    "name": "Accessories",
    "slug": "accessories",
    "parent_id": 1,
    "level": 2,
    "image": {
      "desktop": "https://example.com/storage/categories/desktop-image.jpg",
      "mobile": "https://example.com/storage/categories/mobile-image.jpg"
    },
    "is_featured": false,
    "products_count": 3,
    "status": true,
    "details": "Browse our wide range of accessories.",
    "products": [
      {
        "id": 5,
        "name": "Product 5",
        "slug": "product-5",
        "status": "publish",
        "image": { "thumbnail": "..." }
      }
    ]
  }
}
```

**Response 422** (validation):
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "name.en": ["The name en has already been taken."],
    "image-desktop": ["The image-desktop field is required."]
  }
}
```

**Quick Test**:
```bash
# Create category (without images — will fail 422, use multipart for full test)
curl -X POST "http://example.com/api/v1/categories" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"name": {"en": "Test Category", "ar": "فئة اختبار"}, "details": "Test description", "parent_id": 1}'
```

**Business Rules**:
- Slug is auto-generated from the English name
- Level is auto-calculated: root = 1, child = parent.level + 1
- If `parent_id` is provided, the hierarchy service validates no circular reference or self-parent
- Products are synced (replaces any existing associations)
- Images are uploaded to `categories-desktop` and `categories-mobile` collections on `categories` disk
- `details` is a plain text string (unlike brands where it's translatable array)
- Activity is logged via `CategoryObserver@created`

---

### GET /api/v1/categories/{id}

Get a single category by ID with parent, children, and products.

**Authentication**: `auth:sanctum`, permission: `view-categories`

**Path Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| id | int | Category ID |

**Response 200**:
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 3,
    "name": "Men",
    "slug": "men",
    "parent_id": null,
    "level": 1,
    "image": {
      "desktop": "https://example.com/storage/categories/desktop-image.jpg",
      "mobile": "https://example.com/storage/categories/mobile-image.jpg"
    },
    "is_featured": true,
    "products_count": 25,
    "status": true,
    "details": "A wonderful serenity has taken possession of my entire soul.",
    "children": [
      {
        "id": 4,
        "name": "T-Shirts",
        "slug": "t-shirts",
        "products_count": 10,
        "image": { "desktop": "...", "mobile": "..." }
      }
    ],
    "products": [
      {
        "id": 5,
        "name": "Product 5",
        "slug": "product-5",
        "status": "publish",
        "image": { "thumbnail": "..." }
      }
    ]
  }
}
```

**Response 404**:
```json
{
  "status": 404,
  "message": "Not found",
  "success": false
}
```

**Quick Test**:
```bash
# Get category by ID
curl -X GET "http://example.com/api/v1/categories/3" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

**Business Rules**:
- Accepts numeric ID only (unlike brands which also accept slug)
- Loads parent category, products, and direct children via `CategoryHierarchyService::loadDirectChildren()`
- `children` and `products` are only included when the relationship is loaded and non-empty
- `details` is included in show response (omitted in index)

---

### PUT /api/v1/categories/{id}

Update an existing category.

**Authentication**: `auth:sanctum`, permission: `update-category`

**Path Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| id | int | Category ID |

**Request Body** (multipart/form-data):

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| name | object | sometimes | Translatable name |
| image-desktop | file | sometimes | New desktop image (replaces existing) |
| image-mobile | file | sometimes | New mobile image (replaces existing) |
| parent_id | int | sometimes | New parent category ID (triggers hierarchy recalculation) |
| details | string | sometimes | Plain text description |
| products | array | sometimes | Array of product IDs (replaces all) |
| status | int | sometimes | 1 or 0 |

**Validation Rules**:
| Field | Rules |
|-------|-------|
| name | sometimes, array |
| name.* | sometimes, string, unique_translation:categories ->ignore($id) |
| image-desktop | sometimes, file, mimes:jpeg,png,jpg,gif,svg, max:2048 |
| image-mobile | sometimes, file, mimes:jpeg,png,jpg,gif,svg, max:2048 |
| parent_id | nullable, integer, exists:categories,id, **custom: prevents circular reference** |
| details | sometimes, string, min:3, max:2500 |
| products | sometimes, array |
| products.* | integer, exists:products,id |
| status | sometimes, in:0,1 |

**Request Body (JSON)**:
```json
{
  "name": {
    "en": "Men's Fashion",
    "ar": "أزياء رجالية"
  },
  "details": "Updated description for men's fashion category.",
  "parent_id": null,
  "status": 1,
  "products": [2, 7, 15]
}
```

> **Note:** `image-desktop` and `image-mobile` are file fields — they must be sent as `multipart/form-data`, not included in the JSON body. All fields are optional on update.

**Response 200**:
```json
{
  "status": 200,
  "message": "Category updated successfully",
  "success": true,
  "data": {
    "id": 3,
    "name": "Men's Fashion",
    "slug": "mens-fashion",
    "parent_id": null,
    "level": 1,
    "image": { "desktop": "...", "mobile": "..." },
    "is_featured": true,
    "products_count": 3,
    "status": true,
    "details": "Updated description for men's fashion category.",
    "products": []
  }
}
```

**Response 404**:
```json
{ "status": 404, "message": "Not found", "success": false }
```

**Response 422** (circular reference):
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "parent_id": ["The selected parent category creates a circular reference."]
  }
}
```

**Quick Test**:
```bash
# Update category name and parent
curl -X PUT "http://example.com/api/v1/categories/3" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"name": {"en": "Updated Category"}, "parent_id": 1, "status": 0}'

# Update category product associations
curl -X PUT "http://example.com/api/v1/categories/3" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"products": [1, 2, 3]}'
```

**Business Rules**:
- Slug is auto-regenerated from English name if name is changed
- Uniqueness check ignores the current category's own name
- Changing `parent_id` triggers hierarchy recalculation:
  - Level is recalculated based on new parent
  - Descendant levels are updated recursively
  - Circular reference is detected and rejected
- Existing images are replaced (old collection is cleared)
- Products array replaces ALL current associations (sync, not attach)
- Activity is logged via `CategoryObserver@updated`

---

### DELETE /api/v1/categories/{id}

Soft-delete a category.

**Authentication**: `auth:sanctum`, permission: `delete-category`

**Path Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| id | int | Category ID |

**Response 200**:
```json
{
  "status": 200,
  "message": "Category deleted successfully",
  "success": true
}
```

**Response 404**:
```json
{ "status": 404, "message": "Not found", "success": false }
```

**Response 409** (has children):
```json
{ "status": 409, "message": "Cannot delete category with associated resources", "success": false }
```

**Quick Test**:
```bash
# Delete category
curl -X DELETE "http://example.com/api/v1/categories/3" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

**Business Rules**:
- Uses soft deletes (sets `deleted_at`, does not remove the row)
- **Cannot delete a category that has children** — parent FK uses RESTRICT ON DELETE, throws QueryException → translated to `CANNOT_DELETE_CATEGORY_WITH_ASSOCIATED_RESOURCES`
- Child categories must be deleted or re-parented before a parent can be deleted
- Pivot records in `category_product` are preserved after soft delete
- Media files are preserved on soft delete
- Activity is logged via `CategoryObserver@deleted`

---

### PUT /api/v1/categories/feature

Toggle a category's featured status.

**Authentication**: `auth:sanctum`, permission: `update-category`

**Request Body**:

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| id | int | required | Category ID (exists:categories,id) |

**Request Body (JSON)**:
```json
{
  "id": 3
}
```

**Response 200**:
```json
{
  "status": 200,
  "message": "Category feature toggled successfully",
  "success": true
}
```

**Response 422**:
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "id": ["The selected category does not exist"]
  }
}
```

**Quick Test**:
```bash
# Toggle featured status for category 3
curl -X PUT "http://example.com/api/v1/categories/feature" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"id": 3}'
```

**Business Rules**:
- Toggles `is_featured` between true and false (no explicit true/false parameter)
- If currently featured → unfeatures. If not featured → features.
- This route must be registered BEFORE `apiResource` to avoid `PUT /categories/{id}` collision

---

### GET /api/v1/featured-categories

Get top featured categories sorted by product count.

**Authentication**: None (public)

**Query Parameters**:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| limit | int | 3 | Number of categories to return |

**Response 200**:
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": [
    {
      "id": 3,
      "name": "Men",
      "slug": "men",
      "parent_id": null,
      "level": 1,
      "image": {
        "desktop": "https://example.com/storage/categories/desktop-image.jpg",
        "mobile": "https://example.com/storage/categories/mobile-image.jpg"
      },
      "is_featured": true,
      "products_count": 25,
      "status": true,
      "products": [
        {
          "id": 5,
          "name": "Product 5",
          "slug": "product-5",
          "status": "publish",
          "image": { "thumbnail": "..." }
        }
      ]
    }
  ]
}
```

**Quick Test**:
```bash
# Get top 5 featured categories
curl -X GET "http://example.com/api/v1/featured-categories?limit=5" \
  -H "Accept: application/json"
```

**Business Rules**:
- Returns categories sorted by `products_count` descending (most products first)
- Limit defaults to 3 if not provided
- Includes loaded products for each featured category
- No authentication required

---

## Public Endpoints

---

### GET /api/v1/general/categories

List active categories with filtering and search.

**Authentication**: None (public)

**Query Parameters**:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| limit | int | 15 | Items per page (max 100) |
| search | string | - | Search by name or details (translatable LIKE) |
| parent | bool | - | If true, returns only root categories (parent_id IS NULL) |
| pest_category | bool | - | If true, sorts by products_count instead of id |
| categoriesId | string | - | Comma-separated list of category IDs to include |
| slug | string | - | If provided, delegates to getCategoryBySlug (single category) |
| order | string | desc | Sort direction (asc, desc) |

**Response 200**:
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": [
    {
      "id": 3,
      "name": "Men",
      "slug": "men",
      "image": {
        "desktop": "https://example.com/storage/categories/desktop-image.jpg",
        "mobile": "https://example.com/storage/categories/mobile-image.jpg"
      },
      "products_count": 25,
      "details": "A wonderful serenity has taken possession of my entire soul."
    }
  ],
  "pagination": {
    "total": 20,
    "per_page": 15,
    "current_page": 1,
    "last_page": 2
  }
}
```

**Quick Test**:
```bash
# List categories (page 1, 15 per page)
curl -X GET "http://example.com/api/v1/general/categories?limit=15" \
  -H "Accept: application/json"

# Search categories
curl -X GET "http://example.com/api/v1/general/categories?search=men" \
  -H "Accept: application/json"

# Filter root categories only, sorted by products count
curl -X GET "http://example.com/api/v1/general/categories?parent=true&pest_category=true&order=asc" \
  -H "Accept: application/json"

# Filter by specific IDs
curl -X GET "http://example.com/api/v1/general/categories?categoriesId=1,2,3" \
  -H "Accept: application/json"

# Get single category by slug via query param
curl -X GET "http://example.com/api/v1/general/categories?slug=men" \
  -H "Accept: application/json"
```

**Business Rules**:
- Only returns active categories (status = 1)
- If `slug` query param is provided, returns a single category by slug instead
- Search applies to both `name` and `details` translatable fields
- `details` field is only included in the response when it has a value
- Pagination is capped at 100 items per page

---

### GET /api/v1/general/categories/{slug}

Get a single category by slug with enriched products and children.

**Authentication**: None (public)

**Path Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| slug | string | Category slug |

**Response 200**:
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 3,
    "name": "Men",
    "slug": "men",
    "image": {
      "desktop": "https://example.com/storage/categories/desktop-image.jpg",
      "mobile": "https://example.com/storage/categories/mobile-image.jpg"
    },
    "products_count": 25,
    "details": "A wonderful serenity has taken possession of my entire soul.",
    "children": [
      {
        "id": 4,
        "name": "T-Shirts",
        "slug": "t-shirts",
        "image": { "desktop": "...", "mobile": "..." },
        "products_count": 10,
        "details": "T-shirt collection"
      }
    ],
    "products": [
      {
        "id": 5,
        "name": "Product 5",
        "slug": "product-5",
        "image": { "thumbnail": "..." },
        "price": 99.99,
        "price_after_discount": 89.99
      }
    ]
  }
}
```

**Response 404**:
```json
{ "status": 404, "message": "Not found", "success": false }
```

**Quick Test**:
```bash
# Get category by slug
curl -X GET "http://example.com/api/v1/general/categories/men" \
  -H "Accept: application/json"
```

**Business Rules**:
- Only returns active categories
- Children are filtered to active only, with `products_count` included
- Products are enriched with pricing data via `ProductService::enrichCollectionWithPricing()`
- Products are filtered through channel filter (home vs non-home)
- Children are rendered using `CategoryHomeResource`, products using `ProductMiniResource`
