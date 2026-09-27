# Coupon API Business Contract Verification

## Final Status

**PASS WITH GAPS**

All documented endpoints were traced to real routes + controllers + requests/resources/services. Every method, URL, auth rule, request field, response field, status code, error reason, and business rule in the contract was checked against source. The gaps below are explicitly marked in the contract and listed under Not Verified — none of them affects the core customer/admin flows.

---

## Verified

- **Customer endpoints** — all 5 routes exist in `routes/api.php` (lines 74, 131–134) with the exact methods/paths: `GET general/coupons`, `GET general/coupons/mine`, `GET general/coupons/available`, `POST general/coupons/apply`, `POST general/coupons/{id}/claim` (numeric). Auth middleware (`throttle:public-api` vs `auth:sanctum`) confirmed per route.
- **Apply behavior** — `code required|string|max:191` validation, `no_cart` null-cart branch, `invalid+reason` 400 branch, `already_applied` 200 branch, success 200 branch, all in `App\Http\Controllers\Api\General\CouponController::applyCoupon`. Machine-readable `reason` + `COUPON_<REASON>` codes confirmed.
- **Claim behavior** — `ClaimCouponRequest` (auth + empty rules), `CouponClaimService::claim` (targeting lock, require_claim, ACTIVE/REDEEMED checks, max_claims slots, eligibility, TTL), all 5 `CouponClaimException` reasons + 201/409/404/500 mapping in both claim controllers (general + Marvel admin copy).
- **Validation chain** — `CouponValidator` (disabled/not_active/expired/usage_limit_reached/already_used/product_not_eligible/not_found), `CouponAssignmentValidator` (not_assigned/assignment_expired/usage_quota_exceeded), `CouponOrchestrator` (claim_required gate, 4 targeting modes, prior-public-use policy), `EligibilityEngine` (assignment/dynamic/combined, unknown-mode fail-closed).
- **Calculation** — `CouponCalculator::calculate` (percentage + max cap, fixed_rate floor, free_shipping flag, 2-decimal rounding) and **promotion-first-then-coupon** order in `OrderService::calculateCheckoutTotals` (promotion totals → coupon on the remainder).
- **Usage** — `limiter=null` = unlimited (`scopeValid`, validator, reservation service all confirm); `recordCouponUsage` (assigned path increments coupon+assignment+usage row, public path `firstOrCreate` single-use, idempotent repeat, locked rows); prior-public-use blocks assigned reuse.
- **Reservation** — `CouponReservationService` (30-min TTL, per-order idempotent hold, `used+live holds` capacity check, consume/release, stale-hold revalidation in `OrderService::revalidateAndReacquireReservation`). No public route exposes it (route search confirms).
- **Audience** — `CouponAudienceResolver::resolve/composeType` (is_public flag × assignments × targeting → 7 composite types) and its exact rendering in `CouponResource` (`audience`, `is_assigned`, `audience_type`, `targeting_mode`, `targeting`, `assignments`).
- **Targeting** — `CouponTargetingController::show/upsert/destroy` (routes + alias, read vs write permission split, fail-closed rule_tree check, DELETE→always-eligible), `UpsertTargetingRequest` fields, `CouponTargetingResource` fields, `CouponRulesController::show` + `CouponRuleMetadata` catalog.
- **Assignments** — all 5 routes in `Rest/Routes.php` with per-action permissions; `CouponAssignmentController` + `CouponAssignmentRepository` (scoped queries, duplicate→409, update floor, delete blocked when `used>0`→409); store/update request rules; `CouponAssignmentResource` fields (`remaining`, `is_expired`, limited user object).
- **Claim fields** — targeting-row storage (`mode/require_claim/max_claims/claim_ttl_hours/rule_tree`), ACTIVE/REDEEMED/EXPIRED lifecycle, capacity semantics, TTL expiry + reconcile command.
- **Admin CRUD** — `apiResource('coupons', CouponController)` under the `auth:sanctum` admin group (provider prefix `api/v1` confirmed in `RestAPIServiceProvider`); `CouponRequest`/`UpdateCouponRequest` field tables (incl. `is_public`, system-managed `code/slug/used`); show-by-id-or-code; response shape from `CouponResource`.
- **Admin list filters** — all 30+ parameters in `CouponIndexRequest::rules` (+ boolean normalization, contradictory-range 422s); AND-combination, search semantics (translated name OR code), sort allow-list, `AdminCouponFilter` single home; customer-listing params (`search/limit/start_date/end_date/couponsId/order`) in `CouponService::getCoupons` + assignment-only exclusion + guest-code-hiding.
- **Helpers** — `validate-configuration` (3 inputs, public-single-use error, assigned guidance, limiter warnings), `usage-info` (legacy `isPublic()` definition + all 9 output fields), `suggest-fix` (`desired_behavior` allow-list, 3 recommendation kinds, read-only + action links).
- **Area rule** — saved-address `area_in` evaluation (active governorates, NULL never matches, checkout input ignored), checked at every stage.
- **`is_valid` split** — resource-level basic validity vs full orchestrator validity, documented as different concepts.
- **Missing endpoints** — each NOT FOUND claim backed by route-wide + request-wide searches (no validate/remove/bulk/history/min-order/currency endpoints; stale `api-desc` names `status/valid/order_by/sort`, `add-to-cart` route absent).
- **Audience vs targeting separation** — resolver (visibility) vs mode (evaluation) never mixed; legacy `isPublic()` vs resolver distinction called out.

## Not Found

