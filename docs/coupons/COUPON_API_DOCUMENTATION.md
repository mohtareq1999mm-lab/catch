# Coupon REST API Documentation

> All examples below are derived from the actual source (Controllers + Resources + FormRequests + lang/en). No endpoint or field is invented.
> Envelope rule for all coupon controllers (`Marvel\Traits\ApiResponse::apiResponse`):
> `{"status": <HTTP code as integer>, "message": "<translated>", "success": bool, "data"?: ...}`.
> All `message` strings are translated per locale — examples here are in English as in `resources/lang/en/message.php`.
> Sanctum `401` always has body: `{"message":"Unauthenticated."}`. `403` comes from Spatie or from the in-controller permission check.

---

## 1. Admin — List Coupons

### `GET /api/v1/coupons`

**Purpose:** Fetch the coupon list for admin, with `audience_type` and `targeting_mode` per row.

### Headers

```http
Authorization: Bearer {admin_token}
Accept: application/json
```

### Query Parameters

```text
EXISTING (preserved):
limit      optional, integer 1..100 (default 15; was unbounded)
active     optional boolean (valid coupons)
inactive   optional boolean (invalid coupons)
search     optional, name translations + code (max 191)
order      optional, one of: id code name discount discount_type
           start_date end_date limiter used status created_at updated_at
sortedBy   optional asc|desc (default asc)

NEW (all AND-combined; malformed → 422):
is_valid, expired,
start_date_from/to, end_date_from/to, date_from/to (overlap),
discount_type (fixed_rate|percentage), discount_min/max,
max_discount_amount_min/max, limiter_min/max, used_min/max,
remaining_min/max (null limiter = +infinity),
audience_type (7 states), is_public, has_assignments, has_targeting,
targeting_mode (4 modes), assigned_user_id, require_claim

NOT IMPLEMENTED (deliberate): status (dup of active/inactive),
currently_active/not_expired/starting_soon (use is_valid/expired),
max_claims/claim_ttl_hours, rule_tree, per-assignment date filters,
audience sorting (PHP-computed).
```

### Request

```http
GET /api/v1/coupons?limit=15&active=1&search=SUMMER&order=created_at&sortedBy=desc
GET /api/v1/coupons?active=1&audience_type=PUBLIC_AND_ASSIGNED&discount_type=percentage&discount_min=10&discount_max=50&order=created_at&sortedBy=desc
```

### Success — `200 OK`

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "data": [
      {
        "id": 123,
        "code": "SUMMER50",
        "name": "Summer Coupon",
        "image": { "desktop": "https://.../summer-desktop.webp", "mobile": "https://.../summer-mobile.webp" },
        "borderColor": null,
        "borderless": false,
        "discount": 50,
        "discount_type": "Fixed discount",
        "max_discount_amount": null,
        "start_date": "2026-09-26",
        "end_date": "2026-10-26",
        "limiter": 100,
        "used": 4,
        "status": true,
        "is_valid": true,
        "audience": {
          "type": "ASSIGNED_AND_TARGETED",
          "is_public": false,
          "has_assignments": true,
          "has_targeting": true
        },
        "is_assigned": true,
        "audience_type": "ASSIGNED_AND_TARGETED",
        "targeting_mode": "assignment_and_dynamic",
        "targeting": {
          "id": 50,
          "coupon_id": 123,
          "mode": "assignment_and_dynamic",
          "require_claim": true,
          "max_claims": 1000,
          "claim_ttl_hours": 24,
          "rule_tree": { "operator": "AND", "rules": [] },
          "created_at": "2026-09-26T12:00:00+00:00",
          "updated_at": "2026-09-26T12:00:00+00:00"
        },
        "assignments": [
          { "id": 10, "coupon_id": 123, "user_id": 55, "max_uses": 2, "used": 0, "remaining": 2, "expires_at": null, "assigned_at": "2026-09-26T12:00:00+00:00" }
        ],
        "created_at": "2026-09-26T10:00:00+00:00"
      }
    ],
    "current_page": 1,
    "from": 1,
    "to": 1,
    "last_page": 1,
    "per_page": 15,
    "total": 1
  }
}
```

Fixed notes from source: `status` is boolean (not `1/0`). `discount_type` is a locale-translated label (`Fixed discount` en / Arabic label ar) — request values are `fixed_rate|percentage`. `name` here is a string in the current request locale. `audience` is the authoritative composite object (type + 3 capability flags, resolver-owned). `is_assigned`/`audience_type` are legacy compatibility fields; `targeting_mode` carries eligibility mode only. Embedded `assignments` are shaped rows (`id,coupon_id,user_id,max_uses,used,remaining,expires_at,assigned_at` — no user PII, no timestamps).

### Errors

```http
401 Unauthorized
```

```json
{ "message": "Unauthenticated." }
```

```http
403 Forbidden
```

Missing `view-coupons` permission (Spatie route middleware).

### Validation — `422`

Malformed filters are rejected (Marvel shape, no envelope):

```json
{ "message": "The given data was invalid.", "errors": { "order": ["The selected order is invalid."] } }
```

Invalid: unknown enums (`order`, `discount_type`, `targeting_mode`, `audience_type`, `sortedBy`), non-boolean flags, bad `Y-m-d` dates, contradictory ranges (`date_from` after `date_to`, `min` above `max`), `limit` outside 1..100.

### What does it do?

```text
Admin
 ↓
GET /coupons
 ↓
CouponRepository::modelQuery (+ filter/search/order)
 ↓
READ coupons + eager assignments + eager targeting
 ↓
