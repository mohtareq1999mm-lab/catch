# API Reference — Coupon

---

## Admin Endpoints

---

### GET /api/v1/coupons

Paginated list of coupons with filtering and sorting.

**Authentication**: `auth:sanctum`, permission: `view-coupons`

**Query Parameters**:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| page | int | 1 | Page number |
| limit | int | 15 | Items per page |
| search | string | - | Search by code or name (LIKE) |
| status | bool | - | Filter by status (true = active, false = inactive) |
| valid | string | - | Filter by validity scope (`valid` or `invalid`) |
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
        "code": "SUMMER20",
        "name": "Summer 20% Off",
        "slug": "summer-20-off",
        "discount": 20,
        "discount_type": "percentage",
        "max_discount_amount": 50,
        "image": {
          "desktop": "https://example.com/storage/coupons/desktop.jpg",
          "mobile": "https://example.com/storage/coupons/mobile.jpg"
        },
        "border_color": "#FF0000",
        "borderless": false,
        "start_date": "2026-07-01",
        "end_date": "2026-08-31",
        "limiter": 1000,
        "used": 50,
        "status": true,
        "is_valid": true,
        "is_assigned": false,
        "assignments": [],
        "created_at": "2026-07-01T12:00:00+00:00"
      }
    ],
    "page": 1,
    "current_page": 1,
    "from": 1,
    "to": 15,
    "last_page": 2,
    "path": "http://example.com/api/v1/coupons",
    "per_page": 15,
    "total": 20,
    "next_page_url": "http://example.com/api/v1/coupons?page=2",
    "prev_page_url": "",
    "last_page_url": "http://example.com/api/v1/coupons?page=2",
    "first_page_url": "http://example.com/api/v1/coupons?page=1"
  }
}
```

**Quick Test**:
```bash
# List all coupons
curl -X GET "http://example.com/api/v1/coupons?page=1&limit=15" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"

# Search coupons
curl -X GET "http://example.com/api/v1/coupons?search=SUMMER" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"

# Filter by status
curl -X GET "http://example.com/api/v1/coupons?status=1" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

**Business Rules**:
- Results are ordered by `updated_at desc` by default (global scope on model)
- Search applies to `code` and `name` fields with LIKE operator
- Coupons with `status=false` are still returned (unlike `valid()` scope which filters them out)

---

### POST /api/v1/coupons

Create a new coupon.

**Authentication**: `auth:sanctum`, permission: `create-coupon`

**Request Body** (multipart/form-data):

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| name | object | required | Translatable name (e.g., `{"en": "Summer Sale", "ar": "تخفيضات الصيف"}`) |
| image-desktop | file | required | Desktop image (jpeg,png,jpg,webp) |
| image-mobile | file | required | Mobile image (jpeg,png,jpg,webp) |
| discount | float | required | Discount value. Min: 0 |
| discount_type | string | required | `fixed_rate`, `percentage`, or `free_shipping` |
| max_discount_amount | float | conditional | Required when discount_type=percentage. Min: 1 |
| product_ids | array | sometimes | Array of product IDs the coupon applies to (empty = all products) |
| product_ids.* | int | conditional | Valid product ID |
| start_date | date | required | Coupon start date (format: Y-m-d) |
| end_date | date | required | Coupon end date (format: Y-m-d, after_or_equal:start_date) |
| limiter | int | sometimes | Max usage limit. Min: 0 |
| status | int | sometimes | 0 or 1 |
| border_color | string | sometimes | Hex color code. Max: 50 |
| borderless | int | sometimes | 0 or 1 |

