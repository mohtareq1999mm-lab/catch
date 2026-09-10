# Bug Fix: Contact Reply & Contact Us Routes

**Date:** 2026-07-20

**Affected Endpoints:** `POST /api/v1/contacts/{id}/reply`, `POST /api/v1/contacts/{id}/replay`, `POST /api/v1/contact-us`

**Bugs Fixed:**

| # | Endpoint | Issue | Fix |
|---|---|---|---|
| BUG-1 | `POST /api/v1/contacts/{id}/reply` | Route called `sendReply` method which does not exist (controller has `sendReplay`) | Changed route method from `sendReply` to `sendReplay` |
| BUG-2 | `POST /api/v1/contacts/{id}/replay` | Route doesn't exist (typo: `replay` with 'a') | Frontend already updated to use `/reply`. Tests were also hitting `/replay` — fixed all test URLs |
| BUG-3 | `POST /api/v1/contact-us` | Route doesn't exist (404) | Added new route pointing to `ContactController@store` with `throttle:sensitive` rate limiting (5/min per IP) |

**Root Cause:**

BUG-1 was a method name mismatch: the route definition referenced `sendReply` but the controller method is named `sendReplay` (typo in the route definition, not the controller).

BUG-2 existed because the frontend/test code used the misspelled URL `/replay` (with 'a') instead of the correct `/reply`.

BUG-3 was simply a missing route definition; the public contact form needed a dedicated endpoint.

**Additional Fix:** Added 5 missing English translation keys for contact messages in `lang/en/message.php`.

**Files Changed:**

| File | Change |
|---|---|
| `packages/marvel/src/Rest/Routes.php` | `sendReply` → `sendReplay`; added `contact-us` route |
| `resources/lang/en/message.php` | Added 5 contact translation keys |
| `tests/Feature/Contacts/ContactAuthenticationTest.php` | `/replay` → `/reply` |
| `tests/Feature/Contacts/ContactAuthorizationTest.php` | `/replay` → `/reply` (2 occurrences) |
| `tests/Feature/Contacts/ContactReplyTest.php` | `/replay` → `/reply` (5 occurrences) |

**Route Changes:**

Before:
```php
Route::middleware(["throttle:sensitive"])->group(function () {
    Route::post('contacts/{id}/reply', [ContactController::class, 'sendReply']);
    Route::delete('contacts/delete-all', [ContactController::class, 'deleteAll']);
    Route::delete('contacts/delete-all-read', [ContactController::class, 'deleteAllReadContacts']);
    Route::apiResource('contacts', ContactController::class)->except(['update']);
});
```

After:
```php
Route::middleware(["throttle:sensitive"])->group(function () {
    Route::post('contacts/{id}/reply', [ContactController::class, 'sendReplay']);
    Route::post('contact-us', [ContactController::class, 'store']);
    Route::delete('contacts/delete-all', [ContactController::class, 'deleteAll']);
    Route::delete('contacts/delete-all-read', [ContactController::class, 'deleteAllReadContacts']);
    Route::apiResource('contacts', ContactController::class)->except(['update']);
});
```

**Test Results:** All 59 contact tests pass (120 assertions).
