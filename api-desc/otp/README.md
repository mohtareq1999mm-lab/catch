# OTP Module

## Overview
Phone-based one-time password authentication system. Currently **DISABLED** in production — routes exist but are commented out or excluded from active routing. Provides SMS-based OTP verification for login and contact updates using a configurable gateway (Twilio, local, etc.).

## Status
| Feature | Status |
|---------|--------|
| Routes | ❌ Disabled (commented in production) |
| Controller methods | ✅ Fully implemented |
| Middleware | `throttle:otp` — 3/min per IP |

## Rate Limiter
| Limiter | Limit | Scope |
|---------|-------|-------|
| `throttle:otp` | 3/min per IP | Both OTP endpoints share this limiter |

## Endpoints
| Method | URI | Auth | Description | Status |
|--------|-----|------|-------------|--------|
| POST | `/send-otp-code` | None | Send OTP via SMS/email | ❌ Disabled |
| POST | `/otp-login` | None | Login via OTP verification | ❌ Disabled |

## Flow Summary
1. User provides email or phone → `sendUserOtp()` validates input, sends OTP via configured gateway or email
2. User enters OTP code → `otpLogin()` verifies via gateway and returns Sanctum token

## OTP Gateways
| Gateway | Class | Config Key |
|---------|-------|------------|
| Twilio | `Marvel\Otp\Gateways\TwilioGateway` | `auth.active_otp_gateway` |
| Local (dev/testing) | `Marvel\Otp\Gateways\LocalGateway` | Fallback |

The gateway is resolved dynamically via `getOtpGateway()`. If the configured gateway fails, it falls back to `LocalGateway`.

## Related Endpoints (Outside This Module)
- `POST /register` — sends OTP email via `sendOneTimePassword()` for email verification (always active)
- `POST /update-contact` — (auth:sanctum) uses `sendUserOtp()` + `verifyOtp()` to verify new phone number
