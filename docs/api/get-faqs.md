# Endpoint Name

FAQs – List FAQs (`GET /api/v1/faqs`)

# Description

Returns a paginated, cached list of FAQs with locale-string fields and optional sorting.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/faqs` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `faqs` from `Route::apiResource('faqs', ...)` in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group, plus route-level ANY-of pipe middleware (constructor `view-faqs` gate decides) |
| Controller action | `Marvel\Http\Controllers\FaqsController@index` → optional `orderBy` from an allowlist, `FaqsRepository::paginate($limit)` with `withQueryString` (SoftDeletes scope active — trashed excluded, verified by the FAQ delete test asserting `assertSoftDeleted`), cached per full URL (`remember(FAQS, md5(fullUrl))`); banner-style manual meta-flattening envelope |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-faqs` via controller constructor (applies to `index` and `show`); the route pipe lists `view-faqs\|create-faq\|update-faq\|delete-faq` but the constructor gate is decisive |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`; also selects the `faq_title`/`faq_description` locale strings) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `faqs.index` (apiResource default naming — and NOTE: `FaqResource` checks `routeIs('faqs.index')`, TRUE here, so both translatable fields are locale STRINGS on index) |

## Request inputs

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect | `en`, `ar` | `en` | None | `en` | Selects message locale and field locales |
| `Accept` | header | string | Recommended | No | Content negotiation only | `application/json` | None | None | `application/json` | Requests a JSON response |
| `limit` | query | integer | Optional | No | Falsy → `10` (`$request->limit ? ... : 10`; no clamp, no `per_page` — code-verified) | Any positive integer | `10` | None | `10` | Page size |
| `order` | query | string | Optional | No | Allowlist `['id', 'faq_title', 'faq_description', 'status', 'created_at', 'updated_at']` — anything else SILENTLY ignored (code-verified) | Allowlisted columns | DB default order | None | `id` | Sort column |
| `sortedBy` | query | string | Optional | No | `'desc'` → desc, ANYTHING else → asc (code-verified) | `asc`, `desc` | `asc` | Only meaningful with `order` | `desc` | Sort direction |

No body fields, path parameters, or file uploads. No `422` contract.

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/faqs?limit=10",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: page returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [...])` with the banner-style custom envelope. Verified by `can_list_faqs` (`assertOk`, `success: true`, `data.data` count 2, `getJson`).

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` | `en` → `"Data fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present | Pagination envelope (same flattened shape as banners) | Keys below |
| `data.data` | array | Always present (possibly empty) | Items by `FaqResource`; route IS `faqs.index`, so `faq_title`/`faq_description` are locale STRINGS | Item: `id`, `faq_title` (string), `faq_description` (string), `status` (INT, not bool — `(int)` cast, code-verified), `order` (int) |
| `data.page` / `data.current_page` / `data.from` / `data.to` / `data.last_page` / `data.total` | integers | Always present | Paginator meta | Paginator values |
| `data.path` / `data.next_page_url` / `data.prev_page_url` / `data.last_page_url` / `data.first_page_url` | strings | Always present (possibly `""`) | Paginator links | URLs or `""` |
| `data.per_page` | integer | Always present | Page size echoed back | The effective `limit` |

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "data": [
      {
        "id": 1,
        "faq_title": "FAQ One",
        "faq_description": "Desc One",
        "status": 1,
        "order": 1
      }
    ],
    "page": 1,
    "current_page": 1,
    "from": 1,
    "to": 1,
    "last_page": 1,
    "path": "http://localhost/api/v1/faqs",
    "per_page": 10,
    "total": 1,
    "next_page_url": "",
    "prev_page_url": "",
    "last_page_url": "http://localhost/api/v1/faqs?page=1",
    "first_page_url": "http://localhost/api/v1/faqs?page=1"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`. No validation, so no `422`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token | `{message: string, status: boolean}` | None | `"Unauthenticated"` via `AuthenticationException` branch | None | Any request without a valid token (verified by `unauthenticated_user_cannot_index_faqs`, `getJson` → 401) | See 401 example |
| 403 | Authenticated caller lacks `view-faqs` (constructor gate rejects after the route pipe) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS`: `en` → `"User does not have the required permissions."` | None | User with an unrelated permission (verified by `user_with_no_faq_permissions_gets_forbidden` → 403) | See 403 example |
| 429 | Exceeding `throttle:admin` (400/min per user id or IP) | `{success: boolean, message: string, data: null}` | None | `__('message.too_many_requests')` | None | Rate limit exceeded for the caller key | See 429 example |

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

429 example:

```json
{
  "success": false,
  "message": "Too many requests. Please try again later.",
  "data": null
}
```
