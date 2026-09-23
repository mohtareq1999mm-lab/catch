# COUPON DISTRIBUTION + ASSIGNMENT NOTIFICATION — PRODUCTION FAILURE AUDIT

> READ-ONLY diagnostic audit. No PHP, providers, bindings, config, database,
> jobs, caches, workers, or processes were modified, restarted, cleared, or
> retried. Nothing was implemented.
>
> Labels: **VERIFIED** = proven from source/config evidence in this checkout.
> **INFERRED** = strongly indicated, not directly observed in production.
> **UNVERIFIED** = needs production runtime/DB access.

---

# Executive Summary

| Failure | Symptom | Root cause | Status |
|---------|---------|------------|--------|
| A — Distribution | `CouponEventTransport is not instantiable` for coupons 7, 8, 11, 12 (trigger `coupon_activated`) | Binding exists in source but was not loaded in the failing queue-worker process: stale `bootstrap/cache/services.php` and/or workers not restarted after commit `79aed38` | Source facts VERIFIED; production runtime mechanism INFERRED (high confidence) |
| B — Assignment notification | Admin assigns coupon with NO targeting → user gets no `coupon.assigned` | **No targeting gate exists** in the assignment path (VERIFIED). Prime suspects: (1) silent user-type guard skip, (2) queued listener never processed (shared `high`-queue workers affected by the same deploy incident), (3) push-only perception gap (no FCM tokens / Pusher not subscribed) | Code facts VERIFIED; which suspect fired is UNVERIFIED (needs prod DB/`failed_jobs`) |
| C — Admin alert | `Failed to deliver queue-failure admin alert: Invalid UTF-8 codepoint escape sequence` | Malformed `\u{0627` escape (missing `}`) in `AdminQueueJobFailedNotification.php:42`; breaks ALL queue-failure admin alerts at payload-build time | VERIFIED at code level |

Failures A and B are **code-independent** (VERIFIED): `CouponEventTransport`
appears nowhere in the assignment path. They share only operational coupling
(the same `high`-queue worker fleet and the same deploy window).

---

# Production Symptoms

- **A:** `coupon.trigger.distribution_failed` warnings with
  `Target [App\Services\Coupon\Distribution\Messaging\CouponEventTransport]
  is not instantiable while building [WorkCommand, CallQueuedListener,
  DistributionService, DistributionRunService, CouponOutboxService]`,
  trigger `coupon_activated`, coupons 7, 8, 11, 12 (previously also 18).
- **B:** Admin `POST coupons/{coupon}/assignments` on a coupon with NO
  targeting succeeds (assignment row created) but the user receives no
  `coupon.assigned` notification.
- **C:** `Failed to deliver queue-failure admin alert` with
  `Invalid UTF-8 codepoint escape sequence` whenever any queue job exhausts
  retries.

---

# Root Cause A — CouponEventTransport

## A.1 Declaration (do NOT assume — inspected)

```text
Type:      interface                                    [VERIFIED]
File:      app/Services/Coupon/Distribution/Messaging/CouponEventTransport.php (52 lines)
Namespace: App\Services\Coupon\Distribution\Messaging
Methods:   publish / consume / declareTopology / isHealthy / close
```

## A.2 Concrete implementations (both exist — VERIFIED)

- `RabbitMqCouponEventTransport` (`implements CouponEventTransport`) — production
  php-amqplib transport. Constructor: **none** (lazy connection); config is
  read only at *use* time. File:
  `app/Services/Coupon/Distribution/Messaging/RabbitMqCouponEventTransport.php`.
- `FakeCouponEventTransport` (`implements CouponEventTransport`) — in-memory,
  tests only. File: `.../Messaging/FakeCouponEventTransport.php`.

## A.3 Binding (exists in source — VERIFIED)

`app/Providers/CouponDistributionServiceProvider.php:19-25`:

