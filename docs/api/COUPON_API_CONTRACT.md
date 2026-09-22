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
- METHOD/URL: `GET /api/v1/general/coupons`
- HEADERS: none.
- QUERY (actual, `app/Services/General/CouponService::getCoupons`): `search` (name search, current locale), `limit` (1–100, default 10), `start_date` (filter `created_at >=`), `end_date` (`created_at <=`), `couponsId` (comma list or array, numeric only), `order` (`asc|desc` by id, default `desc`). NOTE: spec draft said `start/end`; actual keys are `start_date`/`end_date`.
- SUCCESS 200: envelope `data` = array of `App\Http\Resources\Coupons\CouponResource`: `id, name(localized), slug, image{desktop,mobile}, borderColor, borderless`. NO `code`, NO targeting, NO limiter/used.
- ERRORS: none specific (empty array when none).
- STATE CHANGE: none. Safe to retry: yes.

### 2.B — Claim coupon

- PURPOSE: activate a claim-based coupon before apply.
- WHEN: Claim button on coupon details / My Coupons empty state.
- AUTH: Sanctum.
- METHOD/URL: `POST /api/v1/general/coupons/{id}/claim` (`{id}` integer, `whereNumber`).
- BODY: `{}` (empty; `ClaimCouponRequest` has no rules). No `governorate_id` field exists — do not send one.
- SUCCESS 201: `data` = `CouponClaimResource` (P1-2 safe shape): `id, coupon_id, code, status(active), claimed_at, expires_at, redeemed_at(null)`. No `eligibility_snapshot`, no `user_id`.
- ERRORS: 401 unauthenticated; 404 `COUPON_NOT_FOUND` (bad id); 409 with `data.reason`: `already_claimed` (ACTIVE unexpired or REDEEMED exists → refresh My Coupons), `not_eligible` (show "not eligible"), `claim_not_required` (coupon is not claim-based → go straight to Apply), `no_targeting` (no targeting row → contact support/admin), `max_claims_reached` (full → unavailable state). 500 unexpected.
- BUSINESS: holds `CouponTargeting FOR UPDATE`, checks ACTIVE+REDEEMED, `occupied=active(unexpired)+redeemed >= max_claims` fails, evaluates eligibility tree, creates ACTIVE claim with `claim_ttl_hours` expiry.
- FRONTEND: 201 → show CLAIMED + enable Apply; 409 `already_claimed` → GET mine; `claim_not_required` → skip to Apply.
- STATE CHANGE: yes (creates ACTIVE claim). Retry: safe — duplicate returns deterministic 409 `already_claimed`, no duplicate ACTIVE.

### 2.C — My Coupons (owner-scoped)

- PURPOSE: show current user's assignments + claims (codes visible ONLY here because owner-scoped).
- WHEN: My Coupons page, before Apply.
- AUTH: Sanctum.
- METHOD/URL: `GET /api/v1/general/coupons/mine`
- SUCCESS 200: `data = { assignments: [...], claims: [...] }`. Assignment item: `id, coupon_id, code, max_uses, used, remaining(max(0,max-used)), expired(bool), expires_at, assigned_at`. Claim item: `id, coupon_id, code, status(active|redeemed|expired), claimed_at, expires_at, redeemed_at`. No pagination (full lists ordered desc).
- ERRORS: 401 only.
- STATE CHANGE: none. Retry safe: yes.
- FRONTEND: use `assignments[].remaining>0 && !expired` for usable grants; `claims[]` with `status=active` for appliable claim coupons.

### 2.D — Apply coupon (preview, NOT consumption)

