# COUPON REMEDIATION FINAL REPORT (REST ONLY)

## 1. Executive Summary

REST coupon engine audited against DB + runtime + tests. Admin CRUD VERIFIED EXISTS (N-01 FALSE, no fix). Missing targeting REST implemented. Rule engine upgraded to nested AND/OR + combined modes. F-16, F-08, F-01, F-14, F-11, F-03, F-06, F-07, F-13 fixed. F-04 enforced at repository whitelist (fillable kept for test compat). All targeted suites green. GraphQL untouched (OUT OF SCOPE).

## 2. Current REST Architecture

```text
Admin REST (/api/v1/coupons + /api/v1/admin/coupons)
Customer REST (/api/v1/general/coupons)
Checkout REST (/api/v1/general/checkout, fast-shipping/checkout)
Payment REST/callbacks (/checkout/callback, /error-callback, COD/cashier mark-paid)
→ Services (CouponService, Orchestrator, Validator, Claim, Reservation, EligibilityEngine, CustomerMetricsService, OrderService, PaymentCheckoutHandler)
→ Database (coupons, targetings, claims, reservations, assignments, usages)
→ Jobs/Scheduler (expire-reservations 5min, expire-claims hourly, reconcile hourly, cancel-unpaid 5min)
```

## 3. Admin Flow

- `GET/POST /api/v1/coupons`, `GET/PUT/PATCH/DELETE /api/v1/coupons/{coupon}` (Marvel, permission split VIEW/CREATE/UPDATE/DELETE). Validation via `CouponRequest/UpdateCouponRequest` (discount, dates, limiter, status; no used/code). Repository whitelist strips used/code (proven by test).
- `GET/PUT/DELETE /api/v1/admin/coupons/{id}/targeting` (NEW): mode (4 values), require_claim, max_claims, claim_ttl_hours, rule_tree (recursive validated, fail-closed). Read requires view-coupons; writes require update/create-coupon (BLOCKER fix).
- Helpers: validate-configuration, usage-info, suggest-fix (read-only diagnostics).
- Assignments: `GET/POST/GET/PUT/DELETE /api/v1/coupons/{coupon}/assignments[/{assignment}]` with 409 mapping.

## 4. Customer Flow

- `GET /api/v1/general/coupons` (public, no codes leaked via CouponResource).
- `POST /api/v1/general/coupons/{id}/claim` (auth, 201 ACTIVE, 409 reason-only, 404).
- `POST /api/v1/general/coupons/apply` (auth, validates via Orchestrator, persists canonical code to cart.coupon, preview only, no usage/reservation).

## 5. Claim Flow

`No claim → ACTIVE (TTL) → REDEEMED` or `ACTIVE → EXPIRED → (may re-claim)`. REDEEMED blocks re-claim (F-16). Parent-row `CouponTargeting FOR UPDATE` serializes. max_claims counts ACTIVE-unexpired + REDEEMED. Eligibility evaluated before insert. Expiry via `coupons:expire-claims` hourly.

## 6. Reservation Flow

Temporary 30min hold during payment (`coupon_reservations`, unique order_id). Enforces `used + active_reservations < limiter` under coupon `FOR UPDATE`. Idempotent per order (refresh TTL). Consumed on success, released on failure/cancel/expiry. Sweeper `coupons:expire-reservations` 5min.

## 7. Payment Flow

Online (MyFatoorah via factory), COD, cashier. Reserve before invoice. Success callback: verify → lock transaction+order → idempotency_key + status check → amount/currency check (cents) → commit inventory → finalize promotion → changeOrderStatus(completed) → recordCouponUsage → PaymentSucceeded. Error callback mirrors with F-01 catch + F-09 token parity. COD/cashier via markPaid → changeOrderStatus (single path).

## 8. Usage Flow

