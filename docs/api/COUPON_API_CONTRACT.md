# Coupon REST API Contract — Next.js Frontend

Source-verified against the Laravel implementation. REST ONLY. GraphQL is out of scope.
Base URL: `{APP_URL}/api/v1`. All JSON. Locale via `Accept-Language: en|ar` where noted.

## 0. Common envelope

Every `app/` + Marvel coupon endpoint uses `Marvel\Traits\ApiResponse::apiResponse`:

Success:
```json
{ "status": 200, "message": "...", "success": true, "data": {} }
```
Error:
```json
{ "status": 409, "message": "...", "success": false, "data": { "reason": "not_eligible" } }
```
Notes (VERIFIED FACT):
- `data` is OMITTED when empty (`!empty($data)` check in `packages/marvel/src/Traits/ApiResponse.php`). Do not assume `data: {}`.
- `message` is a translated string key (e.g. `COUPON_APPLIED_SUCCESSFULLY`); use `data.reason` / `data.code` for branching, never message text.
- Validation failures from FormRequests return either the envelope (coupon controllers) or raw `{"field": ["msg"]}` with 422 (`OrderCreateRequest`, `FastCheckoutRequest`, assignment requests). See each endpoint.
- Auth: `Authorization: Bearer <sanctum-token>`. Public endpoints: no header.

## 1. Concepts (do not collapse)

- **Assignment** = admin grant to a user (`coupon_assignments`, `max_uses`, `used`, optional `expires_at`). Not consumption.
- **Claim** = customer activation (`coupon_claims`: `ACTIVE → REDEEMED`, `ACTIVE → EXPIRED`). Expired releases capacity and may be re-claimed. `REDEEMED` is permanent and blocks re-claim.
- **Reservation** = 30-min payment hold (`coupon_reservations`, `expires_at = now+30min`). Not usage.
- **Usage** = permanent consumption on payment success only (`coupon_assignment_usages` for assigned path, `coupon_usages` UNIQUE(coupon,user) for public path, `coupons.used` increment, `orders.coupon_consumed=true`). Cancellation/refund NEVER returns coupon (policy).

Financial order (VERIFIED in `OrderService::calculateCheckoutTotals` + `withTaxes`):
`Product price → Promotion → Coupon → Tax → Shipping → Final total` (shipping never taxable).

## 2. Customer endpoints

### 2.A — Public coupon list (discover)

- PURPOSE: discover available coupons (no codes, no rules).
- WHEN: page load, coupon listing.
- AUTH: public.
- STATE CHANGE: none. Safe to retry: yes.

### Request
```http
GET /api/v1/general/coupons?search=SAVE&limit=10 HTTP/1.1
Accept: application/json
Accept-Language: en
```
Body: none. Query parameters (actual, `app/Services/General/CouponService::getCoupons`): `search` (name search, current locale), `limit` (1–100, default 10), `start_date` (filter `created_at >=`), `end_date` (`created_at <=`), `couponsId` (comma list or array, numeric only), `order` (`asc|desc` by id, default `desc`). NOTE: spec draft said `start/end`; actual keys are `start_date`/`end_date`.

### Response (exact, verified against implementation)
HTTP 200 with the standard envelope (§0):
```json
{
  "status": 200,
  "message": "...",
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Save 20",
      "slug": "save-20-abc123",
      "image": { "desktop": "https://.../x.jpg", "mobile": "https://.../y.jpg" },
      "borderColor": null,
      "borderless": false
    }
  ]
}
```
Field semantics: `data` = array of `App\Http\Resources\Coupons\CouponResource` — `id, name(localized), slug, image{desktop,mobile}, borderColor, borderless` ONLY. NO `code`, NO targeting, NO limiter/used. Empty list when none; no endpoint-specific errors.
Frontend action: render listing; coupon details live on frontend route `/coupons/{id}` (no backend show for customers).

### 2.B — Claim coupon

- PURPOSE: activate a claim-based coupon before apply.
- WHEN: Claim button on coupon details / My Coupons empty state.
- AUTH: Sanctum.
- STATE CHANGE: yes (creates ACTIVE claim). Retry: safe — duplicate returns deterministic 409 `already_claimed`, no duplicate ACTIVE.

### Request
```http
POST /api/v1/general/coupons/123/claim HTTP/1.1
Authorization: Bearer <sanctum-token>
Accept: application/json
Content-Type: application/json
```
Path parameters: `id` (integer, `whereNumber`). Body: `{}` (empty; `ClaimCouponRequest` has no rules). No `governorate_id` field exists — do not send one. Query parameters: none.

### Response (exact, verified against implementation)
HTTP 201 with the standard envelope (§0):
```json
{
  "status": 201,
  "message": "...",
  "success": true,
  "data": {
    "id": 789,
    "coupon_id": 123,
    "code": "SAVE20",
    "status": "active",
    "claimed_at": "2026-09-22T10:00:00Z",
    "expires_at": "2026-09-23T10:00:00Z",
    "redeemed_at": null
  }
}
```
Field semantics: `data` = `CouponClaimResource` (P1-2 safe shape) — `id, coupon_id, code, status, claimed_at, expires_at, redeemed_at`. No `eligibility_snapshot`, no `user_id`.
Error responses: 401 unauthenticated (no envelope body guarantee); 404 envelope (`COUPON_NOT_FOUND`, bad id); 409 envelope with `data.reason` ∈ `already_claimed` (ACTIVE unexpired or REDEEMED exists → refresh My Coupons) | `not_eligible` (show "not eligible") | `claim_not_required` (not claim-based → go straight to Apply) | `no_targeting` (no targeting row → contact support/admin) | `max_claims_reached` (full → unavailable state); 500 unexpected.
Business: holds `CouponTargeting FOR UPDATE`, checks ACTIVE+REDEEMED, `occupied=active(unexpired)+redeemed >= max_claims` fails, evaluates eligibility tree, creates ACTIVE claim with `claim_ttl_hours` expiry.
Frontend action: 201 → show CLAIMED + enable Apply; 409 `already_claimed` → `GET mine`; `claim_not_required` → skip to Apply.

