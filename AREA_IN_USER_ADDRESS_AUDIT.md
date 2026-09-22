# `area_in` — User Saved Address Eligibility Audit (READ-ONLY DISCOVERY)

Status: **DISCOVERY ONLY — no code, migration, route, or contract changes made.**
Authoritative rule under audit: `area_in` = coupon available iff the authenticated user has ≥1 ACTIVE saved address whose governorate ∈ coupon's allowed governorates (ANY-match; shipping/delivery irrelevant).

## 1. Executive Summary

- Current implementation evaluates `area_in` against the **checkout/delivery governorate** (`$context['governorate_id']` → `orders.governorate_id`), NOT saved addresses. Single implementation point, fully tested (19 area tests) and documented (contract §§2.D/4/5/13, rules-metadata endpoint).
- **The saved-address data required by the new rule does not exist in the schema**: `address` table has NO `governorate_id`, NO status/active flag, NO soft deletes (hard delete only). Address location is free-form JSON (`zip/city/state/country/street_address` strings). There is no reliable address→governorate mapping today.
- Consequently the new rule **cannot be implemented without a migration** (add `governorate_id` to `address`, plus a backfill/default strategy), and "inactive/soft-deleted" address states from the test matrix have no schema representation (would need a status column or reinterpretation).
- Adopting the rule INVERTS current behavior at checkout (Case 9: delivery mismatch no longer strips the coupon) and tightens claim (area evaluated strictly at claim instead of deferred pass). Existing area tests + contract sections + rules-metadata entry must be rewritten, not extended.

## 2. Authoritative Business Rule (as given)

`Authenticated User → active saved addresses → their governorates → ANY ∈ coupon.area_in → eligible; else not eligible.` Shipping destination, checkout `governorate_id`, fulfillment type, and pickup location are irrelevant. `auth()->user()` only — never request-supplied `user_id`.

## 3. Current Architecture (real classes)

```text
POST general/coupons/apply ──CouponController:36-42──┐ (governorate_id? → context)
POST general/checkout ──OrderService:156-157 (preview) / :221 (strict)──┤
POST fast-shipping/checkout ──FastShippingService:107 (fastContext)─────┤
     └→ CouponOrchestrator::validate($coupon,$user,$items,$context) (:97)
           └→ EligibilityEngine::evaluate($coupon,$user,$context) (:38)
                 └→ evaluateNode → evaluateRule (:311 AREA_IN arm)
                       └→ evalAreaIn($value,$context) (:705) — SOLE implementation
Claim: CouponClaimService:119 → evaluate($coupon,$user) — NO context (defers)
Payment: OrderService:1204 → ['governorate_id' => $order->governorate_id]
Validator: RuleTreeValidator:126-139 (value grammar only, no user/data access)
Storage: Marvel CouponTargeting (rule_tree JSON); Marvel has ZERO area logic
```

## 4. Current Runtime Flow (`evalAreaIn`, EligibilityEngine:705-780)

1. Normalize rule value → strict positive ints (reject floats/`'1.5'`/≤0/bool/null; dedupe; empty → fail).
2. `governorate_id` ABSENT from context (claim, apply-without-input) → **PASS** (`deferred…enforced at checkout`).
3. PRESENT (checkout/fast/payment, may be null) → null/non-int → fail; must exist in `governorates` with `status=true`; `in_array(strict)` decides.

## 5. Current `area_in` Behavior vs Required Behavior

| Stage | Today | Required |
|---|---|---|
| Claim | deferred PASS (anyone can claim) | strict address ANY-match |
| Apply (no governorate) | deferred PASS | strict address ANY-match, no input needed |
| Apply (`governorate_id`) | strict vs supplied id | input must become irrelevant to coupon eval |
| Checkout | strict vs delivery governorate; mismatch strips coupon | address ANY-match; delivery irrelevant (Case 9 inverts this) |
| Fast checkout | same as checkout | same as checkout (address rule) |
| Payment revalidation | strict vs `order.governorate_id` | address ANY-match for order user |

## 6. Address Schema / Relationship (verified, no assumptions)

- Table `address` (singular) — `packages/marvel/database/migrations/2020_06_02_051901_create_marvel_tables.php:371-379`: `id, title, address JSON, location JSON nullable, customer_id FK→users, timestamps`. **No `governorate_id`, no status/active, no soft deletes, no `default` column** (model fillable lists `default` but schema lacks it — dormant, never passed by `AddressRequest`).
- Address payload (`AddressRequest:29-40`): `title*, address* {zip*, city*, state*, country*, street_address*}`, `location {latitude, longitude}` optional. All location fields are **free-form strings** — no governorate linkage.
- Relation: `User::address()` HasMany via `customer_id` (User.php:146-149; singular name, HasMany).
- Ownership: index/show/update/destroy all scoped `customer_id = auth user` (AddressController:63,117,148,177). Delete = hard delete (no SoftDeletes trait on model).
- **"Active" address, in-schema terms, can only mean "row exists".** Inactive/disabled/soft-deleted states do not exist.

## 7. Governorate Source (verified)

`governorates` table via `Governorate` model: `country_id, name (translatable), status bool, is_fast_shipping_enabled`; `scopeActive()` = `status=true`. Canonical for shipping/checkout; `evalAreaIn` already requires `status=true` and rejects unknown ids. Reuse as-is for the allowed-list side.

## 8. Multiple Address Semantics (required change)

