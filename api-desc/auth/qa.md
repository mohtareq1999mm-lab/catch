# Auth — QA Test Plan

## Test Environment
- Staging API URL: `https://staging-api.example.com/api/v1`
- Test accounts: pre-seeded customer, admin (verified), admin (unverified), inactive user
- Rate limiter reset: wait 1 minute or use `Cache::forget()` for throttle keys

## 1. Smoke Tests — REST `phone_number` required, `email` optional (`nullable|sometimes|email|unique:users,email|email:rfc,dns`); `email = null` valid; `POST /api/v1/token` supports phone_number + password; admin/GraphQL email-required

| # | Test | Expected |
|---|------|----------|
| 1 | Register a new user (with email) | `{"status":200,"message":"User registered successfully","success":true,"data":{"otp_status":true}}`, user exists in DB |
| 1a | Register phone-only (`email` omitted/null) | `{"status":200,"message":"User registered successfully","success":true,"data":{"otp_status":true}}`, `users.email = null` valid, no OTP email (guard `if ($user->email)`) |
| 2 | Login with new user (email + password) | `{"status":200,"message":"User logged in successfully","success":true,"data":{"token":"1|...","email_verified":bool,"permissions":[...],"role":[...],"expires_at":"..."}}` |
| 2a | Login phone-only customer with phone + password | `{"status":200,"message":"User logged in successfully","success":true,"data":{"token":"1|...","email_verified":false,"permissions":[...],"role":[...],"expires_at":"..."}}` |
| 3 | Get current user | `{"status":200,"message":"User profile retrieved successfully","success":true,"data":{"id":1,"name":"...","email":null,"email_verified_at":null,"is_active":true,"image":null,"type":"user","phone_number":"010...","created_at":"...","updated_at":"...","roles":[...],"permissions":[...],"address":[]}}` — `email` may be `null` for phone-only (valid, not error) |
| 4 | Logout | `{"status":200,"message":"Logged out successfully","success":true}`, token invalidated |
| 5 | Try authenticated request after logout | 401 |

## 2. Functional Tests

### Registration — REST: `phone_number` required, `email` optional (`nullable|sometimes|email|unique:users,email|email:rfc,dns` — `packages/marvel/src/Http/Requests/UserCreateRequest.php:30-38`); `email = null` valid, not an error; admin/GraphQL email-required
| # | Test | Expected |
|---|------|----------|
| 6 | Register with all valid fields (with email) | `{"status":200,"message":"User registered successfully","success":true,"data":{"otp_status":true}}` |
| 6a | Register phone-only — `email` omitted | `{"status":200,"message":"User registered successfully","success":true,"data":{"otp_status":true}}`, `users.email = null`, no OTP email (guard `if ($user->email)` — `UserController.php:668-686`) |
| 6b | Register phone-only — `email: null` explicit | `{"status":200,"message":"User registered successfully","success":true,"data":{"otp_status":true}}`, `users.email = null`, same as 6a |
| 7 | Register with minimum fields (phone-only, no email) | 200 |
| 8 | Register with special chars in name | 200 |
| 9 | Register with Arabic/UTF-8 name | 200 |
| 10 | Register without accepting policy | 422 |
| 11 | Register with mismatched passwords | 422 |
| 12 | Register with existing email | 422 |
| 13 | Register with invalid email (no @) | 422 — only validated when email present (`nullable|sometimes`) |
| 14 | Register with phone < 10 digits | 422 |
| 14a | Register without phone_number | 422 — `phone_number` required for REST |
| 14b | Register with duplicate phone_number | 422 — `unique:users,phone_number` |
| 14c | Register phone-only — verify no OTP email queued when `email = null` | 200, `jobs` table has no `high` OTP job for this user |

### Login — `POST /api/v1/token` supports `email + password` **or** `phone_number + password` (`required_without`; `UserAuthEmailAndPasswordRequest`); phone-only login is primary for `email = null` customers
| # | Test | Expected |
|---|------|----------|
| 15 | Login with correct email + password | 200, token |
| 16 | Login with correct phone + password | 200, `{"status":200,"message":"User logged in successfully","success":true,"data":{"token":"1|...","email_verified":bool,"permissions":[...],"role":[...],"expires_at":"..."}}` — `POST /api/v1/token` supports phone_number + password |
| 16a | Login phone-only customer with phone + password | 200, token, `email = null` is valid not an error |
| 17 | Login with correct email + wrong password | 404 |
| 18 | Login with wrong email | 404 |
| 19 | Login with inactive user | 404 |
| 20 | Login with empty password | 422 |
| 20a | Login with both email and phone omitted | 422 — `required_without` |
| 20b | Get current user for phone-only account | 200, `email: null`, `email_verified_at: null` — valid state, not unverified/error |

### Admin Login — email required (unchanged; admin/GraphQL remain email-required)
| # | Test | Expected |
|---|------|----------|
| 21 | Admin login with verified email | 200, permissions + role in response |
| 22 | Admin login with unverified email | 404 |
| 23 | Customer attempts admin login | 404 |

