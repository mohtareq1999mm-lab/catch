# Endpoint Name

Roles – Update Role (`PUT /api/v1/roles/{id}`)

# Description

Renames a role and replaces its display-name translations, deriving the machine name from English.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `PUT` |
| Full path | `/api/v1/roles/{id}` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `/roles/{id}` from `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group (no route-shadowing concern: roles use explicit routes, not `apiResource`) |
| Controller action | `Marvel\Http\Controllers\RoleAndPermissionController@updateRole` |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `update-roles` via controller constructor `permission:update-roles` middleware (applies to `updateRole`); the method itself performs no inline permission check |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` (error responses are JSON for all `/api/*` paths regardless of `Accept`, per `App\Exceptions\Handler::shouldReturnJson`) |
| Optional headers | `lang: en\|ar` (default `en`; any other value is ignored and the locale is left unchanged, per `App\Http\Middleware\CheckLangMiddleware`) |
| Supported request formats | `application/json` |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | None defined for this route |

Middleware runs before the method body: an unauthenticated or unauthorized call fails with 401/403 without reaching the lookup.

## Request inputs

Order inside `updateRole` (code-verified, and the reverse of the intuitive order): the role is looked up FIRST via `Role::findById($id, 'api')`, and only then is `display_name` validated — so a nonexistent `{id}` yields 404 even with an invalid body. A `ValidationException` is rethrown untouched and rendered by `Handler` as 422. The uniqueness rule ignores the role being updated (`->ignore($id)`), so resubmitting unchanged translations passes.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect; other values ignored | `en`, `ar` | `en` (request default header value; locale applied only when value is `en` or `ar`) | None | `en` | Selects response message locale |
| `Accept` | header | string | Recommended, not strictly required | No | Behaviorally relevant for content negotiation; JSON error shape is still returned for `/api/*` paths | `application/json` | None | None | `application/json` | Requests a JSON response |
| `Content-Type` | header | string | Required when a body is sent | No | Must match body format | `application/json` | None | None | `application/json` | Declares body encoding |
| `id` | path | integer (as it appears in the URI; no route-level numeric constraint) | Required | No | No FormRequest exists; the raw value is passed to `Role::findById($id, 'api')` before validation, throwing `RoleDoesNotExist`/`ModelNotFoundException` for unknown ids (converted to `MarvelException(NOT_FOUND)` → 404) | Id of an existing `api`-guard role | None | None | `9` | Identifies the role to update |
| `display_name` | body | object (associative array of locale → string) | Required | No | `required\|array` (inline `$request->validate`; no FormRequest applies) | Any object whose values each satisfy the per-locale rules | None | None | `{"en": "Updated Role", "ar": "دور محدث"}` | Replacement localized display names |
| `display_name.*` | body (each locale value) | string | Required for every supplied locale | No | `required`, `string`, `UniqueTranslationRule::for('roles', 'display_name')->ignore($id)` (each locale value must be unique among other roles' stored translations; the role itself is excluded) | Any non-empty string per locale, unique across other roles | None | None | `"Updated Role"` | One locale's new display name |

There are no query parameters, multipart fields, or file uploads for this endpoint. Any extra body fields are ignored: only `display_name` is read.

Name derivation (code-verified, same formula as create): the stored machine `name` is recomputed as `strtolower(str_replace(' ', '_', $request->display_name['en']))` — e.g. `"Updated Role"` → `"updated_role"` — and `guard_name` is left untouched. The same missing-`en` edge as create applies (code-traced): omitting `en` passes validation and derives an empty-string `name`.

## Request examples

Valid request updating a role:

```json
{
  "method": "PUT",
  "url": "/api/v1/roles/9",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "Content-Type": "application/json",
    "lang": "en"
  },
  "body": {
    "display_name": {
      "en": "Updated Role",
      "ar": "دور محدث"
    }
  }
}
```

# Responses

## Success: role updated — HTTP 200

Returned via `ApiResponse::apiResponse(ROLE_UPDATED_SUCCESSFULLY, 200, true, RoleResource::make($role))`. Verified by `test_super_admin_can_update_role` (`assertOk`, `success: true`, `assertJsonPath('data.display_name', '{"en":"Updated Role","ar":"دور محدث"}')`). There is exactly one success contract.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.ROLE_UPDATED_SUCCESSFULLY` (locale from `lang` header) | `en` → `"Role updated successfully"`, `ar` → `"تم تحديث الدور بنجاح"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present on success | The updated role serialized by `RoleResource` | `id`, `name` (recomputed), `display_name` (raw JSON string of the new translations), `created_at`, `updated_at`, `permissions` omitted (the relation is never loaded here — code-verified; note: no `guard_name` key since 2026-10-10) |

Complete success example (`en` locale; timestamps illustrative, all other values verified):

```json
{
  "status": 200,
  "message": "Role updated successfully",
  "success": true,
  "data": {
    "id": 9,
    "name": "updated_role",
    "display_name": "{\"en\":\"Updated Role\",\"ar\":\"دور محدث\"}",
    "created_at": "2026-10-10T12:00:00.000000Z",
    "updated_at": "2026-10-10T12:00:00.000000Z"
  }
}
```

An internal audit entry is recorded via the role observer (`App\Observers\RoleObserver`), which has no externally observable HTTP effect.

# Errors

The implementation has no machine-readable error-code field; every error below has Error code `None`. The 200/404 contracts are test-verified (both tests correctly use `putJson`); all other rows are code-verified or code-traced as marked.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token (`auth:sanctum` rejects before the controller runs) | `{message: string, status: boolean}` (Handler shape; note boolean `status`, unlike the success envelope) | None | `"Unauthenticated"` via `AuthenticationException` branch of `App\Exceptions\Handler` (no update-specific 401 test exists; identical rejection test-verified on sibling roles routes) | None | Any request without a valid `Authorization: Bearer` token | See 401 example |
| 403 | Authenticated caller lacks the `update-roles` permission (Spatie `permission:update-roles` middleware rejects before the method body runs) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS` via `Handler::messageForSpatieUnauthorized` (no permission-name suffix: `display_permission_in_exception` is `false`): `en` → `"User does not have the required permissions."`, `ar` → `"لا يمتلك المستخدم الصلاحيات المطلوبة."` | None | Valid token + user without `update-roles` (no update-specific 403 test exists; the identical middleware/Handler path is test-verified on the sibling assign-role endpoint expecting 403) | See 403 example |
| 404 | No `api`-guard role matches `{id}`: `Role::findById($id, 'api')` throws `RoleDoesNotExist`/`ModelNotFoundException`, which `updateRole` converts to `MarvelException(NOT_FOUND)` → `Handler` renders 404 with the translated notice. Takes precedence over 422 (lookup runs before validation — code-verified order) | `{message: string, status: boolean}` | None | Translated `ERROR.NOT_FOUND`: `en` → `"Not found"`, `ar` → `"غير موجود"` (file-verified translation values; end-to-end rendering code-traced — the test asserts status only) | None | Nonexistent `{id}` regardless of body validity; verified by `test_update_nonexistent_role_returns_error` expecting 404 for id `99999` with an otherwise-valid body | See 404 example |
| 405 | `POST` or `PATCH` on `/api/v1/roles/{id}` (no such routes are registered; note `GET /roles/{id}` is the separate show endpoint and `DELETE /roles/{id}` is the separate destroy endpoint, so those do not 405) | `{message: string, status: boolean}` | None | `"Method Not Allowed"` via `MethodNotAllowedHttpException` branch of `Handler` | None | Bare-path `POST`/`PATCH` with an id segment (code-traced) | See 405 example |
| 422 | Any `display_name` validation failure on an existing role, rendered by the `ValidationException` branch of `Handler` (message = `ValidationException::summarize`) with the full map under `errors` | `{message: string, status: boolean, errors: object}` | None | First failure message (dynamic per failure; the identical rule set is test-verified to 422 on the sibling create-role endpoint for a non-array value) | Map of `display_name` / `display_name.{locale}` → message arrays (key verified from rule targets; exact messages **Unverified in source** — no update-specific 422 test exists) | Missing/non-array `display_name`, empty/non-string locale value, or a locale value already used by another role (self excluded via `->ignore($id)`, so resubmitting unchanged translations passes — code-traced) | See 422 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` (rate-limiter shape; note `success`/`data` keys instead of the Handler `status` shape) | None | `__('message.too_many_requests')`: `en` → `"Too many requests. Please try again later."`, `ar` → `"طلبات كثيرة جدًا. يرجى المحاولة لاحقًا."` | None | Rate limit exceeded for the caller key | See 429 example |
| 500 | Any other exception inside the `try` block, caught by the method's own `catch (\Exception $e)` which returns — not throws — so this uses the `apiResponse` envelope, unlike the 401/403/404 errors on this endpoint | `{status: integer, message: string, success: boolean}` (note: `status` integer + `success` flag, no `data` key) | None | Translated `MESSAGE.SOMETHING_WENT_WRONG`: `en` → `"Something went wrong."`, `ar` → `"حدث خطأ ما"` | None | Post-lookup internal failure (e.g. `save()` failure — code-traced, no test) | See 500 example |

401 example (no or invalid token):

```json
{
  "message": "Unauthenticated",
  "status": false
}
```

403 example (authenticated without `update-roles` permission; `en` locale):

```json
{
  "message": "User does not have the required permissions.",
  "status": false
}
```

404 example (nonexistent `{id}`; `en` locale):

```json
{
  "message": "Not found",
  "status": false
}
```

405 example (unregistered method, e.g. `POST /api/v1/roles/9`):

```json
{
  "message": "Method Not Allowed",
  "status": false
}
```

422 example (`display_name` sent as a string instead of an object, on an existing role):

```json
{
  "message": "The display name must be an array.",
  "status": false,
  "errors": {
    "display_name": [
      "The display name must be an array."
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

500 example (post-lookup internal failure):

```json
{
  "status": 500,
  "message": "Something went wrong.",
  "success": false
}
```
