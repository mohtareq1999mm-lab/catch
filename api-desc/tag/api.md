# API Reference — Tag

---

## Endpoints

---

### GET /api/v1/tags

Paginated list of tags with language filtering.

**Authentication**: `auth:sanctum`, permission: `view-tags`

**Query Parameters**:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| page | int | 1 | Page number |
| limit | int | 15 | Items per page |
| language | string | en | Language code (e.g., en, ar) |

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

**Quick Test**:
```bash
# List all tags (page 1, 15 per page)
curl -X GET "http://example.com/api/v1/tags?page=1&limit=15" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"

# Filter by language
curl -X GET "http://example.com/api/v1/tags?language=en" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

**Business Rules**:
- Filters by language (`$request->language ?? DEFAULT_LANGUAGE`)
- Returns custom pagination structure (not default Laravel pagination)

---

### POST /api/v1/tags

Create a new tag.

**Authentication**: `auth:sanctum`, permission: `create-tags`

**Request Body** (multipart/form-data):

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| name | object | required | Translatable name (e.g., `{"en": "Organic", "ar": "عضوي"}`) |
| products | array | optional | Product IDs to attach via `product_tag` relation |
| image | file | sometimes | Tag image (jpeg,png,jpg,gif,svg) |
| icon | file | sometimes | Tag icon (jpeg,png,jpg,gif,svg) |

**Validation Rules**:

| Field | Rules |
|-------|-------|
| name | required, array |
| name.* | required, string, max:150, unique_translation:tags |
| products | nullable, array |
| products.* | integer, exists:products,id |
| image | nullable, image |
| icon | nullable, string |

**Request Body (JSON)**:
```json
{
  "name": {
    "en": "Organic",
    "ar": "عضوي"
  },
  "products": [1, 2, 3]
}
```

> **Note:** `image` and `icon` are file fields — they must be sent as `multipart/form-data`, not included in the JSON body.

**Response 201**:
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

**Response 422** (validation):
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "name.en": ["The name en has already been taken."],
    "products.0": ["The selected products.0 is invalid."]
  }
}
```

**Quick Test**:
```bash
curl -X POST "http://example.com/api/v1/tags" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"name": {"en": "Organic", "ar": "عضوي"}, "products": [1, 2, 3]}'
```

**Business Rules**:
- Slug is auto-generated from the name via `makeSlug()` (uses `globalSlugify`)
- If name is a translatable array, the English (`en`) value is used for slug generation
- If `products` is provided, the `product_tag` pivot is synced (`sync()`) — replaces any existing product associations
- Image is uploaded to `tags` collection on `tags` disk via `MediaManager::uploadSingleImage()`
- Icon is uploaded to `tags` collection on `tags` disk via `MediaManager::uploadSingleImage()`
- Response wraps the tag in the standard `{ status, message, success, data }` envelope

---

### GET /api/v1/tags/{id}

Get a single tag by ID.

**Authentication**: `auth:sanctum`, permission: `view-tags`

**Path Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| id | int | Tag ID |

**Response 200**:
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
curl -X GET "http://example.com/api/v1/tags/1" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

**Business Rules**:
- Accepts numeric ID. If numeric, looks up by `id`.
- If `$params` is non-numeric (string/slug), looks up by `slug` + `language`.
- Eager-loads `products` relationship (exposed in the response).
- Returns the tag wrapped in `apiResponse` (`{ status, message, success, data }`).

---

### PUT /api/v1/tags/{id}

Update an existing tag.

**Authentication**: `auth:sanctum`, permission: `update-tags`

**Path Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| id | int | Tag ID |

**Request Body** (multipart/form-data):

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| name | object | sometimes | Translatable name |
| products | array | optional | Product IDs — syncs `product_tag` relation (replaces existing) |
| image | file | sometimes | New tag image (replaces existing) |
| icon | string | sometimes | New icon string |

**Validation Rules**:

| Field | Rules |
|-------|-------|
| name | sometimes, array |
| name.* | sometimes, string, max:150, unique_translation:tags ->ignore($id) |
| products | nullable, array |
| products.* | integer, exists:products,id |
| image | nullable, image |
| icon | nullable, string |

**Request Body (JSON)**:
```json
{
  "name": {
    "en": "Organic Premium",
    "ar": "عضوي ممتاز"
  },
  "products": [2, 3]
}
```

**Response 200**:
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
    "products": [
      {
        "id": 2,
        "name": "Product B",
        "slug": "product-b",
        "status": true,
        "image": { "thumbnail": null }
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
curl -X PUT "http://example.com/api/v1/tags/1" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"name": {"en": "Organic Premium"}, "products": [2, 3]}'
```

**Business Rules**:
- Slug is auto-regenerated from English name if name is changed
- Uniqueness check ignores the current tag's own name
- If `products` is provided, the `product_tag` pivot is synced (`sync()`) — replaces all existing product associations
- Sending `products: []` clears all product associations
- Existing images are replaced (old collection is cleared via `updateSingleImage`)
- Returns the updated tag wrapped in `apiResponse` (`{ status, message, success, data }`)

---

### DELETE /api/v1/tags/{id}

Delete a tag.

**Authentication**: `auth:sanctum`, permission: `delete-tags`

**Path Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| id | int | Tag ID |

**Response 200**:
```json
{
  "status": 200,
  "message": "Tag deleted successfully",
  "success": true,
  "data": true
}
```

**Response 404**:
```json
{ "status": 404, "message": "Not found", "success": false }
```

**Quick Test**:
```bash
curl -X DELETE "http://example.com/api/v1/tags/1" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

**Business Rules**:
- Uses hard delete (no soft deletes on `tags` table)
- Pivot records in `product_tag` table are cascade-deleted
- Media files are NOT cleaned up on delete
- Returns `{ status, message, success, data: true }` wrapped via `apiResponse`
