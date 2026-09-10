# Password Reset API

## Overview

The password reset flow uses a 4-step process — **email-only by design** (`password_resets.email` is `varchar NOT NULL`; phone-only users `users.email = null` cannot use this flow):

1. **Forget Password** → generates 6-char OTP token, stores hashed in `password_resets` table, sends via queued email
2. **Verify Token** → validates OTP against stored hash + configurable expiry
3. **Reset Password** → updates password, revokes all tokens, deletes reset record
4. **Change Password** → authenticated user changes own password

> **REST `POST /api/v1/register` note:** `email` is optional for REST (`nullable|sometimes|email|unique:users,email|email:rfc,dns` — `packages/marvel/src/Http/Requests/UserCreateRequest.php:30-38`), `phone_number` required; `email = null` is a valid phone-only customer state, not unverified or an error (`UserController::register()` guards `sendOneTimePassword()` with `if ($user->email)` — `UserController.php:668-686`). Phone-only customers (`email = null`) **cannot** use `POST /api/v1/forget-password` / `POST /api/v1/verify-forget-password-token` / `POST /api/v1/reset-password` — `password_resets.email` NOT NULL, email-only by design. `POST /api/v1/token` supports `phone_number + password` for phone-only login; admin/GraphQL remain email-required.

**Mail Driver:** Uses Laravel mail configuration (default: `log` driver for development)
**Queue:** All password reset emails dispatch to the `high` queue — requires `php artisan queue:work --queue=high,default`

---

## POST /api/v1/forget-password — email-only

Request a password reset OTP token. **Email-only** — phone-only users (`users.email = null`) cannot use this endpoint (`password_resets.email` NOT NULL, email-only by design); `POST /api/v1/token` supports `phone_number + password` for phone-only login.

**Authentication:** None (public)
**Rate Limit:** 5/min per IP (throttle:sensitive)

### Request Body — email-only

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| email | string | yes | Registered email address — required; phone-only `email = null` customers have no email to reset (email-only by design; `REST POST /api/v1/register` email is `nullable\|sometimes\|email\|unique:users,email\|email:rfc,dns`, phone required) |

### Response (200)

```json
{
  "status": 200,
  "message": "Check your inbox for password reset email",
  "success": true
}
```

**Always returns 200** — does not disclose whether the email exists (prevents email enumeration). Envelope per `ApiResponse`: `status`/`message`/`success` (no `data` when empty).

### Execution Flow

```
POST /api/v1/forget-password
  → throttle:sensitive (5/min)
  → UserController::forgetPassword()
    → UserRepository::findByField('email', ...) — silent if not found
    → Str::random(6) — generates 6-char OTP
    → DB::table('password_resets')->updateOrInsert() — atomic upsert (no race condition)
    → UserRepository::sendResetEmail() — Mail::to()->queue(new ForgetPassword($token))
    → JSON Response
```

### Mail Template

**File:** `resources/views/emails/forget-password.blade.php`

Sends a Markdown email with the 6-character OTP displayed in a code block.

---

## POST /api/v1/verify-forget-password-token — email-only

Verify the OTP token is valid and not expired. **Email-only** — requires `email` (`password_resets.email` NOT NULL); phone-only users (`email = null`) cannot use this flow.

**Authentication:** None (public)
**Rate Limit:** 5/min per IP (throttle:sensitive)

### Request Body — email-only

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| email | string | yes | Registered email — required; phone-only `email = null` cannot use (email-only by design) |
| otp | string | yes | 6-character token from email |

### Response (200)

```json
{
  "status": 200,
  "message": "Token is valid",
  "success": true
}
```

### Response (400)

```json
{
  "status": 400,
  "message": "Invalid token",
  "success": false
}
```

### Validation Rules

- Token must exist in `password_resets` table for the given email
- Token hash must match using `Hash::check()`
- Token must not be older than `auth.passwords.users.expire` (default: 60 minutes)

### Execution Flow

```
POST /api/v1/verify-forget-password-token
  → throttle:sensitive (5/min)
  → UserController::verifyForgetPasswordToken()
    → $request->validate([email, otp])
    → checkResetToken() — private method
      → DB::table('password_resets')->where('email', ...)->first()
      → Hash::check($request->otp, $tokenData->token)
      → Carbon::parse(...)->addMinutes(config('auth.passwords.users.expire', 60))->isPast()
    → JSON Response
```

