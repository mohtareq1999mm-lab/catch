# Category Module — Frontend Integration Guide

## Endpoints

---

### 1. GET /api/v1/general/categories — List Active Categories (Public)

**Purpose:** Display category list on homepage, navbar, or category browsing page.

**Authentication:** None (public)

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| limit | int | 15 | Items per page |
| page | int | 1 | Page number |
| search | string | - | Search by name or details |
| parent | bool | - | Only top-level categories |
| pest_category | bool | - | Order by product count |
| categoriesId | string | - | Comma-separated IDs to filter |
| order | string | desc | Sort direction |
| slug | string | - | Get single category by slug |

**Response:**
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Face",
      "slug": "face",
      "image": {
        "desktop": "https://cdn.example.com/categories/face-desktop.jpg",
        "mobile": "https://cdn.example.com/categories/face-mobile.jpg"
      },
      "products_count": 25,
      "details": "Face care products and cosmetics"
    }
  ]
}
```

---

### 2. GET /api/v1/general/categories/{slug} — Get Category With Children & Products (Public)

**Purpose:** Display category detail page with subcategories and associated products.

**Authentication:** None (public)

**Response:**
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "name": "Face",
    "slug": "face",
    "image": { "desktop": "...", "mobile": "..." },
    "products_count": 25,
    "details": "Face care products",
    "children": [
      {
        "id": 10,
        "name": "Moisturizers",
        "slug": "moisturizers",
        "image": { "desktop": "...", "mobile": "..." },
        "products_count": 10
      }
    ],
    "products": [
      {
        "id": 5,
        "name": "Face Cream",
        "slug": "face-cream",
        "image": { "thumbnail": "..." },
        "price": 29.99,
        "price_after_discount": 24.99
      }
    ]
  }
}
```

---

### 3. GET /api/v1/featured-categories — Top Featured Categories (Public)

**Purpose:** Display top categories on homepage based on product count.

**Authentication:** None (public)

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| limit | int | 3 | Number of categories |

**Response:**
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Face",
      "slug": "face",
      "image": { "desktop": "...", "mobile": "..." },
      "is_featured": true,
      "products_count": 25
    }
  ]
}
```

---

### 4. GET /api/v1/featured-categories — Navbar Categories (Public, app-level)

**Purpose:** Render multi-level category tree in the navigation bar.

**Endpoint location:** A separate `GET /api/v1/featured-categories` endpoint with `?level=3` query param controls max depth.

**Response recurses children up to the configured `level` depth:**
```json
{
  "id": 1,
  "name": "Face",
  "slug": "face",
  "level": 1,
  "image": { "desktop": "...", "mobile": "..." },
  "children": [
    {
      "id": 10,
      "name": "Moisturizers",
      "slug": "moisturizers",
      "level": 2,
      "image": { "desktop": "...", "mobile": "..." },
      "children": []
    }
  ]
}
```

---

### 5. POST /api/v1/categories — Create Category (Admin)

**Purpose:** Create a new category with optional parent assignment.

**Authentication:** Required (Sanctum), permission: `create-category`

**Request:** `multipart/form-data`
- `name[en]` (required), `name[ar]` (required)
- `image-desktop` (required, file, max 2MB)
- `image-mobile` (required, file, max 2MB)
- `parent_id` (optional, integer)
- `details` (optional, string)
- `products[]` (optional, int[])

---

### 6. PUT /api/v1/categories/{id} — Update Category (Admin)

**Authentication:** Required (Sanctum), permission: `update-category`

**Request:** `multipart/form-data`
- All fields optional
- Changing `parent_id` triggers descendant level recalculation
- Circular references are rejected

---

### 7. PUT /api/v1/categories/feature — Toggle Featured (Admin)

**Request:**
```json
{ "id": 1 }
```

**Response:**
```json
{ "status": 200, "message": "Category feature toggled successfully", "success": true }
```

---

### 8. DELETE /api/v1/categories/{id} — Delete Category (Admin)

**Note:** Cannot delete a category that has children (returns 400).

---

## Frontend Usage

### Loading State
```js
const response = await fetch('/api/v1/general/categories?limit=10');
if (!response.ok) {
  // Show error state
}
const categories = await response.json();
```

### Empty State
- **No categories:** Empty array `[]` — show "No categories available"
- **No children:** Children array is omitted if empty — check `data.children` existence
- **No products:** Products array omitted if not loaded — check `data.products` existence

### Error State
- **404:** Category not found — redirect to categories listing
- **400:** Cannot delete category with children — show warning
- **422:** Validation errors — field-level error messages (including cycle detection)
- **500:** Server error — show generic message

## Key Considerations

1. **Hierarchical data** — Categories have parent-child relationships. The frontend should handle recursive rendering for navbar/trees.

2. **Translatable fields** — `name` and `details` sent as objects:
   ```json
   { "en": "Face", "ar": "وجه" }
   ```

3. **Details field** — Unlike brands where `details` is an object, category `details` is a plain string in the create/update API.

4. **Image uploads** — `multipart/form-data` for create/update. Desktop and mobile images are separate.

5. **Featured toggle** — Simple toggle endpoint. Frontend should toggle the UI state optimistically and revert on error.

6. **Category deletion** — If a category has children or products, deletion is blocked by FK RESTRICT. The error message is `"Cannot delete category with associated resources"`.

7. **Navbar depth** — The `level` parameter controls how deep the category tree renders in the navbar. Default max level is 3.
