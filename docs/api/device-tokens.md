# General – Device Tokens (FCM) Endpoints

This document covers the device token management endpoints used to register and remove Firebase Cloud Messaging (FCM) push tokens for authenticated users.

---

# 1. Register Device Token (`POST /api/v1/general/device-tokens`)

## Description

Registers or updates an FCM registration token for the authenticated user. Tokens are globally unique across the system: if a token was previously associated with another user account (e.g., when multiple accounts log into the same physical device), the record is reassigned to the currently authenticated user and its `last_used_at` timestamp is updated to the current time.

Tokens registered through this endpoint are consumed downstream by `App\Jobs\SendFcmNotificationJob` via `App\Services\Firebase\FcmService` to deliver push notifications scoped strictly to the target user.

## Request

### Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/general/device-tokens` |
| Path composition | `v1/general` group prefix in `routes/api.php` + `device-tokens` route inside the `['api', 'auth:sanctum', 'throttle:authenticated']` group |
| Controller action | `App\Http\Controllers\Api\General\DeviceTokenController@store` |
| Authentication | Required (`auth:sanctum`). Bearer token in the `Authorization` header |
| Permission | Authenticated user (no specialized Spatie permission required) |
| Required headers | `Authorization: Bearer <token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en|ar` (default `en`; selects response message locale) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:authenticated` configured in route middleware |
| Route name | None defined for this route |

### Request inputs

Validated inline via `$request->validate(...)` in `DeviceTokenController@store`:

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| `token` | body | string | Yes | No | `required\|string\|max:512` | Valid FCM registration token string (≤ 512 chars) | None | None | `"fB3k..._9x"` | FCM registration token issued by Firebase SDK on the client device |
| `client` | body | string | Yes | No | `required\|string\|in:client_a,client_b` | `'client_a'`, `'client_b'` | None | None | `"client_a"` | Client application identifier configured in `config/firebase.php` |
| `platform` | body | string | No | No | `sometimes\|string\|in:android,ios` | `'android'`, `'ios'` | `'android'` | Defaults to `'android'` if omitted | `"android"` | Operating system platform of the client device |

### Request examples

Standard JSON request:

```json
{
  "token": "fB3kL8mP0qR1sT2uV3wX4yZ5aB6cD7eF8gH9iJ0kL1mN2oP3qR4sT5uV6wX7yZ8",
  "client": "client_a",
  "platform": "android"
}
```

HTTP raw request with headers:

```http
POST /api/v1/general/device-tokens HTTP/1.1
Host: api.example.com
Authorization: Bearer 24|odPU2cQXM0asFF4oEajmcLd6Tvnk5pqRIq6mAKab79b8fd01
Accept: application/json
Content-Type: application/json
lang: ar

{
  "token": "fB3kL8mP0qR1sT2uV3wX4yZ5aB6cD7eF8gH9iJ0kL1mN2oP3qR4sT5uV6wX7yZ8",
  "client": "client_a",
  "platform": "android"
}
```

## Responses

### Success: Device token registered — HTTP 200

Returned by `DeviceTokenController@store` via `ApiResponse::apiResponse(DEVICE_TOKEN_REGISTERED, 200, true, ['uuid' => $device->uuid])`.

| Property | Value |
| --- | --- |
| HTTP status | `200` |
| Content-Type | `application/json` |
| Envelope | `{status: number, message: string, success: boolean, data: object}` |
| `status` | Always present. Integer `200` |
| `message` | Always present. String. Translated `MESSAGE.DEVICE_TOKEN_REGISTERED`: `en` → `"Device token registered"`, `ar` → `"تم تسجيل رمز الجهاز"` |
| `success` | Always present. Boolean `true` |
| `data` | Always present on success. Object containing device UUID |

#### `data` object structure

| Field | Data type | Presence | Meaning | Example |
| :--- | :--- | :--- | :--- | :--- |
| `uuid` | string (UUID v4 / ordered UUID) | Always present | Unique identifier for the registered device record | `"9d7c0410-6ae9-42b4-8e10-6c0b9e8d35cf"` |

#### Success response example

```json
{
  "status": 200,
  "message": "Device token registered",
  "success": true,
  "data": {
    "uuid": "9d7c0410-6ae9-42b4-8e10-6c0b9e8d35cf"
  }
}
```

## Errors

| HTTP status | Trigger | Response body | Message | Field errors | Conditions |
| :--- | :--- | :--- | :--- | :--- | :--- |
| 401 | Missing or invalid Bearer token | `{"message": "Unauthenticated."}` | `"Unauthenticated."` | None | No Sanctum authorization token supplied or token is revoked/expired |
| 422 | Validation failure | `{"message": "The token field is required.", "errors": {"token": [...]}}` | Summary message | Per-field string arrays | Missing `token`, missing or invalid `client` (`not in client_a,client_b`), invalid `platform` (`not in android,ios`), or `token` length > 512 |

### Error response examples

401 Unauthorized example:

```json
{
  "message": "Unauthenticated."
}
```

422 Validation error example (Invalid client & missing token):

```json
{
  "message": "The token field is required. (and 1 more error)",
  "errors": {
    "token": [
      "The token field is required."
    ],
    "client": [
      "The selected client is invalid."
    ]
  }
}
```

## Database Impact

* **Table:** `device_tokens`
* **Operation:** `updateOrCreate` on `['token' => $data['token']]`
* **Fields written:**
  * `uuid`: Auto-generated ordered UUID on creation (`Str::orderedUuid()`).
  * `user_id`: `$request->user()->id`
  * `token`: `$data['token']`
  * `client`: `$data['client']`
  * `platform`: `$data['platform'] ?? 'android'`
  * `last_used_at`: `now()`
  * `updated_at`: `now()`

---

# 2. Remove Device Token (`DELETE /api/v1/general/device-tokens`)

## Description

Removes an existing FCM registration token for the authenticated user (typically called upon logout or device deregistration). To ensure tenancy and security isolation, deletion queries scope strictly by both `token` and `user_id`: a user cannot remove another user's device token.

## Request

### Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `DELETE` |
| Full path | `/api/v1/general/device-tokens` |
| Path composition | `v1/general` group prefix in `routes/api.php` + `device-tokens` route inside the `['api', 'auth:sanctum', 'throttle:authenticated']` group |
| Controller action | `App\Http\Controllers\Api\General\DeviceTokenController@destroy` |
| Authentication | Required (`auth:sanctum`). Bearer token in the `Authorization` header |
| Permission | Authenticated user |
| Required headers | `Authorization: Bearer <token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en|ar` (default `en`; selects response message locale) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:authenticated` configured in route middleware |
| Route name | None defined for this route |

