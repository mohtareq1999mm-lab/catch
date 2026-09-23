# COUPON DISTRIBUTION — TRANSPORT CONTAINER FAILURE AUDIT

> READ-ONLY audit. No code, config, database, queue, or infrastructure was modified.
> Labels used throughout: **VERIFIED** (direct repo/runtime evidence),
> **INFERRED** (strong multi-signal reasoning, not directly observed in production),
> **UNVERIFIED** (cannot be confirmed from this environment).

---

## 1. Executive Summary

- **Primary failure (VERIFIED in code, INFERRED in production runtime):** the
  production queue worker's Laravel container has **no binding** for the
  `CouponEventTransport` interface, so building the queued coupon-distribution
  listener chain throws `BindingResolutionException` ("Target … is not
  instantiable").
- **The binding is NOT missing from source (VERIFIED).** `app/Providers/
  CouponDistributionServiceProvider.php` registers a `singleton(
  CouponEventTransport::class, …)` and is listed in `config/app.php`
  `'providers'` (line 176). The local `bootstrap/cache/services.php` manifest
  contains the provider (lines 75, 138).
- **Most probable production cause (INFERRED, high confidence): STALE
  `bootstrap/cache/services.php` (and/or `config.php`) on production, and/or
  queue workers (`queue:work` / supervisor) not restarted after the deploy
  that introduced the coupon-distribution subsystem.** Long-lived worker
  processes boot the container once; a worker booted from a pre-change cache
  (or pre-change code) never runs the new provider, so the interface is
  unresolvable in exactly the observed `WorkCommand → CallQueuedListener → …
  → CouponOutboxService → CouponEventTransport` chain.
- **Secondary failure (VERIFIED at code level):** `App\Notifications\
  AdminQueueJobFailedNotification::toDatabase()` contains a malformed PHP
  `\u{…}` escape (`\u{0627` missing its closing `}` on line 42). Evaluating
  the Arabic string throws `Invalid UTF-8 codepoint escape sequence`, which is
  caught by `HandleFailedQueueJob` and logged as `Failed to deliver
  queue-failure admin alert`. It masks admin alerting only; it is NOT the
  cause of the distribution failure.
- **Root-cause classification: G (stale Laravel cache) primary, with F
  (production deployment inconsistency — cache not rebuilt / workers not
  restarted) as the operational mechanism.** See §14.
- **Existing runs/outbox rows are safe to retry (INFERRED, §13):** the failure
  occurs during service *construction* (before the DB transaction), so no
  partial run/outbox state is created by this exact throw; the run dedupe
  unique key and the outbox pending/sweep design make manual retry safe. No
  RabbitMQ publish can have happened on this code path.

---

## 2. Exact Root Cause

**[INFERRED — high confidence; production runtime not directly observable
from this environment]**

Laravel tried to resolve the interface
`App\Services\Coupon\Distribution\Messaging\CouponEventTransport` while
constructing the queued `StartCouponDistribution` listener inside a production
queue worker, found no container binding for it in that worker's booted
container, and threw `BindingResolutionException`.

The binding *exists in the current source tree* but was *not active in the
failing worker process*. The only mechanism consistent with all evidence is:

1. Commit `79aed38` (2026-09-23) introduced the interface, both
   implementations, the service provider, AND its `config/app.php`
   registration **atomically** (VERIFIED via `git show`).
2. `bootstrap/cache/*` is **git-ignored** (VERIFIED: `bootstrap/cache/.gitignore`
   contains `*`; only `.gitignore` is tracked). Production cache manifests are
   therefore generated at deploy time, not shipped from git.
3. If production's `bootstrap/cache/services.php` was generated **before**
   that commit was deployed — or the deploy updated code without re-running
   `php artisan optimize` (or at least rebuilding the manifest) **and**
   restarting the long-lived queue workers / supervisors — the running worker
   container never executes `CouponDistributionServiceProvider::register()`,
   so `CouponEventTransport` has no binding in that process.
4. The observed chain starts at `Illuminate\Queue\Console\WorkCommand`, which
   proves the throw happened **inside a queue worker process**
   (VERIFIED from the error string), the exact process class most vulnerable
   to stale-boot state.

**Ruled out as the cause (VERIFIED):** missing binding in source, missing
provider in source, provider not registered in source, namespace/class
mismatch, rename/move, environment-conditional binding, RabbitMQ
credentials/config (the transport constructor takes no config — connection is
lazy), and composer autoload (a missing class would say "class not found",
not "target is not instantiable").

---

## 3. Evidence

| # | Fact | Status | Source |
|---|------|--------|--------|
| E1 | `CouponEventTransport` is a PHP `interface` with 5 methods (`publish`, `consume`, `declareTopology`, `isHealthy`, `close`) | VERIFIED | `app/Services/Coupon/Distribution/Messaging/CouponEventTransport.php` (52 lines, read verbatim) |
| E2 | Two implementations exist: `RabbitMqCouponEventTransport` (production, php-amqplib, lazy connection) and `FakeCouponEventTransport` (tests/broker-less) | VERIFIED | Files read; both declare `implements CouponEventTransport` |
| E3 | `CouponDistributionServiceProvider::register()` binds `singleton(CouponEventTransport::class, …)` unconditionally (only branch: unit-test fake) | VERIFIED | `app/Providers/CouponDistributionServiceProvider.php` read verbatim |
| E4 | Provider is registered in `config/app.php` `'providers'`, line 176 | VERIFIED | `config/app.php` read verbatim |
| E5 | Local `bootstrap/cache/services.php` manifest contains the provider (2 entries: provider list + eager/deferred map) | VERIFIED | `Select-String` hit at lines 75, 138 |
| E6 | Interface + provider + registration were introduced atomically in commit `79aed38` (2026-09-23) | VERIFIED | `git log -- <paths>` + `git show 79aed38 -- config/app.php` diff |
| E7 | `bootstrap/cache/*` is git-ignored; production manifests are deploy-generated | VERIFIED | `git check-ignore -v` (matched `bootstrap/cache/.gitignore:1:*`); `git ls-files bootstrap/cache` → only `.gitignore` |
| E8 | Failure chain starts at `WorkCommand` → queued listener → distribution services | VERIFIED | Error string in the task report |
| E9 | `CouponOutboxService.__construct` requires `CouponEventTransport`; `DistributionRunService` requires `CouponOutboxService`; `DistributionService` requires both | VERIFIED | Constructor signatures read verbatim |
| E10 | `StartCouponDistribution` is `ShouldQueue` and calls `app(DistributionService::class)` inside `handle()` | VERIFIED | `app/Listeners/Coupons/StartCouponDistribution.php` read verbatim |
| E11 | Malformed `\u{0627` escape in `AdminQueueJobFailedNotification.php:42` | VERIFIED | File read verbatim (see §11) |
| E12 | Production cache contents / worker boot time / deploy log | UNVERIFIED | No production access from this environment |

---

## 4. Dependency Chain

Failure chain from the production error (outermost → innermost):

```text
Illuminate\Queue\Console\WorkCommand          (queue worker daemon)
    ↓  pops a queued job for the failed listener
Illuminate\Events\CallQueuedListener          (re-hydrates StartCouponDistribution)
    ↓  listener calls app(DistributionService::class)
App\Services\Coupon\Distribution\DistributionService
    (__construct: DistributionRunService $runs, CouponOutboxService $outbox)
    ↓  needs DistributionRunService
App\Services\Coupon\Distribution\DistributionRunService
    (__construct: CouponOutboxService $outbox, CouponEventLogService $eventLog)
    ↓  needs CouponOutboxService
App\Services\Coupon\Distribution\Outbox\CouponOutboxService
    (__construct: CouponEventTransport $transport, CouponEventLogService $eventLog)
    ↓  needs CouponEventTransport  ← ✗ RESOLUTION FAILS HERE
App\Services\Coupon\Distribution\Messaging\CouponEventTransport  (interface)
```

Beginner-friendly explanation:

```text
Laravel tries to construct CouponOutboxService.

CouponOutboxService requires CouponEventTransport.

Laravel asks the Container:
"What concrete class should I create for CouponEventTransport?"

The answer lives in CouponDistributionServiceProvider::register()
("when someone asks for the interface, build RabbitMqCouponEventTransport").

In the failing production worker, that provider never ran —
its manifest entry is stale/missing in that process —
so the Container has no answer.

Therefore: BindingResolutionException
"Target [CouponEventTransport] is not instantiable".
```

Why *these* services and nothing else: they are the only production
construction path that type-hints the new interface (VERIFIED by repo-wide
search — see §5). Every other service resolves because its bindings predate
the change.

---

## 5. Contract / Implementation Map

### 5.1 The contract (Phase 1)

```text
Type:      interface            [VERIFIED]
File:      app/Services/Coupon/Distribution/Messaging/CouponEventTransport.php
Namespace: App\Services\Coupon\Distribution\Messaging
Methods:
  - publish(CouponEventEnvelope $envelope): void
  - consume(string $queue, callable $handler, int $maxMessages = 0, int $maxSeconds = 0): int
  - declareTopology(): void
  - isHealthy(): bool
  - close(): void
```

Docblock intent (VERIFIED): "RabbitMQ is the production implementation. Tests
and local environments without a broker bind the fake — application code never
touches AMQP directly."

### 5.2 Reference map (Phase 2) — every `CouponEventTransport` mention in `app/`

| File | Kind |
|------|------|
| `Services/Coupon/Distribution/Messaging/CouponEventTransport.php` | DECLARATION (interface) |
| `Services/Coupon/Distribution/Messaging/RabbitMqCouponEventTransport.php` | IMPLEMENTATION (`implements`) |
| `Services/Coupon/Distribution/Messaging/FakeCouponEventTransport.php` | IMPLEMENTATION (`implements`, tests) |
| `Services/Coupon/Distribution/Outbox/CouponOutboxService.php:36` | CONSTRUCTOR INJECTION (`private readonly CouponEventTransport $transport`) |
| `Services/Coupon/Distribution/Messaging/RabbitMqHealthService.php:15` | CONSTRUCTOR INJECTION |
| `Services/Coupon/Distribution/Messaging/ConsumeResult.php:6` | DOC COMMENT reference |
| `Providers/CouponDistributionServiceProvider.php:5,19-24` | IMPORT + CONTAINER BINDING (singleton) |
| `Console/Commands/Coupons/ConsumeCouponQueueCommand.php:17,42` | IMPORT + METHOD INJECTION (`handle(CouponEventTransport $transport, …)`) |
| `Console/Commands/Coupons/RabbitMqSetupCommand.php:5,14` | IMPORT + METHOD INJECTION |
| `tests/Feature/CouponDistribution/*` (4 files) | TEST BINDING of `FakeCouponEventTransport` via `$this->app->singleton(Fake…)` + `runningUnitTests()` branch in provider |

No references in `packages/`, `routes/`, `bootstrap/` (VERIFIED by scoped
searches). No rename/move: single introducing commit (VERIFIED, §9).

### 5.3 Implementations (Phase 3)

**`RabbitMqCouponEventTransport`** (production):

```text
Class:         App\Services\Coupon\Distribution\Messaging\RabbitMqCouponEventTransport
File:          app/Services/Coupon/Distribution/Messaging/RabbitMqCouponEventTransport.php
Implements:    CouponEventTransport
Constructor:   none (implicit) — connection is LAZY via channel()/openConnection()
Config use:    only at USE time (config('rabbitmq.*')), never at construct time
Provider:      built by CouponDistributionServiceProvider closure via `new RabbitMqCouponEventTransport()`
```

**`FakeCouponEventTransport`** (tests / broker-less):

```text
Class:         App\Services\Coupon\Distribution\Messaging\FakeCouponEventTransport
File:          app/Services/Coupon/Distribution/Messaging/FakeCouponEventTransport.php
Implements:    CouponEventTransport
Constructor:   none; in-memory $published/$queues/$deadLettered + $healthy/$unreachable flags
Provider:      singleton registered in same provider; selected only when
               $app->runningUnitTests() && $app->bound(FakeCouponEventTransport::class)
Tests:         4 test files bind the fake explicitly in setUp()
```

**Production-implementation existence: VERIFIED present in this codebase.**
Whether the production deploy contains it is UNVERIFIED (no prod access) —
but the error chain proves the *consumers* of the transport (`Distribution*`,
`CouponOutboxService`) ARE deployed, which strongly implies the same deploy
unit (same commit) shipped the transport files too; the missing piece is the
*runtime binding*, not the files (INFERRED).

---

## 6. Container Binding Map (Phase 4)

```text
Contract:      App\Services\Coupon\Distribution\Messaging\CouponEventTransport
    ↓
Expected impl: RabbitMqCouponEventTransport (production)
               FakeCouponEventTransport    (only under runningUnitTests + explicit test binding)
    ↓
Binding:       $this->app->singleton(CouponEventTransport::class, closure)  [VERIFIED]
Location:      app/Providers/CouponDistributionServiceProvider.php :: register(), lines 19-25
               + singleton(FakeCouponEventTransport::class, …) line 27
    ↓
Provider:      App\Providers\CouponDistributionServiceProvider
    ↓
Registration:  config/app.php 'providers' array, line 176  [VERIFIED]
               (this app uses legacy bootstrap/app.php + config/app.php provider loading — VERIFIED)
```

Binding-character audit (VERIFIED from provider source):

- NOT environment-conditional: no `env()`/`isProduction()` gate around the
  binding. The only condition is the unit-test fake branch.
- NOT deferred: no `provides()`/`$defer`; provider is eager (also present in
  the eager section of local `services.php`).
- `mergeConfigFrom` for `rabbitmq` + `coupon-distribution` precedes the
  binding; a missing config file would not remove the binding (defaults exist
  in `RabbitMqTopology` and transport).

**If no binding exists, state it explicitly:** in the *current source tree* a
valid binding EXISTS. The production worker that threw demonstrably had NO
binding loaded — the defect is runtime staleness, not source absence.

---

## 7. Service Provider Audit (Phase 5)

- **Present (VERIFIED):** `app/Providers/CouponDistributionServiceProvider.php`
  exists with correct namespace `App\Providers`, correct imports, and a valid
  `register()` method.
- **Registered (VERIFIED in source):** `config/app.php:176`
  `App\Providers\CouponDistributionServiceProvider::class`. Bootstrap is the
  legacy style (`bootstrap/app.php` returns `new Illuminate\Foundation\
  Application`, no `Application::configure()->withProviders()`), so
  `config/app.php` IS the authoritative provider list — no second registration
  path to miss.
- **Loaded in production: UNVERIFIED / suspected NO in the failing worker.**
  Local `bootstrap/cache/services.php` (deploy-generated, git-ignored) lists
  the provider, proving the manifest mechanism picks it up when rebuilt. The
  production manifest state cannot be observed from here; the exception itself
  is the evidence that the provider did not run in that worker process.
- **No environment-specific provider loading** found (VERIFIED: no
  `when()`/`environment()` gating in provider or bootstrap).
- **Composer package discovery:** not relevant — this is an application
  provider, not a package provider (`bootstrap/cache/packages.php` has no
  coupon entry, as expected — VERIFIED).

---

## 8. RabbitMQ / Messaging Flow (Phase 7)

Intended role of `CouponEventTransport` (VERIFIED from interface docblock +
`RabbitMqCouponEventTransport` source):

- It is an **abstraction over RabbitMQ** (domain-event transport contract),
  NOT a Laravel queue transport. The Laravel `database` queue is untouched;
  RabbitMQ carries ONLY coupon-distribution domain events.
- Production implementation: php-amqplib; durable **topic** exchange
  `coupon.events` (default, `config('rabbitmq.exchange')`), routing key =
  event type via `RabbitMqTopology::routingKeyFor()`; persistent messages;
  publisher confirms; manual ack + prefetch; TTL retry queues + per-queue DLQs
  on DLX `coupon.dlx`; lazy/recovered connection surfacing
  `BrokerUnreachableException` so the outbox keeps rows pending.

Flow:

```text
DistributionService::startDistribution / DistributionRunService::maybeFinishRun
    ↓  CouponOutboxService::recordAndDispatch (inside business DB transaction)
coupon_outbox row (PENDING) + PublishCouponOutboxJob::dispatch()->afterCommit()
    ↓  job / per-minute sweep (coupons:publish-outbox --batch=100)
CouponOutboxService::publishOne — claims row (bounded lease 300s), then:
    ↓  CouponEventTransport::publish(envelope->toJson())
RabbitMqCouponEventTransport → AMQPMessage → exchange `coupon.events`
    ↓  routing key = event type
coupon.distribution | coupon.evaluation | coupon.notifications (+ .retry / .dlq)
    ↓  coupon:consume (one supervised process per logical queue, see
       deploy/supervisor/laravel-coupon-consumers.conf)
DistributionStartHandler / DistributionChunkHandler / UserEvaluateHandler /
NotificationRequestHandler (idempotent on event_id)
```

RabbitMQ config surface (VERIFIED, `config/rabbitmq.php` + `.env.example`
entries referenced): `RABBITMQ_HOST/PORT/VHOST/USERNAME/PASSWORD/TLS`,
timeouts, heartbeat, publisher confirms, prefetch, `RABBITMQ_EXCHANGE`
(default `coupon.events`), `RABBITMQ_DLX_EXCHANGE`, per-queue names.
**RabbitMQ connectivity is NOT implicated in this failure:** the throw happens
at *interface resolution* (container build time), before any connection is
attempted — and the transport constructor takes no config at all (VERIFIED).

---

## 9. Production vs Local Comparison (Phase 8)

| Aspect | Local (this checkout) | Production |
|--------|----------------------|------------|
| Contract exists | YES (VERIFIED) | INFERRED yes (consumers deployed, same commit unit) |
| Implementations exist | YES (VERIFIED) | INFERRED yes (same reasoning) |
| Provider exists | YES (VERIFIED) | INFERRED yes |
| `config/app.php` registration | YES, line 176 (VERIFIED) | INFERRED yes IF deploy includes commit `79aed38`; else NO — UNVERIFIED |
| `bootstrap/cache/services.php` contains provider | YES (VERIFIED, rebuilt 2026-09-22) | UNVERIFIED — **prime suspect for staleness** |
| Composer autoload | OK (class found by tooling; no "class not found") | No autoload error observed → OK (INFERRED) |
| Env-conditional binding | None (VERIFIED) | N/A — same code |
| Worker boot state | N/A (no daemon running here) | UNVERIFIED — restart history unknown |

Do NOT assume production is wrong because local works: the claim here is
narrower — local source is self-consistent, and the production error is
exactly what a stale-boot worker would throw (INFERRED, high confidence).

---

## 10. Cache / Deployment Findings (Phase 9)

- `bootstrap/` contains only `app.php` + `cache/.gitignore` in git; local
  `bootstrap/cache/` holds deploy-generated `packages.php` (2026-09-22)
  and `services.php` (2026-09-22, WITH the provider). No `config.php` cache
  present locally (VERIFIED via directory listing).
- Because cache files are git-ignored, **every deploy must rebuild them**
  (`php artisan optimize`, or `config:cache`/`route:cache`/`view:cache` +
  manifest rebuild) AND **restart all long-lived processes**: `queue:work`
  daemons (the failing `WorkCommand`), scheduler, and the three
  `coupon:consume` supervisors (`deploy/supervisor/laravel-coupon-consumers.
  conf` exists — VERIFIED).
- Stale-cache plausibility: **HIGH (INFERRED).** Laravel loads the provider
  manifest from `bootstrap/cache/services.php` when present; a manifest built
  before `79aed38` silently drops the new provider with exactly this symptom.
  A code-only deploy (rsync/git-pull without `optimize` + worker restart)
  reproduces the production error deterministically.
- Nothing was cleared or modified during this audit (read-only honored).

---

## 11. Git History Findings (Phase 10)

- `git log -- app/Providers/CouponDistributionServiceProvider.php` →
  single commit `79aed38` "feat: add coupon lifecycle events and exceptions
  for distribution management" (2026-09-23) (VERIFIED).
- Same commit introduced `CouponEventTransport.php` and the `config/app.php`
  registration (`git show 79aed38 -- config/app.php`: `+ App\Providers\
  CouponDistributionServiceProvider::class`) (VERIFIED).
- No later commit removes/renames the binding (VERIFIED: HEAD history shows
  no touch to the provider file after `79aed38`; working tree clean for these
  paths — `git status --short` shows only unrelated fulfillment/progress-doc
  edits).
- `AdminQueueJobFailedNotification.php` history: `47ff3d8`, `fcbcad0` —
  the UTF-8 typo predates/arrives independently of the distribution change
  (VERIFIED filenames in log; line-level blame not run — read-only kept
  minimal).
- Deployment-incomplete-change risk: **any production deploy checked out
  before `79aed38` for `config/app.php`/provider while serving new queue
  payloads (or vice versa) yields this exact failure.** Which side is stale
  in production is UNVERIFIED.

---

## 12. Secondary UTF-8 Failure (Phase 11)

Failing log: `Failed to deliver queue-failure admin alert` /
`Invalid UTF-8 codepoint escape sequence`.

1. **Sender (VERIFIED):** `App\Listeners\HandleFailedQueueJob::handle()`
   (listens on `Illuminate\Queue\Events\JobFailed`, wired in
   `EventServiceProvider::$listen`). On any job's final failure it builds
   `new AdminQueueJobFailedNotification($jobName, $queue, $message)` and
   `Notification::send()`s it to super-admins inside try/catch; the catch
   logs exactly the observed warning.
2. **Data encoded (VERIFIED):** `toDatabase()` returns `title`/`message`
   arrays; the Arabic `message` on **line 42** reads (verbatim):

   ```php
   'ar' => "…\u{0639}\u{0644}\u{0649} \u{0627}\u{0644}\u{0642}\u{0627\u{0626}\u{0645}\u{0629}} [{$this->queue}]",
   //                                                  ^^^^^^^ missing closing brace
   ```

   The sequence `\u{0627` is unterminated (should be `\u{0627}`). PHP ≥7
   interprets `\u{…}` in double-quoted strings as a Unicode codepoint escape;
   the malformed sequence raises `Invalid UTF-8 codepoint escape sequence`
   when the string is evaluated (i.e., when `toDatabase()` runs, interpolating
   `$this->jobName`/`$this->queue`).
3. **Which value is "invalid" (VERIFIED at code level):** the literal Arabic
   template itself — NOT the exception message, NOT user data. Any delivery
   attempt of this notification (for ANY failed job) throws, which is why the
   admin alert for the coupon failure also failed.
4. **Layer (VERIFIED):** notification-payload formatting
   (`AdminQueueJobFailedNotification::toDatabase()`), surfaced through the
   `database` notification channel (and `toBroadcast()` reuses the same
   payload). Not `json_encode`, not Pusher/FCM, not the log context.
5. **Blast radius of the secondary bug:** admin `database` notifications for
   ALL queue failures are broken until the brace is fixed; the catch block
   correctly preserves the original failure log line, so no failure data is
   lost — only the admin inbox alert.

---

## 13. Blast Radius (Phase 12)

| Flow | Status | Reason |
|------|--------|--------|
| `coupon_activated` → distribution (queued `StartCouponDistribution`) | **AFFECTED** (VERIFIED by prod log: coupon_id 18) | Listener cannot be built in worker |
| `targeting_changed` → distribution (same listener) | **AFFECTED** (INFERRED — same code path) | Same listener class |
| Per-user triggers (`user_registered`, `address_changed`, `order_completed` via `DistributionTriggerService → DistributionService`) | **AFFECTED** (INFERRED) | Same `DistributionService` construction |
| Manual distribution `POST /{id}/distribute` (`CouponDistributionAdminController::distribute` → `DistributionService`) | **AFFECTED** (INFERRED) | Same service; fails whenever container lacks binding (HTTP worker booted stale too) |
| Outbox immediate publish (`PublishCouponOutboxJob`) + per-minute sweep (`coupons:publish-outbox`) | **AFFECTED** (INFERRED) | Job/service needs `CouponOutboxService` → transport |
| RabbitMQ consumers (`coupon:consume`), setup (`rabbitmq:setup`), health checks | **AFFECTED** (INFERRED) | Method-inject the interface |
| `PUT /{id}/targeting` (targeting CRUD) | **NOT AFFECTED** (INFERRED) | `CouponTargetingController` path does not construct distribution services (no transport type-hint found on that path) |
| Distribution listing/detail (`GET …/distributions`) | **NOT AFFECTED** (INFERRED) | Read-only queries over `CouponDistributionRun`; no transport injection |
| Coupon CRUD / claim / reservation / eligibility reads | **NOT AFFECTED** (INFERRED) | No transport in their construction paths per search |
| `coupon.eligible` notification sending for already-evaluated users | **UNKNOWN** | Depends on whether pending outbox rows predate the bad deploy and whether consumers ever ran |

Coupon functionality as a whole is NOT broken — only the **distribution fan-out
plane** (anything that must construct `DistributionService`/
`DistributionRunService`/`CouponOutboxService`/transport).

---

## 14. Existing Run / Outbox Safety (Phase 13)

- **The observed throw happens BEFORE any business write (INFERRED, high
  confidence):** `app(DistributionService::class)` throws during constructor
  injection, so `startDistribution()`'s `DB::transaction()` (run insert +
  outbox `recordAndDispatch`) never starts. For coupon 18 / trigger
  `coupon_activated`: **no run row and no outbox row were created by this
  attempt** — nothing half-written.