### 2.C — My Coupons (owner-scoped)

- PURPOSE: show current user's assignments + claims (codes visible ONLY here because owner-scoped).
- WHEN: My Coupons page, before Apply.
- AUTH: Sanctum.
- STATE CHANGE: none. Retry safe: yes.

### Request
```http
GET /api/v1/general/coupons/mine HTTP/1.1
Authorization: Bearer <sanctum-token>
Accept: application/json
```
Body: none. Query parameters: none.

### Response (exact, verified against implementation)
HTTP 200 with the standard envelope (§0):
```json
{
  "status": 200,
  "message": "...",
  "success": true,
  "data": {
    "assignments": [
      {
        "id": 456,
        "coupon_id": 123,
        "code": "SAVE20",
        "max_uses": 2,
        "used": 0,
        "remaining": 2,
        "expired": false,
        "expires_at": "2026-09-30T23:59:59Z",
        "assigned_at": "2026-09-22T10:00:00Z"
      }
    ],
    "claims": [
      {
        "id": 789,
        "coupon_id": 123,
        "code": "SAVE20",
        "status": "active",
        "claimed_at": "2026-09-22T10:00:00Z",
        "expires_at": "2026-09-23T10:00:00Z",
        "redeemed_at": null
      }
    ]
  }
}
```
Field semantics: assignment item = `id, coupon_id, code, max_uses, used, remaining(max(0,max-used)), expired(bool), expires_at, assigned_at`; claim item = `id, coupon_id, code, status(active|redeemed|expired), claimed_at, expires_at, redeemed_at`. No pagination (full lists, newest first). Error responses: 401 only.
Frontend action: usable grant = `remaining > 0 && !expired`; appliable claim coupon = `status == active`.

### 2.D — Apply coupon (preview, NOT consumption)

- PURPOSE: validate + preview discount on current cart.
- WHEN: user clicks Apply.
- AUTH: Sanctum (cart required).
- STATE CHANGE: yes (`cart.coupon` set) but idempotent preview. Retry safe: yes (same code returns `already_applied`).

### Request
```http
POST /api/v1/general/coupons/apply HTTP/1.1
Authorization: Bearer <sanctum-token>
Accept: application/json
Content-Type: application/json

{
  "code": "SAVE20",
  "governorate_id": 1
}
```
Body: `code` required string ≤191 (canonical: trimmed, case-insensitive `Coupon::byCode`); `governorate_id` nullable integer `exists:governorates,id`. When OMITTED, `area_in` rules DEFER (pass now, enforced at checkout); when PRESENT, evaluated strictly. Query parameters: none.

### Response (exact, verified against implementation)
HTTP 200 with the standard envelope (§0), one of:
```json
{
  "status": 200,
  "message": "...",
  "success": true,
  "data": { "total_price": 80.0, "coupon_discount": 20.0, "free_shipping": false }
}
```
```json
{
  "status": 200,
  "message": "...",
  "success": true,
  "data": { "already_applied": true }
}
```
Field semantics: success (a) = preview totals — update cart UI; (b) = code already on cart — no-op. Envelope message `COUPON_APPLIED_SUCCESSFULLY` / `COUPON_ALREADY_APPLIED`.
Error responses: 400 envelope (generic message) with `data = { "reason": "<orchestrator-reason>", "code": "COUPON_<REASON>" }` (P2-4); `reason` ∈ `not_found|claim_required|already_used|not_eligible|not_assigned|assignment_expired|usage_quota_exceeded|disabled|not_active|expired|usage_limit_reached|product_not_eligible` (+ `no_cart` with `code: COUPON_NO_CART` when the user has no cart). 401 unauthenticated; 422 validation (bad code type / unknown governorate).
Business: `CouponOrchestrator::validateByCode` (claim gate + mode gate + static validator). Writes `cart.coupon = code` only on success. Creates NO usage, NO reservation, NO redemption, NO counter increment.
Frontend action: 200 → show discount; 400 → show `reason`-mapped message, keep cart; `COUPON_NOT_ELIGIBLE` → "no longer eligible" state.

### 2.E — Checkout (authoritative revalidation)

- PURPOSE: create order from cart; Apply result is NOT trusted.
- WHEN: checkout submit.
- AUTH: Sanctum.
- STATE CHANGE: yes (order created). Retry: pending-order reuse — safe to retry, does not duplicate orders.

### Request
```http
POST /api/v1/general/checkout HTTP/1.1
Authorization: Bearer <sanctum-token>
Accept: application/json
Content-Type: application/json

{
  "name": "Ahmed",
  "user_phone": "01000000000",
  "user_email": "a@example.com",
  "address": {},
  "notes": null,
  "selected_promotion_id": null,
  "selected_gift_product_id": null,
  "type": "web",
  "fulfillment_type": "delivery",
  "payment_method": "online",
  "gateway": "myfatoorah",
  "governorate_id": 1
}
```
Body = `OrderCreateRequest` (actual): `name*` string≤255, `user_phone*` string≤255, `user_email` nullable email≤255, `address` required-if physical cart + delivery (array), `notes` nullable, `selected_promotion_id` nullable `exists:promotions,id`, `selected_gift_product_id` nullable `exists:products,id`, `type` nullable `in:mobile,web`, `fulfillment_type` nullable `in:delivery,pickup` (pay_at_cashier forces pickup), `payment_method` nullable `in:online,cod,pay_at_cashier`, `gateway` nullable string≤50, `governorate_id` required-if physical + delivery (integer `exists:governorates,id`), `pickup_location_id` required-if pickup (`exists:pickup_locations,id`). Controller merges `fulfillment_type/payment_method/payment_gateway`. Query parameters: none.

