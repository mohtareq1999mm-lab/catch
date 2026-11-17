# Authentication Routes API Guide

**Audience:** Mobile developers  
**API version:** v1  
**Scope:** The authentication routes registered under `throttle:login`, `throttle:sensitive`, and `throttle:otp`

> `GET /me` appears only as a comment in the route file and is not part of this guide.

**Common caveat:** `throttle:login`, `throttle:sensitive`, and `throttle:otp` are attached to the routes, but their named rate limiters are not registered and rate limiting is bypassed by default. `429` examples apply only when throttling is enabled.

---

# 1. Register

### 1. Endpoint Information

- **Endpoint Name:** User Registration
- **Method & URL:** `POST /api/v1/register`
- **Purpose:** Creates a customer account. It does not authenticate the user or return a token.

### 2. Request

**Headers**

```http
Accept: application/json
Content-Type: application/json
lang: en
```

Use `multipart/form-data` only when uploading `avatar`.

**Body**

| Field | Type | Required | Rules |
|---|---|---|---|
| `first_name` | string | Yes | 2–50 characters |
| `last_name` | string | Yes | 2–50 characters |
| `email` | string | No | Valid email; RFC/DNS; unique |
| `phone_number` | string | Yes | 10–20 characters; unique |
| `password` | string | Yes | 8–50 characters; confirmed |
| `password_confirmation` | string | Yes | 8–50 characters |
| `policy` | boolean/`1` | Yes | Accepted: `1`, `"1"`, `true` |
| `avatar` | file | No | Image; max 2048 KB; multipart |

```json
{
  "first_name": "Mobile",
  "last_name": "User",
  "email": "mobile.user@gmail.com",
  "phone_number": "01000000002",
  "password": "Password123!",
  "password_confirmation": "Password123!",
  "policy": "1"
}
```

### 3. Response

**Success — 200**

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

**Account created but OTP delivery failed — 201**

```json
{
  "status": 201,
  "message": "Account created successfully but failed to send verification code",
  "success": true,
  "data": {
    "requires_resend": true,
    "email": "mobile.user@gmail.com",
    "phone_number": "01000000002",
    "otp_status": false
  }
}
```

**Validation error — 422**

```json
{
  "message": "The given data was invalid.",
  "status": false,
  "success": false,
  "errors": {
    "email": [
      "The email has already been taken."
    ]
  }
}
```

**Server error — 500**

```json
{
  "status": 500,
  "message": "Something went wrong.",
  "success": false
}
```

**Status codes**

- `200` — account created.
- `201` — account created but OTP failed.
- `422` — validation failure.
- `429` — rate limit, if enabled.
- `500` — database/server failure.

### 4. What’s Next?

Call `POST /api/v1/token` with the same email or phone number and password. Registration does **not** return a token.

### 5. Open

- `201` is still a success and the account exists.
- Phone-only registration sends no email OTP but still reports `otp_status: true`.
- No customer role is assigned by registration.

---

# 2. Normal Login

### 1. Endpoint Information

- **Endpoint Name:** Customer Login
- **Method & URL:** `POST /api/v1/token`
- **Purpose:** Authenticates an active customer and returns a Sanctum Bearer token.

### 2. Request

**Headers**

```http
Accept: application/json
Content-Type: application/json
lang: en
```

**Body**

| Field | Type | Required | Rules |
|---|---|---|---|
| `email` | string | Conditional | Required when phone is absent; valid email |
| `phone_number` | string | Conditional | Required when email is absent; 8–15 characters |
| `password` | string | Yes | Minimum 6 characters |

```json
{
  "email": "mobile.user@gmail.com",
  "password": "Password123!"
}
```

Phone login:

```json
{
  "phone_number": "01000000002",
  "password": "Password123!"
}
```

### 3. Response

**Success — 200**

```json
{
  "status": 200,
  "message": "User logged in successfully",
  "success": true,
  "data": {
    "token": "<redacted-token>",
    "email_verified": false,
    "permissions": [],
    "role": [],
    "expires_at": "2026-12-04T11:08:29.000000Z"
  }
}
```

