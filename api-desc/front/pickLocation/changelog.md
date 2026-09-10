# Pickup Location Module — Changelog (Public API)

## [1.0.0] — 2026-07-20

### Added
- Comprehensive API investigation documentation (`api-desc/front/pickLocation/`)
- Public listing endpoint: `GET /api/v1/general/pickup-locations`
- Public show endpoint: `GET /api/v1/general/pickup-locations/{id}`
- Active-only scope with display_order sorting
- Search by store_name
- Pagination support (default 10 per page)
- PickupLocationResource with location details, working hours, map coordinates
- Existing test coverage in `PickupLocationTest.php` and `PickupLocationPricingIntegrationTest.php`

### Known Issues
1. **No caching** — DB hit on every request (BUG-PICK-001)
2. **Unstructured `working_hours`** — No enforced JSON schema (BUG-PICK-002)
3. **Generic exception handling in `show()`** — All errors become 404 (BUG-PICK-003)
4. **Limited filtering** — No city/governorate or advanced filters (BUG-PICK-004)
