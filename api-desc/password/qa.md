# Password — QA Test Plan

## Test Environment
- Staging API: `https://staging-api.example.com/api/v1`
- Pre-seeded test user with known email
- Rate limit reset: wait 1 minute or clear cache manually

## 1. Smoke Tests

| # | Test | Expected |
|---|------|----------|
| 1 | Request reset for existing email | **200** (was 200) |
| 2 | Request reset for **non-existing** email | **200** (was 404 — no email enumeration) |
| 3 | Verify correct OTP | **200, JSON `success: true`** (was raw `true`) |
| 4 | Verify incorrect OTP | **400, JSON `success: false`** (was raw `false`) |
| 5 | Reset password with valid OTP | 200, can login with new password |

## 2. Functional Tests

### Forget Password
| # | Test | Expected |
|---|------|----------|
| 6 | Reset for user with special chars in email | 200 |
| 7 | Reset for recently registered user | 200 |
| 8 | Multiple reset requests for same email | Each generates new OTP, single record in DB |
| 9 | Reset for inactive user | 200 (always 200 — no email enumeration) |
| 10 | Reset for admin user | 200 (works for any user type) |

### Verify OTP
| # | Test | Expected |
|---|------|----------|
| 11 | Verify immediately after request | 200, `success: true` |
| 12 | Verify after 59 minutes | 200, `success: true` (was 4:59) |
| 13 | Verify after 61 minutes | 400, `success: false` (expired — was 5:01) |
| 14 | Verify with leading/trailing spaces in OTP | 400, `success: false` |
| 15 | Verify with lowercase OTP (was sent uppercase) | 400, `success: false` |
| 16 | Verify OTP for wrong email | 400, `success: false` |
| 17 | Verify OTP missing email field | 422 (validation added — was 500) |
| 18 | Verify OTP missing otp field | 422 |

### Reset Password
| # | Test | Expected |
|---|------|----------|
| 19 | Reset with common password "password123" | 200 |
| 20 | Reset with strong password "Str0ng!Pass#2024" | 200 |
| 21 | Reset and immediately login with old password | 404 |
| 22 | Reset and immediately login with new password | 200 |
| 23 | Use same OTP twice | 400 (second time — OTP deleted) |
| 24 | Request new OTP after failed reset | 200, new OTP works |
| 25 | Reset with too-short password (< 8) | 422 (was 500 before fix) |

## 3. Security Tests

| # | Test | Expected |
|---|------|----------|
| 26 | SQL injection in email field | No SQL error |
| 27 | SQL injection in OTP field | No SQL error |
| 28 | Mass assignment: extra fields in reset | Ignored |
| 29 | OTP visible in server response | Never — only success/failure |
| 30 | Hash::check timing attack | Bcrypt constant-time comparison |
| 31 | Reset password for another user's email | Not possible (OTP is email-bound) |
| 32 | Brute force OTP guessing | 10^62 combinations × 5/min rate limit |
| 33 | Forget password for unknown email | 200 (no enumeration — was 404) |
| 34 | Race condition: concurrent reset requests | No duplicate records (updateOrInsert) |

## 4. Queue Tests

| # | Test | Expected |
|---|------|----------|
| 35 | Forget-password — reset email goes to `jobs` table | Job exists with `queue=high` |
| 36 | Run queue worker — email is logged | `storage/logs/laravel.log` contains OTP |
| 37 | API responds before queue worker runs | 200 returned immediately |
| 38 | Forget-password for unknown email — no job queued | No job in DB (user not found) |

## 5. Cross-Feature Tests

| # | Test | Expected |
|---|------|----------|
| 39 | Reset password, then change password (old password no longer valid) | Change password requires old password (works) |
| 40 | Reset password, then logout | Logout succeeds (no token anyway if reset deleted it) |
| 41 | Reset password while logged in on multiple devices | All sessions invalidated |
| 42 | Password reset + social login user | Social login users have Hash::make('password') as password — can reset to real password |

## 6. Regression Tests

| # | Test | Expected |
|---|------|----------|
| 43 | Full flow: request → verify → reset → login → me → logout | All succeed |
| 44 | Full flow with 59-minute delay between steps | OTP still valid |
| 45 | Full flow with expired OTP (start over) | Works on second attempt |
| 46 | Rate limit hit, wait, complete flow | Works after cooldown |
