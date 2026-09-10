# API Reference — Pickup Location Feature

Admin prefix `/api/v1` (`auth:sanctum` + `throttle:admin`). Public prefix `/api/v1/general` (`throttle:public-api`, no auth).

All success/error responses use the shared envelope produced by the `ApiResponse` trait:

```json
{
    "status": <int>,
    "message": "<translated message>",
    "success": true,
    "data": ...
}
```

Validation failures return a **flat errors object** with HTTP 422 (`response()->json($validator->errors(), 422)`).

---

## 1. Admin: List Pickup Locations

**GET** `/api/v1/pickup-locations`

**Authentication:** Sanctum token + `view-pickup-locations` permission

**Query Parameters:**

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `per_page` | int | 15 | Page size (falls back to `limit`) |
| `limit` | int | 15 | Page size fallback |
| `search` | string | — | Filters `store_name` LIKE `%search%` |
| `active` | `"true"` / `"1"` | — | Only active locations |
| `inactive` | `"true"` / `"1"` | — | Only inactive locations |

Sorted by `display_order ASC`, then `id ASC`. Result is cached under the `PICKUP_LOCATIONS` tag keyed by `md5(fullUrl)`.

**Response 200:** *(pagination metadata is manually extracted — note duplicate `page`/`current_page` keys)*

```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": {
        "data": [
            {
                "id": 1,
                "store_name": "Downtown Branch",
                "address": "123 Main St",
                "phone": "01000000001",
                "email": null,
                "latitude": null,
                "longitude": null,
                "working_hours": [
                    {"day": ["Monday", "الاثنين"], "open": "09:00", "close": "21:00"}
                ],
                "status": true,
                "display_order": 1,
                "is_default": true,
                "created_at": "2026-07-20T10:00:00.000000Z"
            }
        ],
        "page": 1,
        "current_page": 1,
        "from": 1,
        "to": 15,
        "last_page": 1,
        "path": "https://example.com/api/v1/pickup-locations",
        "per_page": 15,
        "total": 1,
        "next_page_url": "",
        "prev_page_url": "",
        "last_page_url": "",
        "first_page_url": ""
    }
}
```

---

## 2. Admin: Create Pickup Location

**POST** `/api/v1/pickup-locations`

**Authentication:** Sanctum token + `create-pickup-location` permission

**Request Body:**

```json
{
    "store_name": "Downtown Branch",
    "address": "123 Main St",
    "phone": "01000000001",
    "email": "branch@example.com",
    "latitude": "30.0444",
    "longitude": "31.2357",
    "working_hours": [
        {
            "day": ["Monday", "الاثنين"],
            "open": "09:00",
            "close": "21:00"
        }
    ],
    "status": 1,
    "display_order": 1,
    "is_default": false
}
```

> **Important:** On CREATE, `working_hours.*.day` is an **array of strings** (`working_hours.*.day.*`). This differs from UPDATE where `day.ar` / `day.en` string keys are used.

**Validation Rules (StorePickupLocationRequest):**

| Field | Rules |
|-------|-------|
| `store_name` | required, string, max:255 |
| `address` | required, string |
| `phone` | required, string, max:50 |
| `email` | nullable, email, max:255 |
| `latitude` | nullable, string, max:50 |
| `longitude` | nullable, string, max:50 |
| `working_hours` | nullable, array |
| `working_hours.*.day` | required_with:working_hours, array |
| `working_hours.*.day.*` | required_with:working_hours, string |
| `working_hours.*.open` | required_with:working_hours, string |
| `working_hours.*.close` | required_with:working_hours, string |
| `status` | sometimes, in:1,0 |
| `display_order` | sometimes, integer, min:0 |
| `is_default` | sometimes, boolean |

**Default switching:** Setting `is_default: true` atomically clears the flag on every other location (including soft-deleted) — exactly one default is guaranteed. Omitting `is_default` defaults to `false`.

On success the `PICKUP_LOCATIONS` cache tag is flushed and an activity log job is dispatched (queued).

**Response 200:**

```json
{
    "status": 200,
    "message": "Pickup location created successfully",
    "success": true,
    "data": {
        "id": 2,
        "store_name": "Downtown Branch",
        "address": "123 Main St",
        "phone": "01000000001",
        "email": "branch@example.com",
        "latitude": "30.0444",
        "longitude": "31.2357",
        "working_hours": [
            {"day": ["Monday", "الاثنين"], "open": "09:00", "close": "21:00"}
        ],
        "status": true,
        "display_order": 1,
        "is_default": false,
        "created_at": "2026-08-22T09:00:00.000000Z"
    }
}
```

**Response 422 (validation):**

```json
{
    "store_name": ["The store name field is required."],
    "phone": ["The phone field is required."]
}
```

---

## 3. Admin: Show Pickup Location

**GET** `/api/v1/pickup-locations/{id}`

**Authentication:** Sanctum token + `view-pickup-locations` permission

