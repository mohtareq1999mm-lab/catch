# COUPON DISCOVERY (PUBLIC + TARGETED) API — FINAL REPORT

> Status: IMPLEMENTED + TESTED + REGRESSION VERIFIED.
> Endpoint: `GET /api/v1/general/coupons/available` (auth required).
> Labels: **VERIFIED** / **INFERRED** / **UNVERIFIED** throughout.

---

## 1. Existing Endpoint

- **Route:** `GET coupons/available` → `/api/v1/general/coupons/available`,
  auth:sanctum group (`routes/api.php:125`) [VERIFIED]. Guest `index`
  (`GET coupons`, `routes/api.php:72`) is the legacy catalog listing and was
  deliberately left untouched.
- **Controller:** `App\Http\Controllers\Api\General\CouponController@available`
  (validates `page`/`limit`, delegates, wraps in standard envelope)
  [VERIFIED].
- **Service:** `App\Services\Coupon\Distribution\Discovery\
  AvailableCouponsService::forUser()` — personalized discovery: paginated
  candidates → per-item `EligibilityEngine::evaluate()` → owner-safe shells;
  advisory only, claim/apply/checkout revalidate [VERIFIED].
- **Query (before):** `Coupon::valid()->whereHas('targeting')` — targeted
  coupons only; public coupons never appeared [VERIFIED].
- **Presenter:** `present()` array (`id/name/slug/image/claim_status/
  requires_claim/claim_id/expires_at/action`); no codes/rules/counters
  [VERIFIED]. `requires_claim` already existed as native bool.

## 2. Existing Visibility Rules

- Canonical public = `Coupon::isPublic()` = **no `coupon_assignments` rows**
  (`packages/.../Models/Coupon.php:255`) [VERIFIED].
- `Coupon::scopeValid()` = status + limiter capacity + start/end dates;
  soft-deletes via Eloquent global scope [VERIFIED].
- Public single-use enforced by prior-use check (`coupon_usages.used_at`
  via `CouponValidator` / `rejectIfPubliclyUsed`) [VERIFIED].
- Change: candidates are now `valid AND (has targeting OR has no
  assignments)`. `whereHas`/`whereDoesntHave` run inside the single
  paginated query — no architecture change [VERIFIED].

## 3. Targeting Rules

- Source of truth is `EligibilityEngine::evaluate()` — reused unchanged,
  no duplication [VERIFIED]. No-targeting coupons trivially pass
  (`no_targeting`), so the engine runs uniformly over all candidates.
- Modes honored as implemented: `assignment` (usable-assignment whitelist),
  `dynamic` (rule tree), `assignment_and_dynamic`, `assignment_or_dynamic`,
  unknown → fail-closed [VERIFIED by engine source].
- Only Engine-eligible items are returned; meta.total counts eligible items
  (never pre-filter totals — no hidden-campaign leak) [VERIFIED by test].

## 4. Assignment Semantics

- Assignment-only coupons (assignments, no targeting) are **excluded** from
  general discovery; their owners use `GET coupons/mine`, which exposes
  codes needed for Apply [VERIFIED by code + new test].
- Multi-use = `used < max_uses` (`CouponAssignmentValidator`) [VERIFIED].
- Classification is deterministic: targeting row present → `targeted`,
  else → `public` (predicate guarantees the else-branch has no
  assignments). One row per coupon — never duplicated [VERIFIED by test].

## 5. requires_claim Source

`CouponClaimRequirement::forCoupon($coupon)` → `(bool)
($coupon?->targeting?->require_claim ?? false)` — the single exposure point
for `coupon_targetings.require_claim` (boolean-cast column). No targeting
row ⇒ `false`, which is the correct business meaning: `claim()` refuses
targeting-less coupons (`noTargeting`) and the orchestrator gates claims
only `if ($targeting && $targeting->require_claim)` [VERIFIED]. Native JSON
boolean, strict-asserted (`assertFalse`/`assertTrue`/`assertIsBool`)
[VERIFIED]. Kept distinct from `claim_status` (existing
`redeemed/claimed/not_required/claimable` + `action` untouched) [VERIFIED].

## 6. Final Response Contract

