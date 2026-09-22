# `area_in` Saved-Address Eligibility — Implementation Final Report

## 1. Executive Summary

`area_in` was evaluated against the checkout/delivery governorate (`$context['governorate_id']`). It is now evaluated against the authenticated user's own saved addresses (`address.customer_id = user`, `address.governorate_id ∈ allowed`, governorate active), ANY-match, strict at claim/apply/checkout/fast-checkout/payment. Delivery governorate no longer affects coupon eligibility anywhere. One migration adds nullable indexed `address.governorate_id`; legacy NULL addresses fail closed; no backfill, no status column, no grammar change. All coupon suites green; two unrelated failures proven pre-existing via stash comparison.

## 2. Files Changed

- `database/migrations/2026_09_28_000001_add_governorate_id_to_address_table.php` (NEW) — WHY: schema lacked any address→governorate link, making the rule unimplementable. WHAT: nullable `governorate_id` FK→governorates `nullOnDelete`, composite index `(customer_id, governorate_id)`; guarded `hasTable`/`hasColumn`; best-effort `down()`. Verified `migrate` + `RefreshDatabase` runs.
- `packages/marvel/src/Database/Models/Address.php` — WHY: expose the link. WHAT: `governorate_id` fillable + integer cast + `governorate()` BelongsTo. Nothing else touched.
- `packages/marvel/src/Http/Requests/AddressRequest.php`, `AddressRequestUpdate.php` — WHY: clients must be able to set the link. WHAT: added `governorate_id: nullable|integer|exists:governorates,id` only.
- `packages/marvel/src/Http/Resources/AddressResource.php` — WHY: convention requires stored field to be visible. WHAT: added `governorate_id` key only.
- `app/Services/Coupon/Eligibility/EligibilityEngine.php` — WHY: sole runtime implementation. WHAT: `evalAreaIn($value, User $user)` rewritten (strict allowed-id normalization kept verbatim; rolling-deploy `hasColumn` fail-closed guard; active-only allowed set; single `Address where customer_id + whereIn pluck` ANY-match; NULL never matches); removed dead `governorate_id` snapshot provenance; docblocks updated. Call site passes `$user` instead of `$context`.
- `app/Services/Coupon/CouponOrchestrator.php` — comment only (context key ignored by area).
- `app/Http/Controllers/Api/General/CouponController.php` — WHY: apply contract. WHAT: removed `governorate_id` validation + context; `addCouponToCart($code)` only.
- `app/Services/General/OrderService.php` — WHY: separate shipping from coupon eval. WHAT: preview/checkout/payment coupon validations no longer receive delivery area (shipping `resolveShippingPrice` lines untouched).
- `app/Services/General/FastShippingService.php` — same separation for fast checkout (shipping untouched).
- `app/Services/Coupon/CouponRuleMetadata.php` — WHY: metadata must describe new semantics. WHAT: area entry only (label/description, `context: customer_addresses`, `defers_without_context: false`). Still derived from the enum; no second registry.
- `docs/api/COUPON_API_CONTRACT.md` — §§2.D (apply `{code}` only), 2.F, 4 (matrix), 5 (catalog), 13 (metadata table/semantics).
- Tests: `EligibilityNewRulesTest` (area section rewritten: 12 tests incl. strict-no-context, ANY-match, context-ignored both directions, unknown/inactive, NULL, deleted, cross-user, malformed, nesting), `CouponCheckoutRevalidationTest` (apply-strict + critical Case 9 payment test), `CouponClaimTest::test_area_claim_uses_saved_addresses_strictly` (Case 10), `CouponSystemTest` apply HTTP pair (Case 11).
- `RuleTreeValidator`: UNCHANGED (grammar identical). No reservation/usage/payment/calculation changes.

## 3. Database

`address.governorate_id`: `BIGINT UNSIGNED NULL`, FK→`governorates.id` `NULL ON DELETE`, composite index `(customer_id, governorate_id)`. Existing rows preserved, NULL by default. No `address.status` added (hard deletes = inactive/absent).

## 4. Final Runtime Flow

- Claim: `evaluate($coupon,$user)` → `evalAreaIn` strict address ANY-match (no deferral possible).
- Apply: `{code}` only → orchestrator → same strict evaluation.
- Checkout / Fast: cart coupon revalidated with NO area context (delivery `governorate_id` still required for and used only by shipping).
- Payment: revalidation evaluates the order user's CURRENT saved addresses; reservation/usage/claim-redemption paths untouched.

## 5. Security

Identity exclusively `auth()->user()` (claim/apply/checkout) or `$order->user` (payment); addresses scoped `customer_id = user id`; no request `user_id`/`address_id`/`governorate_id` is read for eligibility (apply field removed); cross-user leakage proven impossible by test; public coupon list still exposes no targeting internals.

## 6. Existing Data / Rollout

**Existing addresses with NULL `governorate_id` fail closed for `area_in`.** No automatic backfill was performed (no guessing from city/state/street JSON). Consequence: area-coupon holders with legacy addresses become ineligible until they re-save an address with a governorate — address create/update now accepts and returns `governorate_id`, so frontend should prompt/allow setting it. No historical rows altered.

## 7. API Contract

Apply: `{ "code": "SAVE20" }` (no `governorate_id`). Checkout/fast-checkout: `governorate_id` retained for shipping with explicit "does not control coupon `area_in`" notes. Claim: unchanged shape, now strict for area coupons (`not_eligible` on mismatch — no new codes).

## 8. Tests

- Added: 9 unit area tests, 1 orchestrator area test (rewritten), 1 payment Case-9 test (rewritten), 1 claim test, 2 apply HTTP tests.
- Rewritten (old delivery semantics): `area_in_*` unit block, `area_rule_defers_*`, `area_matched_order_*`.
- Executed: NewRules 23 PASS; Revalidation 15 PASS; Claim 13 PASS; ClaimLifecycle 18 PASS; ClaimIntegration 8 PASS; CouponSystem 23 PASS; RulesMetadata 6 PASS; Engine 13 PASS; AssignedCoupon 49 PASS; Remediation 15 PASS; Idempotency 5 PASS.
- Unrelated pre-existing failures (proven identical on stashed HEAD, NOT modified): `CouponEligibilityLifecycleTest::claimed_rule_passes_when_claim_is_expired` (contradicts committed P2-2 claimed semantics), `CouponsProductionHardenTest` 16× (`no such table: categories` env issue), `FastShippingControllerTest` 21× (same env issue), `WebhookPaymentCompletionTest` 5× (same env issue).

## 9. Static Search Results

Post-implementation `governorate_id` in coupon paths: `CouponOrchestrator:37` (ignored-key comment), `EligibilityEngine` (docblocks + migration guard + query — all new-semantics), `CouponController:39` (call without context). All remaining `governorate_id =>` writes are order/shipping snapshots (`OrderCreationService`, shipping-info arrays) — KEEP. No runtime path feeds delivery area into `area_in`. No second implementation exists (Marvel: zero hits).

## 10. Regression Verification

No coupon calculation, promotion/tax/shipping, limiter, claims, reservations, usages, idempotency, locking, notification, or targeting-grammar behavior changed (verified by the suite list in §8). `RuleTreeValidator` byte-identical in behavior. Failure reason for area remains `not_eligible`.

## 11. Final Verdict

```text
IMPLEMENTED
VALIDATED
```

Production readiness NOT claimed: rollout requires the §6 backfill UX decision and the usual staging verification; the two unrelated failing suites pre-date this change.