### Response (exact, verified against implementation)
Behavior (VERIFIED `OrderService::addItemsInOrder`): locks cart, refreshes prices, asserts products active, revalidates `cart.coupon` with STRICT checkout context (`governorate_id` present, may be null → area fails closed). Invalid coupon is SILENTLY STRIPPED (`cart.coupon=null`) and order proceeds WITHOUT coupon — frontend distinguishes by comparing `order.coupon` (null = removed during revalidation). Totals computed promotion→coupon→tax→shipping; pending order created/reused.
Payment branch (verified `PaymentCheckoutHandler`):
```json
{
  "status": 200,
  "message": "...",
  "success": true,
  "data": { "url": "https://gateway-redirect/..." }
}
```
(`online`: gateway redirect URL.)
```json
{
  "status": 200,
  "message": "...",
  "success": true,
  "data": { "order_id": 99 }
}
```
(`cod` / `pay_at_cashier`: pending transaction + coupon reservation created.)
Error responses: 400 cart not found/empty; 422 validation / `COD_NOT_AVAILABLE_FOR_PICKUP` / minimum order; 500 create failure. Coupon removal is NOT an error.
Frontend action: after checkout, if you sent a coupon but `order.coupon == null`, show "coupon removed during revalidation" (`COUPON_NO_LONGER_ELIGIBLE` UI). Proceed to payment URL when `online`.

### 2.F — Fast shipping checkout

- PURPOSE: fast-shipping variant (always has delivery area).
- WHEN: fast-shipping checkout submit.
- AUTH: Sanctum.
- STATE CHANGE: yes (order created). Retry: same pending-order semantics as 2.E.

### Request
```http
POST /api/v1/general/fast-shipping/checkout HTTP/1.1
Authorization: Bearer <sanctum-token>
Accept: application/json
Content-Type: application/json

{
  "name": "Ahmed",
  "user_phone": "01000000000",
  "address": {},
  "governorate_id": 1,
  "payment_method": "online",
  "gateway": "myfatoorah"
}
```
Body = `FastCheckoutRequest` (actual): `name*`, `user_phone*`, `user_email` nullable, `address*` array, `notes` nullable, `governorate_id*` required integer `exists:governorates,id`, `selected_promotion_id` nullable, `selected_gift_product_id` nullable, `fulfillment_type` nullable `in:delivery,pickup`, `payment_method` nullable `in:online,cod,pay_at_cashier`, `gateway` nullable, `pickup_location_id` required-if pickup. Governorate is ALWAYS required here → `area_in` always strict. Query parameters: none.

### Response (exact, verified against implementation)
Same shape and payment branching as §2.E (order + `{url}` for online / `{order_id}` for cod/cashier); invalid coupon stripped silently with the same `order.coupon==null` signal. Error responses: 400 empty cart; 422 validation (missing `governorate_id` surfaces here) / `COD_NOT_AVAILABLE_FOR_PICKUP` / `INVALID_PAYMENT_METHOD`; 500 create failure.
Frontend action: same as 2.E; missing-area errors appear as 422 `governorate_id` validation.

### 2.G — Payment callbacks (gateway/backend owned)

- PURPOSE: gateway success/failure handling; ONLY backend may consume coupons.
- WHEN: never called by frontend code — gateway redirect + backend processing.
- AUTH: public, `throttle:payment-callback`.
- STATE CHANGE: yes (success path completes order + consumes coupon; failure path releases reservation). Retry: duplicate callbacks safe (idempotent).

### Request
```http
GET /api/v1/general/checkout/callback?payment_id=... HTTP/1.1
Accept: application/json
```
```http
POST /api/v1/general/checkout/callback HTTP/1.1
Accept: application/json
Content-Type: application/json
```
Same for `/api/v1/general/checkout/error-callback` (`GET|POST`). Body/query: gateway-supplied (verification handled server-side; secrets never exposed). Query parameters: gateway-defined.

### Response (exact, verified against implementation)
Success path (`callback`): locks transaction FOR UPDATE, idempotency check, verifies amount+currency, marks paid, commits inventory reservation, `finalizePromotionUsageAfterPayment`, `changeOrderStatus(completed, emit=false)`, `recordCouponUsage` (locks Coupon+Assignment, revalidates if reservation stale/missing, increments `coupons.used` + assignment `used`, creates usage row, consumes reservation, sets `coupon_consumed=true`), then dispatches `PaymentSucceeded` → `MarkCouponClaimRedeemed` (ACTIVE→REDEEMED) + invoice + digital fulfillment. Mobile (`type=mobile`) returns JSON:
```json
{
  "status": 200,
  "message": "...",
  "success": true,
  "data": { "status": "paid", "payment_id": "..." }
}
```
Web returns an HTTP redirect to `app_url_frontend/{locale}/payment/...`. Coupon-blocked completion (fail-closed) returns 400 JSON (mobile) or `payment/failed?...` redirect (web) with the message; duplicate callback = safe no-op.
Failure path (`error-callback`): marks transaction failed, releases coupon reservation (delete-by-order, idempotent), NO usage, NO redemption, NO counter increment. Mobile JSON:
```json
{
  "status": 400,
  "message": "...",
  "success": false,
  "data": { "status": "failed", "message": "...", "payment_id": "..." }
}
```
Web redirects to the frontend `failed` page. Duplicate error callback safe.
Frontend action: NEVER mark coupon used client-side. Poll order status / wait for redirect. On `failed`, show CLAIMED (not REDEEMED) state.