```php
$this->app->singleton(CouponEventTransport::class, static function ($app) {
    if ($app->runningUnitTests() && $app->bound(FakeCouponEventTransport::class)) {
        return $app->make(FakeCouponEventTransport::class);
    }
    return new RabbitMqCouponEventTransport();
});
```

- NOT conditional on env; NOT production-only/local-only; no `when()->needs()`,
  no alias, no scoped binding. Only branch is the unit-test fake.
- Provider registered at `config/app.php:176`
  (`App\Providers\CouponDistributionServiceProvider::class`). This app uses
  legacy bootstrap (`bootstrap/app.php` returns `new Application`), so
  `config/app.php` IS authoritative; there is no `bootstrap/providers.php`.
- Local deploy-generated `bootstrap/cache/services.php` contains the provider
  (lines 75, 138). Cache files are git-ignored (`bootstrap/cache/.gitignore`
  = `*`; only `.gitignore` tracked) — production manifests are built at
  deploy time.
- Interface + both implementations + provider + registration introduced
  **atomically in commit `79aed38` (2026-09-23)** — no rename/move since
  (VERIFIED via `git log`/`git show`).

## A.4 Why production throws (INFERRED, high confidence)

The chain starts at `WorkCommand`: the throw happened **inside a long-lived
queue-worker process**. That worker's booted container has no binding for the
interface, i.e. `CouponDistributionServiceProvider::register()` never ran in
that process. Consistent mechanisms (all UNVERIFIED in prod, but the only
explanations fitting EVIDENCE E1–E12 of the prior audit):

1. Production `bootstrap/cache/services.php` predates `79aed38` (deploy
   updated code without `php artisan optimize` / manifest rebuild), and/or
2. Queue workers / supervisors were not restarted after the deploy, so they
   boot the old container.

Ruled out (VERIFIED): missing source binding, missing provider, unregistered
provider, namespace mismatch, rename, env-conditional binding, RabbitMQ
credentials (constructor takes none; failure is at *resolution*, before any
connection), composer autoload (that error would read "class not found").

Masking nuance (VERIFIED by code order): `app(DistributionService::class)`
throws at *construction*, before `startDistribution()`'s targeting-mode
check. So even a non-distributable coupon logs the transport error — the
transport failure masks any targeting verdict for coupons 7/8/11/12.

---

# Root Cause B — Assignment Notification

## B.1 Complete traced chain (all VERIFIED from source)

```text
POST /api/v1/coupons/{coupon}/assignments
  packages/marvel/src/Rest/Routes.php:273-275 (prefix 'coupons/{coupon}')
    ↓  permission:create-coupon-assignment
Marvel\Http\Controllers\CouponAssignmentController::store  (Controller.php:50-62)
    ↓  CouponAssignmentRequest validated (user_id exists, max_uses, expires_at — NO targeting fields)
Marvel\Database\Repositories\CouponAssignmentRepository::assignCoupon  (Repository.php:61-98)
    ↓  Coupon::findOrFail → exists() → 409 → DB::transaction(create+fresh)
       → unique violation → 409 → event(new CouponAssigned($assignment)) AFTER commit (line 95!)
App\Events\CouponAssigned  (Dispatchable, SerializesModels; event itself NOT queued)
    ↓  sync dispatch; EventServiceProvider:199-200 maps CouponAssigned → SendUserCouponAssignedNotification
App\Listeners\SendUserCouponAssignedNotification  (ShouldQueue, viaQueue high())
    ↓  handle(): $assignment->user; guard type; $user->notify(...)
App\Notifications\UserCouponAssignedNotification  (ShouldQueue, onQueue high)
    ↓  via() = [database, fcm, broadcast]
    ├─ database → notifications table row, type coupon.assigned
    ├─ fcm → FcmChannel (falls back to toDatabase payload) → SendFcmNotificationJob (user-scoped tokens)
    └─ broadcast → BroadcastMessage on high queue → private-users.{id} (channels.php:22-24 auth same-user)
```

Transaction/after-commit behavior (VERIFIED):

