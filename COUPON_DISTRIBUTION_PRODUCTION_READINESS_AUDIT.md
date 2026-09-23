# COUPON_DISTRIBUTION_PRODUCTION_READINESS_AUDIT.md

> Read-only audit first, fixes after. Report is evidence, not authority — every material claim below was re-traced in current source.
> Scope: `app/Services/Coupon/Distribution/**`, outbox/log models, consumers, `coupon:consume`, triggers, discovery/admin APIs, `config/rabbitmq.php`, `config/coupon-distribution.php`, scheduler, supervisor conf, `tests/Feature/CouponDistribution/**`.
> Environment: no RabbitMQ broker (`127.0.0.1:5672` closed, no docker), no MySQL (3306/3307 closed). Live-broker and MySQL-concurrency proofs are therefore code-inspection + sqlite-test level; explicitly marked NOT VERIFIED where a real broker/DB is required.
> Baseline: `tests/Feature/CouponDistribution` = 81 passed / 1 skipped (live broker) / 236 assertions. Pre-existing unrelated: `claimed_rule_passes_when_claim_is_expired` (1 failed, stale test vs frozen engine), `BusinessRulesImplementationTest` (5 failed, cart routes absent) — both re-confirmed, untouched.

## Flow under audit

```text
Business tx (+ outbox row, atomic)
  → PublishCouponOutboxJob (db queue high, afterCommit) + coupons:publish-outbox sweep (minutely)
  → RabbitMQ coupon.events (confirms, persistent, manual ACK, prefetch 10)
  → coupon:consume → DistributionStart → chunk → evaluate → Engine → transition → notification.requested
  → DB + Pusher + FCM → /available discovery → claim → apply → checkout → payment
```

## Findings

### B1 — BLOCKER — Crashed publisher leaves outbox row in `publishing` forever; sweep never reclaims it
- Where: `CouponOutboxService::publishOne()` claims via `whereIn(status, [pending, publishing]) → publishing`; `publishDue()` selects `status = pending` only.
- Crash between claim-update and outcome-update (process kill, OOM, deploy SIGKILL, DB loss) leaves `publishing` with no owner. No sweep, no job (job held only event_id and is gone) ever touches it again. The event is stuck: run never converges, user never notified, operator sees a `publishing` row with no documented recovery.
- Also the claim is non-exclusive: two publishers calling `publishOne` concurrently both succeed the claim update (both `attempts+1`) and both publish → duplicate broker messages (safe via event_id dedupe, but burns the poison budget 2x and doubles attempts).
- Fix (minimal, preserves architecture): bounded lease — claim only PENDING, plus stale-PUBLISHING reclaim when `updated_at` older than lease (5 min); `publishDue` picks due PENDING + stale PUBLISHING. Concurrent second claimer loses (returns false, no publish).

### M1 — MUST-FIX — same as B1 (implementation item; tested by stale-lease reclaim + single-publish-concurrency tests).

### M2 — MUST-FIX — observability-only PUBLISHED event-log rows grow unbounded; prune ignores them
- Where: `PruneCouponEventsCommand` deletes outbox `published` + logs `completed` only.
- Events `became_eligible / notification.sent / distribution.completed|failed / chunk.created|completed / evaluation.completed` are exchange-only (no consumer binds them) — their log rows stay `published` forever and are never pruned. At distribution scale this is the majority of log volume.
- Work-event `published` rows (start/chunk/evaluate/notify-requested never consumed) indicate stuck pipeline and must be RETAINED as evidence — so the fix must discriminate, not blanket-delete `published`.
- Fix: prune `published` rows older than cutoff whose `event_type` has no bound consumer (`RabbitMqTopology::consumerFor(type) === null`).

### S1 — SHOULD-FIX — `PublishCouponOutboxJob` burns attempts on broker outage; sweep already covers recovery
- Where: `PublishCouponOutboxJob::handle()` calls `$this->release(60)` when `publishOne` returns false.
- `publishOne` false cases: broker down (sweep recovers), poison FAILED (retry pointless), claim lost to concurrent publisher (retry pointless). In all three, requeueing is wrong; during a long outage the job exhausts `tries=5` into `failed_jobs` noise. Hard exceptions (DB down) still throw and use tries/backoff correctly.
- Fix: `$this->delete()` instead of `$this->release(60)`.

### S2 — SHOULD-FIX — notification terminal-drop wedges user in NOTIFY_PENDING for the same tree version
- Where: `NotificationRequestHandler::handle()` returns silently when run is CANCELLED/COMPLETED/FAILED (e.g. coupon disabled mid-flight while `notification.requested` in flight).
- The user was eligible, never notified, state stays NOTIFY_PENDING; a later same-tree re-run (e.g. re-enable + manual distribute) hits `NOTIFY_PENDING → duplicate_skipped` and the user is never notified despite eligibility.
- Fix: on terminal drop, release same (coupon,user,tree) NOTIFY_PENDING → ELIGIBLE (mirrors exhaustion `reopenNotifyPending`), so a future run re-evaluates. No notification is sent on the drop path (disabled coupon must stay silent).

### S3 — SHOULD-FIX — `DistributionStartHandler` can resurrect a terminal run to RUNNING
- Where: early return covers only CANCELLED; `markRunning()` unconditionally sets RUNNING.
- Normal flow cannot produce start-events for finished runs (join emits no event), but a redelivered/stale start message after terminal finish would flip COMPLETED/FAILED → RUNNING with no workers left to converge it.
- Fix: early-return no-op for COMPLETED/FAILED as well (idempotent, keeps terminal states terminal).

