# Endpoint Name

Fulfillments – Create Shipment (`POST /api/v1/admin/fulfillments/{id}/shipments`)

# Description

Creates one active shipment label for a ready fulfillment with keyed replay semantics.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/admin/fulfillments/{id}/shipments` (explicit `{id}` placeholder with `whereNumber('id')` — the FULFILLMENT id, not an order or shipment id; code-verified) |
| Path composition | `api/v1` prefix + `admin/fulfillments/{id}/shipments` from the `Route::prefix('admin/fulfillments')->middleware(['api', 'auth:sanctum', 'throttle:admin'])` group (named `api.admin.fulfillments.shipments.store`) in `packages/marvel/src/Rest/Routes.php` — same family as the seven fulfillment actions above |
| Controller action | `App\Http\Controllers\Api\Admin\Wms\ShipmentController@store(CreateShipmentRequest $request, int $id)` (the WMS controller — NOT the `admin/orders` shipment-fields controller; same class basename, different namespace + purpose, code-verified) → `whereKey->firstOrFail` (miss → 404 fixed; validation ran FIRST — code-verified order); `authorizeWarehouseScope(warehouse_id, 'create-shipment')` (verbatim-403 path — cross-warehouse creates NOTHING, test-verified); then `ShipmentService::createForFulfillment` with NULL-filtered label fields: D8-1 single-active-label rule — UNKEYED duplicate → `RuntimeException` with `already has an active shipment` → 409 (no second row, test-verified); KEYED replay for the SAME fulfillment → 200 SAME row (test-verified); SAME key for a DIFFERENT fulfillment → 409, never its row (test-verified with zero rows abroad); cancelled/non-ready fulfillments → 422 (test-verified on a freshly-released row). The 200/201 hint is a READ-ONLY pre-probe (service stays the replay authority — a lost race only mistakes the code, never the outcome; same comment class as fulfillment release) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` (group; no constructor middleware) |
| Permission | Required: `create-shipment` via the route pipe (a `view-shipment`-only caller fails it — test-verified 403 on create for viewers) |
| Required headers | `Authorization: Bearer <sanctum-token>`, `Content-Type: application/json` when a body is sent |
| Recommended headers | `Accept: application/json` |
| Supported request formats | `application/json` (all fields optional — an empty body is valid on a ready fulfillment, test-verified) |
| Rate limit | `throttle:admin`: 400 requests/minute |
| Route name | `api.admin.fulfillments.shipments.store` (explicit named route) |

Order matters: 401/403-route → 422 (validation) → 404 (lookup) → 403-scope → 200/201/409/422-engine.

## Request inputs

`CreateShipmentRequest` runs before the body (`authorize: true` — the gates above already ran). ONLY safe label fields are accepted — `status`/`tracking`/ids are service-owned and have NO rules (sending them changes nothing; code-verified, source-commented).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `id` | path | integer | Required | No | `whereNumber('id')` + `int $id`; must be READY (`ready_to_ship` in tests — earlier states refuse; code-verified via the non-ready 422 test) | Ready fulfillment id manageable by the caller | None | None | `1` | Identifies the fulfillment (NOT the order) |
| `courier` | body | string | Optional | Yes | `nullable\|string\|max:255` | Any string or null | Service default | None | `"Aramex"` | Carrier label (test-verified persistence) |
| `shipping_method` | body | string | Optional | Yes | `nullable\|string\|max:255` | Any string or null | Service default | None | `"standard"` | Method label |
| `notes` | body | string | Optional | Yes | `nullable\|string\|max:2000` | Any string or null | Service default | None | `"Fragile"` | Label notes |
| `destination_address` | body | object | Optional | Yes | `nullable\|array` (keys unvalidated — code-verified passthrough) | Any object or null | Service default | None | `{"city": "Cairo"}` | Destination snapshot |
| `idempotency_key` | body | string | Optional | Yes | `nullable\|string\|max:255` (NO reserved-namespace rule here, unlike fulfillment release — code-verified absence) | Any string or null | None (unkeyed duplicates 409 — keyless calls do NOT replay on this endpoint, unlike fulfillment release; code-verified difference) | None | `"key-c1"` | Replay key scoped to (fulfillment, key) |

## Request examples

Valid request creating a label (test-verified call):

```json
{
  "method": "POST",
  "url": "/api/v1/admin/fulfillments/1/shipments",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json"
  },
  "body": {
    "courier": "Aramex",
    "shipping_method": "standard",
    "idempotency_key": "key-c1"
  }
}
```

# Responses

## Success: shipment created — HTTP 201 (fresh) or 200 (keyed replay, SAME row)

