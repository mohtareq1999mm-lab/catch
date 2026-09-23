# COUPON VISIBILITY + NOTIFICATION + CACHE — FINAL REPORT (STAGE B)

> Status: IMPLEMENTED + TESTED + REGRESSION VERIFIED.
> Audit basis: `COUPON_VISIBILITY_NOTIFICATION_CACHE_AUDIT.md` (Stage A).
> Labels: **VERIFIED** / **INFERRED** / **UNVERIFIED**.

---

## 1. Root causes

1. **Index hid ineligible targeted coupons** by policy (`include=false`) —
   superseded by the new VISIBILITY ≠ ELIGIBILITY model [VERIFIED].
2. **`coupon.eligible` never fires in production** because the distribution
   plane stalls upstream: `CouponEventTransport` unresolvable in stale
   production workers → outbox publish jobs fail → no evaluation, no
   transitions, no notifications. Pipeline source itself is complete and
   correct [INFERRED mechanism / VERIFIED source facts — see prior
   transport audits].
3. **No cache invalidation existed** for coupon/targeting/assignment/claim/
   usage mutations; `available` versioning additionally missed claims and
   non-max-moving deletes [VERIFIED by source absence].

## 2. Files changed

- `app/Services/Coupon/Discovery/CouponDiscoveryPolicy.php` — visibility
  purely targeting-based; uniform engine verdict; exposure gains
  `&& eligible`; `include` removed (consumers own inclusion).
- `app/Services/Coupon/Discovery/CouponAction.php` (NEW) — canonical
  claim_status/action derivation (`redeemed→null`, active→apply,
  no-claim→apply, else claim; ineligible→action null).
- `app/Services/General/CouponService.php` — catalog lists all valid
  coupons; one batched user-claim query; attaches `discoveryDecision` +
  `userClaim`.
- `app/Http/Resources/Coupons/CustomerCouponResource.php` — adds
  `eligible`, `claim_status`, `action` (code/visibility/claim rules
  unchanged).
- `app/Services/Coupon/Distribution/Discovery/AvailableCouponsService.php`
  — consumes policy + shared action helper (fields unchanged); version key
  gains the discovery counter.
- `app/Services/Coupon/Discovery/CouponDiscoveryCache.php` (NEW) —
  version bump + scoped tag flush.
- Invalidation wiring (one call each): `CouponObserver`
  created/updated/deleted; `CouponTargetingController` upsert/destroy;
  `CouponAssignmentRepository` assign/update/remove; `CouponClaimService`
  claim (post-commit) / markRedeemed / expireExpiredClaims (when>0);
  `OrderService::recordCouponUsage` (post-consumption).
- Tests: `CouponGeneralDiscoveryTest` (new-model matrix + invalidation),
  `AvailableCouponsApiTest` (unified code rule).

## 3. Exact implementation

Policy: `visibility = targeting ? targeted : public`; `eligible` =
engine verdict (auth) / public-only-true (guest); `can_expose_code =
user && eligible && (public || !requiresClaim)`. Index includes every
valid row; `available` filters `eligible`. Claim/action flow through the
one shared helper; `available` output keys are byte-identical in shape
(`redeemed` action normalized `none`→`null` — no test pinned the old
value). Invalidation is version-keyed (store-agnostic) plus tag-flush
where supported; never whole-app flush.

## 4. `/general/coupons` behavior

Catalog of all valid coupons with `visibility/requires_claim/eligible/
claim_status/action/code-or-null`; search/date/id/order preserved;
limit-collection shape preserved; guest rows (codes null) vs per-user
60s entries preserved; dead-Bearer 401 preserved.

## 5. `/general/coupons/available` behavior

Unchanged contract: eligible-only personal shortlist with
claim_status/action/claim metadata; now shares policy + action helper
(zero drift possible). `/mine` and homepage teaser untouched.

## 6. Notification behavior

Unchanged in code (already correct): transition-gated `coupon.eligible`
with NOTIFIED/NOTIFY_PENDING/tree_hash idempotency; `requires_claim`
native bool present. Restored operationally by unblocking transport (P0
runbook in audit §L). No direct notifications added; distribution engine
not bypassed.

## 7. `CouponEventTransport` resolution

No source change (binding/provider/registration verified correct;
`DistributionTriggerTest` 6/6, `OutboxServiceTest` 6/6,
`NotificationPipelineTest` 4/4, `TransitionAndRunLifecycleTest` 8/8,
`ConsumerRetryDlqTest` 4/4 green). Production fix remains operational:
rebuild caches, restart `queue:work` + `coupon:consume` supervisors,
verify resolution in prod console, re-fire triggers (dedupe-safe)
[UNVERIFIED in prod — no access].

## 8. Cache architecture

Index: tagged `COUPONS` entries, guest URL-key (4h) + per-user 60s keys,
now version-suffixed. `available`: untagged per-user keys with
MAX-based + counter version, 60s TTL. `/mine`: uncached.

## 9. Cache invalidation matrix

| Mutation | Version bump | Tag flush | Distribution event |
|----------|--------------|-----------|--------------------|
| Coupon created/updated/deleted/status | YES (observer) | YES | created→CouponCreated/Activated (existing) |
| Targeting upsert/delete | YES | YES | CouponTargetingChanged (existing) |
| Assignment create/update/delete | YES | YES | CouponAssigned on create (existing) |
| Claim create/redeem/expire | YES | YES | — (claim flow is sync) |
| Order consumption (used++) | YES | YES | AssignedCouponConsumed (existing) |

## 10. Security analysis

- Ineligible rows expose no code via any field (matrix tests scan keys +
  raw body) [VERIFIED]. Codes appear only for authenticated + eligible +
  (public | no-claim) rows [VERIFIED].
- Assignment-only coupons surface as public with visible codes to
  authenticated users; apply-time assignment gating stays authoritative
  (fail-closed) — accepted per visible≠usable; monitor abuse [INFERRED
  risk, documented].
- Guest hiding, claim-first hiding, ineligible hiding, per-user cache
  segregation, dead-token 401 all re-verified green [VERIFIED].
- Admin APIs untouched [VERIFIED].

## 11. End-to-end verification

- `CouponGeneralDiscoveryTest` 14/14 (82 assertions): public, targeted±
  claim eligible, ineligible visible-null-action, assignment-only public,
  expired/disabled, guest targeted, claim/apply actions, targeting-PUT
  invalidation, version bump, search.
- `AvailableCouponsApiTest` 12/12; `AuthContext` 5/5; `Remediation` 15/15;
  `RequiresClaim` 9/9; `UserNotification` 31/31; claim suites 18+8;
  `FinalContract` 9/9; `Configuration` 8/8; `SuggestFix` 8/8;
  `Assignment` 43/43. `php -l` clean all touched files.
- Pre-existing failures elsewhere (order/auth/real-Pusher, one
  eligibility-semantics test) proven identical on baseline previously and
  untouched by this change.

## 12. Remaining risks

- Production transport/cache-worker recovery still requires the ops
  runbook (no prod access from here) [UNVERIFIED].
- `limit=100` index fan-out: bounded engine evaluations per request by
  construction; load-test UNVERIFIED.
- Assignment-code visibility to non-assignees (accepted, see §10).
- Frontend must render `eligible=false` rows as non-actionable (contract
  documented; client work out of scope).

```text
Final status: PASS
```
