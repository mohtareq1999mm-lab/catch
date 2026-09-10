# Auth Module

## Overview
Customer and admin authentication system supporting email/password **or** phone_number/password login, registration, social login (Google/Facebook), password reset via email OTP, and phone-based OTP authentication (disabled). All auth endpoints are organized under three rate limiter groups to prevent abuse.

> **REST `POST /api/v1/register`:** `phone_number` is **required** (`required|string|max:20|min:10|unique:users,phone_number`), `email` is **optional** (`nullable|sometimes|email|unique:users,email|email:rfc,dns` — `packages/marvel/src/Http/Requests/UserCreateRequest.php:30-38`); omit or send `email: null`. `email = null` is a valid customer state, not unverified or an error. `POST /api/v1/token` supports `phone_number + password` via `required_without`. Admin creation and GraphQL `RegisterInput.email: String!` remain email-required (unchanged).

## Rate Limiters
| Limiter | Limit | Scope | Applied To |
|---------|-------|-------|------------|
| `throttle:auth` | 10/min per IP | Authentication attempts | register, token, admin-login, social-login-token |
| `throttle:sensitive` | 5/min per IP | Password operations | forget-password, verify-forget-password-token, reset-password |
| `throttle:otp` | 3/min per IP | Phone OTP (disabled) | send-otp-code, otp-login |

## Endpoints

### Authentication (throttle:auth)
| Method | URI | Auth | Description |
|--------|-----|------|-------------|
| POST | `/api/v1/register` | None | Register a new customer account — REST: `phone_number` required, `email` optional (`nullable\|sometimes\|email\|unique:users,email\|email:rfc,dns`); `email = null` valid; admin/GraphQL email-required |
| POST | `/api/v1/token` | None | Login with email + password **or** phone_number + password (`required_without`; `POST /api/v1/token` supports phone_number + password) |
| POST | `/api/v1/admin-login` | None | Admin login (requires verified email) — email required (unchanged) |
| POST | `/api/v1/social-login-token` | None | Login via Google/Facebook OAuth |

### Authenticated User
| Method | URI | Auth | Description |
|--------|-----|------|-------------|
| GET | `/api/v1/me` | `auth:sanctum` | Get current user profile |
| POST | `/api/v1/logout` | `auth:sanctum` | Logout (revoke current token) |

### Password Reset — email-only (throttle:sensitive)
| Method | URI | Auth | Description |
|--------|-----|------|-------------|
| POST | `/api/v1/forget-password` | None | Request password reset (sends 6-digit OTP) — email-only; phone-only users (`email = null`) cannot use this flow (`password_resets.email` NOT NULL, email-only by design) |
| POST | `/api/v1/verify-forget-password-token` | None | Verify reset OTP validity — email + otp |
| POST | `/api/v1/reset-password` | None | Reset password with OTP — email + otp + password |

### Phone OTP — DISABLED (throttle:otp)
| Method | URI | Auth | Description |
|--------|-----|------|-------------|
| POST | `/api/v1/send-otp-code` | None | Send OTP via SMS (disabled) |
| POST | `/api/v1/otp-login` | None | Login via phone OTP (disabled) |

## Key Architecture Decisions
1. **Rate limiters are per-IP** (not per-user) for unauthenticated endpoints to block brute-force at the network level.
2. **Logout is NOT rate limited** — users must always be able to log out regardless of rate limit state.
3. **`/api/v1/me` route is registered twice** (line 102 and line 134 in Routes.php) — once in the public section and once unprotected. The protected version takes precedence via route ordering.
4. **Admin login requires `email_verified`** — regular user login does not. Admin/GraphQL flows remain email-required (unchanged).
5. **Registration auto-assigns "customer" role**; if `email` is present, sends an OTP email for verification, otherwise guards with `if ($user->email)` (`packages/marvel/src/Http/Controllers/UserController.php:668-686`) and returns `{"status":200,"message":"User registered successfully","success":true,"data":{"otp_status":true}}` with no email side effect — `email = null` is a valid phone-only customer state, not unverified or an error. Validation: `email: nullable|sometimes|email|unique:users,email|email:rfc,dns`, `phone_number: required|unique:users,phone_number` (`packages/marvel/src/Http/Requests/UserCreateRequest.php:30-38`).
6. **Password reset uses a plaintext 6-character OTP** stored hashed in `password_resets` table (not Laravel's built-in reset). **Email-only by design:** `password_resets.email` is NOT NULL; phone-only users (`email = null`) cannot use email password reset.
7. **OTP routes are commented/disconnected** — the route group exists but is typically disabled in production deployments.
8. **`POST /api/v1/token` supports `phone_number + password`** via `required_without` (`packages/marvel/src/Http/Requests/UserAuthEmailAndPasswordRequest.php`) — primary login for phone-only customers; `GET /api/v1/me` returns `email: null` for phone-only users (valid, not an error). Envelope per `ApiResponse`: `{"status":200,"message":"User logged in successfully","success":true,"data":{"token":"1|...","email_verified":bool,"permissions":[...],"role":[...],"expires_at":"..."}}` — note `data.token`.

### Registration Examples

With email:
```json
{
  "first_name": "John",
  "last_name": "Doe",
  "email": "john@example.com",
  "phone_number": "12345678901",
  "password": "securePass123!",
  "password_confirmation": "securePass123!",
  "policy": true
}
```

Phone-only (email omitted or `null` — both valid):
```json
{
  "first_name": "John",
  "last_name": "Doe",
  "phone_number": "12345678901",
  "password": "securePass123!",
  "password_confirmation": "securePass123!",
  "policy": true
}
```
```json
{
  "first_name": "John",
  "last_name": "Doe",
  "email": null,
  "phone_number": "12345678901",
  "password": "securePass123!",
  "password_confirmation": "securePass123!",
  "policy": true
}
```
Phone-only returns same `{"status":200,"message":"User registered successfully","success":true,"data":{"otp_status":true}}` envelope; `users.email` persisted as `NULL`; no OTP email attempted.
