# OTP — Test Cases

## Setup
```php
use Tests\TestCase;
use Marvel\Database\Models\User;
use Illuminate\Support\Facades\Notification;
use Mockery;
```

## Send OTP Code

### Success Cases (Phone)
1. **Send OTP to valid phone** — POST /send-otp-code with existing phone → 200
2. **Response contains otp_id** — assert response has `data.otp_id`
3. **Response contains provider name** — assert `data.provider` matches configured gateway
4. **Response contains is_contact_exist** — assert `data.is_contact_exist` is true for existing user

### Success Cases (Email)
5. **Send OTP to valid email** — POST /send-otp-code with existing email → 200
6. **OTP email sent via notification** — Notification::assertSentTo($user)
7. **Response for email OTP** — assert `data.otp_id` is present (integer, the OneTimePassword model ID)

### Validation Failures
8. **Missing email AND phone_number** → 422
9. **Invalid email format** → 422
10. **Phone too short (< 11)** → 422
11. **Phone too long (> 15)** → 422

### Failure Cases
12. **Phone not found** → POST with unknown phone → 404
13. **Email not found** → POST with unknown email → 404
14. **Inactive user** → POST for inactive user by phone → 404
15. **Inactive user** → POST for inactive user by email → 404
16. **Gateway unavailable** → mock gateway to throw → 422 (from sendOtpCode catch)

## OTP Login

### Success Cases (Phone)
17. **Login with valid phone OTP** — POST /otp-login with valid phone + code → 200
18. **Token returned** — assert response has `data.token`
19. **Sanctum token created** — assert personal_access_tokens has record

### Success Cases (Email)
20. **Login with valid email OTP** — POST /otp-login with valid email + otp → 200

### Failure Cases (Phone)
21. **Invalid OTP code** → POST /otp-login with wrong code → 400
22. **User not found by phone** → POST with unknown phone → 404
23. **Missing otp_id** → POST /otp-login without otp_id → 400 (verifyOtp returns false)

### Failure Cases (Email)
24. **Invalid email OTP** → POST /otp-login with wrong otp → exception → 422
25. **Missing email** → 422 (validation in verifyLoginOtp)

### Gateway Errors
26. **Gateway exception during verify** → 400 OTP_VERIFICATION_FAILED
27. **Gateway exception during login** → 422 INVALID_GATEWAY

## Queue Behavior

| # | Test | Expected |
|---|------|----------|
| 33a | **OTP notification queued** — send OTP for email → assert job in `jobs` table | `queue=high` |
| 33b | **OTP email logged on queue work** — run queue worker → assert email in `laravel.log` | log contains OTP code |
| 33c | **API returns before queue processes** — send OTP → response returned immediately | 200 returned, `data.otp_id` present, no email body in response |

## Rate Limiting

### throttle:otp (3/min per IP)
28. **4th send-otp-code request** → 429
29. **4th otp-login request** → 429
30. **Mix of both endpoints** — 4 total requests → 429
31. **Wait 1 minute after 429** → requests succeed again

## Gateway Fallback
32. **Configured gateway fails** → falls back to LocalGateway
33. **LocalGateway verification** — returns true for any code (dev behavior)
34. **Wrong active_otp_gateway config** → logs warning, uses LocalGateway

## Edge Cases
35. **Send OTP then immediately login** — OTP should work
36. **Login with expired OTP** — depends on gateway expiry policy (not controlled by app)
37. **Multiple OTP sends for same number** — last otp_id is valid
38. **Phone with country code prefix variations** — exact match required
39. **Email OTP case sensitivity** — email lookup is case-insensitive or sensitive depending on DB collation
40. **Concurrent OTP requests** — no race condition (gateway manages state)
