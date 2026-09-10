# Password — Frontend Integration

## Overview
Three-step password reset flow implemented as a wizard/paginated form:

```
Step 1: Email → POST /forget-password
Step 2: OTP  → POST /verify-forget-password-token
Step 3: New Password → POST /reset-password
```

## Step 1: Request OTP

```
POST /api/v1/forget-password
Body: { email }
```

**Frontend:**
- Single email input field
- Submit button with loading state
- **Always returns 200** — does NOT disclose whether the email exists (prevents email enumeration)
- Show generic message: "If this email is registered, check your inbox"
- Do NOT show "No account found" — this leaks which emails are registered
- On 429 → "Too many attempts. Try again in 1 minute."
- Pre-fill email in Step 2
- Reset email is **queued** — may take a few seconds to arrive; do not promise instant delivery

## Step 2: Verify OTP

```
POST /api/v1/verify-forget-password-token
Body: { email, otp }
```

**Frontend:**
- 6-character OTP input (single input or 6 individual digit boxes)
- Auto-submit when 6 characters entered (mobile-friendly)
- ✓ **Response is JSON** `{ success, message }` — check `response.success`
  - `success: true` → advance to Step 3
  - `success: false` → "Invalid or expired OTP"
- Show remaining time (60-minute countdown from Step 1)
- "Resend OTP" link → returns to Step 1
- On 429 → rate limit notice

**Implementation note:** The response is a standard JSON envelope:
```js
const res = await api.post('/verify-forget-password-token', body);
if (res.data.success) { /* advance */ }
```

## Step 3: Reset Password

```
POST /api/v1/reset-password
Body: { email, otp, password, password_confirmation }
```

**Frontend:**
- Password + confirm password fields
- Password strength indicator (min 8 chars)
- On 200 → "Password reset successful!" → redirect to login
- On 400 → "Invalid or expired OTP. Please start over." → return to Step 1
- On 422 → field-level validation errors
- On 429 → rate limit notice

## Error Handling Summary

| Status | Meaning | Action |
|--------|---------|--------|
| 200 | Success | Advance to next step or redirect |
| 400 | Invalid OTP | Show error, allow retry or resend |
| 404 | User not found (on reset) | Show error (forget-password is always 200) |
| 422 | Validation error | Show field errors |
| 429 | Rate limited | Show timer, disable submit |
| 500 | Server error | Show generic error, retry |

## Queue Behavior
All password reset emails are **queued** on the `high` queue:
- `POST /forget-password` returns immediately — email arrives after queue worker processes it
- Do NOT promise instant delivery; show a brief loading state before enabling resend
- For production, ensure `php artisan queue:work --queue=high,default` is running as a daemon

## Rate Limiting
All 3 endpoints share `throttle:sensitive` — 5 requests/min per IP.
- After 5 requests, all endpoints return 429 for 1 minute
- Consider showing a cooldown timer to the user
- The limiter is per-IP, so multiple users on same network share the limit
