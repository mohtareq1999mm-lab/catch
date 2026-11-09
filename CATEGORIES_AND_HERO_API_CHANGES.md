# API Changes Reference (Hero, Sections & Categories `active_nav`)

---

# Part 1 — Categories & Nav-Data (`active_nav`)

## 1. Public — Get Navbar Categories (`GET /api/v1/general/nav-data`)

- **Method & URL:** `GET /api/v1/general/nav-data`
- **Headers:** `Accept-Language: en|ar` (or `lang: en|ar`)
- **Authentication:** Public

### Query Parameters

| Parameter | Type | Default | Description |
| :--- | :--- | :--- | :--- |
| `level` | `integer` | `3` | Tree depth (`1`, `2`, or `3`) |

### Response (`200 OK`)

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
      "active_nav": true,
      "image": {
        "desktop": "http://localhost/storage/categories/1/desktop.jpg",
        "mobile": "http://localhost/storage/categories/1/mobile.jpg"
      },
      "children": [
        {
          "id": 2,
          "name": "Smartphones",
          "slug": "smartphones",
          "level": 2,
          "active_nav": false,
          "image": {
            "desktop": "http://localhost/storage/categories/2/desktop.jpg",
            "mobile": "http://localhost/storage/categories/2/mobile.jpg"
          },
          "children": []
        }
      ]
    }
  ]
}
```

---

## 2. Admin — List Categories (`GET /api/v1/categories`)

- **Method & URL:** `GET /api/v1/categories`
- **Headers:** `Authorization: Bearer {token}`, `Accept-Language: en|ar`
- **Permission:** `view-categories`

### Query Parameters

| Parameter | Type | Default | Description |
| :--- | :--- | :--- | :--- |
| `page` | `integer` | `1` | Page number |
| `per_page` / `limit` | `integer` | `15` | Items per page (max `100`) |
| `search` | `string` | `null` | Search by category `name` |
| `status` | `boolean/int` | `null` | `1`/`true` (active) or `0`/`false` (inactive) |
| `active_nav` | `boolean/int` | `null` | **New:** `1`/`true` or `0`/`false` |
| `feature-category` | `boolean/int` | `null` | Filter `is_featured = true` |
| `parent` | `boolean` | `null` | Filter root categories (`parent_id IS NULL`) |
| `parent_id` | `int/string` | `null` | Category ID or `"null"` |
| `level` | `integer` | `null` | Filter by hierarchy level (`1`, `2`, `3`) |
| `order` | `string` | `null` | `id`, `name`, `slug`, `products_count`, `created_at`, `updated_at`, `level` |
| `sortedBy` | `string` | `asc` | `asc` or `desc` |

### Response (`200 OK`)

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "data": [
      {
        "id": 1,
        "name": "Electronics",
        "slug": "electronics",
        "parent_id": null,
        "parent": null,
        "level": 1,
        "image": {
          "desktop": "http://localhost/storage/categories/1/desktop.jpg",
          "mobile": "http://localhost/storage/categories/1/mobile.jpg"
        },
        "is_featured": true,
        "active_nav": true,
        "products_count": 14,
        "status": true
      }
    ],
    "page": 1,
    "current_page": 1,
    "from": 1,
    "to": 1,
    "last_page": 1,
    "path": "http://localhost/api/v1/categories",
    "per_page": 15,
    "total": 1,
    "next_page_url": "",
    "prev_page_url": "",
    "last_page_url": "http://localhost/api/v1/categories?page=1",
    "first_page_url": "http://localhost/api/v1/categories?page=1"
  }
}
```

---

## 3. Admin — Create Category (`POST /api/v1/categories`)

- **Method & URL:** `POST /api/v1/categories`
- **Headers:** `Authorization: Bearer {token}`, `Content-Type: multipart/form-data`
- **Permission:** `create-category`

### Request Body (`multipart/form-data`)

