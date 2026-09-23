# COUPON_DISTRIBUTION_RABBITMQ_IMPLEMENTATION_FINAL_REPORT.md

> Status: **IMPLEMENTATION COMPLETE — VALIDATED** (with documented limitations and one pre-existing unrelated failure).
> Stack: Laravel `10.30.1`, PHP `8.2`, `php-amqplib/php-amqplib ^3.7` (new), REST only, no GraphQL.
> Baseline: `COUPON_DISTRIBUTION_PRE_IMPLEMENTATION_AUDIT.md` (read-only Phase 0; no rewrite).

---

## Executive Summary

The Coupon Distribution system is implemented on a real RabbitMQ event backbone with MySQL as business truth,
the `EligibilityEngine` as sole eligibility authority, a transactional outbox, a durable event audit log,
bounded retries with DLQs, cross-run idempotency, transition-only notifications (no coupon code), customer
discovery (`GET /general/coupons/available`), admin run management, and a fail-closed global fan-out fix.

**Verification:** 81 new tests pass (1 live-broker test skipped without a broker), 236 assertions; regressions
green across security (30), analytics (7), checkout (13), coupon system + assignments (72), notifications E2E (5),
UserNotification (44). Three independent specialist reviews (security, QA, independent) were run; all BLOCKERs
they found were fixed and re-verified (see § Review Findings). One unrelated pre-existing failure remains
(`claimed_rule_passes_when_claim_is_expired` — stale test vs current frozen engine semantics) plus
environmentally-broken route tests (`BusinessRulesImplementationTest` cart 404s — routes absent from the app).

**Key guarantee delivered:** every business event is durably recorded → outbox → RabbitMQ → consumer →
durable processing state → next event → customer-visible result. Any mid-flow failure is traceable via
`correlation_id` + `causation_id` + outbox + retry + DLQ + idempotency to WHAT/WHERE/WHY/HOW-MANY-TIMES/HOW-TO-RECOVER.

---

## Final Architecture

```text
                         ┌──────────────────────────┐
                         │       Laravel App        │
                         └────────────┬─────────────┘
                                      │ Domain Transaction
                    ┌─────────────────┴────────────────┐
                    │                                  │
                    ▼                                  ▼
             Business Tables                       Outbox (coupon_outbox)
              runs/recipients/                      │ atomic with business rows
              user_states                           ▼
                                              PublishCouponOutboxJob (db queue, high, afterCommit)
                                              + coupons:publish-outbox sweep (every minute)
                                                    │
                                                    ▼
                                                RabbitMQ (coupon.events topic)
                    ┌────────────────┬────────────────┐
                    ▼                ▼                ▼
             coupon.distribution coupon.evaluation coupon.notifications
             (start+chunk)      (user evaluate)   (notification.requested)
                    │                │                │
                    └────────────────┼────────────────┘
                                     ▼
                           EligibilityEngine (sole authority, untouched)
                                     ▼
                     Distribution State (runs/recipients/user_states)
                                     ▼
                       Database + Pusher + FCM (existing infra)
```

Responsibilities: MySQL = truth + durable state + audit. EligibilityEngine = eligibility authority (read-only,
unchanged). RabbitMQ = transport/routing/async only. Outbox = reliable DB→broker publication. Event log
(`coupon_event_logs`) = lifecycle/failure observability. Consumers = async processing. DLQ = exhausted retries.

The existing Laravel `database` queue (`high`/`medium`, supervisord workers) is **untouched** except one new
job type on it (`PublishCouponOutboxJob`). No job was migrated to RabbitMQ.

---

## RabbitMQ Topology

- **Exchange:** `coupon.events` (topic, durable). **DLX:** `coupon.dlx` (direct, durable).
- **Work queues** (durable, DLX → own DLQ): `coupon.distribution` ← `coupon.distribution.start`,
  `coupon.distribution.chunk`; `coupon.evaluation` ← `coupon.user.evaluate`; `coupon.notifications` ←
  `coupon.notification.requested`.