Flat `visibility` string (matches the flat presenter style; no ambiguous
`is_public`/`is_targeted` pair). New key only — all existing keys/values
unchanged:

```json
{
  "data": [
    {
      "id": 11, "name": "Welcome 20%", "slug": "welcome-20", "image": null,
      "visibility": "public", "claim_status": "not_required",
      "requires_claim": false, "claim_id": null,
      "expires_at": "2026-10-23T00:00:00+00:00", "action": "apply"
    },
    {
      "id": 12, "name": "VIP 25%", "slug": "vip-25", "image": null,
      "visibility": "targeted", "claim_status": "claimable",
      "requires_claim": true, "claim_id": null,
      "expires_at": "2026-10-23T00:00:00+00:00", "action": "claim"
    }
  ],
  "meta": { "current_page": 1, "per_page": 15, "total": 2, "has_more_pages": false }
}
```

(`name` passes through the model's existing representation — no i18n
restructure; no hardcoded strings added. Codes, rule trees, counters,
assignee IDs, distribution/outbox state: never exposed [VERIFIED].)

## 7. Security Review

- Owner-safe shell preserved and test-pinned (no `code`/`coupon_code`/
  `rule_tree`; response body scanned for the code value) [VERIFIED].
- No assignee IDs, rule trees, PII, distribution counters, tree hashes,
  outbox/RabbitMQ state, or admin fields added [VERIFIED by payload
  inspection + tests].
- Auth unchanged (`auth:sanctum`; guests get 401 — targeted evaluation
  needs identity; guest behavior intentionally not invented) [VERIFIED].
- `visibility` itself leaks nothing: it restates eligibility the caller
  already passed [VERIFIED by reasoning].

## 8. Performance Review

- One paginated candidate query (`valid` + targeting/assignment predicate)
  with eager `targeting`; claims batched per page (`whereIn`) — no N+1
  introduced (classification reads the eager-loaded relation only)
  [VERIFIED by code].
- Engine calls stay bounded per page (existing design); public items cost
  the trivial `no_targeting` return [VERIFIED by engine source].
- Cache version now covers targeting + coupons + assignments MAX
  `updated_at` (two extra cheap MAX queries per request; required because
  assignments flip classification) [VERIFIED].
- Pagination architecture preserved (paginate → per-item filter →
  eligible-count meta + `has_more_pages`); known pre-existing trait: a page
  may carry fewer than `limit` items when later pages hold eligible ones —
  documented, not introduced here [VERIFIED pre-existing].

## 9. Test Matrix

Extended `tests/Feature/CouponDistribution/AvailableCouponsApiTest.php`
(12 tests / 57 assertions, all green):

| Scenario | Expected | Result |
|----------|----------|--------|
| Public coupon | returned, `public`, `false` (bool), `not_required`/`apply`, no code keys | PASS |
| Targeted + eligible | returned, `targeted`, `true` | PASS |
| Public + targeted mix | both, correct per-coupon classification | PASS |
| Targeted + ineligible | excluded (pre-existing) | PASS |
| Assignment-only (no targeting) | excluded | PASS |
| Targeting + assignment, eligible | once, `targeted` (no duplicates) | PASS |
| Expired / disabled / exhausted public | excluded (only the valid one returns) | PASS |
| Guest (no token) | 401 (pre-existing) | PASS |
| Pagination meta / eligible-only total | preserved (pre-existing) | PASS |

Regression: `CouponNotificationRequiresClaimTest` 9/9 green (single
consumer of the changed service is the `available` endpoint — verified by
search). `php -l` clean.

## 10. Verification Status

- VERIFIED: endpoint/service/query/resource flow; `isPublic`/`scopeValid`/
  engine/no-targeting semantics; assignment-only exclusion rationale;
  requires_claim source + bool type; visibility determinism; owner-safe
  shell; auth/guest behavior; cache-version coverage; all matrix rows above.
- INFERRED: nothing material — implementation follows the engine rather
  than re-interpreting it.
- UNVERIFIED: storefront rendering of the new `visibility` key; production
  cache-hit ratios after the version-key change; load-level Engine cost
  with large public catalogs (bounded-per-page design holds by
  construction).