- Assignment row commits inside `DB::transaction`; the event fires AFTER
  commit (line 95, outside the closure) — no phantom-notify on rollback.
- Duplicate assignment → `409 COUPON_ALREADY_ASSIGNED_TO_USER`, NO event.
- Listener is queued (`high`); the HTTP `201` response does NOT prove the
  notification ran. Listener has no `$tries`/`$backoff`, no `failed()`,
  no try/catch — a throw goes to normal job retry → `failed_jobs`.

## B.2 The critical targeting question — ANSWERED (VERIFIED)

Searches for `targeting|distribut|eligib` return **0 matches** in:

- `CouponAssignmentRepository.php` (whole file),
- `CouponAssigned.php`, `SendUserCouponAssignedNotification.php`,
  `UserCouponAssignedNotification.php` (whole files),
- `CouponAssignmentRequest.php` (validation rules contain no targeting rule).

**There is NO `targeting required` / `targeting != null` condition anywhere
in the assignment notification path.** `coupon.assigned` is code-independent
of targeting, exactly as the business rule states. The observed correlation
("no targeting → no notification") is NOT enforced by code.

## B.3 Then why no notification? Ranked suspects

1. **User-type guard silent skip (INFERRED — prime suspect).**
   `SendUserCouponAssignedNotification::handle()` lines 21-23:
   `if (!$user || $user->type !== UserType::USER->value) return;`
   No log, no error. If the assigned account is `type = 'admin'` (or the
   relation is null), the API still returns 201 but nothing is ever sent.
   Needs prod DB check: `users.type` for the affected `user_id`s.
   (UNVERIFIED.)
2. **Queued listener never ran (INFERRED — strong contextual suspect).**
   Same incident window as Failure A: if the `high`-queue workers are
   stale/down, the listener job sits in `jobs` / dies into `failed_jobs`
   while assignment rows keep being created. Needs prod `jobs`/`failed_jobs`
   inspection for `SendUserCouponAssignedNotification` entries. (UNVERIFIED.)
3. **Push-only perception gap (INFERRED).** Database row may exist while the
   user "receives nothing": no `device_tokens` for the user → FCM job is a
   silent no-op (`SendFcmNotificationJob` chunks zero rows); Pusher requires
   an active `private-users.{id}` subscription with same-user auth. If the
   reporter checked push/realtime only, a healthy database notification is
   invisible. Needs prod `notifications` table + device-token check.
   (UNVERIFIED.)
4. **Duplicate-assignment 409 (lower likelihood, VERIFIED behavior).**
   Second assign attempt notifies nothing by design.

## B.4 Notification-type distinction (VERIFIED from code)

- `coupon.assigned` (`UserCouponAssignedNotification::broadcastType()`,
  channel database+fcm+broadcast, per-assignment) = a specific user received
  an admin grant. Independent of targeting. (VERIFIED)
- `coupon.eligible` (`UserCouponEligibleNotification`, distribution plane) =
  newly eligible via dynamic targeting. (Referenced; separate class.)
- `coupon.used` / `coupon.available` = separate notification classes on the
  same database+fcm+broadcast convention (per discovery docs + lang keys
  `coupon.used` / `coupon.available` present in
  `resources/lang/{en,ar}/notifications.php`).
- No code couples `coupon.assigned` to targeting/distribution (VERIFIED §B.2).

---

# Root Cause C — UTF-8 Admin Failure Alert

1. **Implementation (VERIFIED):** `App\Listeners\HandleFailedQueueJob`
   handles `Illuminate\Queue\Events\JobFailed`, wired globally in
   `EventServiceProvider::$listen` lines 128-130 — **used for ALL queued
   jobs, not coupon-only**. It logs the failure, then tries
   `Notification::send($admins, new AdminQueueJobFailedNotification(
   $jobName, $queue, $message))` inside try/catch; the catch logs exactly
   `Failed to deliver queue-failure admin alert` with `$e->getMessage()`.
