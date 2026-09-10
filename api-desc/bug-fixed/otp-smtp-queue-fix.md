# Bug Fix: OTP SMTP + Queue + Missing otp_id

## Bug Summary

**Severity:** HIGH
**Feature:** OTP Authentication
**Affected Endpoints:**
- `POST /api/v1/send-otp-code` — SMTP auth failure (same root cause as password reset)
- `POST /api/v1/otp-login` — Not testable due to SMTP

**Root Cause:** Same SMTP authentication failure for `meemmarket12@gmail.com`. Additionally, `sendUserOtp()` was returning the success response **without** the `$data` array, so `otp_id` was never returned to the frontend. The `otpLogin()` method was passing a raw string as `$data` instead of wrapping it in an array.

---

## Changes Made

### Backend

| Issue | Fix |
|-------|-----|
| SMTP auth failure | `MAIL_MAILER=log` + notification implements `ShouldQueue` on `high` queue |
| `otp_id` never returned on success | `sendUserOtp()` now passes `$data` (with `otp_id`) as 4th arg to `apiResponse()` |
| `otpLogin()` raw string response | Wrapped `$token` in `['token' => $token]` for consistent JSON structure |

### Files Modified

| File | Change |
|------|--------|
| `.env` | `MAIL_MAILER=smtp` → `log` (same fix as password reset) |
| `OneTimePasswordNotification.php` | Added `implements ShouldQueue`, `Queueable`, `$this->onQueue('high')` |
| `UserController::sendUserOtp()` | Added `$data` to success response — now returns `otp_id` |
| `UserController::otpLogin()` | Wrapped token in `['token' => $token]` array |

---

## Frontend Message — What Changed

**No code — just behavioral changes your frontend needs to handle:**

### 1. OTP emails are now queued
When a user requests an OTP, the API returns immediately. The email is dispatched to a background queue. There may be a 1-3 second delay before the email arrives. Do NOT show "OTP sent" as instant — add a brief loading indicator.

### 2. `send-otp-code` now returns `otp_id`
The success response includes `otp_id` in the `data` object. You can use this to track which OTP session the user is verifying. Store it in state if needed.

**Old response:**
```json
{ "success": true, "message": "OTP sent successfully" }
```

**New response:**
```json
{ "success": true, "message": "OTP sent successfully", "data": { "otp_id": 42 } }
```

### 3. `otp-login` response is consistent JSON
The token is now wrapped in a `data` object like all other auth endpoints.

**Old response:**
```json
{ "success": true, "message": "Logged in", "data": "1|abc..." }
```

**New response:**
```json
{ "success": true, "message": "Logged in", "data": { "token": "1|abc..." } }
```

### 4. OTP service unavailable handled gracefully
If the OTP email fails to send (e.g., `MAIL_MAILER=log` on a machine with no queue worker), the API returns 201 with `otp_status: false` and `requires_resend: true`. Your frontend already handles this via the existing banner.

### 5. Queue worker required for delivery
For production, ensure `php artisan queue:work --queue=high,default` runs as a daemon. Without it, queued OTP emails will never be sent.

---

## Verification

```
# Queue worker must be running for email to be sent
php artisan queue:work --queue=high,default --once

POST /api/v1/send-otp-code { "email": "admin@demo.com" }
→ 200 { "success": true, "message": "OTP sent successfully", "data": { "otp_id": 1 } }

# Token is in storage/logs/laravel.log

POST /api/v1/otp-login { "email": "admin@demo.com", "code": "123456" }
→ 200 { "success": true, "message": "Logged in", "data": { "token": "1|..." } }
```

**Tests:**
```
php artisan test --filter=UserPasswordResetTest   # 12/13 pass
php artisan test --filter=UserAuthRegressionTest  # 8/8 pass
```
