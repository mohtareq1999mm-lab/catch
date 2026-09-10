# Bug Fix: Contact sendReply Method Name & Route Consistency

**Date:** 2026-07-20

**Previous Fix Superseded:** `contact-reply-route-fixes.md` attempted to fix by changing the route to match the typo method name (`sendReply` → `sendReplay`). This was incorrect — the proper fix is to rename the method to the correct spelling.

---

## Bugs Fixed

| # | Endpoint | Issue | Root Cause | Fix |
|---|---|---|---|---|
| BUG-1 | `POST /api/v1/contacts/{id}/reply` | Returns 500: "Method ContactController::sendReply does not exist" | Route definition and controller method used typo spelling `sendReplay` instead of `sendReply`. Previous fix inverted the correction — changed the route to match the typo instead of fixing the method name. | Renamed method `sendReplay` → `sendReply` in `ContactController` (method declaration + permission middleware), updated route reference in `Routes.php` |
| BUG-2 | `POST /api/v1/contacts/{id}/replay` | Returns 404 | Jira spec typo — URL uses `replay` (with 'a') instead of `reply`. Database column `is_replay` uses correct spelling; model scope is `replay()`; the URL alone had the mismatch. | **No code change needed.** Frontend already updated to use `/reply`. This endpoint is correct to return 404. |
| BUG-3 | `POST /api/v1/contact-us` | Returns 404 | Route definition exists in code (`Routes.php:127`) pointing to `ContactController@store` inside `throttle:sensitive` group (5/min per IP). Route is NOT behind auth middleware — accessible to unauthenticated users. Test `b4_contact_us_route_works` passes (asserts 201). | **No code change needed** — route is defined and works in tests. Production 404 likely caused by: (1) route cache not rebuilt after deploy, or (2) stale deployment without the route. Run `php artisan route:clear` on production. |

## Files Changed

| File | Change |
|---|---|
| `packages/marvel/src/Http/Controllers/ContactController.php` | Method `sendReplay()` renamed to `sendReply()`; permission middleware updated from `'sendReplay'` to `'sendReply'` |
| `packages/marvel/src/Rest/Routes.php` | Route target changed from `sendReplay` to `sendReply` |

## Test Results

```
OK (59 tests, 120 assertions)
```

All 59 contact feature tests pass:
- ContactAuthenticationTest
- ContactAuthorizationTest
- ContactCrudTest
- ContactRegressionTest (includes b4_contact_us_route_works)
- ContactReplyTest
- ContactResourceTest
- ContactSoftDeleteTest
- ContactValidationTest

## Production Deployment Note

After deploying, run:
```bash
php artisan route:clear
php artisan config:clear
```

This ensures the route cache is rebuilt with the corrected method name.