## 3. Admin endpoints (canonical `/api/v1/coupons/*` + legacy alias `/api/v1/admin/coupons/*`)

Canonical targeting/helpers live in `packages/marvel/src/Rest/Routes.php` under `/api/v1/coupons/{id}/...` (same controller logic). Legacy `/api/v1/admin/coupons/*` aliases are retained in `routes/api.php` (no route names, same middleware) for the admin frontend + existing tests — both URIs serve identical behavior. Prefer canonical for new code.

Auth: Sanctum + permissions (see each). All use envelope.

### 3.1 Coupon CRUD (`Marvel\Http\Controllers\CouponController`, `CouponRepository`)

- PURPOSE: full coupon lifecycle (create → target → assign → activate).
- WHEN: admin coupon management.
- AUTH: Sanctum + `view-coupons` (index/show) / `create-coupon` (store) / `update-coupon` (update) / delete per repository guard.
- STATE CHANGE: store/update/destroy yes; index/show no. Retry: index/show safe; store is NOT idempotent (duplicate canonical code → `Coupon code is already taken`).

### Request
```http
GET /api/v1/coupons?limit=15&search=SAVE&order=id&sortedBy=desc HTTP/1.1
Authorization: Bearer <sanctum-token>
Accept: application/json
```
List query: `limit` (default 15), `active`, `inactive`, `search` (name + code), `order` (`id|code|name|discount|discount_type|start_date|end_date|limiter|used|status|created_at|updated_at`), `sortedBy` (`asc|desc`). Body: none.
```http
POST /api/v1/coupons HTTP/1.1
Authorization: Bearer <sanctum-token>
Accept: application/json
Content-Type: multipart/form-data

name[en]=Save 20&name[ar]=وفر 20&discount=20&discount_type=percentage&max_discount_amount=50&start_date=2026-01-01&end_date=2026-12-31&limiter=100&status=1
```
Create body (`CouponRequest`, multipart with images): `name*` array (unique translations), `image-desktop*` image, `image-mobile*` image, `border_color` nullable string≤50, `borderless` `in:1,0`, `discount*` numeric≥0, `discount_type*` `in:percentage,fixed_rate,free_shipping`, `max_discount_amount` required-if percentage numeric≥1, `start_date*` `Y-m-d`, `end_date*` `Y-m-d` ≥ start, `limiter` nullable int≥0, `status` `in:1,0`. `code` auto-generated canonical uppercase when omitted; `used` never accepted (repository whitelist).
```http
GET /api/v1/coupons/123 HTTP/1.1
Authorization: Bearer <sanctum-token>
Accept: application/json
```
Path: `{coupon}` = id OR code (`where id or code`). Body: none.
```http
PUT /api/v1/coupons/123 HTTP/1.1
Authorization: Bearer <sanctum-token>
Accept: application/json
Content-Type: multipart/form-data
```
Update body (`UpdateCouponRequest`): same fields as create but `sometimes` (partial allowed).
```http
DELETE /api/v1/coupons/123 HTTP/1.1
Authorization: Bearer <sanctum-token>
Accept: application/json
```
Body: none.

### Response (exact, verified against implementation)
List (HTTP 200, paginated admin shape):
```json
{
  "status": 200,
  "message": "...",
  "success": true,
  "data": {
    "data": [
      {
        "id": 1,
        "code": "SAVE20",
        "name": "Save 20",
        "image": { "desktop": "https://.../x.jpg", "mobile": "https://.../y.jpg" },
        "borderColor": null,
        "borderless": false,
        "discount": 20,
        "discount_type": "percentage",
        "max_discount_amount": 50.0,
        "start_date": "2026-01-01",
        "end_date": "2026-12-31",
        "limiter": 100,
        "used": 3,
        "status": true,
        "is_valid": true,
        "is_assigned": true,
        "assignments": [],
        "created_at": "2026-01-01T00:00:00Z"
      }
    ],
    "page": 1,
    "current_page": 1,
    "from": 1,
    "to": 15,
    "last_page": 1,
    "path": "https://.../api/v1/coupons",
    "per_page": 15,
    "total": 1,
    "next_page_url": "",
    "prev_page_url": "",
    "last_page_url": "...",
    "first_page_url": "..."
  }
}
```
Field semantics: `Marvel CouponResource` — ADMIN ONLY shape (includes `code, limiter, used, is_valid, is_assigned, assignments`). Never expose to customers.
Create → HTTP 201 with single resource; show/update → HTTP 200 with single resource; destroy → HTTP 200, no `data`. Error responses: 400 create/update failure; 404 show/destroy miss.
Deleting with live reservations/existing usages creates inconsistent money state — verify no pending orders before delete (manual check; no automatic guard).
Frontend action: after create → configure targeting (§3.3) → assign (§3.2) → activate (`status=1`).

### 3.2 Assignments (`CouponAssignmentController`, `CouponAssignmentRepository`)

- PURPOSE: grant per-user quota (`max_uses`, optional expiry); fires `CouponAssigned` → DB+Pusher+FCM.
- WHEN: after targeting is configured.
- AUTH: Sanctum + `view-coupon-assignments` (index/show) / `create-coupon-assignment` (store) / `update-coupon-assignment` (update) / `delete-coupon-assignment` (destroy).
- STATE CHANGE: store/update/destroy yes; index/show no. Retry: store duplicate → deterministic 409 (unique arbiter), safe.

