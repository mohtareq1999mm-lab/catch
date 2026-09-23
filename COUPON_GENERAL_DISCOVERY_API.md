# COUPON GENERAL DISCOVERY API — FINAL REPORT

> Status: IMPLEMENTED + TESTED + REGRESSION VERIFIED.
> Labels: **VERIFIED** / **INFERRED** / **UNVERIFIED** throughout.

---

## 1. /general/coupons Current Flow

`GET /api/v1/general/coupons` (public route group, optional auth):

```text
Route (routes/api.php:72, throttle:public-api, no auth required)
  ↓
CouponController@index (now passes $request->user(), may be null)
  ↓
CouponService::getCoupons($request, ?$user)
  valid() + search/date/id filters + ordering (PRESERVED)
  + discovery candidate predicate (NEW — see §3)
  + per-item CouponDiscoveryPolicy::decide() (NEW)
  → Collection of models carrying `discoveryDecision`, excluded items removed
  ↓
CustomerCouponResource::collection (NEW — superset of CouponResource shape)
  ↓
JSON envelope (unchanged apiResponse wrapper)
```

Before: `Coupon::valid()` + filters only — every valid coupon returned to
everyone, including ineligible targeted and assignment-only coupons, with no
visibility/claim metadata and (per CP-02) never any code [VERIFIED].

Guest caching preserved (URL-keyed, 4h). Authenticated responses use a
per-user cache key with 60s TTL — per-customer eligibility must never share
the anonymous entry [VERIFIED by code].

## 2. All Customer Coupon Endpoints

| Endpoint | Auth | Policy | Codes | Notes |
|----------|------|--------|-------|-------|
| `GET /general/coupons` (index) | optional | FULL policy via shared `CouponDiscoveryPolicy` | per matrix (guests: hidden) | search/date/id filters + ordering preserved; limit (≤100) shape, no paginator (pre-existing) |
| `GET /general/coupons/available` | required | SAME policy (service refactored onto it) | per matrix | claim_status/action preserved; pagination + eligible-only meta preserved |
| `GET /general/coupons/mine` | required | assignments + claims (owner data, codes included) | owner codes | UNCHANGED — not a discovery surface |
| Homepage `coupons` (`HomeService`) | none | none (teaser) | NEVER (`CouponResource`, INV-08) | EVALUATED, intentionally unchanged: anonymous cached marketing surface; adding eligibility would break its design or leak codes to guests |
| Admin coupon APIs | admin | n/a (config surface) | exposed legitimately | explicitly out of scope (§16) |

`CouponResource` itself is UNCHANGED (homepage + INV-08/CP-02 intact)
[VERIFIED]. No other production callers of `getCoupons` exist (single
caller: index) [VERIFIED by search].

## 3. Canonical Discovery Rules

ONE decision point: `App\Services\Coupon\Discovery\CouponDiscoveryPolicy::
decide(Coupon, ?User)` → `visibility / requires_claim / eligible /
can_expose_code / include`. Both listing endpoints consume it; neither
contains its own interpretation [VERIFIED].

- **public** = no targeting row AND no assignments (canonical
  `Coupon::isPublic()`); Engine trivially passes (`no_targeting`), so no
  identity and no evaluation needed [VERIFIED].
- **targeted** = targeting row present; included only when the
  `EligibilityEngine` verdict for the current user is eligible (all four
  modes honored as implemented; unknown → fail-closed). Guests: never
  included — no guest targeting invented [VERIFIED].
- **assignment-only** (assignments, no targeting) = excluded everywhere
  here; owners use `/mine` [VERIFIED by code + test].
- Classification is deterministic from the eager-loaded targeting
  relation; one row per coupon, never duplicated [VERIFIED by test].

## 4. Claim Rules

`requires_claim` comes from `CouponClaimRequirement::forCoupon()` →
`coupon_targetings.require_claim` (boolean cast); no targeting row ⇒
`false`, matching `claim()` (`noTargeting`) and the orchestrator gate
[VERIFIED]. Native JSON boolean, strict-asserted [VERIFIED]. Never confused
with `claim_status`: `available` keeps its existing
`redeemed/claimed/not_required/claimable` + `action` lifecycle untouched;
index does not expose claim status (it never did — not added) [VERIFIED].