### O1 — OBSERVATION — real-broker contract not executable in this environment
- No broker, no docker, no MySQL. Verified by code inspection against `RabbitMqCouponEventTransport`: durable topic exchange + durable DLX, per-queue DLX→DLQ args, retry queue TTL-ceiling + per-message expiration with DLX back to topic exchange under original key, persistent delivery_mode, publisher confirms with timeout, manual ack + prefetch, lazy connection with per-operation recovery → `BrokerUnreachableException`, poison → reject(false) → DLQ, retry budget from headers, exhaustion → reject(false) → DLQ.
- One code-level note: `scheduleRetry` reads `$message->getChannel()` for the retry publish and ACKs the original — correct (no loss, no double). `consume()` applies `basic_qos(prefetch)` per consume call — verified applied, not merely configured.
- `RabbitMqIntegrationTest` stays skipped until CI provides a broker. Scenarios A–E (§8) verified at Fake level by `DistributionRecoveryTest` + `ConsumerRetryDlqTest` (outage→sweep, crash→redeliver-once, throw→bounded→DLQ, poison→immediate DLQ, duplicate→single effect).

### O2 — OBSERVATION — MySQL concurrency proven by constraint + sqlite tests only
- Dedupe (`dedupe_key` UNIQUE + QueryException join), recipient UNIQUE(run,user), user_state UNIQUE(coupon,user) + row-locked transition with unique-race catch, run counters via atomic increment + reconcile-at-finish — all inspected and covered under sqlite. `FOR UPDATE`/`SKIP LOCKED` behavior under MySQL load, multi-sweeper races, and EXPLAIN index verification remain NOT VERIFIED (no MySQL available). No global locks introduced; lock scope is single user_state row inside one transaction.

### O3 — OBSERVATION — performance not measured at volume
- Bounding mechanisms verified in code: `chunkById(500)` + audience cap, paginate-then-evaluate discovery (≤50 Engine calls), indexed candidate SQL (metrics/governorate/created_at + new last_order_at index), run-row counters, outbox sweep batch 100 + (status,available_at) index, notification guard bounded to 100 rows, prefetch 10. No `User::all()` anywhere in the plane (selector returns a query; start handler chunks). 1k/5k/10k timings NOT MEASURED — first mega-run should be staged behind the audience cap with queue-depth/outbox-backlog watched.

### O4 — OBSERVATION — pre-existing unrelated failures re-confirmed, out of scope
- `CouponEligibilityLifecycleTest::claimed_rule_passes_when_claim_is_expired`: stale test vs frozen engine semantics (engine counts ACTIVE-unexpired OR REDEEMED; test expects EXPIRED to count). Distribution never writes claims.
- `BusinessRulesImplementationTest` (5×): cart routes absent from app (404s). Distribution adds no cart routes.

### A1 — ACCEPTED RISK — notify-then-mark duplicate external delivery in a narrow crash window (at-least-once)
- `NotificationRequestHandler` notifies (dispatches queued `UserCouponEligibleNotification`) BEFORE the NOTIFIED state commits — intentional (prevents under-notification).
- Crash between dispatch and commit → redelivery passes Guard 1 (state still NOTIFY_PENDING) and Guard 2 (DB row not yet written — the notification itself is queued, async) → second dispatch. Durable state still converges to exactly one NOTIFIED (updateOrCreate + Guards), but two external deliveries (DB rows + pushes) are possible in this window. No distributed transaction across MySQL/FCM/Pusher is acceptable; the correct claim is at-least-once delivery + idempotent durable state, NOT exactly-once notification. New final report must use this language (the prior report's "exactly-once notification" phrasing is corrected here).
- Concurrent double-notify across workers is prevented in practice: the transition layer emits one `notification.requested` per (user,tree) (second evaluation sees NOTIFY_PENDING → duplicate_skipped), and the broker never double-delivers an unacked message. No notify-path restructure (risk outweighs theoretical gain).

### A2 — ACCEPTED RISK — sweep-bound end-to-end latency (~1–3 min by construction)
- Per-user evaluate fan-out rides the minutely sweep (chunk dispatch is immediate). Documented behavior, not hidden.

### A3 — ACCEPTED RISK — notification Guard 2 scans last 100 rows
- Only matters on double fault (state write lost AND matching row beyond 100). Guard 1 (state NOTIFIED) is the primary arbiter.

## Verified intact (no change)
- EligibilityEngine untouched/read-only/fail-closed; claim/reservation/usage/assignment semantics frozen; pricing boundary untouched.
- No coupon codes, rules, metrics, PII in envelopes/payloads/logs/available/admin responses (grep clean over Distribution plane; notification payload carries ids + tree_hash + run_id only).
- Identity server-resolved; admin `update-coupon` fail-closed with audit log; `private-users.{id}` unchanged; FCM per-user cleanup reused.
- Database queue untouched (one added job type with tries/backoff/timeout, queue-name convention compliant).
- Area_in saved-address ANY-match + active-governorate check in selector; Engine remains final authority; selector over-includes by design (unknown → broad).
- TreeHash deterministic (key-sorted, int/datetime-normalized, mode-bound); targeting change → new hash → re-notify; no revocation.
- Global fan-out fail-closed (targeted/assignment skip; mature-public sweep after grace).
- Scheduler: publish-outbox minutely, detect-activations/public 15min, detect-expiry hourly, prune-events monthly — all `withoutOverlapping + onOneServer`. Supervisor: 3 programs, bounded rotation, existing db workers untouched.
- Health command fails safe (non-zero, no credential leak); prod empty-password warning present in provider; all RabbitMQ settings env-driven.

## Audit verdict
- 1 BLOCKER (B1/M1), 1 MUST-FIX (M2), 3 SHOULD-FIX (S1–S3). Implement B1/M1/M2/S1/S2/S3 with regression tests, re-run layers, then final report.
