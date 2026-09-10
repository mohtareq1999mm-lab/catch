# Frontend — Pickup Location Feature

## Status

Admin SPA manages pickup locations. Checkout SPA selects them during fulfillment.

---

## Consumption (Admin)

```javascript
export const pickupLocationApi = {
  list(params)        // GET /api/v1/pickup-locations?search=&active=&inactive=&per_page=
  create(data)        // POST /api/v1/pickup-locations
  show(id)            // GET /api/v1/pickup-locations/{id}
  update(id, data)    // PUT /api/v1/pickup-locations/{id}
  delete(id)          // DELETE /api/v1/pickup-locations/{id}
}
```

### 1. List — GET /api/v1/pickup-locations

**Query params:** `per_page` (default 15), `search`, `active=true|1`, `inactive=true|1`

**Response 200:**

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

> Note the duplicate `page`/`current_page` keys (backend behavior).

### 2. Create — POST /api/v1/pickup-locations

**Request Body** (`working_hours.*.day` is an **ARRAY**):

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

Required: `store_name`, `address`, `phone`. All others optional.

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

### 3. Show — GET /api/v1/pickup-locations/{id}

**Request:** path parameter `id` only.

**Response 200:** envelope with a single resource — same shape as the list item above (`data` contains the resource object directly, including `created_at`).

### 4. Update — PUT /api/v1/pickup-locations/{id}

**Request Body** (all fields optional; `working_hours.*.day` uses **`ar`/`en` keys**):

```json
{
    "store_name": "Downtown Branch (Renovated)",
    "phone": "01000000002",
    "working_hours": [
        {
            "day": {"ar": "الاثنين", "en": "Monday"},
            "open": "09:00",
            "close": "21:00"
        }
    ],
    "status": 1,
    "display_order": 2,
    "is_default": true
}
```

> **Critical difference:** CREATE expects `working_hours.*.day` as an **array of strings**; UPDATE expects `day.ar` / `day.en` **object keys**. Sending the wrong shape fails validation with 422.

**Response 200:**

```json
{
    "status": 200,
    "message": "Pickup location updated successfully",
    "success": true,
    "data": {
        "id": 2,
        "store_name": "Downtown Branch (Renovated)",
        "address": "123 Main St",
        "phone": "01000000002",
        "email": "branch@example.com",
        "latitude": "30.0444",
        "longitude": "31.2357",
        "working_hours": [
            {"day": {"ar": "الاثنين", "en": "Monday"}, "open": "09:00", "close": "21:00"}
        ],
        "status": true,
        "display_order": 2,
        "is_default": true,
        "created_at": "2026-08-22T09:00:00.000000Z"
    }
}
```

Setting `is_default: true` atomically clears it on every other branch.

### 5. Delete — DELETE /api/v1/pickup-locations/{id}

**Request:** path parameter `id` only.

**Response 200:**

```json
{
    "status": 200,
    "message": "Pickup location deleted successfully",
    "success": true
}
```

Soft delete — if the deleted location was the default, the next branch (lowest `id`) is promoted automatically.

### Validation failures (Create / Update)

HTTP 422 with a flat errors object:

```json
{
    "store_name": ["The store name field is required."],
    "phone": ["The phone field is required."]
}
```

---

## Consumption (Public/Checkout)

```javascript
// No auth required
export const publicPickupApi = {
  list(limit, search) // GET /api/v1/general/pickup-locations?limit=10&search=
  default()           // GET /api/v1/general/pickup-locations?default=1
  show(id)            // GET /api/v1/general/pickup-locations/{id}
}
```

### 6. Public List — GET /api/v1/general/pickup-locations

**Query params:** `limit` (default **10**), `search`, `default`.

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `limit` | int | 10 | Page size |
| `search` | string | — | store_name LIKE filter |
| `default` | mixed | false | Truthy value (`1`, `"true"`) → returns only the default branch |

**Request examples:**
```
GET /api/v1/general/pickup-locations?limit=10&search=Downtown
GET /api/v1/general/pickup-locations?default=1
```

**Response 200** (standard Laravel paginator inside `data`):

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

Only active locations are returned; items have **no `created_at`** on this endpoint.

### 7. Public Show — GET /api/v1/general/pickup-locations/{id}

**Request:** path parameter `id` only.

**Response 200:** envelope with a single resource (no `created_at`).

**Response 404** (inactive or soft-deleted):

```json
{
    "status": 404,
    "message": "Not found",
    "success": false
}
```

---

## Default Branch Behavior

- Toggling `is_default: true` switches the default — the backend atomically clears it on every other branch.
- Setting a different branch as default preserves the previous default's other fields.
- Deleting the default auto-promotes the next branch (lowest `id`).
- Recommend showing a "Make Default" toggle/star on each row and calling `PUT /pickup-locations/{id}` with `{ "is_default": true }`. The backend flushes the list cache automatically.
- Preselect the item with `is_default === true` in the checkout selector. User selection is frontend-only state and never mutates `is_default` (admin/system configuration).

## Expected Frontend Components

```
PickupLocationsList.vue   → admin list with search, active/inactive filter, default badge + "Make Default" action
PickupLocationForm.vue    → admin create/edit (store_name, address, phone, hours, map, is_default)
PickupLocationSelector.vue → public dropdown for checkout (active only, preselect default)
```