- **Therefore no `FAILED` run needs reconciling for this attempt; retry is
  safe (INFERRED):** a fresh trigger (or re-fire of `CouponActivated`) will
  go through `startOrJoin()` dedupe (`dedupe_key = coupon:tree:trigger:scope`
  unique) — concurrent/duplicate starts converge on one run.
- **Outbox rows that DO exist (from any earlier successful path) stay
  `PENDING` with backoff; broker-down semantics keep them pending, never
  failed (VERIFIED in `CouponOutboxService::publishOne`: `BrokerUnreachable…
  → PENDING`; generic errors → `PENDING` until `outbox_max_attempts`=25, then
  `FAILED`).** But note: while the binding is missing, even the sweep job
  itself cannot be constructed, so pending rows simply wait (INFERRED).
- **RabbitMQ: nothing was published on this code path (VERIFIED by code
  order — publish happens after construction).** No duplicate-message risk
  from this failure; consumer idempotency on `event_id` covers any later
  redelivery anyway (VERIFIED docblock + `publishOne` claim lease).
- **Manual retry safety: SAFE (INFERRED)** — dedupe key + outbox idempotency
  + lease-claimed publishing. Do NOT hand-insert runs/outbox rows.

---

## 15. Root Cause Classification (Phase 14)

```text
A. Missing container binding .......... NO  (binding present in source — VERIFIED)
B. Missing Service Provider ........... NO  (file present — VERIFIED)
C. Provider not registered ............ NO  (in config/app.php — VERIFIED, in source)
D. Missing implementation ............. NO  (RabbitMq impl present — VERIFIED)
E. Namespace/class mismatch ........... NO  (namespaces consistent — VERIFIED)
F. Production deployment inconsistency  YES (contributing — INFERRED)
G. Stale Laravel cache ................ YES (primary — INFERRED, high confidence)
H. Environment/config conditional ..... NO  (binding unconditional — VERIFIED)
I. Composer/autoload problem .......... NO  (would be "class not found" — VERIFIED reasoning)
J. Other .............................. Secondary bug only (UTF-8 typo — VERIFIED, separate)
```