---

## POST /api/v1/reset-password — email-only

Reset the password using a verified OTP token. **Email-only** — requires `email` (`password_resets.email` NOT NULL); phone-only users (`email = null`) cannot use this flow.

**Authentication:** None (public)
**Rate Limit:** 5/min per IP (throttle:sensitive)

### Request Body — email-only

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| email | string | yes | Registered email — required; phone-only `email = null` cannot use (email-only by design) |
| otp | string | yes | 6-character token from email |
| password | string | yes | New password (min 8, max 50) |
| password_confirmation | string | yes | Must match password |

### Response (200)

```json
{
  "status": 200,
  "message": "Password reset successfully",
  "success": true
}
```

### Response (400)

```json
{
  "status": 400,
  "message": "Invalid token",
  "success": false
}
```

### Execution Flow

```
POST /api/v1/reset-password
  → throttle:sensitive (5/min)
  → UserController::resetPassword()
    → $request->validate([password, password_confirmation, email, otp]) — outside try/catch (returns 422, not 500)
    → DB::transaction()
      → checkResetToken() — validates OTP + expiry
      → User::where('email', ...)->first()
      → $user->password = Hash::make($request->password)
      → $user->save()
      → $user->tokens()->delete()
      → DB::table('password_resets')->where('email', ...)->delete()
    → JSON Response
```

### Side Effects

- All existing Sanctum tokens for the user are revoked (forces re-login on all devices)
- The `password_resets` record is deleted (prevents token reuse)

---

## POST /api/v1/change-password

Change password for authenticated user.

**Authentication:** Required (auth:sanctum, email.verified)

### Request Body

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| oldPassword | string | yes | Current password |
| newPassword | string | yes | New password (min 8, max 50) |
| newPassword_confirmation | string | yes | Must match newPassword |

Note: Fields use camelCase (`oldPassword`, `newPassword`).

### Response (200)

```json
{
  "status": 200,
  "message": "Password reset successfully",
  "success": true
}
```

### Execution Flow

```
POST /api/v1/change-password
  → auth:sanctum
  → email.verified
  → UserController::changePassword()
    → ChangePasswordRequest (validation)
    → Hash::check($request->oldPassword, $user->password)
    → $user->password = Hash::make($request->newPassword)
    → $user->tokens()->delete()
    → JSON Response
```

---

## Database Tables

### `password_resets` — email-only (`email` NOT NULL)

| Column | Type | Description |
|--------|------|-------------|
| email | varchar NOT NULL | User email (unique per reset) — phone-only users (`users.email = null`) have no row; cannot use email reset by design |
| token | varchar | Hashed OTP token |
| created_at | timestamp | Token generation time |

> Phone-only REST customers (`users.email = null`, valid state — `email: nullable|sometimes|email|unique:users,email|email:rfc,dns` for `POST /api/v1/register`, `UserController::register()` guard `if ($user->email)`) cannot use email password reset; `POST /api/v1/token` supports `phone_number + password` for their login. Admin/GraphQL remain email-required.

### Indexes

- Primary key on `email` (upsert uses email as unique identifier)

---

## Mail Configuration

| Setting | Value | Source |
|---------|-------|--------|
| Default mailer | `log` (dev) / any (prod) | `.env` → `MAIL_MAILER` |
| Queue for mail | `high` | Set via `$this->onQueue('high')` in Mailable |
| Queue connection | `database` | `.env` → `QUEUE_CONNECTION` |
| Queue worker | `php artisan queue:work --queue=high,default` | Required to dispatch emails |
| Token expiry | 60 min (configurable) | `config/auth.php` → `passwords.users.expire` |
| From address | per config | `.env` → `MAIL_FROM_ADDRESS` |
| Reset mail class | `Marvel\Mail\ForgetPassword` | `packages/marvel/src/Mail/ForgetPassword.php` |
| Reset mail template | `resources/views/emails/forget-password.blade.php` | Markdown email |
| OTP notification | `Marvel\Notifications\OneTimePasswordNotification` | `config/one-time-passwords.php` |
| OTP template | `resources/views/emails/one-time-passwords.blade.php` | Bilingual (EN/AR) |
