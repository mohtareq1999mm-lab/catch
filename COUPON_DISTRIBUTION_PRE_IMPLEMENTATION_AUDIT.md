# COUPON_DISTRIBUTION_PRE_IMPLEMENTATION_AUDIT.md

> PHASE 0 — READ-ONLY DISCOVERY. No code, migration, schema, test, or frontend change was made.
> Root: `D:\work\meem`. Stack: Laravel `10.30.1`, PHP `8.2`, `QUEUE_CONNECTION=database`. REST only; GraphQL out of scope.
> Companion read-only blueprints (already on disk, not re-argued here): `COUPON_DISTRIBUTION_DISCOVERY.md`,
> `COUPON_DISTRIBUTION_ARCHITECTURE.md`, `COUPON_DISTRIBUTION_IMPLEMENTATION_PLAN.md`,
> `COUPON_DISTRIBUTION_API_CONTRACT.md`, `COUPON_DISTRIBUTION_TEST_PLAN.md`.

## 1. Existing architecture (VERIFIED)

- Monolithic Laravel commerce app, two tiers: `packages/marvel/` = vendored commerce kernel (models, repositories,
  controllers, requests, resources, payment integrations); `app/` = authoritative customer/business layer
  (checkout, coupon orchestration, currency, inventory reservations, gateway abstraction, ChannelContext).
  Rule: new business behavior goes in `app/` behind contracts/adapters; Marvel storage/CRUD is reused, never duplicated
  without a documented reason. No merge of the two layers for this feature.
- Request path: Route → Controller (thin) → FormRequest → Application/Domain Service → Repository/Marvel infra →
  Model → Resource. Controllers never hold business logic; validation in FormRequests; authz in Policies/Gates;
  heavy work in Jobs; side effects in Events+Listeners.
- Conventions preserved: `QueueName::high()/medium()` only (static test enforces `high|medium`, no hard-coded strings),
  `FrontendResource` caching, `ApiResponse` envelope `{success,message,data,meta?}`, localized `en/ar` payloads,
  `HasChannelFilter` store scoping where applicable. Single-tenant verified — no tenant-context work required.
- Confidence: HIGH (direct file evidence across `app/`, `packages/marvel/src`, `config/`, `deploy/`, `docs/`).

## 2. Existing coupon flow (VERIFIED — semantics FROZEN)

- **Assignment** (`coupon_assignments`: `id, coupon_id FK cascade, user_id FK cascade, max_uses, used, assigned_at,
  expires_at, timestamps`, `UNIQUE(coupon_id,user_id)` — `database/migrations/2026_07_15_000003_*`):
  admin-only grant via `Marvel CouponAssignmentController::store` (`permission:create-coupon-assignment`) →
  `CouponAssignmentRepository::assignCoupon` (pre-check `exists()`→409, `DB::transaction(create+fresh)`, unique
  violation mapped to 409, lock order Coupon→Assignment) → `event(new CouponAssigned)` AFTER commit. Usable iff
  `!expired AND used < max_uses`. `isPublic() = !assignments()->exists()`; public = single-use per customer.
- **Claim** (`coupon_claims`: created `2026_09_10_000002` with `unique(coupon_id,user_id)`, lifecycle
  `2026_09_14_000001` → `status ENUM(active,expired,redeemed)`, `expires_at`, `redeemed_at`, dropped unique in favor of
  app-level `FOR UPDATE` + `idx_claim_lookup(coupon_id,user_id,status)`):
  customer-only `POST /general/coupons/{id}/claim` → `CouponClaimService::claim` (`DB::transaction(...,3)`,
  `CouponTargeting FOR UPDATE` parent lock, `noTargeting/claimNotRequired/alreadyClaimed/maxClaims/notEligible`,
  `max_claims` counts `ACTIVE(unexpired)+REDEEMED`, TTL from `claim_ttl_hours`, `eligibility_snapshot JSON`).
  `ACTIVE→REDEEMED` only via `markRedeemed`; `ACTIVE→EXPIRED` via `coupons:expire-claims` hourly.
- **Reservation** (`coupon_reservations`, `2026_08_31_120100`, unique `order_id`, 30-min TTL):
  `used + active_reservations < limiter` under coupon `FOR UPDATE`; swept by `coupons:expire-reservations` every 5 min.
