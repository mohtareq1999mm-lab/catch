# Bug Fix: Governorate `PUT /change-status` Route Conflict

**Date:** 2026-07-21

**Affected Endpoint:** `PUT /api/v1/governorates/change-status`

## Bug

| # | Endpoint | Issue | Fix |
|---|---|---|---|
| BUG-1 | `PUT /api/v1/governorates/change-status` | HTTP 500 — `TypeError: GovernorateController::update(): Argument #2 ($id) must be of type int, string given` | Moved `change-status` route before `apiResource('governorates')` |

## Root Cause

Route ordering in `packages/marvel/src/Rest/Routes.php`:

**Before (broken):**
```php
Route::apiResource('governorates', GovernorateController::class);     // registers PUT /governorates/{governorate}
Route::put('governorates/change-status', [GovernorateController::class, 'bulkStatus']);
```

When a request arrives for `PUT /api/v1/governorates/change-status`, Laravel iterates registered routes in order. The `apiResource` route `PUT /governorates/{governorate}` is matched first, capturing `"change-status"` as the `{governorate}` parameter. The request is dispatched to `GovernorateController@update(int $id)` with `$id = "change-status"`, causing a PHP type error.

Laravel matches routes top-to-bottom and stops at the **first match**. Specific static routes must be registered before parameterized resource routes.

## Fix

**After (fixed):**
```php
Route::put('governorates/change-status', [GovernorateController::class, 'bulkStatus']);  // specific route first
Route::put('governorates/{id}/fast-shipping', [GovernorateController::class, 'toggleFastShipping']);
Route::get('governorates/{id}/cities', [GovernorateController::class, 'cities']);
Route::apiResource('governorates', GovernorateController::class);                         // generic resource last
```

## Files Changed

| File | Change |
|---|---|
| `packages/marvel/src/Rest/Routes.php` | Moved `change-status`, `fast-shipping`, `cities` routes before `apiResource` |
| `api-desc/shipping/bug-report.md` | Added Issue 4 with fix details |
| `api-desc/shipping/changelog.md` | Added Fixed section |
| `api-desc/bug-fixed/governorate-change-status-route.md` | This report |

## Audit of All Routes

All other `PUT/PATCH` custom routes in the file were verified to be correctly ordered (specific routes before resource routes):

| Route | Resource | Ordering | Status |
|-------|----------|----------|--------|
| `PUT /brands/reorder` | `apiResource('brands')` | Before resource | ✅ |
| `PATCH /sliders/change-status` | `apiResource('sliders')` | Before resource | ✅ |
| `PUT /sliders/reorder` | `apiResource('sliders')` | Before resource | ✅ |
| `PUT /categories/feature` | `apiResource('categories')` | Before resource | ✅ |
| `PUT /banner/change-status` | `apiResource('banners')` | Before resource (also different slug: `banner` vs `banners`) | ✅ |
| `PUT /flash-sale/reorder` | `apiResource('flash-sale')` | Before resource | ✅ |
| `PUT /faqs/reorder` | `apiResource('faqs')` | Before resource | ✅ |
| `PUT /governorates/change-status` | `apiResource('governorates')` | **Was AFTER → NOW BEFORE** | ✅ **FIXED** |
| `POST /countries/change-status` | `apiResource('countries')` | No conflict (different segment count) | ✅ |

## Post-Fix Verification

- `PUT /api/v1/governorates/change-status` with `{"ids": [1,2], "status": "0"}` now correctly routes to `GovernorateController@bulkStatus`
- `PUT /api/v1/governorates/{id}` still correctly routes to `GovernorateController@update`
- All contact tests (59) and notification tests (38) pass (97 total, 297 assertions)
