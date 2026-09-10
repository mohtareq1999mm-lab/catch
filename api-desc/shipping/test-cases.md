# Test Cases - Shipping Feature

## Current Coverage

**No dedicated tests exist** for CountryController, GovernorateController, or CityController. Fast Shipping tests exist in `tests/Feature/FastShippingControllerTest.php` but cover the fast-shipping service layer, not governorate CRUD.

**Bug Fix:** `PUT /api/v1/governorates/change-status` route ordering was fixed. Custom routes must precede `apiResource` to prevent Laravel from matching `"change-status"` as `{id}`.

## Recommended Tests

### Country CRUD

| # | Test | Priority |
|---|------|----------|
| FT-001 | Index returns paginated list with search/status | High |
| FT-002 | Store creates country with valid data | High |
| FT-003 | Store validates name.en/name.ar (required, unique) | High |
| FT-004 | Show returns country with governorates | High |
| FT-005 | Show returns 404 for non-existent | High |
| FT-006 | Update modifies country fields | High |
| FT-007 | Destroy deletes and cascades | High |
| FT-008 | Governorates endpoint returns nested data | High |
| FT-009 | BulkStatus updates multiple records | Medium |
| FT-010 | BulkStatus validates ids array | Medium |

### Governorate CRUD

| # | Test | Priority |
|---|------|----------|
| FT-011 | Index filters by country_id + status | High |
| FT-012 | Store creates governorate with shipping price | High |
| FT-013 | Store validates country_id exists | High |
| FT-014 | Show returns with country/cities/shippingPrice | High |
| FT-015 | Update modifies shipping price (upsert) | High |
| FT-016 | Destroy fails if governorate has cities | High |
| FT-017 | ToggleFastShipping updates boolean | High |
 | FT-018 | BulkStatus updates status | Medium |
| FT-018b | BulkStatus route not caught by `{id}` parameter | High |

### City CRUD

| # | Test | Priority |
|---|------|----------|
| FT-019 | Index filters by governorate_id | High |
| FT-020 | Store validates governorate_id exists | High |
| FT-021 | Store validates name uniqueness | High |
| FT-022 | Show returns with governorate | High |
| FT-023 | Update works with valid data | Medium |
| FT-024 | Destroy removes city | Medium |

### Permission & Auth

| # | Test | Priority |
|---|------|----------|
| FT-025 | All endpoints return 401 without token | High |
| FT-026 | Index/show return 403 without VIEW permission | High |
| FT-027 | Store returns 403 without CREATE permission | High |
| FT-028 | Destroy returns 403 without DELETE permission | High |

### Translation Issue

| # | Test | Priority |
|---|------|----------|
| FT-029 | Country store response message falls back correctly | Medium |
| FT-030 | City store response message falls back correctly | Medium |
