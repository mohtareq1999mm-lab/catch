# Pickup Location Feature - API Investigation

## Feature Name

Pickup Location Management

## Description

CRUD for pickup locations where customers can collect orders. Uses SoftDeletes. Admin endpoints (permission-gated) and public endpoints (active-only). The pickup location snapshot is saved on the order at checkout time.

## Architecture

```
[Admin Client]                    [Public (no auth)]
    |                                  |
    |--- GET /pickup-locations         |--- GET /general/pickup-locations
    |--- POST /pickup-locations        |--- GET /general/pickup-locations/{id}
    |--- GET /pickup-locations/{id}
    |--- PUT /pickup-locations/{id}
    |--- DELETE /pickup-locations/{id}
    |
    v
[PickupLocationController (admin)]   [PickupLocationController (public)]
    |--- DI of PickupLocationRepository          |--- DI of PickupLocationService
    |--- Permission middleware (Spatie)          |--- active-only reads, no auth
    |
    v                                            v
[PickupLocationRepository]              [PickupLocationService]
    |--- extends BaseRepository (Prettus)       |--- getPickupLocations (limit 10)
    |--- $fieldSearchable ('store_name' => 'like')  |--- getPickupLocationById
                                                |--- getDefaultPickupLocation
    |
    v
[PickupLocation Model]
    |--- SoftDeletes
    |--- scopes: active, inactive, ordered, default
    |--- $casts: working_hours (array), status (boolean), is_default (boolean)
    |--- booted hooks: atomic default-switch (saving) + default promotion (deleted)
    |
    v
[PickupLocationResource]
    |--- id, store_name, address, phone, email, lat/lng, working_hours, status, display_order, is_default
```

## Key Endpoints

| Method | URI | Controller | Permission | Auth |
|--------|-----|------------|------------|------|
| GET | `/pickup-locations` | Admin index | VIEW_PICKUP_LOCATIONS | sanctum |
| POST | `/pickup-locations` | Admin store | CREATE_PICKUP_LOCATION | sanctum |
| GET | `/pickup-locations/{id}` | Admin show | VIEW_PICKUP_LOCATIONS | sanctum |
| PUT | `/pickup-locations/{id}` | Admin update | UPDATE_PICKUP_LOCATION | sanctum |
| DELETE | `/pickup-locations/{id}` | Admin destroy | DELETE_PICKUP_LOCATION | sanctum |
| GET | `/general/pickup-locations` | Public index | None | None |
| GET | `/general/pickup-locations/{id}` | Public show | None | None |

## Key Files

| Layer | Path |
|-------|------|
| Controller (Admin) | `packages/marvel/src/Http/Controllers/PickupLocationController.php` |
| Controller (Public) | `app/Http/Controllers/Api/General/PickupLocationController.php` |
| Service (Public) | `app/Services/General/PickupLocationService.php` |
| Observer | `app/Observers/PickupLocationObserver.php` (queued LogActivityJob on created/updated/deleted) |
| Model | `packages/marvel/src/Database/Models/PickupLocation.php` |
| Repository | `packages/marvel/src/Database/Repositories/PickupLocationRepository.php` |
| Resource (Admin) | `packages/marvel/src/Http/Resources/PickupLocationResource.php` (includes `created_at`) |
| Resource (Public) | `app/Http/Resources/PickupLocation/PickupLocationResource.php` (no `created_at`) |
| Request (Store) | `packages/marvel/src/Http/Requests/StorePickupLocationRequest.php` — `working_hours.*.day` is an **array** of strings |
| Request (Update) | `packages/marvel/src/Http/Requests/UpdatePickupLocationRequest.php` — all fields optional (`sometimes`), `working_hours.*.day.ar`/`.en` string keys |
| Migrations | `database/migrations/2026_07_11_000003_create_pickup_locations_table.php` (**includes** `is_default` column; no separate add-is-default migration exists) |
| Migration (order snapshot) | `database/migrations/2026_07_11_000004_add_pickup_location_snapshot_to_orders.php` |
| Routes (Admin) | `packages/marvel/src/Rest/Routes.php` |
| Routes (Public) | `routes/api.php` |
| Enum (Permission) | `packages/marvel/src/Enums/Permission.php` |
| Translation (EN) | `resources/lang/en/message.php` |
| Translation (AR) | `resources/lang/ar/message.php` |
| Test (CRUD) | `tests/Feature/PickupLocationTest.php` |
| Test (Integration) | `tests/Feature/PickupLocationPricingIntegrationTest.php` |

## Tech Stack

- **Laravel** with Eloquent ORM
- **Sanctum** authentication (admin only)
- **Spatie permissions** (4 permissions)
- **Prettus BaseRepository** pattern
- **SoftDeletes** for safe deletion
- **JSON casts** for `working_hours` array
- **Active/inactive scopes** for filtering
- **Default pickup location** (`is_default`): single default enforced atomically; exposed on both APIs; auto-promotion on delete