**Invalid credentials / inactive / non-customer — 404**

```json
{
  "status": 404,
  "message": "Invalid credentials",
  "success": false
}
```

**Validation error — 422**

```json
{
  "message": "The given data was invalid.",
  "status": false,
  "success": false,
  "errors": {
    "email": [
      "The email field is required when phone number is not present."
    ],
    "password": [
      "The password field is required."
    ]
  }
}
```

**Status codes**

- `200` — success.
- `404` — invalid credentials, inactive account, or non-customer account.
- `422` — validation failure.
- `429` — rate limit, if enabled.
- `500` — server failure.

### 4. What’s Next?

Store the token securely and send it as `Authorization: Bearer <token>` on protected requests.

### 5. Open

- `404` means invalid credentials, not a missing route.
- Email verification is not required to log in.
- Token lifetime is 14 business days; use `data.expires_at`.
- Registration allows phone lengths 10–20, while login allows 8–15.

---

# 3. Admin Login

### 1. Endpoint Information

- **Endpoint Name:** Admin Login
- **Method & URL:** `POST /api/v1/admin-login`
- **Purpose:** Authenticates an active, verified administrator and returns a 7-day token.

### 2. Request

**Headers**

```http
Accept: application/json
Content-Type: application/json
lang: en
```

**Body**

| Field | Type | Required | Rules |
|---|---|---|---|
| `email` | string | Yes in practice | Controller looks up email only |
| `phone_number` | string | No | Accepted by validator but ignored |
| `password` | string | Yes | Minimum 6 characters |

```json
{
  "email": "admin@example.com",
  "password": "AdminPass123!"
}
```

### 3. Response

**Success — 200**

```json
{
  "status": 200,
  "message": "User logged in successfully",
  "success": true,
  "data": {
    "token": "<redacted-token>",
    "permissions": [],
    "email_verified": true,
    "role": [],
    "expires_at": "2026-11-23T11:08:29.000000Z"
  }
}
```

**Invalid credentials / inactive admin — 404**

```json
{
  "status": 404,
  "message": "Invalid credentials",
  "success": false
}
```

**Valid credentials but not an admin — 404**

```json
{
  "status": 404,
  "message": "User not found",
  "success": false
}
```

**Admin email unverified — 404**

```json
{
  "status": 404,
  "message": "User not verified",
  "success": false
}
```

**Status codes**

- `200` — success.
- `404` — invalid credentials, non-admin, or unverified.
- `422` — validation failure.
- `429` — rate limit, if enabled.
- `500` — server failure.

### 4. What’s Next?

Store the token in the admin application and call admin APIs with `Authorization: Bearer <token>`.

### 5. Open

- Phone-only admin login is not supported; the controller ignores `phone_number`.
- A valid non-admin account returns `404 "User not found"`, not `403`.
- The admin token expires in 7 calendar days.

---

# 4. Social Redirect — Query Alias

### 1. Endpoint Information

- **Endpoint Name:** Social Login Redirect
- **Method & URL:** `GET /api/v1/social/redirect?provider=google`
- **Purpose:** Returns the Google/Facebook authorization URL.

### 2. Request

**Headers**

```http
Accept: application/json
lang: en
```

**Query**

| Field | Type | Required | Notes |
|---|---|---|---|
| `provider` | string | Yes | `google` or `facebook`, lowercase |
| `type` | string | No | `web` or `mobile`; default `web` |

```http
GET /api/v1/social/redirect?provider=google&type=mobile
```

### 3. Response

**Success — 200**

```json
{
  "success": true,
  "url": "https://accounts.google.com/o/oauth2/auth?client_id=...&redirect_uri=...&response_type=code&state=mobile"
}
```

**Invalid provider — 500**

```json
{
  "message": "Please login using Facebook or Google",
  "status": false,
  "success": false
}
```