### Request
```http
GET /api/v1/coupons/123/assignments?limit=15 HTTP/1.1
Authorization: Bearer <sanctum-token>
Accept: application/json
```
Body: none. Query parameters: `limit` (default 15).
```http
POST /api/v1/coupons/123/assignments HTTP/1.1
Authorization: Bearer <sanctum-token>
Accept: application/json
Content-Type: application/json

{ "user_id": 55, "max_uses": 2, "expires_at": "2026-09-30T23:59:59Z" }
```
Body (`CouponAssignmentRequest`): `user_id*` int `exists:users,id`, `max_uses*` int≥1, `expires_at` nullable date `after:now`.
```http
GET /api/v1/coupons/123/assignments/456 HTTP/1.1
Authorization: Bearer <sanctum-token>
Accept: application/json
```
Body: none.
```http
PUT /api/v1/coupons/123/assignments/456 HTTP/1.1
Authorization: Bearer <sanctum-token>
Accept: application/json
Content-Type: application/json

{ "max_uses": 3 }
```
Body (`UpdateCouponAssignmentRequest`): `max_uses` sometimes int≥1, `expires_at` nullable date `after:now`.
```http
DELETE /api/v1/coupons/123/assignments/456 HTTP/1.1
Authorization: Bearer <sanctum-token>
Accept: application/json
```
Body: none.

### Response (exact, verified against implementation)
Single resource shape (`CouponAssignmentResource`):
```json
{
  "status": 201,
  "message": "...",
  "success": true,
  "data": {
    "id": 456,
    "coupon_id": 123,
    "user_id": 55,
    "user": { "id": 55, "name": "Ahmed", "email": "a@example.com" },
    "max_uses": 2,
    "used": 0,
    "remaining": 2,
    "is_expired": false,
    "assigned_at": "2026-09-22T10:00:00Z",
    "expires_at": "2026-09-30T23:59:59Z"
  }
}
```
Field semantics: `user` present when loaded; `remaining = max(0, max_uses-used)`. List wraps paginated: `data: { data: [...], current_page, from, last_page, per_page, to, total }`.
Error responses: store duplicate `(coupon,user)` → 409 `COUPON_ALREADY_ASSIGNED_TO_USER`; show/update/destroy miss → 404; update `max_uses < used` → 422 `MAX_USES_BELOW_USED_COUNT`; destroy with `used>0` → 409 `CANNOT_DELETE_ASSIGNMENT_WITH_USAGE`.
Frontend action: 201 → user receives DB+Pusher+FCM notification (no email); show `remaining` quota.

### 3.3 Targeting (`CouponTargetingController`, auth: read needs `view-coupons|update-coupon|create-coupon` or admin type; write needs `update-coupon|create-coupon`)

URIs (both serve): canonical `/api/v1/coupons/{id}/targeting` + legacy alias `/api/v1/admin/coupons/{id}/targeting`.
- PURPOSE: attach eligibility mode + claim policy + rule tree to a coupon.
- WHEN: after coupon create, before assign.
- STATE CHANGE: upsert/destroy yes; show no. Retry: upsert idempotent (`updateOrCreate`); show safe.

### Request
```http
GET /api/v1/coupons/123/targeting HTTP/1.1
Authorization: Bearer <sanctum-token>
Accept: application/json
```
Body: none.
```http
PUT /api/v1/coupons/123/targeting HTTP/1.1
Authorization: Bearer <sanctum-token>
Accept: application/json
Content-Type: application/json

{
  "mode": "assignment_and_dynamic",
  "require_claim": true,
  "max_claims": 100,
  "claim_ttl_hours": 24,
  "rule_tree": {
    "operator": "AND",
    "rules": [
      { "type": "min_completed_orders", "value": 3 },
      { "type": "area_in", "value": [1, 2] }
    ]
  }
}
```
Body (`UpsertTargetingRequest`): `mode*` `in:assignment,dynamic,assignment_and_dynamic,assignment_or_dynamic`, `require_claim*` boolean, `max_claims` nullable int 1–1M, `claim_ttl_hours` nullable int 1–8760, `rule_tree` nullable array (deep-checked by `RuleTreeValidator`, fail-closed).
```http
DELETE /api/v1/coupons/123/targeting HTTP/1.1
Authorization: Bearer <sanctum-token>
Accept: application/json
```
Body: none.

### Response (exact, verified against implementation)
```json
{
  "status": 200,
  "message": "...",
  "success": true,
  "data": {
    "id": 1,
    "coupon_id": 123,
    "mode": "assignment_and_dynamic",
    "require_claim": true,
    "max_claims": 100,
    "claim_ttl_hours": 24,
    "rule_tree": {
      "operator": "AND",
      "rules": [
        { "type": "min_completed_orders", "value": 3 },
        { "type": "area_in", "value": [1, 2] }
      ]
    },
    "created_at": "2026-09-22T10:00:00Z",
    "updated_at": "2026-09-22T10:00:00Z"
  }
}
```
Field semantics: `CouponTargetingResource` — `id, coupon_id, mode, require_claim, max_claims, claim_ttl_hours, rule_tree, created_at, updated_at`. `rule_tree` echoes the stored tree verbatim (admin-only; never sent to customers).
Error responses: show/destroy without row → 404 `COUPON_NO_TARGETING`; upsert with invalid tree → 422 with `data.errors: [...]` (validator messages); 401/403 auth.
DELETE removes the row → coupon becomes always-eligible/assignment-fallback (DANGEROUS: silently widens access). Frontend must confirm; policy: do not delete targeting on live coupons — set `mode=assignment` + `require_claim` instead.
Truth table: `assignment`=usable assignment only; `dynamic`=tree only; `assignment_and_dynamic`=both; `assignment_or_dynamic`=either (assignment path counts only when coupon grants assignments AND user holds valid one).

