# Authentication API

## POST /api/v1/register

Register a new user account.

**Authentication:** None (public)
**Rate Limit:** 10/min per IP (throttle:auth)

### Request Body

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| first_name | string | yes | User's first name |
| last_name | string | yes | User's last name |
| email | string | no | Optional for REST — `nullable\|sometimes\|email\|unique:users,email\|email:rfc,dns` (omit or send `null`; validated only when present; source `packages/marvel/src/Http/Requests/UserCreateRequest.php:30-38`) |
| password | string | yes | Min 8 chars |
| phone_number | string | yes | Required; `unique:users,phone_number` |
| avatar | file | no | Profile picture |

> `POST /api/v1/register` accepts `email` omitted or `email: null`; `email = null` is a valid customer state, not an error. `phone_number` is required for REST customers. Admin/GraphQL registration remains email-required.

#### Request examples

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

Phone-only (email omitted or `null`):
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

### Response (200)

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

### Response (200) — phone-only (email omitted / `null`)

Same envelope as above; `users.email` is persisted as `NULL`. No email OTP is attempted — `UserController::register()` guards `sendOneTimePassword()` with `if ($user->email)` and returns `{"status":200,"message":"User registered successfully","success":true,"data":{"otp_status":true}}` directly when `email` is `null` (`packages/marvel/src/Http/Controllers/UserController.php:668-686`).

### Response (201 — OTP email failed; email path only)

Only returned when an email was supplied and the OTP email dispatch fails:
```json
{
  "status": 201,
  "message": "Account created but OTP failed",
  "success": true,
  "data": {
    "otp_status": false,
    "requires_resend": true,
    "email": "user@example.com",
    "phone_number": "12345678901"
  }
}
```
Phone-only registrations do not trigger this branch.

### Execution Flow

```
POST /api/v1/register
  → throttle:auth (10/min)
  → UserController::register()
    → UserCreateRequest (validation: email nullable|sometimes|email|unique:users,email|email:rfc,dns; phone_number required — packages/marvel/src/Http/Requests/UserCreateRequest.php:30-38)
    → DB::beginTransaction()
    → UserRepository::create()  (email may be null — valid state, not an error)
    → assignRole('customer')
    → DB::commit()
    → if ($user->email) User::sendOneTimePassword() (Spatie OTP notification — queued via ShouldQueue, dispatched to 'high' queue) else skip (no email-side effect)
    → JSON Response (200 otp_status:true, or 201 requires_resend when email OTP fails)
```

---

## POST /api/v1/token

Login with `email + password` or `phone_number + password` (`required_without` — either identifier is accepted).

**Authentication:** None (public)
**Rate Limit:** 10/min per IP (throttle:auth)

### Request Body

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| email | string | `required_without:phone_number` | Registered email (`email` rule) — required if `phone_number` omitted; source `packages/marvel/src/Http/Requests/UserAuthEmailAndPasswordRequest.php` |
| phone_number | string | `required_without:email` | Registered phone (`string|max:15|min:8`) — required if `email` omitted |
| password | string | yes | Account password (`required|string|min:6`) |

`POST /api/v1/token` supports `phone_number + password` via the `required_without` pattern.

#### Request examples

With email:
```json
{
  "email": "john@example.com",
  "password": "securePass123!"
}
```

With phone (phone-only customer):
```json
{
  "phone_number": "12345678901",
  "password": "securePass123!"
}
```

### Response (200)

```json
{
  "status": 200,
  "message": "User logged in successfully",
  "success": true,
  "data": {
    "token": "1|sanctum_token_string",
    "email_verified": false,
    "permissions": ["view-products", "create-products"],
    "role": ["customer"],
    "expires_at": "2026-09-21T12:00:00.000000Z"
  }
}
```

### Execution Flow

```
POST /api/v1/token
  → throttle:auth (10/min)
  → UserController::token()
    → UserAuthEmailAndPasswordRequest (email required_without:phone_number|email; phone_number required_without:email)
    → User::where('email', ...) orWhere('phone_number', ...) → first (supports phone-only login)
    → Hash::check(password)
    → User::createToken('auth_token')
    → JSON Response (token + permissions + role)
```
> `phone_number + password` is the primary login for phone-only customers (`email = null` is valid, not an error).

---

## POST /api/v1/logout

Revoke current access token.

**Authentication:** Required (auth:sanctum)

### Request Body

None

### Response (200)

```json
{
  "status": 200,
  "message": "Logged out successfully",
  "success": true
}
```

### Execution Flow

```
POST /api/v1/logout
  → auth:sanctum
  → UserController::logout()
    → $request->user()->currentAccessToken()->delete()
    → JSON Response
```