### Social Login
| # | Test | Expected |
|---|------|----------|
| 24 | Google login with valid access_token (mock) | 200 |
| 25 | Facebook login with valid access_token (mock) | 200 |
| 26 | Login with unsupported provider | 422 |
| 27 | Login with expired/invalid token | 422 |

### Password Reset — email-only (phone-only users `email = null` cannot use; `password_resets.email` NOT NULL, email-only by design)
| # | Test | Expected |
|---|------|----------|
| 28 | Request reset for existing email | 200 |
| 29 | Request reset for non-existing email | **200** (no email enumeration) |
| 28c | Request reset with `email: null` / phone-only user | 200 (no enumeration) but no record created — phone-only cannot reset via email |
| 30 | Verify valid OTP | **200, `{"status":200,"message":"Token is valid","success":true}`** |
| 31 | Verify invalid OTP | **400, `{"status":400,"message":"Invalid token","success":false}`** |
| 32 | Verify expired OTP (wait 61 min) | **400, `{"status":400,"message":"Invalid token","success":false}`** |
| 33 | Reset password with valid OTP | 200, can login with new password |
| 34 | Reset password with invalid OTP | 400 |
| 35 | Reset password with too-short new password | 422 (was 500 before fix) |
| 36 | After reset, old token cannot be used | 401 |
| 37 | After reset, login with old password fails | 404 |

### OTP Endpoints
| # | Test | Expected |
|---|------|----------|
| 28a | Request OTP for existing email | 200, `data.otp_id` present |
| 28b | Request OTP for non-existing email | 404 |
| 28c | Request OTP with both email+phone missing | 422 |
| 28d | Verify OTP with valid code | 200, `data.token` present |
| 28e | Verify OTP with invalid code | 400, `{"status":400,"message":"OTP verification failed","success":false}` |
| 28f | Verify OTP for wrong email | 404 |
| 28g | Phone OTP login with valid code | 200, `data.token` present |
| 28h | Phone OTP login with invalid code | 400 |

## 3. Rate Limiting Tests

| # | Test | Expected |
|---|------|----------|
| 38 | Send 11 register requests rapidly | 10th succeeds, 11th is 429 |
| 39 | Send 11 login requests rapidly | 10th succeeds, 11th is 429 |
| 40 | Send 6 forget-password requests rapidly | 5th succeeds, 6th is 429 |
| 41 | Send 10 logout requests rapidly | All 200 (no throttle) |
| 42 | Wait 1 minute after 429 | Requests succeed again |

## 4. Security Tests

| # | Test | Expected |
|---|------|----------|
| 43 | SQL injection in email field | 422 or 404, no SQL error |
| 44 | XSS in name field | Stored as-is, no script execution |
| 45 | Mass assignment: pass `type=admin` in register | type forced to 'user' |
| 46 | Mass assignment: pass `is_active=true` | Controller sets it explicitly |
| 47 | Token in response body (not just header) | Yes — Sanctum returns in body |
| 48 | Password in response | Never — only token returned |
| 49 | Reset password without OTP field | 422 |
| 50 | Try to use another user's reset token | Fails (email-bound) |
| 51 | Forget password with unknown email | 200 (no enumeration) |
| 52 | Verify token without email field | 422 (validation added) |
| 53 | Concurrent password reset requests | No duplicate tokens (updateOrInsert) |

## 5. Queue Tests

| # | Test | Expected |
|---|------|----------|
| 54 | Register — OTP email goes to `jobs` table | Job exists with `queue=high` |
| 55 | Forget password — reset email goes to `jobs` table | Job exists with `queue=high` |
| 56 | Run queue worker — email is logged | `storage/logs/laravel.log` contains email |
| 57 | API responds immediately without waiting for mail | 200 received before queue worker runs |
| 57a | Send-otp-code — OTP notification goes to `jobs` table | Job exists with `queue=high` |
| 57b | Send-otp-code — OTP email logged when queue runs | `storage/logs/laravel.log` contains OTP |
| 57c | Send-otp-code — returns 200 immediately, before queue processes | Response does NOT contain email body |

## 6. Regression Tests

| # | Test | Expected |
|---|------|----------|
| 58 | Full flow: register (with email) → email verify → login → me → logout | All steps succeed |
| 58a | Full flow: phone-only register (`email` omitted/null) → login with phone + password → me (`email: null` valid) → logout | All steps succeed |
| 59 | Full flow: register → forget password → reset → login with new password | All steps succeed — email-only flow |
| 59a | Phone-only user cannot use email password reset | 200 (no enumeration) but no reset email; verify DB has no `password_resets` for `null` |
| 60 | Full flow: admin login → me → logout | All steps succeed — admin remains email-required |
| 61 | Token reuse after logout | 401 |

## Known Test Limitations
- `contact_us` tests fail because `contacts` table is missing in test SQLite database (pre-existing infrastructure issue)
