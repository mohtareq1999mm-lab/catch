# Endpoint Name

Coupons – Show Distribution Run (`GET /api/v1/coupons/{id}/distributions/{runId}`)
    
# Description
    
    Returns one distribution run scoped to its coupon with a recipient status breakdown.
    
# Request
    
    ## Request metadata
    
    | Property | Value |
    | --- | --- |
    | HTTP method | `GET` |
    | Full path | `/api/v1/coupons/{id}/distributions/{runId}` (explicit placeholders with `whereNumber(['id', 'runId'])` — non-numeric segments match NO route → 404 route-miss shape) |
    | Path composition | `api/v1` prefix + `coupons/{id}/distributions/{runId}` from the `Route::prefix('coupons')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (NO route name — code-verified unnamed; route middleware `permission:update-coupon` — working-tree addition that runs BEFORE the inline check) in `packages/marvel/src/Rest/Routes.php` |
    | Controller action | `App\Http\Controllers\Api\Admin\CouponDistributionAdminController@show(Request $request, int $id, int $runId)` → `authorizeAdmin` (`update-coupon`); scoped `CouponDistributionRun::where('coupon_id', $id)->findOrFail($runId)` — a VALID run id under the WRONG coupon misses identically (cross-coupon reads impossible by construction — code-verified scoping, same pattern as assignment show); miss → 404 translated `COUPON_NOT_FOUND` (explicit catch — the run-miss shares the coupon message, code-verified conflation); then `recipients` grouped `status → count` breakdown (aggregate counts only, no PII — code-verified) |
    | Authentication | Required. Sanctum bearer token via `auth:sanctum` (group + constructor + manual check) |
    | Permission | `update-coupon` (config key `coupon-distribution.admin_permission`) via route middleware FIRST, then inline `authorizeAdmin` (the inline verbatim abort is now unreachable for permissionless callers while the config matches the route) |
    | Required headers | `Authorization: Bearer <sanctum-token>` |
    | Recommended headers | `Accept: application/json` |
    | Optional headers | `lang: en\|ar` (default `en`) |
    | Supported request formats | No body is read; `Content-Type` is irrelevant |
    | Rate limit | `throttle:admin`: 400 requests/minute |
    | Route name | None (unnamed — code-verified) |
    
    ## Request inputs
    
    | Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
    | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
    | `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
    | `lang` | header | string | Optional | No | In `['en', 'ar']` | `en`, `ar` | `en` | None | `en` | Selects message locale |
    | `id` | path | integer | Required | No | `whereNumber` pair + `int $id` | Owning coupon id | None | None | `1` | Scopes the lookup |
    | `runId` | path | integer | Required | No | `whereNumber` pair + `int $runId` | Run id under `{id}` | None | None | `1` | Identifies the run |
    
    No query parameters, body fields, or `422` contract. No endpoint test found — contracts code-traced.
    
    ## Request examples
    
    ```json
    {
      "method": "GET",
      "url": "/api/v1/coupons/1/distributions/1",
      "headers": {
        "Authorization": "Bearer 1|abcdef123456",
        "Accept": "application/json",
        "lang": "en"
      }
    }
    ```
    
# Responses
    
    ## Success: run with breakdown — HTTP 200
    
    Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, ['run' => presentRun($run), 'recipient_breakdown' => $breakdown])`.
    
    | Field | Data type | Presence | Meaning | Possible values / notes |
    | --- | --- | --- | --- | --- |
    | `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
    | `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` | `en` → `"Data fetched successfully"` |
    | `success` | boolean | Always present | Outcome flag | Always `true` on success |
    | `data` | object | Always present on success | Run payload (keys verified) | `run` (presentation object — same 14 keys as distribute), `recipient_breakdown` (map) |
    | `data.recipient_breakdown` | object | Always present (possibly empty) | `recipients` grouped `status → COUNT(*)` (aggregate counts only — code-verified, no recipient rows) | e.g. `{"notified": 50, "failed": 2}` (status keys are engine values, **Unverified in source** beyond the query) |
    
    ```json
    {
      "status": 200,
      "message": "Data fetched successfully",
      "success": true,
      "data": {
        "run": {
          "id": 1,
          "coupon_id": 1,
          "trigger_type": "manual",
          "tree_hash": "abc123",
          "status": "finished",
          "candidate_count": 5000,
          "eligible_count": 4800,
          "not_eligible_count": 200,
          "notified_count": 4700,
          "failed_count": 100,
          "duplicate_skipped_count": 50,
          "started_at": "2026-01-01T00:00:00+00:00",
          "finished_at": "2026-01-01T01:00:00+00:00"
        },
        "recipient_breakdown": {
          "notified": 4700,
          "failed": 100
        }
      }
    }
    ```
    
# Errors
    
    No error-code field; every error below has Error code `None`. No validation, so no `422`.
    
    | HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
    | --- | --- | --- | --- | --- | --- | --- | --- |
    | 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (verbatim, with period) | None | No valid token (code-traced) | See 401 example |
    | 403 | Authenticated caller lacks the configured permission (route pipe rejects first) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS`: `en` → `"User does not have the required permissions."` | None | Valid token without the permission (code-traced) | See 403 example |
    | 404 | No run for the (`id`, `runId`) pair — unknown run, unknown coupon, or right run under the wrong coupon (all identical; the run-miss shares the COUPON message) | `{status: integer, message: string, success: boolean}` | None | Translated `ERROR.COUPON_NOT_FOUND`: `en` → `"Coupon not found"` | None | Unmatched pair (code-traced conflation) | See 404 example |
    | 404 | Non-numeric `{id}` or `{runId}` matches NO route (`whereNumber` pair) | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment (code-verified — unlike the collection sibling, which lacks the constraint) | See 404-constraint example |
    | 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded | See 429 example |
    
    401 example (note the period):
    
    ```json
    {
      "message": "Unauthenticated.",
      "status": false
    }
    ```
    
    403 example:

    ```json
    {
      "message": "User does not have the required permissions.",
      "status": false
    }
    ```
    
    404 example (unmatched pair):
    
    ```json
    {
      "status": 404,
      "message": "Coupon not found",
      "success": false
    }
    ```
    
    404-constraint example (non-numeric segment):
    
    ```json
    {
      "message": "Not Found",
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
