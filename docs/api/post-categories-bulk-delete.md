# Endpoint Name

Categories – Bulk Delete (`POST /api/v1/categories/bulk-delete`)

# Description

Queues an async job soft-deleting the given categories and returns the operation id.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `POST` |
| Full path | `/api/v1/categories/bulk-delete` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `categories/bulk-delete` from `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, named `admin.categories.bulk-delete`, with route-level `permission:delete-category` ON TOP of the constructor `delete-category` gate (aligned — both require the same permission) |
| Controller action | `Marvel\Http\Controllers\CategoryController@bulkDelete` (dedups ids preserving order, creates a `category-bulk-delete` Import row with `total_rows`, writes the `ids_{id}.json` signal file, dispatches `BulkDeleteCategoriesJob`; NO idempotency layers — every accepted call creates a new operation, like category import) |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required twice (aligned): `delete-category` via route middleware AND constructor middleware |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `admin.categories.bulk-delete` |

## Request inputs

`BulkDeleteCategoriesRequest` validation runs before the method body (no `failedValidation` override, but the framework default throws `ValidationException` — verified in vendor — so failures still render through `Handler` with the standard 422 shape). EVERY rule failure shares ONE custom message (file-verified both locales).

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller (and owns the operation row) |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required when a body is sent | No | Must match body format | `application/json` | None | None | `application/json` | Declares body encoding |
| `ids` | body | array of integer ids | Required | No | `required\|array\|min:1` — one SHARED custom message for every failure below | Non-empty array | None | None | `[4, 9]` | Categories to delete (deduped order-preserved server-side; `total_rows` counts the DEDUPED list — code-verified) |
| `ids.*` | body (each element) | integer id reference | Required for every element | No | `integer\|min:1\|distinct\|exists:categories,id` (table-level check WITHOUT Eloquent scopes — a trashed id PASSES and becomes a job-level error row later; code-verified rule-vs-job split) — same SHARED message | Positive ids of existing categories; no duplicates | None | None | `4` | One category to delete |

There are no path parameters, query parameters, multipart fields, or file uploads. Any extra body fields are ignored.

Job semantics (job-level tests, for context — the endpoint only queues): leaf categories soft-delete (`completed`); parents WITH children are SKIPPED as error rows (`errors[0].category_id`, import `failed`); missing ids become error rows; already-deleted ids are skipped idempotently on retry; children delete before parents. Deletion is SOFT (`assertSoftDeleted`).

## Request examples

Valid request queueing a bulk delete (test-verified call):

```json
{
  "method": "POST",
  "url": "/api/v1/categories/bulk-delete",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "ids": [4, 9]
  }
}
```

# Responses

## Success: bulk delete queued — HTTP 202

Returned via `ApiResponse::apiResponse(CATEGORY_BULK_DELETE_STARTED, 202, true, ['bulk_delete_id' => ..., 'status' => ...])` after persisting the `pending` row, writing the ids signal file, and dispatching `BulkDeleteCategoriesJob`. Verified by `test_bulk_delete_dispatches_job_and_returns_202` (202, `success: true`, structure `data: [bulk_delete_id, status]`, DB row `type: 'category-bulk-delete'` + `status: 'pending'`, job pushed; `postJson`, matching this route). There is exactly one success contract. Processing is ASYNC — poll the bulk-delete status endpoint.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `202` on acceptance |
| `message` | string | Always present | Translated `MESSAGE.CATEGORY_BULK_DELETE_STARTED` (locale from `lang` header) | `en` → `"Category bulk delete started successfully"`, `ar` → `"بدأ الحذف الجماعي للفئات بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on acceptance |
| `data` | object | Always present on success | `{bulk_delete_id, status}` — the operation id to poll, plus its creation-time status (note `bulk_delete_id`, NOT `import_id` — code-verified key) | `bulk_delete_id`: integer; `status`: `"pending"` |

Complete success example (`en` locale):

```json
{
  "status": 202,
  "message": "Category bulk delete started successfully",
  "success": true,
  "data": {
    "bulk_delete_id": 51,
    "status": "pending"
  }
}
```

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 202/401/422 contracts are test-verified (all use `postJson`); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` | None | Any request without a valid `Authorization: Bearer` token; verified by `test_unauthenticated_user_cannot_bulk_delete` expecting 401 | See 401 example |
| 403 | Authenticated caller lacks the `delete-category` permission (BOTH route and constructor gates require it; either rejects before validation runs, so even an invalid body yields 403 here) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `delete-category` (no bulk-delete-specific 403 test exists — the cancel sibling's 403 tests prove the gate; identical path) | See 403 example |
| 405 | `GET`, `PUT`, `PATCH`, or `DELETE` on `/api/v1/categories/bulk-delete` (only `Route::post` is registered) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Wrong method with no competing route match (code-traced) | See 405 example |
| 422 | Any `BulkDeleteCategoriesRequest` failure, rendered by the `ValidationException` branch of `Handler` (message = `ValidationException::summarize`) with the full map under `errors` — EVERY rule shares ONE custom message (file-verified both locales): `en` → `"At least one category id is required"`, `ar` → `"يجب تحديد فئة واحدة على الأقل"` | `{message: string, status: boolean, errors: object}` | None | The shared custom message above | Map of `ids` / `ids.{index}` → message arrays (exact multi-failure maps **Unverified in source**) | Missing body (verified by `test_bulk_delete_validates_ids_required` with `assertJsonValidationErrors(['ids'])`), empty array (verified by `test_bulk_delete_validates_empty_ids_array`), non-integer/non-positive/duplicated/unknown element | See 422 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Unexpected failure (any exception in row creation/signal/job dispatch), rendered by the generic branch of `Handler` (the method has no try/catch) | `{message: string, status: boolean}` | None | The exception message when `app.debug` is true, else `"Internal Server Error"` | None | Infrastructure failure (code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `delete-category` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

405 example (wrong method, e.g. `GET /api/v1/categories/bulk-delete`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422 example (missing body; `en` locale):

```json
{
  "message": "At least one category id is required",
  "status": false,
  "errors": {
    "ids": [
      "At least one category id is required"
    ]
  }
}
```

429 example (rate limit exceeded):

```json
{
  "success": false,
  "message": "Too many requests. Please try again later.",
  "data": null
}
```

500 example (`app.debug` off):

```json
{
  "message": "Internal Server Error",
  "status": false
}
```