- **Retry queues** (durable, per-message TTL, DLX back to topic exchange under original key):
  `coupon.{distribution,evaluation,notifications}.retry`. Delays `[30,120,300,900,1800]s` (first retry 30s).
- **DLQs** (durable, bound to DLX): `coupon.{distribution,evaluation,notifications}.dlq`.
- **Guarantees:** publisher confirms, persistent messages, manual ack + prefetch (default 10), lazy
  connection with recovery per operation, `BrokerUnreachableException` → outbox stays pending (never lost).
- Centralized in `RabbitMqTopology` + `config/rabbitmq.php` (all env, no hardcoded credentials; TLS optional
  with peer verification). Idempotent `coupon:rabbitmq-setup` declares everything.

---

## Event Catalog (v1 contract)

Envelope: `event_id` (UUID) / `event_type` / `version:1` / `occurred_at` / `published_at` / `correlation_id`
(UUID, one per lifecycle) / `causation_id` (parent event_id, UUID-validated) / `aggregate_type` /
`aggregate_id` / `user_id` / `distribution_run_id` / `tree_hash` / `attempt` / `payload` (identifiers only —
NO codes, rules, metrics, PII). Strict `fromArray`/`fromJson` validation; violations are poison → DLQ, never
retried. Consumers reject unknown versions.

| Event | Ver | Producer | Trigger | Queue / Routing key | Consumer | Side effects | Idempotency key | Failure → Retry/DLQ |
|---|---|---|---|---|---|---|---|---|
| `coupon.activated` (Laravel) | n/a | CouponObserver | status false→true, created-active | n/a (db queue) | StartCouponDistribution | startDistribution (full run) | run dedupe key | job retry |
| `coupon.targeting.changed` | n/a | CouponTargetingController | upsert/destroy | n/a | StartCouponDistribution | startDistribution | run dedupe key | job retry |
| `coupon.disabled`/`coupon.expired` | n/a | Observer / detect-expiry | status true→false, end_date passed | n/a | CancelCouponDistribution | cancel pending/running runs | run status | job retry |
| `user.registered` (Laravel Registered) | n/a | auth | registration | n/a | StartUserCouponDistribution | per-user runs (registration-family coupons) | run dedupe key | job retry |
| `user.address.changed` | n/a | AddressObserver | create/delete/governorate change | n/a | StartUserCouponDistribution | per-user runs (area-family coupons) | run dedupe key | job retry |
| `customer.metrics.updated` | n/a | OrderService afterCommit | qualifying order completion | n/a | StartUserCouponDistribution | per-user runs (metrics-family coupons) | run dedupe key | job retry |
| `coupon.distribution.start` | 1 | DistributionService (outbox) | any trigger above + manual + scheduler | distribution queue | DistributionStartHandler | run running, recipients discovered, chunk msgs | run dedupe key | bounded → DLQ |
| `coupon.distribution.chunk` | 1 | start handler (outbox, immediate) | candidate chunk | distribution queue | DistributionChunkHandler | per-user evaluate msgs | recipient firstOrCreate | bounded → DLQ |
| `coupon.user.evaluate` | 1 | chunk handler (outbox, sweep) | per user | evaluation queue | UserEvaluateHandler | Engine eval, transition, notify request | user_state + recipient | bounded → DLQ |
| `coupon.user.became_eligible` | 1 | transition (outbox) | ELIGIBLE transition | exchange only | observability | audit | event_id | n/a |
| `coupon.notification.requested` | 1 | transition (outbox, immediate) | eligible path | notifications queue | NotificationRequestHandler | DB+Pusher+FCM, NOTIFIED | user_state + notif row | bounded → DLQ |
| `coupon.notification.sent` | 1 | notifier (outbox) | delivered | exchange only | observability | audit | event_id | n/a |
| `coupon.distribution.completed/failed`, `chunk.created/completed`, `evaluation.completed` | 1 | handlers (outbox) | lifecycle | exchange only | observability | audit + run finish | run status | n/a |