**Final: G primary + F operational mechanism.** The deploy delivered the new
consumers without activating the new provider in the running workers
(stale `bootstrap/cache/services.php` and/or workers not restarted).

---

## 16. Recommended Fix Plan (Phase 15) — NOT IMPLEMENTED

```text
ROOT CAUSE
  Stale provider manifest / stale-booted queue workers after commit 79aed38.

WHY IT HAPPENS
  bootstrap/cache/* is git-ignored and must be rebuilt per deploy; queue:work
  processes boot once and never see new providers until restarted.

MINIMAL FIX (production, in order)
  1. Verify deploy contains commit 79aed38 (provider + config/app.php entry).
  2. Rebuild framework caches:
       php artisan optimize:clear
       php artisan optimize            (or config:cache + route:cache + view:cache
                                       per project deploy script — check runbook first)
  3. Restart ALL long-lived processes:
       supervisorctl restart laravel-worker-*:*
       supervisorctl restart coupon consumers (deploy/supervisor/laravel-coupon-consumers.conf)
     (php artisan queue:restart only restarts queue:work daemons, NOT coupon:consume
      processes — restart those via supervisor explicitly.)
  4. Fix the secondary UTF-8 typo (one character) in
     app/Notifications/AdminQueueJobFailedNotification.php:42:
       \u{0627\u{0626}  →  \u{0627}\u{0626}
     (deploy with the normal release; no hot-edit on prod.)
  5. Re-fire distribution for coupon 18 (re-dispatch CouponActivated or call
     POST /api/v1/coupons/18/distribute); dedupe makes this safe.

FILES THAT WOULD CHANGE (single follow-up release)
  - app/Notifications/AdminQueueJobFailedNotification.php (1 line — the missing `}`)
  - (optional, test-only hardening) regression test asserting toDatabase()
    payload builds for Arabic locale.
  - NO architecture changes. NO RabbitMQ replacement. NO outbox bypass.
    NO direct concrete injection into business services. NO new providers.

CONFIG CHANGES
  - None required. Verify RABBITMQ_* env present for actual publishing
    (the provider's boot() warns on empty password in production), but env
    is unrelated to the container failure.

CACHE/DEPLOYMENT ACTIONS
  - Rebuild caches + restart workers/supervisors as above.
  - Add/verify deploy-script steps: `php artisan optimize` (or explicit
    cache rebuilds) AND `queue:restart` AND supervisor restarts for
    coupon consumers, so the next provider-adding deploy cannot regress.

VERIFICATION
  - tinker/artisan: app(CouponEventTransport::class) resolves to
    RabbitMqCouponEventTransport in production console.
  - grep bootstrap/cache/services.php for CouponDistributionServiceProvider.
  - coupon:consume --max-messages / rabbitmq:setup dry checks pass.
  - Re-run failed trigger for coupon 18; expect coupon.distribution.run_started
    (not distribution_failed); outbox row → PUBLISHED; consumer processes it.
  - Force one test job failure in staging; expect admin database notification
    delivered (UTF-8 fix proven).

ROLLBACK
  - If the rebuilt deploy misbehaves: redeploy previous release tag with the
    same cache-rebuild + worker-restart sequence (stale cache cuts both ways).
  - The UTF-8 one-line fix rolls back independently with zero data impact.
```

