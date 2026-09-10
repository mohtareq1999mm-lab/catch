# API Reference — Brand

---

## Admin Endpoints

---

### GET /api/v1/brands

Paginated list of brands with filtering and sorting.

**Authentication**: `auth:sanctum`, permission: `view-brands`

**Query Parameters**:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| page | int | 1 | Page number |
| per_page | int | 15 | Items per page (alias: limit) |
| search | string | - | Search by name (LIKE, supports translatable fields) |
| active | bool | - | Filter by status=true |
| inactive | bool | - | Filter by status=false |
| order | string | - | Sort column (id, name, slug, status, created_at, updated_at) |
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
        "id": 1,
        "name": "Apple",
        "slug": "apple",
        "image": {
          "desktop": "https://example.com/storage/brands/desktop-image.jpg",
          "mobile": "https://example.com/storage/brands/mobile-image.jpg"
        },
        "details": "Premium electronics and smartphones",
        "status": true
      }
    ],
    "page": 1,
    "current_page": 1,
    "from": 1,
    "to": 15,
    "last_page": 2,
    "path": "http://example.com/api/v1/brands",
    "per_page": 15,
    "total": 30,
    "next_page_url": "http://example.com/api/v1/brands?page=2",
    "prev_page_url": "",
    "last_page_url": "http://example.com/api/v1/brands?page=2",
    "first_page_url": "http://example.com/api/v1/brands?page=1"
  }
}
```

**Quick Test**:
```bash
# List all brands (page 1, 15 per page)
curl -X GET "http://example.com/api/v1/brands?page=1&per_page=15" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"

# Search brands by name
curl -X GET "http://example.com/api/v1/brands?search=apple" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"

# Filter active brands, sorted by name ascending
curl -X GET "http://example.com/api/v1/brands?active=true&order=name&sortedBy=asc" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

**Business Rules**:
- Results are ordered by the `order` column by default (via `ordered()` scope from SortableTrait)
- Active and inactive filters are mutually exclusive (both true = empty result)
- Search applies to translatable `name` field (searches both `name->{locale}` and raw `name`)

---

### POST /api/v1/brands

Create a new brand.

**Authentication**: `auth:sanctum`, permission: `create-brand`

**Request Body** (multipart/form-data):

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| name | object | required | Translatable name (e.g., `{"en": "Apple", "ar": "أبل"}`) |
| image-desktop | file | required | Desktop image (jpeg,png,jpg,gif,svg, max 2MB) |
| image-mobile | file | required | Mobile image (jpeg,png,jpg,gif,svg, max 2MB) |
| details | object | sometimes | Translatable details (min:3, max:2500 per locale) |
| status | int | sometimes | 1 or 0 (default: 0) |
| products | array | sometimes | Array of product IDs to associate |
| products.* | int | sometimes | Valid product ID (exists:products,id) |

**Validation Rules**:
| Field | Rules |
|-------|-------|
| name | required, array |
| name.* | required, string, unique_translation:brands,name |
| image-desktop | required, file, mimes:jpeg,png,jpg,gif,svg, max:2048 |
| image-mobile | required, file, mimes:jpeg,png,jpg,gif,svg, max:2048 |
| details | sometimes, array |
| details.* | required_with:details, string, min:3, max:2500 |
| status | sometimes, in:1,0 |
| products | sometimes, array |
| products.* | integer, exists:products,id |

**Request Body (JSON)**:
```json
{
  "name": {
    "en": "Apple",
    "ar": "أبل"
  },
  "details": {
    "en": "Premium electronics and smartphones",
    "ar": "إلكترونيات وهواتف ذكية فاخرة"
  },
  "status": 1,
  "products": [1, 5, 12]
}
```

> **Note:** `image-desktop` and `image-mobile` are file fields — they must be sent as `multipart/form-data`, not included in the JSON body. The JSON above covers all non-file fields.

**Response 201**:
```json
{
  "status": 201,
  "message": "Brand created successfully",
  "success": true,
  "data": {
    "id": 31,
    "name": "Apple",
    "slug": "apple",
    "image": {
      "desktop": "https://example.com/storage/brands/desktop-image.jpg",
      "mobile": "https://example.com/storage/brands/mobile-image.jpg"
    },
    "details": "Premium electronics and smartphones",
    "status": true,
    "products": []
  }
}
```

**Response 422** (validation):
```json
{
  "name.en": ["The name en has already been taken."],
  "image-desktop": ["The image-desktop field is required."]
}
```

