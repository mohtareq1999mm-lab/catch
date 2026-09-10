# OTP — Frontend Integration

## Overview
OTP authentication is **disabled** by default. If enabled, it provides phone-based login as an alternative to email/password.

Current active OTP usage (separate from this module):
- **Registration** — `POST /register` sends OTP email via `sendOneTimePassword()` (built-in, always active)
- **Update Contact** — `POST /update-contact` uses OTP to verify new phone number (auth:sanctum, always active)

## Phone OTP Flow (If Enabled)

### Step 1: Send OTP

```
POST /api/v1/send-otp-code
Body: { phone_number: "+201234567890" }
```

**Frontend:**
- Phone number input with country code selector
- Validate phone format (min 11, max 15 digits)
- On success → save `otp_id` from response for Step 2
- On 404 → "No account found with this phone number"
- On 429 → rate limit notice (only 3 attempts/min!)
- OTP email is **queued** — do not show instant delivery; allow brief delay before resend option

### Step 2: Verify & Login

```
POST /api/v1/otp-login
Body: { phone_number, otp_id, code }
```

**Frontend:**
- 6-digit OTP input
- Auto-submit on complete
- On success → store token from `data.token`, redirect to home
- On 400 → "Invalid verification code"
- On 404 → "User not found"
- On 422 → gateway error

## Email OTP Flow (If Enabled)

Alternative: user can receive OTP via email instead of SMS.

### Step 1: Send OTP
```
POST /api/v1/send-otp-code
Body: { email: "user@example.com" }
```
Response includes `data.otp_id` — store for tracking verification session. OTP email is queued (brief delay).

### Step 2: Login
```
POST /api/v1/otp-login
Body: { email: "user@example.com", otp: "123456" }
```
Response includes `data.token` — same format as `/token`.

## Rate Limiting
`throttle:otp` — only **3 requests per minute per IP**. This is very restrictive. Frontend must:
- Show clear cooldown timer on 429
- Warn user before last attempt
- Disable resend button until cooldown expires

## Error Handling

| Status | Meaning | Action |
|--------|---------|--------|
| 200 | OTP sent / login success | Advance or redirect; `data.otp_id` available for tracking |
| 400 | OTP verification failed | Show error, allow retry |
| 404 | User not found | Show "No account found" |
| 422 | Gateway error | Show "Verification service unavailable" |
| 429 | Rate limited (3/min) | Show cooldown timer |
| 500 | Server error | Show generic error |

## Notes for Frontend
- The `sendUserOtp()` method returns translation key `USER_LOGGED_IN_SUCCESSFULLY` even for OTP send — the message string will say "User logged in successfully" when user hasn't logged in yet. Consider ignoring the message string and using your own.
- When phone OTP is used, the gateway returns an `otp_id` that must be passed to the verify step. The `code` is the actual OTP (default `123456` in dev environment via `sendOtpCode()` returning static OTP in comment).
- OTP emails are **queued** — the API returns 200 immediately, but the email arrives after the queue worker processes the job. Do not show a success message that implies instant delivery.
- The token in `otp-login` response is wrapped in `data.token` (consistent with `/token` and all other auth endpoints).
- `otp_id` is always returned on success for email OTP (was missing before the fix). Use it for tracking the OTP verification session.