### 3.4 Helpers (`CouponConfigurationController`)

Both URI families serve (canonical `/api/v1/coupons/...` + legacy `/api/v1/admin/coupons/...`). Auth: Sanctum + coupon view/update roles (controller `authorizeAdmin`). No state change; safe to retry.

### Request
```http
POST /api/v1/coupons/validate-configuration HTTP/1.1
Authorization: Bearer <sanctum-token>
Accept: application/json
Content-Type: application/json

{ "coupon_type": "public", "limiter": 100, "max_uses_per_user": 1 }
```
Body: `coupon_type*` `in:public,assigned`, `limiter` nullable int≥1, `max_uses_per_user` nullable int≥1.
```http
GET /api/v1/coupons/123/usage-info HTTP/1.1
Authorization: Bearer <sanctum-token>
Accept: application/json
```
Body: none.
```http
POST /api/v1/coupons/123/suggest-fix HTTP/1.1
Authorization: Bearer <sanctum-token>
Accept: application/json
Content-Type: application/json

{ "desired_behavior": "multi_use_per_user" }
```
Body: `desired_behavior` = `multi_use_per_user|single_use_per_user` (anything else → 400).
```http
GET /api/v1/dashboard/coupons HTTP/1.1
Authorization: Bearer <sanctum-token>
Accept: application/json
```
Body: none. Permission: `view-analytics` (`DashboardController::couponAnalytics`).

### Response (exact, verified against implementation)
```json
{
  "status": 200,
  "message": "Validation completed.",
  "success": true,
  "data": {
    "valid": true,
    "errors": [],
    "warnings": [],
    "recommendations": [{ "title": "Public Coupon Behavior", "description": "..." }]
  }
}
```
`validate-configuration`: public multi-use (`max_uses_per_user>1` on `public`) → `valid:false` with `errors[{field,message,explanation}]`.
```json
{
  "status": 200,
  "message": "Usage info retrieved.",
  "success": true,
  "data": {
    "coupon_code": "SAVE20",
    "coupon_type": "assigned",
    "usage_model": "...",
    "current_usage": 3,
    "global_limit": 100,
    "remaining_capacity": 97,
    "is_multi_use_per_user": true,
    "assignment_info": {
      "total_assignments": 10,
      "assignments_with_usage": 2,
      "max_uses_per_user": 2,
      "total_possible_redemptions": 20
    },
    "public_usage_count": 0
  }
}
```
`usage-info`: `remaining_capacity` = number or `"unlimited"`; `assignment_info` = null for public coupons.
```json
{
  "status": 200,
  "message": "Suggestion generated.",
  "success": true,
  "data": {
    "current_issue": "Coupon is public (no assignments). Public coupons only support single-use per user.",
    "recommended_action": "convert_to_assigned",
    "steps": ["1. Create a list of eligible customers", "..."],
    "example_code": "CouponAssignment::create(['coupon_id' => 123, 'user_id' => $userId, 'max_uses' => 5]);"
  }
}
```
`suggest-fix`: shape varies by `desired_behavior` + current config (see controller: `convert_to_assigned` / `already_configured` / `update_assignments` / `no_change_needed` / `remove_assignments_or_set_max_uses_1`).
Dashboard: `{"success":true,"message":"...","data":{...analytics payload...}}` (note: direct `response()->json`, not the coupon envelope).

## 4. Validation matrix (what is checked WHERE)

| Check | APPLY (preview) | CHECKOUT (authoritative) | RESERVATION (capacity hold) | PAYMENT SUCCESS (completion) |
|---|---|---|---|---|
| coupon status/dates | yes | yes | no (assumes checkout) | revalidated ONLY if reservation stale/missing; live hold = commitment |
| targeting mode + tree (17 rules) | yes (area defers when no governorate) | yes STRICT (governorate present, null fails) | no | same as checkout when revalidating |
| claim (ACTIVE unexpired / REDEEMED block) | yes | yes | no | via claim redemption listener |
| assignment (usable: exists + !expired + used<max) | yes | yes | no | quota re-checked under `Assignment FOR UPDATE` |
| limiter (`used + active_reservations < limiter`) | via static `used>=limiter` only | same | YES under `Coupon FOR UPDATE` | re-checked via reserve when stale |
| product eligibility + active state + pricing/promotion/currency/area | products gate yes; pricing yes | full + `assertCartProductsActive` | no | product gate on ORDER items when revalidating |
| inventory commit / promotion finalize / currency snapshot | no | compute totals + snapshot | no | commit + finalize + snapshot verify |

Decision (P1-4, preserved semantics): 30-min live reservation is a BUSINESS COMMITMENT. State drift inside TTL is bounded by design; full revalidation runs when the hold is missing/expired. Documented, not silently changed.

## 5. Rule catalog (17, validator parity VERIFIED)

`min_completed_orders, max_completed_orders` (int≥0, metrics.completed_orders); `min_total_spend, max_total_spend` (numeric≥0, DECIMAL-SAFE bccomp at 2dp on `converted_total_price` SUM); `first/last_order_after/before` (parseable datetime, exclusive `isAfter/isBefore`, null→fail); `min/max_coupons_used` (COUNT qualifying coupon orders, int≥0); `claimed` (=ACTIVE unexpired OR REDEEMED; EXPIRED=not claimed), `not_claimed` (inverse, same definition); `has_assignment` (usable assignment only); `area_in` (strict positive ints, active governorate only, absent context defers / present strict, pickup without governorate fails); `has_email` (trim+FILTER_VALIDATE_EMAIL, null/true=require, false=require-absent, verification ignored); `registered_after/before` (users.created_at UTC exclusive, null/invalid fail). Tree: `{operator:AND|OR, rules:[...]}` nested ≤10, fail-closed on unknown operator/rule, malformed, empty group, depth>10.

