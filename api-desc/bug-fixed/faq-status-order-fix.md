# Bug Fix Report: FAQ Status and Order Fields Returning Null

## Bug ID: FAQ-B006/B007
## Severity: MEDIUM
## Status: FIXED (2026-07-21)

---

## Bug 1: `status` and `order` Always Null in Response

### Root Cause
Both API Resources omitted `status` and `order` from their `toArray()` return.

**Files affected:**

| Resource | File |
|----------|------|
| Admin CMS | `Marvel\Http\Resources\FaqResource` |
| Public API | `App\Http\Resources\Faqs\FaqResource` |

The columns exist in the migration (`status` as boolean default true, `order` as integer default 0) and the model has them in `$fillable`, but neither resource passed them to the JSON response.

### Fix
Added to both resources:

```php
'status' => (int) $this->status,
'order'  => (int) $this->order,
```

---

## Bug 2: `status` Required in Create Validation, Null Forced on DB

### Root Cause
`CreateFaqsRequest` had `'status' => ['required', "in:1,0"]` — forcing clients to always send status. When status was absent, `FaqsRepository::storeFaqs()` ran `$faqs['status'] = $request['status']`, which evaluated to `null`, overriding the DB default of `true`.

### Fix
1. `CreateFaqsRequest`: `required` → `sometimes`
2. `FaqsRepository::storeFaqs()`: wrapped in `if ($request->has('status'))`

---

## Bug 3: Tests Asserted Buggy Behavior

### Root Cause
`FaqResourceTest` had `assertArrayNotHasKey('status', $data)` and `assertArrayNotHasKey('order', $data)` — these tested the bug as correct behavior.

### Fix
Changed both to `assertArrayHasKey`. Added `status` and `order` to field presence tests.

---

## Files Modified

| File | Change |
|------|--------|
| `app/Http/Resources/Faqs/FaqResource.php` | Added status, order |
| `packages/marvel/src/Http/Resources/FaqResource.php` | Added status, order |
| `packages/marvel/src/Http/Requests/CreateFaqsRequest.php` | status: required → sometimes |
| `packages/marvel/src/Database/Repositories/FaqsRepository.php` | Conditional status set |
| `tests/Feature/Faqs/FaqResourceTest.php` | Fixed buggy assertions |

## What Was NOT Changed

| Component | Reason |
|-----------|--------|
| Database migration | Columns already exist |
| Model `$fillable` | Fields already in array |
| Validation format | Already correct (`in:0,1`) |
| Routes | No new routes needed |
| Translations | No new keys needed |

## Test Results

All 56 FAQ tests pass (125 assertions, 0 failures).
