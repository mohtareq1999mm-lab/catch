# Test Cases — Static Pages Module

Suite: `tests/Feature/StaticPages/` (9 classes, 105 core tests, all green) + typed-media matrix (9 additional) via `StaticPageMediaTest` (when enabled).
Setup: `RefreshDatabase`, sqlite `:memory:`, `CACHE_DRIVER=array`, spatie permissions created per test, `Sanctum::actingAs` for auth, `Storage::fake('static-pages')` for media.

## StaticPageJsonRoundTripTest (20 cases)

Covers DB + API round-trip integrity for translatable JSON + typed fields + media.

| # | Case |
|---|------|
| 1 | Page title round-trips en+ar through DB |
| 2 | Section title round-trips en+ar |
| 3 | Section content map round-trips en+ar |
| 4 | Content with nested arrays (blocks) round-trips |
| 5 | Content with scalar values (int/bool/float) round-trips |
| 6 | Single-locale content round-trips |
| 7 | Null content → empty map (spatie null translation filtered) |
| 8 | Section created via API → DB row matches (type defaults text) |
| 9 | Section created via API → response matches DB |
| 10 | Page updated via API → DB row matches |
| 11 | Page partial locale update preserves other locale |
| 12 | Section partial content update preserves other locale |
| 13 | Section partial title update preserves other locale |
| 14 | Section order stored/returned as integer |
| 15 | Page `is_active` round-trips as boolean (incl. via API) |
| 16 | Public index returns localized EN title |
| 17 | Public index returns localized AR title (`lang: ar`) |
| 18 | Public API returns full content map |
| 19 | Admin show returns sections in order (with media) |
| 20 | Page update cannot change slug |
| 21 | Section `type=config/is_active` round-trips (text/image/video/screenshot, config JSON, is_active bool) |
| 22 | Public response includes `type,config,is_active,media` fields |

## StaticPageCrudTest (10 cases)

| # | Case |
|---|------|
| 1 | Admin lists pages (active+inactive, with media) |
| 2 | Admin shows single page with sections (ordered, with media) |
| 3 | Admin updates page title |
| 4 | Admin deactivates page |
| 5 | Admin creates text section (legacy without type → text) |
| 6 | Admin updates section (title partial) |
| 7 | Admin deletes section (media purged) |
| 8 | Admin reorders sections (transactional) |
| 9 | Reorder persists new order in DB |
| 10 | Full lifecycle (create text → update → delete) |

## StaticPageAuthorizationTest (15 cases)

| # | Case |
|---|------|
| 1–7 | Guest gets 401 on all 7 admin endpoints (including reorder) |
| 8 | Authenticated user without any permission → 403 |
| 9 | `view-static-pages` → 200 index/show, 403 on all mutations |
| 10 | `update-static-pages` → 200 update only |
| 11 | `create-static-sections` → 200 create only |
| 12 | `update-static-sections` → 200 update + reorder only |
| 13 | `delete-static-sections` → 200 delete only |
| 14 | Full permission set → all admin operations 200 |
| 15 | Public endpoints accessible without auth (filtered is_active) |

## StaticPageValidationTest (15 cases)

| # | Case |
|---|------|
| 1 | Create: title required |
| 2 | Create: title.en required |
| 3 | Create: content required when type=text |
| 4 | Create: top-level list content → 422 + message `STATIC_SECTION_CONTENT_INVALID` |
| 5 | Create: content.en must be array |
| 6 | Create: title locale must be string |
| 7 | Create: title.en > 255 → 422 |
| 8 | Update page: title must be array |
| 9 | Update page: is_active must be 0/1 |
| 10 | Update section: list content → 422, no mutation |
| 11 | Update section: title must be string |
| 12 | Reorder: sections required |
| 13 | Reorder: nonexistent id → 422 |
| 14 | Reorder: duplicate ids → 422 |
| 15 | Reorder: non-integer id → 422 |
| 16 | Failed validation never persists any row |
| 17 | Type: invalid type → 422 in:text,image,video,screenshot |
| 18 | Media: text with media → prohibited 422 |
| 19 | Media: image without media on create → 422 |
| 20 | Media: video without media on create → 422 |
| 21 | Media: invalid MIME pdf for image → 422 |
| 22 | Media: oversized image >5MB → 422, video >20MB → 422 |
| 23 | Media: remove_media prohibited on create → 422 |

