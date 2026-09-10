# Backend — Pickup Location Feature

## Controllers

### Admin: PickupLocationController (`packages/marvel/src/Http/Controllers/PickupLocationController.php`)

Route group: `auth:sanctum` + `throttle:admin`. Permissions are registered in the constructor:

| Method | Permission | Description |
|--------|------------|-------------|
| `index` | `view-pickup-locations` | Paginated list (default 15), optional search/active/inactive filter, ordered by display_order → id; cached under `PICKUP_LOCATIONS` tag |
| `store` | `create-pickup-location` | Create with validated data; flush `PICKUP_LOCATIONS` cache tag |
| `show` | `view-pickup-locations` | Find or fail by ID (throws `MarvelException(NOT_FOUND)`) |
| `update` | `update-pickup-location` | Find, update, return refreshed; flush `PICKUP_LOCATIONS` cache tag |
| `destroy` | `delete-pickup-location` | Find, soft delete; flush `PICKUP_LOCATIONS` cache tag |

**Note:** `index()` manually extracts pagination meta from ResourceCollection (duplicate keys `page`/`current_page`).

### Public: PickupLocationController (`app/Http/Controllers/Api/General/PickupLocationController.php`)

Route group: `v1/general` prefix + `throttle:public-api`, no auth. Uses the **service layer** (`App\Services\General\PickupLocationService`), not the repository directly. Not cached.

- `index`: only active ordered locations; default page size **10** (`limit` query param); optional `search`
- `show`: `PickupLocation::active()->findOrFail($id)`; catches any exception → plain 404 envelope via `apiResponse(NOT_FOUND, 404, false)` (NOT a `MarvelException`)

### Service: PickupLocationService (`app/Services/General/PickupLocationService.php`)

| Method | Behavior |
|--------|----------|
| `getPickupLocations($request)` | `active()->ordered()`, optional store_name LIKE search, optional `default` filter (`query('default')` truthy → `where('is_default', true)`), paginate(`limit`, default 10) |
| `getPickupLocationById($id)` | `active()->findOrFail($id)` |
| `getDefaultPickupLocation()` | `default()->active()->first()` — returns active default or `null` |

## Observer — `App\Observers\PickupLocationObserver`

All events dispatch **queued** `LogActivityJob` entries using `activity.*` translation keys:

| Event | Job action | Payload |
|-------|-----------|---------|
| `created` | `created` | model class + id + auth user |
| `updated` | `statusChanged` (when `status` changed) | old/new status values |
| `updated` | `updated` (other dirty fields, excluding `updated_at`) | old/new values per field |
| `deleted` | `deleted` | model class + id |

Note: a single update can dispatch **two** jobs when both `status` and other fields change.

## Repository — `PickupLocationRepository`

Extends `Prettus\Repository\Eloquent\BaseRepository`. Uses `RequestCriteria`.

```php
protected $fieldSearchable = ['store_name' => 'like'];
```

## Model — `PickupLocation` (`packages/marvel/src/Database/Models/PickupLocation.php`)

**Table:** `pickup_locations` — SoftDeletes

| Column | Type | Default |
|--------|------|---------|
| `store_name` | string | |
| `address` | text | |
| `phone` | string | |
| `email` | string (nullable) | |
| `latitude` | string (nullable) | |
| `longitude` | string (nullable) | |
| `working_hours` | json (nullable) | |
| `status` | boolean | true |
| `display_order` | integer | 0 |
| `is_default` | boolean | false |
| `deleted_at` | timestamp (nullable) | SoftDeletes |

**Casts:** `working_hours` array, `status` boolean, `is_default` boolean.

**Scopes:** `active()` (status=true), `inactive()` (status=false), `ordered()` (display_order → id), `default()` (is_default=true)

**Relations:** `orders(): HasMany` via `orders.pickup_location_id`

**Model hooks (enforce exactly-one-default invariant):**
- `saving`: when `is_default` is set to `true` (dirty), a single atomic `UPDATE` clears the flag on all other rows (incl. soft-deleted) before the change persists.
- `deleted`: when the deleted location was the default, the remaining location with the lowest `id` is promoted to default.

## Form Requests

### StorePickupLocationRequest

