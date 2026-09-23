# Coupon Implementation Changes (this task only)

## 1. `audienceType()` — `app/Services/Coupon/Audience/CouponAudienceResolver.php`
New 12-line method mapping `sources()` to `PUBLIC|ASSIGNED|TARGETED|ASSIGNED_AND_TARGETED`. Why: API contract needs uppercase audience state; resolver is the single home. Safe: pure read, no callers affected (`describe()` unchanged, existing tests green).

## 2. `audience_type` + `targeting_mode` — `packages/marvel/src/Http/Resources/CouponResource.php`
Two computed keys via resolver + targeting relation (relation-aware, mirrors existing `is_assigned` pattern). Why: admin visibility gap. Safe: presentation only; no decisions moved (rule §32 preserved).

## 3. Eager loads — `packages/marvel/src/Http/Controllers/CouponController.php#index|show`
`with/loadMissing(['assignments:id,coupon_id,user_id','targeting'])`. Why: avoid per-row queries for the new keys. Safe: read-only; `fetchCoupons` shared with GraphQL untouched.

## NOT changed (deliberately)
No `coupons.mode`, no `is_public` column, no `isPublic()` change, no engine/orchestrator/claim/reservation/consumption/outbox/notify/Pusher/money changes, no unrelated fixes.
