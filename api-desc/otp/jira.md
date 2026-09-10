# OTP — Jira Stories

## Epic: OTP-EPIC-1 — Phone OTP Authentication

### Story OTP-1: Send OTP Code
**As a** user
**I want** to receive a one-time password on my phone or email
**So that** I can log in without a password

**Acceptance Criteria:**
- [ ] POST /send-otp-code accepts `{ email }` XOR `{ phone_number }`
- [ ] Validates email format or phone format (11-15 digits)
- [ ] Looks up active user by email or phone
- [ ] For email: sends OTP via Laravel Notification
- [ ] For phone: sends OTP via configured SMS gateway
- [ ] Returns `otp_id` for phone verification (required for Step 2)
- [ ] Returns 404 if user not found
- [ ] Returns 422 on validation failure
- [ ] Rate limited: 3 requests/min per IP
- [ ] Routes disabled by default

### Story OTP-2: OTP Login
**As a** user who received an OTP
**I want** to log in by providing the OTP code
**So that** I can access my account without a password

**Acceptance Criteria:**
- [ ] POST /otp-login accepts `{ phone_number, otp_id, code }` or `{ email, otp }`
- [ ] For phone: verifies via OTP gateway `checkVerification()`
- [ ] For email: verifies via `validateOneTimePassword()`
- [ ] Creates Sanctum token on success
- [ ] Returns token in response
- [ ] Returns 400 on invalid/expired OTP
- [ ] Returns 404 on user not found
- [ ] Returns 422 on gateway error

### Story OTP-3: OTP Gateway System
**As a** developer
**I want** a pluggable OTP gateway system
**So that** I can switch between Twilio, local testing, or other providers

**Acceptance Criteria:**
- [ ] Gateway selected via `config('auth.active_otp_gateway')`
- [ ] Gateway class resolved dynamically: `Marvel\Otp\Gateways\StudlyCaseGateway`
- [ ] `OtpGateway` facade wraps concrete gateway implementations
- [ ] `startVerification()` returns verification ID
- [ ] `checkVerification()` returns isValid() boolean
- [ ] Automatic fallback to `LocalGateway` on configuration failure
- [ ] Warning logged when fallback occurs

### Story OTP-4: Rate Limiting
**As a** security engineer
**I want** strict rate limiting on OTP endpoints
**So that** SMS bombing and OTP brute-force attacks are prevented

**Acceptance Criteria:**
- [ ] `throttle:otp` — 3 requests per minute per IP
- [ ] Shared counter across send-otp-code and otp-login
- [ ] 429 response with Retry-After header
- [ ] After cooldown, requests resume working

### Story OTP-5: Bug Fix — Wrong Translation Key
**As a** developer
**I want** sendUserOtp to use a correct translation key
**So that** the API response message accurately describes the action

**Acceptance Criteria:**
- [ ] Replace `USER_LOGGED_IN_SUCCESSFULLY` with new key `OTP_SENT_SUCCESSFULLY`
- [ ] Add translation entry in all supported language files
- [ ] Verify frontend is not relying on the old message string

### Story OTP-6: Bug Fix — Remove Static OTP from Response
**As a** security engineer
**I want** the OTP not to be returned in the API response
**So that** OTP security is maintained

**Acceptance Criteria:**
- [ ] Remove hardcoded `'otp' => '123456'` from sendUserOtp email path
- [ ] Remove commented-out `// 'otp' => '123456'` from sendOtpCode
- [ ] For email OTP, relied on notification delivery only
- [ ] For phone OTP, only `otp_id` is returned (not the code)