- PURPOSE: validate + preview discount on current cart.
- WHEN: user clicks Apply.
- AUTH: Sanctum (cart required).
- METHOD/URL: `POST /api/v1/general/coupons/apply`
- BODY: `{ "code": "SAVE20", "governorate_id": 1 }`. `code` required string ≤191 (canonical: trimmed, case-insensitive `Coupon::byCode`). `governorate_id` nullable integer `exists:governorates,id`. When OMITTED, `area_in` rules DEFER (pass now, enforced at checkout); when PRESENT, evaluated strictly.
- SUCCESS 200: (a) `{ total_price, coupon_discount, free_shipping }` — update cart UI; (b) `{ already_applied: true }` — no-op. Envelope message `COUPON_APPLIED_SUCCESSFULLY` / `COUPON_ALREADY_APPLIED`.
- FAILURE 400: envelope message generic + `data = { reason: <orchestrator-reason>, code: COUPON_<REASON> }` (P2-4). `reason` ∈ `not_found|claim_required|already_used|not_eligible|not_assigned|assignment_expired|usage_quota_exceeded|disabled|not_active|expired|usage_limit_reached|product_not_eligible|no_cart`. 401 unauthenticated; 422 validation (bad code type / unknown governorate).
- BUSINESS: `CouponOrchestrator::validateByCode` (claim gate + mode gate + static validator). Writes `cart.coupon = code` only on success. Creates NO usage, NO reservation, NO redemption, NO counter increment.
- FRONTEND: 200 → show discount; 400 → show `reason`-mapped message, keep cart; `COUPON_NOT_ELIGIBLE` → "no longer eligible" state.
- STATE CHANGE: yes (cart.coupon set) but idempotent preview. Retry safe: yes (same code returns `already_applied`).

### 2.E — Checkout (authoritative revalidation)

- PURPOSE: create order from cart; Apply result is NOT trusted.
- WHEN: checkout submit.
- AUTH: Sanctum.
- METHOD/URL: `POST /api/v1/general/checkout`
- BODY = `OrderCreateRequest` (actual): `name* string≤255, user_phone* string≤255, user_email nullable email≤255, address required-if physical+delivery (array), notes nullable, selected_promotion_id nullable exists:promotions, selected_gift_product_id nullable exists:products, type nullable in:mobile,web, fulfillment_type nullable in:delivery,pickup (pay_at_cashier forces pickup), payment_method nullable in:online,cod,pay_at_cashier, gateway nullable string≤50, governorate_id required-if physical+delivery (integer exists:governorates), pickup_location_id required-if pickup (exists:pickup_locations)`. Plus `payment_method/gateway/fulfillment_type` merged by controller.
- BEHAVIOR (VERIFIED `OrderService::addItemsInOrder`): locks cart, refreshes prices, asserts products active, revalidates `cart.coupon` with STRICT checkout context (`governorate_id` present, may be null → area fails closed). Invalid coupon is SILENTLY STRIPPED (`cart.coupon=null`) and order proceeds WITHOUT coupon — frontend distinguishes by comparing `order.coupon` (null = removed during revalidation). Computes promotion→coupon→tax→shipping, creates/updates pending order, returns order.
- SUCCESS: order object + payment branch: `online` → `PaymentCheckoutHandler::handleOnlinePayment` returns `{url}` (gateway redirect); `cod`/`pay_at_cashier` → creates pending transaction + reserves coupon, returns `{order_id}`.
- ERRORS: 400 cart not found/empty; 422 validation / `COD_NOT_AVAILABLE_FOR_PICKUP` / minimum order; 500 create failure. Coupon removal is NOT an error.
- STATE CHANGE: yes (order created). Retry: pending-order reuse — safe to retry, does not duplicate orders.
- FRONTEND: after checkout, if you sent a coupon but `order.coupon == null`, show "coupon removed during revalidation" (`COUPON_NO_LONGER_ELIGIBLE` UI). Proceed to payment URL when `online`.

### 2.F — Fast shipping checkout

