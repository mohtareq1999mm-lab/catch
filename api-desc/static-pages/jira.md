# Static Pages Module — Backend Jira Tasks

---

## Task 1: Add `is_active` Toggle for Static Sections — DONE

**Priority:** Medium
**Component:** StaticSection model + StaticPageController (admin) + public controller
**Status:** Completed (`2026_09_14_000001_add_type_config_is_active_to_static_sections_table.php`)
**Files:**
- `database/migrations/2026_09_14_000001_add_type_config_is_active_to_static_sections_table.php`
- `packages/marvel/src/Database/Models/StaticSection.php` (casts `is_active` bool, `scopeActive`, fillable `is_active`)
- `packages/marvel/src/Http/Requests/StoreStaticSectionRequest.php`, `UpdateStaticSectionRequest.php` (`is_active` in:0,1)
- `app/Services/General/StaticPageService.php` (`normalizeCreateData` defaults true, `parseBoolean` on update)
- `app/Http/Controllers/Api/General/StaticPageController.php` (public `where is_active true`)
- `app/Http/Resources/StaticPage/StaticSectionResource.php` (`is_active` bool)
- `config/` + `database.md` updated

**Description:** Static sections now have visibility flag — every section created defaults `is_active=true`, toggle via `is_active` bool, public filters `where is_active true` only.

**Acceptance Criteria:**
- [x] `is_active` column added to `static_sections` (boolean default true)
- [x] Create/update requests accept `is_active` (`nullable|in:0,1`)
- [x] Public index/show include active sections only (`with(['staticSections'=>where is_active true])`)
- [x] Admin index/show include all sections (`with('staticSections.media')` no filter)
- [x] Cache flush on toggle (observer + controller flush `static_pages` tag)
- [x] Tests: public hides inactive, admin sees it, toggle persists (`StaticPageMediaTest` + `CacheTest`)

---

## Task 2: Cache Key Must Account for Rendered Locale (future-proofing)

**Priority:** Low
**Component:** Public StaticPageController
**Effort:** Small
**Files:**
- `app/Http/Controllers/Api/General/StaticPageController.php`

**Description:** The cache key is `md5(request()->fullUrl())`, which does not include the `lang` header. This is safe **today** because the cached value is the raw models+media and locale is resolved at render time. If the cache is ever switched to store rendered resources (or content becomes locale-dependent at the query level), the key must include the locale.

**Acceptance Criteria:**
- [ ] Document the current invariant in the controller comment
- [ ] If/when rendering moves into the cache, include `app()->getLocale()` in the key
- [ ] No behavior change — currently PASS (models cached)

---

## Task 3: Paginate Admin Static Pages Index

**Priority:** Low
**Component:** StaticPageController (admin)
**Effort:** Small
**Files:**
- `packages/marvel/src/Http/Controllers/StaticPageController.php`
- `app/Services/General/StaticPageService.php`

**Description:** `GET /api/v1/static-pages` returns all pages without pagination. With the page set fixed to 3 this is fine today, but the admin index should support `page`/`per_page` query params to stay consistent with other admin lists.

**Acceptance Criteria:**
- [ ] `?page` and `?per_page` query params supported
- [ ] Backward compatible with fixed set (no frontend change required)
- [ ] Existing tests unaffected — currently PASS without pagination

---

## Task 4: Fix Stale `FrontendResourceTest` Assertion

**Priority:** Low
**Component:** Tests — FrontendResource enum
**Effort:** Trivial
**Files:**
- `tests/Unit/FrontendResourceTest.php`

**Description:** `it_has_exactly_fifteen_resources` asserts the enum has exactly 15 cases, but it now has 25 (previous modules + the new `STATIC_PAGES = 'static_pages'` case). This test was already failing before Static Pages. Update it to assert the enum contains the expected cases (inclusion) instead of an exact count.

**Acceptance Criteria:**
- [ ] Test asserts `STATIC_PAGES` is present with value `'static_pages'`
- [ ] Exact-count assertion removed or made additive
- [ ] Test green — pending (not blocking static pages)

---

## Task 5: Validate `content` Shape Consistency Across Locales

**Priority:** Low
**Component:** UpdateStaticSectionRequest
**Effort:** Small
**Files:**
- `packages/marvel/src/Http/Requests/UpdateStaticSectionRequest.php`

**Description:** `content` is free-form by design, but partial locale updates currently let each locale diverge in shape (e.g., `en` has `{ heading, body }`, `ar` has `{ title }`). Decision: free-form is intentional per `api.md` — each locale is independent, no cross-locale key enforcement.

**Acceptance Criteria:**
- [x] Decision documented in `api-desc/static-pages/api.md` (free-form is intentional per type, each locale independent)
- [ ] If enforced later, add after-hook validation comparing locale key sets
- [ ] Tests for mixed-shape payloads — currently not enforced, documented as free-form

---

## Task 6: Typed Sections & Media Lifecycle — DONE

**Priority:** High
**Component:** StaticSection typed model + Media Library
**Status:** Completed (`2026_09_14` migration + `StaticSectionType` enum + `HasMedia` collections)
**Files:**
- `packages/marvel/src/Enums/StaticSectionType.php` (text,image,video,screenshot)
- `database/migrations/2026_09_14_000001_add_type_config_is_active_to_static_sections_table.php` (type default text, config nullable, is_active)
- `packages/marvel/src/Database/Models/StaticSection.php` (HasMedia, collections `static-section-image|static-section-video` on `static-pages` disk, singleFile, thumb)
- `config/filesystems.php` (disk static-pages)
- `packages/marvel/src/Http/Requests/StoreStaticSectionRequest.php` / `UpdateStaticSectionRequest.php` (per-type media validation, remove_media, type enum)
- `app/Services/General/StaticPageService.php` (create/update with `DB::transaction` + `validateMediaMimeForType` + clear both collections matrix + remove_media + type change semantics)
- `app/Http/Resources/StaticPage/StaticSectionResource.php` (type,config,is_active,media object)
- `app/Http/Controllers/Api/General/StaticPageController.php` (public active filter + media eager) + `packages/marvel/src/Http/Controllers/StaticPageController.php` (pass media file, loadMissing media)
- `api-desc/static-pages/api.md|backend.md|database.md|README.md|flow.md|frontend.md` updated

**Acceptance Criteria:**
- [x] `type` column default `text` backfills legacy NULL → text, screenshot reuses image collection
- [x] Media collections `static-section-image` (image+screenshot) + `static-section-video` on `static-pages` disk
- [x] Per-type validation: image 5MB jpeg,png,webp,gif; video 20MB mp4,webm,ogg,mov,avi; text prohibited; `remove_media` clears both; omission keeps
- [x] All 8 replacement transitions (image↔video↔screenshot etc.) clear opposite collection
- [x] Type change image→text clears media; image→video without file →422
- [x] Tests: `StaticPageMediaTest` 9 cases + existing 105 green, `php artisan migrate:fresh --env=testing` OK
