# Content Pages & Sections API

---

### GET /content-pages — List Content Pages

**Purpose:** Fetch a paginated list of all content pages with their sections.

**Method:** `GET`

**URL:** `/content-pages`

**Authentication:** Required

**Permissions:** `view-content-pages`

**Business Logic:**
1. Loads all content pages with `sections` relation ordered by `order` column
2. Paginates results (15 per page)

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": [
        {
            "id": 1,
            "title": { "en": "Home Page", "ar": "الصفحة الرئيسية" },
            "slug": "home-page",
            "is_active": true,
            "sections": [
                {
        "id": 1,
        "type": "hero",
        "title": "Hero Banner",
        "is_active": true,
        "endpoint": "general/hero?slug=summer-sale",
        "order": 1,
        "setting": { "front": {}, "back": { "slug": "summer-sale" } }
                }
            ]
        }
    ]
}
```

---

### POST /content-pages — Create Content Page

**Purpose:** Create a new content page.

**Method:** `POST`

**URL:** `/content-pages`

**Authentication:** Required

**Permissions:** `create-content-pages`

**Request Body:**
| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `title` | object | No | `nullable`, translatable array |
| `title.*` | string | No | `nullable`, `string`, `max:30`, unique translation |

**Business Logic:**
1. Generates `slug` from `title.en` using `Str::slug()`
2. Sets `is_active` to `true` by default
3. Wrapped in `DB::transaction()`

**Success Response (201):**
```json
{
    "status": 201,
    "message": "Data created successfully",
    "success": true,
    "data": {
        "id": 2,
        "title": { "en": "About Us", "ar": "من نحن" },
        "slug": "about-us",
        "is_active": true,
        "sections": []
    }
}
```

---

### GET /content-pages/{content_page} — Show Content Page

**Purpose:** Fetch a single content page with its sections.

**Method:** `GET`

**URL:** `/content-pages/{content_page}`

**Authentication:** Required

**Permissions:** `view-content-pages`

**Business Logic:**
1. Route-model binding — `ContentPage` resolved by ID
2. Loads `sections` relation ordered by `order` column

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": {
        "id": 1,
        "title": { "en": "Home Page", "ar": "الصفحة الرئيسية" },
        "slug": "home-page",
        "is_active": true,
        "sections": [
            {
                "id": 1,
                "type": "hero",
"title": "Hero Banner",
                    "is_active": true,
                    "endpoint": "general/hero?slug=summer-sale",
                "order": 1,
                "setting": { "front": {}, "back": { "slug": "summer-sale" } }
            }
        ]
    }
}
```

---

### PUT /content-pages/{content_page} — Update Content Page

**Purpose:** Update a content page title or active status.

**Method:** `PUT`

**URL:** `/content-pages/{content_page}`

**Authentication:** Required

**Permissions:** `update-content-pages`

**Request Body:**
| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `title` | object | No | `sometimes`, translatable array |
| `title.*` | string | No | `sometimes`, `string`, `max:30`, unique translation (ignores self) |
| `is_active` | bool/int | No | `sometimes`, `in:0,1` |

**Business Logic:**
1. Updates only provided fields via `$request->only(['title', 'is_active'])`
2. Reloads `sections` relation after update
3. Wrapped in `DB::transaction()`

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Data updated successfully",
    "success": true,
    "data": {
        "id": 1,
        "title": { "en": "Home Page (Updated)", "ar": "الصفحة الرئيسية" },
        "slug": "home-page",
        "is_active": true,
        "sections": []
    }
}
```

---

### DELETE /content-pages/{content_page} — Delete Content Page

**Purpose:** Permanently delete a content page. Does not cascade-delete sections.

**Method:** `DELETE`

**URL:** `/content-pages/{content_page}`

**Authentication:** Required

**Permissions:** `delete-content-pages`

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Data deleted successfully",
    "success": true
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Forbidden — missing `delete-content-pages` permission |
| 404 | Content page not found |

---

### POST /content-pages/{content_page}/attach-sections — Attach Sections to Content Page

**Purpose:** Attach existing sections to this content page by their IDs, or detach all sections by sending an empty array.

**Method:** `POST`

**URL:** `/content-pages/{content_page}/attach-sections`

**Authentication:** Required

**Permissions:** `update-content-pages`

**Request Body:**
| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `sections` | array | **Yes** | `present`, `array` |
| `sections.*` | int | No | `integer`, `exists:sections,id` |

**Business Logic:**
1. If empty array provided, detaches all sections by setting `content_page_id` to `null`
2. Otherwise, attaches each section to the content page via `attachSectionsByIds()`
3. Wrapped in `DB::transaction()`

**Success Response (200) — Attached:**
```json
{
    "status": 200,
    "message": "Data updated successfully",
    "success": true,
    "data": {
        "id": 1,
        "title": { "en": "Home Page", "ar": "الصفحة الرئيسية" },
        "slug": "home-page",
        "is_active": true,
        "sections": [
            {
                "id": 1,
                "type": "hero",
"title": "Hero Banner",
                    "is_active": true,
                    "endpoint": "general/hero?slug=summer-sale",
                "order": 1,
                "setting": { "front": {}, "back": { "slug": "summer-sale" } }
            }
        ]
    }
}
```

**Success Response (200) — Detached (empty array):**
```json
{
    "status": 200,
    "message": "Data deleted successfully",
    "success": true
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Forbidden — missing `update-content-pages` permission |
| 404 | Content page not found |
| 422 | Validation failure |

---

### PATCH /content-pages/{content_page}/toggle-active — Toggle Content Page Active Status

**Purpose:** Toggle the `is_active` boolean on a content page.

**Method:** `PATCH`

**URL:** `/content-pages/{content_page}/toggle-active`

**Authentication:** Required

**Permissions:** `update-content-pages`

**Business Logic:**
1. Flips `is_active` from `true` to `false` or `false` to `true`
2. Saves and returns the updated content page with sections

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Data updated successfully",
    "success": true,
    "data": {
        "id": 1,
        "title": { "en": "Home Page", "ar": "الصفحة الرئيسية" },
        "slug": "home-page",
        "is_active": false,
        "sections": []
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 403 | Forbidden — missing `update-content-pages` permission |
| 404 | Content page not found |

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
            "setting": { "front": {}, "back": { "slug": "summer-sale" } }
        }
    ]
}
```

---

### POST /sections — Create Section

**Purpose:** Create a new section with type, title, order, and optional settings.

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
| `order` | int | No | `nullable`, `integer` — auto-assigned by sortable trait |
| `setting` | object | No | `nullable`, `array` |
| `setting.front` | object | No | `nullable`, `array` |
| `setting.back` | object | No | `nullable`, `array` — only `slug` allowed when `with_product` is truthy |

**Business Logic:**
1. If `with_product` is present and truthy in the request, validates that `setting.back` only contains `slug` key
2. Order auto-assigned by `spatie/eloquent-sortable`

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
        "setting": { "front": {}, "back": { "slug": "summer-sale" } }
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
        "setting": { "front": {}, "back": { "slug": "summer-sale" } }
    }
}
```

