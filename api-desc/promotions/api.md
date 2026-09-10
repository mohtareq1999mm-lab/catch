# API Reference — Promotion

---

## Admin Endpoints

---

### GET /api/v1/promotions

Paginated list of promotions with filtering and sorting.

**Authentication**: `auth:sanctum`, permission: `view-promotion`

**Query Parameters**:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| page | int | 1 | Page number |
| limit | int | 15 | Items per page |
| search | string | - | Search by name, code, or type (LIKE) |
| status | bool | - | Filter by status (true = active, false = inactive) |
| type | string | - | Filter by type (price, quantity) |
| type_amount | string | - | Filter by type_amount (fixed_rate, percentage, gift) |
| order_by | string | created_at | Sort column |
| sort | string | desc | Sort direction (asc, desc) |

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
        "name": "Summer Special 20% Off",
        "slug": "summer-special-20-off",
        "type": "Percentage discount",
        "discount_type": "percentage",
        "value": 20,
        "discount": 20,
        "code": "ALLXK8FJ2M",
        "minimum_order_amount": 500,
        "required_quantity": 2,
        "apply_to": "all_products",
        "products": [],
        "gift_products": [],
        "image": {
          "desktop": "https://example.com/storage/promotions/desktop.jpg",
          "mobile": "https://example.com/storage/promotions/mobile.jpg"
        },
        "start_at": "2026-07-09T00:00:00+00:00",
        "end_at": "2026-08-28T00:00:00+00:00",
        "status": true,
        "is_valid": true,
        "created_at": "2026-07-19T12:00:00+00:00"
      }
    ],
    "page": 1,
    "current_page": 1,
    "from": 1,
    "to": 15,
    "last_page": 2,
    "path": "http://example.com/api/v1/promotions",
    "per_page": 15,
    "total": 20,
    "next_page_url": "http://example.com/api/v1/promotions?page=2",
    "prev_page_url": "",
    "last_page_url": "http://example.com/api/v1/promotions?page=2",
    "first_page_url": "http://example.com/api/v1/promotions?page=1"
  }
}
```

**Quick Test**:
```bash
# List all promotions
curl -X GET "http://example.com/api/v1/promotions?page=1&limit=15" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"

# Search promotions
curl -X GET "http://example.com/api/v1/promotions?search=summer" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"

# Filter by type_amount
curl -X GET "http://example.com/api/v1/promotions?type_amount=gift" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

**Business Rules**:
- Results are ordered by `created_at desc` by default (global scope on model)
- Search applies to `name`, `code`, and `type` fields with LIKE operator
- Promotions with `status=false` are still returned (unlike `valid()` scope which filters them out)

---

### POST /api/v1/promotions

Create a new promotion.

**Authentication**: `auth:sanctum`, permission: `create-promotion`

**Request Body** (multipart/form-data):

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| name | object | required | Translatable name (e.g., `{"en": "Summer Sale", "ar": "تخفيضات الصيف"}`) |
| image-desktop | file | required | Desktop image (jpeg,png,jpg,webp) |
| image-mobile | file | required | Mobile image (jpeg,png,jpg,webp) |
| type | string | required | `price` or `quantity` |
| type_amount | string | required | `fixed_rate`, `percentage`, or `gift` |
| product_ids | array | conditional | Required when `apply_to=specific_products`, prohibited when `apply_to=all_products` |
| product_ids.* | int | conditional | Valid product ID |
| gift_products | array | conditional | Required when `type_amount=gift` |
| gift_products.*.product_id | int | conditional | Valid product ID (required with gift_products) |
| gift_products.*.product_variant_id | int | sometimes | Valid variant ID (must belong to product) |
| gift_products.*.quantity | int | sometimes | Gift quantity (min: 1) |
| discount | float | conditional | Required when type=price and not gift-only. Min: 0 |
| max_discount_amount | float | conditional | Required when type_amount=percentage. Min: 1 |
| required_quantity_type | int | conditional | Required when type=quantity. Min: 1 |
| minimum_order_amount | float | conditional | Required when type=price. Min: 0 |
| apply_to | string | required | `all_products` or `specific_products` |
| limiter | int | sometimes | Max usage limit. Min: 1 |
| start_at | date | sometimes | Promotion start date |
| end_at | date | sometimes | Promotion end date (must be after or equal to start_at) |
| status | int | sometimes | 0 or 1 |

