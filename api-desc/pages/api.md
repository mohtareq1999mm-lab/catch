# API Reference — Pages Module

Base URL: `/api/v1`
Public endpoints: no auth required.
Admin endpoints: require `role:super_admin|editor` + `auth:sanctum` + `email.verified`.

---

## General Endpoints

### GET /api/v1/product-type

List all product type keys with their translated labels based on the current locale. The locale is determined by the `lang` header (supported: `en`, `ar`).

**Headers:**

| Header | Value | Description |
|--------|-------|-------------|
| lang | en, ar | Language code (default: en) |

**Response 200:**
```json
{
  "best_product_sales": "Best Product Sales",
  "brands_product": "Brands Product",
  "new_arrivals": "New Arrivals",
  "all_product_discounts": "All Product Discounts",
  "product_discount_today_or_low_qty": "Product Discount Today or Low Quantity",
  "flash_sales_product": "Flash Sales Product",
  "flash_sales_end_today": "Flash Sales End Today",
  "product_for_parent_category": "Product for Parent Category",
  "flash_sales_end_week": "Flash Sales End Week"
}
```

**Arabic Response (lang: ar):**
```json
{
  "best_product_sales": "الأكثر مبيعاً",
  "brands_product": "منتجات العلامات التجارية",
  "new_arrivals": "وصل حديثاً",
  "all_product_discounts": "جميع خصومات المنتجات",
  "product_discount_today_or_low_qty": "خصم اليوم أو الكمية المحدودة",
  "flash_sales_product": "منتجات التخفيضات السريعة",
  "flash_sales_end_today": "التخفيضات السريعة تنتهي اليوم",
  "product_for_parent_category": "منتجات القسم الرئيسي",
  "flash_sales_end_week": "التخفيضات السريعة تنتهي هذا الأسبوع"
}
```

**Quick Test:**
```bash
curl -X GET "http://example.com/api/v1/product-type" \
  -H "Accept: application/json"

curl -X GET "http://example.com/api/v1/product-type" \
  -H "Accept: application/json" \
  -H "lang: ar"
```

---

## Public Endpoints

## Public Endpoints

### GET /api/v1/general/content-pages

List all published (public) content pages with active sections only.

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| page | int | 1 | Page number |
| per_page | int | 15 | Items per page |

**Response 200:** Paginated ContentPageResource (only `is_active = true` sections included).

**Quick Test:**
```bash
curl -X GET "http://example.com/api/v1/general/content-pages" \
  -H "Accept: application/json"
```

---

### GET /api/v1/general/content-pages/{slug}

Show a specific content page by slug with active sections only.

**Path Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| slug | string | Content page slug |

**Response 200:** ContentPageResource with sections (is_active = true only).
**Response 404:** If slug not found.

---

## Admin Endpoints

### GET /api/v1/content-pages

List all content pages with all sections (regardless of active status).

**Permissions:** `view-content-pages`

**Query Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| page | int | 1 | Page number |

**Response 200:** Paginated ContentPageResource with all sections.

---

### POST /api/v1/content-pages

Create a new content page.

**Permissions:** `create-content-pages`

**Request Body:**
```json
{
  "title": {
    "en": "Home Page",
    "ar": "الصفحة الرئيسية"
  }
}
```

**Validation Rules:**

| Field | Rules |
|-------|-------|
| title | required, array |
| title.* | required, string, max:30, unique translation |

**Response 201:** ContentPageResource.

---

### GET /api/v1/content-pages/{content_page}

Show a specific content page with all sections.

**Permissions:** `view-content-pages`

**Response 200:** ContentPageResource.
**Response 404:** If not found.

---

### PUT /api/v1/content-pages/{content_page}

Update a content page.

**Permissions:** `update-content-pages`

**Request Body:**
```json
{
  "title": {
    "en": "Updated Home Page"
  },
  "is_active": true
}
```

**Validation Rules:**

| Field | Rules |
|-------|-------|
| title | sometimes, array |
| title.* | sometimes, string, max:30, unique translation ignoring self |
| is_active | sometimes, in:0,1 |

**Response 200:** Updated ContentPageResource.

---

### DELETE /api/v1/content-pages/{content_page}

Delete a content page.

**Permissions:** `delete-content-pages`

**Response 200:** `{ "message": "...", "success": true }`

---

### POST /api/v1/content-pages/{content_page}/attach-sections

Attach existing sections to a content page by ID. If empty array is provided, detaches all sections (sets content_page_id = null).

**Permissions:** `update-content-pages`

**Request Body:**
```json
{
  "sections": [1, 2, 3]
}
```

**Validation Rules:**

| Field | Rules |
|-------|-------|
| sections | required, present, array |
| sections.* | integer, exists:sections,id |

**Response 200:** Updated ContentPageResource with attached sections.
**Response 200 (empty):** If empty sections array, sections are detached and response is simple success.

---

