# Auth — Bug Reports

> **Note — REST `email` optional (not a bug):** `POST /api/v1/register` validates `email` as `nullable|sometimes|email|unique:users,email|email:rfc,dns` (`packages/marvel/src/Http/Requests/UserCreateRequest.php:30-38`) and `phone_number` as `required` (`unique:users,phone_number`). `email = null` is a valid customer state, not unverified or an error — `UserController::register()` guards `sendOneTimePassword()` with `if ($user->email)` (`packages/marvel/src/Http/Controllers/UserController.php:668-686`) and returns `{"status":200,"message":"User registered successfully","success":true,"data":{"otp_status":true}}` with no email side effect. `POST /api/v1/token` supports `phone_number + password` (`required_without`). Admin creation and GraphQL `RegisterInput.email: String!` remain email-required (unchanged). Missing/ `null` email on REST register is **not** a bug. Phone-only users cannot use email password reset (`password_resets.email` NOT NULL, email-only by design).

## Bug 1: Verify-Forget-Password-Token Returns Raw Boolean Instead of API Response

**Severity**: LOW
**File**: `packages/marvel/src/Http/Controllers/UserController.php:798-821`
**Endpoint**: `POST /api/v1/verify-forget-password-token`

**Description**: The `verifyForgetPasswordToken()` method previously returned a raw PHP `true`/`false` value instead of a structured JSON API response. All other endpoints use `$this->apiResponse()`. **Fixed:** now returns structured envelope via `ApiResponse` — `{"status":200,"message":"Token is valid","success":true}` on success and `{"status":400,"message":"Invalid token","success":false}` on failure (see `packages/marvel/src/Http/Controllers/UserController.php:792-804` and `packages/marvel/src/Traits/ApiResponse.php`).

**Impact**: Previously frontend had to handle a bare `true`/`false` response body with Content-Type `text/html`, not `application/json`. Now consistent with envelope.

**Reproduction** (pre-fix):
1. POST /api/v1/verify-forget-password-token with valid email and OTP → response body was `true` (raw, not JSON)
2. POST with invalid OTP → response body was empty (false returns nothing)

**Current (true) behavior**: Wrapped in proper JSON envelope:
```php
public function verifyForgetPasswordToken(Request $request)
{
    $request->validate(['email' => 'required|email', 'otp' => 'required|string']);
    if ($this->checkResetToken($request)) {
        return $this->apiResponse(TOKEN_IS_VALID, 200, true);
    }
    return $this->apiResponse(INVALID_TOKEN, 400, false);
}
// → {"status":200,"message":"Token is valid","success":true} or {"status":400,"message":"Invalid token","success":false}
```

---

## Bug 2: Custom Password Reset Breaks Laravel's Built-in Middleware Integration

**Severity**: LOW
**File**: `packages/marvel/src/Http/Controllers/UserController.php:762-852`
**Routes**: forget-password, reset-password

**Description**: The password reset flow uses a custom `password_resets` table implementation instead of Laravel's built-in `PasswordBroker`. This means:
- No integration with `Password::reset()` or `Password::sendResetLink()`
- No notification class reuse
- Custom OTP length (6 chars) vs Laravel's default (64-char random string)
- No built-in throttle on the password broker side (though route-level throttle exists)

**Impact**: Harder to maintain, no password broker events/broker, custom email sending.

**Suggested Fix**: Consider wrapping the custom logic with Laravel's Password facade for broker integration, or document clearly why custom logic is used (custom OTP length requirement).

---

## Bug 3: SendUserOtp Returns LOGGED_IN Message on OTP Send

**Severity**: LOW
**File**: `packages/marvel/src/Http/Controllers/UserController.php:571`
**Endpoint**: `POST /api/v1/send-otp-code` (DISABLED)

**Description**: `sendUserOtp()` returns the translation key `USER_LOGGED_IN_SUCCESSFULLY` when it successfully sends an OTP code. The message is misleading — user has not logged in, only an OTP was sent.

```php
return $this->apiResponse(USER_LOGGED_IN_SUCCESSFULLY, 200, true, $data);
```

**Impact**: Frontend reading the message string would show "User logged in successfully" after requesting an OTP code.

**Suggested Fix**: Replace with a dedicated translation key like `OTP_SENT_SUCCESSFULLY`.

---

## Bug 4: /me Route Registered Twice

**Severity**: LOW
**File**: `packages/marvel/src/Rest/Routes.php:102,134`

**Description**: `GET /api/v1/me` is registered twice (under `prefix('api/v1')`):
1. Line 102: `Route::get('me', [UserController::class, 'me'])->middleware('auth:sanctum');` → `GET /api/v1/me`
2. Line 134: `Route::get('me', [UserController::class, 'me']);` → `GET /api/v1/me` (duplicate)

The second registration (line 134) is unprotected (no auth middleware). Due to route ordering, the first `auth:sanctum` version should take precedence, but if the route registration order changes, `/api/v1/me` could become publicly accessible.

**Impact**: Potential information disclosure if route order changes.

**Suggested Fix**: Remove the duplicate at line 134. It appears to be an artifact from an older code structure.

---

## Note: Phone-only customers and password reset — not a bug

`password_resets.email` is `NOT NULL` and the `POST /api/v1/forget-password` / `POST /api/v1/verify-forget-password-token` / `POST /api/v1/reset-password` flow is email-only by design. Phone-only REST customers (`users.email = null`, valid state) cannot use email password reset; they must use an alternative recovery (e.g., phone OTP if enabled) — this is expected behavior, not a defect. `GET /api/v1/me` returning `email: null` for phone-only users (e.g., `{"status":200,"message":"User profile retrieved successfully","success":true,"data":{"id":1,"name":"...","email":null,"email_verified_at":null,"is_active":true,"image":null,"type":"user","phone_number":"010...","created_at":"...","updated_at":"...","roles":[...],"permissions":[...],"address":[]}}`) is also expected.