**Quick Test**:
```bash
# Create brand (without images — will fail 422, use multipart for full test)
curl -X POST "http://example.com/api/v1/brands" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"name": {"en": "Test Brand", "ar": "علامة تجارية"}, "details": {"en": "Test description"}, "status": 1}'
```

**Business Rules**:
- Slug is auto-generated from the English name
- Products are synced (replaces any existing associations)
- Images are uploaded to `brands-desktop` and `brands-mobile` collections on `brands` disk
- Activity is logged via `BrandObserver@created`

---

### GET /api/v1/brands/{id}

Get a single brand by ID or slug.

**Authentication**: `auth:sanctum`, permission: `view-brands`

**Path Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| id | int/string | Brand ID (numeric) or slug (string) |

**Response 200**:
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "name": "Apple",
    "slug": "apple",
    "image": {
      "desktop": "https://example.com/storage/brands/desktop-image.jpg",
      "mobile": "https://example.com/storage/brands/mobile-image.jpg"
    },
    "details": "Premium electronics and smartphones",
    "status": true,
    "products": [
      {
        "id": 5,
        "name": "iPhone 15",
        "slug": "iphone-15",
        "status": "publish",
        "image": {
          "thumbnail": "https://example.com/storage/products/thumbnail.jpg"
        }
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
# Get brand by ID
curl -X GET "http://example.com/api/v1/brands/1" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"

# Get brand by slug
curl -X GET "http://example.com/api/v1/brands/apple" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

**Business Rules**:
- Accepts both numeric ID and string slug
- Loads related products via eager loading
- Products are included only if the relationship is loaded

---

### PUT /api/v1/brands/{id}

Update an existing brand.

**Authentication**: `auth:sanctum`, permission: `update-brand`

**Path Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| id | int | Brand ID |

**Request Body** (multipart/form-data):

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| name | object | sometimes | Translatable name |
| image-desktop | file | sometimes | New desktop image (replaces existing) |
| image-mobile | file | sometimes | New mobile image (replaces existing) |
| details | object | sometimes | Translatable details |
| status | int | sometimes | 1 or 0 |
| products | array | sometimes | Array of product IDs (replaces all) |

**Validation Rules**:
| Field | Rules |
|-------|-------|
| name | sometimes, array |
| name.* | sometimes, string, unique_translation:brands,name ->ignore($id) |
| image-desktop | sometimes, file, mimes:jpeg,png,jpg,gif,svg, max:2048 |
| image-mobile | sometimes, file, mimes:jpeg,png,jpg,gif,svg, max:2048 |
| details | sometimes, array |
| details.* | required_with:details, string, min:3, max:2500 |
| status | sometimes, in:1,0 |
| products | sometimes, array |
| products.* | integer, exists:products,id |

**Request Body (JSON)**:
```json
{
  "name": {
    "en": "Apple Inc.",
    "ar": "أبل إنك"
  },
  "details": {
    "en": "Updated description",
    "ar": "وصف محدث"
  },
  "status": 0,
  "products": [2, 7, 15]
}
```

> **Note:** `image-desktop` and `image-mobile` are file fields — they must be sent as `multipart/form-data`, not included in the JSON body. All fields are optional on update.

**Response 200**:
```json
{
  "status": 200,
  "message": "Brand updated successfully",
  "success": true,
  "data": {
    "id": 1,
    "name": "Apple Inc.",
    "slug": "apple-inc",
    "image": { "desktop": "...", "mobile": "..." },
    "details": "Updated details",
    "status": true,
    "products": []
  }
}
```

**Response 404**:
```json
{ "status": 404, "message": "Not found", "success": false }
```

**Quick Test**:
```bash
# Update brand name and status
curl -X PUT "http://example.com/api/v1/brands/1" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"name": {"en": "Updated Brand"}, "status": 0}'

# Update brand with product associations
curl -X PUT "http://example.com/api/v1/brands/1" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"products": [1, 2, 3]}'
```

**Business Rules**:
- Slug is auto-regenerated from English name if name is changed
- Uniqueness check ignores the current brand's own name
- Existing images are replaced (old collection is cleared)
- Products array replaces ALL current associations (sync, not attach)
- Activity is logged via `BrandObserver@updated`

---

### DELETE /api/v1/brands/{id}

Soft-delete a brand.

**Authentication**: `auth:sanctum`, permission: `delete-brand`

**Path Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| id | int | Brand ID |

**Response 200**:
```json
{
  "status": 200,
  "message": "Brand deleted successfully",
  "success": true
}
```

**Response 404**:
```json
{ "status": 404, "message": "Not found", "success": false }
```

**Business Rules**:
- Uses soft deletes (sets `deleted_at`, does not remove the row)
- Pivot records in `brand_product` are preserved after soft delete
- Restoring the brand restores access to pivot relationships
- Activity is logged via `BrandObserver@deleted`

---

### PUT /api/v1/brands/reorder

Reorder brands by providing IDs in the desired order.

**Authentication**: `auth:sanctum`, permission: `update-brand`

**Request Body**:

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| brands | array | required | Array of brand IDs in desired order |
| brands.* | int | required | Valid brand ID (exists:brands,id) |

**Request Body (JSON)**:
```json
{
  "brands": [3, 1, 2, 5, 4]
}
```

**Response 200**:
```json
{
  "status": 200,
  "message": "Brands reordered successfully",
  "success": true
}
```

**Response 422**:
```json
{
  "brands.0": ["One or more brands do not exist"]
}
```

**Quick Test**:
```bash
# Reorder brands: move brand 3 to first position, 1 to second, 2 to third
curl -X PUT "http://example.com/api/v1/brands/reorder" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"brands": [3, 1, 2]}'
```

**Business Rules**:
- Order is determined by the array position (0-based index)
- Uses Spatie Eloquent Sortable's `setNewOrder()` to update the `order` column
- Brand IDs must all exist in the `brands` table

---

## Public Endpoints

---

### GET /api/v1/general/brands

List active brands with optional filters.

**Authentication**: None (public)

**Query Parameters**:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| limit | int | 10 | Number of brands to return |
| start_date | date | - | Filter by created_at >= start_date |
| end_date | date | - | Filter by created_at <= end_date |
| brandsId | string | - | Comma-separated list of brand IDs to include |
| slug | string | - | If provided, delegates to getBrandBySlug (single brand) |
| order | string | desc | Sort direction (asc, desc) |

**Response 200**:
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Apple",
      "slug": "apple",
      "image": { "desktop": "...", "mobile": "..." },
      "status": true
    }
  ]
}
```

**Quick Test**:
```bash
# List active brands (limit 10)
curl -X GET "http://example.com/api/v1/general/brands?limit=10" \
  -H "Accept: application/json"

