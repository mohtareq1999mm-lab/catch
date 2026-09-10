# Tag Module — Changelog

## [1.1.0] — 2026-08-05

### Added
- `products` field in `TagCreateRequest` and `TagUpdateRequest` — accepts product IDs (`products.*` => `integer|exists:products,id`)
- Product relation sync on create and update — `$tag->products()->sync($products)` (mirrors Brand pattern)
- `products` relation exposed in `TagResource` when loaded (id, name, slug, status, image.thumbnail)
- `TagCrudTest` — 13 tests / 52 assertions covering the standard response envelope, product sync, and authorization

### Changed
- `TagController::store()` — response now wrapped in `apiResponse(TAG_CREATED_SUCCESSFULLY, 201, true, ...)` (was bare `TagResource`)
- `TagController::show()` — eager-loads `products`, response wrapped in `apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ...)` (was bare `TagResource`)
- `TagController::tagUpdate()` — response wrapped in `apiResponse(TAG_UPDATED_SUCCESSFULLY, 200, true, ...)` (was raw model)
- `TagController::destroy()` — response now `apiResponse(TAG_DELETED_SUCCESSFULLY, 200, true, true)` (was raw boolean)

## [1.0.0] — 2026-07-29

### Added
- Comprehensive API investigation documentation (`api-desc/tag/`)
- Tags API: full CRUD with permissions, translatable names, image/icon uploads

### Fixed
- `TagUpdateRequest`: UniqueTranslationRule was checking `categories` table instead of `tags` table
- `TagCreateRequest`: Removed nonsensical `->ignore()` from CREATE unique rule
- `TagController::store()`: Added missing `return new TagResource($tag)` — method returned `null`
- `TagController::show()`: Added missing `$language` variable definition — was undefined
- `TagController`: Fixed wrong exception constants in catch blocks (all used `COULD_NOT_CREATE_THE_RESOURCE`)
- `resources/lang/en/message.php`: Added 7 missing English translation keys
- `packages/marvel/config/constants.php`: Added 4 tag-specific constants

### Known
- No soft deletes — tags are hard-deleted
- No observer — no activity logging for tag CRUD
- No public endpoints — no `/general/tags` API exists
- No test files exist for the Tag module
- `type` relationship is eager-loaded but never exposed in TagResource
- Media files are not cleaned up when a tag is deleted
- `destroy()` returns raw boolean instead of JSON response
