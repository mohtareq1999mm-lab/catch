# Changelog - Slider Feature

## [Unreleased]

### Added
- 7 admin slider endpoints (CRUD + change-status + reorder)
- 2 public endpoints (active list, enriched detail by slug)
- Spatie Translatable for title (EN/AR)
- Spatie MediaLibrary for desktop + mobile images
- Spatie EloquentSortable for configurable ordering
- Product association via `slider_product` pivot
- SoftDeletes
- 4 Spatie permissions
- ~47 tests in `SliderApiTest.php`
- Slider import/export sheets

### Known Issues
- Missing `sliders` table migration (HIGH)
- Duplicate route registrations (MEDIUM)
- Inconsistent media collection names (LOW)