**Missing/misconfigured callback URL — 500**

```json
{
  "message": "Social login failed, please try again.",
  "status": false,
  "success": false
}
```

**Status codes**

- `200` — URL returned.
- `500` — invalid provider or configuration.
- `429` — rate limit, if enabled.

### 4. What’s Next?

Open the returned `url` in a browser or WebView. This endpoint does **not** return a 302.

### 5. Open

- Provider credentials are empty in the checked environment.
- The response is JSON, not a redirect.
- `state` is only `web` or `mobile`; it is not a random nonce.

---

# 5. Social Redirect — Provider Path

### 1. Endpoint Information

- **Endpoint Name:** Social Login Redirect by Provider
- **Method & URL:** `GET /api/v1/social/{provider}`
- **Purpose:** Same as the query alias, with the provider in the path.

### 2. Request

**Headers**

```http
Accept: application/json
lang: en
```

**Path / Query**

| Field | Type | Required | Notes |
|---|---|---|---|
| `provider` | path string | Yes | `google` or `facebook` |
| `type` | query string | No | `web` or `mobile` |

```http
GET /api/v1/social/google?type=mobile
```

### 3. Response

**Success — 200**

```json
{
  "success": true,
  "url": "https://accounts.google.com/o/oauth2/auth?client_id=...&redirect_uri=...&response_type=code&state=mobile"
}
```

**Invalid provider — 500**

```json
{
  "message": "Please login using Facebook or Google",
  "status": false,
  "success": false
}
```

**Missing/misconfigured callback URL — 500**

```json
{
  "message": "Social login failed, please try again.",
  "status": false,
  "success": false
}
```

**Status codes**

- `200` — URL returned.
- `500` — invalid provider or configuration.
- `429` — rate limit, if enabled.

### 4. What’s Next?

Open the returned `url` in a browser or WebView.

### 5. Open

- Uppercase provider names are rejected.
- `/social/redirect` is declared before `/social/{provider}`, so the alias is not captured as a provider.

---

# 6. Social Callback

### 1. Endpoint Information

- **Endpoint Name:** Provider OAuth Callback
- **Method & URL:** `GET /api/v1/social/{provider}/callback`
- **Purpose:** Completes the OAuth exchange and creates a 10-minute authorization code for an active customer.

### 2. Request

The provider calls this endpoint; the mobile app should not call it directly.

**Query**

| Field | Type | Required | Notes |
|---|---|---|---|
| `code` | string | Yes on success | Provider code |
| `state` | string | Usually | `web` or `mobile` |
| `type` | string | No | Overrides `state` |

### 3. Response

**Mobile branch — 200**

```json
{
  "success": true,
  "code": "<64-character-hex-code>"
}
```

**Web branch — 302**

```text
Location: <frontend_url>/?code=<authorization-code>
```

**Mobile failure — 400**

```json
{
  "success": false,
  "message": "Social login failed, please try again."
}
```

**Web failure — 302**

```text
Location: <frontend_url>/auth?error=social_login_failed
```

**Invalid provider — 500**

```json
{
  "message": "Please login using Facebook or Google",
  "status": false,
  "success": false
}
```

**Status codes**

- `200` — mobile success with code.
- `302` — web success or web failure redirect.
- `400` — provider/configuration failure on mobile.
- `500` — invalid provider path.
- `429` — rate limit, if enabled.

### 4. What’s Next?

- **Web:** frontend reads `?code=` and calls `/social/exchange`.
- **Mobile:** the browser receives JSON, but there is no deep link/custom scheme to hand it to the app.

### 5. Open

- Native mobile handoff is **not implemented**.
- The code expires after 10 minutes by default.
- The code is reusable until expiry and is not deleted after exchange.
- Non-customer and inactive accounts are rejected before provider linking and code creation.

---

# 7. Social Exchange

### 1. Endpoint Information

- **Endpoint Name:** Exchange Social Code
- **Method & URL:** `POST /api/v1/social/exchange`
- **Purpose:** Exchanges a callback authorization code for a Sanctum token and user object.