Returns same resource structure as list item (includes `created_at`).

**Response 200:** envelope with resource. On failure (non-existent / soft-deleted) a `MarvelException(NOT_FOUND)` is thrown.

---

## 4. Admin: Update Pickup Location

**PUT** `/api/v1/pickup-locations/{id}`

**Authentication:** Sanctum token + `update-pickup-location` permission

All fields are optional (`sometimes`).

**Request Body:**

```json
{
    "store_name": "Downtown Branch (Renovated)",
    "phone": "01000000002",
    "is_default": true
}
```

With working hours (UPDATE format — translatable day keys):

```json
{
    "working_hours": [
        {
            "day": {"ar": "الاثنين", "en": "Monday"},
            "open": "09:00",
            "close": "21:00"
        }
    ]
}
```

**Validation Rules (UpdatePickupLocationRequest):**

| Field | Rules |
|-------|-------|
| `store_name` | sometimes, string, max:255 |
| `address` | sometimes, string |
| `phone` | sometimes, string, max:50 |
| `email` | nullable, email, max:255 |
| `latitude` | nullable, string, max:50 |
| `longitude` | nullable, string, max:50 |
| `working_hours` | nullable, array |
| `working_hours.*.day.ar` | required_with:working_hours, string |
| `working_hours.*.day.en` | required_with:working_hours, string |
| `working_hours.*.open` | required_with:working_hours, string |
| `working_hours.*.close` | required_with:working_hours, string |
| `status` | sometimes, in:1,0 |
| `display_order` | sometimes, integer, min:0 |
| `is_default` | sometimes, boolean |

> **Note:** Update uses translatable `day.ar`/`day.en` keys (strings) instead of the flat `day` **array** used in Create.

**Default behavior:**
- Setting `is_default: true` switches the default (others reset atomically).
- Updating other fields of the current default preserves `is_default`.
- Setting `is_default: false` just clears it (no auto-promotion).
- Deleting the default promotes the remaining location with the lowest `id`.

On success the `PICKUP_LOCATIONS` cache tag is flushed and activity jobs are dispatched (status change detected separately from field updates).

**Response 200:** envelope (`PICKUP_LOCATION_UPDATED_SUCCESSFULLY`) with refreshed resource.

---

## 5. Admin: Delete Pickup Location

**DELETE** `/api/v1/pickup-locations/{id}`

**Authentication:** Sanctum token + `delete-pickup-location` permission

Uses SoftDeletes — record is not hard-removed. If the deleted location was the default, the remaining location with the lowest `id` is promoted automatically. The `PICKUP_LOCATIONS` cache tag is flushed.

**Response 200:**

```json
{
    "status": 200,
    "message": "Pickup location deleted successfully",
    "success": true
}
```

---

## 6. Public: List Active Pickup Locations

**GET** `/api/v1/general/pickup-locations`

**Authentication:** None (public, `throttle:public-api`)

Returns only `status = true` locations ordered by `display_order`, then `id`. Query params: `limit` (default **10**), `search` (store_name LIKE), `default` (falsy values ignored; truthy value like `1` or `true` filters to only the default location). Each item includes `is_default` so the frontend can pre-select the default branch. Not cached.

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `limit` | int | 10 | Page size |
| `search` | string | — | Filters `store_name` LIKE `%search%` |
| `default` | mixed | false | Truthy value (`1`, `"true"`) → only the location with `is_default = true` |

**Request example:** `GET /api/v1/general/pickup-locations?default=1`

**Response 200:** *(standard Laravel paginator structure inside `data`)*

```json
{
    "status": 200,
    "message": "Data fetched successfully",
    "success": true,
    "data": {
        "data": [
            {
                "id": 1,
                "store_name": "Downtown Branch",
                "address": "123 Main St",
                "phone": "01000000001",
                "email": null,
                "latitude": null,
                "longitude": null,
                "working_hours": [
                    {"day": ["Monday", "الاثنين"], "open": "09:00", "close": "21:00"}
                ],
                "status": true,
                "display_order": 1,
                "is_default": true
            }
        ],
        "links": {
            "first": "https://example.com/api/v1/general/pickup-locations?page=1",
            "last": "https://example.com/api/v1/general/pickup-locations?page=1",
            "prev": null,
            "next": null
        },
        "meta": {
            "current_page": 1,
            "from": 1,
            "last_page": 1,
            "path": "https://example.com/api/v1/general/pickup-locations",
            "per_page": 10,
            "to": 1,
            "total": 1
        }
    }
}
```

---

## 7. Public: Show Pickup Location

**GET** `/api/v1/general/pickup-locations/{id}`

**Authentication:** None (public)

Returns the location only if **active** (`status = true`). Inactive or soft-deleted locations return **404** via the plain envelope (no exception handler):

**Response 404:**

```json
{
    "status": 404,
    "message": "Not found",
    "success": false
}
```

**Response 200:** envelope with the resource (no `created_at` on the public resource).