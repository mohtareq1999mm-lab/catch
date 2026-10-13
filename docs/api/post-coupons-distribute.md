# Endpoint Name

Coupons – Start Distribution (`POST /api/v1/coupons/{id}/distribute`)

# Description

Starts a manual coupon distribution run asynchronously and returns 202 with counters, or 409 when busy.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/coupons/{id}/distribute` (explicit `{id}` placeholder with `whereNumber('id')`) |
| Path composition | `api/v1` prefix + `coupons/{id}/distribute` from the `Route::prefix('coupons')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (NO route name — code-verified unnamed, unlike its siblings; route middleware `permission:update-coupon` — working-tree addition that runs BEFORE the inline check, matching the config default) in `packages/marvel/src/Rest/Routes.php`. NOTE: the source comment calls this a "RabbitMQ event backbone" — STALE wording (RabbitMQ has been removed from this project and MUST NOT be reintroduced; the 202-accepted semantics below are transport-agnostic and verified in code) |
| Controller action | `App\Http\Controllers\Api\Admin\CouponDistributionAdminController@distribute(Request $request, int $id)` → `authorizeAdmin` (`update-coupon`, configurable via `coupon-distribution.admin_permission`); inline `$request->validate` (trigger/audience_cap → 422); coupon miss → 404 translated `COUPON_NOT_FOUND`; `DistributionService::startDistribution` (MANUAL trigger, `admin:{id}` actor, audience cap); non-distributable → 422 translated `COUPON_NOT_ELIGIBLE` + `reason: not_distributable`; pending/running run for the SAME targeting version → 409 with the live run (double-submit safe — no duplicate mass distribution, code-verified); else 202 with the fresh run. No PII in runs (counters only — code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group + constructor + manual check) |
| Permission | `update-coupon` (config key `coupon-distribution.admin_permission`, default `update-coupon`) via route middleware FIRST, then inline `authorizeAdmin` — explicit and source-documented (the inline verbatim abort is now unreachable for permissionless callers while the config matches the route; if the config ever changes without the route, the route wins) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` when a body is sent |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` (both fields optional — an empty body is valid) |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | None (unnamed — code-verified; siblings are named) |

Order matters: 401/403 → 422 (validation) → 404 (miss) → 422/409/202 (outcome).

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (recorded as `admin:{id}` actor) |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Existing coupon id | None | None | `1` | Identifies the coupon |
| `trigger` | body | string | Optional | No | `sometimes\|string\|in:manual` (only `manual` accepted — any other value 422s; code-verified) | `manual` | Service default | None | `"manual"` | Distribution trigger |
| `audience_cap` | body | integer | Optional | No | `sometimes\|integer\|min:1\|max:{coupon-distribution.max_audience_cap, default 100000}` | `1`–`100000` (config-dependent) | `coupon-distribution.default_audience_cap` (default `10000`) | None | `5000` | Max audience for this run |

No endpoint test found — contracts code-traced.

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/coupons/1/distribute",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "trigger": "manual",
    "audience_cap": 5000
  }
}
```

# Responses

## Success: run accepted — HTTP 202 (NOT 200)

Returned via `ApiResponse::apiResponse('Distribution run accepted.', 202, true, ['run' => presentRun($run->fresh())])` (literal message). The run object carries counters only (no PII — code-verified); status is the enum value string.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `202` on accept (code-verified non-200 success) |
| `message` | string | Always present | Literal (untranslated) | Always `"Distribution run accepted."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on accept |
| `data` | object | Always present on accept | Accept payload | `run` (object — keys verified verbatim in `presentRun`) |
| `data.run` | object | Always present on accept | Run counters + lifecycle | `id`, `coupon_id`, `trigger_type`, `tree_hash`, `status` (string), `candidate_count`, `eligible_count`, `not_eligible_count`, `notified_count`, `failed_count`, `duplicate_skipped_count`, `started_at`, `finished_at` (ISO or null) |

```json
{
  "status": 202,
  "message": "Distribution run accepted.",
  "success": true,
  "data": {
    "run": {
      "id": 1,
      "coupon_id": 1,
      "trigger_type": "manual",
      "tree_hash": "abc123",
      "status": "pending",
      "candidate_count": 5000,
      "eligible_count": 0,
      "not_eligible_count": 0,
      "notified_count": 0,
      "failed_count": 0,
      "duplicate_skipped_count": 0,
      "started_at": null,
      "finished_at": null
    }
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (verbatim, with period) | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks `update-coupon` (route pipe rejects first; permission name follows config by default) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS`: `en` → `"User does not have the required permissions."` | None | Valid token without the permission (code-traced) | See 403 example |
| 404 | No coupon with `{id}` (explicit `ModelNotFoundException` catch → translated coupon key — unlike the targeting/show fixed-string path) | `{status: integer, message: string, success: boolean}` | None | Translated `ERROR.COUPON_NOT_FOUND`: `en` → `"Coupon not found"` | None | Unknown numeric id (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
| 405 | `PUT`/`PATCH`/`GET` on this path (only `POST` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong method (code-traced) | See 405 example |
| 422 | Inline validation fails (unknown `trigger`, `audience_cap: 0` or over max) | `{message: string, status: boolean, errors: object}` (Handler validation shape) | None | Laravel default | Field → messages | Invalid body (code-traced) | See 422-validation example |
| 422 | Coupon not distributable (`NonDistributableCouponException` — e.g. untargeted/ineligible state; exact engine conditions **Unverified in source** beyond the catch) | `{status: integer, message: string, success: boolean, data: object}` (extra `data.reason` — code-verified) | None | Translated `ERROR.COUPON_NOT_ELIGIBLE`: `en` → `"You are not eligible for this coupon."` (coupon-worded message for an admin action — the `COUPON_NOT_ELIGIBLE ?? SOMETHING_WENT_WRONG` expression always resolves left since the constant is defined; code-verified) | None | Non-distributable coupon (code-traced) | See 422-engine example |
| 409 | A pending/running run already exists for the SAME targeting version (double-submit guard — returns the LIVE run instead of duplicating; other statuses do NOT collide, code-verified) | `{status: integer, message: string, success: boolean, data: object}` (extra `data.reason` + `data.run` — code-verified) | None | `"A distribution run is already in progress for this targeting version."` (literal) | None | Concurrent/double submit (code-traced) | See 409 example |
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
  "status": 404,
  "message": "Coupon not found",
  "success": false
}
```

404-constraint example:

```json
{
  "message": "Not Found",
  "status": false
}
```

405 example:

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422-validation example:

```json
{
  "message": "The selected trigger is invalid.",
  "status": false,
  "errors": {
    "trigger": ["The selected trigger is invalid."]
  }
}
```

422-engine example:

```json
{
  "status": 422,
  "message": "You are not eligible for this coupon.",
  "success": false,
  "data": {
    "reason": "not_distributable"
  }
}
```

409 example (live run embedded):

```json
{
  "status": 409,
  "message": "A distribution run is already in progress for this targeting version.",
  "success": false,
  "data": {
    "reason": "already_running",
    "run": {
      "id": 1,
      "coupon_id": 1,
      "trigger_type": "manual",
      "tree_hash": "abc123",
      "status": "running",
      "candidate_count": 5000,
      "eligible_count": 100,
      "not_eligible_count": 10,
      "notified_count": 50,
      "failed_count": 0,
      "duplicate_skipped_count": 5,
      "started_at": "2026-01-01T00:00:00+00:00",
      "finished_at": null
    }
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