| Field | Type | Required | Rules / Notes |
| :--- | :--- | :--- | :--- |
| `name[en]` | `string` | Yes | Unique in `categories.name` |
| `name[ar]` | `string` | Yes | Unique in `categories.name` |
| `image-desktop` | `file` | Yes | `mimes:jpeg,png,jpg,gif,svg,webp`, max `2048 KB` |
| `image-mobile` | `file` | Yes | `mimes:jpeg,png,jpg,gif,svg,webp`, max `2048 KB` |
| `parent_id` | `integer` | No | `nullable`, `exists:categories,id` |
| `details` | `string` | No | `min:3`, `max:2500` |
| `is_featured` | `boolean/int` | No | `true`/`false` or `1`/`0` |
| `active_nav` | `boolean/int` | No | **New:** `true`/`false` or `1`/`0` (default `false`) |
| `status` | `boolean/int` | No | `1` or `0` (default `true`) |
| `products[]` | `array<int>` | No | `exists:products,id` |

#### Example Request Payload

```json
{
  "name": {
    "en": "Electronics",
    "ar": "إلكترونيات"
  },
  "parent_id": null,
  "details": "All electronic devices",
  "is_featured": 1,
  "active_nav": true,
  "status": 1,
  "image-desktop": "(binary file)",
  "image-mobile": "(binary file)"
}
```

### Response (`201 Created`)

```json
{
  "status": 201,
  "message": "Category created successfully",
  "success": true,
  "data": {
    "id": 1,
    "name": "Electronics",
    "slug": "electronics",
    "parent_id": null,
    "parent": null,
    "level": 1,
    "image": {
      "desktop": "http://localhost/storage/categories/1/desktop.jpg",
      "mobile": "http://localhost/storage/categories/1/mobile.jpg"
    },
    "is_featured": true,
    "active_nav": true,
    "products_count": 0,
    "status": true,
    "details": "All electronic devices",
    "products": []
  }
}
```

---

## 4. Admin — Show Category (`GET /api/v1/categories/{id}`)

- **Method & URL:** `GET /api/v1/categories/{id}`
- **Headers:** `Authorization: Bearer {token}`
- **Permission:** `view-categories`

### Response (`200 OK`)

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "name": {
      "ar": "إلكترونيات",
      "en": "Electronics"
    },
    "slug": "electronics",
    "parent_id": null,
    "parent": null,
    "level": 1,
    "image": {
      "desktop": "http://localhost/storage/categories/1/desktop.jpg",
      "mobile": "http://localhost/storage/categories/1/mobile.jpg"
    },
    "is_featured": true,
    "active_nav": true,
    "products_count": 14,
    "status": true,
    "details": "All electronic devices",
    "children": [
      {
        "id": 2,
        "name": "Smartphones",
        "slug": "smartphones",
        "active_nav": false,
        "products_count": 5,
        "image": {
          "desktop": "http://localhost/storage/categories/2/desktop.jpg",
          "mobile": "http://localhost/storage/categories/2/mobile.jpg"
        }
      }
    ],
    "products": []
  }
}
```

---

## 5. Admin — Update Category (`PUT / PATCH /api/v1/categories/{id}`)

- **Method & URL:** `PUT /api/v1/categories/{id}` or `PATCH /api/v1/categories/{id}`
- **Headers:** `Authorization: Bearer {token}`, `Content-Type: application/json` (or `multipart/form-data`)
- **Permission:** `update-category`

### Request Body

| Field | Type | Required | Rules / Notes |
| :--- | :--- | :--- | :--- |
| `name[en]` / `name[ar]` | `string` | No | Unique in `categories.name` (ignoring current ID) |
| `image-desktop` / `image-mobile` | `file` | No | `mimes:jpeg,png,jpg,gif,svg,webp`, max `2048 KB` |
| `parent_id` | `integer` | No | `nullable`, `exists:categories,id` (prevents circular hierarchy) |
| `details` | `string` | No | `min:3`, `max:2500` |
| `is_featured` | `boolean/int` | No | `true`/`false` or `1`/`0` |
| `active_nav` | `boolean/int` | No | **New:** `true`/`false` or `1`/`0` |
| `status` | `integer` | No | `1` or `0` |
| `products[]` | `array<int>` | No | `exists:products,id` |

#### Example Request Payload

```json
{
  "active_nav": true,
  "status": 1
}
```

### Response (`200 OK`)

Returns the updated Admin `CategoryResource` with `"active_nav": true` and `"message": "Category updated successfully"`.

---

## 6. Public — List Categories & Show Category By Slug

- **Endpoints:**
  - `GET /api/v1/general/categories`
  - `GET /api/v1/general/categories/{slug}`

### Response (`200 OK`)

Both endpoints now include `"active_nav": true | false` on each category object (and on each item inside `children`).

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "name": "Electronics",
    "slug": "electronics",
    "active_nav": true,
    "image": {
      "desktop": "http://localhost/storage/categories/1/desktop.jpg",
      "mobile": "http://localhost/storage/categories/1/mobile.jpg"
    },
    "products_count": 14,
    "details": "All electronic devices",
    "children": []
  }
}
```