`coupon.created` has NO distribution producer by design (targeting cannot exist yet); `coupon_created` trigger
value is reserved. Claim/reserved/redeemed/assignment observations were scoped out of the wire protocol to
keep the topology minimal — claim/assignment remain synchronous domain flows.

---

## Outbox Design

Table `coupon_outbox`: `event_id` UUID unique, `event_type`, `aggregate_type/id`, `correlation_id`,
`causation_id`, `payload` JSON, `status` enum(pending/publishing/published/failed), `attempts`
unsignedSmallInteger, `available_at`, `published_at`, `last_error`, index(status,available_at).
`record()` is called inside business transactions (atomic commit); `recordAndDispatch()` adds the immediate
after-commit job; `coupons:publish-outbox` (every minute, `onOneServer`) sweeps stragglers with backoff
(30s→30min). Broker-down keeps rows pending (never failed); poison fails after 25 attempts. Successful
publish upserts the event-log `published` row. Duplicate publishes are harmless (consumer event_id guard).

## Event Log Design

Table `coupon_event_logs`: `event_id` unique, type/aggregate/user/run/correlation/causation, `status`
enum(published/processing/completed/failed/retrying/dead_lettered), queue/routing_key/consumer/attempt,
occurred/published/consumed/completed/failed timestamps, `duration_ms`, `error_code/message`, `metadata` JSON.
Indexes on event_type, correlation_id, (status,event_type), (distribution_run_id,status). No PII beyond ids.

## Distribution Design

`coupon_distribution_runs` (dedupe_key unique `coupon:tree:trigger:scope`, status pending/running/completed/
failed/cancelled, six counters, started/finished) · `coupon_distribution_recipients` (UNIQUE(run,user) ONLY,
status discovered/eligible/not_eligible/notified/failed_retryable/failed_permanent/duplicate_skipped) ·
`coupon_distribution_user_states` (UNIQUE(coupon,user) cross-run arbiter, state eligible/not_eligible/
notify_pending/notified, tree_hash, last run/recipient/evaluated/notified).
`tree_hash` = SHA-256 over canonicalized (key-sorted, int/datetime-normalized) rule tree + mode.
Reopen policy: activation/targeting = one run per tree version (join terminal); manual = new run when terminal
(409 while live); user-scope + new triggerId = new run (late eligibility re-evaluated).
`maybeFinishRun`: open = discovered/eligible/failed_retryable; else reconcile + complete/failed + observation.
Exhaustion/user-gone paths also converge (plus notify_pending→eligible release).

## Candidate Selection

`RuleFamilyExtractor` preserves AND/OR structure; `CouponCandidateSelector` pushes safe constraints into
indexed SQL (`customer_metrics` ranges incl. new `last_order_at` index, `users.created_at` index,
saved-address ANY-match on `address`⨝active `governorates`, registration/email bounds); relational rules are
suppression semi-joins only (never sole drivers); unknown/malformed/complex shapes degrade to bounded broad
scan + Engine (a flat-OR fall-through bug found by tests was fixed). Audience cap enforced during chunking
(a cap-boundary drop bug found by tests was fixed). Chunk default 500 (`chunkById`).

## Eligibility Flow

Consumers call `EligibilityEngine::evaluate($coupon,$user)` (untouched, read-only, fail-closed) per user;
`EligibilityTransitionService` (row-locked) maps to: not eligible → `not_eligible`; eligible + notified/
notify_pending → `duplicate_skipped` (zero new notifications); eligible + unseen → `notify_pending` +
`became_eligible` + `notification.requested` (new tree_hash re-opens). Area rule uses saved addresses;
claim/reservation/usage semantics frozen; capacity race left to claim (advisory copy only).

## Notification Flow

`notification.requested` → double guard (user_state NOTIFIED, then notification-row scan limit 100) →
`$user->notify(new UserCouponEligibleNotification)` (`coupon.eligible`, channels database+fcm+broadcast,
DB-first, per-channel independence proven by FCM-failure test) → recipient NOTIFIED + state NOTIFIED +
run notified_count + `notification.sent`. Payload: type/coupon_id/resource/action_url/title/message/tree_hash/
run_id — **no coupon code** (claim-first; code appears only under owner-scoped `/mine` after claim).
FCM uses existing per-user token cleanup; Pusher uses existing `private-users.{id}`.

