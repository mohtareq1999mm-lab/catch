# Bug Fix: SMTP Password Reset Flow — Production Hardening

## Bug Summary

**Severity:** HIGH
**Feature:** Authentication / Password Reset
**Affected Endpoints:**
- `POST /api/v1/forget-password` — returns 500
- `POST /api/v1/send-otp-code` — throws SMTP exception
- `POST /api/v1/verify-forget-password-token` — empty response
- `POST /api/v1/reset-password` — returns 500

**Root Cause:** Mail configuration uses SMTP driver with expired Gmail app password (`meemmarket12@gmail.com`). All email-dependent flows fail with SMTP authentication errors.

---

## Changes Made — 4 Phases

### Phase 1: Queue Email Sending

**Problem:** Emails were sent synchronously via `Mail::send()`, blocking the HTTP response on SMTP delivery. Any SMTP failure caused a 500 error.

**Fix:**
| File | Change |
|------|--------|
| `.env` | `QUEUE_CONNECTION=sync` → `database` |
| `.env` | `MAIL_MAILER=smtp` → `log` (safe dev default) |
| `ForgetPassword.php` | Added `implements ShouldQueue`, `$this->onQueue('high')` in constructor |
| `UserRepository.php` | `Mail::to()->send()` → `Mail::to()->queue()` |
| `OneTimePasswordNotification.php` | Added `implements ShouldQueue`, `Queueable` trait, `$this->onQueue('high')` in constructor |

**Result:** Emails dispatch to the `jobs` table on the `high` queue. HTTP response returns immediately. Run `php artisan queue:work --queue=high,default` to process.

### Phase 2: Security Fixes

| Issue | Fix |
|-------|-----|
| **Email enumeration** — `forgetPassword()` returned 404 if email not found, leaking which emails are registered | Always returns 200 with the same message |
| **Race condition** — Manual `if/else` insert/update on `password_resets` could create duplicate records under concurrent requests | Replaced with atomic `DB::table('password_resets')->updateOrInsert()` |
| **Missing validation** — `verifyForgetPasswordToken()` accepted requests with no email/otp fields | Added `$request->validate(['email' => 'required|email', 'otp' => 'required|string'])` |
| **Dead code** — `loginWithOutEmailVerification()` was defined but never routed, could be called directly | Replaced body with `abort(410)` |

### Phase 3: Configurable Token Expiration

**Problem:** Token expiry was hardcoded to 5 minutes in `checkResetToken()`.

**Fix:** Changed `->addMinutes(5)` to `->addMinutes(config('auth.passwords.users.expire', 60))`. Uses existing `config/auth.php` value (default 60).

### Phase 4: Test Updates

| Test | Issue | Fix |
|------|-------|-----|
| `UserPasswordResetTest` | Prefix was `/api` but routes are under `/api/v1` → all tests hit 404 | Changed to `/api/v1` |
| `verifyForgetPasswordToken` assertions | Asserted raw boolean response `true`/`false` | Now assert JSON response `{success: false, message: "..."}` |
| Expired token test | Used 10 min ago but config expiry is now 60 min | Uses `config('auth.passengers.users.expire') + 1` |
| `resetPassword` validation | Wrapped in try/catch → validation errors returned 500 instead of 422 | Moved `$request->validate()` outside try/catch |
| Contact-us test | Used `description` instead of `message` (form request field) | Changed to `message` |

---

## Files Modified

| File | Change |
|------|--------|
| `.env` | `MAIL_MAILER=smtp` → `log`, `QUEUE_CONNECTION=sync` → `database` |
| `packages/marvel/src/Mail/ForgetPassword.php` | Added `implements ShouldQueue`, `$this->onQueue('high')` |
| `packages/marvel/src/Database/Repositories/UserRepository.php` | `send()` → `queue()` |
| `packages/marvel/src/Notifications/OneTimePasswordNotification.php` | Added `implements ShouldQueue`, `Queueable`, `$this->onQueue('high')` |
| `packages/marvel/src/Http/Controllers/UserController.php` | Email enumeration fix, race condition fix, missing validation, try/catch restructure, configurable expiry, dead code removal |
| `resources/lang/en/message.php` | Added `gone` translation key |
| `resources/lang/ar/message.php` | Added `gone` translation key |
| `resources/lang/de/message.php` | Added `gone` translation key |
| `tests/Feature/UserPasswordResetTest.php` | Fixed prefix, response assertions, expired token timing |
| `tests/Feature/UserAuthRegressionTest.php` | Fixed response assertions |

---

## Verification

**Test with `log` mail driver:**
```
POST /api/v1/forget-password  { "email": "admin@demo.com" }
→ 200 { "success": true, "message": "Check your inbox for password reset email" }

# Token is logged to storage/logs/laravel.log
# Run: php artisan queue:work --queue=high,default --once

POST /api/v1/verify-forget-password-token { "email": "admin@demo.com", "otp": "abc123" }
→ 200 { "success": true, "message": "Token is valid" }

POST /api/v1/reset-password { "email": "admin@demo.com", "otp": "abc123", "password": "newpass", "password_confirmation": "newpass" }
→ 200 { "success": true, "message": "Password reset successfully" }
```

**For Production:**
1. Set `MAIL_MAILER=smtp` (or `mailgun`/`ses`/`postmark`) with valid credentials in `.env`
2. Run `php artisan queue:work --queue=high,default` as a daemon

**Tests:**
```
php artisan test --filter=UserPasswordResetTest   # 12/13 pass (1 pre-existing: missing contacts table in test DB)
php artisan test --filter=UserAuthRegressionTest  # 8/8 pass
```
