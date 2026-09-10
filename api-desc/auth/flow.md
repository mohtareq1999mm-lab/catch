# Auth — Flow Diagrams

## 1. Registration Flow — REST: `phone_number` required, `email` optional (`nullable|sometimes|email|unique:users,email|email:rfc,dns`). `email = null` is a valid customer state (not unverified/error).

```
[Client] → POST /api/v1/register (first_name*, last_name*, phone_number*, password*, email? nullable)
  │  e.g. phone-only: { first_name, last_name, phone_number, password, password_confirmation, policy }
  │  e.g. with email: { first_name, last_name, phone_number, email, password, password_confirmation, policy }
  │
  ├─ throttle:auth (10/min per IP)
  │
  ▼
[UserCreateRequest] → validate
  │  email: nullable|sometimes|email|unique:users,email|email:rfc,dns
  │  phone_number: required|string|max:20|min:10|unique:users,phone_number
  │  (Admin/GraphQL remain email-required — REST only)
  │
  ▼
[UserRepository::create()] → INSERT users (type='user', is_active=true, email may be null)
  │
  ▼
[assignRole('customer')] → INSERT model_has_roles
  │
  ▼
[Media upload] → avatar → INSERT media (if file present)
  │
  ▼
[if user.email] → [sendOneTimePassword()] → Send OTP email
│                    ├─ Success → {"status":200,"message":"User registered successfully","success":true,"data":{"otp_status":true}}
│                    └─ Fail    → {"status":201,"message":"Account created but OTP failed","success":true,"data":{"requires_resend":true,"otp_status":false,"email":"...","phone_number":"..."}}
└─ [else (email = null)] → {"status":200,"message":"User registered successfully","success":true,"data":{"otp_status":true}}  // no email side effect; phone-only customer valid
```

## 2. Login Flow — REST supports `phone_number + password` (UserAuthEmailAndPasswordRequest)

```
[Client] → POST /api/v1/token (email|phone_number, password)
│  Body: { email, password } OR { phone_number, password }
│  Validation: email required_without:phone_number|email
│              phone_number required_without:email|string|max:15|min:8
│  Example phone-only: POST /api/v1/token { "phone_number": "+2010xxxxxxx", "password": "secret123" }
  │
  ├─ throttle:auth (10/min per IP)
  │
  ▼
[User] = WHERE email=$email OR phone_number=$phone AND is_active=true
  │
  ├─ !found or !Hash::check → 404 INVALID_CREDENTIALS
  │
  ▼
[createToken('auth_token')] → INSERT personal_access_tokens
  │
  ▼
[dispatch AdminLoggedIn]
  │
  ▼
{"status":200,"message":"User logged in successfully","success":true,"data":{"token":"1|...","email_verified":bool,"permissions":[...],"role":[...],"expires_at":"..."}}  // email_verified false when email is null — `email=null` is valid phone-only state, not an error
```

## 3. Admin Login Flow — email required (Admin/GraphQL unchanged)

```
[Client] → POST /api/v1/admin-login (email, password)  // email required — admin remains email-required
  │
  ├─ throttle:auth (10/min per IP)
  │
  ▼
[User] = WHERE email=$email AND is_active=true
  │
  ├─ !found or !Hash::check → 404 INVALID_CREDENTIALS
  ├─ type !== 'admin' → 404 USER_NOT_FOUND
  ├─ !hasVerifiedEmail() → 404 USER_NOT_VERIFIED
  │
  ▼
[createToken('auth_token')] → INSERT personal_access_tokens
  │
  ▼
[dispatch AdminLoggedIn]
  │
  ▼
{"status":200,"message":"User logged in successfully","success":true,"data":{"token":"1|...","permissions":[...],"email_verified":true,"role":[...],"expires_at":"..."}}
```

## 4. Password Reset Flow

