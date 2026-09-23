# Coupon Admin List — Filter Discovery Report (read-only, 2026-09-26)

## 1. Current implementation
`Marvel CouponController@index(Request)` → `fetchCoupons()` → paginate → `CouponResource` → `remember(COUPONS, md5(fullUrl))` → envelope. Sanctum + `throttle:admin` + `view-coupons`. NO validation (plain Request).

## 2. Current filters
`limit` (default 15, unclamped), `active` → `scopeValid`, `inactive` → `scopeInvalid`, `search` → name(locale JSON+raw) OR code (UNGROUPED orWhere), `order` (12-field whitelist), `sortedBy` (asc default, anything-else→asc).

## 3–4. Sorting/search
Whitelist: id,code,name,discount,discount_type,start_date,end_date,limiter,used,status,created_at,updated_at. Search covers name translations + code; no raw SQL (query builder bindings).

## 5. Cache
Key `md5(fullUrl)` → distinct per filter combo (no collisions). Invalidation: observer create/update/delete + assignment/targeting writes → `CouponDiscoveryCache::invalidate()` → tag flush (real mechanism; controller `forget()` calls are literal-key no-ops — harmless, not redesigned).

## 6. Relationships
Eager `assignments(full shaped cols)` + `targeting` (2 queries, no N+1); resolver reuses loaded relations; `is_valid` is PHP-side, query-free.

## 7. Indexes (live `coupons` table, 17 cols)
PK id, UNIQUE code. NO secondary indexes (status/dates/discount unindexed — table 0.03MiB; no new indexes proposed). `coupon_assignments` UNIQUE(coupon_id,user_id) (leftmost prefix serves exists(coupon_id)); `coupon_targetings` UNIQUE(coupon_id) + INDEX(require_claim).

## 8. Existing tests
Only `?limit=50` usages (my own matrix/api tests). No filter-behavior tests exist.

## 9–10. Candidate classification
IMPLEMENT: is_valid (scopes, exact mirror), start/end_date_from/to, date_from/to overlap (start<=to AND end>=from, nulls open), discount_type (fixed_rate|percentage), discount_min/max, max_discount_amount_min/max (non-null rows), limiter/used_min/max, remaining_min/max (null limiter = +∞, documented), expired (end_date past, narrow), audience_type (7, SQL-mapped), is_public/has_assignments/has_targeting (AND), targeting_mode (4), assigned_user_id (admin-authorized exists-subquery), require_claim (targeting row flag).
NOT IMPLEMENT: status (dup of active/inactive), currently_active/not_expired/starting_soon (use is_valid/expired), max_claims/claim_ttl_hours (nested minutiae), rule_tree (forbidden), assignment_expires_*/assigned_at (per-row scope; dedicated endpoints exist), audience sorting (PHP-computed), new indexes (unneeded scale).
CHANGED (documented): search grouped (fixes AND-precedence bug: today `valid AND name OR code` lets code matches bypass validity); invalid order/sortedBy → 422 (was silent ignore); limit clamped 1..100 (was unbounded); malformed numerics/dates → 422 (were ignored/500); `scopeInvalid` wrapped in a where-group (top-level ORs would otherwise escape AND-combination).

## 11. Performance risks
exists-subqueries per filter (indexed prefixes ✓); no per-row queries added; paginate preserved; limit clamp kills full-table serialization; cache keyed per URL; invalidation intact.

## 12. Final proposed filter contract
All §17 AND-combined. New `CouponIndexRequest` (Marvel 422 shape) + `AdminCouponFilter::apply()` (single home; audience mapping mirrors resolver booleans 1:1 — equivalence test required). Controller signature `index(CouponIndexRequest)`; `fetchCoupons` delegates.
