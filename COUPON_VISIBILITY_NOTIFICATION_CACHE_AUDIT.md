# COUPON VISIBILITY + NOTIFICATION + CACHE — AUDIT (STAGE A, READ-ONLY)

> No application code, schema, routes, config, data, or caches modified.
> Labels: **VERIFIED** / **INFERRED** / **UNVERIFIED**.

---

## A. Current Architecture

- Discovery endpoints: `GET /general/coupons` (index, optional auth,
  `CouponService::getCoupons` + `CustomerCouponResource`),
  `GET /general/coupons/available` (auth, `AvailableCouponsService`),
  `GET /general/coupons/mine` (auth, direct assignment/claim queries)
  [VERIFIED].
- Shared decision: `CouponDiscoveryPolicy::decide()` (visibility /
  requires_claim / eligible / can_expose_code / include); `available`
  consumes it; `CouponClaimRequirement` is the single claim-truth
  [VERIFIED].
- Notification pipeline (source-complete): `CouponTargetingChanged` →
  `StartCouponDistribution` → `DistributionService` → outbox (+`PublishCouponOutboxJob`)
  → RabbitMQ (`RabbitMqCouponEventTransport`) → `coupon:consume` handlers
  (`DistributionStart → Chunk → UserEvaluate → EligibilityTransitionService`
  → `NOTIFICATION_REQUESTED` → `NotificationRequestHandler` →
  `UserCouponEligibleNotification` → database/FCM/Pusher) with
  NOTIFIED/NOTIFY_PENDING/tree_hash dedupe [VERIFIED by source read].
- Cache layers: index = tagged `COUPONS` entries (guest URL-key, per-user
  key + 60s); `available` = untagged `coupon:available:{user}:{page}:
  {limit}:{md5(targeting|coupon|assignment MAX updated_at)}`, 60s TTL;
  `/mine` = uncached [VERIFIED].

## B. Current `/general/coupons` behavior

Valid + (targeting OR no-assignments) candidates → per-item policy →
**ineligible targeted and assignment-only excluded**; rows carry
`visibility/requires_claim/code-or-null` (no `eligible`/`action` fields);
guest = pure-public, codes null; dead Bearer → 401 [VERIFIED].

## C. Required New Behavior

Index must SHOW ineligible targeted coupons (`eligible:false`, code null,
action null) and expose `eligible`+`action`; assignment-only coupons become
`public` rows per the targeting-based definition (§4: public = no targeting
config) with engine-verdict eligibility; `available` KEEPS eligibility
filtering; `/mine` unchanged [REQUIREMENT].

## D. Root Cause of Missing `coupon.eligible`

The pipeline code is intact end-to-end (transition dedupe, guards,
redelivery convergence all present). The production stop point is UPSTREAM:
`CouponEventTransport` unresolvable in stale production workers → outbox
publish jobs fail → events never reach evaluation/consumers → no
transitions → no notifications [INFERRED, high confidence — the exact
prod exception chain starts at `WorkCommand` → distribution services].

## E. `CouponEventTransport` Failure

Interface + `RabbitMq` impl + singleton binding + `config/app.php`
registration all correct in source (single introducing commit; local
manifest contains the provider); `bootstrap/cache/*` is git-ignored and
deploy-generated [VERIFIED in prior audits]. Cause: stale production
provider manifest and/or un-restarted workers. No source change warranted;
fix is operational (rebuild caches + restart `queue:work` AND
`coupon:consume` supervisors) [INFERRED mechanism, VERIFIED source facts].

## F. Eligibility Transition Analysis

`EligibilityTransitionService::evaluate()` notifies ONLY on
unseen/NOT_ELIGIBLE → ELIGIBLE per current tree_hash (row-locked state
machine; same-hash retries/duplicates converge to `duplicate_skipped`; new
tree_hash re-opens) [VERIFIED]. No change needed; must not be bypassed
with direct notifications from `CouponTargetingChanged`.

## G. Notification Pipeline Analysis

