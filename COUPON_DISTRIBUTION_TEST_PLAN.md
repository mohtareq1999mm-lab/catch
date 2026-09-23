# COUPON DISTRIBUTION — TEST PLAN (NO TESTS MODIFIED)

> Levels: UNIT (selector/extractor/transition/dedup) → INTEGRATION (jobs+engine+notifications) → API (available/runs) → DB/MySQL (locks/uniques) → SECURITY → LIFECYCLE/LIMITS.
> Env: sqlite (`database/database.sqlite`) for logic; MySQL 8.4.3 via `phpunit.mysql.xml` (`127.0.0.1:3307`) REQUIRED for FU-lock/unique/concurrency proof (sqlite ignores `FOR UPDATE`). Queue tests both `Queue::fake` (dispatch proof) and real `database` queue E2E (delivery proof).

## 1. Rule targeting (per rule × AND/OR/nested)

For EACH of 17 rules: eligible-user notified + available-lists-it + claim succeeds; ineligible-user skipped + absent from available + claim 409 `not_eligible`. Cases:
- `area_in`: user with matching active governorate address vs inactive-governorate vs no-address vs NULL `governorate_id` (fail-closed); multi-address ANY-match; address update flips → single-user run.
- `registered_after/before`: boundary-exclusive (equal = fail); registration run.
- `min/max_completed_orders`, `min/max_total_spend` (incl. `LEGACY_CURRENCY_UNRESOLVED` spend-excluded), `first/last_order_after/before` (null = fail), `min/max_coupons_used` (per-order count semantics).
- `claimed/not_claimed` (ACTIVE-unexpired + REDEEMED block; EXPIRED releases), `has_assignment` (expired/quota-exhausted ≠ usable), `has_email` (strict RFC, verification-agnostic), nested AND/OR depth-3 + depth-11 rejected + malformed/unknown fail-closed.
- Selector precision: candidate set CONTAINS all engine-eligible users (recall=100%; precision may be lower — engine filters).

## 2. Events

- Registration: new user → only that user evaluated; only `registered_*/has_email` coupons considered; `DB::afterCommit` (user row exists).
- Address create/update/delete: only that user; only `area_in` coupons; noop re-save → `duplicate_skipped`, no notify.
- Order completion (`completed` + `payment-success`): metrics rebuilt first, then only that user + order-family coupons; failed/unpaid order → no run.
- Coupon activation (create/status→true/dates-enter/targeting-changed): run created with correct `tree_hash`; inactive/expired coupon → no run; double-fire → single `dedupe_key` run.
- Claim/assignment: claim → `not_claimed` suppression proof (no self-notify); assignment → `assignment_*` re-evaluation without fan-out to others.

## 3. Distribution

- Eligible / non-eligible / multi-eligible (N users, all notified exactly once) / zero-eligible (`audience_empty`, run completes, counters 0).
- Huge audience: 50k synthetic users, chunk 500–1000, `chunkById` no skip/dup under concurrent inserts; memory bounded; medium/high split observed.
- Duplicate trigger ×2, duplicate chunk job ×2, queue retry ×2 → single notification per (user,coupon,tree_hash); recipients `duplicate_skipped`.
- Retry: chunk fails at 50% → resumes from cursor, no re-notify of first half.
- Worker crash (kill mid-chunk, `retry_after` redelivery) → completes exactly-once-notified.

## 4. Notification (per channel + failure)

- Database: row exists, owner-scoped read, `type=coupon.eligible`, no internals, `action_url /coupons/{id}`.
- Pusher: `private-users.{A}` receives A's; B's channel gets nothing; unauth subscribe rejected (channels.php strict match).
- FCM: A's tokens only; multi-client (`client_a/b`) grouped; invalid token deleted; null-userid job never broadcasts (skips + warns).
- Failures: Pusher down → DB still written, chunk continues; FCM down → DB+broadcast done, recipient `failed` retried independently; DB down → job retry, no Pusher/FCM ghost.
- Content: per-user code policy honored; global path carries NO code post-fix; localized en/ar present; `coupon.assigned` never used without assignment row.

## 5. Security

- `customer_id` spoof (craft user_id param — no such param; identity from Sanctum only); user A `GET available` never sees B's eligibility; A `GET notifications/{B-id}` 404; `private-users.{B}` subscribe as A rejected; FCM token of B never gets A's payload; admin distribute without `permission` → 403; recipient enumeration via admin recipients listing gated.

## 6. Lifecycle

- Inactive → no run; activate → run; expire mid-run → `cancelled`, remaining chunks abort; disable mid-run → same; re-enable → new run, previously-notified = `duplicate_skipped` (same tree) or re-notified (new tree — policy); targeting Giza→Alexandria → Alexandria notified, Giza silent (policy assertion); `max_claims/limiter` change → no retro-notify, claim enforces.

## 7. Limits (distribution never bypasses)

- `max_claims` full before run → notifications may still send (advisory) but ALL claims 409 `max_claims_reached`; fill mid-run → same. `limiter` exhausted → apply/checkout reject. Assignment quota exhausted → `assignment_*` unavailable. Reservation pressure → checkout fails closed. Successful payment → usage + `REDEEMED`, metrics `coupons_used`++ → subsequent runs suppress via `max_coupons_used/not_claimed`. Failed payment → no usage, reservation released, re-claim allowed after expiry.

## 8. Proof gates (production-safe bar)

- `reconcile` TOTAL 0 (incl. new distribution detectors); MySQL concurrency proofs pass (claim parent-lock + chunk `ShouldBeUnique` under parallel workers); full coupon regression (Claim 13, Lifecycle 18, Integration 8, CheckoutRevalidation 15, System 23, Eligibility 13+23, Metrics 7, Assigned 49, Remediation 15, Phase2 9, FinalContract 9, RulesMetadata 6) green on MySQL; `php -l` clean; `route:list` shows `available` + `distribute` without breaking existing 21 coupon routes; no `failed_jobs` residue; counters reconcile (`candidate = eligible + not_eligible + failed`, `eligible = notified + duplicate_skipped + failed`).

## 9. Required new tests (names)

`CandidateSelectorTest` (per-rule recall) · `TreeHashDedupeTest` · `TransitionTrackingTest` (NOT→ELIGIBLE vs ALREADY) · `DistributionRunIdempotencyTest` · `ChunkRetryResumeTest` · `AvailableEndpointTest` (auth/pagination/no-leak/engine-parity) · `EligibleNotificationE2ETest` (db+broadcast+fcm per user) · `CrossUserIsolationTest` · `FcmTokenScopingTest` · `LifecycleAbortTest` (disable-mid-run) · `CapacityRaceTest` (fill-mid-run → claim 409, notify tolerated) · `HugeAudienceTest` (MySQL, 50k) — each with explicit setup/assertions per §§1–7 before implementation begins.
