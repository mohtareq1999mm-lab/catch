# API Documentation - Slider Feature

## Endpoints

---

### 1. List Sliders (Public)

**GET** `/api/v1/general/sliders`

**Purpose:** Retrieve active sliders for homepage carousel display.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | No |

#### Query Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `limit` | `integer` | No | Max results (default: all) |
| `order` | `string` | No | Sort direction (`asc`/`desc`, default: `asc`) |
| `start_date` | `date` | No | Filter sliders created after this date |
| `end_date` | `date` | No | Filter sliders created before this date |
| `slidersId` | `array` | No | Filter by specific slider IDs |

#### Success Response (200)

```json
{
    "data": [
        {
            "id": 1,
            "title": "Summer Sale",
            "slug": "summer-sale",
            "status": true,
            "image": {
                "desktop": "https://cdn.example.com/sliders/summer-desktop.jpg",
                "mobile": "https://cdn.example.com/sliders/summer-mobile.jpg"
            },
            "products": [
                {
                    "id": 1,
                    "name": "Sunscreen SPF 50",
                    "slug": "sunscreen-spf-50",
                    "status": true,
                    "image": { "thumbnail": "https://cdn.example.com/products/sunscreen-thumb.jpg" }
                }
            ]
        }
    ]
}
```

---

### 2. Get Slider by Slug (Public)

**GET** `/api/v1/general/sliders/{slug}`

**Purpose:** Retrieve a single slider with its associated products and pricing.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | No |

#### Path Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `slug` | `string` | Yes | Slider slug |

#### Success Response (200)

```json
{
    "data": {
        "id": 1,
        "title": "Summer Sale",
        "slug": "summer-sale",
        "status": true,
        "image": {
            "desktop": "https://cdn.example.com/sliders/summer-desktop.jpg",
            "mobile": "https://cdn.example.com/sliders/summer-mobile.jpg"
        },
        "products": [
            {
                "id": 1,
                "name": "Sunscreen SPF 50",
                "slug": "sunscreen-spf-50",
                "status": true,
                "image": { "thumbnail": "..." },
                "price": 29.99,
                "sale_price": 19.99
            }
        ]
    }
}
```

#### Error Responses

| Status | Condition |
|--------|-----------|
| 404 | Slug not found |

---

### 3. List Sliders (Admin)

**GET** `/api/v1/sliders`

**Purpose:** Retrieve paginated list of all sliders for admin management.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Guard | `sanctum` |
| Permission | `view-slider` |

#### Query Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `page` | `integer` | No | Page number |
| `limit` | `integer` | No | Items per page |
| `status` | `boolean` | No | Filter by active/inactive |

#### Success Response (200)

```json
{
    "data": [
        {
            "id": 1,
            "title": "Summer Sale",
            "slug": "summer-sale",
            "status": true,
            "order": 1,
            "image": {
                "desktop": "https://cdn.example.com/sliders/summer-desktop.jpg",
                "mobile": "https://cdn.example.com/sliders/summer-mobile.jpg"
            },
            "products": [
                { "id": 1, "name": "Sunscreen SPF 50", "slug": "sunscreen-spf-50", "status": true, "image": {} }
            ]
        }
    ],
    "meta": { "current_page": 1, "last_page": 2, "per_page": 15, "total": 20 }
}
```

---

### 4. Create Slider (Admin)

**POST** `/api/v1/sliders`

**Purpose:** Create a new slider banner.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Guard | `sanctum` |
| Permission | `create-slider` |

#### Request Parameters (multipart/form-data)

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `title[en]` | `string` | Yes | English title |
| `title[ar]` | `string` | Yes | Arabic title |
| `image_desktop` | `file` | Yes | Desktop image (jpeg/png/jpg/gif, max 2MB) |
| `image_mobile` | `file` | Yes | Mobile image (jpeg/png/jpg/gif, max 2MB) |
| `status` | `boolean` | No | Active/inactive (default: 0) |
| `products[]` | `array` | No | Array of product IDs to associate |

#### Success Response (201)