**Validation Rules**:
| Field | Rules |
|-------|-------|
| name | required, array |
| name.* | required_with:name, unique_translation:promotions,name |
| image-desktop | required, image, mimes:jpeg,png,jpg,webp |
| image-mobile | required, image, mimes:jpeg,png,jpg,webp |
| type | required, in:price,quantity |
| type_amount | required, in:fixed_rate,percentage,gift |
| product_ids | required_if:apply_to,specific_products, prohibited_if:apply_to,all_products, array |
| product_ids.* | exists:products,id |
| gift_products | required_if:type_amount,gift, array, min:1 |
| gift_products.*.product_id | required_with:gift_products, exists:products,id |
| gift_products.*.product_variant_id | nullable, exists:product_variants,id |
| gift_products.*.quantity | sometimes, integer, min:1 |
| discount | numeric, min:0, requiredIf(not gift-only) |
| max_discount_amount | required_if:type_amount,percentage, numeric, min:1 |
| required_quantity_type | integer, min:1, required_if:type,quantity |
| minimum_order_amount | numeric, min:0, required_if:type,price |
| apply_to | required, in:all_products,specific_products |
| limiter | sometimes, integer, min:1 |
| start_at | sometimes, date |
| end_at | sometimes, date, after_or_equal:start_at |
| status | sometimes, in:0,1 |

**Response 201**:
```json
{
  "status": 201,
  "message": "Promotion created successfully",
  "success": true,
  "data": {
    "id": 21,
    "name": "Summer Sale",
    "slug": "summer-sale",
    "type": "Percentage discount",
    "discount_type": "percentage",
    "value": 20,
    "discount": 20,
    "code": "ALLXK8FJ2M",
    "minimum_order_amount": 500,
    "required_quantity": null,
    "apply_to": "all_products",
    "products": [],
    "gift_products": [],
    "image": {
      "desktop": "https://example.com/storage/promotions/desktop.jpg",
      "mobile": "https://example.com/storage/promotions/mobile.jpg"
    },
    "start_at": "2026-07-09T00:00:00+00:00",
    "end_at": "2026-08-28T00:00:00+00:00",
    "status": true,
    "is_valid": true,
    "created_at": "2026-07-19T12:00:00+00:00"
  }
}
```

**Response 422** (validation):
```json
{
  "name.en": ["The name en has already been taken."],
  "image-desktop": ["The image-desktop field is required."],
  "type": ["The selected type is invalid."]
}
```

**Business Rules**:
- Slug is auto-generated from the English name via Sluggable
- Code is auto-generated if not provided (prefix: ALL for all_products, PRO for specific_products)
- `value` and `discount` are always synced (normalized in repository)
- Products/gift products are synced (replaces any existing associations)
- Gift variant must belong to the selected product (validated in repository)
- Images are uploaded to `promotions-desktop` and `promotions-mobile` collections on `promotions` disk
- Activity is logged via `PromotionObserver@created`

---

### GET /api/v1/promotions/{id}

Get a single promotion by ID.

**Authentication**: `auth:sanctum`, permission: `view-promotion`

**Path Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| id | int | Promotion ID |

**Response 200**:
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "name": "Summer Special 20% Off",
    "slug": "summer-special-20-off",
    "type": "Percentage discount",
    "discount_type": "percentage",
    "value": 20,
    "discount": 20,
    "code": "ALLXK8FJ2M",
    "minimum_order_amount": 500,
    "required_quantity": 2,
    "apply_to": "all_products",
    "products": [
      {
        "id": 5,
        "name": "Product Name",
        "slug": "product-name",
        "type": "simple",
        "image": { "thumbnail": "..." },
        "price": 100,
        "sale_price": 80,
        "quantity": 50,
        "sku": "PROD-001",
        "status": "publish"
      }
    ],
    "gift_products": [],
    "image": {
      "desktop": "https://example.com/storage/promotions/desktop.jpg",
      "mobile": "https://example.com/storage/promotions/mobile.jpg"
    },
    "start_at": "2026-07-09T00:00:00+00:00",
    "end_at": "2026-08-28T00:00:00+00:00",
    "status": true,
    "is_valid": true,
    "created_at": "2026-07-19T12:00:00+00:00"
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
curl -X GET "http://example.com/api/v1/promotions/1" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

**Business Rules**:
- Only accepts numeric ID (no slug lookups in admin endpoint)
- Loads related products and gift products via eager loading (when relation is loaded)

---

### PUT /api/v1/promotions/{id}

Update an existing promotion.

**Authentication**: `auth:sanctum`, permission: `update-promotion`

**Path Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| id | int | Promotion ID |

**Request Body** (multipart/form-data):

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| name | object | sometimes | Translatable name |
| image-desktop | file | sometimes | New desktop image (replaces existing) |
| image-mobile | file | sometimes | New mobile image (replaces existing) |
| type | string | sometimes | `price` or `quantity` |
| type_amount | string | sometimes | `fixed_rate`, `percentage`, or `gift` |
| product_ids | array | sometimes | Replaces product associations (prohibited if apply_to=all_products) |
| product_ids.* | int | conditional | Valid product ID |
| gift_product_ids | array | sometimes | Simple gift product IDs (quantity=1, no variant) |
| gift_products | array | sometimes | Detailed gift products with quantity and variant |
| gift_products.*.product_id | int | conditional | Valid product ID |
| gift_products.*.product_variant_id | int | nullable | Valid variant ID |
| gift_products.*.quantity | int | sometimes | Gift quantity |
| discount | float | conditional | Required when type=price and not gift-only |
| max_discount_amount | float | sometimes | Max cap for percentage discounts |
| required_quantity_type | int | sometimes | Min quantity requirement |
| minimum_order_amount | float | conditional | Required when type=price |
| apply_to | string | nullable | `all_products` or `specific_products` |
| limiter | int | nullable | Max usage limit |
| start_at | date | nullable | Promotion start date |
| end_at | date | nullable | Promotion end date |
| status | int | sometimes | 0 or 1 |

