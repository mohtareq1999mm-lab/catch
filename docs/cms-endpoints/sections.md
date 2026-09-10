# Sections API

---

### GET /sections — List Sections

**Purpose:** Fetch all sections ordered by their `order` column.

**Method:** `GET`

**URL:** `/sections`

**Authentication:** Required

**Permissions:** `view-sections`

**Business Logic:**
1. Calls `Section::ordered()->get()` (uses `spatie/eloquent-sortable`)

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": [
        {
            "id": 1,
            "type": "hero",
            "title": "Hero Banner",
            "is_active": true,
            "endpoint": "general/hero?slug=summer-sale",
            "order": 1,
            "setting": {
                "front": { "autoplay": true, "slider_speed": 5000 },
                "back": { "slug": "summer-sale" }
            }
        }
    ]
}
```

---

### POST /sections — Create Section

**Purpose:** Create a new section.

**Method:** `POST`

**URL:** `/sections`

**Authentication:** Required

**Permissions:** `create-sections`

**Request Body:**
| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `type` | string | **Yes** | `required`, `string`, `max:100`, `exists:section_types,type` |
| `title` | object | **Yes** | `required`, translatable array |
| `title.*` | string | **Yes** | `string`, `max:50`, unique translation |
| `is_active` | bool/int | No | `nullable`, `in:0,1` |
| `title_visible` | bool/int | No | `nullable`, `in:0,1` |
| `order` | int | No | `nullable`, `integer` |
| `setting` | object | No | `nullable`, `array` |
| `setting.front` | object | No | `nullable`, `array` |
| `setting.back` | object | No | `nullable`, `array` — only `slug` allowed when `with_product` is truthy |

**Business Logic:**
1. If `with_product` is present and truthy in the request, validates `setting.back` only contains `slug` key
2. Order auto-assigned by `spatie/eloquent-sortable` if not provided

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Section created successfully",
    "success": true,
    "data": {
        "id": 1,
        "type": "hero",
        "title": "Hero Banner",
        "is_active": true,
        "endpoint": "general/hero?slug=summer-sale",
        "order": 1,
        "setting": {
            "front": { "autoplay": true, "slider_speed": 5000 },
            "back": { "slug": "summer-sale" }
        }
    }
}
```

---

### GET /sections/{section} — Show Section

**Purpose:** Fetch a single section by ID.

**Method:** `GET`

**URL:** `/sections/{section}`

**Authentication:** Required

**Permissions:** `view-sections`

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": {
        "id": 1,
        "type": "hero",
        "title": "Hero Banner",
        "is_active": true,
        "endpoint": "general/hero?slug=summer-sale",
        "order": 1,
        "setting": {
            "front": { "autoplay": true, "slider_speed": 5000 },
            "back": { "slug": "summer-sale" }
        }
    }
}
```

---

### PUT /sections/{section} — Update Section

**Purpose:** Update a section's fields.

**Method:** `PUT`

**URL:** `/sections/{section}`

**Authentication:** Required

**Permissions:** `update-sections`

**Request Body:**
| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `title` | object | No | `sometimes`, translatable array |
| `title.*` | string | No | `sometimes`, `string`, `max:50` |
| `order` | int | No | `sometimes`, `integer` |
| `is_active` | bool/int | No | `sometimes`, `in:0,1` |
| `title_visible` | bool/int | No | `sometimes`, `in:0,1` |
| `setting` | object | No | `nullable`, `array` |
| `setting.front` | object | No | `nullable`, `array` |
| `setting.back` | object | No | `nullable`, `array` — only `slug` allowed when `with_product` is truthy |

**Business Logic:**
1. Reads existing `with_product` from the section if not in request
2. If `with_product` is truthy, validates `setting.back` only contains `slug`

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Section updated successfully",
    "success": true,
    "data": {
        "id": 1,
        "type": "hero",
        "title": "Hero Banner (Updated)",
        "is_active": true,
        "endpoint": "general/hero?slug=summer-sale",
        "order": 1,
        "setting": {
            "front": { "autoplay": true, "slider_speed": 5000 },
            "back": { "slug": "summer-sale" }
        }
    }
}
```

---

### DELETE /sections/{section} — Delete Section

**Purpose:** Permanently delete a section.

**Method:** `DELETE`

**URL:** `/sections/{section}`

**Authentication:** Required

**Permissions:** `delete-sections`

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Section deleted successfully",
    "success": true
}
```

---

### POST /sections/reorder — Reorder Sections

**Purpose:** Set a custom display order for sections by providing an ordered array of IDs.

**Method:** `POST`

**URL:** `/sections/reorder`

**Authentication:** Required

**Permissions:** `update-sections`

**Request Body:**
| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `sections` | array | **Yes** | `required`, `array` |
| `sections.*` | int | **Yes** | `required`, `integer`, `distinct`, `exists:sections,id` |

**Business Logic:**
1. Uses `spatie/eloquent-sortable` `setNewOrder()` method
2. Sections will be returned in the provided order on subsequent `GET /sections` calls

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Sections reordered successfully",
    "success": true
}
```

---

### PATCH /sections/{section}/toggle-active — Toggle Section Active Status

**Purpose:** Toggle the `is_active` boolean on a section.

**Method:** `PATCH`

**URL:** `/sections/{section}/toggle-active`

**Authentication:** Required

**Permissions:** `update-sections`

**Business Logic:**
1. Flips `is_active` from `true` to `false` or `false` to `true`
2. Saves and returns the updated section

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Data updated successfully",
    "success": true,
    "data": {
        "id": 1,
        "type": "hero",
        "title": "Hero Banner",
        "is_active": true,
        "endpoint": "general/hero?slug=summer-sale",
        "order": 1,
        "setting": {
            "front": { "autoplay": true, "slider_speed": 5000 },
            "back": { "slug": "summer-sale" }
        }
    }
}
```

---

### GET /sections/types — Get Section Types

**Purpose:** Get a unique list of all section `type` values currently used in the database.

**Method:** `GET`

**URL:** `/sections/types`

**Authentication:** Required

**Permissions:** `view-sections`

**Business Logic:**
1. Plucks unique `type` values from all sections

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": ["hero", "featured-products", "categories-grid", "testimonials"]
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 404 | Section not found |
| 422 | Validation failure |
