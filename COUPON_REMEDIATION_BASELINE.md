# COUPON REMEDIATION BASELINE (READ-ONLY DISCOVERY)

> Generated: 2026-09-22. REST ONLY scope. GraphQL OUT OF SCOPE.

## Git

- Branch: `main`
- HEAD: `47ff3d8` (`feat: implement idempotency key`)
- Tree: dirty (coupon + shipment/tracking changes present, do not reset/stash/revert)
- Modified (coupon-relevant): `app/Http/Controllers/Api/General/OrderController.php`, `app/Http/Resources/Coupons/CouponResource.php`, `app/Listeners/Coupon/MarkCouponClaimRedeemed.php`, `app/Providers/EventServiceProvider.php`, `app/Services/Coupon/CouponClaimService.php`, `CouponOrchestrator.php`, `CouponReservationService.php`, `CouponValidator.php`, `app/Services/General/CouponService.php`, `FastShippingService.php`, `OrderService.php`, `app/Services/Payment/PaymentCheckoutHandler.php`, `packages/marvel/src/Database/Models/Coupon.php`, `Order.php`, `Repositories/CheckoutRepository.php`, `CouponRepository.php`, `Http/Resources/CartResource.php`, `Services/Pricing/ProductPricingService.php`, `routes/api.php`, `packages/marvel/src/Rest/Routes.php`
- Untracked (coupon-relevant): `COUPON_BUSINESS_POLICY.md`, `COUPON_INVARIANTS.md`, `COUPON_REMEDIATION_PLAN.md`, `COUPON_TEST_MATRIX.md`, `COUPON_REMEDIATION_FINAL_REPORT.md`, `COUPON_PRODUCTION_READINESS_AUDIT.md`, `app/Support/CouponCode.php`, `app/Exceptions/CouponConsumptionException.php`, `app/Console/Commands/ReconcileCouponState.php`, `tests/Feature/CouponRemediationTest.php`, `CouponConcurrencyProofTest.php`

## Runtime

- Laravel: `10.30.1`
- PHP: `8.2.30` ZTS Visual C++ 2019 x64
- DB: `sqlite` default (`database/database.sqlite`), MySQL 8.4.3 proof via `phpunit.mysql.xml` (`127.0.0.1:3307`)
- Queue: `database`
- Scheduler: `orders:cancel-unpaid` 5min, `coupons:expire-reservations` 5min, `coupons:expire-claims` hourly, NO `coupons:reconcile` schedule (F-06 OPEN)

## REST Route Inventory (VERIFIED via `php artisan route:list --path=coupons`)

### Customer (`routes/api.php`, prefix `api/v1/general`)

- `GET api/v1/general/coupons` → `CouponController@index` (public, throttled)
- `POST api/v1/general/coupons/apply` → `CouponController@applyCoupon` (auth:sanctum)
- `POST api/v1/general/coupons/{id}/claim` → `CouponController@claim` (auth:sanctum)
- `POST api/v1/general/checkout` → `OrderController@checkout` (auth:sanctum)
- `POST api/v1/general/fast-shipping/checkout` → `FastShippingController@checkout` (auth:sanctum)
- `MATCH api/v1/general/checkout/callback` → `OrderController@checkoutCallback` (public, payment-callback throttle)
- `MATCH api/v1/general/checkout/error-callback` → `OrderController@checkoutErrorCallback` (public)
- `POST api/v1/general/checkout/cod/{orderId}/mark-paid` (permission:update-order-status)
- `POST api/v1/general/checkout/cashier/{orderId}/mark-paid` (permission:update-order-status)

### Admin (`packages/marvel/src/Rest/Routes.php`, prefix `api/v1`, middleware `auth:sanctum,throttle:admin,lang`)

- `GET api/v1/coupons` → `Marvel CouponController@index` (permission:view-coupons) — VERIFIED EXISTS (N-01 FALSE)
- `POST api/v1/coupons` → `store` (permission:create-coupon)
- `GET api/v1/coupons/{coupon}` → `show` (permission:view-coupons)
- `PUT|PATCH api/v1/coupons/{coupon}` → `update` (permission:update-coupon)
- `DELETE api/v1/coupons/{coupon}` → `destroy` (permission:delete-coupon)
- `GET|POST|GET|PUT|DELETE api/v1/coupons/{coupon}/assignments[/{assignment}]` → `CouponAssignmentController` (permissions view/create/update/delete-coupon-assignment)
- `POST api/v1/admin/coupons/validate-configuration`, `GET .../{id}/usage-info`, `POST .../{id}/suggest-fix` → `CouponConfigurationController` (custom authorizeAdmin: view-coupons|update-coupon|create-coupon)
- `GET api/v1/dashboard/coupons` → `DashboardController@couponAnalytics`
- NO targeting/rule_tree REST (Phase 5 GAP CONFIRMED)

