# Auth API Reference

## POST /api/v1/register

Register a new customer account. `phone_number` is required; `email` is optional for REST — omit the field or send `email: null` (valid customer state, not an error). When supplied, email must pass `nullable|sometimes|email|unique:users,email|email:rfc,dns` (`packages/marvel/src/Http/Requests/UserCreateRequest.php:30-38`).

### Request — with email
```json
{
  "first_name": "John",
  "last_name": "Doe",
  "email": "john@example.com",
  "phone_number": "+201234567890",
  "password": "securePass123!",
  "password_confirmation": "securePass123!",
  "policy": true
}
```

### Request — phone-only (email omitted or `null`)
```json
{
  "first_name": "John",
  "last_name": "Doe",
  "phone_number": "+201234567890",
  "password": "securePass123!",
  "password_confirmation": "securePass123!",
  "policy": true
}
```
Alternatively with explicit `null`:
```json
{
  "first_name": "John",
  "last_name": "Doe",
  "email": null,
  "phone_number": "+201234567890",
  "password": "securePass123!",
  "password_confirmation": "securePass123!",
  "policy": true
}
```
Both forms are accepted; `email = null` is persisted as `users.email = NULL` and is a valid state.

### Validation Rules
| Field | Rules |
|-------|-------|
| first_name | required, string, max:50, min:2 |
| last_name | required, string, max:50, min:2 |
| email | nullable, sometimes, email, unique:users,email, email:rfc,dns — optional for REST; omit or send `null`; validated only when present |
| phone_number | required, string, max:20, min:10, unique:users |
| password | required, string, min:8, max:50, confirmed |
| password_confirmation | required, string, min:8, max:50 |
| policy | required, in:1,true |

> Source: `packages/marvel/src/Http/Requests/UserCreateRequest.php:30-38` — `email: ['nullable','sometimes','email','unique:users,email','email:rfc,dns']`, `phone_number: ['required',...,'unique:users,phone_number']`. Admin creation and GraphQL `RegisterInput.email: String!` remain email-required (unchanged).

### Success Response (200)
```json
{
  "status": 200,
  "message": "User registered successfully",
  "success": true,
  "data": {
    "otp_status": true
  }
}
```

### Success — phone-only example (200)
Registration with `email` omitted or `email: null` returns the same 200 envelope `{"status":200,"message":"User registered successfully","success":true,"data":{"otp_status":true}}`; `users.email` is persisted as `NULL`. No email OTP is attempted for phone-only users — `UserController::register()` guards `sendOneTimePassword()` with `if ($user->email)` and returns `{"status":200,"message":"User registered successfully","success":true,"data":{"otp_status":true}}` directly when `email` is `null` (see `packages/marvel/src/Http/Controllers/UserController.php:668-686`).

### Partial Failure Response (201 — OTP send failed; email path only)
Only possible when an email was supplied and the OTP email dispatch fails:
```json
{
  "status": 201,
  "message": "Account created but OTP failed",
  "success": true,
  "data": {
    "requires_resend": true,
    "email": "john@example.com",
    "phone_number": "+201234567890",
    "otp_status": false
  }
}
```
Phone-only registrations do not trigger this branch (no email to send to); they succeed with `{"status":200,"message":"User registered successfully","success":true,"data":{"otp_status":true}}`.

---

## POST /api/v1/token

Login with `email + password` or `phone_number + password` (`required_without` — either identifier is accepted).

### Request
```json
{
  "email": "john@example.com",
  "password": "securePass123!"
}
```

Or with phone number:
```json
{
  "phone_number": "+201234567890",
  "password": "securePass123!"
}
```

### Validation
Uses `UserAuthEmailAndPasswordRequest` — `email: required_without:phone_number|email` and `phone_number: required_without:email|string|max:15|min:8` (`packages/marvel/src/Http/Requests/UserAuthEmailAndPasswordRequest.php`). Either `email + password` or `phone_number + password` is accepted (`POST /api/v1/token` supports `phone_number + password` via the `required_without` pattern). `password: required|string|min:6`.

### Success Response (200)
```json
{
  "status": 200,
  "message": "User logged in successfully",
  "success": true,
  "data": {
    "token": "1|abc123...",
    "email_verified": false,
    "permissions": ["view-products", "create-products"],
    "role": ["customer"],
    "expires_at": "2026-09-21T12:00:00.000000Z"
  }
}
```

### Error Response (404)
```json
{
  "status": 404,
  "message": "Invalid credentials",
  "success": false
}
```

---

## POST /api/v1/admin-login

Admin login (requires verified email).

### Request
```json
{
  "email": "admin@example.com",
  "password": "adminPass123!"
}
```

### Success Response (200)
```json
{
  "status": 200,
  "message": "User logged in successfully",
  "success": true,
  "data": {
    "token": "1|abc123...",
    "permissions": ["super_admin", "view_users"],
    "email_verified": true,
    "role": ["super_admin"],
    "expires_at": "2026-09-21T12:00:00.000000Z"
  }
}
```

### Error Responses
- **404**: `{"status":404,"message":"Invalid credentials","success":false}` / `{"status":404,"message":"User not found","success":false}` / `{"status":404,"message":"User not verified","success":false}`

---

## POST /api/v1/social-login-token

Login or register via Google/Facebook.

### Request
```json
{
  "provider": "google",
  "access_token": "ya29.a0AfH6SMC..."
}
```

### Supported Providers
- `google`
- `facebook`

### Business Logic
- Uses Laravel Socialite to verify token
- `firstOrCreate` by email — existing user is logged in, new user is created
- Auto-assigns `email_verified_at = now()`
- Appends/updates provider record in `user_providers` table
- Password is set to `Hash::make('password')` (never used for OAuth users)

