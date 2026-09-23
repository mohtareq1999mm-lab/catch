# Coupon Audience & Targeting (authoritative)

## Three independent capabilities (single source: `CouponAudienceResolver::resolve()`)
- `is_public`: persisted `coupons.is_public` flag (default false). Public discoverability. NEVER derived from assignments — an assignment must not remove publicity.
- `has_assignments`: ≥1 `coupon_assignments` row (quotas: max_uses/used/expiry).
- `has_targeting`: a `coupon_targetings` row exists (any mode).

## Composite `audience.type` (7 values)
```text
PUBLIC | ASSIGNED | TARGETED |
PUBLIC_AND_ASSIGNED | PUBLIC_AND_TARGETED |
ASSIGNED_AND_TARGETED | PUBLIC_AND_ASSIGNED_AND_TARGETED
```
No capabilities at all → `PUBLIC` (existing rule: unconfigured coupons are publicly discoverable). Legacy `audience_type` carries the same label (pre-flag values keep exact meaning); `audience` object `{type,is_public,has_assignments,has_targeting}` is authoritative. `targeting_mode` stays pure eligibility mode (never publicity).

## Customer `visibility` (`public|targeted|assignment-only`)
Targeting row ⇒ `targeted` (unchanged). Otherwise `public` when explicitly public OR no assignments; `assignment-only` only for private assigned coupons (excluded from catalog/available, visible in `mine`). Catalog/available include `whereHas(targeting) OR no-assignments OR is_public`.

## Legacy compatibility (frozen)
`Coupon::isPublic()` ("no assignment rows") → `usage-info coupon_type` unchanged. `assignment.max_uses` overrides per-user; `limiter` stays global. No `coupons.mode` column.

## Union & dedup
Fan-out candidates = rule ranges ∪ assigned customers (resolver primitive, SQL subquery); recipients `unique(run,user)` + states `unique(coupon,user)` → one notification per version.

## Delay
Trigger runs wait the authoritative business delay (`distribution_delay_seconds` = 240s / 4min) via outbox `available_at` + minutely sweep; consumer reloads fresh state + LiveCheck + drift-abort. Grant notifications stay immediate; manual runs immediate. Public maturity grace = 4min (`public_grace_minutes`).
