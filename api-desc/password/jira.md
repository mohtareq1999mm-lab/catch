# Password — Jira Stories

## Epic: PWD-EPIC-1 — Password Reset System

### Story PWD-1: Forget Password (Request OTP)
**As a** registered user who forgot their password
**I want** to request a password reset OTP via email
**So that** I can prove ownership of my account

**Acceptance Criteria:**
- [ ] POST /forget-password accepts `{ email }`
- [ ] Validates email exists in users table
- [ ] Generates 6-character alphanumeric OTP
- [ ] Stores `Hash::make(OTP)` in `password_resets` table
- [ ] Sends email with plaintext OTP via `UserRepository::sendResetEmail()`
- [ ] Returns 200 `"Check your inbox for password reset email"` on success
- [ ] Returns 404 if email not found
- [ ] Upserts `password_resets` record (no duplicates)
- [ ] Rate limited: 5 requests/min per IP

### Story PWD-2: Verify OTP
**As a** user who received an OTP
**I want** to verify the OTP is valid and not expired
**So that** I can proceed to set a new password

**Acceptance Criteria:**
- [ ] POST /verify-forget-password-token accepts `{ email, otp }`
- [ ] Verifies OTP against bcrypt hash in `password_resets`
- [ ] Checks 5-minute expiry window
- [ ] Returns `true` if valid, `false` otherwise
- [ ] Returns raw boolean (known limitation — will be wrapped in JSON in future)

### Story PWD-3: Reset Password
**As a** user with a verified OTP
**I want** to set a new password
**So that** I can regain access to my account

**Acceptance Criteria:**
- [ ] POST /reset-password accepts `{ email, password, password_confirmation, otp }`
- [ ] Validates: password min 8, max 50, confirmed; email required; otp required
- [ ] Verifies OTP via `verifyForgetPasswordToken()`
- [ ] Runs in database transaction
- [ ] Updates user password with bcrypt hash
- [ ] Deletes ALL existing Sanctum tokens
- [ ] Cleans up `password_resets` record
- [ ] Returns 200 on success
- [ ] Returns 400 on invalid/expired OTP
- [ ] Returns 422 on validation failure
- [ ] Rate limited: 5 requests/min per IP

### Story PWD-4: Rate Limiting
**As a** security engineer
**I want** rate limiting on password reset endpoints
**So that** brute-force OTP guessing and email bombing are prevented

**Acceptance Criteria:**
- [ ] `throttle:sensitive` applied to all 3 endpoints
- [ ] 5 requests per minute per IP
- [ ] Shared counter across all 3 endpoints
- [ ] 429 response with Retry-After header
- [ ] After cooldown, requests resume working

### Story PWD-5: Token Invalidation
**As a** security engineer
**I want** all existing tokens revoked when a password is reset
**So that** compromised sessions are invalidated

**Acceptance Criteria:**
- [ ] `$user->tokens()->delete()` called during reset
- [ ] Old tokens return 401 on subsequent requests
- [ ] User must log in again with new password

### Story PWD-6: Known Issue — Verify OTP Response Format
**As a** developer
**I want** the verify endpoint to return JSON
**So that** frontend error handling is consistent

**Acceptance Criteria:**
- [ ] Change return type from raw boolean to `{ success: true, data: bool }`
- [ ] Set proper Content-Type: application/json header
- [ ] Update frontend to handle new format
