# COUPON_DISTRIBUTION_PRODUCTION_READINESS_FINAL_REPORT.md

> Task: final production-readiness validation + hardening of the completed Coupon Distribution + RabbitMQ implementation (Laravel `10.30.1`, PHP `8.2`, `php-amqplib ^3.7`, REST only).
> Method: `READ → UNDERSTAND → AUDIT → REPRODUCE → PLAN → IMPLEMENT → TEST → RE-AUDIT`. No redesign; approved architecture intact.
> Environment limit (explicit): no RabbitMQ broker reachable (`127.0.0.1:5672` closed, no docker daemon), no MySQL (3306/3307 closed). Live-broker and MySQL-load proofs are therefore code-inspection + sqlite-test level and are marked as CONDITIONS, not claimed as done.

## 1. Executive Summary

**Final status: PRODUCTION READY WITH CONDITIONS.**

The audit found 1 BLOCKER, 1 MUST-FIX and 3 SHOULD-FIX items — all implemented with regression tests, all green, independently re-reviewed (verdict: SAFE TO SHIP, no BLOCKER/MUST-FIX remaining). Delivery semantics are corrected to the honest distributed-systems contract: **at-least-once delivery + idempotent consumers + durable state guards + deduplicated notification requests** (the prior report's "exactly-once notification" phrasing is superseded here; durable state converges exactly once, external delivery may duplicate in a narrow, documented crash window).

Conditions before removing the qualifier: (1) run `RabbitMqIntegrationTest` against a real broker in CI/staging; (2) MySQL EXPLAIN + concurrency proof under load; (3) alert on `coupons:publish-outbox` staleness / pending-backlog age (recovery now depends on the scheduler — which was already the case for evaluate fan-out).

## 2. What Was Audited

Full plane: triggers → DistributionService/RunService → outbox (`CouponOutboxService`, `PublishCouponOutboxJob`, `coupons:publish-outbox`) → `RabbitMqCouponEventTransport` + `RabbitMqTopology` → `coupon:consume` + 4 handlers → `EligibilityTransitionService` (Engine untouched) → notifications (`UserCouponEligibleNotification`, DB+Pusher+FCM) → discovery (`AvailableCouponsService`) + admin APIs → event log + prune + scheduler + supervisor conf + health/setup commands. Security grep (codes/rules/metrics/PII across envelopes/logs/APIs/notifications), credential grep, guarantee-language grep, env/secret audit, pre-existing-failure triage.

## 3. What Was Changed

| # | File | Change | Why |
|---|---|---|---|
| B1/M1 | `app/Services/Coupon/Distribution/Outbox/CouponOutboxService.php` | Bounded publisher lease (`LEASE_SECONDS=300`): claim only PENDING or stale-PUBLISHING; sweep reclaims stale claims | Crashed publisher left rows in `publishing` forever (sweep selected `pending` only) → stuck events; concurrent claims double-published |
| M2 | `app/Console/Commands/Coupons/PruneCouponEventsCommand.php` | Prune aged `published` logs for KNOWN observability-only types (`all() − bindings()`); work-event + unknown types retained fail-closed | Exchange-only events stay `published` forever → unbounded log growth; first version failed open on unknown types (review catch, fixed) |
| S1 | `app/Jobs/Coupons/PublishCouponOutboxJob.php` | `delete()` instead of `release(60)` on `publishOne()==false` | All false-cases are sweep-recoverable or unrecoverable; requeue burned `tries` into `failed_jobs` noise during outages |
| S2 | `app/Services/Coupon/Distribution/Consumers/NotificationRequestHandler.php` | Terminal-run drop releases same-(coupon,user,tree) NOTIFY_PENDING → ELIGIBLE (new `releaseNotifyPending()`), still silent | Cancel mid-flight wedged users: same-tree re-run hit `duplicate_skipped`, never notified |
| S3 | `app/Services/Coupon/Distribution/Consumers/DistributionStartHandler.php` | Terminal early-return covers COMPLETED/FAILED (was CANCELLED only) | Stale/redelivered start could resurrect finished runs to RUNNING |
| T | `tests/Feature/CouponDistribution/ProductionReadinessRegressionTest.php` | 7 regression tests (lease reclaim, lease protection, prune discriminate + unknown-retain, terminal release, NOTIFIED never downgraded, COMPLETED/FAILED guard) | Proof for every fix above |
| A | `COUPON_DISTRIBUTION_PRODUCTION_READINESS_AUDIT.md` | Read-only audit (B1/M1/M2/S1–S3/O1–O4/A1–A3) | Evidence trail |

Deliberately NOT changed: EligibilityEngine, claim/reservation/usage/assignment semantics, notify-then-mark ordering (accepted at-least-once risk, §9), database queue, unrelated Fulfillment dirt, pre-existing failing tests.

## 4. RabbitMQ Real-Broker Results

**Broker version: N/A — no broker in this environment (test skips, as before).** Verified by code inspection against `RabbitMqCouponEventTransport`:
- Topology: `coupon.events` topic durable + `coupon.dlx` direct durable declared idempotently by `coupon:rabbitmq-setup`; 3 work queues (durable, DLX→own DLQ, topic bindings), 3 retry queues (durable, 24h TTL ceiling + per-message expiration, DLX back to topic exchange under ORIGINAL key), 3 DLQs bound to DLX. All names centralized in `RabbitMqTopology`, env-overridable, no hardcodes.
- Publish: persistent `delivery_mode`, publisher confirms with timeout; `BrokerUnreachableException` → row stays pending (never lost, never failed).
- Consume: `basic_qos(prefetch)` applied per consume call, manual ACK; RETRY → republish to retry queue with `x-attempt+1` + expiration then ACK original; DEAD_LETTER → `reject(false)` → DLQ; STOP/ACK paths correct; budgets 5/5/3 with delays 30/120/300/900/1800s enforced from headers (crash-safe counting).
- Restart: unacked redelivered; `alreadyCompleted` (event_id COMPLETED) no-op ACKs; state guards converge.
- Failure matrix A–E proven at Fake level by existing `DistributionRecoveryTest` + `ConsumerRetryDlqTest` (outage→sweep resume, crash-before-ACK→single effect, throw→bounded→DLQ + `dead_lettered` + recipient permanent + run converge, poison→immediate DLQ, duplicate→single transition/notification). **Live-broker round-trip remains CONDITION 1.**

## 5. Outbox Results

- Atomicity: `record()` inside business tx (rollback test green); `recordAndDispatch` afterCommit + sweep safety net.
- Broker-down: rows stay PENDING with backoff (30s→30min), never FAILED (test green).
- Crash-before-publish: PENDING swept (existing) — plus NEW: crash-between-claim-and-outcome (stale `publishing`) reclaimed under 300s lease (new test green); fresh claims never stolen (new test green).
- Crash-after-broker-accept-before-DB-mark: duplicate publication possible → safe via event_id idempotency + state guards (documented at-least-once, not solved by false exactly-once).
- Concurrent publishers: exclusive claim (second gets `claimed=0 → false`, no publish); poison budget burns once per genuine attempt.
- `PublishCouponOutboxJob`: broker-down/poison/contention → `delete()`, sweep owns recovery; hard exceptions still use tries=5/backoff.

## 6. Event Trace Example

Any lifecycle is traceable by one `correlation_id`: `coupon_event_logs WHERE correlation_id=? ORDER BY id` yields `event_id → type/version → queue/routing_key/consumer/attempt → status transitions (published→processing→completed|retrying→dead_lettered) → duration_ms + error_code/message + run/recipient/user/aggregate/tree_hash`. Outbox rows (`coupon_outbox WHERE correlation_id`) show unpublished remainder; DLQs + FAILED rows (never pruned) close the loop. Operator recipe (§13 runbook) answered end-to-end from MySQL alone — RabbitMQ UI never required.

## 7. Idempotency Results

Layers re-verified: run `dedupe_key` UNIQUE + join (duplicate POST → 409 live / new run terminal-manual), recipient UNIQUE(run,user) (+ `firstOrCreate`), user_state UNIQUE(coupon,user) + row-locked transition with unique-race catch, consumer event_id COMPLETED-skip, notification Guards 1+2, transport bounded redelivery. Duplicate-event ×N / duplicate-chunk / redelivery / two-runs-same-version converge to one effective transition + one effective durable notification (tests green, incl. new terminal-release tests). New tree_hash re-notifies by design.

## 8. Concurrency Results

Constraint + row-lock design verified by inspection and sqlite tests (duplicate-evaluation-single-request, maybe-finish matrix, terminal-drop, multi-chunk recall, new lease tests). **Real MySQL proof (parallel publishers, FOR UPDATE contention, SKIP LOCKED sweep, EXPLAIN index use) NOT performed — CONDITION 2.** No global locks exist; lock scope is one user_state row per evaluation transaction; run counters are atomic increments reconciled at finish.

## 9. Security Results

- No `coupon_code`/rules/metrics/PII in envelopes, payloads, event logs, `/available`, admin runs, notification payloads, FCM/broadcast data (grep clean over Distribution plane; notification carries ids + tree_hash + run_id only; code appears solely under owner-scoped `/mine` after claim — pre-existing rule preserved).
- Identity server-resolved everywhere; admin `update-coupon` fail-closed with `coupon.admin.auth_failed` audit log; `whereNumber` route guards; validation 401/403/404/422/409/202 covered by existing admin tests.
- `private-users.{id}` strict auth unchanged; FCM per-user token cleanup reused; Pusher/FCM failures never roll back DB truth (channel independence, FCM-failure test green).
- Secrets: all RabbitMQ settings env-driven; no credentials in source/tests/logs/payloads/reports; prod empty-password warning in provider; health command fails safe (non-zero, generic message).
- Guarantee language: code comments contain no false exactly-once claims (grep verified; remaining matches are unrelated DB-local domains).

## 10. Performance Results

Not measured at volume (no prod-like DB). Bounding mechanisms verified in code: `chunkById(500)` + audience cap (≤100k hard), paginate-then-evaluate discovery (≤50 Engine calls/request, 60s per-user version-scoped cache), indexed candidate SQL, run-row counters, sweep batch 100 + `(status,available_at)` index, notification guard ≤100 rows, prefetch 10. No `User::all()` in the plane. **1k/5k/10k timings + MySQL EXPLAIN outstanding — CONDITION 2 (stage first mega-run behind the cap, watch queue depth + outbox backlog).**

## 11. Remaining Limitations

1. Live-broker behaviors (TTL ordering, header round-trip, prefetch backpressure) proven by code + Fake, not a real broker.
2. MySQL concurrency + EXPLAIN unproven under load.
3. Fake transport requeues without delay (timing divergence, documented).
4. Guard 2 scans last 100 notification rows (double-fault-only exposure).
5. Pipeline latency sweep-bound (~1–3 min end-to-end, documented).
6. Scheduler is a recovery dependency (publish-outbox minutely, evaluate fan-out) — needs staleness alerting (CONDITION 3).

## 12. Known Unrelated Failures

- `CouponEligibilityLifecycleTest::claimed_rule_passes_when_claim_is_expired` — stale test vs frozen engine semantics (engine counts ACTIVE-unexpired OR REDEEMED); distribution never writes claims. Unchanged, out of scope.
- `BusinessRulesImplementationTest` (5× cart 404s) — routes absent from app; distribution adds no cart routes. Unchanged, out of scope.
- Working-tree Fulfillment dirt (other workstream) untouched.

## 13. Operational Runbook

- **RabbitMQ down**: app stays up (outbox holds PENDING + backoff). Watch `coupon_outbox WHERE status=pending AND available_at<=now()` count/age; restore broker → sweep drains automatically; `coupon:rabbitmq-health` gates deploys, not traffic.
- **Outbox backlog**: same query + `coupon:consume` lag; `coupons:publish-outbox --batch=` manually; alert if oldest pending age > 15 min (CONDITION 3: scheduler staleness).
- **Consumer stopped**: supervisor `meem-coupon-*` (3 programs, bounded 3600s rotation); restart safe (manual ACK + idempotency); `coupon:consume --queue=... --max-messages=...` for drain/probe.
- **DLQ message**: `coupon_event_logs WHERE status=dead_lettered` → error_code/message, attempt, consumer, queue; fix cause → re-emit via admin re-run (new tree or manual) — never hand-edit state.
- **Stuck RUNNING run**: recipients open? (`discovered/eligible/failed_retryable` rows) → pipeline alive, wait; none open but still running → check `failed` outbox/DLQ rows blocking finish; `maybeFinishRun` converges on next terminal event; reconcile counters via run `show` endpoint.
- **Failed notification**: recipient `failed_retryable` → auto-retries; `failed_permanent` → event log reason; user_state NOTIFY_PENDING released to ELIGIBLE on exhaustion/terminal-drop so re-run recovers.
- **"User says coupon missing"**: find any row by (coupon_id,user_id) in `coupon_distribution_user_states` → correlation via run/event log `traceByCorrelation` → answer WHERE (last status/queue/attempt/error) + WHY + HOW MANY + DLQ? + notified_at + tree version. Then check `/available` (Engine-advisory) and claim status — discovery never grants.

## 14. Final File/Code Change List

- `app/Services/Coupon/Distribution/Outbox/CouponOutboxService.php` (lease)
- `app/Jobs/Coupons/PublishCouponOutboxJob.php` (delete-on-false)
- `app/Console/Commands/Coupons/PruneCouponEventsCommand.php` (fail-closed observability prune)
- `app/Services/Coupon/Distribution/Consumers/NotificationRequestHandler.php` (+ `releaseNotifyPending`)
- `app/Services/Coupon/Distribution/Consumers/DistributionStartHandler.php` (terminal guard)
- `app/Console/Commands/Coupons/ConsumeCouponQueueCommand.php` (graceful broker-down exit)
- `tests/Feature/CouponDistribution/ProductionReadinessRegressionTest.php` (new, 7 tests)
- `COUPON_DISTRIBUTION_PRODUCTION_READINESS_AUDIT.md` (new audit)
- `COUPON_DISTRIBUTION_PRODUCTION_READINESS_FINAL_REPORT.md` (this file)

## 15. Test Matrix

| Suite | Passed | Failed | Skipped | Assertions | Env |
|---|---|---|---|---|---|
| `tests/Feature/CouponDistribution/` (16 files, incl. 8 new) | 89 | 0 | 1 (live broker) | 259 | sqlite |
| `tests/Feature/Coupon/` | 80 | 1 (PRE-EXISTING `claimed_rule`) | 3 | 743 | sqlite |
| `UserNotificationTest` + `CouponNotificationE2ETest` | 31 | 0 | 0 | 110 | sqlite |
| `SecurityRemediationTest` | 30 | 0 | 0 | 93 | sqlite |
| `CheckoutApiTest` + `CouponSystemTest` + `AssignedCouponSystemTest` | 36 | 0 | 0 | 69 | sqlite |
| `BusinessRulesImplementationTest` | 0 | 5 (PRE-EXISTING cart 404s) | 0 | 2 | sqlite |
| `php -l` all changed files | clean | — | — | — | — |
| Independent re-review | SAFE TO SHIP, 0 blocker/must | — | — | — | — |
| Live-broker `RabbitMqIntegrationTest` | — | — | 1 (no broker) | — | needs broker |
| MySQL concurrency / EXPLAIN | NOT RUN | — | — | — | needs MySQL |

## 17. Live Pusher-Leg Validation + "Nothing in Pusher" Diagnosis (2026-09-22, local env)

End-to-end run against the real Pusher app (cluster eu, `BROADCAST_DRIVER=pusher`, database queue):
- Direct `$user->notify(new UserCouponEligibleNotification)` + `queue:work(high)` → `BroadcastNotificationCreated` executed in ~789 ms (real Pusher API call), 1× `coupon.eligible` DB row, payload keys `title,message,icon,resource_type,resource_id,action_url,coupon_id,tree_hash,run_id` with NO coupon code. **Pusher leg: PASS.**
- Pusher Debug Console target: channel `private-users.{id}`, event `coupon.eligible`.
- Stock-Laravel finding (verified in `NotificationSender::queueNotification`, one job PER channel): one `notify()` = 3 jobs (database + fcm + broadcast), each delivering exactly once. No triple-delivery bug.
- Root cause of "nothing in Pusher": **no RabbitMQ broker at `127.0.0.1:5672`** — `coupon:consume` cannot run, outbox rows pile as `pending`, no evaluation → no notification → no broadcast. Proven live (`BrokerUnreachableException`, connection refused). Prerequisites for ANY Pusher traffic: (1) broker up + topology via `coupon:rabbitmq-setup`; (2) `coupon:consume` workers per queue (supervisor); (3) scheduler (`coupons:publish-outbox` minutely — evaluate fan-out rides it); (4) database queue workers (the queued notification job fires the broadcast); (5) targeted coupons skip global fan-out by design — per-user `coupon.eligible` only after Engine evaluation; (6) frontend must subscribe `private-users.{id}` with auth, event `coupon.eligible`.
- Fix shipped here: `coupon:consume` on broker-down previously threw a traceback; now exits FAILURE with a one-line error (supervisor backs off, outbox holds events). Covered by `test_consume_exits_gracefully_when_broker_down`.
- All live-test data cleaned (users, coupons, runs, recipients, states, logs, outbox, notifications, jobs — DB verified empty).

## 18. Final Verdict

```text
PRODUCTION READY WITH CONDITIONS
```

Conditions: (1) green `RabbitMqIntegrationTest` against a real broker in CI/staging before first production distribution; (2) MySQL EXPLAIN on candidate/outbox/log queries + concurrency proof (parallel publishers, duplicate-POST runs, counter reconciliation) under load, first mega-run staged behind the audience cap; (3) alerting on `coupons:publish-outbox` staleness and pending-backlog age. All audit BLOCKER/MUST-FIX/SHOULD-FIX items are implemented, tested, and independently reviewed with no remaining ship-blockers; the honest contract is at-least-once delivery with idempotent, observable, recoverable processing.
