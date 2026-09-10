# Auth — Frontend Integration

## API Client
All auth endpoints are unauthenticated (except `/api/v1/me` and `/api/v1/logout`) and called from:
- Login page (email/password **or** phone_number/password via `POST /api/v1/token`, plus Google, Facebook)
- Registration page (phone required, email optional — `email = null` is valid phone-only customer)
- Password reset flow (email → OTP → new password)

## Endpoints

### Registration — REST: `phone_number` required, `email` optional (`nullable|sometimes|email|unique:users,email|email:rfc,dns`)
```
POST /api/v1/register
Body: { first_name*, last_name*, phone_number*, password*, password_confirmation*, policy* } + { email?: nullable|sometimes|email|unique:users,email|email:rfc,dns }
Examples:
  phone-only (valid): { first_name, last_name, phone_number, password, password_confirmation, policy }
  with email:         { first_name, last_name, phone_number, email, password, password_confirmation, policy }
Validation (UserCreateRequest): email nullable|sometimes|email|unique:users,email|email:rfc,dns; phone_number required|string|max:20|min:10|unique:users,phone_number; Admin/GraphQL remain email-required (REST only)
```

**Frontend handling:**
- Validate: `phone_number` is required (string 10-20, unique); `email` is optional — only validate format/RFC/DNS/unique when present (`nullable|sometimes`)
- Treat `email = null` as a valid customer state (phone-only account) — not unverified and not an error; do not show "missing email" warning for phone-only users
- Show loading state during submission (throttle:auth — 10/min)
- On 200 → if `email` was provided → redirect to email verification prompt or dashboard; if phone-only (`email` omitted/null) → redirect to dashboard (no email verification to await; backend register guard `if (user.email)` skips `sendOneTimePassword()` and returns `{"status":200,"message":"User registered successfully","success":true,"data":{"otp_status":true}}` with no email side effect)
- On 201 (OTP failed) → only when `email` was provided and mail failed → show "Check your email — OTP may not have been sent, you can resend"
- On 422 → display field-level validation errors (note: `email` only validated when present; `phone_number` always required)
- On 429 → show "Too many attempts. Please try again later."
- The `policy` field must be checked via UI checkbox
- OTP email is **queued** (has slight delay) — don't show failure immediately; phone-only registrations have no OTP email by design

### Login — supports `phone_number + password` (UserAuthEmailAndPasswordRequest)
```
POST /api/v1/token
Body: { email, password } OR { phone_number, password }  // POST /api/v1/token supports phone_number + password (REST)
Validation: email required_without:phone_number|email; phone_number required_without:email|string|max:15|min:8
Examples:
  { "email": "user@example.com", "password": "secret123" }
  { "phone_number": "+2010xxxxxxx", "password": "secret123" }  // phone-only login
```

**Frontend handling:**
- Save `token` from response to localStorage/session
- Check `email_verified` flag — if false and `email` is present, prompt verification; if `email` is `null` (phone-only account) this is a valid state, not an error — do not show "unverified email" warning
- Support both login forms: email+password and phone_number+password; backend lookup is `WHERE email=$email OR phone_number=$phone AND is_active=true`
- Redirect based on user role (admin → dashboard, customer → home)
- On 404 (INVALID_CREDENTIALS) → show "Invalid email/phone or password"
- On 429 → rate limit notice

### Admin Login — email required (Admin/GraphQL unchanged)
```
POST /api/v1/admin-login
Body: { email, password }  // email required — admin login remains email-required (not affected by REST optional email)
```

**Frontend handling:**
- Same as login, but requires email verified
- Store `permissions` and `role` from response for UI routing
- 404 with USER_NOT_VERIFIED → show "Please verify your email before logging in"
- 404 with USER_NOT_FOUND → show "No admin account found with this email"

### Send OTP Code
```
POST /api/v1/send-otp-code
Body: { email } or { phone_number }
```

**Frontend handling:**
- Request OTP for email or phone verification
- Response includes `otp_id` in `data` — store it to track verification session
- OTP email is **queued** — may take 1-3 seconds to arrive
- On 201 (mail failed) → show existing "OTP service unavailable" banner with resend option
- Do NOT disable resend for longer than 5 seconds (email is queued, not sent)

