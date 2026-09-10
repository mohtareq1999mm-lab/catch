# Changelog - Pickup Location Feature

## [Unreleased]

### Added
- Admin CRUD: 5 endpoints for pickup location management
- Public API: 2 endpoints for active-only pickup locations
- SoftDeletes for safe deletion
- Working hours support (JSON array with day/open/close)
- Display ordering for custom sort
- Pickup location snapshot on order at checkout
- 4 Spatie permissions for granular access control
- Full translation support (EN + AR)
- 58+ test methods covering CRUD, validation, authorization, checkout integration
- `UpdatePickupLocationRequest` with per-field validation rules (all optional, `sometimes`)
- Update request supports translatable `working_hours.*.day.ar`/`.en` keys (Arabic + English)
- Default pickup location (`is_default` boolean column, included in create migration `2026_07_11_000003_create_pickup_locations_table`):
  - Single default enforced atomically — setting `is_default: true` clears the flag on all other locations
  - Updating other fields of the default preserves `is_default`
  - Deleting the default auto-promotes the next location by lowest `id`
  - Exposed on admin + public APIs (`is_default` field)
  - Validation rule (`sometimes|boolean`) on store + update requests
  - `PickupLocationService::getDefaultPickupLocation()` returns the active default
  - Admin controller flushes the `PICKUP_LOCATIONS` cache tag on create/update/delete (fixes stale admin list)
  - Seeder marks first location as default and is now idempotent (`updateOrCreate`)
  - 11 new feature tests covering create/update/delete default behavior and API exposure

### Known Issues
- Pagination meta has duplicate `page`/`current_page` keys in admin list response
