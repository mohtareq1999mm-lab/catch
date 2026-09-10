# Bug Fix Report: GET /faqs Returns 500 (Collection::paginate Does Not Exist)

## Bug ID: FAQ-B008
## Severity: HIGH
## Status: FIXED (2026-07-21)

---

## Root Cause

`FaqsController::fetchFAQs()` called `$this->repository->query()->paginate(...)` which returned a `LengthAwarePaginator` (already paginated). Then `index()` tried to call `->orderBy()` and `->paginate()` on the paginator, causing:

```
Method Illuminate\Database\Eloquent\Collection::paginate does not exist
```

**Two issues compounded:**
1. `fetchFAQs()` paginated early, returning a paginator instead of a query builder
2. `index()` tried to paginate again on the already-paginated result

## Fix

1. Removed `fetchFAQs()` entirely — inlined logic into `index()`
2. `index()` now uses `$this->repository->orderBy()` then `$this->repository->paginate()` directly
3. This also properly applies the `RequestCriteria` (pushed in repository `boot()`), fixing search filtering via `?search=` parameter

## Files Changed

| File | Change |
|------|--------|
| `packages/marvel/src/Http/Controllers/FaqsController.php` | Removed `fetchFAQs()`, rewrote `index()` to use `$this->repository->orderBy()->paginate()` |

## Test Results

- 9/9 regression tests pass (including `b7_faq_search_by_title`)
- 7/7 CRUD tests pass
- Before: GET /faqs returned 500
- After: GET /faqs returns 200 with paginated + filterable results
