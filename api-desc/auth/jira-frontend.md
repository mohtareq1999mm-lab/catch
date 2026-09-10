# Auth — Frontend Jira Stories

## Epic: AUTH-FE-EPIC-1 — Frontend Authentication

### Story AUTH-FE-1: Registration Page — REST: `phone_number` required, `email` optional
**As a** new visitor
**I want** a registration form
**So that** I can create an account

**Acceptance Criteria:**
- [ ] Form fields: first_name*, last_name*, phone_number* (required), email (optional, nullable), password, password_confirmation, policy checkbox
- [ ] Client-side validation: phone_number required (string 10-20, unique), email only when present — `nullable|sometimes|email|unique:users,email|email:rfc,dns`; phone required, email optional; `email = null` is a valid customer state (not unverified/error) — do not show "missing email" error for phone-only
- [ ] Show field-level error messages from API (422) — `email` only validated when present (`nullable|sometimes`), `phone_number` always required
- [ ] Submit to POST /api/v1/register with either `{ first_name, last_name, phone_number, password, password_confirmation, policy }` (phone-only, valid) or with `email` included; Admin/GraphQL remain email-required (REST only)
- [ ] On 200: if email was provided → redirect to email verification prompt or dashboard; if phone-only (`email` omitted/null) → redirect to dashboard — backend register guard `if (user.email)` skips `sendOneTimePassword()` and returns `{"status":200,"message":"User registered successfully","success":true,"data":{"otp_status":true}}` with no email side effect
- [ ] On 201: only when email was provided and mail failed → show "OTP email may not have been sent — you can resend" (phone-only never returns 201)
- [ ] On 429: show rate limit message with timer
- [ ] Disabled submit button while loading
- [ ] Social login buttons (Google, Facebook)

### Story AUTH-FE-2: Login Page — supports `phone_number + password` via `POST /api/v1/token`
**As a** registered user
**I want** a login form
**So that** I can access my account

**Acceptance Criteria:**
- [ ] Form fields: email OR phone_number + password (two modes: email+password, phone_number+password); validation `email required_without:phone_number|email`, `phone_number required_without:email|string|max:15|min:8`; `POST /api/v1/token` supports `phone_number + password` (REST)
- [ ] "Remember me" option (store token in localStorage)
- [ ] Submit to POST /api/v1/token with either `{ email, password }` or `{ phone_number, password }` — phone-only example: `{ "phone_number": "+2010xxxxxxx", "password": "secret123" }`; backend lookup `WHERE email=$email OR phone_number=$phone AND is_active=true`
- [ ] Store token on success
- [ ] Check email_verified flag — if `email` is `null` (phone-only customer) this is valid state, not unverified/error; only prompt verification when email is present and `email_verified` is false
- [ ] Redirect based on role: admin → /admin/dashboard, customer → /
- [ ] Show error on invalid credentials — message "Invalid email/phone or password" (covers both modes)
- [ ] Inactive user message
- [ ] 429 rate limit handling
- [ ] "Forgot password?" link → password reset flow

### Story AUTH-FE-3: Admin Login Page
**As an** admin
**I want** to log in with verified email
**So that** I can access the admin panel

**Acceptance Criteria:**
- [ ] Same form as regular login but calls POST /api/v1/admin-login
- [ ] Stores permissions and role from response
- [ ] Shows "Please verify your email" on USER_NOT_VERIFIED
- [ ] Shows "No admin account found" on USER_NOT_FOUND

### Story AUTH-FE-4: Social Login Buttons
**As a** visitor
**I want** to log in with Google or Facebook
**So that** I can skip manual registration

**Acceptance Criteria:**
- [ ] Google login button using @react-oauth/google
- [ ] Facebook login button using react-facebook-login
- [ ] Extract access_token and send to POST /api/v1/social-login-token
- [ ] Same post-login flow as regular login
- [ ] Handle provider errors gracefully

### Story AUTH-FE-5: Password Reset Flow
**As a** user who forgot their password
**I want** to reset it via email OTP
**So that** I can regain access

**Acceptance Criteria:**
- [ ] Step 1: Enter email → POST /api/v1/forget-password → "Check your inbox for password reset email" (always `{"status":200,"message":"Check your inbox for password reset email","success":true}`, no email enumeration)
- [ ] Forget-password email is **queued** — brief delay; do not show instant delivery
- [ ] Step 2: Enter 6-digit OTP → POST /api/v1/verify-forget-password-token → advance on `success: true`
- [ ] Response is JSON envelope `{"status":200,"message":"Token is valid","success":true}` / `{"status":400,"message":"Invalid token","success":false}` — check `response.success` and `response.status`, not raw boolean
- [ ] Step 3: Enter new password + confirmation → POST /api/v1/reset-password
- [ ] Show "Invalid or expired OTP" on 400 / `success: false`
- [ ] OTP input: 6 characters, auto-advance on mobile
- [ ] Rate limit handling (5/min)
- [ ] Token expiry: 60 minutes (configurable — no hardcoded 5-min timer)