# Filter by specific brand IDs
curl -X GET "http://example.com/api/v1/general/brands?brandsId=1,2,3" \
  -H "Accept: application/json"

# Get single brand by slug via query param
curl -X GET "http://example.com/api/v1/general/brands?slug=apple" \
  -H "Accept: application/json"
```

**Business Rules**:
- Only returns active brands (status = 1)
- If `slug` query param is provided, returns a single brand by slug instead
- Results are ordered by `id` (desc by default)

---

### GET /api/v1/general/brands/{slug}

Get a single brand by slug with enriched products.

**Authentication**: None (public)

**Path Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| slug | string | Brand slug |

**Response 200**:
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "name": "Apple",
    "slug": "apple",
    "image": { "desktop": "...", "mobile": "..." },
    "status": true,
    "products": [
      {
        "id": 5,
        "name": "iPhone 15",
        "slug": "iphone-15",
        "image": { "thumbnail": "..." },
        "price": 999.99,
        "price_after_discount": 949.99
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
# Get brand by slug
curl -X GET "http://example.com/api/v1/general/brands/apple" \
  -H "Accept: application/json"
```

**Business Rules**:
- Only returns active brands
- Products are enriched with pricing data via `ProductService::enrichCollectionWithPricing()`
- Products are filtered through channel filter (home vs non-home)
- Products include review average ratings

---

### GET /api/v1/general/brands-products

Get products grouped by brands with quantity limits.

**Authentication**: None (public)

**Query Parameters**:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| limit | int | 10 | Products per brand |
| limit_brand | int | 10 | Number of brands to fetch |
| start_date | date | - | Filter brands by created_at >= start_date |
| end_date | date | - | Filter brands by created_at <= end_date |

**Response 200**:
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": [
    {
      "id": 5,
      "name": "iPhone 15",
      "slug": "iphone-15",
      "image": { "thumbnail": "..." },
      "price": 999.99,
      "price_after_discount": 949.99,
      "rating": 4.5
    }
  ]
}
```

**Quick Test**:
```bash
# Get brand products (10 products per brand, up to 10 brands)
curl -X GET "http://example.com/api/v1/general/brands-products?limit=10&limit_brand=5" \
  -H "Accept: application/json"
```

**Business Rules**:
- Returns a flat list of products (not grouped by brand)
- Products are enriched with pricing and review averages
- Products are filtered through channel filter
- Only active brands are included
