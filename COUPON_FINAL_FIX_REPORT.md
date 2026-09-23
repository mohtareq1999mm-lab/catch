# COUPON FINAL FIX REPORT — STAGE B

> Status: IMPLEMENTED + TESTED + REGRESSION VERIFIED.
> Audit basis: `COUPON_VISIBILITY_NOTIFICATION_CACHE_AUDIT.md` (Stage A).
> Labels: **VERIFIED** / **INFERRED** / **UNVERIFIED**.

---

## 1. Root causes

1. Index excluded ineligible targeted rows and conflated assignment-only
   coupons with public ones [VERIFIED — prior implementation].
2. `coupon.eligible` absent in production because distribution stalls
   upstream at the unresolvable `CouponEventTransport` in stale production
   workers; pipeline source is complete [INFERRED mechanism / VERIFIED
   source — prior transport audits].
3. Zero cache invalidation on coupon/targeting/assignment/claim/usage
   mutations; `available` versioning missed claims and non-max-moving
   deletes [VERIFIED by source absence].

## 2. Production transport fix

Source re-verified: interface + `RabbitMqCouponEventTransport` +
singleton binding + `config/app.php` registration all correct; suites
`DistributionTrigger` 6/6, `OutboxService` 6/6, `NotificationPipeline`
4/4, `TransitionAndRunLifecycle` 9/9, `ConsumerRetryDlq` 4/4 green
[VERIFIED]. No second transport introduced; no distribution bypass added
[VERIFIED]. Production recovery remains operational (deploy revision,
rebuild bootstrap/config caches, restart `queue:work` + `coupon:consume`,
verify resolution + RabbitMQ + outbox publishing) [UNVERIFIED — no prod
access]. Transition/dedupe semantics (`NOTIFIED`/`NOTIFY_PENDING`/
`tree_hash`) untouched [VERIFIED].

## 3. General catalog visibility fix

`GET /general/coupons` returns PUBLIC + TARGETED (validity + existing
filters preserved); ineligible targeted rows stay visible; assignment-only
rows excluded at SQL predicate + decision filter (race-safe)
[VERIFIED by 18-test matrix].

## 4. Targeted coupon behavior

Eligible ± claim and ineligible rows verified per matrix (`eligible` bool,
`claim_status`, `action` apply/claim/null, codes exactly per rule)
[VERIFIED]. New tree-hash reevaluation test added (notify → duplicate →
re-notify on new version, exactly 2 requests) [VERIFIED].

## 5. Assignment-only behavior

`visibility = assignment-only` iff assignments exist without targeting;
excluded from index + `available`; owner flow (`/mine` exposes code)
verified end-to-end for customer A while customer B's catalog excludes
the coupon [VERIFIED]. No assignment data leaks (key + body scans)
[VERIFIED]. Existing assignment authorization untouched [VERIFIED].

## 6. Code exposure

Central `CouponDiscoveryPolicy` (now also owns claim_status/action;
resources render only): guest→hidden; auth public→shown; targeted
eligible + no-claim→shown; claim-first/ineligible→hidden; assignment-only
never (defense-in-depth) [VERIFIED by matrix + leak scans].

## 7. Action behavior

Shared `CouponAction` helper consumed by policy: public/claimed/no-claim
→ apply; claim-required unheld → claim; redeemed/ineligible → null;
guests → null (no invented guest affordance) [VERIFIED].

## 8. Notification restoration

Code-complete pipeline preserved; `requires_claim` native bool already in
`coupon.eligible` [VERIFIED]. Dedupe (same-version, redelivery, wedged
NOTIFY_PENDING release) covered by existing + new tests [VERIFIED].
Operational restoration via §2.

## 9. Cache invalidation

`CouponDiscoveryCache` (version bump + scoped tag flush, never whole-app):
wired into CouponObserver created/updated/deleted, targeting
upsert/destroy, assignment create/update/delete, claim create/redeem/
bulk-expire, and order consumption [VERIFIED]. Index keys versioned
(guest + per-user); `available` key gains the counter [VERIFIED].

## 10. User/cache isolation

Guest vs per-user keys (60s auth TTL), live both-direction
non-contamination tests, dead-Bearer 401 preserved [VERIFIED —
`CouponDiscoveryAuthContextTest` 5/5].

## 11. Tests executed

- `CouponGeneralDiscoveryTest` 18/18 (matrix + §27–31 e2e + invalidation).
- `AvailableCouponsApiTest` 13/13 (incl. assignment-only exclusion).
- `TransitionAndRunLifecycleTest` 9/9 (incl. tree-hash reopening).
- `Remediation` 15/15, `RequiresClaim` 9/9, `UserNotification` 31/31,
  claim suites 18+8+13, `FinalContract` 9/9, `Configuration` 8/8,
  `SuggestFix` 8/8, `Assignment` 43/43, distribution suites above.
- `php -l` clean on all touched files.
- Pre-existing failures elsewhere proven identical on baseline previously.

## 12. End-to-end verification

§27 targeting flip (visible-ineligible → eligible-claim), §28 assignment
privacy (B excluded / A via mine), §29 public guest/auth, §30
targeting-deletion → public flip, §31 targeting+assignment precedence —
all PASS as automated tests.

## 13. Files changed

Policy (3-way + claim/action, `include` retired), `CouponAction` (new),
`CouponDiscoveryCache` (new), `CouponService` (catalog predicate, claims
batch), `CustomerCouponResource` (pure renderer + new fields),
`AvailableCouponsService` (policy-shared present), `CouponObserver`,
targeting controller, assignment repo, claim service, `OrderService`,
index + available test suites, transition suite.

## 14. Remaining risks

- Prod worker/cache recovery still needs the ops runbook [UNVERIFIED].
- Assignment-only codes visible to no one in catalog by construction;
  authenticated non-assignees never see these rows at all [VERIFIED].
- `limit=100` engine fan-out un-load-tested (bounded by construction).
- Pre-existing unrelated failures untouched.

```text
STAGE B COMPLETE — VERIFIED
```

Final principle enforced: GENERAL CATALOG = PUBLIC + TARGETED (never
assignment-only); VISIBILITY ≠ ELIGIBILITY ≠ USABILITY.
