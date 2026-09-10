# Backend — Static Pages Module

## Layered Structure

```
StaticPageController (admin, packages/marvel)          StaticPageController (public, app/Http)
        │  (ApiResponse + HasCache, permission middleware)        │  (ApiResponse + HasCache, lazy cache)
        ▼                                                          ▼
StaticPageService (app/Services/General/StaticPageService.php)
        │  (ownership, transactions, media lifecycle, reorder scoping)
        ▼
StaticPage / StaticSection (Marvel\Database\Models)  — StaticSection implements HasMedia
        │  (HasTranslations, SortableTrait scoped by page, InteractsWithMedia)
        ▼
StaticPageResource / StaticSectionResource (App\Http\Resources\StaticPage)
        │  (type, config, is_active, media URLs, localized title/content)
```

No business logic lives in controllers or resources; all ownership checks, reorder scoping, 404 mapping and media compensation live in `StaticPageService`.

## Key Files

| Concern | File |
|---------|------|
| Admin controller | `packages/marvel/src/Http/Controllers/StaticPageController.php` |
| Public controller | `app/Http/Controllers/Api/General/StaticPageController.php` |
| Service | `app/Services/General/StaticPageService.php` |
| Models | `packages/marvel/src/Database/Models/StaticPage.php`, `StaticSection.php` |
| Enums | `packages/marvel/src/Enums/StaticPageIdentifier.php`, `StaticSectionType.php` |
| Requests | `packages/marvel/src/Http/Requests/{UpdateStaticPage,StoreStaticSection,UpdateStaticSection,ReorderStaticSections}Request.php` |
| Resources | `app/Http/Resources/StaticPage/{StaticPageResource,StaticSectionResource}.php` |
| Observers | `app/Observers/StaticPageObserver.php`, `StaticSectionObserver.php` |
| Seeder | `database/seeders/StaticPageSeeder.php` |
| Migrations | `database/migrations/2026_08_18_0000{01,02}_*.php` + `2026_09_14_000001_add_type_config_is_active_to_static_sections_table.php` |
| Disk | `config/filesystems.php` → `static-pages` |
| Tests | `tests/Feature/StaticPages/*.php` (9 classes, 105 core + media matrix) |

## Routes

**Admin (registered in `packages/marvel/src/Rest/Routes.php`, `auth:sanctum` + `throttle:admin` group):**
```php
Route::get('static-pages', [StaticPageController::class, 'index']);
Route::get('static-pages/{static_page:slug}', [StaticPageController::class, 'show']);
Route::put('static-pages/{static_page:slug}', [StaticPageController::class, 'update']);
Route::post('static-pages/{static_page:slug}/sections', [StaticPageController::class, 'storeSection']);
Route::post('static-pages/{static_page:slug}/sections/reorder', [StaticPageController::class, 'reorderSections']); // before {static_section}
Route::put('static-pages/{static_page:slug}/sections/{static_section}', [StaticPageController::class, 'updateSection']);
Route::delete('static-pages/{static_page:slug}/sections/{static_section}', [StaticPageController::class, 'destroySection']);
```
Pages bound by slug (`{static_page:slug}`), sections by id. `reorder` declared before `sections/{static_section}` to prevent route capture.

**Public (registered in `routes/api.php`, `v1/general` group, `throttle:public-api`, no auth):**
```php
Route::get('static-pages', [StaticPageController::class, 'index']); // general
Route::get('static-pages/{slug}', [StaticPageController::class, 'show']);
```
Public scopes `where is_active=true` for pages and `where is_active=true` for sections with `media` eager load.

## Permissions

Five spatie permissions applied **only** via controller middleware `__construct()`:

`view-static-pages` → `index,show`
`update-static-pages` → `update`
`create-static-sections` → `storeSection`
`update-static-sections` → `updateSection,reorderSections`
`delete-static-sections` → `destroySection`

Defined in `Marvel\Enums\Permission::VIEW_STATIC_PAGES etc.`, seeded in `PermissionSeeder` (master + editor lists), and labelled in `resources/lang/{en,ar}/permissions.php`.

## Section Types & Media

- Enum `Marvel\Enums\StaticSectionType`: `text,image,video,screenshot`. `imageTypes()=[image,screenshot]`, `isMediaType()` = image|video.
- `StaticSection` implements `HasMedia` via `InteractsWithMedia`:
  - `static-section-image` (`useDisk('static-pages')->singleFile()`) for `image` + `screenshot`
  - `static-section-video` (`useDisk('static-pages')->singleFile()`) for `video`
  - Conversion `thumb` 368x232 `nonQueued()` on image collection.
  - Disk `static-pages` → `storage/app/public/static-pages` (`config/filesystems.php`).