## StaticPageFixedInvariantTest (11 cases)

| # | Case |
|---|------|
| 1 | POST /static-pages → 405 |
| 2 | DELETE /static-pages/{slug} → 405 |
| 3 | slug in update payload is ignored |
| 4 | Seeder is idempotent (run 3x → still 3 pages) |
| 5 | Seeder creates exactly the 3 fixed slugs |
| 6 | Seeder never overwrites an edited title |
| 7 | Seeder never overwrites a deactivated page |
| 8 | Seeder never creates sections |
| 9 | Seeder never deletes admin-created sections |
| 10 | Public unknown slug → 404 |
| 11 | Public index returns only active pages (inactive hidden) |

## StaticPageCacheTest (10 cases)

| # | Case |
|---|------|
| 1 | Public index warms `static_pages` tag |
| 2 | Public show warms `static_pages` tag |
| 3 | Repeated index calls stay cached (0 queries) |
| 4 | Cache stores models+media, not rendered resources |
| 5 | Page update invalidates cache |
| 6 | Section create (text+media) invalidates cache |
| 7 | Section update (incl. media replace/remove) invalidates cache |
| 8 | Section delete invalidates cache |
| 9 | Section reorder invalidates cache |
| 10 | Mutated data is never served from stale cache (active/inactive toggle) |

## StaticPageNPlusOneTest (4 cases)

| # | Case |
|---|------|
| 1 | Public index: 1 page query + 1 eager section+media query (no N+1) |
| 2 | Public show: single eager section+media query |
| 3 | Admin index: single eager section+media query |
| 4 | Repeated public request → 0 DB queries (lazy cache) |

## StaticPageDeleteBehaviorTest (8 cases)

| # | Case |
|---|------|
| 1 | Delete section removes the row + media |
| 2 | Delete on one page leaves other pages' sections intact |
| 3 | Remaining sections keep their order after delete |
| 4 | New section after delete gets `max + 1` |
| 5 | Deleting a page cascades its sections (DB level) |
| 6 | Deleting a foreign section via page route → 404 |
| 7 | Updating a foreign section via page route → 404 |
| 8 | Reorder containing a foreign id → 404 |

## StaticPageSeederTest (10 cases)

| # | Case |
|---|------|
| 1 | Creates exactly 3 pages |
| 2 | Expected slugs |
| 3 | English titles |
| 4 | Arabic titles |
| 5 | All pages active |
| 6 | Zero sections |
| 7 | Idempotent |
| 8 | Preserves edited titles |
| 9 | Preserves deactivated pages |
| 10 | Seeded pages publicly accessible (index + each slug) |

## StaticPageMediaTest (9 cases, additional)

| # | Case |
|---|------|
| 1 | Create text section (no media) |
| 2 | Create image via multipart → static-section-image |
| 3 | Create video via multipart → static-section-video |
| 4 | Create screenshot reuses static-section-image |
| 5 | Replace image media (old cleared, new URL) |
| 6 | Remove media via remove_media=true |
| 7 | Public is_active filtering (admin sees inactive, public hides) |
| 8 | Invalid MIME rejected (pdf for image → 422) |
| 9 | Oversized image >5MB rejected (422) |
| 10 | Replacement matrix: image→video, video→image, screenshot→video etc. (all 8 transitions clear opposite collection) |
| 11 | Type change image→text clears media |
| 12 | Legacy create without type → text |