---

## 17. Verification Plan (post-fix, for the implementer)

- [ ] `php artisan tinker --execute="echo get_class(app(App\Services\Coupon\Distribution\Messaging\CouponEventTransport::class));"` → `RabbitMqCouponEventTransport`
- [ ] `bootstrap/cache/services.php` contains `CouponDistributionServiceProvider`
- [ ] Existing suites: `tests/Feature/CouponDistribution/*` (Pipeline, Recovery, Trigger, Admin API) green
- [ ] New regression test: Arabic `toDatabase()` payload contains no malformed `\u{}` sequence
- [ ] Staging: full `coupon_activated` → run → outbox → consume → notified loop observed in logs
- [ ] Production: coupon 18 redistributed; `coupon_distribution_runs` has exactly one live run for its dedupe key; outbox rows `PUBLISHED`

---

## 18. Rollback Plan

Covered in §16 (redeploy prior tag + identical cache/worker-restart ritual).
No data migration is involved, so rollback is code+cache only. Do not delete
`coupon_outbox`/`coupon_distribution_runs` rows during rollback — pending rows
are the recovery mechanism.

---

## 19. Open Questions (need production access — all UNVERIFIED)

1. Exact production `bootstrap/cache/services.php` content (does it lack the provider?).
2. Deploy script: does it run `optimize`/`config:cache` and restart workers + coupon consumers?
3. Worker supervisor state: when were `queue:work` / `coupon:consume` processes last (re)started relative to the `79aed38` deploy?
4. Which release/tag is actually running in production (does it include `79aed38` fully)?
5. Current `coupon_distribution_runs` / `coupon_outbox` rows for coupon 18 (confirm nothing partial).
6. RabbitMQ reachability from production (post-fix publishing prerequisite; unrelated to this container failure).

---

## Appendix — Key file pointers (all paths relative to repo root)

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
- `app/Notifications/AdminQueueJobFailedNotification.php` (line 42 — UTF-8 defect)
- `app/Console/Commands/Coupons/ConsumeCouponQueueCommand.php`
- `app/Console/Commands/Coupons/RabbitMqSetupCommand.php`
- `app/Http/Controllers/Api/Admin/CouponDistributionAdminController.php`
- `config/app.php` (line 176), `config/rabbitmq.php`, `config/coupon-distribution.php`
- `bootstrap/app.php`, `bootstrap/cache/services.php` (generated), `bootstrap/cache/packages.php` (generated)
- `packages/marvel/src/Rest/Routes.php` (lines 294-296 — distribution endpoints)
- `deploy/supervisor/laravel-coupon-consumers.conf`
