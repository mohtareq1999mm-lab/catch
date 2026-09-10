# Pickup Locations API

Base path: `/api/v1`

---

## Admin CRUD Endpoints

### GET `/pickup-locations`

List all pickup locations (paginated).

```
GET /api/v1/pickup-locations?per_page=15&page=1&search=Downtown&active=true
```

#### Auth
- `auth:sanctum`, `verified`
- Permission: `view-pickup-locations`

#### Query Parameters

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| per_page | integer | no | Items per page (default: 15) |
| page | integer | no | Page number |
| search | string | no | Search by store_name |
| active | string | no | Filter active only (`true`/`1`) |
| inactive | string | no | Filter inactive only (`true`/`1`) |

#### Response
```json
{
  "status": 200,
  "message": "Fetched data successfully",
  "success": true,
  "data": {
    "data": [
      {
        "id": 1,
        "store_name": "Downtown Main Branch",
        "address": "123 El Tahrir Street, Downtown, Cairo",
        "phone": "01000000001",
        "email": "downtown@pickup.example.com",
        "latitude": "30.0444",
        "longitude": "31.2357",
        "working_hours": [
          {"day": "Saturday", "open": "09:00", "close": "21:00"}
        ],
        "status": true,
        "display_order": 1,
        "created_at": "2026-07-11T00:00:00.000000Z"
      }
    ],
    "page": 1,
    "current_page": 1,
    "from": 1,
    "to": 15,
    "last_page": 1,
    "path": "http://localhost:8000/api/v1/pickup-locations",
    "per_page": 15,
    "total": 1,
    "next_page_url": null,
    "prev_page_url": null,
    "last_page_url": null,
    "first_page_url": null
  }
}
```

---

### POST `/pickup-locations`

Create a new pickup location.

```
POST /api/v1/pickup-locations
Content-Type: application/json

{
  "store_name": "New Store",
  "address": "456 Oak Ave",
  "phone": "01000000002",
  "email": "store@test.com",
  "latitude": "30.0444",
  "longitude": "31.2357",
  "working_hours": [
    {"day": "Monday", "open": "09:00", "close": "21:00"}
  ],
  "status": true,
  "display_order": 1
}
```

#### Auth
- `auth:sanctum`, `verified`
- Permission: `create-pickup-location`

#### Request Parameters

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| store_name | string | yes | Store/branch name (max 255) |
| address | string | yes | Full address |
| phone | string | yes | Contact phone (max 50) |
| email | email | no | Contact email |
| latitude | string | no | Latitude coordinate (max 50) |
| longitude | string | no | Longitude coordinate (max 50) |
| working_hours | array | no | Array of day/hour objects |
| working_hours.*.day | string | required_with:working_hours | Day name |
| working_hours.*.open | string | required_with:working_hours | Opening time |
| working_hours.*.close | string | required_with:working_hours | Closing time |
| status | boolean | no | `1` active, `0` inactive (default: `1`) |
| display_order | integer | no | Sort order (default: 0, min: 0) |

#### Response
```json
{
  "status": 200,
  "message": "Pickup location created successfully",
  "success": true,
  "data": {
    "id": 1,
    "store_name": "New Store",
    "address": "456 Oak Ave",
    "phone": "01000000002",
    "email": "store@test.com",
    "latitude": "30.0444",
    "longitude": "31.2357",
    "working_hours": [
      {"day": "Monday", "open": "09:00", "close": "21:00"}
    ],
    "status": true,
    "display_order": 1,
    "created_at": "2026-07-11T00:00:00.000000Z"
  }
}
```

---

### GET `/pickup-locations/{id}`

Show a single pickup location.

```
GET /api/v1/pickup-locations/1
```

#### Auth
- `auth:sanctum`, `verified`
- Permission: `view-pickup-locations`

