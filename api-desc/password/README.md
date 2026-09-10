# Password Module

## Overview
Password reset system allowing users to reset forgotten passwords via email OTP. Three sequential endpoints form a complete flow: request OTP, verify OTP, reset password. All endpoints are protected by `throttle:sensitive` (5/min per IP) to prevent email bombing and brute-force attacks.

## Rate Limiter
| Limiter | Limit | Scope |
|---------|-------|-------|
| `throttle:sensitive` | 5/min per IP | All 3 endpoints share this limiter |

## Endpoints
| Method | URI | Auth | Description |
|--------|-----|------|-------------|
| POST | `/forget-password` | None | Request password reset — sends 6-char OTP via email |
| POST | `/verify-forget-password-token` | None | Verify OTP validity and expiry |
| POST | `/reset-password` | None | Reset password with verified OTP |

## Flow Summary
1. User enters email → `forgetPassword()` generates 6-char OTP, stores hashed in `password_resets` table, emails plaintext OTP
2. User enters OTP → `verifyForgetPasswordToken()` checks hash + 5-minute expiry, returns boolean
3. User enters new password + OTP → `resetPassword()` verifies OTP, updates password, deletes all existing tokens, cleans up `password_resets`

## Key Architecture Decisions
1. **Custom implementation** (not Laravel's PasswordBroker) — uses plain `password_resets` table with manual hash/expiry checks
2. **6-character OTP** — shorter than Laravel's default for better UX, at the cost of reduced entropy
3. **5-minute OTP expiry** — hardcoded via `Carbon::addMinutes(5)` in `verifyForgetPasswordToken()`
4. **All tokens revoked on reset** — `$user->tokens()->delete()` ensures compromised tokens are invalidated
5. **Email-only** — no SMS option for password reset (OTP routes exist separately under phone auth)