## 5. Code Exposure

Centralized in the policy (`can_expose_code`); no endpoint reimplements it:

```text
public                    → SHOW (authenticated)
targeted + requires_claim=false → SHOW (authenticated, eligible only)
targeted + requires_claim=true  → HIDE (code revealed by the Claim itself)
guests                    → HIDE always (CP-02 preserved)
```

Leak audit [VERIFIED by tests + inspection]: hidden codes absent from
`code` (null), `coupon_code` (key absent), `rule_tree` (absent), message
bodies, nested objects, action URLs; response bodies scanned for the raw
code value in tests. `available` previously hid ALL codes; it now follows
the same matrix (claim-first rows still null — existing pinning tests
updated to the unified rule).

## 6. Security

- `available` owner-safe shell preserved (name/slug/image/claim data only)
  [VERIFIED]. Index gains only visibility/requires_claim/code [VERIFIED].
- Per-user index cache entries (user id in key, 60s) — no cross-customer
  eligibility leak through the anonymous cache [VERIFIED by code].
- Admin surfaces untouched; customer rules never applied to them [VERIFIED].
- `visibility` restates already-passed eligibility; exposes no rules,
  counters, assignees, PII, or distribution internals [VERIFIED].

## 7. Pagination & Performance

- `available`: paginate-candidates → per-item policy → eligible-only meta +
  `has_more_pages` — architecture preserved (pre-existing page-may-be-short
  trait documented, not introduced) [VERIFIED].
- index: limit-based collection (no paginator — pre-existing, per bug-report
  docs); search/date/id/order filters still apply at SQL level before
  policy filtering [VERIFIED by test].
- N+1: single candidate query with eager `targeting`; claims batched per
  page (`available`); classification reads eager relations; Engine stays
  bounded per page/item (public items cost the trivial return); cache
  version adds two cheap MAX queries per `available` request [VERIFIED].
- Engine is reused, never duplicated [VERIFIED].

## 8. Frontend Contract

```json
// public (authenticated)
{ "id": 10, "code": "WELCOME20", "visibility": "public", "requires_claim": false }
// targeted + claim (authenticated, eligible)
{ "id": 11, "code": null, "visibility": "targeted", "requires_claim": true }
// targeted + no claim (authenticated, eligible)
{ "id": 12, "code": "VIP25", "visibility": "targeted", "requires_claim": false }
// guest public
{ "id": 10, "code": null, "visibility": "public", "requires_claim": false }
```

Rules for the client: PUBLIC → show coupon + code; TARGETED + CLAIM →
show coupon + Claim action, hide code; TARGETED + NO CLAIM → show coupon +
code, no Claim action. `requires_claim` is always an explicit boolean —
never infer it from `code` nullness [VERIFIED by tests].

## 9. Test Results

- New `tests/Feature/CouponGeneralDiscoveryTest.php`: 9/9 (42 assertions)
  — matrix cases 1–4, expired/disabled, assignment-only, guest, dedupe,
  filter preservation [VERIFIED].
- `AvailableCouponsApiTest`: 12/12 (58 assertions) — pre-existing rows
  green, new visibility/code-exposure rows green [VERIFIED].
- Regression: `CouponRemediationTest` 15/15 (incl. updated INV-08
  null-code assertion), `CouponConfigurationTest` 8/8, `CouponSuggestFixTest`
  8/8, `CouponNotificationRequiresClaimTest` 9/9, `CouponAssignment` 43/43
  (earlier run, untouched area) [VERIFIED]. `php -l` clean on all touched
  files.
- Pre-existing failures elsewhere (order/auth/real-Pusher suites, one
  eligibility-semantics test) proven identical on stashed baseline in the
  prior task and untouched by this change [VERIFIED earlier].
- UNVERIFIED: storefront rendering; production cache-hit behavior;
  load-level Engine cost at `limit=100` (bounded by construction).