## Idempotency

Layers: run dedupe key (+ reopen policy) → recipient UNIQUE(run,user) → user_state UNIQUE(coupon,user) +
notify_pending claim → notification-row guard → consumer event_id completed-skip → transport bounded redelivery.
Same event ×N, same job ×N, duplicate chunks, new runs (same version), crash-before-ack all converge to
exactly-once notification per (user,coupon,tree_hash); new tree versions re-notify by design.

## Retry/DLQ

Consumer attempts counted in `x-attempt` headers; failures → TTL retry queues (30s→30min) → budget exhausted
(5 distribution/evaluation, 3 notifications) → reject to DLQ + event log `dead_lettered` + recipient
`failed_permanent` + failed_count + run convergence. Poison (schema/payload/unknown version/no handler) →
immediate DLQ, no retry. Fake transport mirrors attempt/DLQ semantics (no delay — documented divergence).

## API Changes

- `GET /api/v1/general/coupons/available` (auth, `throttle:authenticated`, page/limit≤50): Engine-authoritative
  personalized discovery; paginate-then-evaluate (bounded); per-user 60s cache keyed by targeting version;
  owner-safe shells (`id/name/slug/image/claim_status/requires_claim/claim_id/expires_at/action`), never codes;
  meta counts eligible items only (no hidden-campaign oracle).
- `POST /api/v1/admin/coupons/{id}/distribute` (auth, `throttle:admin`, permission `update-coupon`):
  `{trigger:manual, audience_cap≤100000}` → 202 run / 409 already_running / 422 not_distributable / 404.
- `GET /api/v1/admin/coupons/{id}/distributions` + `/{runId}`: counters + recipient breakdown, no PII.

## Database Changes

New (all additive, reversible `dropIfExists`): `coupon_outbox`, `coupon_event_logs`,
`coupon_distribution_runs`, `coupon_distribution_recipients`, `coupon_distribution_user_states`
(+ `100007` notify_pending enum extension, `100008` attempts widening). Added indexes:
`customer_metrics.last_order_at`, `users.created_at`. No existing table/column modified, no data migration.

## Security

Identity server-resolved everywhere (no client user_id); admin gated by `update-coupon` with logged
fail-closed; owner-scoped notifications/discovery; no code/rules/metrics/PII in envelopes, payloads, logs,
or responses (review-verified); RabbitMQ creds env-only + prod-default warning; global fan-out fail-closed
(targeting unknown/fresh → skip; mature-public sweep `coupons:detect-public` after 15-min grace);
distribution triggers failure-contained (never break checkout/registration); `whereNumber` route guards.

## Performance

Paginated-then-evaluate discovery (≤50 Engine calls/request); indexed candidate SQL; chunked fan-out;
run-row counters (no aggregation queries); notification-row guard bounded (100, PHP-side JSON on TEXT);
outbox sweep batched (100) + indexed; consumer prefetch 10. Measured: full 81-test suite ~36s sqlite;
multi-chunk recall (5 users/3 chunks) green. Production SLA by construction: hops via sweep ≤60s each
(typical end-to-end ~1–3 min — documented, not hidden); MySQL `EXPLAIN`/benchmarks remain for production
volumes (see Limitations).

## Deployment

1. Provision RabbitMQ (`coupon.events`/`coupon.dlx` via `coupon:rabbitmq-setup`), set `RABBITMQ_*` env.
2. Run migrations. 3. `coupon:rabbitmq-health` must pass. 4. Install
`deploy/supervisor/laravel-coupon-consumers.conf` (3 programs, bounded `--max-seconds=3600` rotation);
existing database workers untouched. 5. Scheduler (already wired): `coupons:publish-outbox` minutely,
`detect-activations` + `detect-public` every 15 min, `detect-expiry` hourly, `prune-events` monthly.
6. Operate: `coupon:consume --queue=...`, `coupon:rabbitmq-health`, run status APIs, event-log trace.