CouponResource (audience_type/targeting_mode are computed)
 ↓
Response (read from COUPONS cache keyed by URL)
```

Read-only. No Notification, no Queue, no Pusher. Next step: `GET {id}`, `PUT {id}/targeting`, Assignments, or `distribute`.

---

## 2. Admin — Create Coupon

### `POST /api/v1/coupons`

**Purpose:** Create the Base Coupon only.

> The API creates the coupon first; Assignment/Targeting come later via separate endpoints. There is deliberately no `mode` field here.

### Headers

```http
Authorization: Bearer {admin_token}
Accept: application/json
Content-Type: multipart/form-data
```

### Request (`CouponRequest` — `create-coupon` permission)

```text
name[en] = Summer Coupon            (required + unique translation)
name[ar] = <Arabic name>            (required + unique translation)

discount = 50                        (required, numeric >= 0)
discount_type = fixed_rate           (required: fixed_rate|percentage)

max_discount_amount =                (required_if discount_type=percentage, numeric >= 1)

start_date = 2026-09-26              (required, Y-m-d)
end_date = 2026-10-26                (required, Y-m-d, >= start_date)

limiter = 100                        (nullable, integer >= 0)
status = 1                           (sometimes, 0|1)
is_public = false                    (sometimes, boolean; independent public-discoverability flag, default false)

image-desktop = summer-desktop.webp  (REQUIRED image jpeg|png|jpg|webp|gif)
image-mobile = summer-mobile.webp    (REQUIRED image jpeg|png|jpg|webp|gif)
```

The `code` is **always server-generated** (`Coupon::creating`) — any submitted `code` is ignored.

### Success — `201 Created`

```json
{
  "status": 201,
  "message": "Coupon created successfully",
  "success": true,
  "data": {
    "id": 123,
    "code": "SUMMER50",
    "name": "Summer Coupon",
    "discount": 50,
    "discount_type": "Fixed discount",
    "status": true,
    "is_valid": true,
    "audience": {
      "type": "PUBLIC",
      "is_public": false,
      "has_assignments": false,
      "has_targeting": false
    },
    "is_assigned": false,
    "audience_type": "PUBLIC",
    "targeting_mode": null,
    "targeting": null,
    "assignments": []
  }
}
```

### Important

A fresh coupon is `(false, false, false)` → `PUBLIC` (existing rule: unconfigured coupons are publicly discoverable). Setting `is_public: true` opts into explicit publicity that survives later assignments.

### Validation Error — `422`

`CouponRequest::failedValidation` returns validator errors **directly with no envelope**:

```json
{
  "name.en": ["The name field is required."],
  "discount": ["The discount field is required."]
}
```

### Failure — `400`

```json
{ "status": 400, "message": "Could not create the resource", "success": false }
```

(Any failure inside `storeCoupon` rolls back fully — no half-created coupon.)

### Important

Right after creation:

```text
assignments = 0
targeting = 0
        ↓
audience_type = PUBLIC   (computed — not stored)
```

And internally (Observer, after commit): audit + `CouponCreated` (the `coupon.available` listener defers it for the 4-minute maturity window) + `CouponActivated` when `status=1` (starts the distribution path, which skips non-distributable coupons until targeting lands).

---

## 3. Admin — Coupon Details

### `GET /api/v1/coupons/{coupon}` — `view-coupons` permission

### Request (id **or** code)

```http
GET /api/v1/coupons/123
```

or:

```http
GET /api/v1/coupons/SUMMER50
```

### Success — `200`

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 123,
    "code": "SUMMER50",
    "name": { "ar": "<Arabic name>", "en": "Summer Coupon" },
    "discount": 50,
    "discount_type": "Fixed discount",
    "status": true,
    "is_valid": true,
    "audience": {
      "type": "PUBLIC_AND_ASSIGNED",
      "is_public": true,
      "has_assignments": true,
      "has_targeting": false
    },
    "is_assigned": true,
    "audience_type": "PUBLIC_AND_ASSIGNED",
    "targeting_mode": null,
    "targeting": null,
    "assignments": [
      { "id": 10, "coupon_id": 123, "user_id": 55, "max_uses": 2, "used": 0, "remaining": 2, "expires_at": null, "assigned_at": "2026-09-26T12:00:00+00:00" }
    ]
  }
}
```

`targeting: null` whenever no targeting row exists (`targeting_mode` then also null and carries only eligibility mode, never publicity).

Note: `name` here is an `{ar,en}` object (only on `coupons.show`) — unlike the index.

### Errors

`401` / `403` / `404` (`{"status":404,"message":"Not found","success":false}`).

---

## 4. Admin — Update Coupon

### `PUT /api/v1/coupons/{coupon}` — `update-coupon` permission

### Request (`UpdateCouponRequest` — all fields `sometimes`)

```http
PUT /api/v1/coupons/123
Content-Type: application/json
```

```json
{
  "discount": 60,
  "status": 1,
  "end_date": "2026-11-01",
  "is_public": true
}
```

`is_public` is `sometimes|boolean` — flipping it never touches assignments, targeting, or status.

### Success — `200`

```json
{
  "status": 200,
  "message": "Coupon updated successfully",
  "success": true,
  "data": {
    "id": 123,
    "code": "SUMMER50",
    "discount": 60,
    "status": true,
    "is_valid": true,
    "audience": {
      "type": "PUBLIC_AND_ASSIGNED",
      "is_public": true,
      "has_assignments": true,
      "has_targeting": false
    },
    "is_assigned": true,
    "audience_type": "PUBLIC_AND_ASSIGNED",
    "targeting_mode": null,
    "targeting": null
  }
}
```

