# OTP — Bug Reports

## Bug 1: sendUserOtp Returns LOGGED_IN Translation Key

**Severity**: LOW
**File**: `packages/marvel/src/Http/Controllers/UserController.php:571`
**Endpoint**: `POST /api/v1/send-otp-code` (DISABLED)

**Description**: `sendUserOtp()` returns the translation key `USER_LOGGED_IN_SUCCESSFULLY` when it successfully sends an OTP code. The message is misleading — user has not logged in, only an OTP was sent.

```php
return $this->apiResponse(USER_LOGGED_IN_SUCCESSFULLY, 200, true, $data);
```

**Impact**: Frontend reading the message string would show "User logged in successfully" after requesting an OTP code, confusing users.

**Suggested Fix**: Replace with a dedicated translation key like `OTP_SENT_SUCCESSFULLY`.

---

## Bug 2: sendOtpCode Returns Static OTP in Comment

**Severity**: LOW
**File**: `packages/marvel/src/Http/Controllers/UserController.php:1067`
**Method**: `sendOtpCode()`

**Description**: The method has a commented-out line returning a static OTP for testing:
```php
// 'otp' => '123456',
```

While this is commented, the fact that it's present suggests at some point the OTP was returned in plaintext in the response. Even as a comment, it represents a security risk if uncommented for debugging and committed.

**Impact**: If uncommented, any user's OTP would be exposed in the API response.

**Suggested Fix**: Remove the commented line entirely, or wrap in a dev-only condition:
```php
if (app()->environment('local')) {
    $data['otp'] = '123456';
}
```

---

## Bug 3: sendUserOtp Returns Static OTP for Email Path

**Severity**: LOW
**File**: `packages/marvel/src/Http/Controllers/UserController.php:562`
**Method**: `sendUserOtp()`

**Description**: For the email path, the method returns a hardcoded OTP in the response:
```php
$data = ['otp' => '123456'];
```

This means any client calling `/send-otp-code` with an email gets the actual OTP value returned in the response body. While this may be intended for development, it defeats the purpose of OTP security in production.

**Impact**: Anyone who can call the API endpoint can see the OTP in the response, bypassing the need to access the user's email inbox.

**Suggested Fix**:
```php
// For email, the OTP is sent via email. Do not return it in response.
if ($request->email) {
    $user->sendOneTimePassword();
    // Remove the hardcoded OTP from response
} else {
    // Phone OTP — return otp_id for verification step
    $otpResponse = $this->sendOtpCode($request);
    if (is_array($otpResponse)) {
        $data['otp_id'] = $otpResponse['otp_id'] ?? null;
    }
}
```

---

## Bug 4: Missing Translation Key for OTP_SEND_FAIL / OTP_SEND_SUCCESSFUL

**Severity**: LOW
**File**: `packages/marvel/src/Http/Controllers/UserController.php:1058,1063`
**Constants**: `OTP_SEND_FAIL`, `OTP_SEND_SUCCESSFUL`

**Description**: The `sendOtpCode()` method uses translation constants `OTP_SEND_FAIL` and `OTP_SEND_SUCCESSFUL`. These may not have corresponding translation entries in all language files.

**Impact**: If the translation key is missing, the API returns the key name instead of a human-readable message.

**Suggested Fix**: Verify all language files (`lang/en/*.php`, `lang/ar/*.php`, etc.) have entries for these keys.
