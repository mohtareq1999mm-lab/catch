# Bug Fix: Admin Login Endpoint

**Date:** 2026-07-20

**Affected Endpoint:** `POST /api/v1/admin-login`

## What Was Fixed

The admin login endpoint route was established and verified. Admins must use `/admin-login` (not `/token`) to authenticate.

## Why

- `/token` — General login for all user types (no admin type check, no email verification required)
- `/admin-login` — Admin-only login with additional security checks

## Backend-Enforced Rules

The `adminToken()` method in `UserController` enforces:

1. **Valid credentials** — email + password must match
2. **Active account** — `is_active` must be `true`
3. **Admin type** — `type` must be `admin` (non-admin users receive 404)
4. **Email verified** — `email_verified_at` must not be null

## Response Structure

```json
{
  "token": "1|abc123...",
  "permissions": ["super_admin", "store_owner"],
  "email_verified": true,
  "role": ["Super Admin"]
}
```

## Frontend Integration

### Correct Usage

```http
POST /api/v1/admin-login
Content-Type: application/json

{
  "email": "admin@example.com",
  "password": "secret"
}
```

### Incorrect Usage (will fail for admin users)

```http
POST /api/v1/token
```

The `/token` endpoint does NOT verify admin type or email verification and only returns token + email_verified — it lacks permissions and role data needed for admin dashboard.

## Error Responses

| HTTP Status | Message | Reason |
|-------------|---------|--------|
| 404 | Invalid Credentials | Wrong email or password |
| 404 | User not found | User is not type=admin |
| 404 | User not verified | Email not verified |