### Errors

`401` / `403` / `404` / `422` (same shape as create) / `400` (`Could not update the resource`).

If `status` flips from `1 → 0`:

```text
CouponDisabled
 ↓
cancel in-flight distribution runs
```

And if `0 → 1`:

```text
CouponActivated
 ↓
activation fan-out (StartCouponDistribution)
```

Any update invalidates the discovery cache (`CouponDiscoveryCache::invalidate`) + writes an audit row.

---

## 5. Admin — Delete Coupon

### `DELETE /api/v1/coupons/{coupon}` — `delete-coupon` permission

### Request

```http
DELETE /api/v1/coupons/123
```

### Success — `200` (no `data`)

```json
{
  "status": 200,
  "message": "Coupon deleted successfully",
  "success": true
}
```

### Errors

`401` / `403` / `404`.

⚠️ Operational caution (not a code change): deleting a coupon that has usage history loses audit context — prefer disabling it with `status=0`. The observer records `deleted` and invalidates the cache only (no distribution event).

---

## 6. Admin — Get Rule Catalog

### `GET /api/v1/coupons/rules` (alias `GET /api/v1/admin/coupons/rules` — same action)

In-controller permission: `view-coupons|update-coupon|create-coupon`.

### Request

```http
GET /api/v1/coupons/rules
```

### Response — `200`

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "rules": [
      {
        "type": "min_completed_orders",
        "label": { "en": "Minimum completed orders", "ar": "<Arabic label>" },
        "description": { "en": "...", "ar": "..." },
        "value_type": "integer",
        "value_required": true,
        "value_example": 3,
        "min": 0,
        "max": null,
        "allowed_values": null,
        "date_format": null,
        "context": "customer_history",
        "evaluation": { "claim": true, "apply": true, "checkout": true, "fast_checkout": true, "defers_without_context": false }
      }
    ],
    "rule_tree": {
      "supported": true,
      "operators": ["AND", "OR"],
      "max_depth": 10,
      "nested_groups_allowed": true,
      "empty_group_allowed": false,
      "null_allowed": true,
      "duplicate_rules_allowed": true,
      "unknown_rule_behavior": "reject_422",
      "malformed_node_behavior": "reject_422"
    }
  }
}
```

17 rule types total (`min_completed_orders`, `max_completed_orders`, `min_total_spend`, `max_total_spend`, `first/last_order_after/before`, `min/max_coupons_used`, `not_claimed`, `claimed`, `has_assignment`, `area_in`, `has_email`, `registered_after`, `registered_before`). The frontend uses this to build the Rule Builder. Fully static — no customer data is read.

---

## 7. Validate Configuration

### `POST /api/v1/coupons/validate-configuration` (alias under `/admin/`)

In-controller permission: view family (`view-coupons|update-coupon|create-coupon`).

### Request

```json
{
  "coupon_type": "assigned",
  "limiter": 1000,
  "max_uses_per_user": 3
}
```

Rules: `coupon_type` required ∈ {`public`,`assigned`}; `limiter` nullable int ≥ 1; `max_uses_per_user` nullable int ≥ 1.

### Response — `200` (always 200, even with problems)

Valid case:

```json
{
  "status": 200,
  "message": "Validation completed.",
  "success": true,
  "data": {
    "valid": true,
    "errors": [],
    "warnings": [],
    "recommendations": [
      { "title": "Multi-Use Configuration", "description": "Each assigned customer can redeem this coupon up to 3 times. You must create coupon assignments with max_uses=3 for each eligible customer." }
    ]
  }
}
```

Invalid case (`coupon_type: public` + `max_uses_per_user: 3`):

```json
{
  "status": 200,
  "message": "Validation completed.",
  "success": false,
  "data": {
    "valid": false,
    "errors": [
      { "field": "max_uses_per_user", "message": "Public coupons only support single use per customer." }
    ],
    "warnings": [],
    "recommendations": [
      { "title": "Public Coupon Behavior", "description": "Each customer can redeem this coupon exactly once. Global capacity: 1000 total redemptions across all customers." }
    ]
  }
}
```

Pure advisory function — no writes. `errors[]`/`warnings[]` are `{field,message}` objects; `recommendations[]` are `{title,description}` objects.

---

## 8. Get Usage Info

### `GET /api/v1/coupons/{id}/usage-info` (alias under `/admin/`)

In-controller permission: view family.

### Request

```http
GET /api/v1/coupons/123/usage-info
```

### Response — `200`

```json
{
  "status": 200,
  "message": "Usage info retrieved.",
  "success": true,
  "data": {
    "coupon_code": "SUMMER50",
    "coupon_type": "assigned",
    "usage_model": "<usage description string>",
    "current_usage": 4,
    "global_limit": 100,
    "remaining_capacity": 96,
    "is_multi_use_per_user": true,
    "assignment_info": {
      "total_assignments": 2,
      "assignments_with_usage": 1,
      "max_uses_per_user": 2,
      "total_possible_redemptions": 4
    },
    "public_usage_count": 0
  }
}
```

`coupon_type` comes from the frozen `isPublic()`; `remaining_capacity` is `"unlimited"` when no limiter; `assignment_info` is `null` for public coupons. Read-only.

---

## 9. Suggest Fix

### `POST /api/v1/coupons/{id}/suggest-fix` (alias under `/admin/`)

In-controller permission: view family. Business guidance only — never modifies anything.

### Request

```json
{ "desired_behavior": "multi_use_per_user" }
```

`desired_behavior` ∈ {`multi_use_per_user`,`single_use_per_user`}.

### Response — `200`

```json
{
  "status": 200,
  "message": "Suggestion generated.",
  "success": true,
  "data": {
    "current_state": "The current assignments allow one use per customer.",
    "recommended_action": "update_assignments",
    "summary": "Raise the per-customer usage limit on the existing assignments.",
    "steps": ["Open the coupon customer assignments list.", "Select the assignments that should support multiple uses.", "Increase the maximum allowed uses for each selected customer."],
    "expected_result": "The selected customers can use the coupon multiple times, up to their configured limit.",
    "warnings": ["Assignments can only be updated one at a time; there is no bulk update. No automatic change was made."],
    "available_actions": [
      { "label": "List coupon assignments", "method": "GET", "path": "/api/v1/coupons/123/assignments" },
      { "label": "Update coupon assignment", "method": "PUT", "path": "/api/v1/coupons/123/assignments/{assignmentId}" }
    ]
  }
}
```

`recommended_action` ∈ {`convert_to_assigned`,`no_change_needed`,`update_assignments`}. Invalid behavior → `400` (`Invalid desired behavior.` + `allowed_values`).

---

## 10. Get Targeting

### `GET /api/v1/coupons/{id}/targeting` (alias under `/admin/`)

Read permission: view family (`view-coupons|update-coupon|create-coupon`).

### Request

```http
GET /api/v1/coupons/123/targeting
```

### Response — `200`

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "id": 50,
    "coupon_id": 123,
    "mode": "assignment_and_dynamic",
    "require_claim": true,
    "max_claims": 1000,
    "claim_ttl_hours": 24,
    "rule_tree": {
      "operator": "AND",
      "rules": [{ "type": "area_in", "operator": "in", "value": [1, 2] }]
    },
    "created_at": "2026-09-26T12:00:00+00:00",
    "updated_at": "2026-09-26T12:00:00+00:00"
  }
}
```

