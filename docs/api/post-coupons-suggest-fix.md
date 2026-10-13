# Endpoint Name

Coupons – Suggest Fix (`POST /api/v1/coupons/{id}/suggest-fix`)

# Description

Returns read-only, actionable steps toward a desired per-customer usage behavior without modifying anything.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/coupons/{id}/suggest-fix` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route → 404 fixed route-miss shape) |
| Path composition | `api/v1` prefix + `coupons/{id}/suggest-fix` from the `Route::prefix('coupons')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.coupons.suggest-fix`, route middleware `permission:view-coupons|update-coupon|create-coupon` — working-tree addition that runs BEFORE the inline check) in `packages/marvel/src/Rest/Routes.php` |
| Controller action | `App\Http\Controllers\Api\Admin\CouponConfigurationController@suggestFix($couponId, Request $request)` (NOTE the reversed parameter order — route param FIRST — works via Laravel's type-hint resolution; code-verified) → `authorizeAdmin` (triple-permission fallback); `findOrFail` miss escapes → 404 fixed string; `desired_behavior` outside the pair → 400 `'Invalid desired behavior.'` + `allowed_values`; otherwise one of FIVE read-only suggestion shapes (nothing is modified — applying a suggestion is a separate call through the referenced assignment APIs; removal is deliberately never recommended since it affects eligibility/claims/history/notifications — code-verified domain rule) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group + constructor + manual check) |
| Permission | Any ONE of `view-coupons` / `update-coupon` / `create-coupon` via route middleware FIRST, then inline `authorizeAdmin` (same triple fallback; the inline verbatim abort is now unreachable for permissionless callers since the sets match) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.coupons.suggest-fix` (explicit named route) |

Order matters: 401/403 → 404 (miss) → 400 (bad behavior) → 200 (suggestion).

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `id` | path | integer | Required | No | `whereNumber('id')` | Existing coupon id | None | None | `1` | Identifies the coupon |
| `desired_behavior` | body | string | Required (business) | No | Strict `in_array(..., ['multi_use_per_user', 'single_use_per_user'], true)` — anything else (including absent) → 400 (NOT a `FormRequest` 422 — an in-method 400; code-verified) | `multi_use_per_user`, `single_use_per_user` | None | None | `"multi_use_per_user"` | The per-customer behavior the admin wants |

No endpoint test found — contracts code-traced.

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/coupons/1/suggest-fix",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "desired_behavior": "multi_use_per_user"
  }
}
```

# Responses

## Success: suggestion generated — HTTP 200

Returned via `ApiResponse::apiResponse('Suggestion generated.', 200, true, [...])` (literal message). The `recommended_action` enum is CLOSED (code-verified — all five branches enumerated): `convert_to_assigned` (public → multi-use: first assignment flips publicity, past public usage still counts), `update_assignments` (assigned, wrong limit direction), `no_change_needed` (already matching, three variants). Every shape carries `current_state`, `recommended_action`, `summary`, `steps[]`, `expected_result`, `warnings[]` (always plus claim/targeting-compatibility notes when applicable), `available_actions[]` (`{label, method, path}` pointing at the assignment endpoints). Steps never expose PHP/Eloquent/SQL details (code-verified business-guidance-only rule).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Literal (untranslated) | Always `"Suggestion generated."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Suggestion payload (keys verified on all five branches) | `current_state` (string), `recommended_action` (enum below), `summary` (string), `steps` (string[]), `expected_result` (string), `warnings` (string[]), `available_actions` (`{label, method, path}`[]) |
| `data.recommended_action` | string | Always present | Closed action enum | `convert_to_assigned`, `update_assignments`, `no_change_needed` |

```json
{
  "status": 200,
  "message": "Suggestion generated.",
  "success": true,
  "data": {
    "current_state": "This coupon is currently public and has no customer assignments. Public coupons allow one use per customer.",
    "recommended_action": "convert_to_assigned",
    "summary": "To give each customer an individual usage limit, the coupon must use customer-specific assignments.",
    "steps": [
      "Select the customers who should receive this coupon.",
      "Create a coupon assignment for each selected customer.",
      "Set the maximum number of uses for each customer.",
      "Verify that the assignments are active and check their expiration settings.",
      "The selected customers can then use the coupon up to their individual limit."
    ],
    "expected_result": "Each assigned customer receives an independent usage limit.",
    "warnings": [
      "Creating the first assignment converts this coupon from public to assigned. Customers who are not assigned will no longer be able to use it.",
      "Past public usage history is preserved and still counts against returning customers."
    ],
    "available_actions": [
      {
        "label": "Create coupon assignment",
        "method": "POST",
        "path": "/api/v1/coupons/1/assignments"
      },
      {
        "label": "List coupon assignments",
        "method": "GET",
        "path": "/api/v1/coupons/1/assignments"
      }
    ]
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. There is deliberately no 422 (the behavior check is an in-method 400, not validation).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (verbatim, with period) | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller holds NONE of the three permissions (route pipe rejects first) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS`: `en` → `"User does not have the required permissions."` | None | Valid token without any of the three (code-traced) | See 403 example |
| 404 | No coupon with `{id}` (`findOrFail` escapes) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
| 400 | `desired_behavior` missing or outside the pair (strict comparison) | `{status: integer, message: string, success: boolean, data: object}` (extra `data.allowed_values` — code-verified) | None | `"Invalid desired behavior."` (literal) | None | Bad/absent behavior (code-traced) | See 400 example |
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
  "message": "User does not have the required permissions.",
  "status": false
}
```

404 example:

```json
{
  "message": "Resource Not Found",
  "status": false
}
```

404-constraint example:

```json
{
  "message": "Not Found",
  "status": false
}
```

400 example (literal message + allowed values):

```json
{
  "status": 400,
  "message": "Invalid desired behavior.",
  "success": false,
  "data": {
    "allowed_values": ["multi_use_per_user", "single_use_per_user"]
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