ANY-match over the user's active addresses. Efficient form (no object hydration): `Address::where('customer_id',$userId)->whereIn('governorate_id',$allowed)->exists()` — possible only AFTER the migration adds the column. `whereHas` from User equally fine.

## 9–13. Per-Flow Impact (claim / apply / checkout / fast / payment)

- Claim: switch from no-context deferral to strict address evaluation (behavior tightening).
- Apply: `governorate_id` request field (CouponController:36 + :41-42) exists ONLY for coupon area; remove from coupon path. Keep checkout/fast-checkout `governorate_id` for shipping/order creation (OrderCreateRequest:58, FastCheckoutRequest:25, `resolveShippingPrice`, `FastShippingRepository::validateCheckout`).
- Checkout/fast: stop feeding delivery area into `CouponOrchestrator` context for coupon purposes; keep `order.governorate_id` snapshot for shipping.
- Payment (`revalidateAndReacquireReservation` + OrderService:1204): switch context source to order-user addresses; preserve reservation/usage architecture untouched.
- Error reason stays `not_eligible` (existing catalog; no new codes).

## 14. Legacy vs Modern Ownership

- Storage: Marvel (`coupons`, `coupon_targetings`, `addresses`, `governorates`). No legacy area evaluation exists (zero `area_in` hits in `packages/`).
- Runtime authority: `app/` (`EligibilityEngine::evalAreaIn` — the ONLY implementation). One rewrite point; no competing implementation to reconcile; nothing legacy to delete.

## 15. Security Analysis

- New rule is inherently safe: source = `auth()->user()->address()` scoped by `customer_id`; coupon list from admin-owned targeting. Request `user_id/governorate_id/address_id` become unused for eligibility — nothing to trust.
- Current state also has no impersonation hole (apply `governorate_id` is `exists`-validated and only affects the caller's own evaluation), but it IS manipulable within one's own evaluation (user can pass any governorate id at apply) — the new rule closes even that.

## 16. Performance Analysis

Single `exists()` query with `whereIn` on indexed `customer_id` (+ index on new `governorate_id` recommended). Cheaper than today's extra `Governorate::exists()` + checkout-time revalidation. No caching needed (data changes with user addresses; query is trivial).

## 17. API Contract Changes (pending approval)

- `POST general/coupons/apply`: drop `governorate_id` from coupon docs (keep field ONLY if checkout-shipping reuse is desired — recommend removal from apply entirely since checkout collects it separately).
- Contract §§2.D/4/5/13 + `CouponRuleMetadata` area entry (`context: checkout` → `customer_profile`-adjacent new value e.g. `customer_addresses`; `defers_without_context: false`; description rewrite).
- Frontend: sends `{code}` only; `COUPON_NO_LONGER_ELIGIBLE`-on-checkout-strip no longer occurs for area reasons.

## 18. Required Code Changes (pending approval)

1. **Migration (REQUIRED)**: `address.governorate_id` nullable FK→governorates (+ index). Optional: status column if true inactive-state semantics wanted; otherwise define active=exists. Backfill strategy required (existing rows have no governorate → fail-closed ineligible until re-saved).
2. `AddressRequest`/`AddressRequestUpdate`: accept optional `governorate_id exists:governorates`; `AddressResource`: expose it.
3. `EligibilityEngine::evalAreaIn`: rewrite to address ANY-match (`$user` addresses vs allowed; inactive-governorate filtering on allowed side preserved); drop `$context` usage for this rule (keep signature).
4. `CouponController@applyCoupon`: remove `governorate_id` validation/context for coupon path.
5. `OrderService` (:156-157, :221), `FastShippingService` (:107): stop passing delivery area into coupon context (keep for shipping).
6. `OrderService:1204` payment context: address-based (or drop area context entirely since evaluation no longer reads it).
7. `CouponRuleMetadata`: area entry update; contract §§2.D/4/5/13 rewrite.
8. `RuleTreeValidator`: NO change (value grammar identical).
9. Tests: rewrite `EligibilityNewRulesTest` area cases + `CouponCheckoutRevalidationTest` area cases + metadata sample; add §21 matrix Cases 1–12.

## 19. Database Changes

One migration (see §18.1). No other schema change needed. **Rollout risk**: all pre-existing addresses lack governorates → area-coupon holders become ineligible until addresses are re-saved with a governorate. Requires backfill UX decision (e.g. prompt users to update address) before enabling area coupons in production.

## 20. Test Matrix (to implement post-approval)

Cases 1–12 per spec §21, with two schema-dependent notes: Cases 6/7 (inactive / soft-deleted) need the §18 status-column decision first (hard-delete today = Case 8 equivalent); Case 12 must assert `user_id`/`governorate_id` inputs are ignored (apply contract drops the field; orchestrator/engine never read request ids).

## 21. Implementation Results

PENDING APPROVAL — nothing implemented.

## 22. Independent Final Validation

PENDING APPROVAL — to verify post-implementation: address-sourced evaluation at all 5 stages, ANY-match, delivery irrelevance (Case 9), no customer-id trust, fail-closed malformed, no migration beyond §18.1, docs match code.

## 23. Remaining Risks

1. Backfill gap (§19) — biggest rollout risk; needs product decision.
2. Address UX must now capture governorate (admin/city data exists to populate selector).
3. Behavior inversions (claim tightening, Case 9) need frontend awareness (fewer checkout strips, more claim rejections).
4. If product rejects the migration, the rule is NOT implementable without free-form JSON parsing (fragile, forbidden by §5) — would require re-scoping the business rule.
