# Endpoint Name

Admin – Login (`POST /api/v1/admin-login`)

# Description

Authenticates an administrative user using email and password. Verifies that the account is active, has the discriminator `type === 'admin'`, and has a verified email address. Upon successful verification, it generates a Sanctum personal access token with a 7-day expiration (`now()->addWeek()`), dispatches the `AdminLoggedIn` event (broadcasting to the `private-admin.notifications` channel and notifying active administrators), and returns the bearer token along with the user's roles, direct/inherited permissions, and token expiration timestamp.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin-login` (fixed path — no placeholders) |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `admin-login` custom route defined in `packages/marvel/src/Rest/Routes.php` inside the `['throttle:login', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\UserController@adminToken` (`UserAuthEmailAndPasswordRequest $request`) |
| Authentication | Public. No token required (used to authenticate and issue a Sanctum token) |
| Permission | Public. No permission required |
| Required headers | `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`; selects response message locale) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:login`: 5 requests per minute per IP address, configured via `RateLimiter::for('login')` in `app/Providers/AppServiceProvider.php` (returns HTTP 429 on exhaustion) |
| Route name | None defined for this route |

## Request inputs

Validated by `Marvel\Http\Requests\UserAuthEmailAndPasswordRequest` before the controller action executes (`authorize(): true`).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `email` | body | string | Required for admin login | No | `required_without:phone_number\|email` | Valid RFC email address | None | While the FormRequest technically allows `phone_number` as an alternative, `adminToken` queries exclusively by `where('email', $request->email)`: omitting `email` results in HTTP 404 (`INVALID_CREDENTIALS`) | `"admin@demo.com"` | Admin account email address |
| `password` | body | string | Required | No | `required\|string\|min:6` | Plaintext password (min 6 characters) | None | None | `"secret123"` | Admin account password |
| `phone_number` | body | string | Optional | Yes | `required_without:email\|string\|max:15\|min:8` | 8–15 character string | None | Accepted by validation if `email` is absent, but ignored by `adminToken` user resolution | `"01000000001"` | Phone number (customer login legacy, not used in adminToken) |

## Request examples

Standard JSON admin login request:

```json
{
  "email": "admin@demo.com",
  "password": "secret123"
}
```

Request with headers:

```http
POST /api/v1/admin-login HTTP/1.1
Host: api.example.com
Accept: application/json
Content-Type: application/json
lang: ar