## 6. Notification contract (email EXCLUDED)

Event `CouponAssigned` (dispatched AFTER assignment commit in `CouponAssignmentRepository::assignCoupon`). Listener `SendUserCouponAssignedNotification` (queue `high`) → `UserCouponAssignedNotification` via `['database','fcm','broadcast']` ONLY.
- DB/in-app (`toDatabase`): `{title{en,ar}, message{en,ar}, icon:tag, resource_type:coupon, resource_id, action_url:/coupons/{id} (frontend must prefix with APP_URL_FRONTEND, NOT backend host), coupon_assignment_id, coupon_id, coupon_code, max_uses, expires_at}`. `databaseType=broadcastType=coupon.assigned`.
- Pusher (`toBroadcast` = same payload): channel `users.{userId}` (auth: owner only, `routes/channels.php`), event `coupon.assigned`. Admin channel `admin.notifications` unchanged.
- FCM (`FcmChannel` reuses DB payload, strips title/message into native fields, `data` = remainder): targets OWNER tokens only (`SendFcmNotificationJob` scoped by notifiable id, tries=3 backoff 30/120), invalid tokens removed.
- Email: NOT dispatched (PART 4 decision). `toMail()` dormant. Assignment succeeds with no SMTP/email.

## 7. Frontend state machine

`AVAILABLE --POST claim--> CLAIMED --POST apply--> APPLIED --POST checkout--> PAYMENT_PENDING --callback success--> REDEEMED` (usage + claim REDEEMED). `PAYMENT_PENDING --error-callback--> CLAIMED` (reservation released, no usage). `APPLIED --checkout strips--> AVAILABLE` (order.coupon null → show COUPON_NO_LONGER_ELIGIBLE). `CLAIMED --TTL expiry--> EXPIRED --re-claim--> CLAIMED`. `CHECKOUT_REVALIDATION_FAILED` = transient UI when order.coupon null after checkout with coupon.

## 8. Timing (when to call what)

Page load → `GET general/coupons`. Details → frontend route `/coupons/{id}` (no backend show for customers). Claim button → `POST general/coupons/{id}/claim`. My Coupons page → `GET general/coupons/mine`. Apply → `POST general/coupons/apply` (+optional `governorate_id` when known). Checkout → `POST general/checkout` (or `fast-shipping/checkout`). Payment → follow `url` (online) / show order (cod). NEVER call usage/redemption manually — backend callbacks own it.

## 9. Error catalog (machine-readable; use `data.reason`, display `data.code`)

Claim 409: `already_claimed, not_eligible, claim_not_required, no_targeting, max_claims_reached`. Apply 400 `data.reason` = orchestrator reason (`not_found, claim_required, already_used, not_eligible, not_assigned, assignment_expired, usage_quota_exceeded, disabled, not_active, expired, usage_limit_reached, product_not_eligible, no_cart`) + `data.code=COUPON_<REASON>`. Checkout strip: no error — detect via `order.coupon==null`. Payment: `COUPON_RESERVATION_FAILED` (422 at reserve), `COUPON_ALREADY_CONSUMED` (completion throws `already_used`), `COUPON_LIMIT_REACHED` (limiter), `COUPON_ASSIGNMENT_*` (quota/expired). Only codes the backend can distinguish are returned — no fabricated states.

## 10. Idempotency / retry

Claim: repeat → deterministic 409, no duplicate ACTIVE (parent-row lock + reconcile detector). Apply: repeat → `already_applied`, no usage. Checkout: pending-order reuse, no duplicate orders. Callback: unique usage + `coupon_consumed` flag + claim row lock → one usage/redemption. Reservation: per-order idempotent (existing row refreshed, never double-counted).

## 11. E2E example (SAVE20, assignment_and_dynamic, require_claim, max_claims=100, ttl=24h, rules min_completed_orders≥3 AND area_in[1,2])

1. Admin `POST /api/v1/coupons` (discount 20%) → `PUT /api/v1/coupons/{id}/targeting {mode:assignment_and_dynamic, require_claim:true, max_claims:100, claim_ttl_hours:24, rule_tree:{operator:AND,rules:[{type:min_completed_orders,value:3},{type:area_in,value:[1,2]}]}}` → `POST /api/v1/coupons/{id}/assignments {user_id:55, max_uses:2}` → commit → DB+Pusher(`users.55`/`coupon.assigned`)+FCM.
2. User `GET mine` sees assignment → `POST claim` → 201 ACTIVE → `POST apply {code:SAVE20}` → `{total_price,coupon_discount}` → `POST checkout {name, user_phone, governorate_id:1, ...}` → order with `coupon:SAVE20` → reservation (30min) → gateway `url` → pay → callback → usage (`coupons.used+1`, `assignments.used+1`, usage row, `coupon_consumed`) → claim REDEEMED.
3. Failure: pay fails → error-callback → reservation deleted → no usage, claim stays ACTIVE. Cancel/refund → coupon NOT returned.

## 12. Admin E2E + DB effects

CREATE (row in `coupons`) → TARGET (row `coupon_targetings`, UNIQUE(coupon_id)) → ASSIGN (row `coupon_assignments`, UNIQUE(coupon,user), event) → ACTIVATE (`status=1`) → DISCOVER (public list, no code) → CLAIM (row `coupon_claims` ACTIVE) → APPLY (cart.coupon) → CHECKOUT (order snapshot `coupon, coupon_discount, currency_*, governorate_id`) → PAYMENT (reservation row) → USAGE (usage row + counters + `coupon_consumed`, reservation deleted, claim REDEEMED). Deletes: coupon cascade; targeting delete widens access (avoid); assignment delete blocked when used>0.

