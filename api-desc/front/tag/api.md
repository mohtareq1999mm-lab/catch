# API Reference — Tag Module (Public API)

---

### GET /api/v1/general/tags

List all product tags.

**Authentication:** None (public)

**Rate Limit:** Subject to global `throttle:api`

**Query Parameters:** None (returns all tags)

**Response 200:**
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Organic",
      "slug": "organic",
      "details": "Fresh organic products",
      "image": null,
      "icon": "organic-icon",
    }
  ]
}
```

**Quick Test:**
```bash
curl -X GET "http://example.com/api/v1/general/tags" \
  -H "Accept: application/json"
```

---

### GET /api/v1/general/tags/{slug}

Get a single tag by slug.

**Authentication:** None (public)

**Response 200:**
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "name": "Organic",
    "slug": "organic",
    "details": "Fresh organic products",
    "image": null,
    "icon": "organic-icon",
  }
}
```

**Response 404:**
```json
{
  "status": 404,
  "message": "Data not found",
  "success": false
}
```

**Quick Test:**
```bash
# Get tag by slug
curl -X GET "http://example.com/api/v1/general/tags/organic" \
  -H "Accept: application/json"

# Non-existent tag
curl -X GET "http://example.com/api/v1/general/tags/nonexistent" \
  -H "Accept: application/json"
```
