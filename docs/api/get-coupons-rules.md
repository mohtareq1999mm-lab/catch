# Endpoint Name

Coupons – Rule Catalog (`GET /api/v1/coupons/rules`)

# Description

Returns the static targeting rule catalog and grammar for the admin builder with no coupon read.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/coupons/rules` |
| Path composition | `api/v1` prefix + `coupons/rules` custom `Route::get(...)->name('api.admin.coupons.rules')` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group with NO route-level permission middleware (route-inventory confirms group middleware only). Registered BEFORE `apiResource('coupons', ...)` precisely so `rules` is not captured as `coupons/{coupon}` show (source comment verified — removing/reordering it would shadow this endpoint behind the show action) |
| Controller action | `App\Http\Controllers\Api\Admin\CouponRulesController@show` (constructor `auth:sanctum` + manual `abort(401)` belt-and-braces) → inline authorization: `view-coupons` OR `update-coupon` OR `create-coupon` (via `hasPermissionTo`, else `can`, else admin-type WITHOUT `hasPermissionTo` passes — three-tier fallback, code-verified); failure → `abort(403, 'Forbidden. Missing required permission: view-coupons.')` (verbatim message through `Handler`'s `HttpException` branch); success → static `CouponRuleMetadata::all()` + `grammar()` (no customer data read — fully static, code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group + constructor + manual check) |
| Permission | Any ONE of `view-coupons` / `update-coupon` / `create-coupon` via the inline check (NOT a middleware — enforced in-method; code-verified triple fallback) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.coupons.rules` (explicit named route — code-verified) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |

No path/query/body parameters and no `422` contract. No endpoint test found — contracts code-traced.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/coupons/rules",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: catalog returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ['rules' => ..., 'rule_tree' => ...])`. Rule identifiers come from `EligibilityRuleType` (same enum as `RuleTreeValidator`/eligibility engine); labels are display-only en/ar companions, NEVER validation input.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` | `en` → `"Data fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Static catalog (keys verified; per-rule item internals follow the enum and are summarized, not exhaustively pinned) | `rules` (array, one entry per `EligibilityRuleType` case), `rule_tree` (grammar object) |
| `data.rules[]` | object | Always present (possibly empty only if the enum is empty) | One rule descriptor | e.g. label/description en+ar, value type/requirement/example, min, context (exact per-rule keys follow `CouponRuleMetadata::for` — summarized, **Unverified in source** at item level) |
| `data.rule_tree` | object | Always present on success | Grammar descriptor (keys verified verbatim in source) | `supported: true`, `operators: ["AND", "OR"]`, `max_depth` (from `RuleTreeValidator::MAX_DEPTH`), `nested_groups_allowed: true`, `empty_group_allowed: false`, `null_allowed: true`, `duplicate_rules_allowed: true`, `unknown_rule_behavior: "reject_422"`, `malformed_node_behavior: "reject_422"` |

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "rules": [],
    "rule_tree": {
      "supported": true,
      "operators": ["AND", "OR"],
      "max_depth": 10,
      "nested_groups_allowed": true,
      "empty_group_allowed": false,
      "null_allowed": true,
      "duplicate_rules_allowed": true,
      "unknown_rule_behavior": "reject_422",
      "malformed_node_behavior": "reject_422"
    }
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`. NOTE the abort messages carry trailing periods (verbatim `HttpException` messages, NOT translated keys — code-verified distinction from the `Unauthenticated`-without-period Handler shape).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token (manual `abort(401, 'Unauthenticated.')` — WITH trailing period, unlike the Handler shape) | `{message: string, status: boolean}` | None | `"Unauthenticated."` (verbatim abort message with period) | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller holds NONE of `view-coupons`/`update-coupon`/`create-coupon` (all three fallback tiers fail) | `{message: string, status: boolean}` | None | `"Forbidden. Missing required permission: view-coupons."` (verbatim abort message with period) | None | Valid token without any of the three permissions (code-traced) | See 403 example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded | See 429 example |

401 example (note the period):

```json
{
  "message": "Unauthenticated.",
  "status": false
}
```

403 example (verbatim, with period):

```json
{
  "message": "Forbidden. Missing required permission: view-coupons.",
  "status": false
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
