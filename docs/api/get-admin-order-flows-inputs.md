# Endpoint Name

Order Flows – List Flow Inputs (`GET /api/v1/admin/order-flows/{flowId}/inputs`)

# Description

Returns one flow's input definitions ordered for rendering with an optional active filter.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/order-flows/{flowId}/inputs` (explicit `{flowId}` placeholder with `whereNumber('flowId')`) |
| Path composition | `api/v1` prefix + `admin/order-flows/{flowId}/inputs` from the `Route::prefix('admin/order-flows')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.order-flows.inputs.index`) in `packages/marvel/src/Rest/Routes.php` (two segments after `order-flows`, so no collision with `{id}` show) |
| Controller action | `App\Http\Controllers\Api\Admin\FlowInputController@index(Request $request, int $flowId)` (constructor `auth:sanctum` ONLY — no permission middleware; the route pipe alone decides) → `findOrFail` (miss escapes → 404 fixed) → `inputs()->ordered()` with optional `is_active` filter → PLAIN collection array (NOT paginated — no `limit`/`page` honored; code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group + constructor) |
| Permission | Any ONE of `view-order-flows` / `view-orders` / `view-order` via the route pipe (reads ride on flow viewing — source-commented; no constructor/inline check exists) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.order-flows.inputs.index` (explicit named route) |

## Request inputs

No `FormRequest` — one unvalidated filter with the familiar boolean-parsing wart.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `flowId` | path | integer | Required | No | `whereNumber('flowId')` + `int $flowId` | Existing flow id | None | None | `2` | Identifies the flow |
| `is_active` | query | boolean-ish | Optional | No | Applied only when `filled`; `filter_var(..., FILTER_VALIDATE_BOOLEAN)` — unrecognized strings become `false` (code-verified wart shared with flows index and currencies) | `true`/`false`/`1`/`0` | None (no filter) | None | `true` | Active filter |

No `422` contract. NOTE the `data` shape below is a PLAIN ARRAY (not an envelope, not paginated) — code-verified.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/admin/order-flows/2/inputs",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: inputs returned — HTTP 200 (PLAIN array payload)

Returned via `ApiResponse::apiResponse('Flow inputs retrieved successfully.', 200, true, FlowInputResource::collection($inputs)->toArray($request))` (literal message; `data` is the serialized ARRAY directly).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Literal (untranslated) | Always `"Flow inputs retrieved successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | array | Always present (possibly empty) | ORDERED `FlowInputResource` rows (render order = array order; code-verified) | Row: `id`, `flow_id`, `key`, `label{en, ar}` (ALWAYS bilingual — resource contract), `placeholder{en, ar}`, `help_text{en, ar}`, `type` (`text/number/boolean/date/select/multi_select`), `source` (or null), `required` (bool), `required_at`, `sort_order` (int), `validation` (object/null), `is_active` (bool), `created_at`, `updated_at` |

```json
{
  "status": 200,
  "message": "Flow inputs retrieved successfully.",
  "success": true,
  "data": [
    {
      "id": 1,
      "flow_id": 2,
      "key": "delivery_note",
      "label": { "en": "Delivery note", "ar": "ملاحظة التسليم" },
      "placeholder": { "en": null, "ar": null },
      "help_text": { "en": null, "ar": null },
      "type": "text",
      "source": null,
      "required": false,
      "required_at": "checkout",
      "sort_order": 1,
      "validation": null,
      "is_active": true,
      "created_at": "2026-01-01T00:00:00+00:00",
      "updated_at": "2026-01-01T00:00:00+00:00"
    }
  ]
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller holds NONE of the three pipe permissions | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Permissionless user (verified by `test_input_endpoints_require_permission` → GET asserts 403) | See 403 example |
| 404 | No flow with `{flowId}` (`findOrFail` escapes — no translated flow message here) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
| 404 | Non-numeric `{flowId}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
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

429 example:

```json
{
  "success": false,
  "message": "Too many requests. Please try again later.",
  "data": null
}
```