## Tests

81 pass / 1 skip (live-broker, needs broker) / 236 assertions in `tests/Feature/CouponDistribution/`
(15 files): envelope/version/poison, tree-hash canonicalization, outbox atomicity/broker-down/poison/sweep,
run dedupe/reopen/reconcile/finish-matrix, per-rule selector recall (incl. OR-union, area ANY-match,
sole-driver suppression, unknown-rule broad, malformed-area empty, multi-chunk recall, audience-cap),
full pipeline (notify-once, ineligible skip, dedupe, new-version re-notify, B1 late-eligibility),
notifications (no-code, redelivery-once, FCM-failure isolation, owner isolation), retry→DLQ, poison,
exhaustion bookkeeping, completed-redelivery noop, recovery (outage→sweep, crash→redeliver-once, DLQ),
available API (auth/shell/ineligible/claimed/pagination/meta-oracle), admin API (401/403/404/422/202/409/
new-after-terminal/counters-no-PII), global fan-out (targeted/assignment skip, public kept, grace defer +
sweep once-only), triggers (activation/disable/address-family/registration-family/targeting-API/scanner
dedupe), health/setup commands.
Regressions: SecurityRemediation 30 + Analytics 7 + CheckoutApi 13 = 50 ✓; CouponSystem + AssignedCoupon
72 ✓; UserNotification + CouponE2E + Checkout 49 ✓; tests/Feature/Coupon 80 ✓ (+3 skips).
RabbitMQ integration test: skipped (no broker) — real-broker round-trip unverified.

## Failure Recovery

Broker down: transactions commit, outbox pending + backoff, sweep recovers (proven). Crash before ack:
redelivered, completed-skip + state guards → no duplicate effect (proven). Repeated failure: bounded retry →
DLQ + `dead_lettered` + recipient permanent + run FAILED (proven). DB failure in consumer: no ack → retry
(by AMQP discipline; Fake mirrors). FCM/Pusher down: DB row survives (proven). Stuck RUNNING: closed by
exhaustion/user-gone/permanent paths (proven by matrix). Operator trace (§ Operational Debugging).

## Operational Debugging (“Coupon 123 stopped for User 456”)

1. `coupon_event_logs WHERE correlation_id = ? ORDER BY id` (get it from any row by coupon+user) → the chain
   shows the last status/attempt/consumer/queue/error/duration. 2. `coupon_distribution_runs` (counters,
   tree_hash, status) + `recipients WHERE run_id,user_id` (status/error/attempts) + `user_states WHERE
   coupon_id,user_id` (state/tree/notified_at). 3. `coupon_outbox WHERE correlation_id` (pending rows =
   unpublished). 4. DLQs (`coupon.*.dlq`) + `failed` outbox rows (never pruned). 5. Correlate
   `event_id → queue → consumer → attempt N → error → retry → DLQ` entirely from the log.

## Known Limitations

- Live-broker behaviors (TTL backoff ordering, header round-trip, DLX routing, prefetch/backpressure)
  verified by code + Fake, not against a real broker (integration test skips; needs broker in CI).
- Concurrency proofs (duplicate-run race, state-row race) rely on unique constraints + row locks by
  inspection; MySQL `FOR UPDATE`/`SKIP LOCKED` and multi-sweeper races unproven under load.
- Fake transport requeues immediately (no delay) and synthesizes headers — timing divergences hidden.
- `notificationRowExists` scans last 100 rows (heavy recipients beyond that rely on Guard 1).
- Failed/`dead_lettered` rows are never pruned (operator must resolve first).
- Pipeline latency is sweep-bound (~1–3 min end-to-end by construction).
- `coupon.assigned` vs `coupon.eligible` copy for capacity races is advisory-only by design.

## Remaining Risks

- Production volume behaviors (selector recall at scale, Engine N+1 per user at 10k audiences, MySQL
  index verification via EXPLAIN, Redis/cache, supervisor saturation on first mega-run) need production
  measurement; audience caps + chunking bound the blast radius.