---
---

# Part 2 — Hero Module & Sections Integration

## 7. Admin — Get Distinct Section Types (`GET /api/v1/sections/types`)

- **Method & URL:** `GET /api/v1/sections/types`
- **Headers:** `Authorization: Bearer {token}`
- **Permission:** `view-sections`

### Response (`200 OK`)

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "0": "sliders",
    "1": "banners",
    "2": "promotions",
    "3": "flash-sales",
    "4": "products",
    "6": "brands",
    "8": "coupons",
    "11": "tags",
    "17": "categories",
    "19": "hero"
  }
}
```

---

## 8. Admin — List Registered Section Types (`GET /api/v1/section-types`)

- **Method & URL:** `GET /api/v1/section-types`
- **Headers:** `Authorization: Bearer {token}`
- **Permission:** `view-section-types`

### Response (`200 OK`)

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": [
    "banners",
    "sliders",
    "promotions",
    "categories",
    "products",
    "flash-sales",
    "brands",
    "coupons",
    "tags",
    "hero"
  ]
}
```

---

## 9. Admin — Get / Update Hero Section Type Settings

- **Methods & URL:**
  - `GET /api/v1/section-types/hero/settings` (`permission:view-section-types`)
  - `POST | PUT | PATCH /api/v1/section-types/hero/settings` (`permission:update-section-types`)

### Request Body (for `POST / PUT / PATCH`)

```json
{
  "front": {
    "autoplay": true,
    "loop": true,
    "muted": true
  },
  "back": []
}
```

### Response (`200 OK`)

```json
{
  "status": 200,
  "message": "Settings updated successfully",
  "success": true,
  "data": {
    "front": {
      "autoplay": true,
      "loop": true,
      "muted": true
    },
    "back": []
  }
}
```

---

## 10. Admin — Create Hero Section (`POST /api/v1/sections`)

- **Method & URL:** `POST /api/v1/sections`
- **Headers:** `Authorization: Bearer {token}`, `Content-Type: application/json`
- **Permission:** `create-sections`

### Request Body

```json
{
  "type": "hero",
  "title": {
    "en": "Hero",
    "ar": "الهيرو الرئيسي"
  },
  "is_active": 1,
  "title_visible": 1,
  "order": 1,
  "setting": {
    "front": {
      "autoplay": true,
      "loop": true,
      "muted": true
    },
    "back": []
  }
}
```

### Response (`200 OK`)

```json
{
  "status": 200,
  "message": "Section created successfully",
  "success": true,
  "data": {
    "id": 25,
    "type": "hero",
    "title": {
      "en": "Hero",
      "ar": "الهيرو الرئيسي"
    },
    "is_active": true,
    "endpoint": "general/hero?",
    "order": 1,
    "setting": {
      "front": {
        "autoplay": true,
        "loop": true,
        "muted": true
      },
      "back": []
    }
  }
}
```

---

## 11. Admin — Hero CRUD Endpoints (`/api/v1/hero`)

### 11.1 List Heroes (`GET /api/v1/hero`)
- **Permission:** `view-hero`
- **Query Params:** `page`, `per_page` / `limit`, `type` (`image`|`video`), `is_active` (`1`|`0`|`true`|`false`).

