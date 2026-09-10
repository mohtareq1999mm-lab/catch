# Password — Bug Reports

## Bug 1: Verify-Forget-Password-Token Returns Raw Boolean Instead of JSON

**Severity**: LOW
**File**: `packages/marvel/src/Http/Controllers/UserController.php:798-821`
**Endpoint**: `POST /api/v1/verify-forget-password-token`

**Description**: The `verifyForgetPasswordToken()` method returns a raw PHP `true`/`false` value instead of a structured JSON API response. All other endpoints use `$this->apiResponse()`. This inconsistency breaks the frontend if it expects a standard `{ success: true, data: boolean }` envelope.

**Root Cause**: Method returns `return true;` / `return false;` instead of `return response()->json(...)`.

**Impact**: Frontend must handle a bare `true`/`false` response body with Content-Type `text/html` (Laravel default for boolean return), not `application/json`.

**Reproduction**:
1. POST /api/v1/verify-forget-password-token with `{ email: "test@test.com", otp: "ABC123" }`
2. Response body is `true` (raw, not JSON)
3. POST with invalid OTP → response body is empty (false returns nothing)

**Suggested Fix**:
```php
public function verifyForgetPasswordToken(Request $request)
{
    $tokenData = DB::table('password_resets')
        ->where('email', $request->email)
        ->first();

    if (!$tokenData || !Hash::check($request->otp, $tokenData->token) || Carbon::parse($tokenData->created_at)->addMinutes(5)->isPast()) {
        return response()->json(['success' => true, 'data' => false]);
    }

    return response()->json(['success' => true, 'data' => true]);
}
```

---

## Bug 2: No Input Validation on forgetPassword Email Field

**Severity**: LOW
**File**: `packages/marvel/src/Http/Controllers/UserController.php:762-794`
**Endpoint**: `POST /api/v1/forget-password`

**Description**: The `forgetPassword()` method does not validate that `$request->email` is present. If a client sends an empty JSON body or omits the email field, `$request->email` returns `null`, which is passed to `findByField('email', null)`. This may cause unexpected SQL behavior or a 500 error depending on the repository implementation.

**Reproduction**:
1. POST /api/v1/forget-password with `{}` (empty body)
2. May return 500 error or unexpected results

**Suggested Fix**: Add validation:
```php
$request->validate(['email' => 'required|email']);
```

---

## Bug 3: Custom Password Reset Bypasses Laravel Password Broker

**Severity**: LOW
**File**: `packages/marvel/src/Http/Controllers/UserController.php:762-852`
**Routes**: All 3 password reset endpoints

**Description**: The implementation uses raw `DB::table('password_resets')` queries and manual hash/expiry checks instead of Laravel's built-in `PasswordBroker`. This means:
- No `PasswordReset` events are fired
- No integration with mail notification system
- Cannot use `Password::sendResetLink()` or `Password::reset()` helpers
- No built-in throttle (relies entirely on route middleware)
- Different token format (6-char vs Laravel's 64-char random)

**Impact**: Harder to maintain, no event hooks for auditing, no broker customization.

**Suggested Fix**: Either wrap with Laravel's Password facade or document as intentional design for shorter OTP requirement.