2. **Data in (VERIFIED):** job class name, queue name, `$event->exception->
   getMessage()`. No stack trace, no payload (payload intentionally minimal).
3. **Malformed data (VERIFIED at code level):**
   `app/Notifications/AdminQueueJobFailedNotification.php:42` Arabic message
   contains `\u{0627\u{0626}` — `\u{0627` missing its closing `}` (should be
   `\u{0627}\u{0644}\u{0642}\u{0627}\u{0626}\u{0645}\u{0629}`). PHP evaluates
   `\u{…}` escapes in double-quoted strings at runtime (string interpolates
   `$this->jobName`/`$this->queue`), throwing
   `Invalid UTF-8 codepoint escape sequence` from `toDatabase()` — i.e. the
   fault is in the **notification template literal itself**, not in exception
   text, JSON encoding, Pusher, or FCM. It fires for EVERY failed job's
   alert, which is why the coupon failures also produced it.
4. **Masking (VERIFIED design, INFERRED instance):** the original exception
   is preserved in logs + `failed_jobs`; only the admin-inbox alert is lost.
   So C masks A/B in the alert channel but not in logs/DB.
5. **JSON encoding:** no `json_encode` failure is implicated; the throw
   precedes serialization (payload build time, `database` channel).

---

# Complete Distribution Dependency Chain

```text
WorkCommand → CallQueuedListener (StartCouponDistribution, ShouldQueue/high)
  → app(DistributionService)
      DistributionService(distributions: DistributionRunService, outbox: CouponOutboxService)
      DistributionRunService(outbox: CouponOutboxService, eventLog)
      CouponOutboxService(transport: CouponEventTransport ✗, eventLog)
  → BindingResolutionException (in worker w/ stale container)
```

Activation flow detail (VERIFIED): `CouponObserver::created/updated` →
`event(new CouponActivated)` → `StartCouponDistribution@high` →
`DistributionService::startDistribution` (transaction: `startOrJoin` run +
`recordAndDispatch` outbox + `PublishCouponOutboxJob::afterCommit()`) →
per-minute sweep `coupons:publish-outbox` → `transport->publish()` →
exchange `coupon.events`. Because construction fails first, **no run and no
outbox row are created by the failed attempt** (INFERRED, high confidence);
`coupon.eligible` fan-out for dynamic targeting is fully stalled while the
binding is missing (INFERRED).

Manual distribution `POST …/{id}/distribute`
(`CouponDistributionAdminController::distribute:85`
`app(DistributionService::class)->startDistribution(MANUAL, …)`) reaches the
**identical chain** — the same production failure affects manual distribution
whenever the serving worker shares the stale container (VERIFIED trace;
runtime scope INFERRED).

---

# Complete Assignment Notification Chain

See §B.1 diagram. Per-step queue/transaction summary:

| Step | Queue | Transaction | Failure behavior |
|------|-------|-------------|------------------|
| `store` + `assignCoupon` | sync (HTTP) | DB transaction for insert; event after commit | 409 duplicate; 404 no coupon; 422 bad input |
| `CouponAssigned` dispatch | sync | after commit | — |
| `SendUserCouponAssignedNotification` | `high` (database conn) | — | silent skip on type guard; else notify; throws → retries → `failed_jobs` |
| `UserCouponAssignedNotification` | `high` | — | database row; FCM job dispatch; broadcast message |
| `SendFcmNotificationJob` | `frontend.queue` or `high`, tries=3, backoff 30/120 | — | skip if no user id / no tokens; invalid tokens pruned |

Pusher channel (VERIFIED): `User::receivesBroadcastNotificationsOn()` →
`users.{id}` for type `user` (`admin.notifications` for admins);
`routes/channels.php:22-24` authorizes `users.{id}` to the same user id;
Laravel broadcasts on `private-users.{id}`.

---

# Distribution vs Assignment Independence

