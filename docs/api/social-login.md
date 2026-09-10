# Social Login — One-Time Authorization Code Exchange

Production-ready OAuth callback flow for Google / Facebook login.

**Frontend SPA:** `https://meem-market-ecommerce.vercel.app`

The API token is **never** placed in a redirect URL. The callback issues a
short-lived, single-use authorization code that the frontend exchanges for a
Sanctum token.

---

## 1. Start Login

```
GET /api/v1/social/{provider}
```

| Parameter | Type | Required | Description |
|---|---|---|---|
| `provider` | string | yes | `google` or `facebook` |

Example:

```
GET /api/v1/social/google
```

**Response:** `302 Redirect` — the browser is redirected to the OAuth
provider's consent screen.

Backwards-compatible alias:

```
GET /api/v1/social/redirect?provider=google
```

---

## 2. Callback

After the user approves, the provider redirects the browser to:

```
GET /api/v1/social/{provider}/callback
```

Laravel automatically:

1. Validates the provider response.
2. Finds or creates the local user.
3. Creates a `providers` link row for the account.
4. Generates a **cryptographically secure** single-use authorization code
   (`bin2hex(random_bytes(32))`).
5. Stores `{ code, user_id, expires_at, used = false }`.
6. Redirects the browser to the frontend.

### Success

```
302 Redirect
Location: https://meem-market-ecommerce.vercel.app/?code={authorization_code}
```

### Failure

Redirected for any failure: provider rejection, user cancel, invalid state,
unexpected exception.

```
302 Redirect
Location: https://meem-market-ecommerce.vercel.app/auth?error=social_login_failed
```

**Authorization code guarantees**

| Property | Value |
|---|---|
| Length | 64 hex characters (256 bits of entropy) |
| TTL | 5 minutes (`SOCIAL_LOGIN_CODE_TTL_MINUTES`) |
| Usage | Single use only |
| Concurrency | Atomic claim (`UPDATE ... WHERE used = false`) prevents double-spend |
| Cleanup | Row deleted immediately after successful exchange |

---

## 3. Exchange Code

```
POST /api/v1/social/exchange
```

Content-Type: `application/json`

### Request

```json
{
  "code": "a1b2c3...64hexchars"
}
```

### Validation

- `code` is required, string, max 64 chars.

### Success — `200 OK`

```json
{
  "success": true,
  "message": "Login successful.",
  "token": "1|abc123...",
  "token_type": "Bearer",
  "user": {
    "id": 4,
    "name": "Mohammed Tarek",
    "email": "user@gmail.com",
    "email_verified_at": "2026-08-03T10:00:00.000000Z",
    "is_active": 1,
    "image": null,
    "type": "user",
    "phone_number": null,
    "created_at": "2026-08-03T10:00:00.000000Z",
    "updated_at": "2026-08-03T10:00:00.000000Z",
    "roles": [],
    "permissions": []
  }
}
```

### Failure — `400 Bad Request`

Replayed, expired, unknown, or already-used code:

```json
{
  "success": false,
  "message": "Invalid or expired authorization code."
}
```

### Validation failure — `422 Unprocessable Entity`

```json
{
  "code": ["The code field is required."]
}
```

---

## Frontend Integration Guide

### On successful login load

```
https://meem-market-ecommerce.vercel.app/?code=xxxxxxxx
```

1. Read the `code` query parameter.
2. Immediately call:

```javascript
const res = await fetch('https://api.mohammedtareq.me/api/v1/social/exchange', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ code }),
});

const data = await res.json();

if (data.success) {
  // 3. Save the bearer token
  localStorage.setItem('token', data.token);
  // 4. Remove the code from the URL
  history.replaceState({}, '', '/');
} else {
  // show error, redirect to /auth
}
```

### On failure

```
/auth?error=social_login_failed
```

Display an appropriate login error message.

---

## Environment Configuration

```env
# Base URL of the SPA that receives the authorization code
SOCIAL_LOGIN_FRONTEND_URL=https://meem-market-ecommerce.vercel.app

# Optional: code TTL in minutes (default 5)
SOCIAL_LOGIN_CODE_TTL_MINUTES=5
```

### Google Cloud Console

Authorized redirect URIs must include:

```
https://api.mohammedtareq.me/api/v1/social/google/callback
```

### Facebook Developer Console

Valid OAuth redirect URI:

```
https://api.mohammedtareq.me/api/v1/social/facebook/callback
```

---

## Dependencies

| Layer | Class |
|---|---|
| Controller | `Marvel\Http\Controllers\SocialController` |
| Request | `Marvel\Http\Requests\SocialLoginExchangeRequest` |
| Model | `Marvel\Database\Models\SocialLoginCode` |
| Model | `Marvel\Database\Models\Provider` |
| Resource | `Marvel\Http\Resources\UserResource` |
| Table | `social_login_codes` |
| Table | `providers` |

## Test Coverage

`tests/Feature/SocialLoginFlowTest.php`

- redirect bounces user to provider
- callback creates user and issues single-use code
- callback links existing user
- callback redirects to frontend error when provider fails
- exchange returns token and deletes code
- exchange rejects replay of used code
- exchange rejects expired code
- exchange rejects unknown code
- exchange requires code field