### 2. Request

**Headers**

```http
Accept: application/json
Content-Type: application/json
lang: en
```

**Body**

| Field | Type | Required | Rules |
|---|---|---|---|
| `code` | string | Yes | Maximum 64 characters |

```json
{
  "code": "<64-character-hex-code>"
}
```

### 3. Response

**Success — 200**

```json
{
  "success": true,
  "message": "Login successful.",
  "token": "<redacted-token>",
  "token_type": "Bearer",
  "user": {
    "id": 1,
    "name": "Mobile User",
    "email": "mobile.user@gmail.com",
    "email_verified_at": "2026-11-16T11:08:29.000000Z",
    "is_active": 1,
    "image": null,
    "type": "user",
    "phone_number": null,
    "created_at": "2026-11-16T11:08:29.000000Z",
    "updated_at": "2026-11-16T11:08:29.000000Z",
    "roles": [],
    "permissions": []
  }
}
```

**Invalid/expired code or ineligible user — 400**

```json
{
  "success": false,
  "message": "Invalid or expired authorization code."
}
```

**Validation error — 422**

```json
{
  "message": "The given data was invalid.",
  "status": false,
  "success": false,
  "errors": {
    "code": [
      "The code field is required."
    ]
  }
}
```

**Status codes**

- `200` — token issued.
- `400` — invalid, expired, inactive, or non-customer account.
- `422` — missing/invalid code.
- `429` — rate limit, if enabled.
- `500` — server failure.

### 4. What’s Next?

Store the token and use `Authorization: Bearer <token>`.

### 5. Open

- The social token has no expiry.
- The code remains reusable until its 10-minute expiry.
- The exchange independently requires an active `type=user` account.

---

# 8. Logout

### 1. Endpoint Information

- **Endpoint Name:** Logout
- **Method & URL:** `POST /api/v1/logout`
- **Purpose:** Revokes the current Sanctum access token.

### 2. Request

**Headers**

```http
Authorization: Bearer <token>
Accept: application/json
lang: en
```

No body.

### 3. Response

**Success — 200**

```json
{
  "status": 200,
  "message": "Logged out successfully",
  "success": true
}
```

**Unauthenticated — 401**

```json
{
  "message": "Unauthenticated.",
  "status": false,
  "success": false
}
```

**Status codes**

- `200` — current token revoked.
- `401` — missing/invalid token.
- `404` — no authenticated user (normally unreachable).

### 4. What’s Next?

Discard the local token and return the user to the login screen.

### 5. Open

Only the token used for the request is revoked; other device tokens remain valid.

---

# 9. Forget Password

### 1. Endpoint Information

- **Endpoint Name:** Request Password Reset
- **Method & URL:** `POST /api/v1/forget-password`
- **Purpose:** Creates a password-reset OTP and emails it when the account exists.

### 2. Request

**Headers**

```http
Accept: application/json
Content-Type: application/json
lang: en
```

**Body**

| Field | Type | Required | Notes |
|---|---|---|---|
| `email` | string | Yes in practice | The controller does not validate it |

```json
{
  "email": "mobile.user@gmail.com"
}
```

### 3. Response

**Success — 200**

```json
{
  "status": 200,
  "message": "Check your inbox for password reset email",
  "success": true
}
```

**Mail failure — 500**

```json
{
  "status": 500,
  "message": "Something went wrong.",
  "success": false
}
```

**Status codes**

- `200` — request accepted, including unknown emails.
- `500` — reset email could not be sent.
- `429` — sensitive throttle, if enabled.

### 4. What’s Next?

Ask the user for the OTP from the email, then call `/verify-forget-password-token`.

### 5. Open

- Unknown emails return the same success response.
- The controller does not validate that `email` is present or valid.
- Email delivery is external and not verified.

---

# 10. Verify Forget Password Token

### 1. Endpoint Information

