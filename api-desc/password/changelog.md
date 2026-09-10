# Password — Changelog

## [1.1.0] — Production Hardening (Queue + Security + Config)

### Fixed
- `forgetPassword()` email enumeration: now always returns 200 (does not disclose whether email exists)
- `password_resets` race condition: replaced manual insert/update with atomic `updateOrInsert`
- `verifyForgetPasswordToken()` raw boolean: now returns proper JSON `{success, message}` with HTTP 200/400
- `verifyForgetPasswordToken()` missing validation: added `$request->validate([email, otp])`
- `resetPassword()` validation: moved `$request->validate()` outside try/catch — 422 errors now work properly
- Token expiry: changed from hardcoded 5-min to `config('auth.passwords.users.expire', 60)` (configurable)

### Changed
- Queue: `ForgetPassword` Mailable implements `ShouldQueue`, dispatched to `high` queue
- Queue: `UserRepository::sendResetEmail()` uses `Mail::queue()` instead of `Mail::send()`
- `QUEUE_CONNECTION` from `sync` to `database` (requires queue worker)
- Tests: prefix from `/api` to `/api/v1` (was always 404)
- Translation: added `gone` key for `loginWithOutEmailVerification`

### Security
- No email enumeration on forget-password endpoint
- Validation added to verify-forget-password-token
- Validation errors return 422 instead of 500
- Token expiry now configurable via `config/auth.php`

## [1.0.0] — Initial Documentation

### Added
- 3 password reset endpoints: forget-password, verify-forget-password-token, reset-password
- Custom 6-character alphanumeric OTP generation
- 5-minute OTP expiry window
- `throttle:sensitive` rate limiter (5/min per IP) across all endpoints
- DB transaction for atomic password reset + token revocation
- Automatic cleanup of `password_resets` record after successful reset

### Architecture
- Custom implementation (not Laravel's PasswordBroker)
- Manual bcrypt hash comparison for OTP verification
- All Sanctum tokens revoked on password change
- Email-only delivery (no SMS gateway integration)

### Known Issues
1. Custom implementation bypasses Laravel Password events and broker