### OTP Login
```
POST /api/v1/otp-login
Body: { email, code } or { phone_number, code }
```

**Frontend handling:**
- Verify the OTP code and receive authentication token
- On 200 → `data.token` contains the Sanctum token (same format as `/token`)
- On 400 → "Invalid or expired code"
- Show remaining attempts if available

### Social Login
```
POST /api/v1/social-login-token
Body: { provider, access_token }
```

**Frontend handling:**
- Use a social auth library (e.g., `@react-oauth/google`, `react-facebook-login`)
- Extract `access_token` from provider SDK
- Send to backend, receive Sanctum token
- If you're already logged in and do social login, you get a new account (no merge)
- On error (422 INVALID_CREDENTIALS) → "Login failed. Please try again."

### Get Current User
```
GET /api/v1/me
Headers: Authorization: Bearer [REDACTED:Authorization header] header] header]
Response (200): {"status":200,"message":"User profile retrieved successfully","success":true,"data":{"id":1,"name":"...","email":null,"email_verified_at":null,"is_active":true,"image":null,"type":"user","phone_number":"+2010...","created_at":"...","updated_at":"...","roles":[...],"permissions":[...],"address":[]}}  // email:null is valid phone-only state via UserResource
Response (phone-only): { id, name, email: null, phone_number: "+2010...", roles, permissions, image, ... }  // email:null is valid phone-only state
```

**Frontend handling:**
- Call on app mount to check authentication status
- Store `role`, `name`, `email`, `phone_number`, `profile.avatar` in global state; `email` may be `null` for phone-only customers — treat as valid, not unverified/error (do not force email prompt)
- On 401 → clear token, redirect to login
- Used for profile dropdown, user menu, permission checks

### Logout
```
POST /api/v1/logout
Headers: Authorization: Bearer <token>
```

**Frontend handling:**
- Call on logout button click
- Clear token from storage
- Clear user state
- Redirect to login

### Forget Password
```
POST /api/v1/forget-password
Body: { email }
```

**Frontend handling:**
- Always returns 200 — does NOT disclose whether the email exists
- Show generic success message: "If this email is registered, check your inbox"
- Do NOT show "no account found" — this prevents email enumeration
- On 429 → rate limit notice
- Password reset email is **queued** — may take a few seconds to arrive
- Wait at least 2 seconds before offering "resend" option

### Verify OTP
```
POST /api/v1/verify-forget-password-token
Body: { email, otp }
```

**Frontend handling:**
- 6-character OTP input field
- Response is JSON envelope: `{"status":200,"message":"Token is valid","success":true}` or `{"status":400,"message":"Invalid token","success":false}`
- On 200 (`success: true`) → advance to password reset form
- On 400 (`success: false`) → "Invalid or expired OTP"
- OTP expires after `config('auth.passwords.users.expire', 60)` minutes (configurable via backend)

### Reset Password
```
POST /api/v1/reset-password
Body: { email, otp, password, password_confirmation }
```

**Frontend handling:**
- On 200 → "Password reset successful!" → redirect to login
- On 400 (INVALID_TOKEN) → "Invalid or expired OTP, please request a new one"
- On 422 → field validation errors (password too short, mismatch, missing email)

## Error Handling

### 429 Rate Limit
```json
{
  "message": "Too Many Attempts."
}
```
> Note: throttle responses are from Laravel's `ThrottleRequests` middleware, not via `ApiResponse`; HTTP status is 429 with `Retry-After` header.
Show rate limit notice with retry timer.

### 422 Validation
```json
{
  "phone_number": ["The phone number field is required."],
  "password": ["The password must be at least 8 characters."]
}
```
Map to form field errors. Note: for REST `POST /api/v1/register`, `email` is `nullable|sometimes|email|unique:users,email|email:rfc,dns` — it is only validated when present; `phone_number` is always `required`. Login (`POST /api/v1/token`) uses `email required_without:phone_number|email` / `phone_number required_without:email`.

### 401 Unauthenticated
```json
{
  "status": 401,
  "message": "Not authorized",
  "success": false
}
```
Clear session, redirect to login.

## Token Storage
- Use `localStorage` or `secure cookie` for the Sanctum token
- Include `Authorization: Bearer <token>` in all authenticated API calls
- On 401 response, auto-logout