### PATCH /api/v1/content-pages/{content_page}/toggle-active

Toggle the `is_active` status of a content page.

**Permissions:** `update-content-pages`

**Response 200:** ContentPageResource with toggled status.

---

### GET /api/v1/sections

List all sections ordered by their `order` column.

**Permissions:** `view-sections`

**Response 200:** Array of SectionResource.

---

### POST /api/v1/sections

Create a new section.

**Permissions:** `create-sections`

**Request Body:**
```json
{
  "type": "banners",
  "title": {
    "en": "Main Banner",
    "ar": "البنر الرئيسي"
  },
  "is_active": true,
  "title_visible": true,
  "order": 1,
  "setting": {
    "front": { "display": "grid" },
    "back": { "slug": "home-banner" }
  }
}
```

**Validation Rules:**

| Field | Rules |
|-------|-------|
| type | required, string, max:100, exists:section_types,type |
| title | required, array |
| title.* | required, string, max:50 |
| is_active | nullable, in:0,1 |
| title_visible | nullable, in:0,1 |
| order | nullable, integer |
| setting | nullable, array |
| setting.front | nullable, array |
| setting.back | nullable, array |

**Custom Validation:** If `with_product` is truthy, only `slug` is allowed in `setting.back`.

**Response 200:** SectionResource.

---

### GET /api/v1/sections/{section}

Show a specific section.

**Permissions:** `view-sections`

**Response 200:** SectionResource.
**Response 404:** If not found.

---

### PUT /api/v1/sections/{section}

Update a section.

**Permissions:** `update-sections`

**Validation Rules:**

| Field | Rules |
|-------|-------|
| title | sometimes, array |
| title.* | sometimes, string, max:50 |
| order | sometimes, integer |
| is_active | sometimes, in:0,1 |
| title_visible | sometimes, in:0,1 |
| setting | nullable, array |

**Response 200:** Updated SectionResource.

---

### DELETE /api/v1/sections/{section}

Delete a section.

**Permissions:** `delete-sections`

**Response 200:** `{ "message": "...", "success": true }`

---

### post /api/v1/sections/reorder

Reorder sections by providing an ordered array of section IDs.

**Permissions:** `update-sections`

**Request Body:**
```json
{
  "sections": [3, 1, 2]
}
```

**Validation Rules:**

| Field | Rules |
|-------|-------|
| sections | required, array |
| sections.* | required, integer, distinct, exists:sections,id |

**Response 200:** `{ "message": "Sections reordered successfully", "success": true }`

---

### GET /api/v1/sections/types

Get a list of unique section types currently in use.

**Permissions:** `view-sections`

**Response 200:** Array of unique type strings.

---

### PATCH /api/v1/sections/{section}/toggle-active

Toggle the `is_active` status of a section.

**Permissions:** `update-sections`

**Response 200:** SectionResource with toggled status.

---

### GET /api/v1/section-types

List all section types.

**Permissions:** `view-section-types`

**Response 200:** Array of type strings.

---

### POST /api/v1/section-types

Create a new section type.

**Permissions:** `create-section-types`

**Request Body:**
```json
{
  "type": "custom-banners"
}
```

**Validation Rules:**

| Field | Rules |
|-------|-------|
| type | required, string, max:100, unique:section_types,type |

**Response 200:** Created SectionType object.

---

### GET /api/v1/section-types/{section_type}

Show a section type with its settings grouped by front/back.

**Permissions:** `view-section-types`

**Note:** Route-model binding uses `type` column (not `id`).

**Response 200:** `{ "front": {...}, "back": {...} }`
**Response 404:** If type not found.

---

### PUT /api/v1/section-types/{section_type}

Update a section type name.

**Permissions:** `update-section-types`

**Validation Rules:**

| Field | Rules |
|-------|-------|
| type | sometimes, string, max:100, unique:section_types,type ignoring self |

**Response 200:** Updated SectionType object.

---

### DELETE /api/v1/section-types/{section_type}

Delete a section type.

**Permissions:** `delete-section-types`

**Response 200:** `{ "message": "...", "success": true }`

---

### GET /api/v1/section-types/{type}/settings

Get settings for a section type by its type string.

**Permissions:** `view-section-types`

**Response 200:** `{ "front": {...}, "back": {...} }`
**Response 404:** If type not found.

---

### POST /api/v1/section-types/{type}/settings

Update settings for a section type (replaces all existing settings).

**Permissions:** `update-section-types`

**Request Body:**
```json
{
  "front": { "display": "grid", "columns": 3 },
  "back": { "slug": "home-banner", "limit": 10 }
}
```

**Validation Rules:**

| Field | Rules |
|-------|-------|
| front | nullable, array |
| back | nullable, array |

**Response 200:** `{ "front": {...}, "back": {...} }` after update.
**Response 404:** If type not found.
dont use this endpoint to update individual section settings,only for develop 
