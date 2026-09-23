# Coupon Audience — Discovery Report (read-only, 2026-09-26)

## 1. Current implementation
- `CouponAudienceResolver` (`app/Services/Coupon/Audience/`): `sources()` (assignments-exists + targeting-exists) → `describe()` + `audienceType()` (PUBLIC|ASSIGNED|TARGETED|ASSIGNED_AND_TARGETED). Computed only.
- `Coupon::isPublic()` (`packages/.../Models/Coupon.php:255`): `!assignments()->exists()`. Used by `usage-info` (coupon_type), descriptions, config validation. Frozen + tested.
- `CouponDiscoveryPolicy::decide()`: visibility = targeted (row exists) > assignment-only (rows, no targeting) > public (neither). Drives catalog + available + codes.
- Catalog (`CouponService::getCoupons`) and `AvailableCouponsService`: `whereHas('targeting')->orWhereDoesntHave('assignments')` — assignment-only rows excluded for everyone.
- Admin `CouponResource`: `is_assigned`, `audience_type` (4 values), `targeting_mode`, raw embedded assignment rows.
- Available fan-out gate (`sendIfMaturePublic`): assignments-exists → refuse; targeting → refuse; grace 4min.

## 2–5. Current derivations
- Audience: assignments-exists × targeting-exists (4 combos).
- Public: NO independent concept — "public" is inferred as NOT(assignments) at 5 points: `isPublic()`, resolver, policy, available-gate, catalog filters. **This inference is exactly what forbids Public+Assigned.**
- Assignment: `coupon_assignments` rows (max_uses/used/expiry).
- Targeting: `coupon_targetings` row + mode (4 modes, engine-evaluated).

## 6. Affected endpoints
Admin CRUD (5), assignments (5), targeting (3), config (4), distribution (3), customer catalog/available/mine/claim/apply (5), usage-info, checkout chain (read-only here).

## 7. Affected resources
`CouponResource` (admin), `CustomerCouponResource` (visibility), `CouponTargetingResource` (unchanged), `CouponAssignmentResource` (unchanged), `CouponClaimResource` (unchanged).

## 8. Affected tests
`CouponAudienceApiTest`, `CouponAudienceTest`, `CouponConfigurationTest` (isPublic asserts — must keep passing), `CouponGeneralDiscoveryTest` (Case 7 assignment-only excluded — stays valid ONLY for is_public=false rows), `CouponDiscoveryAuthContextTest`, `AvailableCouponsApiTest`.

## 9. Legacy compatibility risks
- `isPublic()` + `coupon_type`: KEEP untouched (frozen, tested, documented as legacy).
- `visibility` values (`public|targeted|assignment-only`): KEEP names; `public` must now include public+assigned rows.
- `audience_type` (4 values, 1 session old, additive): EXTEND with new composite values + document mapping; `audience.type` becomes authoritative.
- Embedded assignment rows: extend to §8 shape (adds `remaining`, drops internal timestamps) — admin-only, documented.
- `targeting_mode`: unchanged, never carries public semantics.

## 10. Files that need changes
Migration (new `coupons.is_public`), `Coupon` (fillable+cast), `CouponAudienceResolver` (7-type composition), `CouponResource` (audience object + assignment shape), `CouponDiscoveryPolicy` (visibility via flag), `CouponService::getCoupons` + `AvailableCouponsService` (inclusion filters), `CouponRequest`/`UpdateCouponRequest` (`is_public` rule), `CouponRepository::$dataArray`, `sendIfMaturePublic` + `DetectPublic` (flag gate), tests (matrix + updates).

## 11. Files that must NOT change
`isPublic()`, targeting modes/engine/grammar, claim/apply/checkout/consumption, reservation/claim TTLs, outbox/RabbitMQ, Pusher/FCM/payloads, channels, queue config, `max_uses`, eligibility semantics, notification triggers.

## 12. Proposed architecture + schema necessity proof
`is_public` (boolean, persisted) × `has_assignments` (rows) × `has_targeting` (row) → 7-type composition in the resolver (single source); admin `audience{type,is_public,has_assignments,has_targeting}`; customer `visibility=public` whenever `is_public` (assignments never demote).
**Schema-change proof (§8): Case B (ASSIGNED, private) vs Case D (PUBLIC_AND_ASSIGNED) are identical in all existing rows/columns — no derivation can separate them. A persisted flag is REQUIRED. No other schema change is needed.**
Open business decisions (asked separately): new-row default, existing-row backfill, admin write path.