{
  "email": "admin@demo.com",
  "password": "secret123"
}
```

# Responses

## Success: Admin authenticated — HTTP 200

Returned by `UserController::adminToken` via `ApiResponse::apiResponse(USER_LOGGED_IN_SUCCESSFULLY, 200, true, $data)`.

| Property | Value |
| --- | --- |
| HTTP status | `200` |
| Content-Type | `application/json` |
| Envelope | `{status: number, message: string, success: boolean, data: object}` |
| `status` | Always present. Integer `200` (mirrors HTTP status code) |
| `message` | Always present. String. Translated `MESSAGE.USER_LOGGED_IN_SUCCESSFULLY`: `en` → `"User logged in successfully"`, `ar` → `"تم تسجيل دخول المستخدم بنجاح"` |
| `success` | Always present. Boolean `true` |
| `data` | Always present on success. Object containing credentials and authorization metadata |

### `data` object structure

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `token` | string | Always present | Plaintext Sanctum bearer token | Format: `<token_id>\|<plaintext_hash>`, e.g. `"24\|odPU2cQXM0asFF4oEajmcLd6Tvnk5pqRIq6mAKab79b8fd01"`. Created with name `'auth_token'` and expiration `now()->addWeek()` |
| `permissions` | array of strings | Always present | Flat list of all direct and role-inherited permission names | Output of `$user->getAllPermissions()->pluck('name')`, e.g. `["super_admin", "barcode.scan", "warehouses.index", ...]` |
| `email_verified` | boolean | Always present | Whether the user's email is verified | Always `true` on this path (unverified emails trigger HTTP 404 before token creation) |
| `role` | array of strings | Always present | Names of Spatie roles assigned directly to the user | Output of `$user->roles->pluck('name')`, e.g. `["super_admin"]` |
| `expires_at` | string (datetime) or null | Always present | Expiration timestamp of the created token | ISO 8601 string, e.g. `"2026-10-25T11:19:07.000000Z"` (7 days from authentication) |

### Success response example

```json
{
  "status": 200,
  "message": "User logged in successfully",
  "success": true,
  "data": {
    "token": "24|odPU2cQXM0asFF4oEajmcLd6Tvnk5pqRIq6mAKab79b8fd01",
    "permissions": [
      "super_admin",
      "barcode.scan",
      "warehouses.index",
      "warehouses.create",
      "warehouses.show",
      "warehouses.update"
    ],
    "email_verified": true,
    "role": [
      "super_admin"
    ],
    "expires_at": "2026-10-25T11:19:07.000000Z"
  }
}
```

# Errors

The endpoint utilizes specific HTTP status codes and translation constants. Note that authentication logic rejections return HTTP `404` via the `ApiResponse` trait, while validation failures return HTTP `422` and rate limits return HTTP `429`.

| HTTP status | Trigger | Response body | Message | Field errors | Conditions |
| --- | --- | --- | --- | --- | --- |
| 404 | Invalid credentials or inactive account | `{status: 404, message: string, success: false}` | Translated `ERROR.INVALID_CREDENTIALS`: `en` → `"Invalid credentials"`, `ar` → `"بيانات الاعتماد غير صحيحة"` | None | User row not found with `email` and `is_active = true`, OR `Hash::check($password, $user->password)` returned `false` |
| 404 | User account is not an admin | `{status: 404, message: string, success: false}` | Translated `ERROR.USER_NOT_FOUND`: `en` → `"User not found"`, `ar` → `"المستخدم غير موجود"` | None | Credentials valid, but `$user->type !== 'admin'` |
| 404 | Email not verified | `{status: 404, message: string, success: false}` | Translated `ERROR.USER_NOT_VERIFIED`: `en` → `"User not verified"`, `ar` → `"الحساب غير مفعل"` | None | Active admin credentials valid, but `$user->hasVerifiedEmail()` is `false` |
| 405 | Wrong HTTP method | `{message: string, status: boolean}` | `"Method Not Allowed"` | None | Calling `/api/v1/admin-login` with `GET`, `PUT`, `PATCH`, `DELETE` |
| 422 | Request validation failure | `{message: string, status: boolean, errors: object}` | Validation summary (e.g. `"The email field is required."`) | Per-field string arrays | Missing `email` and `phone_number`, invalid email format, missing `password`, or `password` shorter than 6 characters |
| 429 | Rate limit exceeded | `{success: false, message: string, data: null}` | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Caller exceeded 5 login attempts per minute from the same IP address |

### Error response examples

404 example (Invalid credentials / inactive user):

```json
{
  "status": 404,
  "message": "Invalid credentials",
  "success": false
}
```

404 example (User is a customer, not an admin):

```json
{
  "status": 404,
  "message": "User not found",
  "success": false
}
```

404 example (Admin email is not verified):

```json
{
  "status": 404,
  "message": "User not verified",
  "success": false
}
```

422 example (Missing credentials):

```json
{
  "message": "The email field is required when phone number is not present. (and 1 more error)",
  "status": false,
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

422 example (Password under minimum length):

```json
{
  "message": "The password must be at least 6 characters.",
  "status": false,
  "errors": {
    "password": [
      "The password must be at least 6 characters."
    ]
  }
}
```

429 example (Rate limit exceeded: > 5 attempts/minute):

```json
{
  "success": false,
  "message": "Too many requests. Please try again later.",
  "data": null
}
```

# Side Effects & System Events

1. **Sanctum Personal Access Token Creation**:
   - Creates a new record in `personal_access_tokens` table:
     - `tokenable_type`: `App\Models\User` (or `Marvel\Database\Models\User`)
     - `tokenable_id`: `$user->id`
     - `name`: `'auth_token'`
     - `abilities`: `["*"]`
     - `expires_at`: `now()->addWeek()` (7 days from creation)

2. **Event Dispatching (`AdminLoggedIn`)**:
   - `App\Events\AdminLoggedIn::dispatch($user, request()->ip(), request()->userAgent())`
   - **Broadcasting**: Implements `ShouldBroadcast` on connection `'pusher'`.
     - Channel: `private-admin.notifications`
     - Broadcast Event Name: `admin.logged.in`
     - Broadcast Payload:
       ```json
       {
         "id": 1,
         "name": "Super Admin",
         "email": "admin@demo.com",
         "type": "admin",
         "login_time": "2026-10-18T14:03:23+03:00"
       }
       ```

3. **Notification Triggering (`SendAdminLoginNotification`)**:
   - Handled synchronously by `App\Listeners\SendAdminLoginNotification`.
   - Locates all active administrator accounts (`type === 'admin'`, `is_active === true`).
   - Dispatches queued notification `App\Notifications\AdminLoggedInNotification` to all administrators alerting them of the login attempt with IP address and user-agent details.
