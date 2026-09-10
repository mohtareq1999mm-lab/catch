# OTP — Flow Diagrams

## 1. Phone OTP Login Flow

```
[User]          [Frontend]          [API]                 [OTP Gateway]
  │                  │                 │                       │
  │  Enter phone     │                 │                       │
  │ ───────────────> │                 │                       │
  │                  │ POST /send-otp  │                       │
  │                  │ { phone_number }│                       │
  │                  │ ──────────────> │                       │
  │                  │                 │ User::where(phone)    │
  │                  │                 │ ─────────────────┐    │
  │                  │                 │ <── user found ──┘    │
  │                  │                 │                       │
  │                  │                 │ getOtpGateway()        │
  │                  │                 │ → TwilioGateway        │
  │                  │                 │                       │
  │                  │                 │ startVerification()    │
  │                  │                 │ ───────────────────> │
  │                  │                 │ <── verification_sid │
  │                  │                 │                       │
  │  <── 200 {otp_id}─ │                 │                       │
  │                  │                 │                       │
  │  Enter OTP code  │                 │                       │
  │ ───────────────> │                 │                       │
  │                  │ POST /otp-login │                       │
  │                  │ { phone_number, │                       │
  │                  │   otp_id, code }│                       │
  │                  │ ──────────────> │                       │
  │                  │                 │ verifyOtp()            │
  │                  │                 │ checkVerification()    │
  │                  │                 │ ───────────────────> │
  │                  │                 │ <── isValid() ─────── │
  │                  │                 │                       │
  │                  │                 │ User::where(phone)     │
  │                  │                 │ createToken()          │
  │                  │                 │                       │
  │  <── 200 {token} ─│                 │                       │
```

## 2. Email OTP Login Flow

```
[User]          [Frontend]          [API]
  │                  │                 │
  │  Enter email     │                 │
  │ ───────────────> │                 │
  │                  │ POST /send-otp  │
  │                  │ { email }       │
  │                  │ ──────────────> │
  │                  │                 │ User::where(email)
  │                  │                 │ sendOneTimePassword()
  │                  │                 │ → Email Notification
  │  <── Email with OTP                 │
  │                  │                 │
  │  Enter OTP       │                 │
  │ ───────────────> │ POST /otp-login │
  │                  │ { email, otp }  │
  │                  │ ──────────────> │
  │                  │                 │ verifyLoginOtp()
  │                  │                 │ validateOneTimePassword()
  │                  │                 │ createToken()
  │  <── 200 {token} ─│                 │
```

## 3. Gateway Resolution

```
getOtpGateway()
  │
  ├─ config('auth.active_otp_gateway') = 'twilio'
  │
  ├─ Class: Marvel\Otp\Gateways\TwilioGateway
  │  ├─ Instantiate → return OtpGateway(new TwilioGateway())
  │
  └─ On exception:
     └─ Log::warning('OTP gateway unavailable')
        └─ Return OtpGateway(new LocalGateway())
```

## 4. Error Flows

### User Not Found (send-otp-code)
```
POST /send-otp-code (unknown phone)
  → User::where('phone_number', $phone)->where('is_active', true)->first()
  → null
  → 404 "User not found"
```

### OTP Verification Failed (otp-login)
```
POST /otp-login (wrong code)
  → verifyOtp() → checkVerification() → !isValid()
  → 400 "OTP verification failed"
```

### Gateway Unavailable (otp-login)
```
POST /otp-login
  → verifyOtp() → Exception from gateway
  → Catch → return false
  → 400 "OTP verification failed"
```