- Customer coupon-validation-only endpoint.
- Customer remove-coupon endpoint.
- Customer assignment management endpoints.
- Admin bulk assignment endpoints.
- Coupon minimum-order / maximum-order / currency conditions.
- Admin list params `not_expired`, `starting_soon`, `currently_active`, `assignment_expires_from/to`, `status`, `valid`, `order_by`, `sort`.
- `POST /api/v1/coupons/add-to-cart` route (stale doc reference only).

## Not Verified

- Exact display strings of `COUPON_*` / `__('coupon.*')` translation constants (keys verified in code; rendered wording depends on locale files).
- `AvailableCouponsService` item shell fields beyond `data/meta` (verified: `current_page/per_page/total/has_more_pages`; item internals not enumerated).
- Distribution endpoints (`distribute`, `distributions`, `distributions/{runId}`) request/response bodies (routes + controller existence verified only).
- Dashboard `GET /api/v1/dashboard/coupons` response shape (route verified only).
- Delete-coupon cascade effects on orders/assignments/claims/targeting/holds (no verified source; marked in contract).
- GraphQL coupon operations' runtime use (schema files exist; storefront/admin flows use REST).
- Runtime execution: no server was started and no HTTP calls were made — verification is static (source-to-document trace), per the discovery-only mandate. No production code was modified.

## Important Findings

- Coupon amount is calculated by backend; frontend sends only `code` (apply) or `id` (claim).
- Coupon eligibility depends on Audience (visibility) + Targeting mode (evaluation) + claim state — three separate concepts the frontend must not conflate.
- `is_valid:true` does not mean "this customer can use it" — apply/claim/checkout re-check everything.
- Public coupons are single-use per customer; multi-use requires assignments; past public use blocks assigned-path reuse.
- `limiter = null` means unlimited, not zero.
- Checkout silently drops a coupon that became invalid and continues the order — the frontend must refresh totals after checkout preview calls.
- Area rules read saved addresses only; checkout delivery input never affects coupons.
- Promotion is priced first; coupon applies to the remainder.
- Stale `api-desc/coupon/api.md` parameter names (`status/valid/order_by/sort`, `add-to-cart`, `coupon_code` field) do not match the current implementation — the contract follows the code.

## Files Examined

- `routes/api.php` (customer coupon routes, legacy admin aliases, checkout routes)
- `packages/marvel/src/Rest/Routes.php` (admin coupon CRUD, assignments, targeting/helpers, distribution, dashboard)
- `packages/marvel/src/Providers/RestAPIServiceProvider.php` (api/v1 prefix)
- `app/Http/Controllers/Api/General/CouponController.php` (all 5 customer actions)
- `packages/marvel/src/Http/Controllers/CouponController.php` (admin CRUD + unrouted claim copy)
- `packages/marvel/src/Http/Controllers/CouponAssignmentController.php`
- `app/Http/Controllers/Api/Admin/CouponTargetingController.php`
- `app/Http/Controllers/Api/Admin/CouponConfigurationController.php`
- `app/Http/Controllers/Api/Admin/CouponRulesController.php`
- `packages/marvel/src/Http/Requests/CouponRequest.php`, `UpdateCouponRequest.php`, `CouponIndexRequest.php`, `CouponAssignmentRequest.php`, `UpdateCouponAssignmentRequest.php`
- `app/Http/Requests/Coupon/UpsertTargetingRequest.php`, `ClaimCouponRequest.php`
- `packages/marvel/src/Http/Resources/CouponResource.php`, `CouponAssignmentResource.php`
- `app/Http/Resources/Coupons/CustomerCouponResource.php`, `app/Http/Resources/Coupon/CouponTargetingResource.php`, `CouponClaimResource.php`
- `packages/marvel/src/Database/Models/Coupon.php`, `CouponTargeting.php`
- `app/Services/General/CouponService.php` (listing + addCouponToCart)
- `app/Services/Coupon/CouponValidator.php`, `CouponCalculator.php`, `CouponOrchestrator.php`, `CouponAssignmentValidator.php`, `CouponClaimService.php`, `CouponReservationService.php`, `CouponClaimRequirement.php`
- `app/Services/Coupon/Audience/CouponAudienceResolver.php`
- `app/Services/Coupon/Eligibility/EligibilityEngine.php` (modes + area_in)
- `app/Services/Coupon/Discovery/CouponDiscoveryPolicy.php` (referenced), `AvailableCouponsService.php` (meta shape)
- `app/Services/Coupon/Distribution/Discovery/AvailableCouponsService.php`
- `app/Services/General/OrderService.php` (checkout revalidation, totals order, recordCouponUsage, reservation policy)
- `packages/marvel/src/Database/Repositories/CheckoutRepository.php` (verify pricing), `CouponRepository.php`, `CouponAssignmentRepository.php`
- `app/Exceptions/CouponClaimException.php`, `packages/marvel/src/Enums/DiscountType.php`
- `api-desc/coupon/api.md`, `api-desc/coupon-assignment/api.md` (cross-checked; stale areas noted, not used as truth)
- `tests/Feature/Coupon/` (claim lifecycle, eligibility, audience matrix, admin list filters, checkout revalidation), `tests/Feature/CouponAssignment/` (API + validation), `tests/Feature/CouponDistribution/AvailableCouponsApiTest.php`, `AdminDistributionApiTest.php` (existence confirmed; individual assertions spot-checked)
- `packages/marvel/src/GraphQL/Schema/models/coupon.graphql` (REST/GraphQL separation note)