**Validation Rules**:
| Field | Rules |
|-------|-------|
| name | required, array |
| name.* | required_with:name, unique_translation:coupons,name |
| image-desktop | required, image, mimes:jpeg,png,jpg,webp |
| image-mobile | required, image, mimes:jpeg,png,jpg,webp |
| discount | required, numeric, min:0 |
| discount_type | required, in:fixed_rate,percentage,free_shipping |
| max_discount_amount | required_if:discount_type,percentage, numeric, min:1 |
| product_ids | sometimes, array |
| product_ids.* | exists:products,id |
| start_date | required, date_format:Y-m-d |
| end_date | required, date_format:Y-m-d, after_or_equal:start_date |
| limiter | nullable, integer, min:0 |
| status | sometimes, in:1,0 |
| border_color | nullable, string, max:50 |
| borderless | sometimes, in:1,0 |

**Response 201**:
```json
{
  "status": 201,
  "message": "Coupon created successfully",
  "success": true,
  "data": {
    "id": 21,
    "code": "COUPON_X7K9M2A",
    "name": "Summer Sale",
    "slug": "summer-sale",
    "discount": 20,
    "discount_type": "percentage",
    "max_discount_amount": 50,
    "image": {
      "desktop": "https://example.com/storage/coupons/desktop.jpg",
      "mobile": "https://example.com/storage/coupons/mobile.jpg"
    },
    "start_date": "2026-07-01",
    "end_date": "2026-08-31",
    "limiter": 1000,
    "used": 0,
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
  "discount_type": ["The selected discount type is invalid."]
}
```

**Business Rules**:
- Code is auto-generated on creating with prefix `coupon_` + 7 random uppercase characters (`generateUniqueCode()`)
- If `product_ids` is empty, the coupon applies to all products (no restriction)
- `is_approve` defaults to false (requires super admin approval)
- Images are uploaded to `coupons-desktop` and `coupons-mobile` collections via MediaManager trait
- Activity is logged via `CouponObserver@created`

---

### GET /api/v1/coupons/{id}

Get a single coupon by ID or code.

**Authentication**: `auth:sanctum`, permission: `view-coupons`

**Path Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| id | int/string | Coupon ID or code |

**Response 200**:
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "code": "SUMMER20",
    "name": "Summer 20% Off",
    "slug": "summer-20-off",
    "discount": 20,
    "discount_type": "percentage",
    "max_discount_amount": 50,
    "image": {
      "desktop": "https://example.com/storage/coupons/desktop.jpg",
      "mobile": "https://example.com/storage/coupons/mobile.jpg"
    },
    "border_color": "#FF0000",
    "borderless": false,
    "start_date": "2026-07-01",
    "end_date": "2026-08-31",
    "limiter": 1000,
    "used": 50,
    "status": true,
    "is_valid": true,
    "is_assigned": false,
    "assignments": [],
    "created_at": "2026-07-01T12:00:00+00:00"
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
curl -X GET "http://example.com/api/v1/coupons/1" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"

curl -X GET "http://example.com/api/v1/coupons/SUMMER20" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

**Business Rules**:
- Accepts both numeric ID and code string (auto-detected)
- Loads assignments relation when available
- `is_valid` is computed via `CouponValidator::validate()` (checks status, dates, limiter)
- `is_assigned` checks if assignments relation is non-empty

---

### PUT /api/v1/coupons/{id}

Update an existing coupon.

**Authentication**: `auth:sanctum`, permission: `update-coupon`

**Path Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| id | int | Coupon ID |

**Request Body** (multipart/form-data):

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| name | object | sometimes | Translatable name |
| image-desktop | file | sometimes | New desktop image (replaces existing) |
| image-mobile | file | sometimes | New mobile image (replaces existing) |
| discount | float | sometimes | Discount value |
| discount_type | string | sometimes | `fixed_rate`, `percentage`, or `free_shipping` |
| max_discount_amount | float | sometimes | Max cap for percentage discounts |
| product_ids | array | sometimes | Replaces product associations |
| product_ids.* | int | conditional | Valid product ID |
| start_date | date | sometimes | Coupon start date |
| end_date | date | sometimes | Coupon end date |
| limiter | int | nullable | Max usage limit |
| status | int | sometimes | 0 or 1 |
| border_color | string | nullable | Hex color |
| borderless | int | sometimes | 0 or 1 |

