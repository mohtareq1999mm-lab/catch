# Endpoint Name

Coupons – List Coupons (`GET /api/v1/coupons`)

# Description

Returns a paginated, cached list of coupons under the strictest index validation in the API.

# Request

## Request metadata

| Property | Value |
| --- | --- |
| HTTP method | `GET` |
| Full path | `/api/v1/coupons` |
| Path composition | `api/v1` prefix from `packages/marvel/src/Providers/RestAPIServiceProvider.php` + `coupons` from bare `Route::apiResource('coupons', ...)` (NO route middleware — route-inventory confirms group middleware only; the in-controller `view-coupons` gate decides) in `packages/marvel/src/Rest/Routes.php` inside the `['auth:sanctum', 'throttle:admin', 'lang']` group |
| Controller action | `Marvel\Http\Controllers\CouponController@index(CouponIndexRequest $request)` → `fetchCoupons` (active/inactive scopes, grouped search, `AdminCouponFilter::apply`, allowlisted order) with `assignments` + `targeting` eager-loaded (read-only N+1 guard), paginated, cached per full URL (`remember(COUPONS, md5(fullUrl))`); banner-style manual meta-flattening envelope |
| Authentication | Required. Sanctum bearer token via `auth:sanctum` |
| Permission | Required once: `view-coupons` via controller constructor (applies to `index` and `show`); no route-level permission exists on this action |
| Required headers | `Authorization: Bearer <sanctum-token>` |
| Recommended headers | `Accept: application/json` |
| Optional headers | `lang: en\|ar` (default `en`; also selects the `name` locale string and search locale) |
| Supported request formats | No body is read; `Content-Type` is irrelevant |
| Rate limit | `throttle:admin`: 400 requests/minute per authenticated user id (or per IP for guests), per `App\Providers\AppServiceProvider::boot` |
| Route name | `coupons.index` (apiResource default naming) |

## Request inputs

`CouponIndexRequest` validates BEFORE the body (unlike most indexes, which validate nothing): `limit` outside `1`–`100`, an off-allowlist `order`/`sortedBy`, a malformed date, or a contradictory range (from > to, min > max) all 422. Boolean spellings `"true"`/`"false"` are normalized pre-validation (so `?active=true` works); anything else must satisfy the `boolean` rule. No endpoint test found — contracts code-traced.

| Field | Location | Data type | Required | Nullable | Rules | Allowed values | Default | Conditional rules | Example | Meaning |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `Authorization` | header | string | Required | No | Bearer Sanctum token enforced by `auth:sanctum` | Any valid token | None | None | `Bearer 1\|abcdef123456` | Authenticates the caller |
| `lang` | header | string | Optional | No | Must be in `['en', 'ar']` to take effect | `en`, `ar` | `en` | None | `en` | Selects message locale and `name` locale string |
| `limit` | query | integer | Optional | No | `sometimes\|integer\|min:1\|max:100` (VALIDATED — `limit=0` or `200` 422s; code-verified strictness shared only with currencies) | `1`–`100` | `15` | None | `15` | Page size |
| `active` | query | boolean | Optional | No | `sometimes\|boolean` → `valid()` scope when true | `true`, `false`, `1`, `0` | None (no filter) | None | `true` | Currently-redeemable filter |
| `inactive` | query | boolean | Optional | No | `sometimes\|boolean` → grouped `invalid()` scope when true | `true`, `false`, `1`, `0` | None (no filter) | None | `true` | Non-redeemable filter |
| `search` | query | string | Optional | No | `sometimes\|string\|max:191` → grouped (locale `name` LIKE OR `code` LIKE; grouping prevents code matches bypassing sibling filters — code-verified) | Any string ≤ 191 chars | None (no filter) | None | `SAVE` | Name/code search |
| `order` | query | string | Optional | No | `sometimes\|string\|Rule::in(id, code, name, discount, discount_type, start_date, end_date, limiter, used, status, created_at, updated_at)` — off-list values 422 (VALIDATED, unlike flash-sale/FAQ ordering) | Allowlisted columns | DB default (`updated_at DESC` global scope) | None | `created_at` | Sort column |
| `sortedBy` | query | string | Optional | No | `sometimes\|string\|Rule::in(asc, desc)` — anything else 422s | `asc`, `desc` | `asc` | None | `desc` | Sort direction |
| `is_valid` | query | boolean | Optional | No | `sometimes\|boolean` (mirrors the validity scopes exactly) | Booleans | None | None | `true` | Validity filter |
| `status` | query | boolean | Optional | No | `sometimes\|boolean` (exact flag match — distinct from validity) | Booleans | None | None | `true` | Enabled-flag filter |
| `start_date` / `end_date` | query | date | Optional | No | `sometimes\|date_format:Y-m-d` (null columns never match) | `Y-m-d` dates | None | None | `"2026-01-01"` | Exact-date filters |
| `start_date_from/to`, `end_date_from/to`, `date_from/to` | query | date | Optional | No | `sometimes\|date_format:Y-m-d` pairs; contradictory from > to is a 422 (explicit `withValidator` rejection — malformed input, not empty results) | `Y-m-d` dates | None | None | `"2026-01-01"` | Date-range filters |
| `discount_type` | query | string | Optional | No | `sometimes\|Rule::in(fixed_rate, percentage)` (persisted values, never translated labels) | `fixed_rate`, `percentage` | None | None | `"percentage"` | Discount-kind filter |
| `discount`, `discount_min/max`, `max_discount_amount_min/max` | query | number | Optional | No | `sometimes\|numeric\|min:0` (+ min > max rejection) | Numbers ≥ 0 | None | None | `50` | Discount value filters |
| `limiter`, `used`, `limiter_min/max`, `used_min/max`, `remaining_min/max` | query | integer | Optional | No | `sometimes\|integer\|min:0` (+ min > max rejection; null limiter counts as +infinity for `remaining`) | Integers ≥ 0 | None | None | `100` | Usage/limit filters |
| `expired` | query | boolean | Optional | No | `sometimes\|boolean` (past `end_date` ONLY — narrower than invalid) | Booleans | None | None | `true` | Expiry filter |
| `audience_type` | query | string | Optional | No | `sometimes\|Rule::in(PUBLIC, ASSIGNED, TARGETED, PUBLIC_AND_ASSIGNED, PUBLIC_AND_TARGETED, ASSIGNED_AND_TARGETED, PUBLIC_AND_ASSIGNED_AND_TARGETED)` | Composite labels | None | None | `"ASSIGNED"` | Audience filter |
| `is_public`, `has_assignments`, `has_targeting` (+ aliases `is_assigned`, `assignments`, `targeting`) | query | boolean | Optional | No | `sometimes\|boolean`, AND-combined | Booleans | None | None | `true` | Audience-presence filters |
| `targeting_mode` | query | string | Optional | No | `sometimes\|Rule::in(assignment, dynamic, assignment_and_dynamic, assignment_or_dynamic)` (eligibility only, never publicity) | Mode values | None | None | `"dynamic"` | Targeting-mode filter |
| `assigned_user_id` | query | integer | Optional | No | `sometimes\|integer\|min:1` (exists-subquery; no PII output) | User id ≥ 1 | None | None | `7` | Assignee filter |
| `require_claim` | query | boolean | Optional | No | `sometimes\|boolean` (lives on the targeting row) | Booleans | None | None | `true` | Claim-requirement filter |

