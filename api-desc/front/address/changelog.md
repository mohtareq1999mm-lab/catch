# Address Module — Changelog

## [Unreleased]

### Added
- Full CRUD for user addresses via `Route::apiResource('address', AddressController::class)`
- 5 endpoints under `/api/v1/address`: index, store, show, update, destroy
- All endpoints authenticated via `auth:sanctum` + `email.verified`

### Architecture
- Controller → Repository → Model pattern
- Single AddressRequest for create/update (same validation rules)
- AddressResource with 8 fields: id, title, type, default, address, location, customer_id, created_at
- User isolation via `customer_id` scoping
- `customer_id` auto-set on create, immutable on update
- Hard deletes (no SoftDeletes)

### Known Limitations
- No pagination on list endpoint (returns all addresses)
- PUT request requires all fields (validation has `required` not `sometimes`)
- Store success message uses `COULD_NOT_CREATE_THE_RESOURCE` (misleading)
- No test coverage
