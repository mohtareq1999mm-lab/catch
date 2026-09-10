# Auth — Changelog

## [1.2.0] — REST Registration: email optional, phone required

### Changed
- **REST `POST /api/v1/register` email is now optional** — validation changed to `email: nullable|sometimes|email|unique:users,email|email:rfc,dns` (`packages/marvel/src/Http/Requests/UserCreateRequest.php:30-38`); `phone_number` is required (`required|string|max:20|min:10|unique:users,phone_number`). Omit `email` or send `email: null`; `email = null` is persisted as `users.email = NULL` and is a valid customer state, not unverified or an error. `UserController::register()` guards `sendOneTimePassword()` with `if ($user->email)` (`packages/marvel/src/Http/Controllers/UserController.php:668-686`) and returns `{"status":200,"message":"User registered successfully","success":true,"data":{"otp_status":true}}` with no email side effect for phone-only registrations. Admin creation and GraphQL `RegisterInput.email: String!` remain email-required (unchanged).
- **`POST /api/v1/token` supports `phone_number + password`** via `required_without` (`packages/marvel/src/Http/Requests/UserAuthEmailAndPasswordRequest.php`) — either `email + password` or `phone_number + password` is accepted; phone-only customers log in with `phone_number`. Response envelope `{"status":200,"message":"User logged in successfully","success":true,"data":{"token":"1|...","email_verified":bool,"permissions":[...],"role":[...],"expires_at":"..."}}`.
- **`GET /api/v1/me` returns `email: string | null`** — `email = null` for phone-only customers is valid, not an error; `email_verified_at` is `null` for phone-only. Via `UserResource`: `{"status":200,"message":"User profile retrieved successfully","success":true,"data":{"id":1,"name":"...","email":null,"email_verified_at":null,"is_active":true,"image":null,"type":"user","phone_number":"010...","created_at":"...","updated_at":"...","roles":[...],"permissions":[...],"address":[]}}`.
- **Password reset is email-only by design** — `password_resets.email` is NOT NULL; phone-only users (`email = null`) cannot use `POST /api/v1/forget-password` / `POST /api/v1/verify-forget-password-token` / `POST /api/v1/reset-password` (email-only flow). Frontend should not offer email reset for phone-only accounts. Envelope: `{"status":200,"message":"Check your inbox for password reset email","success":true}` / `{"status":200,"message":"Token is valid","success":true}` / `{"status":200,"message":"Password reset successfully","success":true}`.

### Notes
- No GraphQL changes. Docs updated: `README.md`, `bug-report.md`, `qa.md`, `test-cases.md`, `password-reset.md` — added phone-only register examples and clarified `email = null` valid state.

## [1.1.0] — Production Hardening (SMTP + Queue + Security)

### Fixed
- SMTP authentication failure: switched `MAIL_MAILER` to `log` (safe dev default)
- Email queueing: all mailables implement `ShouldQueue`, dispatched to `database` connection on `high` queue
- `forgetPassword` email enumeration: always returns 200 (does not disclose whether email exists)
- `password_resets` race condition: replaced manual insert/update with atomic `updateOrInsert`
- `verifyForgetPasswordToken` empty response: now returns proper JSON `{"status":200,"message":"Token is valid","success":true}` / `{"status":400,"message":"Invalid token","success":false}` with envelope per `ApiResponse`
- `verifyForgetPasswordToken` missing validation: added `$request->validate()` for email + otp fields
- `resetPassword` validation swallowed: moved `$request->validate()` outside try/catch — 422 errors now work
- Token expiry: changed from hardcoded 5 minutes to `config('auth.passengers.users.expire', 60)`
- Dead code: `loginWithOutEmailVerification` now returns 410 instead of logging in
- Missing `gone` translation key added to EN, AR, DE

### Changed
- `QUEUE_CONNECTION` from `sync` to `database`
- `ForgetPassword` Mailable: added `implements ShouldQueue`, queues on `high`
- `UserRepository::sendResetEmail`: `Mail::send()` → `Mail::queue()`
- `OneTimePasswordNotification`: added `implements ShouldQueue`, queues on `high`
- `UserPasswordResetTest`: fixed prefix from `/api` to `/api/v1` (was always 404)

### Known Issues
1. Custom password reset bypasses Laravel broker integration
2. `sendUserOtp` uses wrong translation key (USER_LOGGED_IN_SUCCESSFULLY) — should be OTP-specific key
3. Duplicate `/api/v1/me` route registration
4. `contacts` table missing in test SQLite DB (contact-us tests fail)
5. `sendUserOtp`: `otp_id` missing from success response (fixed: now returns in `data`)

### Fixed in this release
- `sendUserOtp()` now returns `otp_id` in success response (line 565 was missing `$data` as 4th arg)
- `otpLogin()` token wrapped in `['token' => $token]` array for consistent JSON structure (was raw string)

## [1.0.0] — Initial Documentation

### Added
- 11 auth endpoints documented across 3 rate limiter groups
- Registration with email/phone + role assignment — REST: `phone_number` required, `email` optional (`nullable|sometimes|email|unique:users,email|email:rfc,dns`; `email = null` valid) for `[1.2.0]`; admin/GraphQL email-required
- Email/password login with Sanctum token generation — `[1.2.0]` supports `phone_number + password` via `required_without` (`POST /api/v1/token` supports phone_number + password); admin login remains email-required — envelope `{"status":200,"message":"User logged in successfully","success":true,"data":{"token":"1|...","email_verified":bool,"permissions":[...],"role":[...],"expires_at":"..."}}`
- Admin login with email verification requirement
- Social login (Google/Facebook) via Laravel Socialite
- Password reset flow (forget → verify → reset) with 6-char OTP
- Phone OTP authentication skeleton (disabled)
- Current user profile endpoint
- Token revocation on logout

### Architecture
- Three rate limiters: `auth` (10/min), `sensitive` (5/min), `otp` (3/min)
- Custom password reset implementation (not Laravel's PasswordBroker)
- Spatie MediaLibrary for avatar upload
- AdminLoggedIn event dispatched on login
- DB transactions for registration and password reset

### Known Issues
1. `POST /api/v1/verify-forget-password-token` previously returned raw boolean instead of JSON — fixed to `{"status":200,"message":"Token is valid","success":true}` / `{"status":400,"message":"Invalid token","success":false}`
2. Custom password reset bypasses Laravel broker integration
3. `sendUserOtp` uses wrong translation key (USER_LOGGED_IN_SUCCESSFULLY)
4. Duplicate `/api/v1/me` route registration