- **Usage** (`coupon_usages`, `2024_12_27_000001`): permanent consumption in `OrderService::recordCouponUsage` on
  `changeOrderStatus(completed)` (coupon lock + reservation + Orchestrator revalidation on order items + `used++` +
  `coupon_usages`/`coupon_assignment_usages` + `coupon_consumed` idempotency flag). `PaymentSucceeded`
  (`ShouldDispatchAfterCommit`) → `MarkCouponClaimRedeemed` (queued, `afterCommit`, high) resolves coupon from
  authoritative `order.coupon` snapshot via `byCode`, locks exact `ACTIVE unexpired` claim, `ACTIVE→REDEEMED`;
  missing/lost-race = safe no-op; transient = rethrow. Cancel/refund NEVER restores usage (anti-farming policy).
- Static gates: `CouponValidator` (status/dates/`used>=limiter`/already-used/product gate) + `CouponAssignmentValidator`
  + `CouponOrchestrator::validate/validateByCode` (canonical `byCode`, `require_claim` gate, per-mode
  `dynamic/assignment/and/or` wiring, `rejectIfPubliclyUsed`). `Coupon::scopeValid` (status+limiter+dates),
  `scopeByCode` canonical. `used` system-controlled only.
- Confidence: HIGH. Implementation MUST NOT alter any of the above semantics.

## 3. Existing eligibility flow (VERIFIED — Engine stays pure)

- Sole authority: `app/Services/Coupon/Eligibility/EligibilityEngine.php::evaluate($coupon,$user,$context=[])` —
  read-only, deterministic, fail-closed. Grammar: `RuleTreeValidator::MAX_DEPTH=10`, leaf `{type,value}` /
  group `{operator:AND|OR, rules:[...]}`; unknown mode/rule/operator, malformed, empty group, depth>10 = ineligible.
- 17 whitelisted rules (`App\Enums\EligibilityRuleType`): `min/max_completed_orders`, `min/max_total_spend`
  (bccomp 2dp, non-numeric fail-closed), `first/last_order_after/before` (null = fail),
  `min/max_coupons_used` (per-order count), `claimed` (=ACTIVE-unexpired OR REDEEMED), `not_claimed` (=neither),
  `has_assignment` (usable only), `area_in` (saved-address ANY-match — §9), `has_email` (strict RFC, verification
  not required), `registered_after/before` (`users.created_at`, exclusive). Modes: no-targeting = eligible;
  `dynamic` = tree; `assignment` = usable assignment; `and/or` combos. Metrics from `CustomerMetricsService`
  (`completed` + `payment-success` only; `LEGACY_CURRENCY_UNRESOLVED` excluded from spend sum).
