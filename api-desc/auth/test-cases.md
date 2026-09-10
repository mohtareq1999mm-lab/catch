# Auth — Test Cases

## Setup
```php
use Tests\TestCase;
use Marvel\Database\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
```

## Registration

### Success Cases — REST: `phone_number` required, `email` optional (`nullable|sometimes|email|unique:users,email|email:rfc,dns` — `packages/marvel/src/Http/Requests/UserCreateRequest.php:30-38`); `email = null` valid; admin/GraphQL email-required
1. **Register a new customer (with email)** — POST /api/v1/register with valid data (including `email` + `phone_number`) → 200, user created in DB, role 'customer' assigned, `personal_access_tokens` not created
1a. **Register phone-only (email omitted)** — POST /api/v1/register with `first_name, last_name, phone_number, password, password_confirmation, policy` and no `email` → 200, `users.email = null` (valid, not error), `otp_status: true`, no OTP email (guard `if ($user->email)` — `UserController.php:668-686`)
1b. **Register phone-only (email: null)** — POST /api/v1/register with `email: null` → 200, same as 1a (`users.email = null`)
1c. **Register with avatar (phone-only)** — POST /api/v1/register phone-only with valid image → 200, media record created
2. **Register with avatar (with email)** — POST /api/v1/register with valid image file → 200, media record created in `media` table with collection_name='avatar'
3. **Register without avatar** — POST /api/v1/register without avatar file → 200, no media record

### Validation Failures — `email` only validated when present (`nullable|sometimes`); `phone_number` always required
4. **Missing first_name** → 422
5. **Missing email (omitted)** → 200 — not a failure; phone-only valid (`email: nullable|sometimes`)
5a. **email: null** → 200 — valid phone-only
6. **Invalid email format (when supplied, no @)** → 422
7. **Duplicate email (when supplied)** → 422 — `unique:users,email`
7a. **Duplicate phone_number** → 422 — `unique:users,phone_number`
8. **Password too short (< 8)** → 422
9. **Password confirmation mismatch** → 422
10. **Policy not accepted** → 422
11. **Missing phone_number** → 422 — `phone_number: required` for REST

### Business Rules — `email = null` is a valid customer state, not unverified/error
12. **New user type is 'user'** → assert DatabaseHas with type='user'
13. **New user is_active is true** → assert DatabaseHas with is_active=1
14. **Customer role assigned** → assert user has role 'customer'
15. **OTP email sent (when email present)** → assert Notification::assertSentTo — only when `user.email` truthy; phone-only (`email = null`) returns `{"status":200,"message":"User registered successfully","success":true,"data":{"otp_status":true}}` with no email side effect (guard `if ($user->email)` — `UserController.php:668-686`)
15a. **Phone-only: no OTP email** → assert Notification::assertNotSentTo for phone-only user; `users.email = null` valid
15b. **Phone-only: `GET /api/v1/me` returns `email: null`, `email_verified_at: null`** → valid state, not error

## Login (token) — `POST /api/v1/token` supports `email + password` **or** `phone_number + password` (`required_without` — `UserAuthEmailAndPasswordRequest`); phone is primary for `email = null` customers

### Success Cases
16. **Login with valid email/password** → POST /api/v1/token `{ email, password }` → `{"status":200,"message":"User logged in successfully","success":true,"data":{"token":"1|...","email_verified":bool,"permissions":[...],"role":[...],"expires_at":"..."}}` — note `data.token`
17. **Login with valid phone_number/password** → POST /api/v1/token `{ phone_number, password }` → `{"status":200,"message":"User logged in successfully","success":true,"data":{"token":"1|...","email_verified":false,"permissions":[...],"role":[...],"expires_at":"..."}}` — phone-only login
17a. **Login phone-only customer** → `users.email = null` customer login with `phone_number + password` → `{"status":200,"message":"User logged in successfully","success":true,"data":{"token":"1|...","email_verified":false,"permissions":[...],"role":[...],"expires_at":"..."}}`, `email = null` is valid not an error
18. **AdminLoggedIn event dispatched** → Event::assertDispatched(AdminLoggedIn::class)

### Failure Cases
19. **Invalid password** → POST /api/v1/token with wrong password → 404 'Invalid credentials'
20. **Non-existent email** → POST /api/v1/token with unknown email → 404 'Invalid credentials'
20a. **Non-existent phone_number** → POST /api/v1/token with unknown phone → 404 'Invalid credentials'
21. **Inactive user** → POST /api/v1/token with is_active=false user → 404 'Invalid credentials'
21a. **Missing both email and phone_number** → POST /api/v1/token with only password → 422 — `required_without`

## Admin Login — email required (unchanged; admin/GraphQL remain email-required)

### Success Cases
22. **Admin login with verified email** → POST /api/v1/admin-login → 200, includes permissions and role in response

### Failure Cases
23. **Customer tries admin login** → POST /api/v1/admin-login with customer credentials → 404 'User not found'
24. **Admin without verified email** → POST /api/v1/admin-login with unverified admin → 404 'User not verified'

## Social Login

### Success Cases
25. **Social login with valid Google token (mock)** → POST /api/v1/social-login-token → 200, user created if new
26. **Existing user social login** → user exists with same email → 200, no duplicate

### Failure Cases
27. **Invalid provider** → POST /api/v1/social-login-token with provider='twitter' → exception
28. **Invalid access token** → POST /api/v1/social-login-token with fake token → 422

## Get Current User — `email` is `string | null`; `email = null` is valid phone-only state, not error; `email_verified_at: null` for phone-only

