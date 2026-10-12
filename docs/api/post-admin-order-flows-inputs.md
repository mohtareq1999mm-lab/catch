# Endpoint Name

Order Flows – Bulk Create Inputs (`POST /api/v1/admin/order-flows/{flowId}/inputs`)

# Description

Creates many input definitions atomically with layered guards, returning 201 in request order.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/order-flows/{flowId}/inputs` (explicit `{flowId}` placeholder with `whereNumber('flowId')`) |
| Path composition | `api/v1` prefix + `admin/order-flows/{flowId}/inputs` from the `Route::prefix('admin/order-flows')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.order-flows.inputs.store`) in `packages/marvel/src/Rest/Routes.php` |
| Controller action | `App\Http\Controllers\Api\Admin\FlowInputController@store(FlowInputBulkStoreRequest $request, int $flowId)` → `findOrFail` (miss → 404 fixed; validation ran FIRST, so 422 precedes 404 — code-verified order); then SEVEN sequential gates, ALL pre-persistence (nothing writes until every gate passes — the all-or-nothing contract, test-proven): (1) `required_at` defaults to `'checkout'`; (2) per-item `validateFlowInputDefinition` → `inputs.{i}: <live-text>` 422; (3) intra-request `key`/`sort_order` duplicates → translated 422 (nulls SKIPPED — code-verified); (4) existing-key collisions → `inputs.{i}: <taken-text>` 422; (5) existing-sort collisions (only EXPLICIT sorts checked) → 422; (6) required-adding + in-flight flow → 422 (introducing a demand changes what in-flight orders must satisfy); (7) deterministic sort allocation (explicit respected, omitted = max+1 in request order — test-proven `[1,2,3]` and `[10→11]`); then ONE transaction of inserts → 201. NOTE the update (`order-flow-inputs/{id}`) and destroy paths exist in the controller but have NO routes in the pasted block — OUT OF SCOPE here (their guards are cited only where tests touch them) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group + constructor) |
| Permission | Any ONE of `manage-order-flow-inputs` / `update-order-status` via the route pipe (writes demand the dedicated input permission OR the legacy status permission — source-commented; no constructor/inline check exists) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `application/json` with the MANDATORY `{inputs: [...]}` wrapper (never a root array, never a single object — source-commented contract, test-proven) |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.order-flows.inputs.store` (explicit named route) |

Order matters: 401/403 → 422 (request validation) → 404 (flow lookup) → 422 (seven gates in order) → 201.

## Request inputs

`FlowInputBulkStoreRequest` runs before the body (1–100 items; `key` snake-case regex; bilingual label/placeholder/help_text; type/source allowlists; `required_at` free-form ≤ 60 chars at this layer — semantic checks live in gate (2)).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `flowId` | path | integer | Required | No | `whereNumber('flowId')` + `int $flowId` | Existing flow id | None | None | `2` | Identifies the flow |
| `inputs` | body | array of objects | Required | No | `required\|array\|min:1\|max:100` | 1–100 items | None | None | `[{...}]` | Definitions batch (MANDATORY wrapper) |
| `inputs.*.key` | body | string | Required | No | `required\|string\|max:50\|regex:/^[a-z][a-z0-9_]{1,49}$/` (lowercase snake — IMMUTABLE after create per the out-of-scope update path) | snake_case keys | None | None | `"from_country"` | Stable frontend contract key |
| `inputs.*.label` | body | object | Required | No | `required` wrapper + `label.en: required\|string\|max:100`, `label.ar: nullable\|string\|max:100` | Bilingual object | None | None | `{"en": "From country"}` | Rendered label |
| `inputs.*.placeholder` | body | object | Optional | Yes | `nullable` wrapper + per-locale `nullable\|string\|max:150` | Bilingual or null | `null` | None | `{"en": "Select..."}` | Placeholder text |
| `inputs.*.help_text` | body | object | Optional | Yes | `nullable` wrapper + per-locale `nullable\|string\|max:500` | Bilingual or null | `null` | None | `{"en": "Required"}` | Help text |
| `inputs.*.type` | body | string | Required | No | `required\|string\|in:text,number,boolean,date,select,multi_select` | The six types | None | None | `"select"` | Input widget type |
| `inputs.*.source` | body | string | Optional | Yes | `nullable\|string\|in:countries,governorates,warehouses,pickup_locations` | The four sources | `null` | None | `"countries"` | Option source (select types) |
| `inputs.*.required` | body | boolean | Optional | No | `sometimes\|required\|boolean` | `true`, `false` | `false` | None | `true` | Demand flag (in-flight-guarded, gate 6) |
| `inputs.*.required_at` | body | string | Optional | Yes | `nullable\|string\|max:60` (defaults `'checkout'`; semantic validation in gate 2 — e.g. `transition:<code>` must resolve, verified by the `transition:hyperdrive` 422 test) | Context strings | `"checkout"` | None | `"checkout"` | Demand context |
| `inputs.*.sort_order` | body | integer | Optional | Yes | `nullable\|integer\|min:1\|max:1000` (explicit values kept; omitted auto-allocated — test-proven) | `1`–`1000` | max+1 sequence | None | `10` | Render position |
| `inputs.*.validation` | body | object | Optional | Yes | `nullable\|array` + `options` (≤ 200)/`min`/`max`/`pattern` (≤ 255) sub-rules | Constraint objects | `null` | None | `{"min": 3}` | Value constraints |