#### Response (`200 OK`)
```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "data": [
      {
        "id": 1,
        "type": "image",
        "description": {
          "en": "Discover our new seasonal collection with exclusive offers",
          "ar": "اكتشف مجموعتنا الموسمية الجديدة مع عروض حصرية"
        },
        "is_active": true,
        "media": {
          "id": 10,
          "url": "http://localhost/storage/heroes/10/hero.jpg",
          "mime_type": "image/jpeg",
          "file_name": "hero.jpg",
          "size": 142500
        },
        "created_at": "2026-11-09T09:00:00.000000Z",
        "updated_at": "2026-11-09T09:00:00.000000Z"
      }
    ],
    "page": 1,
    "current_page": 1,
    "from": 1,
    "to": 1,
    "last_page": 1,
    "path": "http://localhost/api/v1/hero",
    "per_page": 15,
    "total": 1,
    "next_page_url": "",
    "prev_page_url": "",
    "last_page_url": "http://localhost/api/v1/hero?page=1",
    "first_page_url": "http://localhost/api/v1/hero?page=1"
  }
}
```

### 11.2 Create Hero (`POST /api/v1/hero`)
- **Permission:** `create-hero`
- **Content-Type:** `multipart/form-data`
- **Single Active Rule:** If `is_active = true` (default), all other Hero records are automatically set to `is_active = false`.

| Field | Type | Required | Rules |
| :--- | :--- | :--- | :--- |
| `type` | `string` | Yes | `image` or `video` |
| `description[en]` | `string` | No | Max `2000` |
| `description[ar]` | `string` | No | Max `2000` |
| `is_active` | `boolean/int` | No | Default `true` (`1`, `0`, `true`, `false`) |
| `media` | `file` | Yes | If `image`: `jpeg,png,jpg,webp,gif` (max 10MB). If `video`: `mp4,webm,ogg,mov,avi` (max 50MB). |

#### Response (`201 Created`)
```json
{
  "status": 201,
  "message": "Hero created successfully.",
  "success": true,
  "data": {
    "id": 3,
    "type": "video",
    "description": {
      "en": "Watch our new collection",
      "ar": "شاهد مجموعتنا الجديدة"
    },
    "is_active": true,
    "media": {
      "id": 12,
      "url": "http://localhost/storage/heroes/12/promo.mp4",
      "mime_type": "video/mp4",
      "file_name": "promo.mp4",
      "size": 1048576
    },
    "created_at": "2026-11-09T09:10:00.000000Z",
    "updated_at": "2026-11-09T09:10:00.000000Z"
  }
}
```

### 11.3 Show Hero (`GET /api/v1/hero/{id}`)
- **Permission:** `view-hero`
- **Response (`200 OK`):** Returns the single Admin `HeroResource` object in `data`.

### 11.4 Update Hero (`PUT | PATCH | POST /api/v1/hero/{id}`)
- **Permission:** `update-hero`
- **Request Body:** Any subset of `type`, `description[en]`, `description[ar]`, `is_active`, `media`. (If `is_active = true`, all other Heroes are deactivated).
- **Response (`200 OK`):** Returns the updated Admin `HeroResource` object in `data`.

### 11.5 Toggle / Change Hero Active Status
- **Endpoints:**
  - `PATCH /api/v1/hero/{id}/toggle-active` (`permission:update-hero`)
  - `PATCH /api/v1/hero/change-status` (`permission:update-hero`, body: `{"id": 1, "is_active": true}`)
- **Single Active Rule:** Activating a Hero automatically sets `is_active = false` on all other Heroes.
- **Response (`200 OK`):** Returns the updated Admin `HeroResource` object in `data`.

### 11.6 Delete Hero (`DELETE /api/v1/hero/{id}`)
- **Permission:** `delete-hero`
- **Response (`200 OK`):**
```json
{
  "status": 200,
  "message": "Hero deleted successfully.",
  "success": true,
  "data": null
}
```

---

## 12. Public — Get Active Hero (`GET /api/v1/general/hero`)

- **Method & URL:** `GET /api/v1/general/hero`
- **Headers:** `Accept-Language: en|ar` (or `lang: en|ar`)
- **Authentication:** Public
- **Behavior:** Returns **only the single active Hero object** (`is_active = true`), or `data: null` if none is active.

### Response (`200 OK`)

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 1,
    "type": "image",
    "description": "Discover our new seasonal collection with exclusive offers",
    "is_active": true,
    "media": {
      "id": 10,
      "url": "http://localhost/storage/heroes/10/hero.jpg",
      "mime_type": "image/jpeg",
      "file_name": "hero.jpg",
      "size": 142500
    }
  }
}
```