Atomic in `changeOrderStatus(completed)` → `recordCouponUsage`: lock coupon → revalidate reservation + Orchestrator (product gate on order items) → assigned path (assignment lock, quota, POLICY1 public-use check, assignment_usage unique) or public path (coupon_usages firstOrCreate, single-use) → increment counters → consume reservation → coupon_consumed flag → afterCommit AssignedCouponConsumed → PaymentSucceeded → MarkCouponClaimRedeemed (ACTIVE→REDEEMED, idempotent). Fail-closed via CouponConsumptionException (rollback, order stays pending).

## 9. Rule Engine

13 whitelisted rules (see MATRIX). Source: `customer_metrics` (rebuilt from qualifying orders) + claims/assignments tables. Fail-closed on unknown/malformed. Value validation (numeric >=0, datetime parseable).

## 10. AND/OR Evaluation

Recursive `evaluateNode` with child outcomes (not leaf counts) — fixes OR leakage. Depth max 10. Empty group/unknown operator/unknown rule/malformed → ineligible. Combined modes: AND requires both, OR requires either.

## 11. Rule Capability Matrix

See `COUPON_RULE_CAPABILITY_MATRIX.md`. All 13 YES/YES/YES. Payment/product/refund/demographic rules explicitly NOT SUPPORTED with reasons.

## 12. Fixed Issues

**F-04 used system-controlled:** Root: fillable included used but repo already whitelisted. Fix: kept fillable for test compat, documented repo whitelist as enforcement, runtime only via increment. Files: `Coupon.php`. Risk: low (no behavior change). Validation: `store_coupon_strips_system_managed_fields` passes.

**N-01 admin CRUD:** Finding: routes EXIST (`api/v1/coupons` apiResource). No fix. Files: none. Risk: none. Validation: `route:list`.

**Targeting REST (Phase5):** Root: no routes. Fix: NEW controller/request/resource/validator + routes. Files: `CouponTargetingController.php`, `UpsertTargetingRequest.php`, `CouponTargetingResource.php`, `RuleTreeValidator.php`, `routes/api.php`. Risk: medium (new surface, permission-gated, validated). Validation: Phase2Test targeting CRUD + 403 view-only test.

**Nested engine + combined modes:** Root: flat only, new modes fail-closed. Fix: recursive evaluateNode with child outcomes, combined evaluators. Files: `EligibilityEngine.php`, `RuleTreeValidator.php`. Risk: medium (logic change, backward compat preserved for flat). Validation: 9 Phase2 tests + 13 engine tests.

**F-16 redeemed block:** Root: only ACTIVE blocked, REDEEMED re-claim occupied slot but unusable. Fix: block REDEEMED in claim(), align NOT_CLAIMED + Orchestrator to already_used. Files: `CouponClaimService.php`, `EligibilityEngine.php`, `CouponOrchestrator.php`. Risk: low (stricter, matches single-use policy). Validation: redeemed/expired test.

**F-08/N-03 assignment race:** Root: exists-check race → raw 500. Fix: catch QueryException unique → 409. Files: `CouponAssignmentRepository.php`. Risk: low. Validation: duplicate 409 test.

**F-11 leakage:** Root: failed_rules in customer response. Fix: reason-only + log. Files: both CouponControllers. Risk: low. Validation: leak test.

**F-03 infra vs business:** Root: broad catch hid DB failures. Fix: Schema::hasTable guard, bubble unexpected. Files: `CouponOrchestrator.php`. Risk: low. Validation: existing suites (no silent pass).

**F-06 reconcile schedule:** Root: no schedule. Fix: hourly withoutOverlapping+onOneServer. Files: `Kernel.php`. Risk: none (read-only). Validation: `coupons:reconcile` 0 issues.

**F-07 lookup:** Root: UPPER() scan. Fix: exact indexed lookup + backfill migration. Files: `CouponCode.php`, `2026_09_27_...normalize...php`. Risk: low (canonical writes guarantee hit; backfill covers legacy). Validation: canonical lookup test + migrate.

**F-13 lock order:** Root: undocumented. Fix: documented global order, verified consistent. Files: `OrderService.php`, `CouponCode.php`, `CouponClaimService.php`, `CouponAssignmentRepository.php`. Risk: none. Validation: code-path review.

