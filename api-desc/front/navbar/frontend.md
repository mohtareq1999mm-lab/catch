# Navigation Bar — Frontend Integration Guide

## Endpoint

### GET /api/v1/general/nav-data — Navigation Category Tree (Public)

**Purpose:** Fetch the hierarchical category tree for rendering the main navigation bar, mega-menu, and sidebar navigation.

**Authentication:** None (public)

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| level | int | 3 | Maximum depth of children to return (1 = parent categories only, 2 = parents + direct children, 3 = up to grandchildren) |

**Response:**
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
        "desktop": "https://cdn.example.com/categories/electronics-desktop.jpg",
        "mobile": "https://cdn.example.com/categories/electronics-mobile.jpg"
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

## Frontend Usage

### Navigation Bar (Mega Menu)

```
┌─────────────────────────────────────────────────────┐
│  [Logo]  Electronics ▼  Fashion ▼  Home & Garden ▼ │
│          ├─ Laptops          ├─ Men         ├─ Kitchen│
│          ├─ Phones           ├─ Women       ├─ Decor │
│          └─ Accessories      └─ Kids        └─ Tools │
└─────────────────────────────────────────────────────┘
```

Map the response data to render:
1. **Top level** (`level: 1`) → Main nav bar items
2. **Second level** (`level: 2`) → Dropdown columns
3. **Third level** (`level: 3`) → Sub-items within columns

### Sidebar Category Navigation

```
Categories
├─ Electronics (12)
│  ├─ Laptops (5)
│  ├─ Phones (4)
│  └─ Accessories (3)
├─ Fashion (8)
└─ Home & Garden (6)
```

Use the hierarchical structure to render expandable sidebar navigation.

### Implementation Notes

- **Caching:** Response is cached for 120 seconds on the server. The browser can also cache using standard HTTP cache headers.
- **Images:** `desktop` and `mobile` URLs may be null if no image is uploaded for that category. Provide fallback/default images on the frontend.
- **Locale:** Category names are returned in the current application locale (`app()->getLocale()`). The frontend should send `Accept-Language` header or the locale is determined server-side.
- **Channel:** The `X-Channel` header affects the cache key. Different channels get different cached responses.
- **Empty State:** If no active categories exist, the response returns an empty array `[]`.