Returned via `ApiResponse::apiResponse($replay ? 'Shipment already exists for this idempotency key.' : 'Shipment created successfully.', $replay ? 200 : 201, true, ShipmentDetailResource::make($fresh))` (literal messages; E2E twins also create through this exact action with `courier` payloads). Verified: fresh 201 + `label_created` + `courier` proofs; keyed replay 200-same-id.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | `201` fresh / `200` keyed replay (a lost race only mistakes the CODE — same comment class as fulfillment release) |
| `message` | string | Always present | Literal (untranslated) | `"Shipment created successfully."` (201) / `"Shipment already exists for this idempotency key."` (200) |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | Fresh `ShipmentDetailResource` (`status: label_created`) | 24 keys: `id`, `uuid`, `order_id`, `fulfillment_id`, `packing_task_id`, `tracking_number`, `courier`, `status`, `shipping_method`, `shipping_cost` (float/null), `currency`, `total_weight` (float/null), `origin_address`, `destination_address`, `notes`, `cancel_reason`, `cancel_source`, `cancelled_by`, `shipped_at`, `estimated_delivery_at`, `delivered_at`, `cancelled_at`, `created_at`, `updated_at` |

```json
{
  "status": 201,
  "message": "Shipment created successfully.",
  "success": true,
  "data": {
    "id": 1,
    "uuid": "123e4567-e89b-12d3-a456-426614174000",
    "order_id": 42,
    "fulfillment_id": 1,
    "packing_task_id": null,
    "tracking_number": null,
    "courier": "Aramex",
    "status": "label_created",
    "shipping_method": "standard",
    "shipping_cost": null,
    "currency": null,
    "total_weight": null,
    "origin_address": null,
    "destination_address": null,
    "notes": null,
    "cancel_reason": null,
    "cancel_source": null,
    "cancelled_by": null,
    "shipped_at": null,
    "estimated_delivery_at": null,
    "delivered_at": null,
    "cancelled_at": null,
    "created_at": "2026-01-01T00:00:00+00:00",
    "updated_at": "2026-01-01T00:00:00+00:00"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing/invalid token | `{message: string, status: boolean}` | None | `"Unauthenticated"` | None | Empty body by a guest (verified by `test_unauthenticated_shipment_endpoints_return_401` → 401 on this exact path) | See 401 example |
| 403 | Authenticated caller lacks `create-shipment` (route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` | None | Homed viewer (verified by `test_shipment_commands_require_granular_permissions` → 403 on create) | See 403 example |
| 403 | Out-of-scope `create-shipment` holder at the SCOPE layer (verbatim, NOTHING created) | `{message: string, status: boolean}` | None | `"Forbidden warehouse operation."` (verbatim WITH period) | None | Cross-warehouse create (verified by the non-ready-twin family pattern → 403; zero-row proof on the fulfillment-release twin) | See 403-scope example |
| 404 | No fulfillment with `{id}` (post-validation lookup miss) | `{message: string, status: boolean}` | None | `"Resource Not Found"` (fixed string) | None | Unknown numeric id with valid body (code-traced) | See 404 example |
| 404 | Non-numeric `{id}` matches NO route | `{message: string, status: boolean}` | None | `"Not Found"` (route-miss shape) | None | Non-numeric segment | See 404-constraint example |
| 405 | `PUT`/`PATCH`/`GET` on this path (only `POST` registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` | None | Wrong method (code-traced) | See 405 example |
| 409 | UNKEYED duplicate (an active label already exists — one-active-label rule D8-1) | `{message: string, status: boolean}` (NO `errors` map — `conflict()`-thrown `HttpException`) | None | Live text containing `already has an active shipment` (substring-matched; exact full string service-dependent) | None | Second label without a key (verified → 409 + single-row proof) | See 409-duplicate example |
| 409 | SAME key for a DIFFERENT fulfillment (cross-row replay refused loudly — never the other row) | `{message: string, status: boolean}` (NO `errors` map) | None | Live text containing `already bound to shipment` (substring-matched) | None | Key reuse abroad (verified → 409 + zero-rows-abroad proof) | See 409-bound example |
| 422 | `CreateShipmentRequest` fails (overlong strings) | `{message: string, status: boolean, errors: object}` | None | Laravel default | Field → messages | Invalid body (code-traced; all fields nullable so only length violations qualify) | See 422-validation example |
| 422 | Fulfillment not ready / cancelled (service refusal — NOT the key path) | `{message: string, status: boolean}` (NO `errors` map — `unprocessable()`-thrown `HttpException`) | None | Live engine text (exact string engine-dependent) | None | Freshly-released row (verified → 422 on the non-ready twin) | See 422-engine example |
| 429 | Exceeding `throttle:admin` | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded | See 429 example |

401 example:

```json
{
  "message": "Unauthenticated",
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

403-scope example (verbatim WITH period):

```json
{
  "message": "Forbidden warehouse operation.",
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

409-duplicate example (live substring, no map):

```json
{
  "message": "Live duplicate text containing already has an active shipment (exact string service-dependent)",
  "status": false
}
```

409-bound example (live substring, no map):

```json
{
  "message": "Live binding text containing already bound to shipment (exact string service-dependent)",
  "status": false
}
```

422-validation example:

```json
{
  "message": "The courier field must not be greater than 255 characters.",
  "status": false,
  "errors": {
    "courier": ["The courier field must not be greater than 255 characters."]
  }
}
```

422-engine example (live text, no map):

```json
{
  "message": "Live engine refusal text (exact string engine-dependent)",
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