**`CouponEventTransport` is involved in `coupon.assigned`: NO (VERIFIED).**
Zero references to the transport (or RabbitMQ, outbox, distribution) exist
in the route, controller, request, repository, event, listener, or
notification of the assignment path. Failure B is an **independent failure**
with its own suspects (§B.3). The only shared risk is operational: both
planes consume the same `high`-queue worker fleet, so a worker outage stalls
both simultaneously without any code coupling.

---

# Production vs Local Differences

- Source (this checkout, HEAD `0dfcd6b` lineage incl. `79aed38`): contract,
  both implementations, provider, registration, assignment chain, channels,
  translations (`resources/lang/{en,ar}/notifications.php` incl.
  `coupon.assigned`) all present and consistent (VERIFIED).
- Key files confirmed unmodified in working tree (`git status` clean for all
  six audited files) (VERIFIED).
- Env/config names only (no values read): `RABBITMQ_*`, `QUEUE_HIGH/MEDIUM`,
  `QUEUE_CONNECTION=database`, FCM/Firebase, broadcast/Pusher — RabbitMQ
  carries only distribution events; assignment uses database-queue +
  database/FCM/broadcast notification channels (VERIFIED from config + code).
- No local-vs-prod transport-implementation difference exists in code: prod
  = `RabbitMqCouponEventTransport`, tests = fake via `runningUnitTests()`
  (VERIFIED). Any prod difference is runtime boot state (UNVERIFIED).
- `.env` values, package versions on prod, deployed commit: UNVERIFIED
  (no prod access; `.env.bak-*` explicitly untouched).

---

# Queue / Retry Impact

- Distribution jobs failing with the transport error will exhaust retries
  into `failed_jobs` (listener `StartCouponDistribution` rethrows after
  logging — VERIFIED lines 44-52). Retry before the binding fix is futile
  (same container error); retry after fix is safe (see below).
- Assignment listener jobs (if queued but unprocessed) remain in `jobs`
  until workers consume them — do NOT delete; they are the recovery backlog.
- Admin alert (C) fails for every job failure until the one-character fix
  ships; originals remain in logs + `failed_jobs`.

---

# Database / Outbox / Idempotency Impact

- Distribution: `dedupe_key = coupon:tree:trigger:scope` unique arbitrates
  concurrent starts (losers join); outbox `publishOne` uses bounded lease
  claims (300 s) + backoff, `PENDING` until `outbox_max_attempts`=25;
  consumers idempotent on `event_id`; cross-run `NOTIFIED` state prevents
  duplicate eligibility pushes (VERIFIED in `DistributionService`,
  `DistributionRunService`, `CouponOutboxService`).
- **Retrying distribution after the binding fix cannot duplicate
  eligibility/outbox/Pusher/DB notifications** beyond the designed at-least-
  once envelope (consumers dedupe on `event_id`) (INFERRED from the above
  mechanisms; load test UNVERIFIED).
- Assignment: `unique(coupon_id,user_id)` arbitrates double-assign → 409, no
  event (VERIFIED). Re-running assignment notification for an existing row
  notifies once per explicit re-dispatch — no automatic duplication.
- Failed-job/DB row counts for coupons 7/8/11/12 and assignment jobs:
  UNVERIFIED (no prod DB access; nothing retried or deleted per read-only
  mode).

---

# Blast Radius

| Area | Status |
|------|--------|
| `coupon_activated` / `targeting_changed` distribution | AFFECTED (A) |
| Manual `POST {id}/distribute` | AFFECTED (A, same chain) |
| Per-user triggers, outbox sweep, `coupon:consume`, setup/health commands | AFFECTED (A, INFERRED — same interface) |
| `coupon.eligible` dynamic-targeting fan-out | STALLED while A persists (INFERRED) |
| Assignment `coupon.assigned` for type-`user` recipients | DEGRADED (B, per suspects; NOT caused by A) |
| Targeting CRUD, distribution listing/detail, coupon CRUD, claims | NOT AFFECTED (no transport in path) |
| Queue-failure admin inbox alerts (all jobs) | BROKEN (C) |
| Logs + `failed_jobs` fidelity | INTACT (C masks only the inbox copy) |

