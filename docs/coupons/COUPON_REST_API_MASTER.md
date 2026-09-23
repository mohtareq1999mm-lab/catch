# Coupon REST API Master (source-verified, route:list 33 routes)

> Every endpoint proven by `php artisan route:list` + route files. Response shapes from Resources/controllers. Audience: admin vs customer. Envelope `{status,message,success,data?}` (`status` echoes HTTP code; `data` omitted when empty) — CORRECTED from source (`Marvel\Traits\ApiResponse`).

## A. Admin CRUD — Marvel `CouponController` (`api/v1/coupons`, sanctum+admin throttle)
| Method | URI | Name | AuthZ | Body (CouponRequest) | Success | Errors |
|---|---|---|---|---|---|---|
| GET | `/api/v1/coupons?limit&search&active&inactive&order&sortedBy` | coupons.index | `view-coupons` | — | 200 `data:{data:[rows],page,current_page,from,to,last_page,path,per_page,total,*_page_url}` + `audience_type`/`targeting_mode` per row (NEW) | 401/403 |
| POST | `/api/v1/coupons` | coupons.store | `create-coupon` | multipart: `name{en,ar}` required, **`image-desktop`+`image-mobile` REQUIRED**, `discount`, `discount_type`, `max_discount_amount?` (required_if percentage), `start_date/end_date` Y-m-d, `limiter?`, `status?` (code ALWAYS server-generated; 422 shape is Marvel `{message,errors}`) | 201 (RUNTIME-PROVEN) | 401/403/422/400 |
| GET | `/api/v1/coupons/{id}` (id **OR code**) | coupons.show | `view-coupons` | — | 200 + `audience_type`/`targeting_mode` (RUNTIME-PROVEN: ASSIGNED_AND_TARGETED) | 401/403/404 |
| PUT/PATCH | `/api/v1/coupons/{id}` | coupons.update | `update-coupon` | UpdateCouponRequest | 200 | 400/401/403/404/422 |
| DELETE | `/api/v1/coupons/{id}` | coupons.destroy | `delete-coupon` | — | 200 | 401/403/404 |
DB: INSERT/UPDATE/DELETE `coupons` (+media); observer fires `CouponCreated`/`CouponActivated`/toggles + cache bust. Idempotency: create none (canonical code guard only); update/delete safe-retry.

## B. Configuration (`CouponConfigurationController` + `CouponRulesController`, sanctum, in-controller `view-coupons`)
- `GET /api/v1/coupons/rules` (+ legacy alias): 200 rule catalog (17 types + metadata). No writes.
- `POST /api/v1/coupons/validate-configuration` (+ alias): `{coupon_type: public|assigned, limiter?, max_uses_per_user?}` → 200 `{valid,errors[],warnings[],recommendations[]}` (RUNTIME-PROVEN). Pure function, no writes.
- `GET /api/v1/coupons/{id}/usage-info` (+ alias): 200 usage explainer (RUNTIME-PROVEN). Read-only.
- `POST /api/v1/coupons/{id}/suggest-fix` (+ alias): 200/400 observed (400 NOT RUNTIME-classified beyond observed).

## C. Targeting (`CouponTargetingController`, sanctum; read `view-coupons`, write `update-coupon`)
- `GET .../targeting`: 200 `CouponTargetingResource{id,coupon_id,mode,require_claim,max_claims,claim_ttl_hours,rule_tree,created_at,updated_at}`; 404 `COUPON_NO_TARGETING` (RUNTIME-PROVEN).
- `PUT .../targeting` (`UpsertTargetingRequest`: mode∈4, require_claim bool, max_claims 1..1M?, ttl?, rule_tree?): RuleTreeValidator 422 fail-closed → transactional upsert → `CouponTargetingChanged` → delayed run + cache bust → 200 (RUNTIME-PROVEN).
- `DELETE .../targeting`: removes row (back to always-eligible) → change event → cache bust → 200 (RUNTIME-PROVEN).

## D. Assignments (`CouponAssignmentController`, per-action Spatie permissions)
- `GET .../assignments`: 200 paginated (`view-coupon-assignments`).
- `POST .../assignments` (`user_id` exists, `max_uses` int≥1 required, `expires_at?` future): unique→409 `COUPON_ALREADY_ASSIGNED_TO_USER` (race-safe), event AFTER commit → immediate `coupon.assigned` (payload incl. `requires_claim`) → 201 (RUNTIME-PROVEN).
- `GET .../assignments/{a}`: 200/404. `PUT`: only `max_uses` (floor vs used → **422** `MAX_USES_BELOW_USED_COUNT` — CORRECTED) + `expires_at`, silent, 200 (suites green). `DELETE`: refused if used>0 (409), silent (suites green).
- `POST .../distribute` (`trigger:manual?`, `audience_cap?` ≤100k, in-controller `update-coupon`): 202 run / 409 running / 422 non-dynamic (RUNTIME-PROVEN 202→409).
- `GET .../distributions`, `GET .../distributions/{run}`: 200 + recipient breakdown (RUNTIME-PROVEN list).

## E. Customer (`routes/api.php`)
- `GET /api/v1/general/coupons` (public group, optional auth; bad token→401): catalog public+targeted, codes gated by policy; 1-min versioned cache. 200 (RUNTIME-PROVEN).
- `GET .../coupons/available` (sanctum): personalized eligible-only feed, paginated, versioned 60s cache. 200 (RUNTIME-PROVEN, code hidden pre-claim).
- `GET .../coupons/mine` (sanctum): owner assignments (code/max_uses/used/remaining/expiry) + claims. 200 (RUNTIME-PROVEN).
- `POST .../coupons/{id}/claim` (sanctum, empty body): parent-row lock + gates + snapshot insert → 201 safe shape; 409 reason-coded / 404 / 500-infra (RUNTIME-PROVEN incl. reclaim 409).
- `POST .../coupons/apply` (`code` required ≤191): orchestrator→validator→engine→calculator → 200 totals or 400 `COUPON_<REASON>` (RUNTIME-PROVEN 200 discount + `not_found` 400).

## F. Checkout & payment (coupon-affecting)
- `POST /api/v1/general/checkout` (sanctum, `OrderCreateRequest`): locked revalidation + snapshot + 30-min reservation + gateway branch → 200 order / 400 cart / 422 validation / 500 infra (RUNTIME-PROVEN order 27 post tax-fix).
- `checkout/callback|error-callback` (public, 20/min): verify → complete-once / release (error-cb 400 RUNTIME-PROVEN; invalid gateway callback fail-closed OBSERVED).
- `mark-paid` COD/cashier (`update-order-status`): 200 then 422 dup-safe (RUNTIME-PROVEN COD).
- `POST /api/v1/cart` (`item.product_id/quantity/shipping_method` nested): 201 (RUNTIME-PROVEN as fixture path).

## G. Queues/broadcast (no extra REST)
Broadcast auth `POST /broadcasting/auth` (sanctum): owner 200 / others 403 (RUNTIME-PROVEN matrix). GraphQL `/graphql`: 500 pre-existing (separate). Dashboard `GET /api/v1/dashboard/coupons` (sanctum + `throttle:analytics` + `view-analytics`): aggregate coupon analytics, cached (inventoried from route:list; body NOT traced — admin analytics surface, out of coupon-flow scope).

ببساطة: كل endpoint فوق مثبت من الـ route:list والكود. الـ Admin بيدير الكوبون (إنشاء/استهداف/إسناد/توزيع) والعميل بيكتشف/يطالب/يطبق/يدفع، والنظام يعيد التحقق في كل خطوة.
