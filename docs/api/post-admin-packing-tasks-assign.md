# Endpoint Name

Packing Tasks – Assign Task (`POST /api/v1/admin/packing-tasks/{id}/assign`)

# Description

Assigns one packing task to a station with the caller pinned as assignee (first-winner contention → 409), returning 200 with task detail.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/packing-tasks/{id}/assign` (explicit `{id}` placeholder with `whereNumber('id')` — non-numeric segments match NO route) |
| Path composition | `api/v1` prefix + `admin/packing-tasks/{id}/assign` custom `Route::post(...)->name('api.admin.packing-tasks.assign')` in `packages/marvel/src/Rest/Routes.php` inside the `['api', 'auth:sanctum', 'throttle:admin']` group with route-level `permission:packing-execute` |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\PackingController@assign(AssignPackingTaskRequest $request, int $id)` → validated `station_id` → `whereKey->firstOrFail` (miss → 404 fixed); `authorizeWarehouseScope(task→fulfillment warehouse, 'packing-execute', asNotFound: FALSE)`; then `PackingService::assignToStation(task, stationId, actorId)` — `WmsConflictException` → 409 (typed, locale-proof), `RuntimeException` → 422 (code-verified). The assignee is ALWAYS the authenticated caller (no `user_id` field exists — forgery impossible, request-commented, code-verified). Service, under lock in order (fulfillment → task → station — code-verified): terminal (outside `pending/assigned`) → 422 `assign_status`; assigned to ANOTHER user → 409 `already_assigned` (same-user re-assign proceeds and refreshes — code-verified); station miss → 422 quirk (service lookup inside the try — see below); inactive station → 422 `station_inactive`; cross-warehouse station → 422 `station_warehouse`; else station + caller + `assigned` + `assigned_at` (code-verified) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `packing-execute` via the route pipe + scope check |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute per user id (or IP for guests), per `App\Providers\AppServiceProvider::boot` (code-verified) |
| Route name | `api.admin.packing-tasks.assign` (explicit named route; `route:list` verified; covered by the generic WMS route sweeps) |

Order matters: 401 → 403-route → 422-validation → 404-task → 403-scope → 422-terminal → 409-occupied → 422-station-miss (quirk — no `exists` rule, lookup inside the try) → 422-station-guards → 200.

## Request inputs