- Create→targeting admin latency beyond grace is safe (sweep only announces still-public), but public
  announcements are delayed 15 min by design (documented behavior change).
- Pre-existing unrelated dirt in tree (Fulfillment workstream files) is NOT part of this changeset.

## Decisions

- php-amqplib ^3.7 (pure PHP, sockets present, Laravel 10/PHP 8.2 compatible) over re-adding the removed
  `vladimir-yuldashev` connector: thin contract-bound wrapper, no queue-driver coupling. Revisit: never
  (contract isolates transport swaps).
- Database queue untouched (only +1 job type): no migration risk to existing workers. Revisit: only with
  measured `jobs.available_at` lag SLO breach.
- Code hidden in `coupon.eligible` (prompt §42 absolute) over audit's code-included recommendation.
- No revocation notifications on targeting change (prompt §26).
- Admin permission reuses `update-coupon` (explicit + documented) over a new permission (no seeder churn).
-Notify-then-mark (at-least-once + dedupe) over mark-then-notify (under-notification on crash).
- Manual/user re-runs open follow-up runs when terminal (B1/B2) over eternal join (which dropped late
  eligibility); activation/targeting keep one-run-per-version.
- Chunk fan-out immediate, per-user evaluate via sweep (M5 tradeoff: SLA honesty over 10k queue rows).

## Review Findings (all addressed)

- Security review: M1 global race → grace + sweep + fail-closed (fixed); S1 payload asserts (fixed);
  S2 meta oracle → post-filter counts (fixed); S3 auth logging (fixed); N2 limit→100 (fixed);
  N5 prod credential warning (fixed).
- QA review: Fake poison divergence (fixed); attempt-transport coverage gap (documented); recovery-test
  realism (hardened with handler-level tests); admin re-run lock-in (fixed + test rewritten);
  audience-cap/chunk-retry/finish-matrix/terminal-drop/poison-chunk (all now tested); concurrency
  (unique-catch added, MySQL proof remaining); sqlite/MySQL notes (attempts widened, onOneServer added).
- Independent review: B1 one-shot retrigger (fixed + test); B2 silent no-op re-run (fixed + test);
  B3 double-notify window (notify_pending + test); B4 stuck runs (finish paths + matrix test);
  B5 creation race (grace + sweep + tests); M1 event_id skip (fixed + test); M2 overflow (widened);
  M3 dispatch ordering (documented rationale); M4 created-active/null-start (observer + scanner + test);
  M5 sweep latency (chunk immediate + documented SLA); S1–S8 (fixed or documented; S8 fulfillment dirt
  confirmed pre-existing, untouched).

## Final Verification Results

| Suite | Result |
|---|---|
| CouponDistribution (new, 15 files) | 81 passed, 1 skipped (live broker), 236 assertions |
| SecurityRemediationTest + AnalyticsAPITest | 37 passed |
| CheckoutApiTest | 13 passed |
| CouponSystemTest + AssignedCouponSystemTest | 72 passed |
| UserNotificationTest + CouponNotificationE2ETest + CheckoutApiTest | 49 passed |
| tests/Feature/Coupon | 80 passed, 3 skipped, 1 PRE-EXISTING failure (`claimed_rule_passes_when_claim_is_expired`: stale test vs frozen engine semantics — engine counts ACTIVE-unexpired OR REDEEMED; test expects EXPIRED to count; untouched by this work) |
| BusinessRulesImplementationTest | 5 PRE-EXISTING failures (cart routes absent from app — 404s; untouched by this work) |
| php -l (all new/changed files) | clean |
| artisan boot + route:list + scheduler commands smoke | clean |

## Files Changed (this changeset)

