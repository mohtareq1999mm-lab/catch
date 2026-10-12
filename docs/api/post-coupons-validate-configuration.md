# Endpoint Name

Coupons – Validate Configuration (`POST /api/v1/coupons/validate-configuration`)

# Description

Dry-runs coupon capacity rules and returns verdicts without persisting anything.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/coupons/validate-configuration` |
| Path composition | `api/v1` prefix + `coupons/validate-configuration` from the `Route::prefix('coupons')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.coupons.validate-config`) in `packages/marvel/src/Rest/Routes.php` (two segments after `coupons`, so no collision with `coupons/{coupon}`) |
| Controller action | `App\Http\Controllers\Api\Admin\CouponConfigurationController@validateConfiguration` → `authorizeAdmin` (view OR update OR create coupon permission — same triple fallback as the rules endpoint); then inline `$request->validate(...)` (→ 422); then PURE computation over three hard rules (public single-use, assigned multi-use guidance, limiter sanity) — nothing is read from or written to the database (code-verified side-effect-free) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group + constructor + manual check) |
| Permission | Any ONE of `view-coupons` / `update-coupon` / `create-coupon` via the inline `authorizeAdmin` (same triple fallback; abort messages verbatim with periods) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.coupons.validate-config` (explicit named route) |

Order matters: 401/403 → 422 (validation) → 200 (always — even for invalid configurations; see the `success` flag).

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `coupon_type` | body | string | Required | No | `required\|in:public,assigned` | `public`, `assigned` | None | None | `"public"` | Configuration model under test |
| `limiter` | body | integer | Optional | Yes | `nullable\|integer\|min:1` | Integer ≥ 1 | `null` (unlimited) | None | `500` | Hypothetical global cap |
| `max_uses_per_user` | body | integer | Optional | No | `nullable\|integer\|min:1` (defaults to `1` when absent — a missing key means single-use, code-verified) | Integer ≥ 1 | `1` | None | `1` | Hypothetical per-customer quota |

No endpoint test found — contracts code-traced.

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/coupons/validate-configuration",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "coupon_type": "public",
    "limiter": 500,
    "max_uses_per_user": 1
  }
}
```

# Responses

## Success: verdict returned — HTTP 200 (ALWAYS, even for invalid configurations)

Returned via `ApiResponse::apiResponse('Validation completed.', 200, empty($errors), [...])`. WART (code-verified): the HTTP status is 200 AND `success` is `false` when `errors` is non-empty — clients MUST inspect `success`/`data.valid`, not the status code. The message is a LITERAL string (not a constant — passes through `translateNotice` unchanged).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` (even when `valid: false`) |
| `message` | string | Always present | Literal (untranslated) | Always `"Validation completed."` |
| `success` | boolean | Always present | EQUALS `data.valid` (false when errors exist — code-verified coupling) | `true`/`false` |
| `data` | object | Always present on success | Verdict payload | Keys below |
| `data.valid` | boolean | Always present | True when `errors` is empty | `true`/`false` |
| `data.errors` | array | Always present (possibly empty) | Blocking findings (`{field, message}`; verified literal: public + `max_uses_per_user > 1` → `{field: max_uses_per_user, message: 'Public coupons only support single use per customer.'}`) | Array of `{field, message}` |
| `data.warnings` | array | Always present (possibly empty) | Non-blocking findings (verified literals: assigned without limiter; public limiter > 1000) | Array of `{field, message}` |
| `data.recommendations` | array | Always present (possibly empty) | Behavior guidance (`{title, description}` — verified literals per branch) | Array of `{title, description}` |

```json
{
  "status": 200,
  "message": "Validation completed.",
  "success": true,
  "data": {
    "valid": true,
    "errors": [],
    "warnings": [],
    "recommendations": [
      {
        "title": "Public Coupon Behavior",
        "description": "Each customer can redeem this coupon exactly once. Global capacity: 500 total redemptions across all customers."
      }
    ]
  }
}
```

Invalid-configuration variant (still HTTP 200 — `success: false`):

```json
{
  "status": 200,
  "message": "Validation completed.",
  "success": false,
  "data": {
    "valid": false,
    "errors": [
      {
        "field": "max_uses_per_user",
        "message": "Public coupons only support single use per customer."
      }
    ],
    "warnings": [],
    "recommendations": [
      {
        "title": "Public Coupon Behavior",
        "description": "Each customer can redeem this coupon exactly once. Global capacity: unlimited total redemptions across all customers."
      }
    ]
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. Business-rule violations are NOT errors here (they are 200 verdicts above) — only auth/validation/rate-limit produce error statuses.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token (`abort(401, 'Unauthenticated.')` — with period) | `{message: string, status: boolean}` | None | `"Unauthenticated."` (verbatim) | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller holds NONE of `view-coupons`/`update-coupon`/`create-coupon` | `{message: string, status: boolean}` | None | `"Forbidden. Missing required permission: view-coupons."` (verbatim) | None | Valid token without any of the three (code-traced) | See 403 example |
| 422 | Inline `$request->validate` fails (missing/invalid `coupon_type`, `limiter: 0`, non-integer quotas) | `{message: string, status: boolean, errors: object}` (Handler validation shape — NOT the verdict shape) | None | Laravel default | Field → messages | Invalid body (code-traced) | See 422 example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded | See 429 example |

401 example (note the period):

```json
{
  "message": "Unauthenticated.",
  "status": false
}
```

403 example (verbatim):

```json
{
  "message": "Forbidden. Missing required permission: view-coupons.",
  "status": false
}
```

422 example:

```json
{
  "message": "The coupon type field is required.",
  "status": false,
  "errors": {
    "coupon_type": ["The coupon type field is required."]
  }
}
```

429 example:

```json
{
  "success": false,
  "message": "Too many requests. Please try again later.",
  "data": null
}
```