`AssignPackingTaskRequest` runs before the action (`authorize: true`).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (BECOMES the assignee — no override field exists) |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id` | Non-terminal task id manageable by the caller | None | None | `1` | Identifies the packing task |
| `station_id` | body | integer | Required | No | `required\|integer\|min:1` (NO `exists` rule — unknown ids do NOT 422 at validation; they 422 in-service, see quirk row — code-verified) | Active station id in the fulfillment's warehouse | None | None | `2` | The packing station (test-authored success + foreign-station 422) |

## Request examples

```json
{
  "method": "POST",
  "url": "/api/v1/admin/packing-tasks/1/assign",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "station_id": 2
  }
}
```

# Responses

## Success: task assigned — HTTP 200 (DETAIL resource, caller pinned)

Returned via `ApiResponse::apiResponse(__('wms.packing.assigned'), 200, true, PackingTaskDetailResource…)` — translated at the call site (`en` → `"Packing task assigned successfully."`, verified in `resources/lang/en/wms.php:96`). Test-authored: 200 + `assigned_to` = caller + `assigned`.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `wms.packing.assigned` | `en` → `"Packing task assigned successfully."` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh `PackingTaskDetailResource` (`assigned_to` = caller) | Full detail keys (see show doc) |

```json
{
  "status": 200,
  "message": "Packing task assigned successfully.",
  "success": true,
  "data": {
    "id": 1,
    "fulfillment_id": 11,
    "packing_station_id": 2,
    "station": {
      "id": 2,
      "code": "ST-A1",
      "name": "Station A1",
      "warehouse_id": 1
    },
    "assigned_to": 7,
    "status": "assigned",
    "assigned_at": "2026-10-14T10:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. Validation failures use the VALIDATION envelope (`errors` map); guard refusals and contention use the EXCEPTION envelope `{message, status: false}` (code-verified).

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated."` (WITH period, code-verified) | None | No valid token (test-authored 401 on this URI) | See 401 example |
| 403 | Authenticated caller lacks `packing-execute` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Valid token without the permission (code-traced) | See 403 example |
| 403 | Out-of-scope `packing-execute` holder at the SCOPE layer (controller verbatim discarded — code-verified) | `{message: string, status: boolean}` | None | `"This action is unauthorized."` (`message.ERROR.UNAUTHORIZED_ACTION`) | None | Cross-warehouse assign (code-traced) | See 403-scope example |
| 404 | No task with `{id}` (lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id (code-traced) | See 404 example |
| 422 | Unknown `station_id` (in-service quirk: NO `exists` rule + the service `firstOrFail()` lives INSIDE the try whose `catch (RuntimeException)` swallows the `ModelNotFoundException` — `ModelNotFound extends RecordsNotFound extends RuntimeException`, code-verified — and reframes it as 422; the leaked framework text exposes the model FQN and SHOULD be mapped to a translated 422/404 in code — follow-up) | `{message: string, status: boolean}` (NO `errors` map) | None | Framework no-query-results text, verbatim passthrough (of the form `No query results for model […PackingStation] <id>` — shape code-verified, exact literal NOT asserted) | None | Ghost station id (code-traced) | See 422-unknown-station example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | Framework passthrough (ILLUSTRATIVE `"Not Found"` — NOT live-verified) | None | Non-numeric segment | See 404-constraint example |
| 409 | Task already assigned to ANOTHER user (first winner provably retained, test-authored) | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.packing.already_assigned`: `en` → `"Packing task #1 is already assigned to user 7"` (ids illustrative; verified in `resources/lang/en/wms.php:110`) | None | Contested assign (test-authored 409 + winner proof) | See 409 example |
| 422 | Body validation failure (missing/non-integer/`station_id < 1`) | `{message: string, status: boolean, errors: object}` (WITH `errors` map) | None | `"The given data was invalid."` (`message.ERROR.INVALID_DATA`) | Per-field arrays | Bad body (code-traced) | See 422-validation example |
| 422 | Terminal task (outside `pending/assigned`) | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.packing.assign_status`: `en` → `"Cannot assign packing task in status: packing"` (status illustrative; verified in `resources/lang/en/wms.php:109`) | None | Assigned/packing/packed/verified/cancelled task (code-traced) | See 422-status example |
| 422 | Station inactive | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.packing.station_inactive`: `en` → `"Packing station is not active"` (verified in `resources/lang/en/wms.php:111`) | None | Deactivated station (code-traced) | See 422-station example |
| 422 | Station in another warehouse (service refuses — never assigns, code-verified) | `{message: string, status: boolean}` (NO `errors` map) | None | Translated `wms.packing.station_warehouse`: `en` → `"Packing station #2 belongs to a different warehouse than fulfillment #11"` (ids illustrative; verified in `resources/lang/en/wms.php:112`) | None | Foreign station (test-authored 422 with state provably still `pending`) | See 422-warehouse example |
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

403-scope example (catalogue text, code-verified):

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

422-unknown-station example (framework text reframed as 422 — shape code-verified, exact literal NOT asserted):

```json
{
  "message": "No query results for model [App\\Models\\Fulfillment\\PackingStation] 999999",
  "status": false
}
```

404-constraint example (ILLUSTRATIVE — NOT live-verified):

```json
{
  "message": "Not Found",
  "status": false
}
```

409 example (translated, no map):

```json
{
  "message": "Packing task #1 is already assigned to user 7",
  "status": false
}
```

422-validation example (WITH `errors` map):

```json
{
  "message": "The given data was invalid.",
  "status": false,
  "errors": {
    "station_id": ["The station id field is required."]
  }
}
```

422-status example (translated, no map):

```json
{
  "message": "Cannot assign packing task in status: packing",
  "status": false
}
```

422-station example (translated, no map):

```json
{
  "message": "Packing station is not active",
  "status": false
}
```

422-warehouse example (translated, no map):

```json
{
  "message": "Packing station #2 belongs to a different warehouse than fulfillment #11",
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
