# API Reference — Attribute

---

## CRUD Endpoints

---

### GET /api/v1/attributes

Paginated list of attributes with values.

**Authentication**: Controller middleware, permission: `view-attributes`

**Query Parameters**:

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| page | int | 1 | Page number |
| limit | int | 15 | Items per page |
| search | string | - | Search by name (LIKE, translatable) |
| order | string | - | Sort column (id, name, slug, created_at, updated_at) |
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
        "name": "Size",
        "slug": "size",
        "values": [
          { "id": 1, "value": "Small", "slug": "small" },
          { "id": 2, "value": "Large", "slug": "large" }
        ]
      }
    ],
    "page": 1,
    "current_page": 1,
    "from": 1,
    "to": 15,
    "last_page": 1,
    "path": "http://example.com/api/v1/attributes",
    "per_page": 15,
    "total": 2,
    "next_page_url": "",
    "prev_page_url": "",
    "last_page_url": "http://example.com/api/v1/attributes?page=1",
    "first_page_url": "http://example.com/api/v1/attributes?page=1"
  }
}
```

**Quick Test**:
```bash
curl -X GET "http://example.com/api/v1/attributes?page=1&limit=15" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"

curl -X GET "http://example.com/api/v1/attributes?search=size" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

**Business Rules**:
- Values eager-loaded via `with('values')`
- Name returned translated for index
- Search applies to translatable `name` field

---

### POST /api/v1/attributes

Create a new attribute with optional values.

**Authentication**: Controller middleware, permission: `create-attribute`

**Request Body**:

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| name | object | required | `{"en": "Size", "ar": "حجم"}` |
| name.en | string | required | min:2, max:50, unique |
| name.ar | string | required | min:2, max:50, unique |
| values | array | sometimes | Array of value objects |
| values.*.value | object | required | Translatable value |
| values.*.value.en | string | required | min:2, max:50 |
| values.*.value.ar | string | required | min:2, max:50 |

**Request Body (JSON)**:
```json
{
  "name": { "en": "Size", "ar": "حجم" },
  "values": [
    { "value": { "en": "Small", "ar": "صغير" } },
    { "value": { "en": "Large", "ar": "كبير" } }
  ]
}
```

**Response 201**:
```json
{
  "status": 201,
  "message": "Attribute created successfully",
  "success": true,
  "data": {
    "id": 1,
    "name": "Size",
    "slug": "size",
    "values": [
      { "id": 1, "value": "Small", "slug": "small" },
      { "id": 2, "value": "Large", "slug": "large" }
    ]
  }
}
```

**Response 422**:
```json
{
  "name.en": ["The name en has already been taken."],
  "name.ar": ["The name ar field is required."]
}
```

**Quick Test**:
```bash
curl -X POST "http://example.com/api/v1/attributes" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"name": {"en": "Color", "ar": "لون"}}'
```

**Business Rules**:
- Slug auto-generated from English name via Sluggable
- Values created in DB transaction — failure rolls back everything
- Both `name.en` and `name.ar` individually required

---

### GET /api/v1/attributes/{id}

Get attribute by ID or slug with values.

**Authentication**: Controller middleware, permission: `view-attributes`

**Path Parameters**: `id` (int/string) — ID or slug

**Response 200**:
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "name": "Size",
    "slug": "size",
    "values": [
      { "id": 1, "value": "Small", "slug": "small" }
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
curl -X GET "http://example.com/api/v1/attributes/1" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"

curl -X GET "http://example.com/api/v1/attributes/size" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

**Business Rules**:
- Accepts both numeric ID and string slug
- Name returned raw (JSON object) for show

---

### PUT /api/v1/attributes/{id}

Update attribute name and/or sync values.

**Authentication**: Controller middleware, permission: `update-attribute`

**Request Body**:

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| name | object | required | Translatable name |
| name.en | string | required | unique (ignores current) |
| name.ar | string | required | unique (ignores current) |
| values | array | sometimes | Replaces existing values |

**Request Body (JSON)**:
```json
{
  "name": { "en": "Dimensions", "ar": "أبعاد" },
  "values": [
    { "value": { "en": "10cm", "ar": "10 سم" } }
  ]
}
```

**Response 200**:
```json
{
  "status": 200,
  "message": "Attribute updated successfully",
  "success": true,
  "data": {
    "id": 1,
    "name": "Dimensions",
    "slug": "dimensions",
    "values": [
      { "id": 3, "value": "10cm", "slug": "10cm" }
    ]
  }
}
```

**Quick Test**:
```bash
curl -X PUT "http://example.com/api/v1/attributes/1" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"name": {"en": "Size", "ar": "حجم"}, "values": [{"value": {"en": "Small", "ar": "صغير"}}]}'
```

**Business Rules**:
- Slug regenerated from English name
- Values synced: new created, missing deleted, matching slugs preserved
- Preserved values retain their ID and pivot associations

---

### DELETE /api/v1/attributes/{id}

Hard-delete attribute. Cascades to values and pivot records.

**Authentication**: Controller middleware, permission: `delete-attribute`

**Response 200**:
```json
{
  "status": 200,
  "message": "Attribute deleted successfully",
  "success": true
}
```

**Response 404**:
```json
{ "status": 404, "message": "Not found", "success": false }
```

**Quick Test**:
```bash
curl -X DELETE "http://example.com/api/v1/attributes/1" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json"
```

**Business Rules**:
- Hard delete (no soft deletes)
- FK CASCADE removes all `attribute_values` and `attribute_product` records
- Irreversible — all variant associations lost