**F-01 error callback:** Root: no CouponConsumptionException catch. Fix: mirror success handling + failed transaction + PaymentFailed. Files: `OrderController.php`. Risk: low (fail-closed, no 500). Validation: existing callback test + code review.

**F-09 idempotency parity:** Root: error path lacked token. Fix: token guard. Files: `OrderController.php`. Risk: low. Validation: suites pass.

**F-14 null event:** Root: `PaymentSucceeded(null)` possible. Fix: guard dispatches + contract doc + consumer guard. Files: `OrderController.php`, `PaymentSucceeded.php`. Risk: none. Validation: code review.

**BLOCKER targeting authz:** Root: view-only could write. Fix: split read/write auth. Files: `CouponTargetingController.php`. Risk: low (stricter). Validation: view-only 403 test.

## 13. Remaining Risks

- Full suite has 39 unrelated sqlite failures (`order_analytics_hourly` view) — signal unusable for broad regression; focused coupon suites green.
- Prod data, live gateway retry, UPPER cost NOT VERIFIED (no prod access).
- Payment_method/product/category/refund rules NOT SUPPORTED by design (need policy + metrics pipeline).
- Reconcile hourly may alert on pre-enforcement historical rows; add `--since` window if noisy.
- Marvel `CouponController::show` `orWhere(code)` bypasses canonical lookup (low risk, public listing omits codes).

## 14. Tests

```text
PASS1 targeted:
- EligibilityEngineTest: 13 passed
- CouponRemediationTest: 15 passed (48 assertions)
- AssignedCouponSystemTest: 49 passed (111 assertions)
- CouponRemediationPhase2Test (NEW): 9 passed (nested, combined, F-16, targeting CRUD + 403, 409, lookup, leak)
PASS2 relevant REST: same as above (focused suites green)
PASS3 concurrency: CouponConcurrencyProofTest 0 executed (no isolated cases); parent-row locks + unique guards proven via code + duplicate tests; parallel_claim_proof manual script not run in CI
PASS4 static: php -l 6 files clean; route:list 17 coupon routes verified; coupons:reconcile TOTAL 0
PASS5 independent review: 1 BLOCKER + 4 MUST-FIX found, all fixed, re-tested (Phase2 9/9, Remediation 15/15, Engine 13/13)
Failed: 0 in coupon scope. Skipped: 0 in coupon scope (4 skipped in prior broader run unrelated).
```

## 15. Database Changes

- NEW migration `2026_09_27_000001_normalize_coupon_codes_canonical.php` (backfill UPPER/TRIM, idempotent).
- No schema shape changes; constraints already verified (unique code, unique assignment, unique order reservation, claim idx).

## 16. API Changes

- NEW (admin, permission-gated): `GET/PUT/DELETE /api/v1/admin/coupons/{id}/targeting` (show/upsert/destroy, 401/403/404/422).
- CHANGED (non-breaking): `POST /api/v1/general/coupons/{id}/claim` + Marvel claim error payload narrowed from `['reason','context']` to `['reason']` (diagnostics moved to logs).
- UNCHANGED: all other coupon/checkout/payment routes, response shapes, status codes (except F-08 duplicate now 409 not 400, F-01 error path now 400/302-failed not 500).

## 17. Final Production Readiness

```text
NOT READY
```

Blockers remaining (non-coupon or needs ops):
- Full-suite sqlite signal broken (39 unrelated failures) — must fix `order_analytics_hourly` view before broad regression can gate release.
- No prod gateway verification (test gateway mocks only); live MyFatoorah/COD/cashier retry + reconciliation alerting must be proven in staging.
- Reconcile hourly will exit 1 on historical pre-enforcement rows; triage runbook + `--since` window needed before enabling paging.
- Coupon scope itself: READY (all F/N fixed, tests green, reconcile 0, routes verified).

---

# ARABIC SUMMARY (لصاحب المشروع)