---

## GET /api/v1/me

Get current authenticated user profile.

**Authentication:** Required (auth:sanctum)

### Response (200)

`email` is `string | null`; `email = null` is a valid customer state, not an error. `email_verified_at` is `null` for phone-only customers. Via `Marvel\Http\Resources\UserResource` → `{"status":200,"message":"User profile retrieved successfully","success":true,"data":{"id":1,"name":"...","email":null,"email_verified_at":null,"is_active":true,"image":null,"type":"user","phone_number":"010...","created_at":"...","updated_at":"...","roles":[...],"permissions":[...],"address":[...]}}` (envelope: `status`/`message`/`success`/`data` per `packages/marvel/src/Traits/ApiResponse.php`).

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
    "email_verified_at": "2026-07-22T10:00:00Z",
    "is_active": true,
    "image": null,
    "type": "user",
    "phone_number": "12345678901",
    "created_at": "2026-07-22T10:00:00Z",
    "updated_at": "2026-07-22T10:00:00Z",
    "roles": [{ "id": 1, "name": "customer" }],
    "permissions": [{ "id": 1, "name": "view-products" }],
    "address": []
  }
}
```

Phone-only:
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
    "phone_number": "12345678901",
    "created_at": "2026-07-22T10:00:00Z",
    "updated_at": "2026-07-22T10:00:00Z",
    "roles": [{ "id": 1, "name": "customer" }],
    "permissions": [{ "id": 1, "name": "view-products" }],
    "address": []
  }
}
```

### Execution Flow

```
GET /api/v1/me
  → auth:sanctum
  → UserController::me()
    → UserResource (serializes email as string|null)
    → JSON Response
```

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

Note: Fields use camelCase (`oldPassword`, `newPassword`), not snake_case.

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
    → Hash::check(oldPassword)
    → Hash::make(newPassword) → save
    → $user->tokens()->delete()
    → JSON Response
```

---

## POST /api/v1/send-otp-code

Send an OTP code via email for phone-based authentication.

**Authentication:** None (public)
**Rate Limit:** 3/min per IP (throttle:otp)

### Request Body

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| email | string | yes (if phone not provided) | User email |
| phone_number | string | yes (if email not provided) | User phone |

### Response (200)

```json
{
  "status": 200,
  "message": "Verification code sent successfully",
  "success": true,
  "data": {
    "otp_id": 42
  }
}
```

### Response (201 — mail failed)

```json
{
  "status": 201,
  "message": "Account created but OTP failed",
  "success": true,
  "data": {
    "otp_status": false,
    "requires_resend": true
  }
}
```

### Execution Flow

```
POST /api/v1/send-otp-code
  → throttle:otp (3/min)
  → UserController::sendUserOtp()
    → validate(email|phone_number)
    → User::where(email|phone_number, is_active) → first
    → if not found → 404
    → if email:
        → User::createOneTimePassword()
        → notify(OneTimePasswordNotification) → queued on 'high'
        → $data['otp_id'] = $oneTimePassword->id
    → if phone:
        → sendOtpCode($request)
        → $data['otp_id'] from response
    → JSON Response (200, data includes otp_id)
```

### Notes

- Returns `otp_id` in `data` — frontend should store this to track the OTP verification session
- OTP email is **queued** (ShouldQueue, `high` queue) — do NOT show instant success; allow brief delay
- On mail failure, returns 201 with `otp_status: false`, `requires_resend: true`
- This endpoint is currently DISABLED in routes (uncomment Routes.php to enable)
- Queue worker must be running: `php artisan queue:work --queue=high,default`

---

## POST /api/v1/otp-login

Verify OTP code and receive authentication token.

**Authentication:** None (public)

### Request Body

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| email | string | yes (if phone not provided) | Registered email |
| phone_number | string | yes (if email not provided) | Registered phone |
| code | string | yes | 4-6 digit OTP code |

### Response (200)

```json
{
  "status": 200,
  "message": "User logged in successfully",
  "success": true,
  "data": {
    "token": "1|sanctum_token_string"
  }
}
```

### Response (400)

```json
{
  "status": 400,
  "message": "OTP verification failed",
  "success": false
}
```

### Execution Flow

```
POST /api/v1/otp-login
  → UserController::otpLogin()
    → if email:
        → UserController::verifyLoginOtp()
          → validate(email, code)
          → User::where(email, is_active) → first
          → verifyOneTimePassword(code)
          → createToken('auth_token')
          → JSON Response (200, {token})
    → if phone_number:
        → verifyOtp(request)
        → User::where(phone_number) → first
        → createToken('auth_token')
        → JSON Response (200, {token})
    → on failure → 400 or 422
```
