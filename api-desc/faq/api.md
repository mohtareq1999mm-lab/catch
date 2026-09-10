# API Reference — FAQ

---

## Admin Endpoints

---

### GET /api/v1/faqs

Paginated list of FAQs with ordering and shop scoping.

**Authentication**: `auth:sanctum`, permission: `view-faqs`

**Query Parameters**:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| page | int | 1 | Page number |
| limit | int | 10 | Items per page |
| order | string | - | Sort column (id, faq_title, status, created_at, updated_at) |
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
        "faq_title": "How to return a product?",
        "faq_description": "You can return any product within 30 days of purchase.",
        "status": 1,
        "order": 0
      }
    ],
    "page": 1,
    "current_page": 1,
    "from": 1,
    "to": 15,
    "last_page": 2,
    "path": "http://example.com/api/v1/faqs",
    "per_page": 10,
    "total": 20,
    "next_page_url": "http://example.com/api/v1/faqs?page=2",
    "prev_page_url": "",
    "last_page_url": "http://example.com/api/v1/faqs?page=2",
    "first_page_url": "http://example.com/api/v1/faqs?page=1"
  }
}
```

**Quick Test**:
```bash
# List all FAQs
curl -X GET "http://example.com/api/v1/faqs?page=1&limit=10" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"

# Sort by title ascending
curl -X GET "http://example.com/api/v1/faqs?order=faq_title&sortedBy=asc" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

**Business Rules**:
- Results on `faqs.index` return translated strings for the current locale (not raw JSON)
- All authenticated users see all FAQs (role-based scoping removed — shop_id/user_id columns don't exist in migration)

---

### POST /api/v1/faqs

Create a new FAQ.

**Authentication**: `auth:sanctum`, permission: `create-faq`

**Request Body** (JSON):

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| faq_title | object | required | Translatable title (e.g., `{"en": "How to return?", "ar": "كيفية الإرجاع"}`) |
| faq_title.* | string | required | Per-locale title (min:3, max:1000, unique per locale) |
| faq_description | object | required | Translatable description (e.g., `{"en": "Details...", "ar": "التفاصيل..."}`) |
| faq_description.* | string | required | Per-locale description (min:3, max:1000) |
| status | int | sometimes | 0 or 1 (defaults to 1) |

**Validation Rules**:
| Field | Rules |
|-------|-------|
| faq_title | required, array |
| faq_title.* | required, string, min:3, max:1000, unique_translation:faqs |
| faq_description | required, array |
| faq_description.* | required, string, min:3, max:1000 |
| status | sometimes, in:0,1 |

**Response 201**:
```json
{
  "status": 201,
  "message": "FAQ created successfully",
  "success": true,
  "data": {
    "id": 51,
    "faq_title": {
      "en": "How to return a product?",
      "ar": "كيفية إرجاع المنتج؟"
    },
    "faq_description": {
      "en": "You can return any product within 30 days.",
      "ar": "يمكنك إرجاع أي منتج خلال 30 يومًا."
    },
    "status": 1,
    "order": 5
  }
}
```

**Response 422** (validation):
```json
{
  "faq_title.en": ["The faq title.en has already been taken."],
  "faq_description": ["The faq description field is required."]
}
```

**Business Rules**:
- `order` is auto-set on creation via SortableTrait (`sort_when_creating: true`)
- `status` defaults to `true` (active)
- No images, no media uploads

---

### GET /api/v1/faqs/{id}

Get a single FAQ by ID.

**Authentication**: `auth:sanctum`, permission: `view-faqs`

**Path Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| id | int | FAQ ID |

**Response 200**:
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "faq_title": {
      "en": "How to return a product?",
      "ar": "كيفية إرجاع المنتج؟"
    },
    "faq_description": {
      "en": "You can return any product within 30 days.",
      "ar": "يمكنك إرجاع أي منتج خلال 30 يومًا."
    },
    "status": 1,
    "order": 0
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
curl -X GET "http://example.com/api/v1/faqs/1" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

**Business Rules**:
- On `show`, returns raw JSON with all locales (not translated strings like index)
- Respects soft deletes — returns 404 for soft-deleted FAQs
- Soft-deleted FAQs are excluded from index

---

### PUT /api/v1/faqs/{id}

Update an existing FAQ.

**Authentication**: `auth:sanctum`, permission: `update-faq`

**Path Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| id | int | FAQ ID |

**Request Body** (JSON):

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| faq_title | object | sometimes | Translatable title |
| faq_title.* | string | sometimes | Per-locale title (min:3, max:1000, unique per locale ignoring self) |
| faq_description | object | sometimes | Translatable description |
| faq_description.* | string | sometimes | Per-locale description (min:3, max:1000) |
| status | int | sometimes | 0 or 1 |

**Response 200**:
```json
{
  "status": 200,
  "message": "FAQ updated successfully",
  "success": true,
  "data": {
    "id": 1,
    "faq_title": {
      "en": "How to return a product? (Updated)",
      "ar": "كيفية إرجاع المنتج؟ (مُحدّث)"
    },
    "faq_description": {
      "en": "Updated description.",
      "ar": "الوصف المُحدّث."
    },
    "status": 1,
    "order": 0
  }
}
```

**Response 404**:
```json
{ "status": 404, "message": "Not found", "success": false }
```

**Business Rules**:
- All fields are optional on update
- `faq_title.*` unique check uses `->ignore($id)` to exclude the current record
- Soft-deleted FAQs cannot be updated (404)

---

### DELETE /api/v1/faqs/{id}

Soft delete a FAQ.

**Authentication**: `auth:sanctum`, permission: `delete-faq`

**Path Parameters**:

| Parameter | Type | Description |
|-----------|------|-------------|
| id | int | FAQ ID |

**Response 200**:
```json
{
  "status": 200,
  "message": "FAQ deleted successfully",
  "success": true
}
```

**Response 404**:
```json
{ "status": 404, "message": "Not found", "success": false }
```

**Business Rules**:
- Uses soft deletes — record is not removed from database, only `deleted_at` is set
- Soft-deleted FAQs are excluded from index and show returns 404
- Force delete requires direct database interaction (no endpoint)

---

### PUT /api/v1/faqs/reorder

Reorder FAQs by providing a sorted array of IDs.

**Authentication**: `auth:sanctum`, permission: `update-faq`

**Request Body** (JSON):

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| faqs | array | required | Array of FAQ IDs in desired order |
| faqs.* | int | required | Valid FAQ ID (must exist in faqs table) |

**Response 200**:
```json
{
  "status": 200,
  "message": "FAQs reordered successfully",
  "success": true
}
```

**Business Rules**:
- Uses Spatie Sortable `setNewOrder()` to update the `order` column
- All provided IDs must exist in the `faqs` table (validated by `exists:faqs,id`)
- Order reflects the array index position

---

## Public Endpoints

---

### GET /api/v1/general/faqs

List all active FAQs (no authentication required).

**Authentication**: None (public)

**Response 200**:
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": [
    {
      "id": 1,
      "faq_title": "How to return a product?",
      "faq_description": "You can return any product within 30 days.",
      "status": 1,
      "order": 0
    }
  ]
}
```

**Quick Test**:
```bash
curl -X GET "http://example.com/api/v1/general/faqs" \
  -H "Accept: application/json"
```

**Business Rules**:
- Only returns FAQs with `status = 1` (active)
- Returns translated strings for the current locale
- No pagination — returns all active FAQs
- No authentication required
