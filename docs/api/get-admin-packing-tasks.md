# Endpoint Name

Packing Tasks – List Tasks (`GET /api/v1/admin/packing-tasks`)

# Description

Lists packing tasks in reverse-id order inside the caller's warehouse scope with station/fulfillment/status/assignee filters, returning 200 with a paginated flat array.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/admin/packing-tasks` (fixed path — no placeholders) |
| Path composition | `api/v1` prefix + `admin/packing-tasks` custom `Route::get(...)->name('api.admin.packing-tasks.index')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:view-fulfillment` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\PackingController@index(ListPackingTasksRequest $request)` → validated filters → scope branch: `manage-warehouse` holders see everything (optional `?warehouse_id` honored as a plain filter with NO scope authorization on it — code-verified); scoped callers are confined to home (`warehouseIdFor`, null home → 403 catalogue) and their `?warehouse_id` is IGNORED (code-verified — never read in that branch); `station_id`/`fulfillment_id` narrow with lookup + `view-fulfillment` scope check (404 anti-enumeration); `status`/`assigned_to` narrow; `limit` caps at 100 (default 15); `orderByDesc('id')`, station eager-load (`id,code,name,warehouse_id`) → 200 `PackingTaskResource` collection. Pure read (code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `view-fulfillment` (route pipe + scope check — BOTH the same permission) |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per user id (or IP for guests), per `App\Providers\AppServiceProvider::boot` (code-verified) |
| Route name | `api.admin.packing-tasks.index` (explicit named route; `route:list` verified; covered by the generic WMS route sweeps) |

Order matters: 401 → 403-route → 422-validation → 403-homeless → 404-station/fulfillment (lookup miss OR scope denial — indistinguishable) → 200.

## Request inputs

`ListPackingTasksRequest` runs before the action (`authorize: true`). NOTE the rule split (code-verified): NO `exists` rules here (plain `min:1`) — unknown `warehouse_id`/`station_id`/`fulfillment_id`/`assigned_to` do NOT 422 at validation; unknown station/fulfillment ids 404 at the in-action lookup, unknown `warehouse_id` just matches nothing (200 `[]`).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (also the scope key) |
| `warehouse_id` | query | integer | Optional | Yes | `nullable\|integer\|min:1` | Warehouse id or omitted | `null` | Globals: plain filter; scoped: IGNORED (forced to home) | `4` | Narrows listing to one warehouse (globals only) |
| `station_id` | query | integer | Optional | Yes | `nullable\|integer\|min:1` | Station id or omitted | `null` | Non-null → lookup + scope check (404 either way) | `9` | Narrows listing to one station |
| `fulfillment_id` | query | integer | Optional | Yes | `nullable\|integer\|min:1` | Fulfillment id or omitted | `null` | Non-null → lookup + scope check (404 either way; test-authored foreign → 404) | `12` | Narrows listing to one fulfillment |
| `status` | query | string | Optional | Yes | `nullable\|string\|in:pending,assigned,packing,packed,verified,cancelled` | One status or omitted | `null` | None | `packing` | Narrows listing to one status |
| `assigned_to` | query | integer | Optional | Yes | `nullable\|integer\|min:1` | User id or omitted | `null` | None | `7` | Narrows listing to one assignee |
| `limit` | query | integer | Optional | Yes | `nullable\|integer\|min:1\|max:100` | `1`–`100` | `15` | None | `50` | Page size |

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/admin/packing-tasks?status=packing",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json"
  }
}
```

# Responses

## Success: tasks listed — HTTP 200 (flat paginated array)

Returned via `ApiResponse::apiResponse(__('wms.packing.tasks_fetched'), 200, true, PackingTaskResource::collection($paginated))`. Collection serializes as a FLAT array under `data` (same contract shape as the picking index — code-verified). Test-authored: scoped list 200 non-empty; home confinement proven on sibling reads.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `wms.packing.tasks_fetched` | `en` → `"Packing tasks fetched successfully."` (verified in `resources/lang/en/wms.php:93`) |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | array | Always present on success | Task rows, newest first | Flat `PackingTaskResource[]` (possibly `[]`) |
| `data[].id` | integer | Per row | Task id | — |
| `data[].fulfillment_id` | integer | Per row | Owning fulfillment | — |
| `data[].packing_station_id` | integer/null | Per row | Assigned station | `null` when unassigned |
| `data[].assigned_to` | integer/null | Per row | Assignee user id | `null` when unassigned |
| `data[].status` | string | Per row | Task status | `pending`/`assigned`/`packing`/`packed`/`verified`/`cancelled` |
| `data[].weight` | number/null | Per row | Packed weight | Float or `null` |
| `data[].packed_at` | string/null | Per row | Completion time | ISO-8601 |
| `data[].verified_at` | string/null | Per row | Verification time | ISO-8601 |
| `data[].created_at` | string/null | Per row | Creation time | ISO-8601 |
| `data[].updated_at` | string/null | Per row | Update time | ISO-8601 |

```json
{
  "status": 200,
  "message": "Packing tasks fetched successfully.",
  "success": true,
  "data": [
    {
      "id": 3,
      "fulfillment_id": 11,
      "packing_station_id": 2,
      "assigned_to": 7,
      "status": "packing",
      "weight": null,
      "packed_at": null,
      "verified_at": null,
      "created_at": "2026-10-14T09:00:00+00:00",
      "updated_at": "2026-10-14T10:00:00+00:00"
    }
  ]
}
```

# Errors

No error-code field; every error below has Error code `None`. Validation failures use the VALIDATION envelope (`errors` map); all other errors use the EXCEPTION envelope `{message, status: false}` (code-verified via `Handler`).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, `message.ERROR.UNAUTHENTICATED` — Handler:170, code-verified) | None | No valid token (test-authored 401 on this URI) | See 401 example |
| 403 | Authenticated caller lacks `view-fulfillment` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 403 | Scoped caller with NO home warehouse (fail-closed; controller verbatim discarded — code-verified) | `{message: string, status: boolean}` | None | `"This action is unauthorized."` (`message.ERROR.UNAUTHORIZED_ACTION`) | None | Homeless staffer (code-traced) | See 403-scope example |
| 404 | Unknown `station_id`/`fulfillment_id` (lookup miss) OR cross-warehouse row (scope anti-enumeration — indistinguishable by design, code-verified) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Bad filter id; foreign fulfillment filter (test-authored 404 on the fulfillment probe) | See 404 example |
| 422 | Filter validation failure (`min`/`in`/`max` rules) | `{message: string, status: boolean, errors: object}` (WITH `errors` map) | None | `"The given data was invalid."` (`message.ERROR.INVALID_DATA`) | Per-field arrays | E.g. bad `status`, `limit > 100` (code-traced) | See 422-validation example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded | See 429 example |

401 example (`message.ERROR.UNAUTHENTICATED`, WITH period):

```json
{
  "message": "Unauthenticated.",
  "status": false
}
```

403 example (missing permission):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

403-scope example (catalogue text — the controller verbatim is discarded by the Handler, code-verified):

```json
{
  "message": "This action is unauthorized.",
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

422-validation example (WITH `errors` map):

```json
{
  "message": "The given data was invalid.",
  "status": false,
  "errors": {
    "status": ["The selected status is invalid."]
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