New: `config/rabbitmq.php`, `config/coupon-distribution.php`,
`app/Providers/CouponDistributionServiceProvider.php`,
`app/Services/Coupon/Distribution/{TreeHash,DistributionService,DistributionRunService,NonDistributableCouponException,CouponLiveCheck,
Events/{CouponDistributionEvents,CouponEventEnvelope,InvalidCouponEventException},
Messaging/{CouponEventTransport,RabbitMqTopology,RabbitMqCouponEventTransport,FakeCouponEventTransport,BrokerUnreachableException,ConsumeResult,RabbitMqHealthService},
Outbox/CouponOutboxService, Observability/CouponEventLogService,
Selection/{RuleFamilyExtractor,CouponCandidateSelector}, Discovery/AvailableCouponsService,
Triggers/DistributionTriggerService,
Consumers/{DistributionStartHandler,DistributionChunkHandler,UserEvaluateHandler,NotificationRequestHandler,PoisonMessageException,AssertsCouponPayload},
EligibilityTransitionService},`,
`app/Models/{CouponOutbox,CouponEventLog,CouponDistributionRun,CouponDistributionRecipient,CouponDistributionUserState}`,
`app/Enums/{CouponOutboxStatus,CouponEventStatus,CouponDistributionRunStatus,CouponDistributionRecipientStatus,CouponDistributionTriggerType,CouponDistributionUserState}`,
`app/Events/Coupons/{CouponLifecycleEvent,CouponActivated,CouponDisabled,CouponExpired,CouponTargetingChanged,CustomerMetricsUpdated,UserAddressChanged}`,
`app/Listeners/Coupons/{StartCouponDistribution,CancelCouponDistribution,StartUserCouponDistribution}`,
`app/Observers/AddressObserver.php`, `app/Notifications/UserCouponEligibleNotification.php`,
`app/Jobs/Coupons/PublishCouponOutboxJob.php`,
`app/Console/Commands/Coupons/{RabbitMqSetupCommand,RabbitMqHealthCommand,PublishOutboxCommand,ConsumeCouponQueueCommand,DetectCouponActivationsCommand,DetectCouponExpiryCommand,DetectPublicCouponsCommand,PruneCouponEventsCommand}`,
`app/Http/Controllers/Api/Admin/CouponDistributionAdminController.php`,
`database/migrations/2026_09_22_100001..100008_*`, `deploy/supervisor/laravel-coupon-consumers.conf`,
`tests/Feature/CouponDistribution/*` (15 files + NullFcmChannel).
Modified: `composer.json/lock` (+php-amqplib), `config/app.php` (provider), `.env.example` (RabbitMQ + tuning),
`routes/api.php` (+4 routes), `app/Console/Kernel.php` (4 schedule lines), `app/Providers/EventServiceProvider.php`
(events/listeners/AddressObserver), `app/Observers/CouponObserver.php` (activation/disabled/created-active),
`app/Http/Controllers/Api/General/CouponController.php` (+available),
`app/Http/Controllers/Api/Admin/CouponTargetingController.php` (change hook),
`app/Listeners/SendUserCouponAvailableNotification.php` (fail-closed mature-public),
`app/Services/General/OrderService.php` (metrics event, afterCommit, contained),
`resources/lang/{en,ar}/notifications.php` (+eligible),
`tests/Concerns/CreatesTestTables.php` (+coupon_targetings mirror),
`tests/Feature/UserNotificationTest.php` (+coupon_targetings mirror + maturity),
`tests/Feature/Notifications/{CouponNotificationE2ETest.php,NotificationE2ETestCase.php}` (maturity + recorder).
NOT modified by this work (pre-existing dirt, other workstream): Fulfillment files, `Shipment.php`,
`ProductLocationService.php`, Marvel `Order.php`, fulfillment migrations, `nul`, `.phpunit.cache`.

## Follow-up

1. Provision broker + run `RabbitMqIntegrationTest` live; add broker to CI. 2. MySQL EXPLAIN + load proof
(selector/recall, 10k-audience run, supervisor saturation). 3. Resolve stale `claimed_rule` test vs frozen
semantics with product (test update, not engine). 4. Decide `BusinessRulesImplementationTest` cart-route
expectations (routes absent). 5. Consider `SKIP LOCKED` publisher claim for multi-server schedulers.