- **Endpoint Name:** Verify Password Reset OTP
- **Method & URL:** `POST /api/v1/verify-forget-password-token`
- **Purpose:** Checks the reset OTP before allowing a password change.

### 2. Request

**Headers**

```http
Accept: application/json
Content-Type: application/json
lang: en
```

**Body**

| Field | Type | Required | Rules |
|---|---|---|---|
| `email` | string | Yes | Valid email |
| `otp` | string | Yes | Reset code |

```json
{
  "email": "mobile.user@gmail.com",
  "otp": "123456"
}
```

### 3. Response

**Success — 200**

```json
{
  "status": 200,
  "message": "Token is valid",
  "success": true
}
```

**Invalid/expired OTP — 400**

```json
{
  "status": 400,
  "message": "Invalid token",
  "success": false
}
```

**Validation error — 422**

```json
{
  "message": "The given data was invalid.",
  "status": false,
  "success": false,
  "errors": {
    "otp": [
      "The otp field is required."
    ]
  }
}
```

**Lockout — 429**

```json
{
  "status": 429,
  "success": false,
  "message": "Too many requests",
  "data": null
}
```

**Status codes**

- `200` — OTP valid.
- `400` — invalid or expired OTP.
- `422` — validation failure.
- `429` — repeated failures or throttle.

### 4. What’s Next?

Call `/reset-password` with the same email, OTP, and new password.

### 5. Open

The reset OTP is a six-digit number and expires after the configured password-reset window.

---

# 11. Reset Password

### 1. Endpoint Information

- **Endpoint Name:** Reset Password
- **Method & URL:** `POST /api/v1/reset-password`
- **Purpose:** Changes the password after OTP verification and revokes all existing tokens.

### 2. Request

**Headers**

```http
Accept: application/json
Content-Type: application/json
lang: en
```

**Body**

| Field | Type | Required | Rules |
|---|---|---|---|
| `email` | string | Yes | Valid email |
| `otp` | string | Yes | Reset code |
| `password` | string | Yes | 8–50; confirmed |
| `password_confirmation` | string | Yes | 8–50 |

```json
{
  "email": "mobile.user@gmail.com",
  "otp": "123456",
  "password": "NewPassword123!",
  "password_confirmation": "NewPassword123!"
}
```

### 3. Response

**Success — 200**

```json
{
  "status": 200,
  "message": "Password reset successfully",
  "success": true
}
```

**Invalid/expired OTP — 400**

```json
{
  "status": 400,
  "message": "Invalid token",
  "success": false
}
```

**Validation error — 422**

```json
{
  "message": "The given data was invalid.",
  "status": false,
  "success": false,
  "errors": {
    "password": [
      "The password field confirmation does not match."
    ]
  }
}
```

**Status codes**

- `200` — password changed.
- `400` — invalid/expired OTP.
- `422` — validation failure.
- `429` — repeated failures or throttle.
- `500` — reset failure.

### 4. What’s Next?

Log in again with the new password. All previous tokens are revoked.

### 5. Open

The reset is transactional; a failed reset should not change the password.

---

# 12. Send OTP Code

### 1. Endpoint Information

- **Endpoint Name:** Send Login OTP
- **Method & URL:** `POST /api/v1/send-otp-code`
- **Purpose:** Sends an email OTP or phone OTP for login. Unknown accounts receive a generic success response.

### 2. Request

**Headers**

```http
Accept: application/json
Content-Type: application/json
lang: en
```

**Body**

| Field | Type | Required | Rules |
|---|---|---|---|
| `email` | string | Conditional | Required without phone; valid email |
| `phone_number` | string | Conditional | Required without email; 11–15 characters |

Email:

```json
{
  "email": "mobile.user@gmail.com"
}
```

Phone:

```json
{
  "phone_number": "01000000002"
}
```

### 3. Response

**Success — 200**

```json
{
  "status": 200,
  "message": "OTP sent successfully",
  "success": true,
  "data": {}
}
```

