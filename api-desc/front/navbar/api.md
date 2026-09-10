# API Reference — Navigation Bar

---

### GET /api/v1/general/nav-data

Fetch the hierarchical category tree for the navigation bar. Returns active categories with parent-child relationships up to 3 levels deep.

**Authentication:** None (public)

**Rate Limit:** Subject to global `throttle:api` middleware

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| level | int | 3 | Maximum depth of the category tree (1 = root only, 2 = root + children, 3 = root + children + grandchildren) |

**Response 200:**
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Electronics",
      "slug": "electronics",
      "level": 1,
      "image": {
        "desktop": "https://cdn.example.com/storage/categories/electronics-desktop.jpg",
        "mobile": "https://cdn.example.com/storage/categories/electronics-mobile.jpg"
      },
      "children": [
        {
          "id": 5,
          "name": "Laptops",
          "slug": "laptops",
          "level": 2,
          "image": {
            "desktop": null,
            "mobile": null
          },
          "children": [
            {
              "id": 9,
              "name": "Gaming Laptops",
              "slug": "gaming-laptops",
              "level": 3,
              "image": {
                "desktop": null,
                "mobile": null
              },
              "children": []
            }
          ]
        }
      ]
    }
  ]
}
```

**Error Responses:**

| Status | Condition |
|--------|-----------|
| 400 | Invalid `X-Channel` header value (when strict mode is enabled) |
| 429 | Too many requests (rate limit exceeded) |

**Quick Test:**
```bash
# Fetch full navigation tree (default level=3)
curl -X GET "http://example.com/api/v1/general/nav-data" \
  -H "Accept: application/json"

# Fetch only top-level categories
curl -X GET "http://example.com/api/v1/general/nav-data?level=1" \
  -H "Accept: application/json"

# Fetch with channel header
curl -X GET "http://example.com/api/v1/general/nav-data" \
  -H "Accept: application/json" \
  -H "X-Channel: fast-shipping"
```

**Business Rules:**
- Only categories with `status = 1` (active) are returned
- Soft-deleted categories are excluded
- Categories are ordered by `products_count` descending within each level
- If no active categories exist, an empty array `[]` is returned
- The response is cached for 120 seconds per channel scope
- Category names are returned in the current application locale
- Images (`desktop`, `mobile`) are null if no media is uploaded for that category