```
[Client] → POST /api/v1/forget-password (email)
  │
  ├─ throttle:sensitive (5/min per IP)
  │
  ▼
[User] = findByField('email', $email)
  │
  ├─ count < 1 → {"status":200,"message":"Check your inbox for password reset email","success":true} (no enumeration — always 200)
  │
  ▼
[password_resets] → upsert { email, token: Hash::make(6-char OTP) }
  │
  ▼
[sendResetEmail($email, $plainTextToken)] → Send email with 6-char OTP (queued on high)
  │
  ├─ true  → {"status":200,"message":"Check your inbox for password reset email","success":true}
  └─ false → {"status":500,"message":"Something went wrong","success":false}
```

```
[Client] → POST /api/v1/verify-forget-password-token (email, otp)
  │
  ├─ throttle:sensitive
  │
  ▼
[password_resets] = WHERE email=$email
  │
  ├─ !found → {"status":400,"message":"Invalid token","success":false}
  ├─ !Hash::check(otp, token) → {"status":400,"message":"Invalid token","success":false}
  ├─ created_at + config('auth.passwords.users.expire',60) < now() → {"status":400,"message":"Invalid token","success":false}
  │
  ▼
{"status":200,"message":"Token is valid","success":true}
```

```
[Client] → POST /api/v1/reset-password (email, otp, password, password_confirmation)
  │
  ├─ throttle:sensitive
  │
  ▼
[validate] // outside try/catch — 422 on validation failure
  │
  ▼
[verifyForgetPasswordToken()]
  │
  ├─ false → {"status":400,"message":"Invalid token","success":false}
  │
  ▼
[DB::transaction]
  ├─ UPDATE users SET password = Hash::make($password)
  ├─ DELETE personal_access_tokens WHERE tokenable_id = $user->id
  └─ DELETE password_resets WHERE email = $email
  │
  ▼
{"status":200,"message":"Password reset successfully","success":true}
```
```

## 5. Social Login Flow

```
[Client] → POST /api/v1/social-login-token (provider, access_token)
  │
  ├─ throttle:auth
  │
  ▼
[validateProvider] → provider in ['facebook', 'google']
  │
  ├─ invalid → 422 PLEASE_LOGIN_USING_FACEBOOK_OR_GOOGLE
  │
  ▼
[Socialite::driver($provider)->userFromToken($token)]
  │
  ├─ Exception → 422 INVALID_CREDENTIALS
  │
  ▼
[User::firstOrCreate(['email' => $email], [...])]
  │
  ▼
[user_providers::updateOrCreate(...)]
  │
  ▼
[createToken('auth_token')] → INSERT personal_access_tokens
  │
  ▼
{"status":200,"message":"User logged in successfully","success":true,"data":{"token":"1|..."}}
```

## 6. Phone-only REST Flows (no email)

```
[Client] → POST /api/v1/register { first_name, last_name, phone_number, password, password_confirmation, policy }
  → [UserCreateRequest] email omitted/null → passes (nullable|sometimes)
  → INSERT users { email: null, phone_number: "+2010..." }
  → if (!user.email) skip sendOneTimePassword()
  → {"status":200,"message":"User registered successfully","success":true,"data":{"otp_status":true}}  // phone-only customer created; email=null is valid state

[Client] → POST /api/v1/token { phone_number, password }
  → lookup WHERE phone_number=$phone AND is_active=true
  → {"status":200,"message":"User logged in successfully","success":true,"data":{"token":"1|...","email_verified":false,"permissions":[...],"role":[...],"expires_at":"..."}}  // not an error — customer has no email

[Client] → GET /api/v1/me (Bearer token for phone-only user)
  → {"status":200,"message":"User profile retrieved successfully","success":true,"data":{"id":1,"name":"...","email":null,"email_verified_at":null,"is_active":true,"image":null,"type":"user","phone_number":"+2010...","created_at":"...","updated_at":"...","roles":[...],"permissions":[...],"address":[]}}
  // frontend must treat email:null as valid customer, not unverified/error
  // Admin/GraphQL flows remain email-required — this phone-only path is REST only
```