### Errors

`401` / `403` / `404` coupon-missing (framework) / `404` (`This coupon has no targeting configuration.`).

---

## 11. Create/Update Targeting

### `PUT /api/v1/coupons/{id}/targeting` (alias under `/admin/`)

Write permission: `update-coupon|create-coupon` (a bare admin type without the permission is rejected). This is the **only writer of `mode`**.

### Request (`UpsertTargetingRequest`)

```json
{
  "mode": "assignment_and_dynamic",
  "require_claim": true,
  "max_claims": 1000,
  "claim_ttl_hours": 24,
  "rule_tree": {
    "operator": "AND",
    "rules": [{ "type": "area_in", "operator": "in", "value": [1, 2] }]
  }
}
```

Rules: `mode` required ∈ {`assignment`,`dynamic`,`assignment_and_dynamic`,`assignment_or_dynamic`}; `require_claim` required boolean; `max_claims` nullable 1..1,000,000; `claim_ttl_hours` nullable 1..8760; `rule_tree` nullable array — then fail-closed `RuleTreeValidator` (unknown type / depth > 10 → `422` with `errors`).

### Success — `200` (same shape as §10)

### What happens after?

```text
PUT targeting
 ↓
DB transaction (updateOrCreate by coupon_id)
 ↓
CouponTargetingChanged (after commit)
 ↓
StartCouponDistribution (queued)
 ↓
Outbox (240s / 4min delay for trigger runs; manual = immediate)
 ↓
RabbitMQ fan-out
 ↓
Eligibility evaluation
 ↓
`coupon.eligible` for new eligibles
 ↓
Notification → Pusher
```

---

## 12. Delete Targeting

### `DELETE /api/v1/coupons/{id}/targeting` (alias under `/admin/`)

Write permission: `update-coupon|create-coupon`.

### Request

```http
DELETE /api/v1/coupons/123/targeting
```

### Response — `200` (no `data`)

```json
{
  "status": 200,
  "message": "Coupon deleted successfully",
  "success": true
}
```

### Errors

`401` / `403` / `404` (`This coupon has no targeting configuration.`).

After:

```text
targeting = NULL
```

and when there are no assignments either:

```text
audience_type = PUBLIC
```

Removing targeting fires `CouponTargetingChanged` (in-flight targeted runs stop harmlessly) + invalidates discovery cache. Retry after success → `404`.

---

## 13. List Assignments

### `GET /api/v1/coupons/{coupon}/assignments` — `view-coupon-assignments` permission (route + constructor)

### Request

```http
GET /api/v1/coupons/123/assignments?limit=15
```

### Response — `200`

```json
{
  "status": 200,
  "message": "Assignments fetched successfully.",
  "success": true,
  "data": {
    "data": [
      {
        "id": 10,
        "coupon_id": 123,
        "user_id": 55,
        "user": { "id": 55, "name": "Mohamed", "email": "user@example.com" },
        "max_uses": 2,
        "used": 1,
        "remaining": 1,
        "is_expired": false,
        "assigned_at": "2026-09-26T12:00:00+00:00",
        "expires_at": null
      }
    ],
    "current_page": 1,
    "from": 1,
    "to": 1,
    "last_page": 1,
    "per_page": 15,
    "total": 1
  }
}
```

### Errors

`401` / `403` / `404`. Read-only. Next: POST / PUT / DELETE per assignment.

---

## 14. Show Assignment

### `GET /api/v1/coupons/{coupon}/assignments/{assignment}` — `view-coupon-assignments`

