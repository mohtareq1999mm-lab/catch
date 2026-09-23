# Coupon Audience Refactor — Final Report

## Executive Summary
The coupon audience is now three independent capabilities (`is_public` × assignments × targeting) composed into 7 types by one authoritative resolver. A coupon can be publicly discoverable AND assigned: assignments never flip publicity. All legacy semantics preserved. `docs/coupons/COUPON_API_DOCUMENTATION.md` updated with the new responses.

## Before
"Public" was inferred as NOT(assignments) at 5 points (`isPublic()`, resolver, discovery policy, available-gate, catalog filters) → any assignment forced privacy. 4 audience states; PUBLIC+ASSIGNED unrepresentable.

## After
Persisted `coupons.is_public` (boolean, default false) + rows → `resolve()` → 7 types. Admin `audience{type,is_public,has_assignments,has_targeting}` + shaped `assignments[]` (+`remaining`, no PII/timestamps) + embedded `targeting`. Customer `visibility=public` for public+assigned; private assigned stays excluded/hidden. `is_public` settable via create/update (`sometimes|boolean`).

## Audience Model
```text
PUBLIC | ASSIGNED | TARGETED |
PUBLIC_AND_ASSIGNED | PUBLIC_AND_TARGETED |
ASSIGNED_AND_TARGETED | PUBLIC_AND_ASSIGNED_AND_TARGETED
```
(false,false,false) → PUBLIC (existing unconfigured-public rule kept).

## API Contract
Admin show/index: `audience` object (authoritative) + legacy `is_assigned`/`audience_type` (extended, old values keep meaning) + `targeting_mode` + `targeting` (object|null) + shaped assignments. Customer catalog/available/mine shapes unchanged; `mine` owner-scoped; catalog items carry no assignment PII. Full examples in `COUPON_API_DOCUMENTATION.md` §§1–4, 21–23.

## Targeting Contract
`targeting.mode` ∈ {assignment, dynamic, assignment_and_dynamic, assignment_or_dynamic} — evaluation only, never publicity. PUBLIC+ASSIGNED has `targeting: null`. Eligibility engine, claim, apply, checkout, consumption untouched.

## Legacy Compatibility
- `Coupon::isPublic()` ("no assignment rows"): PRESERVED (usage-info `coupon_type`, descriptions, validation; tests green).
- `visibility` names: PRESERVED; `public` widened per §10 (documented).
- `audience_type`: KEPT + extended (old 4 values identical for flag-off rows).
- `is_assigned`: KEPT (= has_assignments).
- Embedded assignments: reshaped (admin-only; documented; adds `remaining`, drops timestamps/PII object).

## Files Changed
| File | Change | Why | Risk |
|---|---|---|---|
| `database/migrations/2026_09_27_000002_*` | ADD `is_public` bool default false | B-vs-D indistinguishable without it (§8 proof) | SAFE/REVERSIBLE (rollback+migrate verified) |
| `Coupon` model | fillable + bool cast | write path + typed reads | LOW (whitelist-gated) |
| `CouponAudienceResolver` | `resolve()` + 7-type `composeType()`; legacy methods kept/extended | single source of truth | LOW (pure, tested) |
| Marvel `CouponResource` | `audience` object, `targeting` embed, shaped assignments, single resolve() call | §8 contract | LOW (admin-only, eager-loaded) |
| Marvel `CouponController` | eager selects extended (quota/timestamp cols) | shaped rows without N+1 | LOW |
| `CouponDiscoveryPolicy` | visibility via flag (assignments never demote) | §10 | MEDIUM → covered by 44 discovery assertions |
| `CouponService::getCoupons`, `AvailableCouponsService` | inclusion `OR is_public` | catalog correctness | same cover |
| `SendUserCouponAvailableNotification`, `DetectPublicCouponsCommand` | flag-aware gate (legacy no-assignments leg kept) | fan-out correctness | LOW (sweep tests) |
| `CouponRequest`, `UpdateCouponRequest`, `CouponRepository::$dataArray` | `is_public sometimes\|boolean` + whitelist | admin write path | LOW |
| 2 service docblocks | wording aligned to new model | accuracy | NONE |

NOT changed: `isPublic()`, modes/engine/grammar, claim/apply/checkout/consumption, TTLs, outbox/RabbitMQ, Pusher/FCM/payloads, channels, queues, max_uses, usage-info logic.

## Tests
- NEW `CouponAudienceMatrixTest`: 12/12 PASS (7-case matrix, admin object+shape, index N+1=1 query, catalog inclusion+no-leak, private exclusion, mine scoping, usage-info legacy, assignment/targeting independence, cache refresh, 403).
- Existing: AudienceApi 4/4, Audience 13/13, Config 8/8, GeneralDiscovery 18/18, AuthContext 5/5, AvailableCoupons 13/13, coupon dir 124 passed (1 pre-existing CLAIMED-expired failure, zero references to touched files; 3 skipped).
- Decisions (no user input needed — only backward-compatible option): new-row default false; no backfill update (column default preserves every existing row); write path create+update.

## Full Combination Matrix — all 7 verified in `resolve_covers_all_seven_combinations` ✓

## Security Verification
Catalog items assert no `user_id/assignment_id/max_uses/used/expires_at`; mine asserts cross-user emptiness + owner codes; admin show asserts 403 without permission and no user-PII object in embedded rows; Sanctum permissions unchanged; targeting rules never in customer payloads.

## Performance Verification
Admin index: `coupon_assignments` queried exactly once for 3 coupons (eager `with`, test-enforced). Resolver reuses loaded relations (zero extra queries when eager). No new per-row queries (single `resolve()` call per row).

## Cache Verification
Coupon update (incl. `is_public`) invalidates discovery cache via observer + `forget(COUPONS)`; test `coupon_update_refreshes_cached_audience` proves same-URL index reflects the flip. Assignment/targeting writes invalidate as before.

## Final Status
```text
PASS
```