---

### PUT /sections/{section} — Update Section

**Purpose:** Update a section's fields and settings.

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
1. Reads existing `with_product` from the section if not provided in request
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
        "title": "Hero Banner",
        "is_active": true,
        "endpoint": "general/hero?slug=summer-sale",
        "order": 1,
        "setting": { "front": {}, "back": { "slug": "summer-sale" } }
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

**Purpose:** Set a custom order for all sections by providing an ordered array of section IDs.

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
2. Sections will display in the provided order

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
        "setting": { "front": {}, "back": { "slug": "summer-sale" } }
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

---

### GET /section-types — List Section Types

**Purpose:** Fetch a list of all registered section types (distinct type strings).

**Method:** `GET`

**URL:** `/section-types`

**Authentication:** Required

**Permissions:** `view-section-types`

**Business Logic:**
1. Uses `SectionTypeService::getAll()` which plucks `type` column

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": ["hero", "featured-products", "categories-grid", "testimonials"]
}
```

---

### POST /section-types — Create Section Type

**Purpose:** Register a new section type string.

**Method:** `POST`

**URL:** `/section-types`

**Authentication:** Required

**Permissions:** `create-section-types`

**Request Body:**
| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `type` | string | **Yes** | `required`, `string`, `max:100`, `unique:section_types,type` |

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Type created successfully",
    "success": true,
    "data": { "id": 1, "type": "hero" }
}
```

---

### GET /section-types/{section_type} — Show Section Type

**Purpose:** Fetch a section type by its `type` string (route-model binding uses `type` column, not ID).

**Method:** `GET`

**URL:** `/section-types/{section_type}`

**Authentication:** Required

**Permissions:** `view-section-types`

**Business Logic:**
1. Route-model binding resolves via `type` column (`getRouteKeyName()` returns `'type'`)
2. Loads settings grouped into `front` and `back` via `SectionTypeService::getSettingsGrouped()`

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": {
        "front": { "title": "Hero Section", "subtitle": "Featured" },
        "back": { "slug": "summer-sale" }
    }
}
```

---

### PUT /section-types/{section_type} — Update Section Type

**Purpose:** Update a section type's type string.

**Method:** `PUT`

**URL:** `/section-types/{section_type}`

**Authentication:** Required

**Permissions:** `update-section-types`

**Request Body:**
| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `type` | string | No | `sometimes`, `string`, `max:100`, `unique:section_types,type` (ignores self) |

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Type updated successfully",
    "success": true,
    "data": { "id": 1, "type": "hero-new" }
}
```

---

### DELETE /section-types/{section_type} — Delete Section Type

**Purpose:** Delete a section type.

**Method:** `DELETE`

**URL:** `/section-types/{section_type}`

**Authentication:** Required

**Permissions:** `delete-section-types`

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Type deleted successfully",
    "success": true
}
```

---

### GET /section-types/{type}/settings — Get Section Type Settings

**Purpose:** Fetch the front/back settings for a given section type string.

**Method:** `GET`

**URL:** `/section-types/{type}/settings`

**Authentication:** Required

**Permissions:** `view-section-types`

**Business Logic:**
1. Looks up the `SectionType` by the `type` string
2. Returns settings grouped into `front` and `back` arrays

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": {
        "front": { "title": "Hero Section", "subtitle": "Featured" },
        "back": { "slug": "summer-sale" }
    }
}
```

---

### POST /section-types/{type}/settings — Update Section Type Settings

**Purpose:** Upsert front/back settings for a section type. Replaces all existing settings.

**Method:** `POST`

**URL:** `/section-types/{type}/settings`

**Authentication:** Required

**Permissions:** `update-section-types`

**Request Body:**
| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `front` | object | No | `nullable`, `array` — frontend display settings |
| `back` | object | No | `nullable`, `array` — backend/query parameter settings |

**Business Logic:**
1. Deletes all existing settings for the section type
2. Creates new `SectionTypeSetting` records for `front` and `back` if provided
3. `front` settings are used for display configuration
4. `back` settings are used to build query parameters for the section endpoint URL

**Success Response (200):**
```json
{
    "status": 200,
    "message": "Settings updated successfully",
    "success": true,
    "data": {
        "front": { "title": "Hero Section", "subtitle": "Updated" },
        "back": { "slug": "winter-sale" }
    }
}
```

**Error Responses:**
| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 404 | Section type not found |
| 422 | Validation failure |