### Request

```http
GET /api/v1/coupons/123/assignments/10
```

### Response — `200` (single `CouponAssignmentResource`, same shape as §13 items)

### Errors

`401` / `403` / `404` (a wrong coupon scope also yields `404`).

---

## 15. Assign Coupon

### `POST /api/v1/coupons/{coupon}/assignments` — `create-coupon-assignment`

### Request (`CouponAssignmentRequest`)

```json
{
  "user_id": 55,
  "max_uses": 2,
  "expires_at": "2026-10-26 23:59:59"
}
```

Rules: `user_id` required int `exists:users,id`; `max_uses` required int ≥ 1; `expires_at` nullable date `after:now`. Violation → `422` Marvel shape `{"message":"...","errors":{...}}`.

### Success — `201`

```json
{
  "status": 201,
  "message": "Coupon assigned successfully.",
  "success": true,
  "data": {
    "id": 10,
    "coupon_id": 123,
    "user_id": 55,
    "user": { "id": 55, "name": "Mohamed", "email": "user@example.com" },
    "max_uses": 2,
    "used": 0,
    "remaining": 2,
    "is_expired": false,
    "assigned_at": "2026-09-26T12:00:00+00:00",
    "expires_at": "2026-10-26T23:59:59+00:00"
  }
}
```

Then internally:

```text
DB COMMIT (unique coupon_id+user_id is the race arbiter)
 ↓
CouponAssigned
 ↓
SendUserCouponAssignedNotification (queue: high)
 ↓
UserCouponAssignedNotification → database + FCM + broadcast
 ↓
Pusher → coupon.assigned on users.{id}
```

### Duplicate — `409`

```json
{
  "status": 409,
  "message": "COUPON_ALREADY_ASSIGNED_TO_USER",
  "success": false
}
```

Double-submit yields exactly one row + `409` (the event fires once — no duplicate notify). `404` when coupon/user is missing; `400` generic.

---

## 16. Update Assignment

### `PUT /api/v1/coupons/{coupon}/assignments/{assignment}` — `update-coupon-assignment`

Silent by design — no event, no notification, no Pusher.

### Request (`UpdateCouponAssignmentRequest`)

```json
{
  "max_uses": 5,
  "expires_at": "2026-11-01 23:59:59"
}
```

Rules: `max_uses` sometimes int ≥ 1; `expires_at` nullable future date.

### Response — `200` (resource includes the `user` object)

```json
{
  "status": 200,
  "message": "Assignment updated successfully.",
  "success": true,
  "data": {
    "id": 10,
    "coupon_id": 123,
    "user_id": 55,
    "user": { "id": 55, "name": "Mohamed", "email": "user@example.com" },
    "max_uses": 5,
    "used": 1,
    "remaining": 4,
    "is_expired": false,
    "expires_at": "2026-11-01T23:59:59+00:00"
  }
}
```

Floor breach (`max_uses` < recorded `used`):

```http
422 Unprocessable Entity
```

```json
{
  "status": 422,
  "message": "MAX_USES_BELOW_USED_COUNT",
  "success": false
}
```

`404` when missing; `400` generic. Retry-safe. Discovery cache is invalidated.

---

## 17. Delete Assignment

### `DELETE /api/v1/coupons/{coupon}/assignments/{assignment}` — `delete-coupon-assignment`

### Request

```http
DELETE /api/v1/coupons/123/assignments/10
```

### Success — `200` (no `data`)

```json
{
  "status": 200,
  "message": "Assignment removed successfully.",
  "success": true
}
```

Refused when already used (row is locked, checked, then deleted — all inside one transaction):

```http
409 Conflict
```

```json
{
  "status": 409,
  "message": "CANNOT_DELETE_ASSIGNMENT_WITH_USAGE",
  "success": false
}
```

`401` / `403` / `404`. Silent (no notify). Retry after success → `404`.

---

## 18. Manual Distribution

### `POST /api/v1/coupons/{id}/distribute`

In-controller permission: `update-coupon` (from `config('coupon-distribution.admin_permission')`).

### Request

```json
{
  "trigger": "manual",
  "audience_cap": 10000
}
```

Rules: `trigger` sometimes `in:manual`; `audience_cap` sometimes int 1..100000 (default cap 10000). Manual runs are IMMEDIATE (no business delay).

### Response — `202 Accepted`

```json
{
  "status": 202,
  "message": "Distribution run accepted.",
  "success": true,
  "data": {
    "run": {
      "id": 500,
      "coupon_id": 123,
      "trigger_type": "manual",
      "tree_hash": "a1b2c3...",
      "status": "pending",
      "candidate_count": 0,
      "eligible_count": 0,
      "not_eligible_count": 0,
      "notified_count": 0,
      "failed_count": 0,
      "duplicate_skipped_count": 0,
      "started_at": "2026-09-26T14:00:00+00:00",
      "finished_at": null
    }
  }
}
```

`status` ∈ {`pending`,`running`,`completed`,`failed`,`cancelled`}. Double-submit while pending/running → `409` with the live run (`reason: already_running`) — no duplicate run. Non-distributable coupon → `422` (`reason: not_distributable`). `404` (`Coupon not found`).

Then:

```text
Distribution Run
 ↓
Candidates (rule ranges ∪ assigned customers)
 ↓
Evaluate
 ↓
Eligible → Notification Request
 ↓
Laravel Notification → coupon.eligible
 ↓
Pusher
```

---

## 19. Distribution History

### `GET /api/v1/coupons/{id}/distributions`