- PURPOSE: fast-shipping variant (always has delivery area).
- WHEN: fast-shipping checkout submit.
- AUTH: Sanctum.
- METHOD/URL: `POST /api/v1/general/fast-shipping/checkout`
- BODY = `FastCheckoutRequest` (actual): `name*, user_phone*, user_email nullable, address* array, notes nullable, governorate_id* required integer exists:governorates, selected_promotion_id nullable, selected_gift_product_id nullable, fulfillment_type nullable in:delivery,pickup, payment_method nullable in:online,cod,pay_at_cashier, gateway nullable, pickup_location_id required-if pickup`. Governorate is ALWAYS required here → `area_in` always strict.
- BEHAVIOR: same orchestrator revalidation with strict `fastContext`; invalid coupon stripped silently; otherwise identical payment branching.
- FRONTEND: same as 2.E, but area errors surface as 422 `governorate_id` validation when missing.

### 2.G — Payment callbacks (gateway/backend owned)

- `MATCH GET|POST /api/v1/general/checkout/callback` (public, `throttle:payment-callback`): success path. Locks transaction FOR UPDATE, idempotency check, verifies amount+currency, marks paid, commits inventory reservation, `finalizePromotionUsageAfterPayment`, `changeOrderStatus(completed, emit=false)`, `recordCouponUsage` (locks Coupon+Assignment, revalidates if reservation stale/missing, increments `coupons.used` + assignment `used`, creates usage row, consumes reservation, sets `coupon_consumed=true`), then dispatches `PaymentSucceeded` → `MarkCouponClaimRedeemed` (ACTIVE→REDEEMED) + invoice + digital fulfillment. Duplicate callback = safe no-op (usage unique + `coupon_consumed` flag + claim lock). Mobile returns JSON `{status,message,payment_id}`; web redirects to `app_url_frontend/{locale}/payment/...`.
- `MATCH GET|POST /api/v1/general/checkout/error-callback`: failure path. Marks transaction failed, releases coupon reservation (delete-by-order, idempotent), NO usage, NO redemption, NO counter increment. Duplicate error callback safe.
- FRONTEND: NEVER mark coupon used client-side. Poll order status / wait for redirect. Handle `failed` redirect by showing CLAIMED (not REDEEMED) state.

## 3. Admin endpoints (canonical `/api/v1/coupons/*` + legacy alias `/api/v1/admin/coupons/*`)

Canonical targeting/helpers live in `packages/marvel/src/Rest/Routes.php` under `/api/v1/coupons/{id}/...` (same controller logic). Legacy `/api/v1/admin/coupons/*` aliases are retained in `routes/api.php` (no route names, same middleware) for the admin frontend + existing tests — both URIs serve identical behavior. Prefer canonical for new code.

Auth: Sanctum + permissions (see each). All use envelope.

### 3.1 Coupon CRUD (`Marvel\Http\Controllers\CouponController`, `CouponRepository`)
- `GET /api/v1/coupons` perm `view-coupons`: query `limit(default15), active, inactive, search(name+code), order(id|code|name|discount|...), sortedBy(asc|desc)`. Returns paginated `{data, page, current_page, from,to,last_page,path,per_page,total,...}` with `Marvel CouponResource` (includes `code, limiter, used, status, is_valid, is_assigned, assignments` — ADMIN ONLY shape).
- `POST /api/v1/coupons` perm `create-coupon`: multipart/form: `name* array (unique translations), image-desktop* image, image-mobile* image, border_color nullable, borderless in:1,0, discount* numeric≥0, discount_type* in:percentage,fixed_rate,free_shipping, max_discount_amount required-if percentage numeric≥1, start_date* Y-m-d, end_date* Y-m-d ≥start, limiter nullable int≥0, status in:1,0`. 201. `code` auto-generated canonical uppercase when omitted; `used` never accepted from input (repository whitelist).
- `GET /api/v1/coupons/{coupon}` perm `view-coupons`: `{coupon}` = id OR code (`where id or code`). 200 / 404.
- `PUT|PATCH /api/v1/coupons/{coupon}` perm `update-coupon`: same fields `sometimes`. 200 / 400.
- `DELETE /api/v1/coupons/{coupon}`: deletes coupon (FK cascade: targeting/assignments/claims/usages/reservations per migrations). Blocked paths: deleting with live reservations/existing usages creates inconsistent money state — verify no pending orders before delete (manual check; no automatic guard).
- Frontend action: after create → configure targeting → assign → activate (`status=1`).

