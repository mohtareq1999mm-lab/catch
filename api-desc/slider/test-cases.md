# Test Cases - Slider Feature

## Current Coverage

**File:** `tests/Feature/SliderApiTest.php` (~47 tests)

### Admin CRUD

| Category | Tests |
|----------|-------|
| List (pagination, active filter, ordering) | Covered |
| Create (valid, validation, image upload) | Covered |
| Show (existing, 404) | Covered |
| Update (valid, validation, image replace) | Covered |
| Delete (soft delete, 404) | Covered |

### Custom Endpoints

| Endpoint | Tests |
|----------|-------|
| PATCH change-status | Covered |
| PUT reorder | Covered |

### Public API

| Endpoint | Tests |
|----------|-------|
| GET /general/sliders (active only) | Covered |
| GET /general/sliders/{slug} (with enriched products) | Covered |

### Auth & Permissions

| Scenario | Coverage |
|----------|----------|
| Unauthenticated (401) | Covered |
| No permission (403) | Covered |
| View-only vs manage | Covered |

## Recommended Additional Tests

| # | Test | Priority |
|---|------|----------|
| FT-001 | Media collection fallback (slider-image-desktop → sliders-desktop) | Medium |
| FT-002 | Slug auto-generation on create/update | Medium |
| FT-003 | Import/export sliders | Low |