---

# VERIFIED Findings

1. `CouponEventTransport` is an interface; 5 methods; exact file/namespace.
2. Two implementations exist; production = `RabbitMqCouponEventTransport`
   (constructor-less, lazy connection).
3. Singleton binding + provider + `config/app.php:176` registration present.
4. All introduced atomically in `79aed38`; no rename; files unmodified since.
5. `bootstrap/cache/*` git-ignored; local manifest contains the provider.
6. Full A chain traced to constructor injection; failure precedes any DB write.
7. Manual distribute reaches the identical distribution chain.
8. Full B chain traced route→controller→request→repository→event→listener→
   notification→database/FCM/broadcast with file:line evidence.
9. NO targeting condition exists anywhere in the assignment path.
10. Assignment event fires after commit; duplicates 409 with no event.
11. User-type guard (`type !== 'user'` → silent return) exists and is unlogged.
12. `UserCouponAssignedNotification` via = database+fcm+broadcast; lang keys
    exist en+ar; FCM falls back to database payload; FCM skips silently
    without tokens; broadcast targets `private-users.{id}` with same-user auth.
13. C defect is the `\u{0627` typo at `AdminQueueJobFailedNotification:42`;
    the alert listener is global (all jobs) and its catch preserves logs.

---

# INFERRED Findings

- A: stale prod provider manifest / un-restarted workers (high confidence).
- A: no run/outbox rows created by failed attempts; retry-after-fix safe.
- B suspects ranked: type-guard skip > unprocessed queued listener (same
  incident window) > push-only perception gap > duplicate-409.
- B: eligible-plane stall does not block assigned-plane code (operational
  coupling via shared workers only).

---

# UNVERIFIED Findings

- Prod `bootstrap/cache/*` contents, deployed commit, worker restart history.
- `users.type` for affected assignees; `jobs`/`failed_jobs`/`notifications`
  rows for assignment + distribution; device tokens; Pusher subscriptions.
- RabbitMQ reachability from prod (post-fix prerequisite, unrelated to A).
- Load-level idempotency proof (mechanisms reviewed, not load-tested).

---

# Minimal Recommended Fixes (NOT implemented)

