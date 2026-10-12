# Endpoint Name

Order Flow Inputs – Update Input (`PUT /api/v1/admin/order-flow-inputs/{id}`)

# Description

Partially updates one input definition with an immutable key and in-flight guards, returning 200.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` (only `PUT` is registered — `PATCH` on this path → 405; code-verified, same as flow update) |
| Full path | `/api/v1/admin/order-flow-inputs/{id}` (explicit `{id}` placeholder with `whereNumber('id')` — NOTE the path addresses the INPUT id directly, NOT `order-flows/{flowId}/inputs`; code-verified distinct family) |
| Path composition | `api/v1` prefix + `admin/order-flow-inputs/{id}` from the `Route::prefix('admin/order-flow-inputs')->middleware(['api', 'lang', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.order-flow-inputs.update`) in `packages/marvel/src/Rest/Routes.php`. NOTE the middleware ORDER lists `"lang"` second (cosmetic only — verified present here, unlike its order-flow siblings whose groups omit it; no behavioral difference documented) |
| Controller action | `App\Http\Controllers\Api\Admin\FlowInputController@update(FlowInputUpsertRequest $request, int $id)` → `findOrFail` FIRST (miss → 404 fixed; validation ran earlier, so 422-validation still precedes 404 — code-verified order); `key` change REJECTED (the key is the stable frontend contract — even a valid-format new key 422s with the translated immutable text, test-verified); the CANDIDATE (stored row merged with input) re-runs full definition validation → live-text 422; deactivation guard (`required: true→false` OR `is_active: true→false` on a REQUIRED... precisely: EITHER toggle-off while the row is in the corresponding on-state, per the OR condition — code-verified) + in-flight (non-terminal) orders on the flow → translated 422 WITH the input key; else whitelisted update (`label/placeholder/help_text/type/source/required/required_at/sort_order/validation/is_active` — `key`/`flow_id` never mass-assigned; code-verified `array_intersect_key`) → 200 with the fresh resource |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group + constructor) |
| Permission | Any ONE of `manage-order-flow-inputs` / `update-order-status` via the route pipe (writes demand the dedicated input permission OR the legacy status permission — same split as bulk store; no constructor/inline check exists) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` when a body is sent |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.order-flow-inputs.update` (explicit named route) |

Order matters: 401/403 → 422 (validation) → 404 (lookup) → 422 (key/definition/in-flight business rules) → 200.

## Request inputs

`FlowInputUpsertRequest` runs before the body (all `sometimes`; same shape families as bulk items but WITHOUT the bulk-only `min:1`/`max:100` wrapper constraints).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Existing input id (flow-agnostic address) | None | None | `9` | Identifies the input definition |
| `key` | body | string | Optional | No | `sometimes\|required\|string\|max:50\|regex:/^[a-z][a-z0-9_]{1,49}$/` — BUT any value ≠ stored key → 422 immutable refusal REGARDLESS of validity (test-verified with a valid-format rename) | Must equal stored key | Unchanged | None | `"delivery_note"` | Key (effectively read-only after create) |
| `label` | body | object | Optional | No | `sometimes\|required\|array` (+ `label.en: sometimes\|required\|string\|max:100`, `label.ar: nullable\|string\|max:100`) | Bilingual object | Unchanged | None | `{"en": "Note"}` | Rendered label |
| `placeholder` / `help_text` | body | object | Optional | Yes | `nullable` wrappers + per-locale `nullable\|string` (150/500 caps) | Bilingual or null | Unchanged | None | `{"en": "..."}` | Chrome text |
| `type` | body | string | Optional | No | `sometimes\|required\|string\|Rule::in(TYPES)` + candidate re-validation | Six types | Unchanged | None | `"text"` | Widget type |
| `source` | body | string | Optional | Yes | `nullable\|string\|Rule::in(SOURCES)` | Four sources or null | Unchanged | None | `"countries"` | Option source |
| `required` | body | boolean | Optional | No | `sometimes\|required\|boolean` (true→false toggle on a required row + in-flight orders → 422 guard) | `true`, `false` | Unchanged | None | `false` | Demand flag |
| `required_at` | body | string | Optional | No | `sometimes\|required\|string\|max:60` (+ candidate re-validation — semantic checks rerun on the MERGED row, not the delta; code-verified) | Context strings | Unchanged | None | `"checkout"` | Demand context |
| `sort_order` | body | integer | Optional | Yes | `nullable\|integer\|min:1\|max:1000` | `1`–`1000` or null | Unchanged | None | `2` | Render position |
| `validation` | body | object | Optional | Yes | `nullable\|array` + `options` (≤ 200)/`min`/`max`/`pattern` (≤ 255) sub-rules | Constraint objects | Unchanged | None | `{"min": 3}` | Value constraints |
| `is_active` | body | boolean | Optional | No | `sometimes\|required\|boolean` (true→false toggle on an ACTIVE row + in-flight orders → 422 guard) | `true`, `false` | Unchanged | None | `false` | Active flag |

## Request examples

```json
{
  "method": "PUT",
  "url": "/api/v1/admin/order-flow-inputs/9",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "label": { "en": "Note" }
  }
}
```

# Responses

## Success: input updated — HTTP 200

Returned via `ApiResponse::apiResponse('Flow input updated successfully.', 200, true, (new FlowInputResource($input->fresh()))->toArray(request()))` (literal message; FRESH re-read — code-verified).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Literal (untranslated) | Always `"Flow input updated successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh `FlowInputResource` (full 15-key shape, see inputs index doc) | Same key set as bulk-create rows |

```json
{
  "status": 200,
  "message": "Flow input updated successfully.",
  "success": true,
  "data": {
    "id": 9,
    "flow_id": 2,
    "key": "delivery_note",
    "label": { "en": "Note", "ar": null },
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
    "updated_at": "2026-01-02T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. All business 422s render `{status, message, success}` with NO `errors` map (only validation carries one).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | No valid token (code-traced) | See 401 example |
| 403 | Authenticated caller holds NEITHER `manage-order-flow-inputs` NOR `update-order-status` | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without either (code-traced; the bulk twin pins 403 for the same family) | See 403 example |
| 404 | No input with `{id}` (post-validation lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id with valid body (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
| 405 | `PATCH` on this path (only `PUT` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong method (code-traced) | See 405 example |
| 422 | `FlowInputUpsertRequest` fails (bad key format, bad type/source, overlong strings) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body (code-traced) | See 422-validation example |
| 422 | `key` change attempt (ANY differing value — validity irrelevant) | `{status: integer, message: string, success: boolean}` (NO `errors` map) | None | Translated `checkout.flow_input_key_immutable` | None | Rename attempt (verified by `test_admin_input_crud_and_guards` → 422 on `key: renamed`) | See 422-key example |
| 422 | Merged-candidate definition refusal (live text from the same validator as bulk gate 2) | `{status: integer, message: string, success: boolean}` (NO `errors` map) | None | Live definition text (exact string definition-dependent) | None | Semantically bad merged row (code-traced) | See 422-definition example |
| 422 | Demand-removal with in-flight (non-terminal) orders on the flow (`required` true→false OR `is_active` true→false while in the on-state — translated WITH the input key) | `{status: integer, message: string, success: boolean}` (NO `errors` map) | None | Translated `checkout.flow_input_inflight_block` WITH `key` | None | Relaxing a live demand (verified by `test_required_input_cannot_be_removed_with_inflight_orders` → 422 on BOTH the deactivate and the delete twins) | See 422-inflight example |
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
  "message": "The type field must be one of text, number, boolean, date, select, multi_select.",
  "status": false,
  "errors": {
    "type": ["The type field must be one of text, number, boolean, date, select, multi_select."]
  }
}
```

422-key example (translated, no map):

```json
{
  "status": 422,
  "message": "Translated key-immutable text (checkout.flow_input_key_immutable)",
  "success": false
}
```

422-definition example (live text, no map):

```json
{
  "status": 422,
  "message": "Live definition refusal text (exact string definition-dependent)",
  "success": false
}
```

422-inflight example (translated WITH key, no map):

```json
{
  "status": 422,
  "message": "Translated in-flight block text with key delivery_note (checkout.flow_input_inflight_block)",
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
