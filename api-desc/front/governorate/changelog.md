# Governorate Module — Changelog (Public API)

## [1.0.0] — 2026-07-23

### Added
- Public listing endpoint: `GET /api/v1/general/governorates`
- GovernorateController with `index()` method
- Active-only scope via GovernorateRepository::allActive()
- Route registered in `routes/api.php`

### Bug Fix
- Missing governorates endpoint blocked delivery checkout (governorate_id was required but unfetchable)