#### Response
```json
{
  "status": 200,
  "message": "Fetched data successfully",
  "success": true,
  "data": {
    "id": 1,
    "store_name": "Downtown Main Branch",
    "address": "123 El Tahrir Street, Downtown, Cairo",
    "phone": "01000000001",
    "email": "downtown@pickup.example.com",
    "latitude": "30.0444",
    "longitude": "31.2357",
    "working_hours": [
      {"day": "Saturday", "open": "09:00", "close": "21:00"}
    ],
    "status": true,
    "display_order": 1,
    "created_at": "2026-07-11T00:00:00.000000Z"
  }
}
```

---

### PUT `/pickup-locations/{id}`

Update a pickup location.

```
PUT /api/v1/pickup-locations/1
Content-Type: application/json

{
  "store_name": "Updated Name",
  "address": "123 Updated Street",
  "phone": "01000000099",
  "status": true,
  "display_order": 1
}
```

#### Auth
- `auth:sanctum`, `verified`
- Permission: `update-pickup-location`

#### Request Parameters

Same fields as POST, all optional (uses `sometimes` validation).

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| store_name | string | no | Store/branch name (max 255) |
| address | string | no | Full address |
| phone | string | no | Contact phone (max 50) |
| email | email | no | Contact email |
| latitude | string | no | Latitude coordinate (max 50) |
| longitude | string | no | Longitude coordinate (max 50) |
| working_hours | array | no | Array of day/hour objects |
| working_hours.*.day | string | required_with:working_hours | Day name |
| working_hours.*.open | string | required_with:working_hours | Opening time |
| working_hours.*.close | string | required_with:working_hours | Closing time |
| status | boolean | no | `1` active, `0` inactive |
| display_order | integer | no | Sort order (min: 0) |

#### Response
```json
{
  "status": 200,
  "message": "Pickup location updated successfully",
  "success": true,
  "data": {
    "id": 1,
    "store_name": "Updated Name",
    "address": "123 El Tahrir Street, Downtown, Cairo",
    "phone": "01000000001",
    "email": "downtown@pickup.example.com",
    "latitude": "30.0444",
    "longitude": "31.2357",
    "working_hours": [
      {"day": "Saturday", "open": "09:00", "close": "21:00"}
    ],
    "status": true,
    "display_order": 1,
    "created_at": "2026-07-11T00:00:00.000000Z"
  }
}
```

---

### DELETE `/pickup-locations/{id}`

Soft delete a pickup location.

#### Auth
- `auth:sanctum`, `verified`
- Permission: `delete-pickup-location`

#### Response
```json
{
  "status": 200,
  "message": "Pickup location deleted successfully",
  "success": true
}
```

---

## Public Endpoints

Base path: `/api/v1/general`

### GET `/pickup-locations`

List active pickup locations (no auth required).

```
GET /api/v1/general/pickup-locations?limit=10&search=Downtown
```

#### Query Parameters

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| limit | integer | no | Items per page (default: 10) |
| search | string | no | Search by store_name |

Returns only locations with `status = true`, ordered by `display_order`.

#### Response
```json
{
  "status": 200,
  "message": "Fetched data successfully",
  "success": true,
  "data": [
    {
      "id": 1,
      "store_name": "Downtown Main Branch",
      "address": "123 El Tahrir Street, Downtown, Cairo",
      "phone": "01000000001",
      "email": "downtown@pickup.example.com",
      "latitude": "30.0444",
      "longitude": "31.2357",
      "working_hours": [
        {"day": "Saturday", "open": "09:00", "close": "21:00"}
      ],
      "status": true,
      "display_order": 1
    }
  ]
}
```

---

### GET `/pickup-locations/{id}`

Show a single active pickup location.

```
GET /api/v1/general/pickup-locations/1
```

No auth required. Returns 404 if location is inactive or does not exist.

#### Response
```json
{
  "status": 200,
  "message": "Fetched data successfully",
  "success": true,
  "data": {
    "id": 1,
    "store_name": "Downtown Main Branch",
    "address": "123 El Tahrir Street, Downtown, Cairo",
    "phone": "01000000001",
    "email": "downtown@pickup.example.com",
    "latitude": "30.0444",
    "longitude": "31.2357",
    "working_hours": [
      {"day": "Saturday", "open": "09:00", "close": "21:00"}
    ],
    "status": true,
    "display_order": 1
  }
}
```