Same auth as §18. Paginated (15 per page).

### Request

```http
GET /api/v1/coupons/123/distributions
```

### Response — `200`

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "data": [
      {
        "id": 500,
        "coupon_id": 123,
        "trigger_type": "manual",
        "tree_hash": "a1b2c3...",
        "status": "completed",
        "candidate_count": 1000,
        "eligible_count": 400,
        "not_eligible_count": 600,
        "notified_count": 400,
        "failed_count": 0,
        "duplicate_skipped_count": 0,
        "started_at": "2026-09-26T14:00:00+00:00",
        "finished_at": "2026-09-26T14:05:00+00:00"
      }
    ],
    "meta": { "current_page": 1, "per_page": 15, "total": 1, "last_page": 1 }
  }
}
```

`401` / `403` / `404`. Read-only.

---

## 20. Distribution Details

### `GET /api/v1/coupons/{id}/distributions/{runId}`

Same auth as §18. Run is scoped to the coupon.

### Request

```http
GET /api/v1/coupons/123/distributions/500
```

### Response — `200`

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "run": {
      "id": 500,
      "coupon_id": 123,
      "trigger_type": "manual",
      "tree_hash": "a1b2c3...",
      "status": "completed",
      "candidate_count": 1000,
      "eligible_count": 400,
      "not_eligible_count": 600,
      "notified_count": 400,
      "failed_count": 0,
      "duplicate_skipped_count": 0,
      "started_at": "2026-09-26T14:00:00+00:00",
      "finished_at": "2026-09-26T14:05:00+00:00"
    },
    "recipient_breakdown": { "eligible": 400, "not_eligible": 600 }
  }
}
```

`401` / `403` / `404`. Terminal visibility — next step is watching `notified_count` grow or inspecting failures.

---

## 21. Customer Coupon Catalog

### `GET /api/v1/general/coupons`

Public (`throttle:public-api`), optional auth. A presented-but-unresolvable bearer token yields `401` (never a silent downgrade); true guests get the public shape.

### Request

```http
GET /api/v1/general/coupons?search=SUMMER&limit=10
Authorization: Bearer {token}   (optional)
```

Query: `search`, `limit` (1..100, default 10), `start_date`, `end_date`, `couponsId`, `order` (default `desc`). Catalog = public + targeted; assignment-only grants never appear here (they live in `mine`).

### Response — `200`

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": [
    {
      "id": 123,
      "name": "Summer Coupon",
      "slug": "summer-coupon",
      "image": { "desktop": "https://...", "mobile": "https://..." },
      "borderColor": null,
      "borderless": false,
      "visibility": "targeted",
      "requires_claim": true,
      "eligible": true,
      "claim_status": "claimable",
      "action": "claim",
      "code": null
    }
  ]
}
```

`visibility` ∈ {`public`,`targeted`,`assignment-only`}; `claim_status` ∈ {`redeemed`,`claimed`,`not_required`,`claimable`}; `action` ∈ {`apply`,`claim`,null}. A coupon with `is_public` stays `visibility: public` even with assignments (assignments never demote it); only private assigned coupons read `assignment-only` and are excluded here.

**Important:** `code` may be `null` — codes are exposed only to owners (post-claim via `mine`/claim response).

---

## 22. Available Coupons

### `GET /api/v1/general/coupons/available` — sanctum, `throttle:authenticated`

Personalized, advisory-only feed (eligible-only, owner-safe shells — never codes, rules, or counters).

### Request

```http
GET /api/v1/general/coupons/available?page=1&limit=15
Authorization: Bearer {customer_token}
```

`page`/`limit` validated (limit 1..50, default 15) — `422` otherwise.

### Response — `200`

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "data": [
      {
        "id": 123,
        "name": "Summer Coupon",
        "slug": "summer-coupon",
        "image": "https://.../summer-desktop.webp",
        "visibility": "targeted",
        "claim_status": "claimable",
        "requires_claim": true,
        "code": null,
        "claim_id": null,
        "expires_at": "2026-10-26T00:00:00+00:00",
        "action": "claim"
      }
    ],
    "meta": { "current_page": 1, "per_page": 15, "total": 1, "has_more_pages": false }
  }
}
```

Note: `meta.total` counts ENGINE-ELIGIBLE items on this page (never the pre-filter total — no hidden-campaign leakage), and there is no `last_page` here. Listing here never guarantees checkout success — claim/apply/checkout revalidate authoritatively.

---

## 23. My Coupons

### `GET /api/v1/general/coupons/mine` — sanctum, `throttle:authenticated`

Owner-scoped: assignments + claims of the authenticated user. Codes are exposed ONLY here (needed for Apply).

### Request

```http
GET /api/v1/general/coupons/mine
Authorization: Bearer {customer_token}
```

### Response — `200`

```json
{
  "status": 200,
  "message": "Data fetched successfully",
  "success": true,
  "data": {
    "assignments": [
      {
        "id": 10,
        "coupon_id": 123,
        "code": "SUMMER50",
        "max_uses": 2,
        "used": 0,
        "remaining": 2,
        "expired": false,
        "expires_at": null,
        "assigned_at": "2026-09-26T12:00:00+00:00"
      }
    ],
    "claims": [
      {
        "id": 50,
        "coupon_id": 123,
        "code": "SUMMER50",
        "status": "active",
        "claimed_at": "2026-09-26T14:00:00+00:00",
        "expires_at": "2026-09-27T14:00:00+00:00",
        "redeemed_at": null
      }
    ]
  }
}
```

