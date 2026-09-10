# Bug Report — Pages Module

---

## BUG-PAGE-008 (SECTION-B002): Multilingual Title Not Stored via FormData

**Severity:** HIGH

**Status:** FIXED (2026-07-23)

**Component:** `packages/marvel/src/Http/Controllers/SectionController.php` (lines 38-40, 56-58)

**Root Cause:** Laravel's Validator Factory enables `excludeUnvalidatedArrayKeys` by default. When a FormRequest has rules like `'title' => 'required|array'` + `'title.*' => 'required|string|max:50'`, the `validated()` method SKIPS the parent `title` key because it has `array` rule AND wildcard sub-rules exist. Only `title.*` is processed via `Arr::set($results, 'title.*', [...])`, which on a non-existent parent key produces an empty array `[]`.

**Fix:** Added guard in both `store()` and `update()`:
```php
if (! isset($data['title']) && $request->has('title')) {
    $data['title'] = $request->input('title');
}
```

This matches the ContentPage pattern which uses `$request->only(['title'])` instead of `validated()`.

**Impact:** Any section create/update via FormData (including `_method=PUT` spoofing) silently stored `title` as empty JSON array `[]` instead of the multilingual payload. JSON API requests were unaffected because of different request body parsing paths.

**Related:** Same latent bug exists in any FormRequest using `array` + `wildcard.*` rules with translatable fields. The ContentPage form requests also use `UniqueTranslationRule` (unrelated).

---

---

## BUG-PAGE-001: `attachSections` Returns "Deleted" Message When Detaching

**Severity:** Low

**Component:** `packages/marvel/src/Http/Controllers/ContentPageController.php` (line 73)

**Description:** When `attachSections` receives an empty `sections` array, it calls `$content_page->sections()->update(['content_page_id' => null])` which **detaches** sections (sets the FK to null), but returns `DELETE_DATA_SUCCESSFULLY` as the message. The sections are not deleted — they still exist in the database. The message is misleading.

---

## BUG-PAGE-002: No Caching on Public Endpoints

**Severity:** Medium

**Component:** `app/Http/Controllers/Api/General/ContentPageController.php`

**Description:** The `index()` and `show()` methods have `Cache::remember` calls commented out. Every public page request hits the database for both the page query and the sections query. For high-traffic home pages, this is unnecessary load. Cache should be implemented with invalidation on page/section mutations.

---

## BUG-PAGE-003: Dead Code in SectionController@store

**Severity:** Low

**Component:** `packages/marvel/src/Http/Controllers/SectionController.php` (lines 42-48)

**Description:** There is commented-out code that auto-creates SectionType and upserts settings when creating a section. This code is never executed. It should either be removed or uncommented if the feature is desired.

---

## BUG-PAGE-004: Sections Index Returns All Records Without Pagination

**Severity:** Low

**Component:** `packages/marvel/src/Http/Controllers/SectionController.php` (line 29)

**Description:** `Section::ordered()->get()` returns ALL sections without pagination. A site with hundreds or thousands of sections could experience performance issues. Should use `paginate()` with configurable limit.

---

## BUG-PAGE-005: Redundant `byType` Method in SectionTypeController

**Severity:** Low

**Component:** `packages/marvel/src/Http/Controllers/SectionTypeController.php` (lines 92-100)

**Description:** `byType($type)` and `settings($type)` return identical data. The `byType` method is not referenced in any route definition. It's dead code.

---

## BUG-PAGE-006: Section Settings Cascade No Cache

**Severity:** Low

**Component:** `app/Http/Resources/Pages/SectionResource.php`

**Description:** `getSettings()` queries the `section_types` and `section_type_settings` tables on every serialization. While it caches the result per object instance, a page with many sections will still make 2*N queries for a single page load. This could be optimized with eager loading or a separate cached endpoint.

---

## BUG-PAGE-007: No Soft Deletes

**Severity:** Low

**Component:** All three models (ContentPage, Section, SectionType)

**Description:** Deleting a content page or section is a hard delete. There is no soft delete / trash functionality. Accidental deletions are unrecoverable.
