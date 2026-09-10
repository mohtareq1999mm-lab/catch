# Brand Module — Changelog

## [1.0.0] — 2026-07-19

### Added
- BrandsReorderRequest Form Request — extracted inline validation from controller
- Comprehensive API investigation documentation (`api-desc/brand/`)
- Brands API: full CRUD, reorder, and public read-only endpoints

### Changed
- `BrandController::reorder()` now type-hints `BrandsReorderRequest $request` instead of using `$request->validate()`
- `BrandController::brandUpdate()` visibility changed from `public` to `private`

### Fixed
- Validation logic moved from controller to dedicated Form Request (separation of concerns)
- Internal helper method properly encapsulated

## Known Issues

1. **No transaction wrapping on `reorder()`** — `BrandRepository::reorder()` calls `setNewOrder()` without `DB::transaction()`. Partial failure could leave brands in an inconsistent order state.
2. **Fragile route ordering** — `PUT brands/reorder` must be defined before `apiResource('brands', ...)`. If route order changes, reorder will break.
3. **Uniqueness check depends on route parameter name** — `BrandUpdateRequest` uses `$this->route('brand')` for ignore logic. Changing the route parameter name would silently break the ignore logic.
4. **No force-delete endpoint** — Brands are soft-deleted only. There is no admin endpoint to force-delete a brand.
5. **No restore endpoint** — Soft-deleted brands must be restored via database or code. No admin API endpoint exists.
