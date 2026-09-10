# OTP — QA Test Plan

## Test Environment
- Staging API: `https://staging-api.example.com/api/v1`
- Routes must be enabled (uncomment in Routes.php)
- Test phone number: Twilio verified number or use LocalGateway
- Rate limit: 3/min — test with care to avoid lockout

## 1. Smoke Tests

| # | Test | Expected |
|---|------|----------|
| 1 | Send OTP to test phone | 200, otp_id returned |
| 2 | Send OTP to test email | 200, otp_status true |
| 3 | Login with valid phone OTP | 200, token returned |
| 4 | Login with invalid OTP | 400 |

## 2. Functional Tests

### Send OTP
| # | Test | Expected |
|---|------|----------|
| 5 | Send OTP with international format +201234567890 | 200 |
| 6 | Send OTP with local format 01234567890 | might fail (depends on gateway) |
| 7 | Send OTP multiple times for same number | Each returns new otp_id |
| 8 | Send OTP for number without account | 404 |
| 9 | Send OTP for email without account | 404 |

### OTP Login
| # | Test | Expected |
|---|------|----------|
| 10 | Login with phone + correct OTP | 200, valid token |
| 11 | Login with email + correct OTP | 200, valid token |
| 12 | Login with phone + wrong OTP | 400 |
| 13 | Login with expired OTP | 400 |
| 14 | Login with email OTP for wrong email | 422 exception |
| 15 | Use token to access /me | 200, correct user |
| 16 | Send OTP for number, login with email OTP | Depends on user having both |

## 3. Gateway Tests

| # | Test | Expected |
|---|------|----------|
| 17 | Verify with correct otp_id + code | Gateway returns isValid() = true |
| 18 | Verify with wrong code | Gateway returns isValid() = false |
| 19 | Verify with invalid otp_id | Gateway returns isValid() = false |
| 20 | Gateway times out | Falls back to LocalGateway? Depends on exception handling |
| 21 | Switch config to invalid gateway name | Falls back to LocalGateway (logged) |

## 4. Rate Limiting Tests

| # | Test | Expected |
|---|------|----------|
| 22 | 3 rapid send-otp requests | All 200 |
| 23 | 4th request | 429 |
| 24 | Wait 60 seconds | Requests work again |
| 25 | Mix send + login: 4 total | 4th is 429 |

## 5. Security Tests

| # | Test | Expected |
|---|------|----------|
| 26 | SQL injection in phone_number | 422 or 404, no SQL error |
| 27 | SQL injection in email | 422 or 404 |
| 28 | OTP brute force (try many codes) | Rate limited after 4 attempts |
| 29 | OTP in response body | Static "123456" is in response for email — dev-only risk |
| 30 | Token impersonation | Token belongs to correct user |

## 6. Queue Tests

| # | Test | Expected |
|---|------|----------|
| 35 | Send OTP email — job goes to `jobs` table | Job exists with `queue=high` |
| 36 | Send OTP email — logged when queue worker runs | `storage/logs/laravel.log` contains OTP |
| 37 | API responds immediately without waiting for mail | 200 returned before queue worker runs |
| 38 | Registration OTP also queued (separate system) | Job exists with `queue=high` |

## 7. Regression Tests

| # | Test | Expected |
|---|------|----------|
| 31 | Registration still works (separate OTP system) | 200 |
| 32 | Email/password login still works | 200 |
| 33 | Update contact OTP still works | 200 |
| 34 | Password reset flow still works | 200 |