### 3.2 Assignments (`CouponAssignmentController`, `CouponAssignmentRepository`)
- `GET /api/v1/coupons/{coupon}/assignments` perm `view-coupon-assignments`: `limit(default15)` → `{data: CouponAssignmentResource[], current_page,from,last_page,per_page,to,total}`.
- `POST /api/v1/coupons/{coupon}/assignments` perm `create-coupon-assignment`: `{user_id* int exists:users, max_uses* int≥1, expires_at nullable date after:now}`. 201 with resource. Duplicate `(coupon,user)` → 409 `COUPON_ALREADY_ASSIGNED_TO_USER` (unique arbiter). Fires `CouponAssigned` AFTER commit → DB+Pusher+FCM (no email).
- `GET /api/v1/coupons/{coupon}/assignments/{assignment}` perm view: 200 / 404.
- `PUT /api/v1/coupons/{coupon}/assignments/{assignment}` perm update: `{max_uses sometimes int≥1, expires_at nullable date after:now}`. `max_uses < used` → 422 `MAX_USES_BELOW_USED_COUNT`.
- `DELETE ...` perm delete: blocked when `used>0` → 409 `CANNOT_DELETE_ASSIGNMENT_WITH_USAGE`.
- Resource: `id, coupon_id, user_id, user{id,name,email} (when loaded), max_uses, used, remaining, is_expired, assigned_at, expires_at`.

### 3.3 Targeting (`CouponTargetingController`, auth: read needs `view-coupons|update-coupon|create-coupon` or admin type; write needs `update-coupon|create-coupon`)
URIs (both serve): canonical `/api/v1/coupons/{id}/targeting` + legacy alias `/api/v1/admin/coupons/{id}/targeting`.
- `GET .../targeting`: 200 `CouponTargetingResource{id,coupon_id,mode,require_claim,max_claims,claim_ttl_hours,rule_tree,created_at,updated_at}` / 404 `COUPON_NO_TARGETING`.
- `PUT .../targeting` body `UpsertTargetingRequest`: `{mode* in:assignment,dynamic,assignment_and_dynamic,assignment_or_dynamic, require_claim* boolean, max_claims nullable int 1–1M, claim_ttl_hours nullable int 1–8760, rule_tree nullable array}` + fail-closed `RuleTreeValidator` (invalid → 422 `{errors:[...]}`). Null tree in dynamic family = "no rules" (eligible). 200.
- `DELETE .../targeting`: removes row → coupon becomes always-eligible/assignment-fallback (DANGEROUS: silently widens access). Frontend must confirm; policy: do not delete targeting on live coupons — set `mode=assignment` + `require_claim` instead.
- Truth table: `assignment`=usable assignment only; `dynamic`=tree only; `assignment_and_dynamic`=both; `assignment_or_dynamic`=either (assignment path counts only when coupon grants assignments AND user holds valid one).

### 3.4 Helpers (`CouponConfigurationController`)
Both URI families serve (canonical `/api/v1/coupons/...` + legacy `/api/v1/admin/coupons/...`).
- `POST .../validate-configuration` body `{coupon_type* in:public,assigned, limiter nullable int≥1, max_uses_per_user nullable int≥1}` → `{valid, errors[], warnings[], recommendations[]}` (public multi-use rejected).
- `GET .../{id}/usage-info` → `{coupon_code, coupon_type(public|assigned), usage_model, current_usage, global_limit, remaining_capacity|unlimited, is_multi_use_per_user, assignment_info{total, with_usage, max_per_user, total_possible}|null, public_usage_count}`.
- `POST .../{id}/suggest-fix` body `{desired_behavior: multi_use_per_user|single_use_per_user}` → steps + example code.
- `GET /api/v1/dashboard/coupons` perm `view-analytics`: `DashboardController::couponAnalytics` → `{success:true, message, data}` analytics payload.

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