1. **A:** Deploy includes `79aed38` → `php artisan optimize:clear &&
   php artisan optimize` (or project's cache rebuild) → restart ALL
   long-lived processes (`queue:work` via `queue:restart` PLUS supervisor
   restarts incl. `deploy/supervisor/laravel-coupon-consumers.conf`) →
   confirm `app(CouponEventTransport::class)` resolves in prod console →
   re-fire triggers for coupons 7/8/11/12 (dedupe-safe).
2. **B (diagnose before fixing):** query prod read-replica: assignee
   `users.type`; `jobs`/`failed_jobs` for `SendUserCouponAssignedNotification`;
   `notifications` rows for the assignment ids; device tokens. Most likely
   outcomes map to: guard logging fix, worker recovery (same as A), or
   frontend subscription guidance. Add logging to the type-guard early
   return (one line) so the next skip is observable.
3. **C:** one character in `AdminQueueJobFailedNotification.php:42`:
   `\u{0627\u{0626}` → `\u{0627}\u{0626}` + regression test asserting
   `toDatabase()` builds. No architecture change.
4. No RabbitMQ replacement, no outbox bypass, no direct concrete injection,
   no targeting added to assignment (that would VIOLATE the business rule).

---

# Fix Order

1. C first (one character, unblocks alerting so subsequent work is observed).
2. A second (rebuild caches + restart workers; re-fire coupon triggers).
3. B third (DB-backed diagnosis, then targeted micro-fix; verify per-user).

---

# Post-Fix Validation Plan

- [ ] Tinker (prod console): transport resolves to `RabbitMqCouponEventTransport`.
- [ ] `bootstrap/cache/services.php` contains the provider.
- [ ] `tests/Feature/CouponDistribution/*` green; new tests: Arabic
      `toDatabase()` payload builds; type-guard skip logs.
- [ ] Staging end-to-end: `coupon_activated` → run → outbox PUBLISHED →
      consumed → `coupon.eligible` (where targeting matches).
- [ ] Manual `POST {id}/distribute` → 202, single run per dedupe key.
- [ ] Assignment on a targeting-less coupon to a type-`user` account →
      database row + broadcast received; FCM received where tokens exist.
- [ ] Forced job failure in staging → admin database alert delivered.
- [ ] Coupons 7/8/11/12 redistributed exactly once per dedupe key.

---

# Files Inspected

- `app/Services/Coupon/Distribution/Messaging/CouponEventTransport.php`
- `app/Services/Coupon/Distribution/Messaging/RabbitMqCouponEventTransport.php`
- `app/Services/Coupon/Distribution/Messaging/FakeCouponEventTransport.php`
- `app/Services/Coupon/Distribution/Messaging/RabbitMqTopology.php`
- `app/Services/Coupon/Distribution/Messaging/RabbitMqHealthService.php`
- `app/Providers/CouponDistributionServiceProvider.php`
- `app/Services/Coupon/Distribution/Outbox/CouponOutboxService.php`
- `app/Services/Coupon/Distribution/DistributionService.php`
- `app/Services/Coupon/Distribution/DistributionRunService.php`
- `app/Services/Coupon/Distribution/Triggers/DistributionTriggerService.php`
- `app/Listeners/Coupons/StartCouponDistribution.php`
- `app/Listeners/HandleFailedQueueJob.php`
- `app/Notifications/AdminQueueJobFailedNotification.php`
- `app/Console/Commands/Coupons/ConsumeCouponQueueCommand.php`
- `app/Console/Commands/Coupons/RabbitMqSetupCommand.php`
- `app/Http/Controllers/Api/Admin/CouponDistributionAdminController.php`
- `app/Http/Controllers/Api/Admin/CouponTargetingController.php` (routes only)
- `app/Events/CouponAssigned.php`
- `app/Listeners/SendUserCouponAssignedNotification.php`
- `app/Notifications/UserCouponAssignedNotification.php`
- `app/Notifications/UserCouponEligibleNotification.php` (reference)
- `app/Notifications/Channels/FcmChannel.php`
- `app/Jobs/SendFcmNotificationJob.php`
- `app/Services/Firebase/FcmService.php` (search hits)
- `app/Providers/EventServiceProvider.php`
- `app/Providers/AppServiceProvider.php` (FCM channel registration)
- `app/Observers/CouponObserver.php` (activation dispatch)
- `app/Enums/UserType.php`, `app/Enums/QueueName.php` (search hits)
- `app/Models/DeviceToken.php` (via job reference)
- `packages/marvel/src/Rest/Routes.php` (assignments §273-279, distribute §294-296)
- `packages/marvel/src/Http/Controllers/CouponAssignmentController.php`
- `packages/marvel/src/Http/Requests/CouponAssignmentRequest.php`
- `packages/marvel/src/Database/Repositories/CouponAssignmentRepository.php`
- `packages/marvel/src/Database/Models/User.php` (`receivesBroadcastNotificationsOn`)
- `config/app.php`, `config/queue.php`, `config/rabbitmq.php`, `config/coupon-distribution.php`
- `bootstrap/app.php`, `bootstrap/cache/services.php` (generated), `routes/channels.php`
- `resources/lang/{en,ar}/notifications.php`
- `deploy/supervisor/laravel-coupon-consumers.conf`
- Prior report: `COUPON_DISTRIBUTION_TRANSPORT_FAILURE_AUDIT.md` (Failure A deep evidence E1–E12)

---

# Fin

End of read-only audit. No fixes implemented, no runtime state touched.
Awaiting approval to proceed with the ordered fix plan.
