# Bug: Missing Governorates API Blocks Checkout Delivery

**Date:** 2026-07-23

---

**Component:** Checkout / Governorates

**Priority:** High

**Severity:** Blocker

---

## Description

The checkout endpoint `POST /api/v1/general/checkout` requires `governorate_id` as an integer (validated as `exists:governorates,id`) when `fulfillment_type` is `delivery`. However, there was no public endpoint to fetch available governorates with their IDs.

Frontend attempted paths (`/general/governorates`, `/general/cities`, `/general/regions`, `/general/locations`) all returned 404.

## Impact

Users cannot complete delivery orders because there is no way to render a governorate dropdown selector.

## Fix

Added `GET /api/v1/general/governorates` — returns all active governorates with `id` and `name`.

## Files Added/Changed

| File | Change |
|------|--------|
| `app/Http/Controllers/Api/General/GovernorateController.php` | New — public governorate listing |
| `routes/api.php` | Added route + import |
| `api-desc/front/governorate/api.md` | API reference |
| `api-desc/front/governorate/frontend.md` | Frontend integration guide |

## Test

```bash
curl http://example.com/api/v1/general/governorates
```

Expected:
```json
{ "status": 200, "success": true, "data": [{ id, country_id, name, status, ... }] }
```