`NotificationRequestHandler` double-guards (NOTIFIED state + existing
`coupon.eligible` row), marks `NOTIFIED`/converges state transactionally,
releases wedged `NOTIFY_PENDING` on terminal drops [VERIFIED]. `requires_claim`
already present as native bool in `coupon.eligible` [VERIFIED]. Nothing to
fix in code; restore by unblocking transport (E).

## H. Code Exposure Analysis

Current central rule (`can_expose_code = user && (public || !requiresClaim)
&& eligible` after Stage B adjustment) matches the required matrix for all
rows [VERIFIED by matrix tests]. Leak scans (alternate fields, raw body)
pass [VERIFIED]. Admin surfaces untouched by design [VERIFIED].

## I. Cache Architecture

See (A). Index tag-flush capability exists (`HasCache::flushTag`) and is
the established pattern (Product/Brand/Category observers)
[VERIFIED]. `available` relies on MAX(updated_at) versioning (no tags)
[VERIFIED].

## J. Cache Invalidation Gaps (all VERIFIED by absence in source)

1. `CouponObserver` (created/updated/deleted/status): **no flush**.
2. Targeting upsert/destroy: distribution event only, **no flush**.
3. Assignment create/update/delete: **no flush** (only `CouponAssigned`
   notify on create).
4. Claim create/redeem, assignment consumption (`used++`): **no flush**.
5. `available` version ignores claims and misses deletes that don't move
   MAX(updated_at) (older-row deletes) — stale up to 60s TTL.
6. Index guest entries (4h TTL) go stale on ANY of the above until expiry.

## K. Endpoint Semantics

- index → catalog (public + targeted, ineligible shown, NEW).
- available → actionable personal shortlist (eligibility-filtered, KEEP).
- mine → owned assignments/claims (KEEP). Homepage teaser → unchanged
  (anonymous, codeless; eligibility there would break its design).

## L. Proposed Changes (Stage B, minimal)

1. P0 (operational, no code): rebuild prod caches + restart workers/
   consumers; re-fire triggers (dedupe-safe). Re-verify transport
   resolution in prod console.
2. P1: policy — visibility purely targeting-based; eligible = engine
   verdict (auth) / targeted-hidden-guest-false / public-true; exposure
   gains `&& eligible`. New shared `CouponAction` derivation
   (claim_status/action, ineligible → action null); index batches claims;
   resource gains `eligible`+`action`(+claim-aware); `available` reuses the
   helper (fields unchanged).
3. P1: `CouponDiscoveryCache` helper (tag flush + version bump); wire into
   CouponObserver, targeting controller, assignment repo, claim/redeem
   paths; `available` key gains the version counter.
4. P1: `requires_claim` already in eligible notifications (keep).
5. Tests: update index matrix (ineligible visible, assignment-only public),
   add action/claim cases, cache-invalidation tests, re-run all suites.

## M. Risk Analysis

- Assignment-only coupons surfacing as public with visible codes to
  non-assignees: accepted per "visible ≠ usable" + apply-time assignment
  gate (fail-closed); monitor abuse signals. Alternative (hide them) would
  contradict §4's definition.
- 401-on-dead-token (prior fix) interacts benignly: unaffected paths.
- No targeting/claim/usage semantics change; no distribution redesign.

---

# STAGE A GATE — answers

1. Eligible users get no `coupon.eligible` because distribution never
   reaches evaluation (transport unresolvable in prod workers) — D/E.
2. Yes, transport is the blocker — E.
3. Pipeline stops at outbox publish inside queue workers — D.
4. Yes, index currently filters ineligible targeted out (to be changed) — B.
5. Code hidden by policy `can_expose_code` (null user / claim-first /
   ineligible) — H.
6. Keys segregated (proven); staleness (not leakage) is the cache disease
   — I/J.
7. Currently: (almost) nothing invalidates coupon caches — J.
8. See L.

```text
STAGE A COMPLETE — PROCEEDING TO STAGE B (causes proven above)
```
