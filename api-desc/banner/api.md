# API Documentation - Banner Feature

All endpoints under prefix `/api/v1`.

## 1. List Banners

**GET** `/api/v1/banners`

**Query:** `limit` (default 15), `active` (boolean — filters by status=true)

Ordered by `order` column (Sortable trait). Eager loads `products`.

```json
{
    "data": [
        {
            "id": 1,
            "title": {"en": "Summer Sale", "ar": "تخفيضات الصيف"},
            "slug": "summer-sale",
            "description": {"en": "...", "ar": "..."},
            "image": {
                "desktop": "https://.../banners-desktop/1/image.jpg",
                "mobile": "https://.../banners-mobile/1/image.jpg"
            },
            "status": true,
            "products": [{ "id": 1, "name": "Product", ... }]
        }
    ],
    "page": 1,
    "current_page": 1,
    "from": 1,
    "to": 15,
    ...
}
```

Note: Duplicate pagination keys (`page`/`current_page`).

## 2. Create Banner

**POST** `/api/v1/banners`

| Field | Type | Required | Rules |
|-------|------|----------|-------|
| `title.en` | string | Yes | max:255, min:3, unique |
| `title.ar` | string | Yes | max:255, min:3, unique |
| `description.en` | string | No | max:500, min:5 |
| `description.ar` | string | No | max:500, min:5 |
| `image_desktop` | file | Yes | image, mimes:jpeg,png,jpg,gif, max:2048 |
| `image_mobile` | file | Yes | image, mimes:jpeg,png,jpg,gif, max:2048 |
| `status` | bool | No | in:0,1 |
| `products` | array | No | exists:products,id |

Transactional: creates banner + syncs products + uploads images via MediaLibrary.

## 3. Show Banner

**GET** `/api/v1/banners/{id}`

Returns single banner with products loaded.

## 4. Update Banner

**PUT** `/api/v1/banners/{id}`

Same fields as store, all optional. Images can be re-uploaded (replaces existing).

## 5. Delete Banner

**DELETE** `/api/v1/banners/{id}`

Soft deletes.

## 6. Change Status

**PUT** `/api/v1/banner/change-status`

**Body:** `id` (required, exists:banners,id)

Toggles status (true→false, false→true).

## 7. Reorder Banners

**POST** `/api/v1/banner/reorder`

**Body:** `banners` (required, array of IDs in desired order)

Uses Spatie Sortable `setNewOrder()`.

## Business Rules

1. **Slug auto-generated** from English title on save (via `saving` booted event)
2. **Transactional create/update** — image upload failure rolls back banner
3. **Image replacement** — update replaces, does not append
4. **Duplicate routes** — `banners` apiResource appears twice in Routes.php (lines 217 and 259)