## 13. Coupon Rules Metadata (Admin targeting builder)

### Endpoint
```http
GET /api/v1/coupons/rules
```
Legacy alias (identical response):
```http
GET /api/v1/admin/coupons/rules
```
No public/customer variant exists by design: customer endpoints deliberately hide targeting internals (§2.A), and the builder is Admin UI. Do not add `GET /api/v1/general/coupons/rules`.

### Authentication
Sanctum + targeting-read model (same as `GET .../targeting`): `view-coupons` OR `update-coupon` OR `create-coupon` (Spatie), else legacy `type/role=admin`. 401 unauthenticated, 403 without permission.

### Request
```http
GET /api/v1/coupons/rules HTTP/1.1
Authorization: Bearer <sanctum-token>
Accept: application/json
Accept-Language: en
```
Body: none. Query parameters: none.

### Response (exact, verified against implementation)
HTTP 200 with the standard envelope (§0):
```json
{
  "status": 200,
  "message": "...",
  "success": true,
  "data": {
    "rules": [
      {
        "type": "min_completed_orders",
        "label": { "en": "Minimum completed orders", "ar": "الحد الأدنى من الطلبات المكتملة" },
        "description": {
          "en": "Requires the customer to have at least the specified number of qualifying completed orders (status completed and payment successful).",
          "ar": "يشترط أن يكون لدى العميل عدد محدد على الأقل من الطلبات المكتملة المؤهلة (مكتملة ومدفوعة بنجاح)."
        },
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
Field semantics: `type` = exact runtime identifier (matches `EligibilityRuleType`, `RuleTreeValidator`, `EligibilityEngine`); `value_type` ∈ `integer|decimal|datetime|none|area_list|boolean_or_null` (only types the system uses); `value_required` false only for `claimed|not_claimed|has_assignment` (value ignored) and `has_email` (null allowed = require email); `min` set (0) only for integer/decimal rules; `allowed_values` set only for `has_email` (`[true,false,null]`); `date_format` set only for the 6 date rules (`parseable datetime string (Y-m-d accepted), UTC, exclusive boundary`); `context` ∈ `customer_history|customer_profile|claim_state|assignment_state|checkout`; `evaluation.*` all true (every rule evaluates at every stage; only `area_in` has `defers_without_context: true` — passes without delivery area at claim/apply, enforced strictly at checkout). `rule_tree.*` mirrors `RuleTreeValidator` exactly (depth 10, AND/OR, nested allowed, empty rejected, null = "no rules" eligible, duplicates allowed, unknown/malformed → 422).

### Rule table (all 17, from verified behavior)

| Rule | Meaning | Value Type | Example | Context |
|---|---|---|---|---|
| `min_completed_orders` | Minimum qualifying completed orders | integer (≥0) | `3` | customer_history |
| `max_completed_orders` | Maximum qualifying completed orders | integer (≥0) | `10` | customer_history |
| `min_total_spend` | Minimum qualifying spend (`converted_total_price` SUM, base currency, 2dp) | decimal (≥0) | `"100.00"` | customer_history |
| `max_total_spend` | Maximum qualifying spend (same basis) | decimal (≥0) | `"500.00"` | customer_history |
| `first_order_after` | First qualifying order strictly after UTC datetime | datetime | `"2024-01-01"` | customer_history |
| `first_order_before` | First qualifying order strictly before UTC datetime | datetime | `"2025-01-01"` | customer_history |
| `last_order_after` | Most recent qualifying order strictly after UTC datetime | datetime | `"2024-01-01"` | customer_history |
| `last_order_before` | Most recent qualifying order strictly before UTC datetime | datetime | `"2025-01-01"` | customer_history |
| `min_coupons_used` | Minimum coupon-order count (orders, not distinct codes) | integer (≥0) | `1` | customer_history |
| `max_coupons_used` | Maximum coupon-order count | integer (≥0) | `5` | customer_history |
| `claimed` | Holds ACTIVE-unexpired or REDEEMED claim (value ignored) | none | — | claim_state |
| `not_claimed` | Holds neither ACTIVE-unexpired nor REDEEMED (value ignored) | none | — | claim_state |
| `has_assignment` | Holds usable assignment (exists, !expired, used<max; value ignored) | none | — | assignment_state |
| `area_in` | Delivery `governorates.id` (active only) in list; single id or non-empty array, strict positive ints; defers without context | area_list | `[1, 2]` | checkout |
| `has_email` | Strict email presence (trim+RFC, verification ignored); `true`/`null`=require, `false`=require-absent | boolean_or_null | `true` | customer_profile |
| `registered_after` | Account `users.created_at` strictly after UTC datetime | datetime | `"2024-01-01"` | customer_profile |
| `registered_before` | Account `users.created_at` strictly before UTC datetime | datetime | `"2025-01-01"` | customer_profile |

### Builder flow
```text
Admin opens Coupon Targeting → GET /api/v1/coupons/rules → render rule selector
from data.rules (label/description per locale) → per selected rule render the
value_type input (integer/decimal/datetime/area_list/boolean_or_null/none) →
build rule_tree {operator, rules[]} → PUT /api/v1/coupons/{id}/targeting →
backend RuleTreeValidator re-validates (authoritative, 422 on error).
```
Frontend validation is UX assistance ONLY. Never assume metadata presence guarantees acceptance — the tree must still pass backend validation (e.g. unknown governorate id fails at runtime even if the shape is valid). State change: none (read-only, safe to retry; no caching in v1 — static code-deployed data).