**Phone OTP with gateway ID — 200**

```json
{
  "status": 200,
  "message": "OTP sent successfully",
  "success": true,
  "data": {
    "otp_id": "local-verification-id"
  }
}
```

**Email OTP delivery failed — 201**

```json
{
  "status": 201,
  "message": "Account created successfully but failed to send verification code",
  "success": true
}
```

**Status codes**

- `200` — accepted; unknown/inactive accounts also receive this shape.
- `201` — email OTP creation failed.
- `422` — validation or gateway configuration failure.
- `429` — OTP throttle, if enabled.

### 4. What’s Next?

Call `/otp-login` with the email or phone and the received code. Phone login also requires `otp_id` when returned.

### 5. Open

- The response intentionally does not reveal whether the account exists.
- The email OTP failure path reuses an account-registration message.

---

# 13. OTP Login

### 1. Endpoint Information

- **Endpoint Name:** Login with OTP
- **Method & URL:** `POST /api/v1/otp-login`
- **Purpose:** Logs in an active customer using an email OTP or a phone OTP.

### 2. Request

**Headers**

```http
Accept: application/json
Content-Type: application/json
lang: en
```

**Email OTP**

| Field | Type | Required | Rules |
|---|---|---|---|
| `email` | string | Yes | Valid email |
| `code` | string | Yes | 4–6 characters |

```json
{
  "email": "mobile.user@gmail.com",
  "code": "123456"
}
```

**Phone OTP**

| Field | Type | Required | Notes |
|---|---|---|---|
| `phone_number` | string | Yes | Registered phone |
| `otp_id` | string | Yes for gateway verification | Returned by `/send-otp-code` |
| `code` | string | Yes | OTP code |

```json
{
  "phone_number": "01000000002",
  "otp_id": "local-verification-id",
  "code": "123456"
}
```

### 3. Response

**Success — 200**

```json
{
  "status": 200,
  "message": "User logged in successfully",
  "success": true,
  "data": {
    "token": "<redacted-token>"
  }
}
```

**Invalid OTP — 400**

```json
{
  "status": 400,
  "message": "Invalid verification code",
  "success": false
}
```

**Email user not found — 404**

```json
{
  "status": 404,
  "message": "User not found",
  "success": false
}
```

**Phone user not found — 404**

```json
{
  "status": 404,
  "message": "Required information is missing",
  "success": false
}
```

**Lockout — 429**

```json
{
  "status": 429,
  "success": false,
  "message": "Too many requests",
  "data": null
}
```

**Status codes**

- `200` — token issued.
- `400` — invalid OTP.
- `404` — unknown/inactive/non-customer account.
- `422` — OTP gateway failure.
- `429` — repeated failures or throttle.

### 4. What’s Next?

Store the token and call authenticated endpoints with `Authorization: Bearer <token>`.

### 5. Open

- Email OTP uses the email OTP flow; phone OTP uses the configured gateway.
- Only active customers (`type=user`) can obtain a token through this path.
- Phone OTP login requires the `otp_id` from `/send-otp-code` for gateway verification.

---

## Cross-Endpoint Notes

1. **Rate limiting:** The route middleware is present, but the named limiters are not registered and rate limiting is bypassed by default. `429` should be treated as conditional.
2. **Admin login:** Included because it is in the provided route group. It is not part of the customer mobile contract.
3. **Social login:** Provider credentials are empty in the checked environment; the redirect endpoint returns a URL but provider authentication cannot complete until configured.
4. **Social native handoff:** No deep link or custom scheme exists; `type=mobile` returns JSON in the browser.
5. **Token lifetimes:** Normal user token = 14 weekdays; admin token = 7 days; social exchange token = no expiry.
6. **Password reset OTP:** Six numeric digits, hashed in `password_resets`, expires per `auth.passwords.users.expire`.
7. **OTP behavior:** `/send-otp-code` deliberately returns the same success shape for unknown accounts.
8. **Logout:** Revokes only the current token.