### Success Cases
29. **Get authenticated user profile (with email)** → GET /api/v1/me with valid token → `{"status":200,"message":"User profile retrieved successfully","success":true,"data":{"id":1,"name":"...","email":"john@example.com","email_verified_at":"...","is_active":true,"image":null,"type":"user","phone_number":"...","created_at":"...","updated_at":"...","roles":[...],"permissions":[...],"address":[]}}` — `email: string`
29a. **Get authenticated user profile (phone-only)** → GET /api/v1/me for `users.email = null` user → `{"status":200,"message":"User profile retrieved successfully","success":true,"data":{"id":1,"name":"...","email":null,"email_verified_at":null,"is_active":true,"image":null,"type":"user","phone_number":"...","created_at":"...","updated_at":"...","roles":[...],"permissions":[...],"address":[]}}` — `email: null`, `email_verified_at: null` — valid, not unverified/error
30. **Response includes role** → assert response has 'role' field
31. **Response includes wallet** → assert response has 'wallet' field (if wallet exists)

### Failure Cases
32. **No token** → GET /api/v1/me without Bearer token → 401
33. **Invalid token** → GET /api/v1/me with fake token → 401

## Logout

### Success Cases
34. **Logout with valid token** → POST /api/v1/logout → 200, token deleted from DB

### Failure Cases
35. **Logout without auth** → POST /api/v1/logout without token → 401

## Forget Password — email-only (phone-only `email = null` cannot use; `password_resets.email` NOT NULL, email-only by design)

### Success Cases
36. **Request password reset (existing email)** → POST /api/v1/forget-password with existing email → 200
36a. **Request password reset (non-existent email)** → POST /api/v1/forget-password → 200 (no email enumeration) — does not disclose existence
37. **Password reset token created** → assert password_resets table has record (only when email exists)
37a. **Phone-only user cannot request reset** → POST /api/v1/forget-password with `email: null` / no email or phone-only account → 200 (no enumeration) but no `password_resets` record; cannot use email flow by design

### Failure Cases
38. **Non-existent email now returns 200** → POST /api/v1/forget-password → 200, not 404 (enumeration protection; was 404 before fix)
39. **Too many requests** → exceed throttle:sensitive (6th request) → 429

## Verify Forget Password Token — email-only (requires `email` + `otp`; phone-only `email = null` cannot use — `password_resets.email` NOT NULL)

### Success Cases
40. **Valid OTP** → POST /api/v1/verify-forget-password-token with correct email + OTP → `{"status":200,"message":"Token is valid","success":true}`

### Failure Cases
41. **Invalid OTP** → POST /api/v1/verify-forget-password-token with wrong OTP → `{"status":400,"message":"Invalid token","success":false}`
42. **Expired OTP** → travel 61 minutes (config `auth.passwords.users.expire` 60) → `{"status":400,"message":"Invalid token","success":false}`
43. **No token for email** → random email → `{"status":400,"message":"Invalid token","success":false}`
43a. **Phone-only (email null) cannot verify** → POST with `email: null` → 422 or `success: false` — email-only by design

## Reset Password — email-only (`password_resets.email` NOT NULL; phone-only `email = null` cannot use)

### Success Cases
44. **Reset password with valid OTP** → POST /api/v1/reset-password `{ email, otp, password, password_confirmation }` → `{"status":200,"message":"Password reset successfully","success":true}`, password updated
45. **All tokens deleted after reset** → assert personal_access_tokens count = 0
46. **password_resets cleaned up** → assert record deleted
46a. **Phone-only user cannot reset via email** → POST /api/v1/reset-password with `email: null` or phone-only account → fails (no `password_resets` record; email-only by design)

### Failure Cases
47. **Invalid OTP** → POST /api/v1/reset-password with wrong OTP → `{"status":400,"message":"Invalid token","success":false}`
48. **Password too short** → POST /api/v1/reset-password with 6-char password → 422
49. **Password confirmation mismatch** → 422
50. **Missing fields** → 422

## Rate Limiting

### throttle:auth
51. **11th register request in 1 minute** → 429
52. **11th token request in 1 minute** → 429
53. **11th admin-login request in 1 minute** → 429
54. **11th social-login request in 1 minute** → 429

### throttle:sensitive
55. **6th forget-password request in 1 minute** → 429

### Logout (no throttle)
56. **Rapid logout requests** → all succeed (no 429)

## Edge Cases — `email = null` is valid phone-only state, not error; `POST /api/v1/token` supports phone_number + password
57. **Register with existing email (soft-deleted user)** → 422 unique constraint — `unique:users,email`
57a. **Register phone-only then try duplicate phone** → 422 `unique:users,phone_number`
58. **Login with email that exists but inactive** → 404
58a. **Login phone-only with phone that exists but inactive** → 404
59. **Password reset for user with multiple tokens** → all tokens deleted
59a. **Password reset for phone-only user** → cannot use email flow (`password_resets.email` NOT NULL, email-only by design) — expected 200 no record
60. **Register and immediately login (phone-only)** → register with `email: null` then `POST /api/v1/token { phone_number, password }` → 200; verify `GET /api/v1/me` returns `email: null`
61. **Social login creates user with Hash::make('password')** — verify user can NOT log in with 'password' via token endpoint (password is a fallback only)
62. **`email = null` not unverified** → phone-only `GET /api/v1/me` `email: null`, `email_verified_at: null` is valid, not an error — frontend must not show "missing email" warning