### Story AUTH-FE-6: Auth State Management — handle `email:null` phone-only customers
**As a** frontend developer
**I want** a global auth state
**So that** all components can check authentication status

**Acceptance Criteria:**
- [ ] On app mount: check localStorage for token
- [ ] If token exists: call GET /api/v1/me to validate — response may be `{ email: null, phone_number: "+2010..." }` for phone-only customers; treat `email:null` as valid, not unverified/error
- [ ] Store user data in global state (context/redux) including `email` (nullable) and `phone_number`; `email` may be `null`
- [ ] Provide `isAuthenticated`, `user`, `role`, `permissions` to all components
- [ ] On 401 from any API call: clear token, redirect to login
- [ ] On logout: clear token and user state
- [ ] Handle token expiry (Sanctum does not auto-expire by default)

### Story AUTH-FE-7: Profile Dropdown / User Menu — support phone-only `email:null`
**As a** logged-in user
**I want** to see my name and avatar in the header
**So that** I know I'm logged in

**Acceptance Criteria:**
- [ ] Show user avatar (or initials if no avatar)
- [ ] Show user name
- [ ] Show email if present, otherwise show phone_number — `email:null` is valid phone-only state; do not show "missing email" warning
- [ ] Dropdown menu: Profile, Orders, Settings, Logout
- [ ] Admin users see "Admin Panel" link (admin remains email-required)

### Story AUTH-FE-8: Protected Route Guards
**As a** frontend developer
**I want** route guards for authenticated pages
**So that** unauthenticated users are redirected to login

**Acceptance Criteria:**
- [ ] Pages requiring auth redirect to /login if not authenticated
- [ ] Admin pages redirect to /admin/login if not admin
- [ ] Login/register pages redirect to / if already authenticated
- [ ] Loading state while checking auth on app mount

## Jest Test Cases

### AuthService API layer
1. `register()` — POST /api/v1/register with valid data → returns `{"status":200,"message":"User registered successfully","success":true,"data":{"otp_status":true}}`; REST phone-only without email `{ first_name, last_name, phone_number, password, password_confirmation, policy }` → `{"status":200,"message":"User registered successfully","success":true,"data":{"otp_status":true}}` (no email side effect, `email: null` valid; `email nullable|sometimes|email|unique:users,email|email:rfc,dns`, `phone_number required|string|max:20|min:10|unique:users,phone_number`); with email also valid; Admin/GraphQL remain email-required
2. `login()` — POST /api/v1/token → returns `{"status":200,"message":"User logged in successfully","success":true,"data":{"token":"1|...","email_verified":bool,"permissions":[...],"role":[...],"expires_at":"..."}}`; supports `{ email, password }` OR `{ phone_number, password }` (`email required_without:phone_number|email`, `phone_number required_without:email|string|max:15|min:8`); phone-only example `{ phone_number, password }` → `{"status":200,"message":"User logged in successfully","success":true,"data":{"token":"1|...","email_verified":false,"permissions":[...],"role":[...],"expires_at":"..."}}` is valid phone-only state
3. `adminLogin()` — POST /api/v1/admin-login → returns token + permissions (email required — admin unchanged)
4. `socialLogin()` — POST /api/v1/social-login-token → returns token
5. `getMe()` — GET /api/v1/me → returns `{"status":200,"message":"User profile retrieved successfully","success":true,"data":{"id":1,"name":"...","email":null,"email_verified_at":null,"is_active":true,"image":null,"type":"user","phone_number":"+2010...","created_at":"...","updated_at":"...","roles":[...],"permissions":[...],"address":[]}}`; phone-only returns `{ email: null, phone_number: "+2010..." }` — frontend must treat `email:null` as valid, not error
6. `logout()` — POST /api/v1/logout → clears token
7. `forgetPassword()` — POST /api/v1/forget-password → always 200, success message (no email enumeration)
8. `verifyOtp()` — POST /api/v1/verify-forget-password-token → JSON `{"status":200,"message":"Token is valid","success":true}` or `{"status":400,"message":"Invalid token","success":false}` (not raw boolean)
9. `resetPassword()` — POST /api/v1/reset-password → 422 if validation fails (outside try/catch, works correctly)
10. `sendOtpCode()` — POST /api/v1/send-otp-code → returns `data.otp_id` for session tracking
11. `otpLogin()` — POST /api/v1/otp-login → returns `data.token` (wrapped in object)
12. All API calls handle 401 → auto-logout
13. All API calls handle 429 → rate limit error message
14. All API calls handle 422 → field validation errors

### AuthContext / AuthProvider
15. `useAuth()` returns isAuthenticated=false when no token
16. `useAuth()` returns user data when token is valid; phone-only `email:null` is valid stored state — do not treat as unverified
17. `login()` updates auth state (supports phone_number login)
18. `logout()` clears auth state
19. `useAuth()` redirects to login on 401
