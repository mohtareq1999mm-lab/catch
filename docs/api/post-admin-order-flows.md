# Endpoint Name

Order Flows – Create Flow (`POST /api/v1/admin/order-flows`)

# Description

Creates one flow row with ordered statuses and single-default enforcement, returning 201.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/order-flows` |
| Path composition | `api/v1` prefix + `admin/order-flows/` from the `Route::prefix('admin/order-flows')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.order-flows.store`) in `packages/marvel/src/Rest/Routes.php` |
| Controller action | `App\Http\Controllers\Api\Admin\OrderFlowController@store(OrderFlowUpsertRequest $request)` → in-method REQUIRED quartet (`code/name/shipping_type/status_ids` must ALL be present — `sometimes` rules alone would allow partials, so the explicit `isset` check 422s first; code-verified belt-and-braces); `validateFlowStatuses` (empty → 422, duplicates → 422, unknown ids → 422, INACTIVE status → 422 with code — all translated `checkout.*` messages); transactional create (`is_default` defaults `false`, `is_active` defaults `true`) + position-ordered status sync (`sort_order` 1..N from ARRAY order — From/To derived, never input) + `enforceSingleDefault` (new active default DEMOTES the previous one — never deleted; concurrent-writer double-default is a documented known limitation, code-verified comment) → 201 |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group + constructor) |
| Permission | Any ONE of `create-order-flows` / `update-order-status` via the route pipe (legacy status permission accepted on the write path — code-verified; no constructor/inline check exists) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.order-flows.store` (explicit named route) |

Order matters: 401/403 → 422 (validation) → 422 (quartet/statuses) → 201.

## Request inputs

`OrderFlowUpsertRequest` runs before the body (legacy string `name` normalized to `{en}` pre-validation; `code` unique; `shipping_type` in `local|international` + unique per flow — a THIRD type is rejected here AND at checkout AND at resolution, test-verified by `test_third_shipping_type_is_rejected_everywhere`).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `code` | body | string | Required (business) | No | `sometimes\|required\|string\|max:50\|unique:order_flows,code` (+ in-method `isset` — both layers must pass) | Unique ≤ 50 chars | None | None | `"same_day_flow"` | Flow code (rejected when third-type testing uses `same_day` as the TYPE — the code itself is free-form) |
| `name` | body | object/string | Required (business) | No | `sometimes\|required` (+ `name.en: sometimes\|required\|string\|max:100`, `name.ar: nullable\|string\|max:100`); plain strings auto-wrapped to `{en}` pre-validation (code-verified legacy path) | Bilingual object or legacy string | None | None | `{"en": "Same Day", "ar": "نفس اليوم"}` | Localized name (stored bilingual JSON) |
| `shipping_type` | body | string | Required (business) | No | `sometimes\|required\|string\|max:30\|in:local,international\|unique:order_flows,shipping_type` (ONE row per type — the type IS the mapping; code-verified) | `local`, `international` | None | None | `"international"` | Flow identity (immutable after create — see update doc) |
| `status_ids` | body | array of integers | Required (business) | No | `sometimes\|required\|array\|min:1`, per-element `integer\|exists:order_statuses,id` + service rules (non-empty, distinct, all known, all ACTIVE — code-verified `validateFlowStatuses`) | Active status ids, no duplicates | None | None | `[1, 2, 3]` | Transition map (ARRAY ORDER = positions 1..N) |
| `is_default` | body | boolean | Optional | No | `sometimes\|required\|boolean` (when present must be a real boolean) | `true`, `false` | `false` | None | `false` | Default flag (demotes the previous default when active) |
| `is_active` | body | boolean | Optional | No | `sometimes\|required\|boolean` | `true`, `false` | `true` | None | `true` | Active flag |

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/admin/order-flows",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "code": "eu_flow",
    "name": { "en": "EU Flow", "ar": "تدفق الاتحاد الأوروبي" },
    "shipping_type": "international",
    "status_ids": [1, 2, 3]
  }
}
```

# Responses

## Success: flow created — HTTP 201

Returned via `ApiResponse::apiResponse('Order flow created successfully.', 201, true, (new OrderFlowResource($flow))->toArray(request()))` (literal message; resource ARRAY — not the resource object — code-verified serialization choice).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `201` on success |
| `message` | string | Always present | Literal (untranslated) | Always `"Order flow created successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | `OrderFlowResource` array with `statuses` loaded (positioned rows) | `id`, `code`, `name` (localized), `shipping_type`, `is_default`, `is_active`, `statuses[{id, code, name, is_active, sort_order}]`, `created_at`, `updated_at` |

```json
{
  "status": 201,
  "message": "Order flow created successfully.",
  "success": true,
  "data": {
    "id": 3,
    "code": "eu_flow",
    "name": { "en": "EU Flow" },
    "shipping_type": "international",
    "is_default": false,
    "is_active": true,
    "statuses": [],
    "created_at": "2026-01-01T00:00:00+00:00",
    "updated_at": "2026-01-01T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. All 422s here render `{status, message, success}` with NO `errors` map (in-method responses, NOT validation — code-verified shape except the validation row).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller holds NEITHER `create-order-flows` NOR `update-order-status` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without either (code-traced) | See 403 example |
| 422 | `OrderFlowUpsertRequest` fails (bad `code`/`shipping_type`/duplicates/unknown `status_ids`, third type `same_day`) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body (third-type rejection verified by `test_third_shipping_type_is_rejected_everywhere` → 422 with zero rows created) | See 422-validation example |
| 422 | Quartet incomplete (`code`/`name`/`shipping_type`/`status_ids` any-missing — the `sometimes`-rules loophole closed in-method) | `{status: integer, message: string, success: boolean}` (NO `errors` map) | None | `"code, name, shipping_type and status_ids are required."` (literal WITH period) | None | Partial body passing validation but missing a member (code-traced) | See 422-quartet example |
| 422 | `status_ids` fail service rules (empty/duplicates/unknown/inactive — translated `checkout.*` texts) | `{status: integer, message: string, success: boolean}` (NO `errors` map) | None | Live translated text (e.g. inactive-member message with the status code) | None | Semantically bad member list (inactive case verified by `test_inactive_status_cannot_join_flow` → 422 on the update twin; same service method) | See 422-statuses example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded | See 429 example |

401 example:

```json
{
  "message": "Unauthenticated",
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

422-validation example:

```json
{
  "message": "The shipping type field must be one of local, international.",
  "status": false,
  "errors": {
    "shipping_type": ["The shipping type field must be one of local, international."]
  }
}
```

422-quartet example (literal WITH period, no map):

```json
{
  "status": 422,
  "message": "code, name, shipping_type and status_ids are required.",
  "success": false
}
```

422-statuses example (translated service text, no map):

```json
{
  "status": 422,
  "message": "Translated status-membership refusal (exact text from checkout.* lang lines)",
  "success": false
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