### Success Response (200)
```json
{
  "status": 200,
  "message": "User logged in successfully",
  "success": true,
  "data": {
    "token": "1|abc123..."
  }
}
```

---

## GET /api/v1/me

Get the authenticated user's profile.

### Auth
`auth:sanctum`

### Success Response (200)
`email` may be `string` or `null`; `email = null` is a valid customer state, not an error (see `database.md`). `email_verified_at` is `null` for phone-only customers. Via `Marvel\Http\Resources\UserResource` → `{"status":200,"message":"...","success":true,"data":{"id":1,"name":"...","email":null,"email_verified_at":null,"is_active":true,"image":null,"type":"user","phone_number":"010...","created_at":"...","updated_at":"...","roles":[...],"permissions":[...],"address":[...]}}` (envelope: `status`/`message`/`success`/`data` per `packages/marvel/src/Traits/ApiResponse.php`).

With email:
```json
{
  "status": 200,
  "message": "User profile retrieved successfully",
  "success": true,
  "data": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com",
    "email_verified_at": "2026-07-20T12:00:00Z",
    "is_active": true,
    "image": null,
    "type": "user",
    "phone_number": "+201234567890",
    "created_at": "2026-07-20T12:00:00Z",
    "updated_at": "2026-07-20T12:00:00Z",
    "roles": [{ "id": 1, "name": "customer" }],
    "permissions": [{ "id": 1, "name": "view-products" }],
    "address": []
  }
}
```

Phone-only (no email):
```json
{
  "status": 200,
  "message": "User profile retrieved successfully",
  "success": true,
  "data": {
    "id": 1,
    "name": "John Doe",
    "email": null,
    "email_verified_at": null,
    "is_active": true,
    "image": null,
    "type": "user",
    "phone_number": "+201234567890",
    "created_at": "2026-07-20T12:00:00Z",
    "updated_at": "2026-07-20T12:00:00Z",
    "roles": [{ "id": 1, "name": "customer" }],
    "permissions": [{ "id": 1, "name": "view-products" }],
    "address": []
  }
}
```

### Error (401)
```json
{
  "status": 401,
  "message": "Not authorized",
  "success": false
}
```

---

## POST /api/v1/logout

Revoke the current access token.

### Auth
`auth:sanctum`

### Success Response (200)
```json
{
  "status": 200,
  "message": "Logged out successfully",
  "success": true
}
```

### Error (404 — no user)
```json
{
  "status": 404,
  "message": "User not found",
  "success": false
}
```

---

## POST /api/v1/forget-password

Request a password reset OTP.

### Request
```json
{
  "email": "john@example.com"
}
```

### Business Logic
- Lookup user by email
- Generate a random 6-character string
- Store hashed token in `password_resets` table (upsert)
- Send email with plaintext OTP via `UserRepository::sendResetEmail()`
- Token expires after 5 minutes

### Success Response (200)
```json
{
  "status": 200,
  "message": "Check your inbox for password reset email",
  "success": true
}
```

### Error (404)
```json
{
  "status": 404,
  "message": "Not found",
  "success": false
}
```

---

## POST /api/v1/verify-forget-password-token

Verify a password reset OTP.

### Request
```json
{
  "email": "john@example.com",
  "otp": "A1B2C3"
}
```

### Business Logic
- Look up `password_resets` by email
- Hash::check the OTP
- Verify token is not older than `config('auth.passwords.users.expire', 60)` minutes (via `checkResetToken()`)
- Returns structured `ApiResponse` envelope (not raw boolean)

### Response (200 — valid)
```json
{
  "status": 200,
  "message": "Token is valid",
  "success": true
}
```

### Response (400 — invalid/expired)
```json
{
  "status": 400,
  "message": "Invalid token",
  "success": false
}
```

---

## POST /api/v1/reset-password

Reset password with OTP verification.

### Request
```json
{
  "email": "john@example.com",
  "password": "newSecurePass456!",
  "password_confirmation": "newSecurePass456!",
  "otp": "A1B2C3"
}
```

### Validation Rules
| Field | Rules |
|-------|-------|
| password | required, string, min:8, max:50, confirmed |
| password_confirmation | required, string, min:8, max:50 |
| email | required, email |
| otp | required, string |

### Business Logic
- Runs in a database transaction
- Calls `verifyForgetPasswordToken()` internally
- If OTP invalid → returns 400 `Invalid token`
- Hashes new password
- Deletes all existing tokens (forces re-login on all devices)
- Cleans up the `password_resets` record

### Success Response (200)
```json
{
  "status": 200,
  "message": "Password reset successfully",
  "success": true
}
```

---

## POST /api/v1/send-otp-code — DISABLED

Send a phone OTP via SMS gateway.

### Request
```json
{
  "email": "john@example.com",
  "phone_number": "+201234567890"
}
```

### Validation
- `email` required_without:phone_number
- `phone_number` required_without:email, string, max:15, min:11

### Response (200)
```json
{
  "status": 200,
  "message": "Verification code sent successfully",
  "success": true,
  "data": {
    "otp": "123456",
    "otp_id": "verification-id-from-gateway",
    "provider": "twilio"
  }
}
```

---

## POST /api/v1/otp-login — DISABLED

Login via OTP (phone or email).

### Request (phone)
```json
{
  "phone_number": "+201234567890",
  "otp_id": "...",
  "code": "123456"
}
```

### Request (email)
```json
{
  "email": "john@example.com",
  "otp": "123456"
}
```

### Response (200)
```json
{
  "status": 200,
  "message": "User logged in successfully",
  "success": true,
  "data": {
    "token": "1|abc123..."
  }
}
```