## Database (migrations VERIFIED)

- `coupons`: `code unique`, `slug NOT NULL`, `discount`, `discount_type (percentage|fixed_rate|free_shipping)`, `max_discount_amount`, `start_date/end_date`, `limiter nullable`, `used` counter, `status bool`, `border_color/borderless`. Fillable includes `used` (F-04 OPEN), repo `dataArray` whitelists business fields only (used/code excluded) — partial mitigation.
- `coupon_targetings`: `coupon_id unique FK cascade`, `mode ENUM(assignment,dynamic[,assignment_and_dynamic,assignment_or_dynamic] via 2026_09_14_000003)`, `require_claim bool`, `max_claims nullable (renamed from max_claims_per_user)`, `rule_tree JSON nullable`, `claim_ttl_hours nullable`, `index(require_claim)`
- `coupon_claims`: `coupon_id FK`, `user_id FK`, `claimed_at`, `eligibility_snapshot JSON`, `status (active|redeemed|expired via 2026_09_14_000001)`, `expires_at`, `redeemed_at`, `claimed_at`; original `unique(coupon_id,user_id)` DROPPED, replaced by `idx_claim_lookup`; duplicate ACTIVE guard is application-only (lock + check)
- `coupon_reservations`: `coupon_id FK`, `user_id FK`, `order_id FK unique`, `reserved_at`, `expires_at`, `index(coupon_id,expires_at)`, TTL 30min (code constant)
- `coupon_assignments`: `coupon_id FK`, `user_id FK`, `max_uses default 1`, `used default 0`, `assigned_at`, `expires_at nullable`, `unique(coupon_id,user_id)`
- `coupon_assignment_usages`: `coupon_assignment_id FK`, `order_id NOT NULL FK`, `used_at`, `unique(assignment,order)+check` (via 2026_09_11_000002/000003)
- `coupon_usages`: `coupon_id FK`, `user_id FK`, `order_id nullable`, `used_at`, `unique(coupon_id,user_id)`
- `coupon_product`: `coupon_id`, `product_id`
- `orders`: `coupon snapshot string nullable`, `coupon_consumed bool`, `status (pending|processing|completed|cancelled|delivered)`, `payment_status`, `converted_total_price`, `total_price`, `coupon_discount`
- `transactions`: `order_id FK`, `invoice_id/gateway_transaction_id`, `payment_method (myfatoorah|cod|pay_at_cashier|...)`, `status (pending|paid|failed)`, `amount/currency`

## Existing Tests (VERIFIED via filesystem)

- `tests/Feature/CouponRemediationTest.php`, `CouponConcurrencyProofTest.php`, `AssignedCouponSystemTest.php`, `tests/Unit/Services/Coupon/Eligibility/EligibilityEngineTest.php`, `tests/Concurrency/CouponClaimConcurrencyTest.php`, `CouponClaimRealConcurrencyTest.php`
- Focused suites green (prior evidence: 123 passed, 4 skipped); full suite has 39 unrelated sqlite failures (`order_analytics_hourly: no such table: main.orders` on `orders_processing_new RENAME`)

## Prior Findings Status (re-checked against current tree)

- F-01 OPEN P1: `OrderController.php:610-656` error-callback success path calls `changeOrderStatus(completed)` inside `DB::transaction` with NO `catch (CouponConsumptionException)`; contrast success-callback M1 handling at `:457-504`.
- F-04 OPEN: `Coupon.php $fillable` includes `used`; repo whitelist mitigates but model still mass-assignable.
- F-06 OPEN: no `coupons:reconcile` schedule in `Kernel.php`.
- F-07 OPEN: `CouponCode::queryByCode` uses `UPPER(code)=?` full scan; `code` unique index unused.
- F-08/N-03 OPEN race: `CouponAssignmentRepository::assignCoupon` pre-check `exists()` then `create()`; concurrent unique violation bubbles as raw `QueryException` → 400, not 409.
- F-11 OPEN: `CouponController::claim` returns `['reason','context'=>failed_rules]` to customer.
- F-14 OPEN: `OrderController.php:666` `event(new PaymentSucceeded($order?$order->fresh():null))` can dispatch null.
- F-16 OPEN: `CouponClaimService::claim` only blocks ACTIVE unexpired; REDEEMED allows re-claim occupying another slot while `coupon_usages` unique blocks reuse.
- F-03 OPEN: `CouponOrchestrator::validate` catches generic `Exception` for targeting and silently continues (fail-open).
- N-01 FALSE: admin CRUD EXISTS (see routes above). No fix; document correction.
- Rule engine: 13 rules implemented, flat AND/OR only, NO nested groups; `assignment_and_dynamic`/`assignment_or_dynamic` modes in DB but engine returns `unknown_mode` ineligible (fail-closed, needs combined semantics).