## Request examples

Valid bulk request (test-verified shape — three items incl. a `transition:` context and validation map):

```json
{
  "method": "POST",
  "url": "/api/v1/admin/order-flows/2/inputs",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "inputs": [
      {
        "key": "from_country",
        "label": { "en": "From country", "ar": "من" },
        "type": "select",
        "source": "countries",
        "required": true
      },
      {
        "key": "customs_reference",
        "label": { "en": "Customs reference", "ar": "مرجع" },
        "type": "text",
        "required": true,
        "required_at": "transition:customs_clearance",
        "validation": { "min": 3, "max": 100 }
      }
    ]
  }
}
```

# Responses

## Success: inputs created — HTTP 201 (REQUEST order, allocated sorts)

Returned via `ApiResponse::apiResponse('Flow inputs created successfully.', 201, true, FlowInputResource::collection($created)->toArray(request()))` (literal message; PLAIN array payload). Verified by `test_creates_three_inputs_in_one_request` (`assertStatus(201)`, key order = request order, sorts `[1,2,3]`, DB count 3, `postJson`) and `test_explicit_sort_orders_respected_and_collisions_rejected` (201 + `[10→11]` allocation).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `201` on success |
| `message` | string | Always present | Literal (untranslated) | Always `"Flow inputs created successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | array | Always present (non-empty — `min:1` guarantees ≥ 1) | Created `FlowInputResource` rows in REQUEST order (full 15-key shape, see index doc) | Created rows |

```json
{
  "status": 201,
  "message": "Flow inputs created successfully.",
  "success": true,
  "data": [
    {
      "id": 1,
      "flow_id": 2,
      "key": "from_country",
      "label": { "en": "From country", "ar": "من" },
      "placeholder": { "en": null, "ar": null },
      "help_text": { "en": null, "ar": null },
      "type": "select",
      "source": "countries",
      "required": true,
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

No error-code field; every error below has Error code `None`. ALL business 422s render `{status, message, success}` with NO `errors` map (only request-validation carries one) — and every gate precedes persistence (atomicity test-proven: counts stay 0/1 after refusals).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller holds NEITHER `manage-order-flow-inputs` NOR `update-order-status` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Permissionless user (verified by `test_permission_denied_without_input_permission` → 403 with ZERO rows created; and `test_input_endpoints_require_permission` → POST 403) | See 403 example |
| 404 | No flow with `{flowId}` (post-validation lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id with valid body (code-traced) | See 404 example |
| 404 | Non-numeric `{flowId}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
| 422 | `FlowInputBulkStoreRequest` fails (missing wrapper, > 100 items, bad key regex, bad type/source, bad `required_at` FORMAT-level issues) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body (bad `type: country` also 422s here per `test_invalid_type_source_and_required_at_fail` — request and semantic layers overlap on type/source) | See 422-validation example |
| 422 | Gate 2: per-item DEFINITION refusal (`inputs.{i}: <live-text>` — bad `required_at` semantics, multi-select allow-list violations) | `{status: integer, message: string, success: boolean}` (NO `errors` map; INDEX-PREFIXED message) | None | `"inputs.{i}: "` + live definition text (e.g. `transition:hyperdrive` refusal — verified 422 by the invalid-timing test) | None | Semantically bad item (nothing persisted — verified) | See 422-definition example |
| 422 | Gate 3: intra-request duplicates (`key` or `sort_order` twice, nulls skipped) | `{status: integer, message: string, success: boolean}` (NO `errors` map) | None | Translated `checkout.flow_input_key_duplicate_request` / `..._sort_duplicate_request` | None | Duplicate within the batch (verified by `test_atomic_rollback_when_third_item_fails` → 422 with ZERO rows) | See 422-duplicate example |
| 422 | Gates 4–5: collisions with EXISTING rows (taken key incl. `inputs.{i}` prefix; taken explicit sort) | `{status: integer, message: string, success: boolean}` (NO `errors` map) | None | Translated `checkout.flow_input_key_taken` / `checkout.flow_input_sort_taken` | None | Colliding item (verified: existing-key 422 with only the pre-existing row surviving; taken-sort 422 after the `[10→11]` test) | See 422-collision example |
| 422 | Gate 6: required-adding batch while the flow has IN-FLIGHT (non-terminal) orders | `{status: integer, message: string, success: boolean}` (NO `errors` map) | None | Translated `checkout.flow_input_inflight_block` | None | Demand-expanding batch on a live flow (code-traced; the update/destroy twins pin the same key with in-flight tests) | See 422-inflight example |
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

422-validation example:

```json
{
  "message": "The inputs field is required.",
  "status": false,
  "errors": {
    "inputs": ["The inputs field is required."]
  }
}
```

422-definition example (index-prefixed, no map):

```json
{
  "status": 422,
  "message": "inputs.0: Live definition refusal text (exact string definition-dependent)",
  "success": false
}
```

422-duplicate example (translated, no map):

```json
{
  "status": 422,
  "message": "Translated intra-request duplicate text (checkout.flow_input_key_duplicate_request)",
  "success": false
}
```

422-collision example (translated, no map):

```json
{
  "status": 422,
  "message": "inputs.1: Translated key-taken text (checkout.flow_input_key_taken)",
  "success": false
}
```

422-inflight example (translated, no map):

```json
{
  "status": 422,
  "message": "Translated in-flight block text (checkout.flow_input_inflight_block)",
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
