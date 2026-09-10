# Changelog - Shipping Feature

## [Unreleased]

### Added
- Country CRUD: 5 standard + 2 custom endpoints (governorates list, bulk status)
- Governorate CRUD: 5 standard + 3 custom endpoints (cities list, bulk status, fast-shipping toggle)
- City CRUD: 5 standard endpoints
- Repository pattern with search on JSON translatable columns
- Database hierarchy with FK constraints (CASCADE on delete)
- Governorate create/update with nested ShippingPrice upsert
- Bulk status update for countries and governorates
- Fast shipping toggle per governorate
- 12 Spatie permissions for granular access control
- Spatie Translatable for multilingual name fields
- UniqueTranslationRule validation for unique names per locale

### Fixed
- Governorate `PUT /change-status` route ordering: moved before `apiResource` to prevent Laravel matching `"change-status"` as `{id}` parameter (caused `TypeError: must be of type int, string given`)

### Known Issues
- Missing EN/AR translation keys for country and city response messages
- No auth:sanctum middleware on route group (relies on Spatie permission middleware)
- Governorate delete with cities throws unhandled exception (500 error)
- No test coverage for Country/Governorate/City CRUD
- JSON column search is not indexable (requires full table scan)