---

## Error Responses

### 400 Validation Error
```json
{
  "store_name": ["The store name field is required."],
  "address": ["The address field is required."],
  "phone": ["The phone field is required."]
}
```

### 401 Unauthorized
```json
{
  "message": "Unauthenticated.",
  "status": false
}
```

### 403 Forbidden
```json
{
  "message": "CHAWKBAZAR_ERROR.NOT_AUTHORIZED",
  "status": false
}
```

### 404 Not Found
```json
{
  "message": "CHAWKBAZAR_ERROR.ERROR.NOT_FOUND",
  "status": false
}
```

### 500 Server Error
```json
{
  "message": "CHAWKBAZAR_ERROR.COULD_NOT_CREATE_THE_RESOURCE",
  "status": false
}
```

---

## Database

### Table: `pickup_locations`

| Column | Type | Description |
|--------|------|-------------|
| id | bigint (PK) | Auto-increment |
| store_name | string(255) | Branch/store name |
| address | text | Full address |
| phone | string(50) | Contact phone |
| email | string(255) | Contact email |
| latitude | string(50) | Latitude |
| longitude | string(50) | Longitude |
| working_hours | json | Operating hours per day |
| status | boolean | Active/inactive |
| display_order | integer | Sort priority |
| created_at | timestamp | |
| updated_at | timestamp | |
| deleted_at | timestamp | Soft delete |

### Observers

`PickupLocationObserver` logs events to `activity_log`:
- `created` → "Pickup location created"
- `updated` → "Pickup location updated" (with old/new values)
- `statusChanged` → "Pickup location activated/deactivated"
- `deleted` → "Pickup location deleted"

---

## Dependencies

| Component | File |
|-----------|------|
| Admin Controller | `packages/marvel/src/Http/Controllers/PickupLocationController.php` |
| Public Controller | `app/Http/Controllers/Api/General/PickupLocationController.php` |
| Admin Service | `packages/marvel/src/Database/Repositories/PickupLocationRepository.php` |
| Public Service | `app/Services/General/PickupLocationService.php` |
| Model | `packages/marvel/src/Database/Models/PickupLocation.php` |
| Store Request | `packages/marvel/src/Http/Requests/StorePickupLocationRequest.php` |
| Update Request | `packages/marvel/src/Http/Requests/UpdatePickupLocationRequest.php` |
| Admin Resource | `packages/marvel/src/Http/Resources/PickupLocationResource.php` |
| Public Resource | `app/Http/Resources/PickupLocation/PickupLocationResource.php` |
| Observer | `app/Observers/PickupLocationObserver.php` |
| Seeder | `database/seeders/PickupLocationSeeder.php` |
| Permissions | `Marvel\Enums\Permission` (VIEW/CREATE/UPDATE/DELETE_PICKUP_LOCATION) |
| Translations EN | `resources/lang/en/permissions.php` |
| Translations AR | `resources/lang/ar/permissions.php` |

---

## Test Coverage

`tests/Feature/PickupLocationTest.php` — 19 tests, 48 assertions:

| Category | Tests |
|----------|-------|
| Admin List | admin_can_list_pickup_locations |
| Admin Create | admin_can_create_pickup_location |
| Admin Show | admin_can_show_pickup_location |
| Admin Update | admin_can_update_pickup_location |
| Admin Delete | admin_can_delete_pickup_location |
| Validation | store_requires_store_name, store_requires_address, store_requires_phone, store_accepts_valid_email, store_validates_display_order_is_integer |
| Authorization | unauthenticated_user_cannot_access_admin_endpoints, customer_cannot_create_pickup_location, customer_cannot_delete_pickup_location |
| Public API | public_can_list_active_pickup_locations, public_can_show_active_pickup_location, public_cannot_show_inactive_pickup_location |
| Edge Cases | returns_404_for_nonexistent_pickup_location, pickup_locations_are_ordered_by_display_order, admin_can_search_pickup_locations |