Claim `status` ∈ {`active`,`expired`,`redeemed`} (lowercase enum values). `401` when unauthenticated.

---

## 24. Claim Coupon

### `POST /api/v1/general/coupons/{id}/claim` — sanctum, `throttle:authenticated`

### Request (`ClaimCouponRequest` — authorized user only, no body rules)

```http
POST /api/v1/general/coupons/123/claim
Authorization: Bearer {customer_token}
Content-Type: application/json
```

```json
{}
```

### Success — `201` (customer-safe shape: no eligibility snapshot, no internals)

```json
{
  "status": 201,
  "message": "Coupon claimed successfully.",
  "success": true,
  "data": {
    "id": 50,
    "coupon_id": 123,
    "code": "SUMMER50",
    "status": "active",
    "claimed_at": "2026-09-26T14:00:00+00:00",
    "expires_at": "2026-09-27T14:00:00+00:00",
    "redeemed_at": null
  }
}
```

Internally: parent coupon row lock + gates + snapshot insert (unique per user/coupon/version is the race arbiter) inside a service transaction; discovery cache invalidated (claim changes `claim_status`/`action`).

### Rejection — `409` (reason-coded)

```json
{
  "status": 409,
  "message": "You have already claimed this coupon.",
  "success": false,
  "data": { "reason": "already_claimed" }
}
```

`reason` ∈ {`already_claimed`,`not_eligible`,`claim_not_required`,`no_targeting`,`max_claims_reached`} (the `message` is the locale-translated constant). `404` (`Coupon not found`); `500` infra. Re-claim → `409`, never a duplicate row. Next: `apply`, then checkout.

---

## 25. Apply Coupon

### `POST /api/v1/general/coupons/apply` — sanctum, `throttle:authenticated`

Tries the code on the current cart and computes the discount before payment. Writes `carts.coupon` (inside a DB transaction) but creates NO reservation yet. Skipped from HTTP response cache.

### Request

```json
{ "code": "SUMMER50" }
```

`code` required string ≤ 191 (`422` otherwise). Comparison is canonical (case/whitespace-insensitive).

### Success — `200`

```json
{
  "status": 200,
  "message": "Coupon applied successfully",
  "success": true,
  "data": {
    "total_price": 450.0,
    "coupon_discount": 50.0,
    "free_shipping": false
  }
}
```

Same code twice → `200` (`Coupon already applied`, `already_applied`). Full orchestrator revalidation runs here (feed/claim state is never trusted).

### Not Eligible — `400`

```json
{
  "status": 400,
  "message": "Could not add coupon to cart: the coupon usage limit has been reached.",
  "success": false,
  "data": { "reason": "not_eligible", "code": "COUPON_NOT_ELIGIBLE" }
}
```

`reason` is the machine-readable cause (`no_cart` → `COUPON_NO_CART`, `not_found`, `not_eligible`, …) with `code = COUPON_` + uppercased reason. Next: `POST checkout` (which revalidates AGAIN under lock).

---

## 26. Checkout

### `POST /api/v1/general/checkout` — sanctum

Converts the cart (with coupon) into an order + 30-minute coupon reservation + starts payment.

### Request (`OrderCreateRequest`)

```json
{
  "name": "Ahmed",
  "user_phone": "01000000000",
  "user_email": "ahmed@example.com",
  "address": { "id": 10 },
  "governorate_id": 1,
  "payment_method": "online",
  "gateway": "myfatoorah",
  "fulfillment_type": "delivery"
}
```

Required: `name`, `user_phone`; `address` (array) + `governorate_id` when the cart has physical items and fulfillment is delivery; `pickup_location_id` when pickup. Optional: `user_email`, `notes`, `selected_promotion_id`, `selected_gift_product_id`, `type` (`mobile|web`), `fulfillment_type`, `payment_method` (`online|cod|pay_at_cashier`, default online), `gateway` (default `myfatoorah`). `cod` + `pickup` → `422` (`COD is not available for pickup...`). Validation failures → `422` bare-errors shape.

### Responses (by payment method)

- Online, zero-value order (total ≤ 0): completes locally, no gateway — `200` (`Checkout successful`, `data: {order_id}`).
- Online, payable: handled by `PaymentCheckoutHandler` (gateway redirect payload), `200`.
- `cod` / `pay_at_cashier`: handled by `PaymentCheckoutHandler`, `200`.

Internally (every method):

```text
Cart
 ↓
Coupon LOCKED revalidation + snapshot
 ↓
Order + items + transaction rows
 ↓
30-minute coupon_reservations row (TTL)
 ↓
Payment branch
```

### Errors

`400` (`Cart not found` — including the consumed-cart race); `422` validation / invalid payment method; `500` (order-creation failure). Unsafe to blindly retry after a success (may double-create) — the client must key on the returned order. Next: gateway `callback`, `error-callback`, or `mark-paid`.

---

## 27. Payment Callback

### `GET|POST /api/v1/general/checkout/callback` — public, `throttle:payment-callback`

### Request

```http
GET /api/v1/general/checkout/callback?paymentId=ABC123&type=web
```

`paymentId` required, `[A-Za-z0-9-_]{1,191}`; `type` ∈ {`web`,`mobile`} → `400` (`Missing payment ID` / `Invalid payment method`). Gateway is resolved from the stored transaction (defaults to `myfatoorah`); unknown gateway → `500` (`Payment gateway is unavailable`).

### Mobile success — `200`

```json
{
  "status": 200,
  "message": "Checkout successful",
  "success": true,
  "data": {
    "status": "success",
    "message": "Payment successful",
    "payment_id": "ABC123",
    "order_id": 1000
  }
}
```

