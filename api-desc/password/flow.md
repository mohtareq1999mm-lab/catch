# Password — Flow Diagrams

## 1. Complete Password Reset Flow

```
[User]                     [Frontend]                   [API]                      [Database]
  │                            │                          │                           │
  │  1. Enter email            │                          │                           │
  │ ─────────────────────────> │                          │                           │
  │                            │  POST /forget-password   │                           │
  │                            │ ───────────────────────> │                           │
  │                            │                          │  SELECT * FROM users      │
  │                            │                          │  WHERE email = ?          │
  │                            │                          │ ───────────────────────> │
  │                            │                          │ <───── user found ────── │
  │                            │                          │                           │
  │                            │                          │  Str::random(6) = "A1B2C3"│
  │                            │                          │  $hash = Hash::make("A1B2C3")│
  │                            │                          │                           │
  │                            │                          │  UPSERT password_resets   │
  │                            │                          │  (email, $hash, now())    │
  │                            │                          │ ───────────────────────> │
  │                            │                          │                           │
  │                            │                          │  sendResetEmail()         │
  │                            │                          │ ──── Email with OTP ────>│
  │                            │                          │                           │
  │  <── 200 CHECK_INBOX ──── │                          │                           │
  │                            │                          │                           │
  │  2. Check email            │                          │                           │
  │  OTP: A1B2C3              │                          │                           │
  │                            │                          │                           │
  │  3. Enter OTP              │                          │                           │
  │ ─────────────────────────> │  POST /verify-otp        │                           │
  │                            │  { email, otp: "A1B2C3" }│                           │
  │                            │ ───────────────────────> │                           │
  │                            │                          │  SELECT * FROM            │
  │                            │                          │  password_resets          │
  │                            │                          │  WHERE email = ?          │
  │                            │                          │ ───────────────────────> │
  │                            │                          │ <─── return token data ── │
  │                            │                          │                           │
  │                            │                          │  Hash::check("A1B2C3", $hash)│
  │                            │                          │  ┌─────────────────────┐  │
  │                            │                          │  │ created_at + 5 min   │  │
  │                            │                          │  │ > now() ?            │  │
  │                            │                          │  └─────────────────────┘  │
  │                            │                          │                           │
  │  <── true ─────────────── │                          │                           │
  │                            │                          │                           │
  │  4. Enter new password     │                          │                           │
  │ ─────────────────────────> │  POST /reset-password    │                           │
  │                            │  { email, otp, password, │                           │
  │                            │    password_confirmation }│                           │
  │                            │ ───────────────────────> │                           │
  │                            │                          │  ┌─────────────────────┐  │
  │                            │                          │  │ DB::transaction     │  │
  │                            │                          │  │                     │  │
  │                            │                          │  │ verifyForgetPassword│  │
  │                            │                          │  │ Token() → true      │  │
  │                            │                          │  │                     │  │
  │                            │                          │  │ UPDATE users SET    │  │
  │                            │                          │  │ password = $hash    │  │
  │                            │                          │  │                     │  │
  │                            │                          │  │ DELETE FROM         │  │
  │                            │                          │  │ personal_access_    │  │
  │                            │                          │  │ tokens WHERE        │  │
  │                            │                          │  │ tokenable_id = ?    │  │
  │                            │                          │  │                     │  │
  │                            │                          │  │ DELETE FROM         │  │
  │                            │                          │  │ password_resets     │  │
  │                            │                          │  │ WHERE email = ?     │  │
  │                            │                          │  └─────────────────────┘  │
  │                            │                          │                           │
  │  <── 200 RESET_SUCCESS ─── │                          │                           │
  │                            │                          │                           │
  │  5. Login with new password│                          │                           │
```

## 2. Error Flows

### Invalid Email
```
POST /forget-password (unknown@example.com)
  → findByField('email') returns empty collection
  → count($user) < 1 → 404 NOT_FOUND
```

### Expired OTP
```
POST /verify-forget-password-token (valid otp, but 6 minutes later)
  → token found, hash matches
  → Carbon::parse(created_at)->addMinutes(5)->isPast() → true
  → returns false
```

### Invalid OTP During Reset
```
POST /reset-password (wrong OTP)
  → DB::transaction begins
  → verifyForgetPasswordToken() returns false
  → 400 INVALID_TOKEN
  → transaction rolls back automatically
```

### Too Many Requests
```
6th request to any of the 3 endpoints within 1 minute
  → throttle:sensitive (5/min) exceeded
  → 429 Too Many Attempts
```
