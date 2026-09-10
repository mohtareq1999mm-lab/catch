# Password — Test Cases

## Setup
```php
use Tests\TestCase;
use Marvel\Database\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
```

## Forget Password

### Success Cases
1. **Request reset for existing user** — POST /forget-password with valid email → 200
2. **Password reset token created** — assert `password_resets` table has record for email
3. **Token is hashed** — assert token in DB is not plaintext
4. **Email sent** — assert `UserRepository::sendResetEmail()` was called
5. **Existing token is updated** — request reset twice, assert single record in `password_resets`

### Failure Cases
6. **Non-existent email** → 404
7. **Missing email field** → 500 (no validation — crashes on `$request->email`)
8. **Too many requests** — 6th request → 429

## Verify Forget Password Token

### Success Cases
9. **Valid OTP** — POST with correct email + OTP → `true`
10. **Response is boolean true** — assert `$response->content() === 'true'`

### Failure Cases
11. **Invalid OTP** — wrong characters → `false`
12. **Expired OTP** — travel 6 minutes forward → `false`
13. **Non-existent email** — no password_resets record → `false`
14. **Empty OTP** → `false`
15. **Null email** → `false`

## Reset Password

### Success Cases
16. **Reset with valid OTP** — POST /reset-password → 200
17. **Password updated** — assert `Hash::check($newPassword, $user->password)` is true
18. **All tokens deleted** — assert `personal_access_tokens` count = 0 for user
19. **password_resets cleaned up** — assert no record for email
20. **Can login with new password** — POST /token with new password → 200

### Validation Failures
21. **Password too short (< 8)** → 422
22. **Password too long (> 50)** → 422
23. **Password confirmation mismatch** → 422
24. **Missing email** → 422
25. **Missing OTP** → 422
26. **Missing password** → 422
27. **Invalid email format** → 422

### Business Rule Failures
28. **Invalid OTP** → 400 "Invalid token"
29. **Expired OTP** → 400 "Invalid token"
30. **OTP for different email** → 400 "Invalid token"
31. **Password same as old password** → 200 (allowed, no history check)

## Rate Limiting

### throttle:sensitive (5/min per IP)
32. **6th forget-password request** → 429
33. **6th verify-forget-password-token request** → 429
34. **6th reset-password request** → 429
35. **Mix of all 3 endpoints** — 6 total requests → 429
36. **Wait 1 minute after 429** → requests succeed again

## Edge Cases
37. **User with multiple active tokens** — all deleted on password reset
38. **Reset password twice with same OTP** — second attempt fails (OTP deleted after first use)
39. **OTP with special characters** — Str::random(6) is alphanumeric, verify hash works
40. **Concurrent reset requests** — DB transaction prevents race conditions
41. **Register, immediately reset password** — OTP from registration != password reset OTP (different systems)
