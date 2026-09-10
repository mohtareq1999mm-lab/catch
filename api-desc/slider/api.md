# API Documentation - Slider Feature

Admin prefix `/api/v1`. Public prefix `/api/v1/general`.

## 1. Admin: List Sliders

**GET** `/api/v1/sliders`

**Query:** `per_page` or `limit` (default 15), `active` (boolean), `order` (column), `sortedBy` (asc/desc)

```json
{
    "data": [
        {
            "id": 1,
            "title": {"en": "Summer Sale", "ar": "تخفيضات الصيف"},
            "slug": "summer-sale",
            "status": true,
            "order": 1,
            "image": {
                "desktop": "https://.../sliders-desktop/1/image.jpg",
                "mobile": "https://.../sliders-mobile/1/image.jpg"
            },
            "products": [{"id": 1, "name": "Product", "slug": "...", "status": true, "image": {...}}]
        }
    ],
    "page": 1, "current_page": 1, "from": 1, "to": 15, ...
}
```

Note: Duplicate pagination keys (`page`/`current_page`).

## 2. Admin: Create Slider

**POST** `/api/v1/sliders`

| Field | Type | Required | Rules |
|-------|------|----------|-------|
| `title.en` | string | Yes | unique |
| `title.ar` | string | Yes | unique |
| `image_desktop` | file | Yes | image, mimes:jpeg,png,jpg,gif, max:2048 |
| `image_mobile` | file | Yes | image, mimes:jpeg,png,jpg,gif, max:2048 |
| `status` | bool | No | in:1,0 |
| `products` | array | No | exists:products,id |

Transactional: creates slider + uploads images + syncs products.

## 3. Admin: Show Slider

**GET** `/api/v1/sliders/{id}`

Returns single slider with products loaded and full title translations.

## 4. Admin: Update Slider

**PUT** `/api/v1/sliders/{id}`

Same fields, all optional. Images replaced if provided.

## 5. Admin: Delete Slider

**DELETE** `/api/v1/sliders/{id}`

Soft deletes.

## 6. Admin: Change Status

**PATCH** `/api/v1/sliders/change-status`

**Body:** `id` (required, exists:sliders,id). Toggles status boolean.

## 7. Admin: Reorder

**PUT** `/api/v1/sliders/reorder`

**Body:** `sliders` (required, array of IDs in desired order). Uses `setNewOrder()`.

## 8. Public: List Active Sliders

**GET** `/api/v1/general/sliders`

No auth. Returns only active sliders. Optional `slug` query param.

## 9. Public: Show Slider by Slug

**GET** `/api/v1/general/sliders/{slug}`

Returns enriched slider with full product pricing data (not just basic info).
