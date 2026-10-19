# Endpoint Name

User – Logout (`POST /api/v1/logout`)

# Description

Logs out the authenticated user by revoking and deleting their current Sanctum personal access token (`$user->currentAccessToken()->delete()`). Ensures that only the current session/token used to make the request is invalidated, while leaving any other active sessions or device tokens intact. This route is deliberately not rate limited so that users are always able to terminate their session securely.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/logout` (fixed path — no placeholders) |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `logout` route defined in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'lang']` middleware group |
| Controller action | `Marvel\Http\Controllers\UserController@logout` (`Request $request`) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Public to any authenticated user. No specific role or permission required |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`; selects response message locale) |
| Supported request formats | None required (no request body expected) |
| Rate limit | **None** (`// Logout is not rate limited - users should always be able to log out`, explicitly excluded from throttling to allow graceful session exit) |
| Route name | None defined for this route |

## Request inputs

This endpoint does not accept, require, or process any request body parameters, query parameters, or form fields. All operational context is derived entirely from the authenticated caller (`$request->user()`) and the active bearer token (`$user->currentAccessToken()`).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid bearer token | None | Authenticates the user and identifies the token to revoke |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | Selects locale for response message |
| `Accept` | header | string | Recommended | No | Content negotiation | `application/json` | None | Directs server to return JSON envelope |

## Request examples

Standard logout request:

```http
POST /api/v1/logout HTTP/1.1
Host: api.example.com
Authorization: Bearer 24|odPU2cQXM0asFF4oEajmcLd6Tvnk5pqRIq6mAKab79b8fd01
Accept: application/json
lang: ar
```

cURL example:

```bash
curl -X POST https://api.example.com/api/v1/logout \
  -H "Authorization: Bearer 24|odPU2cQXM0asFF4oEajmcLd6Tvnk5pqRIq6mAKab79b8fd01" \
  -H "Accept: application/json" \
  -H "lang: en"
```

# Responses

## Success: Session terminated — HTTP 200

Returned by `UserController::logout` via `ApiResponse::apiResponse(USER_LOGGED_OUT_SUCCESSFULLY, 200, true)`.

| Property | Value |
| --- | --- |
| HTTP status | `200` |
| Content-Type | `application/json` |
| Envelope | `{status: number, message: string, success: boolean}` (Note: `data` key is omitted when empty, per `ApiResponse` design) |
| `status` | Always present. Integer `200` (mirrors HTTP status code) |
| `message` | Always present. String. Translated `MESSAGE.USER_LOGGED_OUT_SUCCESSFULLY`: `en` → `"Logged out successfully"`, `ar` → `"تم تسجيل الخروج بنجاح"` |
| `success` | Always present. Boolean `true` |

### Success response example

English response (`lang: en` or default):

```json
{
  "status": 200,
  "message": "Logged out successfully",
  "success": true
}
```

Arabic response (`lang: ar`):

```json
{
  "status": 200,
  "message": "تم تسجيل الخروج بنجاح",
  "success": true
}
```

# Errors

| HTTP status | Trigger | Response body | Message | Conditions | Example |
| --- | --- | --- | --- | --- | --- |
| 401 | Missing, expired, malformed, or already-revoked bearer token | `{message: string, status: boolean}` | `"Unauthenticated"` | Rejected by `auth:sanctum` middleware before reaching controller | See 401 example |
| 404 | User model cannot be resolved from request | `{status: 404, message: string, success: false}` | Translated `ERROR.USER_NOT_FOUND`: `en` → `"User not found"`, `ar` → `"المستخدم غير موجود"` | Triggered by `$user = $request->user(); if (!$user)` guard check | See 404 example |
| 405 | Wrong HTTP method | `{message: string, status: boolean}` | `"Method Not Allowed"` | Calling `/api/v1/logout` with `GET`, `PUT`, `PATCH`, `DELETE` | See 405 example |

### Error response examples

401 example (No or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

404 example (User record deleted or unavailable):

```json
{
  "status": 404,
  "message": "User not found",
  "success": false
}
```

405 example (e.g. `GET /api/v1/logout`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

# Side Effects & System Impact

1. **Token Invalidation in Database**:
   - Executes:
     ```sql
     DELETE FROM `personal_access_tokens` WHERE `id` = :current_token_id;
     ```
   - Only the specific token used in the `Authorization: Bearer` header is deleted.
   - Any other active tokens issued to the same user (e.g., from other browsers, devices, or active mobile sessions) remain valid.
2. **Immediate Session Termination**:
   - Any subsequent HTTP request using the deleted token will immediately fail with HTTP `401 Unauthenticated`.