- Distributor MUST call the Engine as final authority and MUST NOT re-implement, copy, or embed any rule logic;
  Engine MUST NOT gain queue/notification/persistence/selection duties (new code lives in
  `App\Services\Coupon\Distribution\`, which does not exist yet — VERIFIED ABSENT).
- Confidence: HIGH.

## 4. Existing notification flow (VERIFIED — reuse, fix one leak)

- `CouponAssigned` (AFTER assignment commit) → `SendUserCouponAssignedNotification` (ShouldQueue, `viaQueue high()`,
  guards `type==user`) → `UserCouponAssignedNotification` via `[database,fcm,broadcast]` (mail dormant, never in
  `via()`), payload localized `title/message{en,ar}`, `icon:tag`, `resource_type:coupon`, `resource_id`,
  `action_url:/coupons/{id}`, `coupon_assignment_id/coupon_id/coupon_code/max_uses/expires_at`,
  `broadcastType/databaseType=coupon.assigned`.
- `CouponCreated` (`CouponObserver::created` post-persist) → `SendUserCouponAvailableNotification` (ShouldQueue, high;
  `if assignments()->exists() return`, else `User::where(type=user)->chunkById(500){notify}`) →
  `UserCouponAvailableNotification` via `[database,fcm,broadcast]`, type `coupon.available`, payload carries
  `coupon_id/coupon_code/coupon_type`. **LEAK CONFIRMED**: global fan-out sends `coupon_code` to ALL users and ignores
  targeting — MUST NOT be used for targeted coupons (fix: skip targeted coupons here; targeted path uses new
  `coupon.eligible` per-user notification).
- `AssignedCouponConsumed` → `coupon.used`. All listeners/notifications on high queue via `QueueName`/config.
- New: `UserCouponEligibleNotification`, type `coupon.eligible`, same 3 channels, per-user payload (code policy §18).
- Confidence: HIGH.

## 5. Existing queue infrastructure (VERIFIED)

- Driver: `database` (`config/queue.php` default `env(QUEUE_CONNECTION,database)`; `.env.example`
  `QUEUE_CONNECTION=database`, `QUEUE_HIGH=high`, `QUEUE_MEDIUM=medium`). Tables: `jobs`
  (`bigIncrements id, queue indexed, payload longText, attempts, reserved_at, available_at, created_at` —
  `2022_04_11_094659`) + `failed_jobs` (`database-uuids`, `2019_08_19_000000`) + `HandleFailedQueueJob` on `JobFailed`.
  Timing contract: `retry_after=1800 > worker timeout 1300 > job timeout 1200 > p99 ~600s`.
- Semantic queues: `config('queue.queues.high/medium')` via `QueueName::high()/medium()` only (static test
  `QueueStandardizationStaticTest` allows `high|medium`). Coupon plane today: everything on **high**;
  `SendFcmNotificationJob` on `config(frontend.queue, high)` with `tries=3, backoff=[30,120]`; coupon listeners declare
  NO per-job tries/backoff (worker defaults apply) — new distribution jobs MUST declare them.
- Workers (prod, `deploy/supervisor/`): `laravel-worker-catch-high.conf` (1 proc, `--queue=${QUEUE_HIGH:-high}`,
  `--tries=5 --timeout=1300 --sleep=1 --memory=512 --max-jobs=500 --max-time=3600`, `stopwaitsecs=1400`) and
  `-catch-medium.conf` (same with `--tries=3 --sleep=3`). No Horizon, no Redis queue driver in use (predis installed
  as client only), no custom broker, no systemd/cPanel workers beyond supervisord + `artisan schedule:run`.
- Scheduler (`app/Console/Kernel.php`): `orders:cancel-unpaid` 5min, `coupons:expire-reservations` 5min,
  `coupons:expire-claims` hourly, `coupons:reconcile` hourly `withoutOverlapping+onOneServer`,
  wishlist/cart/promo/flash-sale notifiers, `payments:reconcile` 15min, `queue:prune-failed` daily, currency 6h.
- Jobs inventory (`app/Jobs/`): `GenerateInvoicePdfJob, LogActivityJob, PaymentReconciliationJob,
  SendFcmNotificationJob, SendFrontendWebhookJob, SendPasswordResetEmailJob` — no distribution jobs exist.
- Confidence: HIGH.

## 6. Existing Pusher infrastructure (VERIFIED)

- `config/broadcasting.php` default `env(BROADCAST_DRIVER,pusher)`; `pusher` connection
  (`key/secret/app_id` + `cluster`, `useTLS=true`). `pusher/pusher-php-server ^7.2` (via `packages/marvel/composer.json`).
- Per-user channel: `User::receivesBroadcastNotificationsOn()` (`packages/marvel/src/Database/Models/User.php:360`)
  → `users.{id}` → on-wire `private-users.{id}` (`BroadcastNotificationCreated`). Auth `routes/channels.php`:
  `users.{id}` strict `(int)user.id===(int)id`; plus `admin.notifications` (admin), `user.{id}.orders`,
  `order.{orderId}` (owner-order check). Broadcast auth requires auth (401 unauth proven by
  `FileOperationSecurityTest`). Coupon notifications `->onQueue(high)`.
- Reuse as-is; new `coupon.eligible` rides the same private channel. No channel changes required.
- Confidence: HIGH.

## 7. Existing FCM infrastructure (VERIFIED)

- `kreait/firebase-php 6.7` (`composer.json`); `predis` present but irrelevant to FCM path.
- `FcmChannel::send`: single source = `toDatabase()` (or `toFcm()` override), resolves `{en,ar}` maps via
  `App::getLocale()`, missing title/body = skip + warn, dispatches `SendFcmNotificationJob(title,body,data,userId)`
  with **notifiable-scoped user id** (null id = skip, never table-wide broadcast).
- `SendFcmNotificationJob`: `tries=3, backoff=[30,120]`, `DeviceToken WHERE user_id` chunked 500, grouped by `client`
  (`client_a|client_b` validated server-side), `sendToClient`, invalid tokens deleted + logged, `failed()` logs.
- Device tables: `device_tokens` (`2026_08_23_073810`) + `user_device_tokens` (`2026_09_12_000003`, distinct schema);
  `POST/DELETE device-tokens` client endpoints; multi-project routing documented in `api-desc/moblieNotifecation/`.
- Reuse as-is; per-user scoping already satisfies §17.
- Confidence: HIGH.

## 8. Existing DB notification infrastructure (VERIFIED)

- `notifications` (`2026_07_05_080106`): `uuid id PK, type string, morphs notifiable, data text, read_at, timestamps`.
  `type` = business id via `databaseType()` (`DatabaseChannel::buildPayload`), e.g. `coupon.assigned`.
- Owner-scoped APIs (Marvel `NotificationController`, `auth:sanctum`): `index` (paginate), `unread`, `show`
  (`findOrFail` under `$user->notifications()`), `markAsRead`, `markAllAsRead`, `destroy`. Locale from `lang` header
  (`CheckLangMiddleware`), `en` fallback. `formatNotification` exposes only `{id,type,title,message,icon,
  resource_type,resource_id,action_url,created_at,read_at}`.
- DB notification = durable truth; Pusher/FCM failures never delete it (channels independent — verified in channel +
  job code). New type `coupon.eligible` follows the same row shape.
- Confidence: HIGH.

## 9. Existing assignment notification flow (VERIFIED end-to-end)

```text
Admin POST /api/v1/coupons/{coupon}/assignments (permission create-coupon-assignment)
 → CouponAssignmentRepository::assignCoupon (409 on duplicate, unique arbiter)
 → commit → event(new CouponAssigned) → queued listener (high)
 → $user->notify(UserCouponAssignedNotification) → database row + broadcast private-users.{id} + FCM to that user's tokens
 → customer GET notifications/unread → GET .../mine (sees code/quota) → apply/claim/checkout
```

- Untouched by this feature. Distribution MUST NOT call `assignCoupon()` (hybrid model); the only writer of
  assignment rows stays the admin path.
- Confidence: HIGH.

## 10. Existing coupon discovery endpoints (VERIFIED — gap confirmed)

| Endpoint | Auth | Behavior today |
|---|---|---|
| `GET /api/v1/general/coupons` (`CouponController@index` → `CouponService::getCoupons`) | none | `Coupon::valid()` + search/limit≤100; NO engine call; `CouponResource` = `id/name/slug/image/border` ONLY (never `code`, never rules/counters) |
| `GET /api/v1/general/coupons/mine` | sanctum | own assignments + own claims WITH owner `code`; nothing else |
| `POST /api/v1/general/coupons/{id}/claim` | sanctum | `findOrFail` → `CouponClaimService::claim` → 201 `CouponClaimResource` or 409 `{reason}`-only / 404 |
| `POST /api/v1/general/coupons/apply` | sanctum | `{code}`-only → cart preview (`addCouponToCart`); 400 `{reason,COUPON_<REASON>}`; never claims/reserves/consumes |
| `GET /api/v1/general/coupons/available` | — | **ABSENT** (search hits only prior proposal docs; zero `app/` implementation) — TO BUILD per §19 |

- Area proof case (required test): Giza saved address + Alexandria checkout + `area_in=Giza` → eligible (saved
  addresses authoritative; checkout/delivery input ignored by `evalAreaIn`). Engine already implements this.
- Confidence: HIGH.

## 11. Existing events / listeners / jobs (inventory, VERIFIED)

- Events (`app/Events/`, 28): `CouponAssigned`, `CouponCreated`, `AssignedCouponConsumed`, `OrderCreated`,
  `OrderStatusChanged`, `OrderDelivered`, `OrderShipped`, `OrderCancelled`, `PaymentSucceeded`
  (`ShouldDispatchAfterCommit`), `PaymentFailed`, `PromotionActivated`, `FlashSaleActivated`, product/review,
  shipment, refund, invoice, cache-invalidation. NO `CouponActivated/TargetingChanged/AddressChanged/
  MetricsChanged/UserRegistered-for-coupons` events — all TO ADD as thin dispatchers (after-commit only).
- Listeners: `SendUserCouponAssignedNotification`, `SendUserCouponAvailableNotification` (anti-pattern inline
  `chunkById{notify}` — to be bypassed for targeted coupons), `MarkCouponClaimRedeemed`, order/payment/promo/flash
  fan-outs, wishlist action, 3 scheduler notifiers. All coupon listeners `ShouldQueue` on high.
- Jobs: 6 listed in §5; scheduler §5. `coupons:reconcile` detectors read-only (extend with distribution detectors).
- Missing triggers today: `Registered` → only email verification; address save → none; order completed → metrics
  rebuild only; coupon update → audit only. Each gets a thin after-commit dispatcher in P4 (never sync in request).
- Confidence: HIGH.

## 12. Candidate discovery feasibility (VERIFIED — feasible with existing indexes)

- `customer_metrics` ALREADY indexed (`2026_09_10_000003`): `completed_orders`, `total_qualifying_order_value`,
  `first_order_at`, `coupons_used` (+ `unique user_id`). Gap: `last_order_at` NOT indexed → add in P0 migration.
- `addresses`: `(customer_id,governorate_id)` index added `2026_09_28_000001` (+ `governorate_id` FK `nullOnDelete`);
  `governorates.status` boolean filters inactive. `users.created_at` range scans for registration rules (add index if
  `EXPLAIN` shows need — measure in P1, don't assume).
- Strategy (correctness > precision): rule-family extractor → coupon pre-filter (tree contains family) →
  `area_in`→address join DISTINCT; registered→`users.created_at`; metric/spend/usage→`customer_metrics` ranges;
  first/last→metrics datetimes; `has_email`→user email nullability (+ engine strict check);
  `claimed/not_claimed/has_assignment`→suppression semi-joins only. AND→intersect, OR→union, complex/unparseable→
  broad `customer_metrics`-bounded scan + Engine filter (never exclude on optimizer ignorance). Chunk `chunkById`
  500–1000 (tune in P2 with memory/DB/job-duration evidence; do NOT hardcode blindly).
- Confidence: MEDIUM-HIGH (queries proven present; volumes to measure in P1/P2).

## 13. RabbitMQ feasibility (VERIFIED)

- History: `redis` → `database` (2026-08-19, fa5bdfc); RabbitMQ package `vladimir-yuldashev/laravel-queue-rabbitmq
  ^15.0` added 2026-08-26 as "Phase 1 groundwork" but **never configured, never referenced in app/config/database/
  routes/tests** (`docs/RABBITMQ_AUDIT.md` 0-match audit); fully REMOVED 2026-08-31
  (`docs/RABBITMQ_REMOVAL_REPORT.md`; `composer.json`/`composer.lock` clean today — verified: no
  `vladimir-yuldashev` match in current `composer.json`); queue remains `database`.
- What RabbitMQ would buy: independent consumers, DLQ routing, backpressure, priority, multi-service fan-out.
- What it costs here: new broker to deploy/monitor/secure, Laravel connector complexity, local-dev burden,
  retry/DLQ semantic duplication of `failed_jobs`, connection failure modes, no consuming service waiting for it.
- Expected load (evidence): coupon count = admin-curated (tens, not millions); distributions = on-activation +
  per-user state changes (not a firehose); notifications = existing per-user fan-out already handled on `database`
  queue; chunk jobs 500–1000 keep each job seconds-scale; supervisor + `retry_after` contract already proven.
- Confidence: HIGH.

## 14. RabbitMQ recommendation: DO NOT USE RABBITMQ YET (evidence-based)

```text
DECISION: DO NOT USE RABBITMQ YET
```

- Why: existing `database` queue + semantic high/medium + supervisord + `failed_jobs` demonstrably handles
  Start→Chunk→Notify decomposition; no measured bottleneck; no second consumer/service; removed-once dead dependency
  must not be reintroduced on preference. RabbitMQ adds operational complexity with zero required capability.
- Revisit triggers (measurable): sustained `jobs.available_at` lag > SLO, worker saturation (both supervisors at cap
  with growing backlog), coupon distribution frequency × audience exceeding single-DB throughput, or a second
  consuming service actually commissioned. Then: `USE RABBITMQ ONLY FOR DISTRIBUTION EVENTS` behind a
  queue/message abstraction, Engine untouched, with documented queues/exchanges/routing-keys/consumers/retries/DLQ/
  RabbitMQ-down behavior — none of which is built now.
- If overruled, the mandatory isolation + documentation list in spec §26 applies verbatim.
- Confidence: HIGH. Status: DECIDED (reversible on triggers above).

## 15. Database design (reconciled to existing conventions — PROPOSAL, not created)

Follow observed conventions: `$table->id()` (bigint PK), `foreignId()->constrained()->cascadeOnDelete()`,
`timestamps()`, `unique()`, `index()`, `enum()`, `json()`, `decimal(15,2)`, `unsignedInteger`, reversible `down()`.

```php
coupon_distribution_runs: id; coupon_id FK coupons cascade; trigger_type ENUM(coupon_activated,
  coupon_created, targeting_changed, user_registered, address_changed, order_completed, manual);
  trigger_id VARCHAR(191) NULL; tree_hash CHAR(64); dedupe_key VARCHAR(191) UNIQUE;
  status ENUM(pending,running,completed,failed,cancelled) DEFAULT pending;
  candidate_count/eligible_count/notified_count/failed_count INT DEFAULT 0 (+ duplicate_skipped_count);
  started_at/completed_at NULL; timestamps; INDEX(coupon_id,status), INDEX(status,created_at).
coupon_distribution_recipients: id; run_id FK runs cascade; coupon_id FK coupons cascade; user_id FK users cascade;
  status ENUM(discovered,eligible,notified,failed,duplicate_skipped) DEFAULT discovered (+ explicit retryable vs
  permanent failure columns: attempts TINYINT DEFAULT 0, error VARCHAR(500) NULL);
  notified_at NULL; timestamps; UNIQUE(run_id,user_id) ONLY (no redundant equivalent unique);
  INDEX(coupon_id,user_id), INDEX(run_id,status), INDEX(user_id,status).
Support: ADD INDEX customer_metrics.last_order_at; ADD INDEX users.created_at IF measured; ADD functional/
  composite support for notifications dedup lookup (notifiable+type+coupon_id) IF engine allows, else app-level
  pre-check (decide in P0 with EXPLAIN on target DB).
```

- `tree_hash` = `sha256` over canonical-normalized tree (sorted keys, normalized ints/datetimes — same normalizer the
  Engine's validator implies; unstable `json_encode` of raw input FORBIDDEN). New targeting version → new hash →
  new distribution opportunity; same hash + same trigger scope → dedupe hit.
- Recipient states FINAL: `discovered → eligible → notified`, plus `not_eligible`, `duplicate_skipped`,
  `failed_retryable`, `failed_permanent` (do NOT collapse retryable/permanent; do NOT leave ambiguous).
- All additive, reversible (`dropIfExists` new tables only), non-destructive, production-safe. NOT CREATED in Phase 0.
- Confidence: MEDIUM (schema reconciled; final index list confirmed with EXPLAIN in P0).

## 16. Idempotency design (REQUIRED, nothing sufficient exists)

- Layers: run dedupe (`dedupe_key = coupon:tree_hash:trigger_type:trigger_scope`) + recipient `UNIQUE(run_id,user_id)`
  + cross-run notification guard (`notifications` lookup by `notifiable+type=coupon.eligible+coupon_id+tree_hash`
  before `notify`) + job `ShouldBeUnique` on chunk cursor (evaluate carefully per §27 — uniqueness on
  `(run_id,cursor)`, never on `(coupon_id)` alone which would suppress legitimate new-tree runs).
- Policy: notify ONLY on `unseen/NOT ELIGIBLE → ELIGIBLE` for that `tree_hash`; `ALREADY ELIGIBLE` re-evaluations,
  retries, double-fires, noop address saves → `duplicate_skipped`, zero notifications. Same event ×N, same job ×N,
  concurrent jobs ×N, same manual request ×N → exactly-once notify per `(user,coupon,tree_hash)`.
- Confidence: MEDIUM (design complete; proof awaits P2/P3 tests).

## 17. Concurrency design

- Same user+coupon+multiple events (e.g. `AddressUpdated` + `OrderCompleted` racing): uniqueness constraints (not
  timing) arbitrate — second writer gets `UNIQUE` hit → skip. Claim capacity stays under `CouponTargeting FOR UPDATE`
  (distribution takes NO locks; Engine reads only). Chunk jobs carry disjoint `chunkById` cursors; retry resumes
  cursor; `retry_after(1800) > worker timeout` prevents premature redelivery. `ShouldBeUnique` scoped to
  `(run_id,cursor)` so distinct runs never suppress each other.
- Confidence: MEDIUM (proven primitives; distribution-level proof in P8 MySQL concurrency tests).

## 18. Security audit (must-hold invariants)

- Identity: NEVER trust client `user_id`; candidates selected server-side; Engine called with server-resolved `User`;
  admin endpoints permission-gated (`update-coupon` or dedicated `distribute-coupon` — reconcile to existing
  `permission:*coupon*` convention in P6; admin MUST NOT submit arbitrary audience ids).
- Delivery: Pusher stays `private-users.{id}` (strict int match, 401 unauth proven); FCM stays per-user token scoped
  (null-id never broadcasts — proven); notifications stay owner-scoped `findOrFail` (IDOR 404, proven by
  `NotificationAuthorizationTest` family).
- Confidentiality: NO rules/snapshots/counters/limiter/other-user data in any customer response, notification, log;
  code policy §19 enforced in resource + notification review (P3/P5 security gates).
- Confidence: HIGH (boundaries verified; new code must re-prove with `CrossUserIsolationTest` + channel tests).

## 19. Performance risks (to measure, not assume)

- Fan-out amplification (one activation × large audience), `chunkById` over non-covering indexes, N+1 in chunk loop
  (metrics/addresses/claims per user — MUST eager/batch), `rebuildAll()`-style full scans (FORBIDDEN pattern),
  broadcast/FCM per-user round-trips, `notifications.data` JSON filter cost, lock contention (distribution takes none —
  keep it that way), duplicate-suppression lookup overhead, `available` endpoint per-request engine evaluation (needs
  short per-user cache + pagination cap 50). Mitigations: rule-indexed candidates, 500–1000 chunks (tuned), batch
  loads, medium-queue for chunks, high for notify, counters on run row (no extra aggregation queries), `EXPLAIN`
  before final indexes, MySQL proof for FU-adjacent paths.
- Confidence: MEDIUM (risks identified; numbers come from P2/P7 benchmarks).

## 20. Exact implementation phases (after approval — NOT started)

- P0 Foundation: migrations (runs/recipients + `last_order_at` [+`users.created_at` if measured] + notif-dedup
  support), models, status enums, canonical tree-hash normalizer + unit tests. Gate: EXPLAIN-clean + rollback-safe.
- P1 Distribution engine: `Distribution/` (distributor, `CandidateSelector`, `RuleFamilyExtractor`), Engine
  integration (read-only calls), AND-intersect/OR-union/broad-fallback. Gate: recall=100% per-rule tests.
- P2 Queue: `StartCouponDistributionJob` (validate coupon/targeting, dedupe, create run, dispatch chunks) +
  `EvaluateCouponChunkJob` (ids→batch loads→Engine→recipient transition→notify-if-transition) with explicit
  tries/backoff/timeout + `ShouldBeUnique(run,cursor)` + cursor resume. Gate: retry/crash/duplicate proofs.
- P3 Notifications: `UserCouponEligibleNotification` (`coupon.eligible`, db+broadcast+fcm, DB-first, per-channel
  isolation) + dedup guard + global-`available` guard fix (skip targeted). Gate: channel + leak + isolation tests.
- P4 Triggers: coupon activation, registration, address CUD, order-completed post-metrics, manual — thin
  after-commit dispatchers only (never sync in request). Gate: single-user scoping + double-fire single-run proofs.
- P5 Customer discovery: `GET /general/coupons/available` (auth, paginated ≤50, engine-evaluated, public shell +
  `claim_status`, NO codes, NO internals) + resource + per-user short cache. Gate: parity-with-engine + no-leak tests.
- P6 Admin: `POST /api/v1/admin/coupons/{coupon}/distribute` (202 + `already_running` 409), `GET .../distributions`,
  `GET .../distributions/{run}` — reconciled to dual-route (`/api/v1/coupons` + `/api/v1/admin/coupons`) +
  `permission:` convention. Gate: 403/409/422 proofs.
- P7 Observability: run counters (`candidate/eligible/notified/failed/duplicate_skipped`, started/completed/duration),
  structured logs (`run_id,coupon_id,user_id,tree_hash`, no PII), `coupons:reconcile` distribution detectors,
  retention prune. Gate: counter-reconciliation proofs.
- P8 Validation: full matrix (`COUPON_DISTRIBUTION_TEST_PLAN.md`) + coupon suite + order/payment subset + static
  (`php -l`, queue-convention test) + MySQL concurrency + security + perf review + final report (§47).
- Test baseline: NOT recorded in Phase 0 (read-only; no test executed). P8 records `baseline → targeted → suite →
  PASS/FAIL/PRE-EXISTING` without touching unrelated failures.

## 21. Risks

- Over-notification without dedupe (mit: §16). Capacity race confusion (mit: advisory copy + authoritative claim).
- Global code leak reuse (mit: §4 fix + §18 review). `isPublic()` flip via auto-assign (mit: hybrid model forbids it).
- Supervisor saturation on first mega-run (mit: feature flag + audience-cap + medium-queue + staged triggers).
- Stale metrics at evaluation (mit: order trigger fires AFTER rebuild commit; activation runs accept point-in-time
  semantics + claim revalidates). Missing `last_order_at` index (mit: P0 migration). Notification JSON lookup cost
  (mit: app-level guard or functional index per EXPLAIN). Scope creep into Marvel rewrite/pricing/tax/shipping/
  payment (mit: frozen-semantics gates each phase).

## 22. Open business decisions (REQUIRED before/early in build)

1. Per-user eligible notification: include `coupon_code` (RECOMMENDED, mirrors `/mine` owner-code rule) vs code-hidden
   (claim-first)? 2. Giza→Alexandria / re-enable / capacity-race customer copy + revocation-notifications explicitly
   OUT unless ordered? 3. Admin permission: reuse `update-coupon` vs new `distribute-coupon`? 4. high-vs-medium split
   for chunk dispatch + audience-cap default + retention window (propose 90d)? 5. `available` cache TTL (propose 60s)
   + limit default (propose 15, max 50)? 6. Mass-grant campaigns: always explicit admin action with quota (never
   distributor auto-assign) — confirm. No code begins until (1)–(3) are answered; (4)–(5) may be tuned with P2/P7 data.

---
*Evidence index (all read, none modified): `config/queue.php`, `config/broadcasting.php`, `.env.example`,
`composer.json`, `app/Console/Kernel.php`, `deploy/supervisor/*.conf`, `docs/RABBITMQ_AUDIT.md`,
`docs/RABBITMQ_REMOVAL_REPORT.md`, `app/Services/Coupon/Eligibility/EligibilityEngine.php`,
`app/Services/Coupon/RuleTreeValidator.php`, `app/Enums/EligibilityRuleType.php`,
`app/Services/Coupon/CouponOrchestrator.php`, `CouponValidator.php`, `CouponAssignmentValidator.php`,
`app/Services/Coupon/CouponClaimService.php`, `CouponReservationService.php`, `CustomerMetricsService.php`,
`General/CouponService.php`, `General/CouponController.php`, `General/OrderService.php` (consumption/metrics points),
`Events/{CouponAssigned,CouponCreated,PaymentSucceeded}.php`, `Listeners/SendUserCoupon{Assigned,Available}Notification.php`,
`Listeners/Coupon/MarkCouponClaimRedeemed.php`, `Notifications/UserCoupon{Assigned,Available}Notification.php`,
`Notifications/Channels/FcmChannel.php`, `Jobs/SendFcmNotificationJob.php`, `Observers/CouponObserver.php`,
`Providers/EventServiceProvider.php`, `routes/channels.php`, `routes/api.php` (coupon blocks),
`Resources/Coupons/CouponResource.php`, Marvel `Models/{Coupon,CouponTargeting,CouponAssignment,CouponClaim,
CouponUsage,CustomerMetrics,User,Address,Governorate}.php`, `Repositories/CouponAssignmentRepository.php`,
migrations `2026_07_05_080106`, `2026_07_15_000003`, `2026_09_10_000002/000003`, `2026_09_14_000001/000003`,
`2026_09_28_000001`, `2026_08_31_120100`, `2022_04_11_094659`, `2019_08_19_000000`, `api-desc/notification/backend.md`,
`api-desc/moblieNotifecation/README.md`, `COUPON_FRONTEND_CONTRACT.md`.*