**Response 200**:
```json
{
  "status": 200,
  "message": "Promotion updated successfully",
  "success": true,
  "data": {
    "id": 1,
    "name": "Summer Special 25% Off",
    "slug": "summer-special-25-off",
    "discount": 25,
    "status": true,
    "is_valid": true
  }
}
```

**Response 404**:
```json
{ "status": 404, "message": "Not found", "success": false }
```

**Business Rules**:
- Slug is auto-regenerated from English name if name is changed
- Existing images are replaced (old collection is cleared)
- Products array replaces ALL current associations (sync, not attach)
- Gift products are synced (replaces all existing gift associations)
- Activity is logged via `PromotionObserver@updated`

---

### DELETE /api/v1/promotions/{id}

Delete a promotion.

**Authentication**: `auth:sanctum`, permission: `delete-promotion`

**Path Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| id | int | Promotion ID |

**Response 200**:
```json
{
  "status": 200,
  "message": "Promotion deleted successfully",
  "success": true
}
```

**Response 404**:
```json
{ "status": 404, "message": "Not found", "success": false }
```

**Business Rules**:
- Hard deletes the promotion (no soft delete on the model)
- Pivot records in `promotion_product` and `promotion_gift_products` are cascade-deleted (FK ON DELETE CASCADE)
- Activity is logged via `PromotionObserver@deleted`

---

## Public Endpoints

---

### GET /api/v1/general/promotions

List valid promotions with optional filters.

**Authentication**: None (public)

**Query Parameters**:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| limit | int | 10 | Number of promotions to return |
| start_date | date | - | Filter by created_at >= start_date |
| end_date | date | - | Filter by created_at <= end_date |
| promotionsId | string | - | Comma-separated list of promotion IDs |
| slug | string | - | If provided, delegates to getPromotionBySlug (single promotion) |
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
      "name": "Summer Special 20% Off",
      "slug": "summer-special-20-off",
      "status": true,
      "image": {
        "desktop": "https://example.com/storage/promotions/desktop.jpg",
        "mobile": "https://example.com/storage/promotions/mobile.jpg"
      }
    }
  ]
}
```

**Quick Test**:
```bash
# List valid promotions
curl -X GET "http://example.com/api/v1/general/promotions?limit=10" \
  -H "Accept: application/json"

# Filter by specific promotion IDs
curl -X GET "http://example.com/api/v1/general/promotions?promotionsId=1,2,3" \
  -H "Accept: application/json"

# Get single promotion by slug via query param
curl -X GET "http://example.com/api/v1/general/promotions?slug=summer-special-20-off" \
  -H "Accept: application/json"
```

**Business Rules**:
- Only returns valid promotions (status=true, within date range, usage < limiter)
- If `slug` query param is provided, returns a single promotion by slug instead
- Results are ordered by `id` (desc by default)

---

### GET /api/v1/general/promotions/{slug}

Get a single promotion by slug with enriched products.

**Authentication**: None (public)

**Path Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| slug | string | Promotion slug |

**Response 200**:
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "name": "Summer Special 20% Off",
    "slug": "summer-special-20-off",
    "status": true,
    "image": {
      "desktop": "https://example.com/storage/promotions/desktop.jpg",
      "mobile": "https://example.com/storage/promotions/mobile.jpg"
    },
    "products": [
      {
        "id": 5,
        "name": "Product Name",
        "slug": "product-name",
        "image": { "thumbnail": "..." },
        "price": 100,
        "price_after_discount": 80
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
curl -X GET "http://example.com/api/v1/general/promotions/summer-special-20-off" \
  -H "Accept: application/json"
```

**Business Rules**:
- Only returns valid promotions
- Products are enriched with pricing data via `ProductService::enrichCollectionWithPricing()`
- Products are filtered through channel filter (home vs non-home)

---

## Checkout Endpoint

---

### GET /api/v1/checkout/promotions

Get eligible promotions for the current user's cart.

**Authentication**: `auth:sanctum`

**Response 200**:
```json
{
  "eligible_promotions": [
    {
      "id": 1,
      "type": "percentage",
      "title": "Summer Special 20% Off",
      "code": "ALLXK8FJ2M",
      "discount": 20.00,
      "gift_items": []
    }
  ]
}
```

**Business Rules**:
- Only valid promotions are considered (status=true, date range, usage < limiter)
- Eligibility is evaluated against the current user's active cart
- Only promotions matching the cart content (product scope, minimum order, quantity) are returned
- Gift promotions include `gift_items` array with available gift products
- Returns empty array if no promotions are eligible