- File field is `media` for all media types. Screenshot reuses image infrastructure (semantic type, same collection/mimes).

## Requests & Validation

- `StoreStaticSectionRequest`: `type` sometimes|in:text,image,video,screenshot (defaults to `text` for legacy), `title` required array with `title.en` required string max:255, `content` nullable array but required when `type=text`, `config` nullable array, `is_active` nullable in:0,1, `remove_media` prohibited, `media` required file for `image|screenshot` (`mimetypes:image/jpeg,png,jpg,webp,gif max:5120`) and `video` (`mimetypes:video/mp4,webm,ogg,quicktime,x-msvideo max:20480`), prohibited for `text`. `withValidator` rejects top-level list `content` (`STATIC_SECTION_CONTENT_INVALID`).
- `UpdateStaticSectionRequest`: `type` sometimes|in:..., `title/content/config/is_active` sometimes, `remove_media` sometimes in:0,1, `media` sometimes file with same per-type rules; when `type` absent loose `max:20480` then service strict mime `validateMediaMimeForType()` throws `ValidationException`. `content` list rejection same.
- `ReorderStaticSectionsRequest`: `sections` required array, `sections.*` required integer distinct exists:static_sections,id.
- `UpdateStaticPageRequest`: `title` sometimes array, `is_active` sometimes in:0,1.

## Service Lifecycle

- `getAll()` / `getBySlug()` eager `with('staticSections.media')` to avoid N+1 for media URLs.
- `createSection(page, data, UploadedFile|null)`: `normalizeCreateData` defaults `type=text`, `is_active=true`, `DB::transaction` create, then `validateMediaMimeForType` + `addMedia()->toMediaCollection()`; on media failure deletes row (compensation).
- `updateSection(page, section, data, UploadedFile|null)`: asserts ownership `assertSectionBelongsToPage` → 404, parses `remove_media`/`is_active` booleans, resolves `type` (payload ?? existing), `DB::transaction` update, then media branch: file → clear both collections + add to resolved collection; `remove_media` true → clear both; `image/text` type change to `text` → clear; enforces `media` required when changing to media type without file.
- `deleteSection()`: clears both collections then `delete()`.
- `reorderSections()`: counts `where static_page_id whereIn ids` vs `array_unique` → 404 on foreign, `DB::transaction` + `setNewOrder(ids,1,'id',scope where static_page_id)`.

## Translations & Messages

- `constants.php` defines six `STATIC_*` constants → `MESSAGE.*` keys, resolved by `ApiResponse::translateNotice`.
- EN + AR keys added to `resources/lang/{en,ar}/message.php`.

## Translation Mechanics (Spatie HasTranslations)

- Resources read `getTranslation('title', locale)` for localized title and `getTranslations('content')` for full locale map (null/empty filtered).
- Partial locale updates merge per-locale, so sending only `title.en` preserves `title.ar`.
- Top-level list for `content` rejected in request's `withValidator` after-hook before model.

## Caching

- Trait: `App\Traits\HasCache::remember(tag, key, closure)` — lazy, so cache hits avoid DB entirely.
- Tag: `FrontendResource::STATIC_PAGES` = `'static_pages'`; key: `md5(request()->fullUrl())`.
- Cache stores **models with media** (`with('staticSections.media')` in service, `with(['staticSections'=>where is_active with media])` in public), not rendered resources.
- Invalidated on every mutation via `flushTag` in controller (`storeSection,updateSection,destroySection,reorderSections,update`) + observers (`StaticPageObserver`, `StaticSectionObserver` on created/updated/deleted). Reorder relies on controller flush (query-builder `setNewOrder` fires no model events).

## Edge Cases Handled

- Cross-page section update/delete/reorder → 404 (no existence leak) via `assertSectionBelongsToPage` and scoped `setNewOrder`.
- Fixed-page invariants → 405 for POST/DELETE on pages; slug cannot be changed (`PUT` only forwards `title,is_active`).
- Section content free-form but must be locale-keyed object → 422 otherwise, `type=text` requires `content`.
- `type` legacy missing defaults to `text` (migration default `text` + backfill + request `sometimes` + service default).
- `screenshot` shares `static-section-image` → no duplicate infrastructure.
- `text` with `media` → `prohibited` 422; `image/video` without `media` on create → `required` 422; oversize/mime mismatch → 422.
- `remove_media` explicit vs omission = keep (critical distinction).
- Delete keeps remaining orders; new sections continue at `max + 1`.
- Public 404 for unknown or inactive page slug; inactive sections hidden via `where is_active=true`.
- Media replacement clears both collections then adds → no duplicate, no orphan in wrong collection.
