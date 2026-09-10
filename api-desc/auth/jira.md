# Auth — Jira Stories

## Epic: AUTH-EPIC-1 — Authentication System

### Story AUTH-1: User Registration — REST: `phone_number` required, `email` optional (`nullable|sometimes|email|unique:users,email|email:rfc,dns`)
**As a** new visitor
**I want** to register with my phone (and optionally email) plus name and password
**So that** I can create a customer account

**Acceptance Criteria:**
- [ ] Registration requires: first_name, last_name, phone_number (required), password, password_confirmation, policy; email is optional (`nullable|sometimes|email|unique:users,email|email:rfc,dns`) — `email = null` is a valid customer state, not unverified/error (Admin/GraphQL remain email-required — REST only)
- [ ] Phone validation: `phone_number` required|string|max:20|min:10|unique:users,phone_number
- [ ] When email is provided: must be unique and RFC-compliant with DNS check (`email:rfc,dns`); when omitted/null passes (`nullable|sometimes`)
- [ ] Password minimum 8 characters, must be confirmed
- [ ] Policy must be accepted (in: 1, true)
- [ ] On success: user created with type='user', role='customer', is_active=true, `email` may be `null` (phone-only)
- [ ] OTP email sent via `sendOneTimePassword()` only when `user.email` is present — backend guard `if ($user->email)`; phone-only (`email = null`) skips email side effect and returns `{"status":200,"message":"User registered successfully","success":true,"data":{"otp_status":true}}` (no 201, no requires_resend)
- [ ] Returns `{"status":200,"message":"User registered successfully","success":true,"data":{"otp_status":true}}` on success (including phone-only)
- [ ] Returns `{"status":201,"message":"Account created but OTP failed","success":true,"data":{"requires_resend":true,"email":"...","phone_number":"...","otp_status":false}}` if OTP mail fails (only possible when email was provided)
- [ ] Returns 422 on validation failure (email only validated when present; phone always required)
- [ ] Rate limited: 10 requests/min per IP
- [ ] Avatar upload supported via Spatie MediaLibrary
- [ ] Examples: phone-only `{ first_name, last_name, phone_number, password, password_confirmation, policy }` → 200; with email `{ first_name, last_name, phone_number, email, ... }` → 200 or 201

### Story AUTH-2: User Login — supports `phone_number + password` via `POST /api/v1/token` (REST)
**As a** registered user
**I want** to log in with my email or phone number and password
**So that** I can access my account

**Acceptance Criteria:**
- [ ] Login by email OR phone_number — `POST /api/v1/token` supports `phone_number + password` (REST): validation `email required_without:phone_number|email`, `phone_number required_without:email|string|max:15|min:8`; examples `{ email, password }` and `{ phone_number, password }` — response envelope `{"status":200,"message":"User logged in successfully","success":true,"data":{"token":"1|...","email_verified":bool,"permissions":[...],"role":[...],"expires_at":"..."}}`
- [ ] Backend lookup `WHERE email=$email OR phone_number=$phone AND is_active=true` + `Hash::check`
- [ ] Returns Sanctum token on success
- [ ] Returns email_verified flag in response — `email_verified = false` when `email = null` (phone-only) is valid state, not unverified/error
- [ ] Fires AdminLoggedIn event
- [ ] Invalid credentials return 404 with INVALID_CREDENTIALS
- [ ] Inactive users cannot log in
- [ ] Rate limited: 10 requests/min per IP

### Story AUTH-3: Admin Login — email required (Admin/GraphQL unchanged, not affected by REST optional email)
**As an** admin user
**I want** to log in with my verified email
**So that** I can access the admin panel

**Acceptance Criteria:**
- [ ] Requires email (admin login remains `WHERE email=$email AND is_active=true` — email required, unlike REST customer login)
- [ ] Requires type='admin'
- [ ] Requires email_verified_at to be set
- [ ] Returns token + permissions + role in response
- [ ] Non-admin users get 404 USER_NOT_FOUND
- [ ] Unverified admins get 404 USER_NOT_VERIFIED

### Story AUTH-4: Social Login
**As a** visitor
**I want** to log in with Google or Facebook
**So that** I don't need to create a separate password

**Acceptance Criteria:**
- [ ] Supports Google and Facebook providers
- [ ] Verifies access_token via Socialite
- [ ] Creates user if email not found (firstOrCreate)
- [ ] Stores provider info in user_providers table
- [ ] Auto-verifies email for OAuth users
- [ ] Invalid provider returns 422
- [ ] Invalid token returns 422

### Story AUTH-5: Current User Profile — `email` may be `null` (phone-only REST customer)
**As a** logged-in user
**I want** to view my profile
**So that** I can see my account details

**Acceptance Criteria:**
- [ ] Requires auth:sanctum
- [ ] Returns user data with wallet, addresses, shop, profile, role; `email` may be `null` for phone-only customers (valid state, not unverified/error) — response example `GET /api/v1/me` → `{"status":200,"message":"User profile retrieved successfully","success":true,"data":{"id":1,"name":"...","email":null,"email_verified_at":null,"is_active":true,"image":null,"type":"user","phone_number":"+2010...","created_at":"...","updated_at":"...","roles":[...],"permissions":[...],"address":[]}}`; frontend must not treat `email:null` as error
- [ ] 401 if not authenticated

### Story AUTH-6: Logout
**As a** logged-in user
**I want** to log out
**So that** my session ends

**Acceptance Criteria:**
- [ ] Requires auth:sanctum
- [ ] Deletes current access token
- [ ] NOT rate limited (users must always be able to log out)
- [ ] Returns 404 if no user found (should not happen with auth middleware)

### Story AUTH-7: Password Reset
**As a** registered user
**I want** to reset my forgotten password
**So that** I can regain access to my account

**Acceptance Criteria:**
- [ ] Forget password sends 6-char OTP to email
- [ ] OTP stored hashed in password_resets table
- [ ] OTP expires after `config('auth.passwords.users.expire', 60)` minutes (via `checkResetToken()`)
- [ ] Verify token endpoint returns structured envelope `{"status":200,"message":"Token is valid","success":true}` or `{"status":400,"message":"Invalid token","success":false}` (per `ApiResponse`, not raw boolean)
- [ ] Reset password requires: email, otp, password, password_confirmation
- [ ] Reset deletes all existing tokens (force re-login)
- [ ] Reset cleans up password_resets record
- [ ] Rate limited: 5 requests/min per IP for all three endpoints

### Story AUTH-8: Phone OTP Authentication (Disabled)
**As a** user
**I want** to authenticate via phone OTP
**So that** I can log in without a password

**Acceptance Criteria:**
- [ ] Send OTP code via SMS gateway (configurable)
- [ ] Verify OTP code
- [ ] Return Sanctum token on successful verification
- [ ] Rate limited: 3 requests/min per IP
- [ ] Routes disabled by default
- [ ] Fallback to LocalGateway if configured gateway unavailable

### Story AUTH-9: Rate Limiter Protection
**As a** security engineer
**I want** rate limiters on auth endpoints
**So that** brute force and credential stuffing attacks are mitigated

**Acceptance Criteria:**
- [ ] throttle:auth → 10/min on register, token, admin-login, social-login
- [ ] throttle:sensitive → 5/min on password reset endpoints
- [ ] throttle:otp → 3/min on phone OTP endpoints
- [ ] All throttle keys are per-IP
- [ ] 429 response with Retry-After header
- [ ] Logout is not rate limited