### A. ماذا يفعل Admin؟
ينشئ Coupon (خصم، نوع، حد limiter) عبر `POST /api/v1/coupons`، ويدير Targeting (الاستهداف) عبر `PUT /api/v1/admin/coupons/{id}/targeting` (يحدد mode و require_claim و max_claims و claim_ttl_hours و rule_tree)، ويُسند المستخدمين عبر assignments، ويراقب usage-info و reconcile. الكتابة تتطلب صلاحية update-coupon (المشاهدة فقط لا تكفي).

### B. ماذا يفعل Customer؟
يكتشف الكوبونات (`GET /general/coupons` بدون كشف code)، ثم Claim (حجز أهلية)، ثم Apply (وضع code في السلة للمعاينة فقط)، ثم Checkout (إعادة تحقق)، ثم Payment (الحجز المؤقت)، ثم يكتمل Order مع Usage.

### C. ما معنى Claim؟
حجز أهلية قبل الاستخدام. الحالات: ACTIVE (صالح، يمنع التكرار)، REDEEMED (استُهلك، يمنع إعادة الحجز — إصلاح F-16)، EXPIRED (انتهت المدة، يسمح بحجز جديد ويحرر السعة).

### D. ما معنى Reservation؟
حجز مؤقت لسعة limiter أثناء الدفع (30 دقيقة). يمنع بيع نفس السعة مرتين: `used + active_reservations < limiter`. يُستهلك عند النجاح، ويُحرر عند الفشل/الإلغاء/الانتهاء.

### E. ما معنى Usage؟
الاستهلاك النهائي عند اكتمال الدفع (سجل في coupon_usages أو coupon_assignment_usages + زيادة counters). يحدث مرة واحدة فقط (Idempotency) ولا يُسترجع تلقائياً عند الإلغاء (مكافحة إساءة).

### F. كيف يعمل AND؟
يجب أن تنجح كل الشروط (مثال: طلبات>=3 AND إنفاق>=1000). أي شرط فاشل → غير مؤهل. يدعم التداخل (مجموعة داخل مجموعة).

### G. كيف يعمل OR؟
يكفي شرط واحد ناجح (مثال: لديه assignment OR إنفاق عالٍ). كل الفروع فاشلة → غير مؤهل.

### H. أي Rules موجودة فعلاً؟
13 قاعدة: min/max_completed_orders، min/max_total_spend، first/last_order_after/before، min/max_coupons_used، claimed/not_claimed، has_assignment. كلها تعمل مع AND/OR المتداخلة.

### I. أي Rules يمكنها جلب بياناتها فعلاً؟
كل الـ13: عدد الطلبات المكتملة والإنفاق من `orders` (مكتمل+مدفوع فقط) عبر `customer_metrics`، والـClaim من `coupon_claims`، والـAssignment من `coupon_assignments`. كلها مختبرة.

### J. أي Rules غير ممكنة حالياً؟
`payment_method` (طريقة الدفع التاريخية)، المنتجات/الفئات/البراندات المشتراة، الاسترجاعات/الملغاة، الدولة/المنطقة، Stripe/MyFatoorah specifics — الأسباب: لا مصدر canonical موثوق ولا سياسة عمل معتمدة. مكتوب NOT SUPPORTED في المصفوفة.

### K. ماذا تم إصلاحه؟
منع إعادة حجز REDEEMED، دعم AND/OR المتداخلة والأنماط المدمجة، واجهة Targeting للإدارة مع صلاحيات صحيحة، تحويل سباق assignment إلى 409، معالجة error-callback مثل النجاح (بدون 500)، منع null في PaymentSucceeded، إخفاء failed_rules عن العميل، التمييز بين خطأ العمل والبنية، جدولة reconcile كل ساعة، بحث code عبر index مع backfill، توثيق ترتيب الأقفال، وإصلاح صلاحية الكتابة.

### L. ماذا تبقى؟
نطاق الكوبون جاهز، لكن الإصدار الكلي NOT READY بسبب: إشارة الاختبارات الشاملة مكسورة (39 فشل unrelated)، ويلزم تحقق بوابة حقيقية في staging، وتهذيب تنبيهات reconcile التاريخية. لا عمل GraphQL (خارج النطاق).