**Response 200**:
```json
{
  "status": 200,
  "message": "Coupon updated successfully",
  "success": true,
  "data": {
    "id": 1,
    "code": "SUMMER20",
    "name": "Summer 25% Off",
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
- Existing images are replaced (old collection is cleared)
- Products array replaces ALL current associations (sync, not attach)
- Activity is logged via `CouponObserver@updated`

---

### DELETE /api/v1/coupons/{id}

Delete a coupon.

**Authentication**: `auth:sanctum`, permission: `delete-coupon`

**Path Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| id | int | Coupon ID |

**Response 200**:
```json
{
  "status": 200,
  "message": "Coupon deleted successfully",
  "success": true
}
```

**Response 404**:
```json
{ "status": 404, "message": "Not found", "success": false }
```

**Business Rules**:
- Hard deletes the coupon (no soft delete)
- Pivot records in `coupon_product` are cascade-deleted (FK ON DELETE CASCADE)
- Coupon usages and assignments are cascade-deleted
- Activity is logged via `CouponObserver@deleted`

---

### POST /api/v1/coupons/add-to-cart

Apply a coupon code to the current user's cart.

**Authentication**: `auth:sanctum`

**Request Body**:

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| code | string | required | Coupon code |

**Response 200**:
```json
{
  "status": 200,
  "message": "Coupon applied successfully",
  "success": true,
  "data": {
    "coupon": {
      "id": 1,
      "code": "SUMMER20",
      "discount": 20,
      "discount_type": "percentage",
      "max_discount_amount": 50
    },
    "discount_amount": 40.00
  }
}
```

**Business Rules**:
- Validates via `CouponOrchestrator::validateByCode()`:
  - Checks assignments (CouponAssignmentValidator)
  - Checks status, dates, limiter, already_used, product restrictions (CouponValidator)
- Calculates discount via `CouponCalculator::calculate()`
- Stores coupon code on the user's active cart

---

## Public Endpoints

---

### GET /api/v1/general/coupons

List valid coupons.

**Authentication**: None (public)

**Query Parameters**:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| limit | int | 10 | Number of coupons to return |
| start_date | date | - | Filter by created_at >= start_date |
| end_date | date | - | Filter by created_at <= end_date |
| id | string | - | Comma-separated list of coupon IDs |
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
      "name": "Summer 20% Off",
      "slug": "summer-20-off",
      "image": {
        "desktop": "https://example.com/storage/coupons/desktop.jpg",
        "mobile": "https://example.com/storage/coupons/mobile.jpg"
      },
      "borderColor": "#FF0000",
      "borderless": false
    }
  ]
}
```

**Quick Test**:
```bash
# List valid coupons
curl -X GET "http://example.com/api/v1/general/coupons?limit=10" \
  -H "Accept: application/json"

# Filter by specific coupon IDs
curl -X GET "http://example.com/api/v1/general/coupons?id=1,2,3" \
  -H "Accept: application/json"
```

**Business Rules**:
- Only returns valid coupons (status=true, within date range, usage < limiter, approved)
- Results are ordered by `id` (desc by default)

---

### POST /api/v1/general/coupons/apply

Apply a coupon code to the current user's cart (public API variant).

**Authentication**: `auth:sanctum`

**Request Body**:

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| coupon_code | string | required | Coupon code |

**Response 200**:
```json
{
  "status": 200,
  "message": "Coupon applied successfully",
  "success": true,
  "data": {
    "coupon": {
      "id": 1,
      "code": "SUMMER20",
      "discount": 20,
      "discount_type": "percentage"
    },
    "discount_amount": 40.00
  }
}
```

**Response 400** (already applied):
```json
{
  "status": 400,
  "message": "Coupon already applied",
  "success": false
}
```

**Business Rules**:
- Same validation flow as admin `add-to-cart` endpoint
- Checks for already_applied state before applying
- Replaces existing coupon on cart
