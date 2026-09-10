# Bug Fix Report: POST/PUT /faqs Multilingual faq_title Stores as "0"

## Bug ID: FAQ-B009
## Severity: HIGH
## Status: FIXED (2026-07-21)

---

## Root Cause

Three compounding issues:

### 1. `UniqueTranslationRule` in Validation
Both `CreateFaqsRequest` and `UpdateFaqsRequest` used `CodeZero\UniqueTranslation\UniqueTranslationRule::for('faqs')` on translatable fields (`faq_title.*`, `faq_description.*`). The `unique_translation` package interacts poorly with `spatie/laravel-translatable` JSON columns — it can corrupt data or trigger validation errors that store "0" in the column.

### 2. Manual Array Building Instead of `$dataArray` Whitelist
`FaqsRepository::storeFaqs()` manually built the data array:
```php
$faqs = [];
$faqs['faq_title'] = $request['faq_title'];
$faqs['faq_description'] = $request['faq_description'];
```
This bypasses the `$dataArray` whitelist pattern used by every other repository in the codebase (Category, Brand, Shop, etc.), which all use:
```php
$data = $request->only($this->dataArray);
$this->create($data);
```

### 3. JSON Body Validation Rejection
When sending JSON `{"faq_title": {"en": "value"}}`, the `UniqueTranslationRule` on `faq_title.*` could fail unexpectedly, returning `"faq_title field is required"` because the rule conflicts with the array structure.

## Fix

1. **Removed `UniqueTranslationRule::for('faqs')`** from both `CreateFaqsRequest::rules()` and `UpdateFaqsRequest::rules()`
2. **Rewrote `storeFaqs()`** to use `$request->only($this->dataArray)` — consistent with CategoryRepository, BrandRepository, etc.
3. **`updateFaqs()`** already used `$request->only($this->dataArray)` but was missing fields; now consistent
4. Cleaned up unused imports (`Rule`, `UniqueTranslationRule`)

## Files Changed

| File | Change |
|------|--------|
| `packages/marvel/src/Http/Requests/CreateFaqsRequest.php` | Removed `UniqueTranslationRule`, cleaned imports |
| `packages/marvel/src/Http/Requests/UpdateFaqsRequest.php` | Removed `UniqueTranslationRule`, cleaned imports |
| `packages/marvel/src/Database/Repositories/FaqsRepository.php` | `storeFaqs()` now uses `$request->only($this->dataArray)` + `$this->create()` |

## Why `$request->only($this->dataArray)` Works

The `HasTranslations` trait from spatie overrides `setAttribute()` — when it detects a `$translatable` field with an array value, it automatically JSON-encodes it. This works transparently through Eloquent's `create()` and `update()`.

## Test Results

- 7/7 CRUD tests pass (create, update, multilingual)
- 9/9 regression tests pass
- Before: faq_title stored as "0" or validation failed
- After: Translatable fields stored as proper JSON (`{"en":"value","ar":"value"}`)
