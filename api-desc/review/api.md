# API Reference — Review

---

## Endpoints

| Method | Endpoint | Auth | Permission | Rate Limited |
|--------|----------|------|------------|-------------|
| GET | `/api/v1/reviews` | Sanctum | — | — |
| POST | `/api/v1/reviews` | Sanctum | — | 5/min |
| GET | `/api/v1/reviews/{id}` | Sanctum | — | — |
| PUT | `/api/v1/reviews/{id}` | Sanctum | — | 5/min |
| DELETE | `/api/v1/reviews/{id}` | Sanctum | `delete-reviews` | — |
| PATCH | `/api/v1/reviews/{id}/toggle-approve` | Sanctum | `approve-reviews` | — |

---

### GET /api/v1/reviews

Paginated list of reviews for a specific product.

**Authentication**: `auth:sanctum`

**Query Parameters**:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| product_id | int | required | Product ID to filter reviews |
| limit | int | 15 | Items per page |
| page | int | 1 | Page number |

**Response 200**:
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": [
    {
      "id": 1,
      "rating": 5,
      "comment": "Great product!",
      "images": [],
      "is_approved": true
    }
  ]
}
```

**Response 422** (missing product_id):
```json
{
  "product_id": ["The product id field is required."]
}
```

**Quick Test**:
```bash
curl -X GET "http://example.com/api/v1/reviews?product_id=1&limit=15&page=1" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

**Business Rules**:
- `product_id` query parameter is **required** — returns 422 if missing
- Results are paginated with `limit` (default 15)
- `is_approved` field is only visible when the authenticated user has `approve-reviews` permission

---

### POST /api/v1/reviews

Create a new review for a product.

**Authentication**: `auth:sanctum`

**Rate Limited**: 5 requests per minute per user (`throttle:content`)

**Request Body**:

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| product_id | int | required | Product ID (must exist) |
| comment | string | required | Review comment text |
| rating | int | required | Rating 1-5 |

**Validation Rules**:
| Field | Rules |
|-------|-------|
| product_id | required, exists:products,id |
| comment | required, string |
| rating | required, integer, min:1, max:5 |

**Request Body (JSON)**:
```json
{
  "product_id": 10,
  "comment": "Great product, highly recommended!",
  "rating": 5
}
```

**Response 200**:
```json
{
  "status": 200,
  "message": "Review created successfully",
  "success": true,
  "data": {
    "id": 42,
    "rating": 5,
    "comment": "Great product, highly recommended!",
    "images": [],
    "is_approved": false
  }
}
```

**Response 400** (already reviewed):
```json
{
  "status": 400,
  "message": "Already given review for this product",
  "success": false
}
```

**Response 422** (validation):
```json
{
  "rating": ["The rating must be between 1 and 5."]
}
```

**Quick Test**:
```bash
curl -X POST "http://example.com/api/v1/reviews" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"product_id": 10, "comment": "Great product!", "rating": 5}'
```

**Business Rules**:
- `user_id` is automatically set from the authenticated user
- Duplicate reviews for the same product by the same user will throw `ALREADY_GIVEN_REVIEW_FOR_THIS_PRODUCT` (400)
- The `ReviewCreated` event is defined but commented out in the repository
- New reviews have `approved = false` by default (from migration default)
- Rate limited to 5 requests per minute

---

### GET /api/v1/reviews/{id}

Get a single review by ID.

**Authentication**: `auth:sanctum`

**Path Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| id | int | Review ID |

**Response 200**:
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "rating": 5,
    "comment": "Great product!",
    "images": [],
    "is_approved": true
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
curl -X GET "http://example.com/api/v1/reviews/1" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

---

### PUT /api/v1/reviews/{id}

Update an existing review.

**Authentication**: `auth:sanctum`

**Rate Limited**: 5 requests per minute per user (`throttle:content`)

**Path Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| id | int | Review ID |

**Request Body**:

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| comment | string | required | Updated comment |
| rating | int | required | Updated rating 1-5 |

**Validation Rules**:
| Field | Rules |
|-------|-------|
| comment | required, string |
| rating | required, integer, min:1, max:5 |

**Request Body (JSON)**:
```json
{
  "comment": "Updated review comment",
  "rating": 4
}
```

**Response 200**:
```json
{
  "status": 200,
  "message": "Review updated successfully",
  "success": true,
  "data": {
    "id": 1,
    "rating": 4,
    "comment": "Updated review comment",
    "images": [],
    "is_approved": false
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
curl -X PUT "http://example.com/api/v1/reviews/1" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"comment": "Updated comment", "rating": 4}'
```

**Business Rules**:
- `product_id` cannot be updated — only `comment` and `rating` are modifiable
- Rate limited to 5 requests per minute

---

### DELETE /api/v1/reviews/{id}

Soft-delete a review.

**Authentication**: `auth:sanctum`, permission: `delete-reviews`

**Path Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| id | int | Review ID |

**Response 200**:
```json
{
  "status": 200,
  "message": "Review deleted successfully",
  "success": true
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
curl -X DELETE "http://example.com/api/v1/reviews/1" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

**Business Rules**:
- Uses soft deletes (sets `deleted_at`, does not remove the row)
- User must have `delete-reviews` permission
- Related feedbacks and abusive reports are preserved (morphMany, not cascade)

---

### PATCH /api/v1/reviews/{id}/toggle-approve

Toggle the approved status of a review.

**Authentication**: `auth:sanctum`, permission: `approve-reviews`

**Path Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| id | int | Review ID |

**Response 200**:
```json
{
  "status": 200,
  "message": "Review updated successfully",
  "success": true,
  "data": {
    "id": 1,
    "rating": 5,
    "comment": "Great product!",
    "images": [],
    "is_approved": true
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
curl -X PATCH "http://example.com/api/v1/reviews/1/toggle-approve" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

**Business Rules**:
- Toggles the `approved` boolean field (true → false or false → true)
- User must have `approve-reviews` permission
- No request body required
