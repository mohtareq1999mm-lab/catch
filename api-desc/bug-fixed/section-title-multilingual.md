# Bug Fix Report: POST/PUT /sections Multilingual title Stores as "0" or Null

## Bug ID: SECTION-B001
## Severity: HIGH
## Status: FIXED (2026-07-21)

---

## Root Cause

### `UniqueTranslationRule` in Validation

`StoreSectionRequest` used `CodeZero\UniqueTranslation\UniqueTranslationRule::for('sections', 'title')` on the translatable field (`title.*`). The `unique_translation` package interacts poorly with `spatie/laravel-translatable` JSON columns — when validation processes `title.*`, the `UniqueTranslationRule` can corrupt the data flow, causing `title` to be stored as `"0"` or `null` in the database.

This is the **same root cause** as the FAQ multilingual bug (FAQ-B009).

### Spatie HasTranslations Setter Flow

When `spatie/laravel-translatable` detects an array value for a `$translatable` attribute (like `title`), `setAttribute()` calls `setTranslations()` which JSON-encodes each locale value. For this to work, the raw array must arrive intact at the model level. The `UniqueTranslationRule` interferes before this point.

## Fix

1. **Removed `UniqueTranslationRule::for('sections', 'title')`** from `StoreSectionRequest::rules()` — changed `title.*` rule from `['required', 'string', 'max:50', UniqueTranslationRule::for('sections', 'title')]` to `['required', 'string', 'max:50']`
2. **Cleaned unused import** `CodeZero\UniqueTranslation\UniqueTranslationRule` from both `StoreSectionRequest` and `UpdateSectionRequest`

## Files Changed

| File | Change |
|------|--------|
| `packages/marvel/src/Http/Requests/StoreSectionRequest.php` | Removed `UniqueTranslationRule` from `title.*` rule + cleaned import |
| `packages/marvel/src/Http/Requests/UpdateSectionRequest.php` | Removed unused `UniqueTranslationRule` import |

## Why This Fix Works

The controller uses `$request->validated()` → `Section::create($data)` / `$section->update($data)`. The `Section` model has `$translatable = ['title']` and `$fillable` includes `title`. When the validated array is passed to `create()`, the `HasTranslations` trait's `setAttribute()` correctly detects the array and JSON-encodes it as `{"en":"value","ar":"value"}`.

## Test Results

- Before: `title` stored as `"0"` or `null` in DB when sent via multilingual payload
- After: `title` stored as proper JSON (`{"en":"English Title","ar":"عنوان عربي"}`)
- All 74 section/content-page CRUD tests pass (excluding 7 pre-existing unrelated failures)

## Pre-existing Test Bugs Found (Unrelated)

During investigation, 7 pre-existing test failures were identified:

| Test | Failure | Root Cause |
|------|---------|------------|
| 5 reorder tests | 405 Method Not Allowed instead of expected codes | Route definition issue (pre-existing) |
| 2 translatable tests | `app()->setLocale('ar')` doesn't propagate to model locale resolution | `HasTranslations::getLocale()` uses `config('app.locale')` not `app()->getLocale()` — tests should use `config(['app.locale' => 'ar'])` |