## Request examples

```json
{
  "method": "GET",
  "url": "/api/v1/coupons?limit=15&active=true",
  "headers": {
    "Authorization": "Bearer 1|abcdef123456",
    "Accept": "application/json",
    "lang": "en"
  }
}
```

# Responses

## Success: page returned — HTTP 200

Returned via `ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, [...])` with the banner-style custom envelope (`data` is an OBJECT). Results cached per full URL.

| Field | Data type | Presence | Meaning | Possible values / notes |
| --- | --- | --- | --- | --- |
| `status` | integer | Always present | Mirrors the HTTP status | Always `200` on success |
| `message` | string | Always present | Translated `MESSAGE.FETCH_DATA_SUCCESSFULLY` | `en` → `"Data fetched successfully"` |
| `success` | boolean | Always present | Outcome flag | Always `true` on success |
| `data` | object | Always present | Pagination envelope (same flattened shape as banners) | Keys below |
| `data.data` | array | Always present (possibly empty) | Items by Marvel `CouponResource` (NOT `coupons.show` → `name` is the locale STRING); `assignments` safe-shaped (quotas only, no PII); `targeting` embedded or null | Item: `id`, `code`, `name` (string), `image{desktop, mobile}`, `borderColor`, `borderless`, `discount`, `discount_type` (LOCALIZED label via `typeByLang`, not raw), `max_discount_amount` (2dp/null), `start_date`, `end_date`, `limiter`, `used`, `status`, `is_valid` (computed), `audience{}` (resolver composite), `is_assigned`, `audience_type`, `targeting_mode`, `targeting{}|null`, `assignments[]`, `created_at` |
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
        "code": "COUPON_AB12CD3",
        "name": "Summer Sale",
        "image": { "desktop": null, "mobile": null },
        "borderColor": null,
        "borderless": false,
        "discount": "50.00",
        "discount_type": "Percentage discount",
        "max_discount_amount": 100.0,
        "start_date": "2026-01-01",
        "end_date": "2026-02-01",
        "limiter": 100,
        "used": 3,
        "status": true,
        "is_valid": true,
        "audience": {},
        "is_assigned": false,
        "audience_type": "PUBLIC",
        "targeting_mode": null,
        "targeting": null,
        "assignments": [],
        "created_at": "2026-01-01T00:00:00+00:00"
      }
    ],
    "page": 1,
    "current_page": 1,
    "from": 1,
    "to": 1,
    "last_page": 1,
    "path": "http://localhost/api/v1/coupons",
    "per_page": 15,
    "total": 1,
    "next_page_url": "",
    "prev_page_url": "",
    "last_page_url": "http://localhost/api/v1/coupons?page=1",
    "first_page_url": "http://localhost/api/v1/coupons?page=1"
  }
}
```

# Errors

No error-code field; every error below has Error code `None`.

| HTTP status | Trigger | Response body | Error code | Message | Field errors | Conditions | Example |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 401 | Missing, invalid, revoked, or expired Sanctum token | `{message: string, status: boolean}` | None | `"Unauthenticated"` via `AuthenticationException` branch | None | Any request without a valid token (code-traced; no coupon endpoint test found) | See 401 example |
| 403 | Authenticated caller lacks `view-coupons` (constructor gate rejects; no route pipe exists to satisfy instead) | `{message: string, status: boolean}` | None | Translated `ERROR.PERMISSION_MISSING_PERMISSIONS`: `en` → `"User does not have the required permissions."` | None | Valid token without `view-coupons` (code-traced) | See 403 example |
| 422 | `CouponIndexRequest` fails (`limit` outside 1–100, off-allowlist `order`/`sortedBy`, malformed date, contradictory range, non-boolean flag) | `{message: string, status: boolean, errors: object}` | None | Laravel default (+ explicit after-or-equal/gte messages for contradictory ranges) | Field → messages | Invalid query (code-traced; the strictest index validation in this API) | See 422 example |
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

422 example:

```json
{
  "message": "The limit field must not be greater than 100.",
  "status": false,
  "errors": {
    "limit": ["The limit field must not be greater than 100."]
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
