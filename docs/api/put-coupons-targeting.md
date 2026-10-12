# Endpoint Name

Coupons – Upsert Targeting (`PUT /api/v1/coupons/{id}/targeting`)

# Description

Creates or replaces one coupon's targeting row under fail-closed tree validation and returns 200.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` (only `PUT` is registered — `PATCH` on this path → 405; code-verified) |
| Full path | `/api/v1/coupons/{id}/targeting` (explicit `{id}` placeholder with `whereNumber('id')`) |
| Path composition | `api/v1` prefix + `coupons/{id}/targeting` from the `Route::prefix('coupons')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.coupons.targeting.upsert`) in `packages/marvel/src/Rest/Routes.php` |
| Controller action | `App\Http\Controllers\Api\Admin\CouponTargetingController@upsert(UpsertTargetingRequest $request, int $id)` → `authorizeWrite` (update-coupon OR create-coupon — view-only FAILS here; the source-commented BLOCKER fix); `findOrFail` miss escapes → 404 fixed; `RuleTreeValidator::validate` fail-closed (`unknown → reject_422`, per the catalog grammar) → 422 `COULD_NOT_UPDATE_THE_RESOURCE` with `data.errors`; then transactional `updateOrCreate` + `CouponTargetingChanged` event + discovery-cache invalidation (side effects — a new targeting version re-opens distribution evaluation, code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group + constructor + manual check) |
| Permission | WRITE split: `update-coupon` OR `create-coupon` via inline `authorizeWrite` (view-only is NOT enough — code-verified, source-commented; type=admin WITHOUT an explicit permission is also NOT enough for writes, unlike reads) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.coupons.targeting.upsert` (explicit named route) |

Order matters: 401/403 → 404 (miss) → 422 (validation or tree) → 200. NOTE `UpsertTargetingRequest::authorize` returns `user() !== null`, but `authorizeWrite` already ran first — the request gate never fires independently (code-verified dead-ish gate).

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Existing coupon id | None | None | `1` | Identifies the coupon |
| `mode` | body | string | Required | No | `required\|string\|in:assignment,dynamic,assignment_and_dynamic,assignment_or_dynamic` | The four modes | None | None | `"dynamic"` | Eligibility evaluation mode (assignment mode ignores `rule_tree` at runtime but still stores it as given — code-verified) |
| `require_claim` | body | boolean | Required | No | `required\|boolean` | `true`, `false` | None | None | `false` | Whether customers must claim before use |
| `max_claims` | body | integer | Optional | Yes | `nullable\|integer\|min:1\|max:1000000` | `1`–`1000000` or null | `null` | None | `1000` | Total claim cap |
| `claim_ttl_hours` | body | integer | Optional | Yes | `nullable\|integer\|min:1\|max:8760` (≤ 1 year) | `1`–`8760` or null | `null` | None | `72` | Claim time-to-live |
| `rule_tree` | body | object/null | Optional | Yes | `nullable\|array` + fail-closed `RuleTreeValidator` (unknown rules and malformed nodes reject; null with dynamic-family modes means "no rules" = eligible — code-verified) | Valid tree or null | `null` | None | `null` | Eligibility rule tree |

No endpoint test found — contracts code-traced.

## Request examples

```json
{
  "method": "PUT",
  "url": "/api/v1/coupons/1/targeting",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "mode": "dynamic",
    "require_claim": false,
    "max_claims": null,
    "claim_ttl_hours": null,
    "rule_tree": null
  }
}
```

# Responses

## Success: targeting upserted — HTTP 200

Returned via `ApiResponse::apiResponse(UPDATED_COUPON_SUCCESSFULLY, 200, true, CouponTargetingResource::make($targeting->fresh()))` (generic update message, NOT a targeting-specific key — code-verified).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.UPDATED_COUPON_SUCCESSFULLY` | `en` → `"Coupon updated successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh `CouponTargetingResource` | Same key set as the show response |

```json
{
  "status": 200,
  "message": "Coupon updated successfully",
  "success": true,
  "data": {
    "id": 1,
    "coupon_id": 1,
    "mode": "dynamic",
    "require_claim": false,
    "max_claims": null,
    "claim_ttl_hours": null,
    "rule_tree": null,
    "created_at": "2026-01-01T00:00:00+00:00",
    "updated_at": "2026-01-01T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (verbatim, with period) | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller lacks BOTH `update-coupon` and `create-coupon` (view-only fails WRITES — the BLOCKER fix; admin-type without explicit permission also fails) | `{message: string, status: boolean}` | None | `"Forbidden. Missing required permission: update-coupon."` (verbatim — note `update-coupon`, not `view-coupons`) | None | View-only or permissionless token (code-traced) | See 403 example |
| 404 | No coupon with `{id}` (`findOrFail` escapes) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id with valid body (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
| 405 | `PATCH`/`POST` on this path (only `PUT` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong method (code-traced) | See 405 example |
| 422 | `UpsertTargetingRequest` fails (missing/unknown `mode`, non-boolean `require_claim`, out-of-range caps) | `{message: string, status: boolean, errors: object}` (Handler validation shape) | None | Laravel default | Field → messages | Invalid body (code-traced) | See 422-validation example |
| 422 | `rule_tree` fails fail-closed validation (unknown rule or malformed node — admin input never creates an open gate) | `{status: integer, message: string, success: boolean, data: object}` (custom shape: `data.errors` ARRAY, not a field map — code-verified) | None | Translated `ERROR.COULD_NOT_UPDATE_THE_RESOURCE`: `en` → `"Could not update the resource"` | N/A (array, not a map) | Invalid tree on an existing coupon (code-traced) | See 422-tree example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded | See 429 example |

401 example (note the period):

```json
{
  "message": "Unauthenticated.",
  "status": false
}
```

403 example (verbatim — `update-coupon`):

```json
{
  "message": "Forbidden. Missing required permission: update-coupon.",
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
  "message": "The mode field is required.",
  "status": false,
  "errors": {
    "mode": ["The mode field is required."]
  }
}
```

422-tree example (custom `data.errors` array):

```json
{
  "status": 422,
  "message": "Could not update the resource",
  "success": false,
  "data": {
    "errors": ["Unknown rule type: loyalty_tier."]
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