### Web success

Redirect to `{frontend}/{locale}/payment/success?status=success&message=...&payment_id=...&order_id=...`. Failures redirect to `/payment/failed?...` instead. A gateway-verified but locally-unknown payment FAILS SAFE (never a success UI).

Then:

```text
PaymentSucceeded (only for Processed outcome, valid order)
 ↓
Complete Order (locked: transaction + order rows)
 ↓
Consume Coupon (coupon.used++, assignment.used++, receipt)
 ↓
Redeem Claim (ACTIVE → REDEEMED)
 ↓
AssignedCouponConsumed → coupon.used Notification
 ↓
Pusher → coupon.used on users.{id}
```

Idempotent replay (non-pending transaction) → silent no-op. Amount/currency mismatch → fail-closed failed-marking + `PaymentFailed`. Coupon refusal → whole completion transaction ROLLED BACK (order stays pending), transaction marked failed with token rotation for ops-retry, `coupons:reconcile` surfaces it. Terminal on success.

---

## 28. Payment Error Callback

### `GET|POST /api/v1/general/checkout/error-callback` — public, `throttle:payment-callback`

Same params/validation as §27 (`400` shapes identical).

### Result

```text
Payment Failed (verified)
 ↓
Transaction marked failed (locked update)
 ↓
Coupon Reservation Released
 ↓
Coupon NOT consumed
 ↓
Claim NOT redeemed (stays ACTIVE)
```

A verified-success arriving here still follows the same locked completion guards as §27. No coupon notification (nothing was consumed). Next: the customer retries checkout (fresh reservation).

---

## 29. COD Mark Paid

### `POST /api/v1/general/checkout/cod/{orderId}/mark-paid` — sanctum + `payments.mark_paid`

### Request

```json
{ "reason": "Cash collected from customer" }
```

`reason` is optional free text (tags stripped, capped at 500 chars, re-sanitized server-side).

### Response — `200` (no `data`)

```json
{
  "status": 200,
  "message": "Payment successful",
  "success": true
}
```

At the same time:

```text
PaymentSucceeded
 ↓
Coupon Consumption (same chain as §27)
 ↓
coupon.used Notification
 ↓
Pusher
```

`404` order missing; `422` on duplicate (dup-safe — second call cannot double-consume). `401` / `403` without the permission.

---

## 30. Cashier Mark Paid

### `POST /api/v1/general/checkout/cashier/{orderId}/mark-paid` — sanctum + `payments.mark_paid`

Same flow as §29:

```json
{ "reason": "Paid at cashier" }
```

### Response — `200`

```json
{
  "status": 200,
  "message": "Payment successful",
  "success": true
}
```

Same consumption + `coupon.used` + Pusher chain. Same `404`/`422`-duplicate semantics.

---

## 31. The Notification Flow Is Internal (Not an Endpoint)

There is no `POST /notification` endpoint — and that is by design. Notification is an INTERNAL backend flow:

```text
POST /coupons/{id}/assignments   (REST)
             ↓
       DB COMMIT
             ↓
       CouponAssigned (event, AFTER commit)
             ↓
 SendUserCouponAssignedNotification (queue: high)
             ↓
 UserCouponAssignedNotification
             ↓
 Laravel Notification (three independent channels)
       ┌─────┼─────┐
       ↓     ↓     ↓
   Database FCM Broadcast
                    ↓
                  Pusher
                    ↓
             coupon.assigned → private-users.{id}
```

The other three follow the same last mile: `CouponCreated` → `coupon.available` (only mature-public), eligible-transition (RabbitMQ consumer) → `coupon.eligible`, `AssignedCouponConsumed` → `coupon.used`. The full matrix (trigger → class → recipient → queue → event → channel → timing) lives in `COUPON_NOTIFICATION_FLOW.md`. Remember: notification ≠ permission to use.

---

## 32. Final Closed Flow (Full System Test)

```text
1.  POST /api/v1/coupons
         ↓
2.  PUT /api/v1/coupons/{id}/targeting
         ↓
3.  POST /api/v1/coupons/{id}/assignments
         ↓
4.  GET /api/v1/general/coupons
         ↓
5.  GET /api/v1/general/coupons/available
         ↓
6.  POST /api/v1/general/coupons/{id}/claim
         ↓
7.  POST /api/v1/general/coupons/apply
         ↓
8.  POST /api/v1/general/checkout
         ↓
9.  Payment (gateway / COD / cashier)
         ↓
10. checkout/callback (or error-callback / mark-paid)
         ↓
11. PaymentSucceeded (INTERNAL event)
         ↓
12. Coupon Consumption (INTERNAL)
         ↓
13. coupon.used (INTERNAL event → notification)
         ↓
14. Notification (INTERNAL: database + FCM + broadcast)
         ↓
15. Pusher (private-users.{id})
         ↓
16. END
```

Expected final DB state:

```text
Order              = COMPLETED
Payment            = SUCCESS
Coupon Reservation = CONSUMED
Coupon Claim       = REDEEMED
Assignment.used    = +1
Coupon.used        = +1
AssignmentUsage    = 1 row
coupon.used event  = emitted
Notification       = dispatched (database row)
Pusher             = published
```

This is the shape every endpoint doc above follows: **who sends it → Request → Validation → Response → Errors → DB → Events → Queues → Notification → Pusher → next step**, with REST APIs always separated from INTERNAL steps (a Queue or Pusher publish is never a standalone endpoint).