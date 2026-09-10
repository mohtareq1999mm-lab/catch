# Tag Module — Frontend Integration Guide

## Endpoints

---

### 1. GET /api/v1/tags — List Tags (Admin)

**Purpose:** Display paginated tag list with language filtering.

**Authentication:** `auth:sanctum`, permission: `view-tags`

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| page | int | 1 | Page number |
| limit | int | 15 | Items per page |
| language | string | en | Language code |

**Response:**
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "data": [
      {
        "id": 1,
        "name": "Organic",
        "slug": "organic",
        "image": null,
        "icon": null
      }
    ],
    "page": 1,
    "current_page": 1,
    "from": 1,
    "to": 15,
    "last_page": 2,
    "path": "http://example.com/api/v1/tags",
    "per_page": 15,
    "total": 20,
    "next_page_url": "http://example.com/api/v1/tags?page=2",
    "prev_page_url": "",
    "last_page_url": "http://example.com/api/v1/tags?page=2",
    "first_page_url": "http://example.com/api/v1/tags?page=1"
  }
}
```

---

### 2. POST /api/v1/tags — Create Tag (Admin)

**Purpose:** Create a new product tag.

**Authentication:** `auth:sanctum`, permission: `create-tags`

**Request Body (multipart/form-data):**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| name | object | required | Translatable name: `{"en": "Organic", "ar": "عضوي"}` |
| products | array | no | Product IDs to attach via `product_tag` relation |
| image | file | no | Tag image file |
| icon | file | no | Tag icon file |

**Response (201):**
```json
{
  "status": 201,
  "message": "Tag created successfully",
  "success": true,
  "data": {
    "id": 1,
    "name": "Organic",
    "slug": "organic",
    "image": null,
    "icon": null,
    "products": []
  }
}
```

---

### 3. GET /api/v1/tags/{id} — Show Tag (Admin)

**Purpose:** Get a single tag by ID.

**Authentication:** `auth:sanctum`, permission: `view-tags`

**Response:**
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "name": "Organic",
    "slug": "organic",
    "image": null,
    "icon": null,
    "products": [
      {
        "id": 1,
        "name": "Product A",
        "slug": "product-a",
        "status": true,
        "image": { "thumbnail": null }
      }
    ]
  }
}
```

---

### 4. PUT /api/v1/tags/{id} — Update Tag (Admin)

**Purpose:** Update an existing tag.

**Authentication:** `auth:sanctum`, permission: `update-tags`

**Request Body (multipart/form-data):**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| name | object | no | Updated translatable name |
| products | array | no | Product IDs — replaces `product_tag` associations (empty array clears) |
| image | file | no | New image (replaces existing) |
| icon | string | no | New icon string |

**Response (200):**
```json
{
  "status": 200,
  "message": "Tag updated successfully",
  "success": true,
  "data": {
    "id": 1,
    "name": "Organic Premium",
    "slug": "organic-premium",
    "image": null,
    "icon": null,
    "products": []
  }
}
```

---

### 5. DELETE /api/v1/tags/{id} — Delete Tag (Admin)

**Purpose:** Delete a tag permanently.

**Authentication:** `auth:sanctum`, permission: `delete-tags`

**Response (200):**
```json
{
  "status": 200,
  "message": "Tag deleted successfully",
  "success": true,
  "data": true
}
```

---

## Frontend States

### Loading States

| Component | Loading State |
|-----------|--------------|
| Tag list | Skeleton rows (3-5 placeholder rows) |
| Tag form (edit) | Skeleton form fields |
| Delete action | Spinner on delete button |

### Empty States

| Component | Empty State |
|-----------|-------------|
| Tag list (no tags exist) | "No tags yet" with "Create your first tag" button |
| Tag list (filtered, no results) | "No tags match your filter" |

### Error States

| Component | Error State |
|-----------|-------------|
| Tag list fetch error | Error message with "Retry" button |
| Tag create error | Toast with error message |
| Tag update error | Toast with error message |
| Tag delete error | Toast with error message |
| Validation error | Inline field errors from 422 response |
| Network error | Toast "Network error, please try again" |

### Edge Cases

| Scenario | Handling |
|----------|----------|
| Duplicate tag name (same locale) | 422 validation error, inline message "The name has already been taken" |
| Non-array name | 422 validation error, inline message |
| Large image upload | Server enforces file type and size validation |
| Delete tag linked to products | Pivot cascade deletes, products remain unaffected |
| Product IDs invalid on create/update | 422 validation error on `products.*` |
| Update with `products: []` | Clears all product associations for the tag |
| Update without `products` | Product associations left untouched |
| Rapid create/delete cycle | Standard CRUD, no rate limiting on tags |
