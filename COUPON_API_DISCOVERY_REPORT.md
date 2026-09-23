# Coupon REST — API Discovery Report (generated from live `route:list`, 2026-09-26)

> Source: `php artisan route:list --path=coupons -v` + `routes/api.php` + `packages/marvel/src/Rest/Routes.php`.
> 33 routes. No route invented; aliases and legacy paths recorded as found.

## Counts
- Admin CRUD (Marvel `CouponController`): 5 — `coupons.index|store|show|update|destroy`.
- Assignment (Marvel `CouponAssignmentController`): 5 — index/store/show/update/destroy.
- Configuration (`CouponConfigurationController` + `CouponRulesController`): 4 canonical + 4 legacy `admin/` aliases sharing the same actions.
- Targeting (`CouponTargetingController`): 3 canonical + 3 legacy `admin/` aliases sharing the same actions.
- Distribution (`CouponDistributionAdminController`): 3 (canonical paths only; distribution routes were NOT duplicated under `admin/` prefix — only config/targeting were).
- Customer (`Api\General\CouponController`): 5 — index/available/mine/claim/apply.
- Dashboard analytics: 1 — `GET api/v1/dashboard/coupons` (`couponAnalytics`, sanctum + `throttle:analytics` + `view-analytics`).
- Coupon-adjacent (consumption path, not coupon-owned): checkout, callbacks, mark-paid, cart, promotions — traced in the contract doc.

## Middleware matrix (from `-v` output)
- Admin CRUD: `api` + `auth:sanctum` + `throttle:admin` + `CheckLangMiddleware` + Spatie `permission:{view|create|update|delete}-coupon(s)`; assignments add per-action `permission:{view|create|update|delete}-coupon-assignment` (route-level AND constructor-level — defense in depth, `Routes.php:273-279` + controller `__construct`).
- Config/targeting/distribution: `api` + `auth:sanctum` + `throttle:admin` (+ `CheckLang` on canonical paths; legacy `admin/` aliases carry sanctum+admin throttle WITHOUT `CheckLang`); authorization is IN-CONTROLLER: reads accept `view-coupons|update-coupon|create-coupon`, writes require `update-coupon|create-coupon` (targeting writes reject bare `type=admin` without the permission), distribution permission from `config('coupon-distribution.admin_permission')` default `update-coupon`.
- Customer: catalog `GET general/coupons` is PUBLIC (`throttle:public-api`, optional auth — unresolvable bearer → 401 by design); available/mine/claim/apply require `auth:sanctum` + `throttle:authenticated`.
- Checkout: `POST general/checkout` sanctum; callbacks public + `throttle:payment-callback`; webhooks (Stripe/PayPal, concurrent-session WIP — NOT traced) + `throttle:payment-webhook`; mark-paid + `permission:payments.mark_paid`.

## Response envelopes (from `Marvel\Traits\ApiResponse` + `Handler.php`)
- Controller envelope: `{status, message, success, data?}` — `status` echoes HTTP code, `success` bool, `data` omitted when empty. (Prior docs wrote `{success,message,data}` — CORRECTED.)
- Framework/validation errors: Marvel FormRequests → 422 `{message, errors}` (no envelope); `MarvelException` → `{message, status:false}` with 404 on `NOT_FOUND`, 403 on `NOT_AUTHORIZED`, else 500.
- `MarvelBadRequestException` is a Symfony `HttpException` (default 400) — assignment/targeting controllers catch it explicitly and remap (409 duplicate, 422 floor-breach, 400 generic); uncaught instances render via Laravel default handling.

## Audience visibility gap → fixed (minimal)
- BEFORE: admin `CouponResource` exposed `is_assigned` + raw `assignments` but no derived audience state and no targeting mode.
- AFTER (this work, additive only): `audience_type` (`PUBLIC|ASSIGNED|TARGETED|ASSIGNED_AND_TARGETED` via `CouponAudienceResolver::audienceType()`, computed from persisted rows) + `targeting_mode` (nullable) in `CouponResource`; eager loads in admin `index`/`show`. No `coupons.mode` column, no `is_public` column, `Coupon::isPublic()` frozen. Customer `CustomerCouponResource.visibility` (`public|targeted|assignment-only`) already existed and is unchanged.
- `mode` stays owned by `coupon_targetings` (4 values); `PUT /targeting` remains the ONLY writer of mode; `POST /coupons` intentionally accepts NO mode/targeting fields (sequence: create → PUT targeting → POST assignments).

## Canonical ownership (for the contract doc)
- `app/` owns: audience resolution, targeting CRUD, distribution, discovery/eligibility orchestration, claim, notifications, outbox, consumers.
- Marvel owns: base coupon CRUD + media, assignment persistence, code generation (`Coupon::creating`), base validator.
- Integration: assignment repository fires `App\Events\CouponAssigned` AFTER commit; observers fire `CouponCreated/CouponActivated/CouponDisabled`; distribution events are `App\Events\Coupons\*`.