```json
{
    "data": {
        "id": 11,
        "title": { "en": "Summer Sale", "ar": "تخفيضات الصيف" },
        "slug": "summer-sale",
        "status": true,
        "order": 6,
        "image": { "desktop": "...", "mobile": "..." },
        "products": []
    }
}
```

#### Error Responses

| Status | Condition |
|--------|-----------|
| 422 | Validation failure (missing title, missing images, invalid image type, etc.) |
| 401 | Unauthenticated |
| 403 | Forbidden (missing permission) |

---

### 5. Get Slider (Admin)

**GET** `/api/v1/sliders/{slider}`

**Purpose:** Retrieve a single slider by ID.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Guard | `sanctum` |
| Permission | `view-slider` |

#### Success Response (200)

Returns same structure as create response with `title` as an object.

#### Error Responses

| Status | Condition |
|--------|-----------|
| 404 | Slider not found |

---

### 6. Update Slider (Admin)

**PUT** `/api/v1/sliders/{slider}`

**Purpose:** Update an existing slider (partial updates supported).

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Guard | `sanctum` |
| Permission | `update-slider` |

#### Request Parameters (multipart/form-data)

Same as create but all fields optional. Images replaced when new files provided.

#### Success Response (200)

Returns updated slider with `title` as an object.

---

### 7. Delete Slider (Admin)

**DELETE** `/api/v1/sliders/{slider}`

**Purpose:** Soft-delete a slider.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Guard | `sanctum` |
| Permission | `delete-slider` |

#### Success Response (200)

```json
{
    "message": "Slider deleted successfully"
}
```

#### Error Responses

| Status | Condition |
|--------|-----------|
| 404 | Already deleted or not found |

---

### 8. Change Slider Status (Admin)

**PATCH** `/api/v1/sliders/change-status`

**Purpose:** Toggle a slider's active/inactive status.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Guard | `sanctum` |
| Permission | `update-slider` |

#### Request Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `id` | `integer` | Yes | Slider ID |

#### Success Response (200)

```json
{
    "message": "Slider status changed successfully"
}
```

#### Error Responses

| Status | Condition |
|--------|-----------|
| 422 | Missing or invalid slider ID |

---

### 9. Reorder Sliders (Admin)

**PUT** `/api/v1/sliders/reorder`

**Purpose:** Update sort order for all sliders.

#### Authentication

| Aspect | Detail |
|--------|--------|
| Required | Yes |
| Guard | `sanctum` |
| Permission | `update-slider` |

#### Request Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `sliders` | `array` | Yes | Array of slider IDs in new order |

#### Example Request

```json
{
    "sliders": [3, 1, 5, 2, 4]
}
```

#### Success Response (200)

```json
{
    "message": "Sliders reordered successfully"
}
```

#### Error Responses

| Status | Condition |
|--------|-----------|
| 422 | Missing sliders array or invalid IDs |

---

## Resource Structure

### Admin SliderResource (index)

| Field | Type | Description |
|-------|------|-------------|
| `id` | `integer` | Primary key |
| `title` | `string` | Translated title (string on index, object on show/update) |
| `slug` | `string` | URL slug |
| `status` | `boolean` | Active/inactive |
| `order` | `integer` | Sort position |
| `image` | `object` | `{ desktop: string, mobile: string }` |
| `products` | `array` | Associated products (when loaded) |

### Public SliderResource

| Field | Type | Description |
|-------|------|-------------|
| `id` | `integer` | Primary key |
| `title` | `string` | Translated title (always string) |
| `slug` | `string` | URL slug |
| `status` | `boolean` | Active/inactive |
| `image` | `object` | `{ desktop: string, mobile: string }` |
| `products` | `array` | Associated products (when loaded, with pricing) |

## Business Rules

1. **Slug:** Auto-generated from English title on save, uses `Str::slug()`
2. **Order:** Managed automatically by Spatie Sortable (auto-assigned on create)
3. **Soft Delete:** Slider is soft-deleted; product pivot records are preserved
4. **Media Fallback:** Admin resource checks both `sliders-*` and `slider-image-*` collections for backward compatibility
5. **Status Toggle:** Flips `status` boolean between 0 and 1
6. **Reorder:** Accepts array of slider IDs in desired order, updates all `order` fields atomically
