# Coupon Admin List — Filter Final Report

## 1. Final Status
```text
PASS
```

## 2. Final Filter Table
| Parameter | Type | Allowed | Meaning | Status |
|---|---|---|---|---|
| limit | int 1..100, default 15 | — | page size (was unbounded) | CHANGED (clamped) |
| active/inactive | boolean | — | valid()/invalid() scopes (presence flags preserved) | EXISTING |
| search | string ≤191 | — | name translations + code, grouped OR | EXISTING (grouping fixed) |
| order/sortedBy | enum | 12 fields / asc,desc | strict whitelist | EXISTING (422 now, was silent ignore) |
| is_valid | boolean | — | scopeValid / grouped scopeInvalid (exact `is_valid` mirror) | NEW |
| start/end_date_from/to | Y-m-d | — | field bounds (nulls never match) | NEW |
| date_from/to | Y-m-d | — | overlap: start≤to AND end≥from, nulls open | NEW |
| discount_type | enum | fixed_rate,percentage | persisted values | NEW |
| discount_min/max, max_discount_amount_min/max | numeric ≥0 | min≤max enforced | ranges; cap filters skip nulls | NEW |
| limiter/used_min/max | int ≥0 | min≤max enforced | ranges | NEW |
| remaining_min/max | int ≥0 | — | limiter−used; null limiter = +∞ (passes min, never max) | NEW |
| expired | boolean | — | narrow: end_date past (complement: null or future) | NEW |
| audience_type | enum | 7 states | SQL mirror of resolver booleans | NEW |
| is_public/has_assignments/has_targeting | boolean | — | AND-combined capabilities | NEW |
| targeting_mode | enum | 4 modes | eligibility mode only | NEW |
| assigned_user_id | int ≥1 | — | admin exists-subquery (no PII output) | NEW |
| require_claim | boolean | — | targeting-row flag | NEW |

## 3. Sorting
Whitelist unchanged (12 fields); direction strict; `orderBy($order)` only after `in_array` + FormRequest enum (no injection path).

## 4. Search
Name (locale JSON + raw fallback) + code LIKE, grouped in one closure; bindings only.

## 5. Audience
`audience_type` → 3 boolean constraints via AUDIENCE_MAP (1:1 with `composeType`, equivalence-tested); capability flags AND on top (`is_public=true&has_assignments=true` ⇒ PUBLIC_AND_ASSIGNED + FULL only). `targeting_mode` separate (requires targeting row; audience combos rejected as values).

## 6. Targeting — see §5; modes never carry publicity.

## 7. Performance
Per request: 1 base + 1 assignments eager + 1 targeting eager + bounded exists-subqueries (unique-prefix indexes); resource adds zero queries (loaded relations, single resolve()); paginate + limit≤100 cap. Test-enforced: assignments/targetings queried exactly once each for 5 coupons; total < 25. No new indexes (0.03MiB table; exists-subqueries hit UNIQUE prefixes).

## 8. Cache
Key `md5(fullUrl)` → per-combo entries (tested: distinct filters, distinct data). Invalidation via observer + assignment/targeting writes → tag flush (tested: mutation refreshes filtered entry). Controller `forget()` literal-key calls are ineffective no-ops (pre-existing wart, harmless — real path is the tag flush; not redesigned per scope).

## 9. Security
Sanctum + `view-coupons` enforced (401/403 tested). No interpolated SQL (all bound; whereRaw uses `?`). No dynamic columns/relations/JSON paths. `assigned_user_id` admin-only, outputs no PII. `prepareForValidation` normalizes true/false spellings; garbage → 422.

## 10. Tests
- NEW `CouponAdminListFilterTest`: **16/16 PASS** (sqlite) and **16/16 PASS** (MySQL 8.4.3, e2e DB left clean).
- Regression: coupon dir 140 passed (1 pre-existing CLAIMED-expired, 3 skipped); GeneralDiscovery 18/18, AuthContext 5/5, AvailableCoupons 13/13, Config 8/8, Matrix 12/12, AudienceApi 4/4.
- `composer dump-autoload` was required for the new Marvel request class (18462 classes; vendor-only, no composer.json change).
- `php -l` clean on all touched files (no PHPStan/Pint configured).

## 11. Files Changed
| File | What | Why |
|---|---|---|
| `packages/marvel/src/Http/Requests/CouponIndexRequest.php` | NEW: full rules + range cross-checks + Marvel 422 shape + boolean normalization | §18 validation |
| `app/Services/Coupon/Discovery/AdminCouponFilter.php` | NEW: single-home filter application + AUDIENCE_MAP | §§11,17,24 |
| `packages/marvel/src/Http/Controllers/CouponController.php` | `index(CouponIndexRequest)`, grouped search, grouped invalid(), filter delegation, import | §§2,17 + precedence bugfixes |
| `tests/Feature/Coupon/CouponAdminListFilterTest.php` | NEW: 16 tests | §23 matrix |
| `docs/coupons/COUPON_API_DOCUMENTATION.md` | §1 query contract (EXISTING/NEW/CHANGED/NOT IMPLEMENTED) + 422 section | §27 |
| `COUPON_FILTER_DISCOVERY_REPORT.md`, `COUPON_FILTER_FINAL_REPORT.md` | NEW | §§25,28 |

## 12. Backward Compatibility
Existing requests work unchanged (same params, same shapes, same defaults) EXCEPT three documented strictness fixes: (a) search+validity AND-precedence corrected (code matches no longer bypass `active`), (b) invalid `order`/`sortedBy` → 422 instead of silent ignore, (c) `limit` clamped to 1..100, malformed numerics/dates → 422 instead of ignore/500. `scopeInvalid` grouping is result-identical standalone.
