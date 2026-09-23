# Coupon API Verification Report

## Executive Summary: PASS WITH KNOWN NON-BLOCKING ISSUES
33 routes inventoried from `route:list`; 22 endpoints HTTP-exercised on live MySQL; audience_type exposure implemented + proven; full REST E2E re-proven; pre-existing failures isolated with clean-tree evidence.

## Architecture / Counts
REST 33 (admin 20, customer 5, checkout/payment 5, dashboard 1, broadcast-auth, GraphQL broken-separate). Internal-only: reservation, outbox/sweep, consumers, transitions, reconcile. Audience: resolver + trichotomy. Targeting: 4 modes, 17 rules. Notifications: 4 types. Pusher: broadcaster path. Payment: online/COD/cashier + callbacks. Consumption: dual-path exactly-once.

## Tests: executed 30+ (audience API 4/4 new green; audience engine 13/13; config 8/8; outbox/pipeline/assignment suites green)
Failed (all pre-existing, clean-tree identical): CLAIMED-expired 1, alias-route 404s 8, notification-env 18, float 1. Blocked: live gateway, runtime-429, fan-out client receipt. Existing issues: GraphQL, WMS, email-nullability, alias gap, concurrent dirt — all separated, untouched.

## Changes Made (minimal, tested)
1. `CouponAudienceResolver::audienceType()` (new method, uppercase contract).
2. Marvel `CouponResource`: `audience_type` + `targeting_mode` (computed via resolver; no logic moved).
3. Admin `index`/`show`: eager-load assignments+targeting (read-only optimization).
4. `tests/Feature/Coupon/CouponAudienceApiTest.php` (new, 4/4 green).
Why: admin could not see audience state (§13 requirement); no DB/logic changes; customer `visibility` already existed.
Evidence: unit 4-states; HTTP show/index over sqlite + MySQL live (`ASSIGNED_AND_TARGETED`/`assignment_and_dynamic` observed); suites green.

## Files Changed
`app/Services/Coupon/Audience/CouponAudienceResolver.php`, `packages/marvel/src/Http/Resources/CouponResource.php`, `packages/marvel/src/Http/Controllers/CouponController.php`, `tests/Feature/Coupon/CouponAudienceApiTest.php` (+ 8 required docs incl. this one).

## Final Status: PASS WITH KNOWN NON-BLOCKING ISSUES

---

## Re-verification (this session, 2026-09-26, sqlite :memory:)
- `CouponAudienceApiTest`: 4/4 PASS — audience_type + targeting_mode on admin show/index.
- `CouponAudienceTest`: 13/13 PASS.
- `CouponRulesMetadataTest`: 6/6 PASS.
- `CouponClaimTest`: 13/13 PASS.
- `CouponEligibilityLifecycleTest`: 15/16 — 1 failure (`claimed rule passes when claim is expired`, line 325) is PRE-EXISTING: the test calls `EligibilityEngine::evaluate` directly with zero references to any file changed by this work (verified by search), matching the prior clean-tree-identical record. Engine CLAIMED-rule semantics vs test expectation = product decision, left untouched (no security weakening to force green).
- Source-tracing corrections applied to MASTER: envelope `{status,message,success,data?}`, assignment-update floor-breach 422 (not 409), show-by-id-or-code, required create images, `requires_claim` in assigned payload.
- New artifacts: `COUPON_API_DISCOVERY_REPORT.md` (root, 33 routes from live `route:list -v`), `docs/coupons/COUPON_ENDPOINT_CONTRACT.md` (32 endpoints × 23-section template), `docs/coupons/COUPON_API_DOCUMENTATION.md` (user-style Request+Response+Errors+next-step docs, 32 sections, all shapes source-traced this session), notification pipeline + RabbitMQ≠Queue≠Pusher extension, SAVE20/Ahmed E2E expansion.
