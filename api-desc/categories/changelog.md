# Category Module — Changelog

## [1.0.0] — 2026-07-19

### Added
- Comprehensive API investigation documentation (`api-desc/categories/`)
- Categories API: full CRUD, featured toggle, public read-only, featured categories
- CategoryHierarchyService with cycle detection, level auto-calculation, descendant level updates
- 13 test files covering CRUD, validation, auth, soft delete, translations, relationships, resources, pivot, featured, media, and regression

### Known
- `categoryUpdate()` declared `public` (should be `private`)
- `addOrRemoveCategoryFromFeature()` uses inline validation (no dedicated Form Request)
- `PUT categories/feature` route ordering is fragile (must be before apiResource)
- `details` field validated as plain string but model declares it translatable (JSON)
- Duplicate `GET /categories` route registration (public + authenticated)
- FK RESTRICT prevents deleting categories with children

## Known Issues

1. **Fragile route ordering** — `PUT categories/feature` must be defined before `apiResource('categories', ...)`. If route order changes, feature toggle will break.
2. **Inline validation in feature toggle** — `addOrRemoveCategoryFromFeature()` uses `$request->validate()` instead of a Form Request.
3. **Public `categoryUpdate()` method** — Internal helper is `public`, should be `private`.
4. **`details` field type mismatch** — Validated as string but model expects translatable JSON.
5. **Duplicate route registration** — `GET /categories` registered twice (public then authenticated).