### Request inputs

Validated inline via `$request->validate(...)` in `DeviceTokenController@destroy`:

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| `token` | body | string | Yes | No | `required\|string\|max:512` | String (≤ 512 chars) | None | None | `"fB3k..._9x"` | FCM registration token to unregister |

### Request examples

Standard JSON request:

```json
{
  "token": "fB3kL8mP0qR1sT2uV3wX4yZ5aB6cD7eF8gH9iJ0kL1mN2oP3qR4sT5uV6wX7yZ8"
}
```

HTTP raw request with headers:

```http
DELETE /api/v1/general/device-tokens HTTP/1.1
Host: api.example.com
Authorization: Bearer 24|odPU2cQXM0asFF4oEajmcLd6Tvnk5pqRIq6mAKab79b8fd01
Accept: application/json
Content-Type: application/json
lang: ar

{
  "token": "fB3kL8mP0qR1sT2uV3wX4yZ5aB6cD7eF8gH9iJ0kL1mN2oP3qR4sT5uV6wX7yZ8"
}
```

## Responses

### Success: Device token removed — HTTP 200

Returned by `DeviceTokenController@destroy` via `ApiResponse::apiResponse(DEVICE_TOKEN_REMOVED, 200, true)`.

| Property | Value |
| --- | --- |
| HTTP status | `200` |
| Content-Type | `application/json` |
| Envelope | `{status: number, message: string, success: boolean}` |
| `status` | Always present. Integer `200` |
| `message` | Always present. String. Translated `MESSAGE.DEVICE_TOKEN_REMOVED`: `en` → `"Device token removed"`, `ar` → `"تمت إزالة رمز الجهاز"` |
| `success` | Always present. Boolean `true` |

#### Success response example

```json
{
  "status": 200,
  "message": "Device token removed",
  "success": true
}
```

## Errors

| HTTP status | Trigger | Response body | Message | Field errors | Conditions |
| :--- | :--- | :--- | :--- | :--- | :--- |
| 401 | Missing or invalid Bearer token | `{"message": "Unauthenticated."}` | `"Unauthenticated."` | None | No Sanctum authorization token supplied or token is revoked/expired |
| 422 | Validation failure | `{"message": "The token field is required.", "errors": {"token": [...]}}` | Summary message | Per-field string arrays | Missing `token` field or length > 512 |

## Database Impact

* **Table:** `device_tokens`
* **Operation:** `DELETE FROM device_tokens WHERE token = :token AND user_id = :user_id`
* **Side effects:** The token is completely removed from the database, preventing subsequent FCM broadcasts from reaching this device for this user.

---

# Architecture & Downstream Lifecycle

```
[Mobile Device] 
      │
      │ 1. POST /api/v1/token (Login)
      ▼
[Sanctum Auth Token Returned]
      │
      │ 2. POST /api/v1/general/device-tokens (Register FCM Token)
      ▼
[DeviceTokenController@store] ────► [device_tokens table]
                                            ▲
                                            │ queries tokens
                                            │ for target user_id
                                            │
[Backend Business Events] ────► [SendFcmNotificationJob] ────► [FcmService] ────► [Firebase FCM Push]
```
