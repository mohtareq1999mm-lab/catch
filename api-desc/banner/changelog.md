# Changelog - Banner Feature

## [Unreleased]

### Added
- 5 standard CRUD endpoints for banner management
- 2 custom endpoints: change-status (toggle) and reorder (drag-and-drop)
- Spatie Translatable for title and description (EN/AR)
- Spatie MediaLibrary for desktop + mobile images
- Spatie EloquentSortable for configurable ordering
- Product association via `banner_product` pivot
- SoftDeletes for safe deletion
- 4 Spatie permissions for granular access control
- Full translation support (EN + AR) for all 5 messages
- Auto-slug generation from English title

### Known Issues
- Duplicate `banners` apiResource route registration (lines 217 + 259)
- Duplicate pagination keys in list response