| Field | Rules |
|-------|-------|
| `store_name` | required, string, max:255 |
| `address` | required, string |
| `phone` | required, string, max:50 |
| `email` | nullable, email, max:255 |
| `latitude` | nullable, string, max:50 |
| `longitude` | nullable, string, max:50 |
| `working_hours` | nullable, array |
| `working_hours.*.day` | required_with:working_hours, **array** |
| `working_hours.*.day.*` | required_with:working_hours, string |
| `working_hours.*.open` | required_with:working_hours, string |
| `working_hours.*.close` | required_with:working_hours, string |
| `status` | sometimes, in:1,0 |
| `display_order` | sometimes, integer, min:0 |
| `is_default` | sometimes, boolean |

### UpdatePickupLocationRequest

All fields optional (`sometimes`) except where noted:

| Field | Rules | Difference from Store |
|-------|-------|----------------------|
| `store_name` | sometimes, string, max:255 | |
| `address` | sometimes, string | |
| `phone` | sometimes, string, max:50 | |
| `email` | nullable, email, max:255 | |
| `latitude` | nullable, string, max:50 | |
| `longitude` | nullable, string, max:50 | |
| `working_hours` | nullable, array | |
| `working_hours.*.day.ar` | required_with:working_hours, string | Translatable day name (Arabic key) |
| `working_hours.*.day.en` | required_with:working_hours, string | Translatable day name (English key) |
| `working_hours.*.open` | required_with:working_hours, string | |
| `working_hours.*.close` | required_with:working_hours, string | |
| `status` | sometimes, in:1,0 | |
| `display_order` | sometimes, integer, min:0 | |
| `is_default` | sometimes, boolean | |

Both requests return validation errors as a flat JSON object with HTTP 422 (`failedValidation` override).

## Resources

### Admin — `Marvel\Http\Resources\PickupLocationResource`

| Field | Source |
|-------|--------|
| `id` | `$this->id` |
| `store_name` | `$this->store_name` |
| `address` | `$this->address` |
| `phone` | `$this->phone` |
| `email` | `$this->email` |
| `latitude` | `$this->latitude` |
| `longitude` | `$this->longitude` |
| `working_hours` | `$this->working_hours` (array) |
| `status` | `(bool) $this->status` |
| `display_order` | `$this->display_order` |
| `is_default` | `(bool) $this->is_default` |
| `created_at` | `$this->created_at` |

### Public — `App\Http\Resources\PickupLocation\PickupLocationResource`

Same fields as admin **minus `created_at`**. Includes `is_default`.

## Caching

| Endpoint | Cache |
|----------|-------|
| Admin GET /pickup-locations | Cached under `PICKUP_LOCATIONS` tag (`HasCache::remember(PICKUP_LOCATIONS, md5(fullUrl), $data)`) |
| Public GET /general/pickup-locations | NOT cached |
| POST / PUT / DELETE | Flush `PICKUP_LOCATIONS` tag after successful write |

## Permissions (4 Spatie permissions)

| Permission Slug | Used On |
|----------------|---------|
| `view-pickup-locations` | index, show |
| `create-pickup-location` | store |
| `update-pickup-location` | update |
| `delete-pickup-location` | destroy |

## Migrations

| Migration | Purpose |
|-----------|---------|
| `database/migrations/2026_07_11_000003_create_pickup_locations_table.php` | Creates table **including** `is_default` column (no separate add-is-default migration exists) |
| `database/migrations/2026_07_11_000004_add_pickup_location_snapshot_to_orders.php` | Snapshot columns on `orders` |

## Translations

Both EN and AR have all message keys present:

| Key | EN | AR |
|-----|----|-----|
| `MESSAGE.PICKUP_LOCATION_CREATED_SUCCESSFULLY` | Pickup location created successfully | تم إنشاء موقع الاستلام بنجاح |
| `MESSAGE.PICKUP_LOCATION_UPDATED_SUCCESSFULLY` | Pickup location updated successfully | تم تحديث موقع الاستلام بنجاح |
| `MESSAGE.PICKUP_LOCATION_DELETED_SUCCESSFULLY` | Pickup location deleted successfully | تم حذف موقع الاستلام بنجاح |

Activity logging uses `activity.pickup_location_created` / `_updated` / `_deleted` / `_activated` / `_deactivated`.