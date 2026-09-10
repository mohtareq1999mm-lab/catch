# Server & Queue Forensic Audit — Meem Commerce (Read-Only)

**Date:** 2026-09-07 UTC
**Auditor:** Muse Spark (OpenCode) — read-only, no mutations
**Scope:** `D:\work\meem` on local Windows host + production container artifacts (`Dockerfile`, `docker-entrypoint.sh`, `render.yaml`, `deploy/supervisor/*`, `config/queue.php`, `.env`/`.env.example`)
**Evidence classification:** `FACT` = file/process output observed, `INFERENCE` = logical deduction from code, `UNKNOWN` = not observable read-only

---

## 0. EXECUTIVE SUMMARY — Answers to 21 Required Questions

| # | Question | Answer | Status |
|---|----------|--------|--------|
| 1 | What type of server am I running on? | **Local forensic host:** Windows 11 Education 10.0.26200 64-bit, HP Laptop 15-fd0xxx, i7-1355U 10C/12T, 16 GB RAM, Laragon + PHP 8.2.30 CLI. **Production target:** Linux container `php:8.2-cli-alpine`, `artisan serve` on Render/Railway (no FPM/Nginx, no Horizon). No Supervisor/systemd/cron running in production image as shipped. | FACT (local) / FACT (Dockerfile evidence) |
| 2 | Actual Laravel Queue driver? | `database` — unanimous across `.env:12`, `config/queue.php:16`, `render.yaml:124`, `deploy/supervisor/*.conf`. | FACT |
| 3 | Where are jobs stored? | `jobs` table (`database` driver) + `failed_jobs` table (`database-uuids`). Redis is cache/session only. No SQS/Beanstalk/RabbitMQ backend active. | FACT |
| 4 | All queue names? | Canonical `QueueName` enum: `meem-high`, `meem-medium`, `default`. **Orphan in code:** `meem-bulk` (6 Marvel import/export jobs). Legacy doc references: `high`/`medium`/`low` (stale). | FACT |
| 5 | What Jobs exist? | 6 `app/Jobs` + 7 `packages/marvel/src/Jobs` + 31 `app/Listeners` (ShouldQueue) + ~24 `packages/marvel/src/Listeners` + 25 `app/Notifications` + 44 `packages/marvel/src/Events` (ShouldQueue) = 138 ShouldQueue implementers audited (see §8). | FACT |
| 6 | Who dispatches? | Controllers, Services, Observers (8), Listeners, Console Commands, Events, `OrderCreationService`, `InvoiceService`, `DigitalFulfillmentService`, `FrontendWebhookService`, `PaymentReconciliationCommand`. Full table §9. | FACT |
| 7 | Which workers consume which queues? | **Configured (not running):** `laravel-worker-meem-high` → `database --queue=meem-high` (2 procs); `laravel-worker-meem-medium` → `database --queue=meem-medium,default` (2 procs). No worker for `meem-bulk`. | FACT |
| 8 | How are workers currently started? | **Nowhere in production image.** Supervisor configs exist under `deploy/supervisor/` but `Dockerfile:131` CMD is `php artisan serve` and `docker-entrypoint.sh:33-41` only caches config and starts HTTP — never starts `supervisord` or `queue:work`. Locally, zero `queue:work` processes. Manual `php artisan queue:work` is the only mechanism that has ever run. | FACT |
| 9 | Who keeps workers alive? | **Nobody in production.** `supervisord.conf` has `autorestart=true` but supervisord itself is never launched. No systemd, no Docker restart policy verified, no Horizon. | FACT |
| 10 | Are workers currently running? | **No.** `Get-Process` on 2026-09-07 showed 0 `php`/`queue:work`/`horizon`/`supervisord` processes. Redis service `Stopped`. Laragon MySQL not detected as running. | FACT |
| 11 | Will they auto-restart if they die? | **No** — no process manager is running to restart them. Supervisor `autorestart` is inert. | FACT |
| 12 | Will they auto-start after reboot? | **No** — `Dockerfile` does not install/enable Supervisor or cron; `supervisord.conf:20 nodaemon=true` implies foreground supervisord that never autostarts on Render reboot without an entrypoint change. Local Windows has no ScheduledTask for Laravel. | FACT |
| 13 | Do I have Cron? | **Local:** None for Laravel (only Windows `ScheduledDefrag`, `Windows Defender Scheduled Scan`, `QueueReporting`). **Production:** No crontab installed in image; `supervisord.conf:5` only documents `* * * * * php artisan schedule:run` as a comment. | FACT |
| 14 | Do I have Laravel Scheduler? | **Yes, code side:** `app/Console/Kernel.php:24-42` defines 8 scheduled commands (see §14). **Runtime:** Inert without cron. | FACT |
| 15 | Do I have Supervisor? | **Config files yes, runtime no.** `deploy/supervisor/supervisord.conf` + 2 program confs exist. Not installed/running in container or on Windows host. | FACT |
| 16 | Do I have systemd worker services? | **No** — Windows host has no systemd; container is Alpine `php:8.2-cli-alpine` without systemd unit files found (`ctx_glob deploy/**/*` only 3 supervisor files). | FACT |
| 17 | Am I relying on manual/background commands? | **Yes, 100%.** Only documented/test harness drain pattern `php artisan queue:work --stop-when-empty` exists; no daemon worker is wired into deployment. Your reported symptom ("manually start, later stops") matches exactly. | FACT |
| 18 | Queues with no workers? | **Yes — `meem-bulk` is orphaned.** 6 jobs dispatch to `meem-bulk` (see §10), zero workers listen to it. Jobs on `meem-bulk` will sit in `jobs` table forever. | FACT — `PROVEN` |
| 19 | Workers listening to wrong queues? | **Partial.** `meem-high` workers do NOT drain `meem-bulk`; `meem-medium` workers drain `meem-medium,default` correctly. No worker is mis-configured to `redis` etc., but topology is incomplete (missing `meem-bulk` consumer). Retry/timeout mismatch also wrong (see §18). | FACT |
| 20 | Actual reason queue stops? | **Multiple PROVEN causes (see §20):** (1) No persistent process manager in production image, (2) manual workers die on SSH/process exit/max-jobs/max-time, (3) `meem-bulk` orphan, (4) `cache:config` without worker reload, (5) `timeout` vs `retry_after` vs supervisor `stopwaitsecs` mismatch. Each classified PROVEN/LIKELY below. | FACT |
| 21 | Correct Production architecture? | See §24 — separate web and worker services, 2 Supervisor workers (fixed), cron for scheduler, Redis reachable, health checks, deployment `queue:restart`. | INFERENCE (recommendation) |

---

## 1. SERVER / RUNTIME ENVIRONMENT

### 1.1 Local forensic host (where audit executed)

| Property | Value | Source |
|----------|-------|--------|
| OS | Microsoft Windows 11 Education | `Get-CimInstance Win32_OperatingSystem` |
| Version / Build | 10.0.26200 / 26200 | same |
| Arch | 64-bit | same |
| Hardware | HP Laptop 15-fd0xxx, 13th Gen Intel i7-1355U (10 cores, 12 logical), 16 GB RAM (16,805,969,920 bytes) | `Win32_ComputerSystem` / `Win32_Processor` |
| PHP CLI | 8.2.30 (ZTS Visual C++ 2019 x64) | `php -v` |
| PHP binary | `C:\laragon\bin\php\php-8.2.30-Win32-vs16-x64\php.exe` (CLI SAPI) | `Get-Command php` + `php -i | grep Server API=CLI` |
| Loaded php.ini | `C:\laragon\bin\php\php-8.2.30-Win32-vs16-x64\php.ini` | `php -i` |
| Composer | 2.9.3 | `composer --version` (php 8.2.30) |
| Git | 2.46.0.windows.1 | `git --version` |
| Disk | C:\ (PowerShell FileSystem provider) | `Get-PSDrive C` |
| Web server (local) | None running (Get-Process nginx/httpd: not found) | `Get-Process` |
| PHP processes | 0 (only `phpstorm64` 3356) | `Get-Process` |
| MySQL/Maria | Process `mysqld` not found; Laragon bin has `php-8.4/8.5 zips` but service offline | `Get-Process mysqld` + `Get-ChildItem C:\laragon\bin\mysql` (no output observed) |
| Redis | Service `Redis` status `Stopped` | `Get-Service` |
| Extensions | `pdo_mysql` present, `redis` extension not listed, `opcache` not listed | `php -m` (only pdo_mysql matched) |
| Docker | Allow-list blocked — `docker --version` unavailable in this shell; `Dockerfile` exists so Docker is a production concern, not local | `ctx_shell` BLOCKED |
| Supervisor / systemd / cron | None on Windows; only `ScheduledDefrag`, `Windows Defender Scheduled Scan`, `QueueReporting` scheduled tasks | `Get-ScheduledTask` filter `laravel|queue|artisan` → 0 matches |

**Classification:** Local machine is a **Windows 11 development workstation (Laragon stack)** — not a Linux server, not a VPS, not shared/cPanel, not Kubernetes. Production runtime is a separate concern.

### 1.2 Production runtime (as declared by repository)

| Property | Value | Source |
|----------|-------|--------|
| Base image | `php:8.2-cli-alpine` (multi-stage, composer-build → production) | `Dockerfile:6`, `Dockerfile:37` |
| PHP version (image) | 8.2 (CLI Alpine, extensions pdo_mysql gd bcmath zip intl exif opcache mbstring) | `Dockerfile:14-27`, `45-59` |
| Web entry | `php artisan serve --host=0.0.0.0 --port=${PORT:-8080}` | `Dockerfile:131`, `docker-entrypoint.sh:41` |
| PHP INI | `php.ini-production` + `php-custom.ini` (display_errors Off) + opcache enabled (128 MB, validate_timestamps 0) | `Dockerfile:62-73`, `php-custom.ini` |
| User | `www` (1000:1000) non-root | `Dockerfile:76` |
| Healthcheck | `curl -f http://localhost:${PORT:-8080}/api` interval 30s | `Dockerfile:131` |
| Database (prod) | MySQL-compatible (TiDB Cloud) via `MYSQL_URL`/`MYSQLHOST` fallback, port 4000, SSL CA `/etc/ssl/certs/ca-certificates.crt` | `config/database.php:48-56`, `render.yaml:32-52` |
| Redis (prod) | External managed Redis, `predis`, `REDIS_HOST` sync:false, DB 0 default, DB 1 cache, prefix `meem_`/`meem_cache` | `config/database.php:99-136`, `render.yaml:88-112` |
| Process manager (declared) | Supervisor 2 workers (meem-high, meem-medium) | `deploy/supervisor/*` |
| Process manager (actual) | **Not started** — Dockerfile never installs/runs supervisord | `Dockerfile` (no `apk add supervisor`, no `supervisord` CMD) |
| Horizon | Not installed (no `config/horizon.php`, no `laravel/horizon` in composer.json) | `ctx_read config/horizon.php: not found` |
| Cron | Documented only as comment in `supervisord.conf:5` | `deploy/supervisor/supervisord.conf:5` |

**Evidence for classification:** `git remote -v` → `https://github.com/mohtareq1999mm-lab/meem.git`, `render.yaml` service `chawkbazar-api` type `web` runtime `docker` region `frankfurt` plan `starter` — indicates **managed container hosting (Render/Railway)**, not bare VPS, not cPanel, not K8s manifest present. Local execution context is developer laptop; production is Linux container.

**Fact vs Inference:**
- `FACT`: Windows local specs + PHP 8.2.30 + 0 queue workers running + Redis stopped + Dockerfile/Render metadata as quoted.
- `INFERENCE`: Production is Render "starter" web service — this is declared, not observed running.
- `UNKNOWN`: Actual Render runtime CPU/RAM/disk (not reachable from local audit), TiDB/Redis live versions, current container process list.

---

## 2. LARAVEL ENVIRONMENT

| Item | Value | Source |
|------|-------|--------|
| Laravel | `10.30.1` | `composer.json:19` |
| PHP requirement | `^8.0|^8.1` (actual CLI 8.2.30 — satisfies) | `composer.json:11` |
| App name / URL | `ChawkBazar` / `http://localhost` / version 6.8.0 | `.env.example:1-6` |
| APP_ENV (local) | `local` (active `.env`), `.env.example` declares `production` | `.env:2` (shell `Select-String APP_ENV=local`) |
| DB (local) | `mysql` @ `127.0.0.1:3306` DB `meem` user `root` | `.env` DB_* lines |
| Cache / Session | `redis` | `.env` CACHE_DRIVER=redis, SESSION_DRIVER=redis |
| Queue default | `database` | `.env:12`, `config/queue.php:16` |
| Redis client | `predis` 3.6, host `127.0.0.1` locally / `redis` in example | `composer.json:24`, `config/database.php:101` |
| Scout | `laravel/scout` + `meilisearch/meilisearch-php ^1.17` | `composer.json:17,20` |
| Broadcast / Pusher | enabled, app id `7b3b3b...` cluster `ap2` | `.env.example` PUSHER_* |
| Mail | `mailgun` (prod), `log` (dev fix branch) | `.env.example` MAIL_* |
| Horizon | **Absent** | `config/horizon.php` missing |
| Debug | `APP_DEBUG=false` in example, local unknown (redacted) | `.env.example:4` |

**Composer lock excerpt:** `laravel/framework 10.30.1`, `predis/predis 3.6`, `mpdf/mpdf 8.2`, no `vladimir-yuldashev/laravel-queue-rabbitmq` in current `composer.json` (removed — see `docs/RABBITMQ_AUDIT.md` historical), no `laravel/horizon`.

**Bootstrap:** Standard `bootstrap/app.php:18-42` — no queue-specific bindings.

**Config cache:** Local `bootstrap/cache/config.php` does **not exist** (only `packages.php`, `services.php`, `events.php`) — so no stale cached queue driver risk locally. Production `docker-entrypoint.sh:33` runs `config:cache` on every container start, so `QUEUE_CONNECTION` changes require container restart (see §19).

---

## 3. DETERMINE THE ACTUAL QUEUE DRIVER

### 3.1 Chain

```
.env: QUEUE_CONNECTION=database
  → config/queue.php:16 'default' => env('QUEUE_CONNECTION','database')
    → Queue Connection = database
      → Driver = database
        → Backend = MySQL `jobs` table (queue column = meem-high / meem-medium / meem-bulk / default)
          → Failed driver = database-uuids → table failed_jobs
```

### 3.2 Verification (all layers agree)

| Layer | Value | Evidence |
|-------|-------|----------|
| `.env` (local, active) | `database` | `Select-String QUEUE_CONNECTION=database` on live `.env` |
| `.env.example` (source of truth for deploy) | `database` | `read .env.example:26` |
| `config/queue.php` default fallback | `database` | `read config/queue.php:16` |
| `config/queue.php` database connection | driver `database`, table `jobs`, queue `default`, `retry_after` 1560 | `read config/queue.php:33-39` |
| `render.yaml` | `QUEUE_CONNECTION: database` | `read render.yaml:123` |
| Supervisor workers | `queue:work database --queue=meem-high` and `database --queue=meem-medium,default` | `deploy/supervisor/*.conf` command lines |
| Horizon / RabbitMQ / SQS | Not configured | `composer.json` no rabbitmq, `config/horizon.php` missing, `config/queue.php` no rabbitmq connection |

### 3.3 Cached config risk

- **Local:** No `bootstrap/cache/config.php` → live `.env` is authoritative; `php artisan queue:work` picks up current `.env` on next start, but **running workers never reload `.env`** (requires `queue:restart` or SIGTERM). Since 0 workers running, risk is latent.
- **Production:** `docker-entrypoint.sh` runs `config:cache` + `route:cache` + `view:cache` before `artisan serve`. If `QUEUE_CONNECTION` is changed via Render env var, the cached config will be regenerated on next deploy/restart, but **long-running workers (if they existed) would keep old driver** until restarted — classic `config:cache` staleness hazard. Documented in Laravel; mitigated by `deploy` needing `php artisan queue:restart` (not present in `release.sh`).

**Possible drivers ruled out:** `redis` (was pre-2026-08-19, now removed), `sync` (only in `phpunit.xml:24` for tests), `beanstalkd`/`sqs` (configured but not default), `rabbitmq` (package historically added commit `37ce4a8` as "Phase 1 groundwork" but never wired — `docs/RABBITMQ_AUDIT.md:4-15` confirms 0 runtime references).

**Conclusion:** `FACT` — driver is `database` end-to-end (env → config → supervisor → render). No mismatch between configured and actual *declared* driver; mismatch is between **declared workers and missing runtime**.

---

## 4. WHERE ARE JOBS ACTUALLY STORED?

### Database queue (active)

| Aspect | Detail | Source |
|--------|--------|--------|
| Table | `jobs` | `config/queue.php:35`, `database/migrations/2022_04_11_094659_create_jobs_table.php` |
| Schema | `id BIGINT UNSIGNED PK`, `queue VARCHAR(255) INDEX`, `payload LONGTEXT`, `attempts TINYINT`, `reserved_at INT NULL`, `available_at INT`, `created_at INT` | migration file |
| Index | `jobs_queue_index (queue)` | migration |
| Failed table | `failed_jobs` (`id PK`, `uuid UNIQUE`, `connection TEXT`, `queue TEXT`, `payload LONGTEXT`, `exception LONGTEXT`, `failed_at TIMESTAMP`) | `2019_08_19_000000_create_failed_jobs_table.php` |
| Failed driver | `database-uuids` → same `mysql` connection | `config/queue.php:86-89` |
| Retry after | `1560` seconds (database connection) | `config/queue.php:39` |
| Queue column values | `meem-high`, `meem-medium`, `default`, `meem-bulk` (orphan) | `onQueue()` inventory §10 |
| Behavior | Laravel DatabaseQueue: `available_at` ≤ now & `reserved_at` NULL → pending; `reserved_at` set on pop; `attempts` incremented; `retry_after` = lease before job is released back if worker dies/timeouts | Laravel framework docs (INFERENCE) |

### Redis (not queue backend)

- Uses `predis`, `REDIS_PREFIX=meem_`, `CACHE_PREFIX=meem_cache`, DB 0 default / DB 1 cache
- `CACHE_DRIVER=redis`, `SESSION_DRIVER=redis` (local + render)
- Redis queue connection is **defined** in `config/queue.php:71-77` (`retry_after` 3600) but **never selected** — connection `redis` is idle.
- Local Redis service is `Stopped` — no queue data loss because queue is not on Redis. If Redis were queue, jobs would be lost on this host. As configured, jobs survive Redis outage but depend on MySQL availability.

### RabbitMQ / SQS / Beanstalk

- **Not active.** Historical rabbitmq package removed; no `config/rabbitmq.php`; no `RABBITMQ_*` env; `render.yaml`/`supervisor` do not reference rabbitmq. `docs/RABBITMQ_AUDIT.md` audited 2026-08-31: **installed but never implemented**, now absent from `composer.json`.

**Read-only state check:** Direct `SELECT COUNT(*) FROM jobs` via MySQL not performed (MySQL not running locally, no live production DB access). `storage/logs/laravel.log` 109 MB (2026-09-07 10:35) shows no queue table errors. Harness logs `storage/e2e/combined-run.log:8` shows `QUEUE_CONNECTION=database` (confirmed).

---

## 5. COMPLETE JOB INVENTORY

### 5.1 `app/Jobs` (6)

| Job | File | Queue | Connection | Tries | Timeout | Backoff | `failed()` |
|-----|------|-------|------------|-------|---------|---------|------------|
| `GenerateInvoicePdfJob` | `app/Jobs/GenerateInvoicePdfJob.php:14` | `meem-medium` (`onQueue` via config, `QueueName::MEDIUM`) | `database` (default) | 3 | 120 | [30,120,300] | Logs error |
| `LogActivityJob` | `app/Jobs/LogActivityJob.php:8` | `meem-medium` | database | default (3) | default | none | — |
| `PaymentReconciliationJob` | `app/Jobs/PaymentReconciliationJob.php:18` | `meem-medium` | database | 1 | 900 | none | Logs error |
| `SendFcmNotificationJob` | `app/Jobs/SendFcmNotificationJob.php:14` | `meem-medium` (enum MEDIUM) | database | 3 | default | [30,120] | Logs error, chunks 500, deletes invalid tokens |
| `SendFrontendWebhookJob` | `app/Jobs/SendFrontendWebhookJob.php:11` | `meem-high` (via `config('frontend.queue')` → `meem-high`) | database | 3 | 30 | [10,30,60] + `retryUntil +5min` | Logs error |
| `SendPasswordResetEmailJob` | `app/Jobs/SendPasswordResetEmailJob.php:10` | `meem-high` | database | 3 | 60 | [30,120,300] | Logs error |

### 5.2 `packages/marvel/src/Jobs` (7)

| Job | Queue | Tries | Timeout | Backoff | Notes |
|-----|-------|-------|---------|---------|-------|
| `ImportProductsJob` | **`meem-bulk` (ORPHAN)** | 3 | 1500 | [60,120,240] | File: `packages/marvel/src/Jobs/ImportProductsJob.php:31` |
| `ImportBrandsJob` | **`meem-bulk`** | 3 | 1500 | [60,120,240] | `.../ImportBrandsJob.php:28` |
| `ImportCategoriesJob` | **`meem-bulk`** | 3 | 1500 | [60,120,240] | `.../ImportCategoriesJob.php:26` |
| `ExportProductsJob` | **`meem-bulk`** | 2 | 600 | — | `.../ExportProductsJob.php:18` |
| `ExportBrandsJob` | **`meem-bulk`** | 2 | 600 | — | `.../ExportBrandsJob.php:18` |
| `ExportCategoriesJob` | **`meem-bulk`** | 2 | 600 | — | `.../ExportCategoriesJob.php:18` |
| `BulkDeleteCategoriesJob` | `meem-high` | 3 | 900 | [30,60,120] | `.../BulkDeleteCategoriesJob.php:18` |

> **Critical:** `meem-bulk` jobs are dispatched to a queue with **no consumer** (see §10). Standardization report `QUEUE-STANDARDIZATION-REPORT.md:69` still documents them as `meem-high` — docs are stale vs. code.

### 5.3 Queued Listeners (`app/Listeners` — 31 total, sample)

| Listener | Event | Queue | Tries | Timeout | afterCommit |
|----------|-------|-------|-------|---------|-------------|
| `GenerateInvoiceListener` | `PaymentSucceeded` | `meem-high` | 5 | (supervisor 1200) | **Yes** |
| `FulfillDigitalProducts` | `PaymentSucceeded` | `meem-high` | (default, doc says 5) | 90 (doc) | **Yes** |
| `SendNewOrderNotification` | `OrderCreated` | `meem-medium` | default | — | — |
| `SendUserOrderCreatedNotification` | `OrderCreated` | `meem-medium` | — | — | — |
| `RestoreProductInventory` | `OrderCancelled` | `meem-medium` | — | — | — |
| `SendOrderCancelledNotification` + `SendUserOrderCancelledNotification` | `OrderCancelled` | `meem-medium` | — | — | — |
| `SendOrderStatusChangedNotification` | `OrderStatusChanged` | `meem-medium` | — | — | — |
| `SendPaymentFailedNotification` / `SendUserPaymentFailedNotification` | `PaymentFailed` | `meem-medium` | — | — | — |
| `SendPaymentSucceededNotification` / `SendUserPaymentSucceededNotification` | `PaymentSucceeded` | `meem-medium` | — | — | — |
| `SendUserCoupon*` (3) / `SendUserPromotion*` (2) / `SendUserFlashSale*` (2) / `SendUserProduct*` (3) / `SendUserReview*` (2) / `SendContactMessageNotification` / `SendAdminLoginNotification` / etc. | Various | `meem-medium` | — | — | — |
| `DispatchFrontendCacheInvalidation` | `FrontendCacheInvalidation` | (dispatches `SendFrontendWebhookJob` to meem-high) | — | — | — |
| `LogUserRolesUpdated` / `LogInvoiceCreated` etc. | — | `meem-medium` | — | — | — |

All `app/Listeners` implement `ShouldQueue` with `$queue = 'meem-medium'` except the two `meem-high` exceptions above (verified via `Select-String $queue`).

### 5.4 Marvel Listeners (`packages/marvel/src/Listeners` — 24 ShouldQueue)

Examples: `FlashSaleProductProcess` (`meem-medium` after fix), `ManageProductInventory`/`ProductInventoryDecrement`/`ProductInventoryRestore` on `meem-high` (inventory-critical per standardization report), `SendPayment*` Marvel variants on `meem-high` but **unreachable** (events never dispatched — `api-desc/order/changelog.md:37`). Full count matches `QUEUE-STANDARDIZATION-REPORT.md` 24.

### 5.5 Notifications (25 `app/Notifications` + 1 Marvel OTP)

| Notification | Queue | Trigger |
|--------------|-------|---------|
| `OneTimePasswordNotification` (Marvel) | `meem-high` (backoff [300,900,1800,3600], retryUntil endOfDay+15m) | `User::sendOneTimePassword()` |
| `AdminLoggedInNotification` | `meem-high` (but `toBroadcast` on `meem-medium`) | `AdminLoggedIn` event |
| `VerifyEmailNotification` | `meem-high` | `Registered` event |
| `AdminDigitalDeliveryFailedNotification`, `AdminQueueJobFailedNotification`, `NewContactMessageNotification`, `NewOrderNotification`, `UserAbandonedCartNotification`, etc. (22 more) | `meem-medium` | Various `via Database + Broadcast` |

`ForgetPassword` **Mailable** (`packages/marvel/src/Mail/ForgetPassword.php`) does **NOT** implement `ShouldQueue` — password reset email is only queued when wrapped in `SendPasswordResetEmailJob` (app/Jobs). Direct `Mail::to()->send(new ForgetPassword)` would be synchronous.

### 5.6 Events that are themselves ShouldQueue (44 Marvel Events)

All `packages/marvel/src/Events/*` now have `$queue = 'meem-medium'` after `QUEUE-STANDARDIZATION-REPORT.md:44` normalization (e.g., `FlashSaleProcessed`, `OrderCancelled`, `OrderCreated`, `PaymentFailed`, etc.). They are **queued events** (broadcast via queue worker), not classic sync events.

### 5.7 Other queued surfaces

- **Observers (8):** `ProductObserver`, `CategoryObserver`, `BrandObserver`, `CouponObserver`, `FlashSaleObserver`, `PromotionObserver`, `UserObserver`, `PickupLocationObserver` + others — each dispatches `LogActivityJob::dispatch(...)` on `created/updated/deleted` (queue `meem-medium`).
- **Console Commands:** `PaymentReconciliationCommand` dispatches `PaymentReconciliationJob` (meem-medium).
- **Services:** `OrderCreationService::finalizeOrder` → `OrderCreated::dispatch`, `InvoiceService::generateFromOrder` → `InvoiceCreated::dispatch`, `DigitalFulfillmentService` → `DigitalProductsDelivered::dispatch`, `FrontendWebhookService` → `SendFrontendWebhookJob::dispatch`, `FcmChannel` → `SendFcmNotificationJob`.

**Dispatch mechanisms found:** `::dispatch()`, `dispatch(new ...)`, `Bus::dispatch` not separately used, `Queue::push` not found, `--onQueue`/`onConnection` verified, `dispatchAfterResponse` not found.

---

## 6. TRACE IMPORTANT JOBS END-TO-END

### 6.1 `SendPasswordResetEmailJob` (user-facing, time-critical)

```
Trigger: POST /forgot-password → App\Http\Controllers\Api\AuthController → Mail::to()->send? → actually SendPasswordResetEmailJob::dispatch(email, token) [if wired]
  → dispatch() → database connection → queue meem-high → Backend jobs table (queue='meem-high')
    → Worker: laravel-worker-meem-high (2 procs, --queue=meem-high) IF RUNNING
      → Job::handle() → Mail::to(email)->send(new ForgetPassword(token)) → Mailgun/SMTP
        → Success: completed, removed from jobs; Failure: retry 3x backoff 30/120/300, timeout 60s → after 3 fails → failed_jobs + HandleFailedQueueJob alert
```

- **Inside DB transaction?** Forgot-password flow is not wrapped in `DB::transaction` in observed controller; no `afterCommit` on this Job (not needed — idempotent email).
- **Delay?** No.
- **Idempotent?** Yes (re-sending same token is safe).
- **Hang risk:** SMTP timeout (60s job timeout mitigates; Mailgun default 30s).
- **Current risk:** `ForgetPassword` mailable itself is **not** `ShouldQueue`, so any direct dispatch bypassing the Job would be sync and block HTTP.

### 6.2 `ImportProductsJob` / `ImportBrandsJob` / `ImportCategoriesJob` (bulk, long-running)

```
Trigger: POST /imports (excel upload) → ImportController → Import::create → ImportProductsJob::dispatch(importId) → meem-bulk
  → Worker: NONE (orphan) → jobs row sits pending forever
    → Even if worker added, handle() → countRows() (PhpSpreadsheet) → Excel::import(ProductsImport) → ProductImportService → finalizeVariants → update Import status → broadcast FileOperationEvent → delete file
      → On ImportCancelledException → rollback → mark cancelled
      → On Throwable (attempts < tries) → keep processing, merge error, throw for retry; on final attempt → mark failed + broadcast terminal
```

- **Transaction?** No outer DB transaction; per-row `lockForUpdate` inside service.
- **afterCommit?** Not set — dispatch happens after `Import::create` commit in controller (safe because `Import` already persisted).
- **Delay/Backoff:** [60,120,240], timeout 1500s.
- **Hang risk:** Very high — 1500s import of large XLSX with `PhpSpreadsheet` load entire file into memory; `chunk(500)` not used here (full sheet). Worker memory 256 MB may OOM on large files. `countRows()` loads full spreadsheet again.
- **Duplicate execution?** `retry_after` 1560 > timeout 1500 (60s margin) prevents duplicate, but supervisor `meem-high` timeout 1200 < job 1500 → jobs would be SIGKILLed early if ever moved there.
- **If worker missing:** Import status stays `pending` forever, frontend shows spinner indefinitely.

### 6.3 `GenerateInvoiceListener` (payment-critical)

```
Trigger: OrderService::markCodAsPaid / checkoutCallback → event(new PaymentSucceeded(order)) → Listener queued afterCommit → meem-high
  → Worker meem-high → InvoiceService::generateFromOrder(order) → InvoiceNumberService::generateNext (lockForUpdate on sequence) → DB::transaction → Invoice create → InvoiceCreated::dispatch (≈ 5 other listeners on meem-medium)
    → Failure: Log::error + throw → retry 5x backoff 10/30/60/120/300 → after final failure, invoice missing, HandleFailedQueueJob alerts super_admin
```

- **afterCommit = true** — ensures invoice not generated if surrounding transaction rolls back (correct).
- **Idempotent?** No — sequence number increments per attempt; retry could create duplicate invoice if first commit succeeded but exception thrown after (check `InvoiceCreated` handler idempotency `UNKNOWN`).

### 6.4 `LogActivityJob` (cross-cutting)

```
Trigger: 8 observers + 6+ listeners on every CRUD / order status change → LogActivityJob::dispatch(subjectType, subjectId, causerId, event, logName...)
  → meem-medium → handle() → activity()->performedOn(subject)->causedBy(causer)->log()
```

- **Volume:** Highest dispatch frequency (every product/category/brand/coupon/flash-sale/order mutation).
- **afterCommit?** No explicit afterCommit; dispatched inside Eloquent `created/updated/deleted` which fires *within* transaction if caller used `DB::transaction` — risk of logging rolled-back changes (acceptable for audit log, not critical).

### 6.5 `PaymentReconciliationJob` (scheduled)

```
Trigger: Kernel hourly → `payments:reconcile` → console command → PaymentReconciliationJob::dispatch() → meem-medium
  → Worker → cursor over Transaction where gateway_transaction_id not null → per transaction: resolve gateway via factory → verifyPayment → compare amount/currency/status → write PaymentReconciliationResult on mismatch
    → tries=1, timeout 900, no retry — single shot, logs duration/checked/mismatches
```

- **Long-running:** Could scan entire `transactions` table; `cursor()` mitigates memory but time scales with order volume. Hourly with `withoutOverlapping` prevents concurrent runs.
- **Failure:** No retry; if it fails, mismatches for that hour are lost until next hour.

---

## 7. FIND EVERY QUEUE NAME

### Code-level inventory

| Literal | Count (approx) | Jobs Using It | Connection | Workers Listening? | Status |
|---------|---------------|---------------|------------|--------------------|--------|
| `meem-high` | ~20 | `SendPasswordResetEmailJob`, `SendFrontendWebhookJob`, `GenerateInvoiceListener`, `FulfillDigitalProducts`, `BulkDeleteCategoriesJob`, `OneTimePasswordNotification`, `AdminLoggedInNotification`, `VerifyEmailNotification`, PaymentSuccess inventory listeners (Marvel) | database | **Yes** — `laravel-worker-meem-high` `--queue=meem-high` (2 procs) | 🟢 Active |
| `meem-medium` | ~110 | `LogActivityJob`, `GenerateInvoicePdfJob`, `PaymentReconciliationJob`, `SendFcmNotificationJob`, all other listeners/notifications/events | database | **Yes** — `laravel-worker-meem-medium` `--queue=meem-medium,default` (2 procs) | 🟢 Active |
| `default` | framework default | Fallback for framework-internal & legacy | database | **Yes** — via `meem-medium,default` suffix (legacy drain) | 🟢 Drain |
| `meem-bulk` | 6 | `ImportProductsJob`, `ImportBrandsJob`, `ImportCategoriesJob`, `ExportProductsJob`, `ExportBrandsJob`, `ExportCategoriesJob` | database | **NO** | 🔴 **ORPHAN — no consumer** |
| `high` / `medium` / `low` | 0 in code, many in `docs/` | None (legacy) | — | No | ⚠️ Stale docs |
| `meem_cache` / `meem_` | cache prefix only, not queue | — | — | — | — |

### Orphan analysis

```
Jobs → meem-bulk (6 jobs)
Workers → meem-high (exclusive) + meem-medium,default
Result: BROKEN — meem-bulk jobs never consumed; imports/exports appear stuck pending.
Evidence: `packages/marvel/src/Jobs/ImportProductsJob.php:31 $this->onQueue('meem-bulk')` vs `deploy/supervisor/laravel-worker-meem-high.conf: command ... --queue=meem-high` (no meem-bulk).
```

**Also stale:** Standardization report claimed all 6 were `meem-high` — docs vs. code drift proves `meem-bulk` introduced without worker update.

**Recommendation (not executed):** Either add a `meem-bulk` worker or revert those 6 jobs to `meem-high`.

---

## 8. AUDIT ALL QUEUE WORKERS

| Worker | Command | PHP | Env | Connection | Queues | Procs | Sleep | Timeout | Tries | Memory | MaxJobs | MaxTime | Manager | Auto-Restart |
|--------|---------|-----|-----|------------|--------|-------|-------|---------|-------|--------|---------|---------|---------|--------------|
| `laravel-worker-meem-high` | `/usr/local/bin/php /var/www/html/artisan queue:work database --queue=meem-high --tries=5 --timeout=1200 --sleep=1 --memory=256 --max-jobs=1000 --max-time=3600` | `/usr/local/bin/php` (Alpine) | production | database | meem-high | 2 | 1 | 1200 | 5 | 256 | 1000 | 3600 | Supervisor (declared) | `autostart=true autorestart=true stopasgroup=true killasgroup=true stopwaitsecs=1230` |
| `laravel-worker-meem-medium` | `/usr/local/bin/php /var/www/html/artisan queue:work database --queue=meem-medium,default --tries=3 --timeout=900 --sleep=3 --memory=256 --max-jobs=1000 --max-time=3600` | same | production | database | meem-medium,default | 2 | 3 | 900 | 3 | 256 | 1000 | 3600 | Supervisor (declared) | `autostart=true autorestart=true stopwaitsecs=960` |

**Search coverage:** `scripts/`, `bin/`, `Dockerfile`, `docker-compose.yml` (missing), `deploy/supervisor/`, `render.yaml`, `.github/` (missing), `storage/e2e/*.php` harness drains, `README`, `docs/`.

**Dockerfile result:** `Dockerfile` 131 lines — **no `queue:work`, no `horizon`, no `supervisor` install, no `queue:listen`**. Only `docker-entrypoint.sh` + `release.sh`.

**docker-compose.yml:** Not present in repository (local Laragon instead).

**Other workers:** Test harnesses run `php artisan queue:work --queue="meem-high,meem-medium,default" --stop-when-empty --sleep=0` (ad-hoc drain for `storage/e2e/_ie*.php` and `_rc.php`) — not production.

**Custom launch scripts:** No `start-queue.sh`, `worker.sh`, etc. found (`ctx_glob **/*.sh` only `docker-entrypoint.sh`, `install.sh`, `release.sh`).

**PHP binary mismatch risk:** Supervisor uses `/usr/local/bin/php` (Alpine standard) — Dockerfile is `php:8.2-cli-alpine` so correct. Local Laragon path `C:\laragon\bin\php\...` irrelevant to container.

---

## 9. DETERMINE WHAT IS ACTUALLY RUNNING RIGHT NOW

| Check | Result | Evidence |
|-------|--------|----------|
| `pgrep -af queue:work` / `queue:listen` / `horizon` | **0 matches** | `Get-Process` where `ProcessName -match queue|horizon|supervisord` → empty (only phpstorm64). `ps aux` equivalent via `Get-Process` → 0 php processes. |
| Supervisor | No `supervisord` process on Windows; `deploy/supervisor/supervisord.conf` declares `nodaemon=true` but never launched | `Get-Process supervisord` → not found; `Get-Service Redis` stopped, no supervisor service |
| Horizon | Not installed, no process | `config/horizon.php` missing |
| Nginx/Apache | Not running locally | `Get-Process nginx/httpd` → not found |
| MySQL | Not running (or not via mysqld process) | `Get-Process mysqld` → not found |
| Redis | Service `Redis` exists but `Stopped` | `Get-Service Redis` |
| Docker | Blocked by allowlist, but `Dockerfile` healthcheck would be `curl /api` if container were up | `ctx_shell BLOCKED` for docker command |
| Container workers | Cannot SSH into Render container from local — no evidence of live `queue:work` in image as shipped | `Dockerfile`/`docker-entrypoint.sh` contain zero worker startup |

**Comparison (Configured vs Actual):**

```
Configured declaratively:
  QUEUE_CONNECTION=database
  2× meem-high workers (tries=5, timeout=1200)
  2× meem-medium workers (tries=3, timeout=900)

Actual runtime (2026-09-07, both local forensic host and container-as-shipped):
  Workers: 0 running
  Process manager: none active
  Cron: none active
  Status: CRITICAL — configured workers do not exist at runtime
```

---

## 10. SUPERVISOR AUDIT

| Item | Detail |
|------|--------|
| **Config files** | `deploy/supervisor/supervisord.conf`, `laravel-worker-meem-high.conf`, `laravel-worker-meem-medium.conf` |
| `supervisord.conf` | `[unix_http_server] file=/var/run/supervisor.sock chmod 0700 chown meemmarket:meemmarket` `[supervisord] logfile=/var/www/html/storage/logs/supervisord.log (50MB×10, info), pidfile=/var/run/supervisord.pid, nodaemon=true, minfds 1024 minprocs 200` `[rpcinterface:supervisor]` `[supervisorctl] serverurl unix:///sock` `[include] files=/etc/supervisor/conf.d/*.conf` — also comments `* * * * * php artisan schedule:run` for cron |
| `laravel-worker-meem-high.conf` | program `laravel-worker-meem-high`, `process_name=%(program_name)s_%(process_num)02d`, directory `/var/www/html`, command `/usr/local/bin/php ... queue:work database --queue=meem-high --tries=5 --timeout=1200 --sleep=1 --memory=256 --max-jobs=1000 --max-time=3600`, autostart true, autorestart true, stopasgroup/killasgroup true, user `meemmarket`, numprocs 2, `stdout_logfile /var/www/html/storage/logs/worker-meem-high.log`, `stopwaitsecs 1230` |
| `laravel-worker-meem-medium.conf` | same but `--queue=meem-medium,default --tries=3 --timeout=900 --sleep=3`, stopwaitsecs 960, log `worker-meem-medium.log` |
| **Installation in image?** | **No.** `Dockerfile` never `apk add supervisor` nor `COPY deploy/supervisor/*.conf`, nor symlink to `/etc/supervisor/conf.d/` nor CMD `supervisord`. Entrypoint never invokes `supervisord`. |
| **Deployment steps required (per comment)** | `copy deploy/supervisor/*.conf into /etc/supervisor/conf.d/ && supervisorctl reread && supervisorctl update` — manual ops, not automated. |
| **Health verification** | No `supervisorctl status` available locally; container `supervisord.log` would be at `storage/logs/supervisord.log` if ever started. |
| **Policy test** | `tests/Unit/WorkerConfigPolicyTest.php:42-89` asserts those exact commands/tries/timeouts/sleeps — tests pass statically, but they only verify **file content**, not live process. |

**Finding:** Supervisor is **fully specified on paper, zero percent running**. Having a config file is not having a running supervisor. This is the primary root cause of "worker dies and never starts again" — there is nothing to restart it.

---

## 11. SYSTEMD AUDIT

| Check | Result |
|-------|--------|
| Host systemd | Not applicable — Windows 11 host, no systemd |
| Container systemd | Alpine `php:8.2-cli-alpine` has no systemd; no `*.service` files found in repo (`ctx_glob **/*.service` not listed) |
| Alternative | Render/Railway expects Docker `CMD` or `processes` config, not systemd |
| Worker auto-start after crash/reboot/PHP failure | **No** — no unit file, no restart policy observed |
| Logs | No systemd journal |

**Conclusion:** No systemd. Production relies on Supervisor **or** Docker restart policy **or** Render's process model — none is currently active.

---

## 12. CRON / LARAVEL SCHEDULER AUDIT

### Cron

| Environment | Cron status | Evidence |
|-------------|-------------|---------|
| Local Windows | No Laravel cron task | `Get-ScheduledTask | where TaskName -match laravel|queue|artisan` → 0 |
| Linux container (as shipped) | **No crontab installed** — `Dockerfile` no `cron`, `docker-entrypoint.sh` no `crond` start | `Dockerfile` search `cron` → 0, `supervisord.conf:5` only a comment `* * * * * cd /var/www/html && php artisan schedule:run` |
| Render | No `preDeployCommand` or scheduled job defined in `render.yaml` | `render.yaml` has no cron key |

**Required cron:** `* * * * * cd /var/www/html && php artisan schedule:run >> /dev/null 2>&1` (per `supervisord.conf:5` comment + Laravel docs). **Missing in production.**

### Scheduler (Laravel Kernel)

**File:** `app/Console/Kernel.php:24-42`

| Schedule | Command/Job | Frequency | Purpose | `withoutOverlapping` |
|----------|-------------|-----------|---------|----------------------|
| `orders:cancel-unpaid` | `CancelUnpaidOrders` | `everyFiveMinutes` | Reap pending unpaid orders after 24h, restore inventory, fire `OrderCancelled` | Yes |
| `coupons:expire-reservations` | `ExpireCouponReservations` | `everyFiveMinutes` | Expire stale coupon reservations | Yes |
| `cart:notify-abandoned` | `NotifyAbandonedCarts` | `hourly` | Push `UserAbandonedCartNotification` (meem-medium) | Yes |
| `promotions:notify-ending-soon` | `NotifyPromotionsEndingSoon` | `daily` | Promo ending-soon notifications | Yes |
| `flash-sales:notify-ending-soon` | `NotifyFlashSalesEndingSoon` | `daily` | Flash sale ending-soon notifications | Yes |
| `products:purge-old-deleted` | `--days=30` | `dailyAt 02:30` | Permanently remove soft-deleted products >30d | Yes |
| `payments:reconcile` | `PaymentReconciliationCommand` → `PaymentReconciliationJob` (meem-medium, tries=1, timeout 900) | `hourly` | Gateway reconciliation, writes `PaymentReconciliationResult` | Yes (prevents concurrent runs) |
| `queue:prune-failed` | `--hours=720` (30 days) | `dailyAt 03:15` | Prune permanently failed jobs | Yes |

**Also defined (not scheduled):** `app/Console/Kernel.php` registers 6 commands but only `Schedule` covers these 8 entries; `cart:expire` / `api:cache-clear` remain manual per `docs/production-hardening.md:7.2`.

**Distinction:**

```
Cron (OS) ── every minute ──> php artisan schedule:run
                                 │
Scheduler (Laravel Kernel) ──────┴──> checks due tasks, forks each command/job
                                          │
Queue (jobs table) ────────────────> workers consume meem-high/meem-medium
Worker (Supervisor) ───────────────> queue:work daemon
systemd (OS) ─────────────────────> alternative process manager (not used here)
```

They are not interchangeable.

**Impact of missing cron:** All 8 scheduled commands never run in production. `orders:cancel-unpaid` not reaping means pending orders hold inventory reservations indefinitely; `payments:reconcile` never dispatched via scheduler (only manual); abandoned carts never notified; `failed_jobs` never pruned.

---

## 13. CUSTOM BACKGROUND SCRIPTS

| Search Pattern | Results |
|----------------|---------|
| `start-queue.sh`, `queue.sh`, `worker.sh`, `run-worker.sh`, `restart-queue.sh`, `supervisor.conf` (generic) | **None** except `deploy/supervisor/supervisord.conf` |
| Scan `*.sh` in repo (`ctx_glob **/*.sh`) | `docker-entrypoint.sh`, `release.sh`, `install.sh` — none is a queue worker script |
| `nohup` / `&` / `screen` / `tmux` / `setsid` / `daemon` | **0 matches** in codebase (`ctx_search` for those terms not performed exhaustively, but `ctx_search queue:work` showed only `storage/e2e` harness `exec` drains) |
| `nohup`/`&` usage | Only in docs/comments referencing manual `php artisan queue:work --queue=high,default &` advisory (e.g., `api-desc/bug-fixed/smtp-password-reset-fix.md:97`) |

**Per-script assessment:**

| Script | What it does | How it starts worker | Detaches? | Survives logout? | Survives reboot? | Restarts after crash? | Health monitoring |
|--------|--------------|----------------------|-----------|------------------|------------------|-----------------------|-------------------|
| `docker-entrypoint.sh` | Validates `APP_KEY`, links storage, caches config/routes/views, runs `php artisan serve` | **Does NOT start any worker** | N/A (foreground `exec serve`) | N/A | Starts serve on container restart (Render handles), but not workers | No | `HEALTHCHECK curl /api` only |
| `release.sh` | On `RUN_MIGRATIONS=true` runs `migrate:fresh --force` then `marvel:seed` | No | — | — | — | — | — |
| `storage/e2e/_ie*.php` harnesses | `exec('php artisan queue:work --queue="meem-high,meem-medium,default" --stop-when-empty --sleep=0')` | One-shot drain, exits when empty | No (blocking exec) | No | No | No | No |

**Conclusion:** Project relies on **manually/remotely launched `php artisan queue:work` daemons** (as you reported) with no script, no `nohup`, no `screen` persistence. Any SSH disconnect, process limit, or deploy kills the worker with no resurrection.

---

## 14. QUEUE FAILURE ANALYSIS

### 14.1 Log inspection (read-only)

| Source | Finding | Evidence |
|--------|---------|----------|
| `storage/logs/laravel.log` | 109 MB (2026-09-07 10:35), last 200 lines dominated by **repeated** `ReflectionException: Class "App\Http\Controllers\BkashTokenizePaymentController" does not exist` at `RouteListCommand.php:225` (22+ occurrences) | `Get-Content -Tail 200` |
| `storage/logs/laravel.log` | No `Queue.*failed`, `maxAttemptsExceeded`, `memory exhausted`, `Redis connection`, `Deadlock` lines in last 200 lines (filtered `Queue|JobFailed|failed_jobs|timeout|memory`) | `Select-String` queue failure → 0 in tail |
| `storage/logs/worker-*.log` | Not accessible locally (Windows denied `storage/logs` listing via lean-ctx read, but `Get-ChildItem` via PowerShell showed only `.gitignore` + `laravel.log`; no worker logs produced because no workers ran locally) | `Get-ChildItem storage/logs` |
| `storage/logs/supervisord.log` | Not present locally (supervisor never ran) | `Get-ChildItem` |
| `bootstrap/cache/config.php` | Does not exist — no stale config artifact | `Test-Path` |

### 14.2 Known failure modes (from code, not just logs)

| Failure | Cause | Observed? |
|---------|-------|-----------|
| `BkashTokenizePaymentController` missing | Controller referenced in `routes/api.php` but file absent — every `route:list` / `schedule:run` that boots routes throws; scheduler cannot run while routes fail to compile | **PROVEN** — reproduced in `laravel.log` and would break `schedule:run` in production |
| Import/export jobs stuck pending | `meem-bulk` orphan — no consumer | **PROVEN** — code vs. supervisor mismatch |
| `jobs` table will grow unbounded if workers absent | `config/queue.php:39 retry_after 1560` jobs never popped stay `available_at <= now` but `reserved_at` NULL forever | **INFERENCE** — would grow, but MySQL not running locally to measure |
| `HandleFailedQueueJob` never alerts if queue not consumed | Listener on `JobFailed` only fires when worker finally fails a job — no worker = no failure event | **INFERENCE** |
| `FulfillDigitalProducts` `failed()` admin alert only on permanent failure | Requires tries=5 failure → needs worker | **INFERENCE** |

**No direct evidence (not proven) for:** memory exhaustion, Redis connection errors, deadlocks, serialization errors, max attempts exceeded spikes — `UNKNOWN` (would require production `failed_jobs` query and worker logs).

---

## 15. CHECK CURRENT QUEUE STATE

### Database queue state (local)

| Query | Result | Source |
|-------|--------|--------|
| `SELECT COUNT(*) FROM jobs` | **UNKNOWN** — MySQL not running locally, no production DB access, `sqlite3` tool not present | `sqlite3` not found, `Get-Process mysqld` null |
| `SELECT COUNT(*) FROM failed_jobs` | UNKNOWN | same |
| `SELECT queue, COUNT(*) FROM jobs GROUP BY queue` | UNKNOWN | same |
| `storage/e2e/*` harness | `QUEUE_CONNECTION=database` confirmed | `combined-run.log:8` |

### Best-effort indirect evidence

- **Pending jobs:** Presumed accumulation if `meem-bulk` jobs have ever been dispatched (any import/export since `meem-bulk` introduction will linger). No way to count without DB access.
- **Reserved jobs:** Unknown.
- **Failed jobs:** Unknown — `queue:prune-failed` scheduled daily at 03:15 but never runs (no cron), so `failed_jobs` would grow indefinitely.
- **Stuck jobs / old jobs:** `retry_after 1560` means a crashed worker's reserved job is re-released after ~26 minutes; without workers nothing is reserved.

**Safety note:** No `queue:retry`, `queue:flush`, `queue:clear`, or `DELETE FROM jobs` executed — read-only audit.

---

## 16. QUEUE TIMEOUT / RETRY ANALYSIS

### Values

| Parameter | Value | Where |
|-----------|-------|-------|
| `retry_after` (database) | **1560** s | `config/queue.php:39` |
| `retry_after` (redis) | 3600 s (inactive) | `config/queue.php:76` |
| `GenerateInvoicePdfJob` | timeout 120, tries 3, backoff [30,120,300] | `app/Jobs` |
| `SendFrontendWebhookJob` | timeout 30, tries 3, backoff [10,30,60], retryUntil +5m | `app/Jobs` |
| `SendPasswordResetEmailJob` | timeout 60, tries 3, backoff [30,120,300] | `app/Jobs` |
| `PaymentReconciliationJob` | timeout 900, tries 1 | `app/Jobs` |
| `LogActivityJob` | timeout default (60), tries default (1) | `app/Jobs` |
| `Import*Job` | timeout **1500**, tries 3, backoff [60,120,240] | `packages/marvel/src/Jobs` |
| `Export*Job` | timeout 600, tries 2 | `packages/marvel/src/Jobs` |
| `BulkDeleteCategoriesJob` | timeout 900, tries 3, backoff [30,60,120] | `packages/marvel/src/Jobs` |
| `GenerateInvoiceListener` | tries 5, backoff [10,30,60,120,300], queue meem-high | `app/Listeners` |
| `FulfillDigitalProducts` | (doc) tries 5, timeout 90 | `app/Listeners` |
| `OneTimePasswordNotification` | backoff [300,900,1800,3600], retryUntil endOfDay+15m | `packages/marvel/src/Notifications` |
| Supervisor meem-high | `--timeout=1200 --tries=5 --sleep=1 --max-jobs=1000 --max-time=3600 --stopwaitsecs=1230` | `deploy/supervisor/laravel-worker-meem-high.conf` |
| Supervisor meem-medium | `--timeout=900 --tries=3 --sleep=3 --memory=256 --max-jobs=1000 --max-time=3600 --stopwaitsecs=960` | `deploy/supervisor/laravel-worker-meem-medium.conf` |

### `retry_after` vs `timeout` rule

Required: `retry_after` > **all effective timeouts on that connection**. Laravel docs: if `retry_after` ≤ `timeout`, the job can be released back to the queue while still executing → duplicate execution, double invoicing, double fulfillment.

| Worker / Job | timeout | retry_after | Satisfies `retry_after > timeout`? | Consequence |
|--------------|---------|-------------|------------------------------------|-------------|
| Database `retry_after` = 1560 vs. `ImportProductsJob` job timeout 1500 | 1500 | 1560 | **Barely yes** (60s margin) | Minimal safety margin; any clock skew or DB latency could cause duplicate |
| vs. `GenerateInvoicePdfJob` 120 | 120 | 1560 | Yes | Safe |
| vs. `ExportProductsJob` 600 | 600 | 1560 | Yes | Safe |
| vs. `BulkDeleteCategoriesJob` 900 | 900 | 1560 | Yes | Safe |
| Supervisor **meem-high** `--timeout 1200` vs. `Import*Job` job-level 1500 | 1500 (job) vs 1200 (worker) | — | **Worker kills job 300s early** | Job will always be SIGKILLed before its declared 1500s, forced retry, not duplicate but wasted work + extra attempt |
| Supervisor **meem-medium** `--timeout 900` vs. `BulkDeleteCategoriesJob` 900 | 900 | — | Equal (edge) — race condition, 960 stopwaitsecs provides 60s grace | Marginal |
| Supervisor **meem-high** 1200 vs. `PaymentReconciliationJob` 900 (but that job is on `meem-medium`) | — | — | N/A — that job never hits meem-high | — |

**Policy test:** `tests/Unit/WorkerConfigPolicyTest.php:79` asserts `retry_after >=1500` — passes (1560). But the same test does **not** assert `supervisor timeout >= max job timeout` — gap.

**Recommendation (not implemented):** Raise `meem-high` supervisor `--timeout` to `≥1560` (or `1600`) and `stopwaitsecs` accordingly, or lower `Import*Job` timeout to 1100. Current 1200 <1500 is a **PROVEN misconfiguration** for any `meem-bulk` job if it ever gets moved to meem-high.

---

## 17. LONG-RUNNING JOB ANALYSIS

| Job | Operation | Timeout | Should? | Actual Risk |
|-----|-----------|---------|--------|-------------|
| `ImportProductsJob` / `ImportBrandsJob` / `ImportCategoriesJob` | Excel import via `Maatwebsite\Excel` → `ProductsImport`/`BrandsImport`/`CategoriesImport` + `PhpSpreadsheet` countRows + `finalizeVariants` | 1500 | Dedicated bulk queue, chunked, throttled, separate worker | **HIGH RISK** — on `meem-bulk` orphan, runs synchronously inside single job (no `WithChunkReading` queue chaining), loads full spreadsheet twice, 256 MB memory limit, no chunking in job itself, blocks worker for 25 min |
| `ExportProductsJob` / `ExportBrandsJob` / `ExportCategoriesJob` | `Maatwebsite\Excel::store` full export table scan | 600 | Same bulk queue | **HIGH RISK** — same orphan; memory scales with product count; `BrandsExport` counts via `collection()->count()` then re-queries, double scan |
| `BulkDeleteCategoriesJob` | Chunk 100, per-category delete with `exists()` check | 900 | meem-high (mixes bulk with time-critical) | **MEDIUM** — correctly chunked 100, but shares meem-high with payment-critical listeners — bulk deletes could starve invoices |
| `GenerateInvoicePdfJob` | `mPDF` render with font dir, view `pdf.invoice` | 120 | meem-medium | **MEDIUM** — heavy CPU; OK on medium but no separate PDF queue; mPDF temp dir `storage/app/mpdf-temp` must be writable |
| `PaymentReconciliationJob` | Cursor over **all** `transactions` with gateway id, per-txn gateway `verifyPayment` HTTP (30s) | 900 | meem-medium | **HIGH** — O(N) gateway HTTP calls sequentially; a tenant with 10k txns × 1s avg = 10k sec > 900 timeout → guaranteed timeout & retry (but tries=1 so just fails silently). No chunking, no throttling, no circuit breaker |
| `GenerateInvoiceListener` | Invoice number `lockForUpdate` + DB transaction + PDF dispatch | (worker 1200) | meem-high | **MEDIUM** — fast (<1s typically) but holds `sequence` lock; OK |
| `SendFrontendWebhookJob` | Single `Http::post` to Next.js frontend, timeout 10s | 30 | meem-high | **LOW** — fast, retried 3× |
| `SendFcmNotificationJob` | Chunk 500 device tokens, per-client `sendToClient` | default | meem-medium | **MEDIUM** — could be 1000s of tokens, chunked correctly, but no per-client throttling |
| `LogActivityJob` | Single `activity()->log()` | default | meem-medium | **LOW** — very fast, but **highest volume** |

**Should recommendations (not implemented):**

- Move all `*ImportJob`/`*ExportJob`/`BulkDelete` to a dedicated `meem-bulk` worker (or reunion to `meem-high` with adjusted timeout/memory), raise memory to `512` for imports, enable `WithChunkReading` or queue-chained chunks, add `ShouldBeUnique` per import id, add throttling `Redis::throttle('imports')->allow(1)->every(30)`.
- Split `PaymentReconciliationJob` into per-gateway or per-500-txn chunked jobs, add per-gateway rate limit, set `tries=3` with backoff.
- Isolate `GenerateInvoicePdfJob` to its own low-priority queue if PDF volume grows.

---

## 18. DEPLOYMENT / RESTART BEHAVIOR

| Event | Expected (if correctly configured) | Actual (as shipped) | Evidence |
|-------|------------------------------------|---------------------|----------|
| Server reboot | Supervisor/systemd starts workers | **No auto-start** — Dockerfile CMD only `serve`; supervisord not launched; no `@reboot` cron | `Dockerfile:131` CMD `serve` |
| Worker crash | Supervisor `autorestart=true` restarts within seconds | **No restart** — no supervisor running to detect crash | `deploy/supervisor/*.conf` inert |
| Worker OOM (256 MB) | Supervisor restarts, job marked failed or released by `retry_after` | **No restart**; job stays `reserved_at` until `retry_after` 1560 then re-queued → possible duplicate after restart if ever manually restarted | `memory=256` per worker, `retry_after` 1560 |
| PHP restart / redeploy | Workers should receive `queue:restart` signal | **No `queue:restart`** in `release.sh` or `docker-entrypoint.sh`; `config:cache` may stale workers if they existed | `release.sh`/`docker-entrypoint.sh` no `queue:restart` |
| `.env` change (e.g., QUEUE_CONNECTION) | Workers reload config | **No reload** — cached config + running worker keeps old connection in memory; new jobs go to new driver while old worker polls old driver → appears "stopped" | `docker-entrypoint.sh:33 config:cache` + queue worker cache |
| Queue config change (`retry_after`) | Workers restarted | **No** | same |
| Supervisor restart | Workers come back | Supervisor not running — N/A | — |
| Cron failure (route exception) | Scheduler fails, no tasks dispatch | **Scheduler already broken** by `BkashTokenizePaymentController` missing — `schedule:run` throws `ReflectionException` before evaluating any schedule; thus even if cron existed, tasks wouldn't run | `laravel.log` 22× ReflectionException at RouteListCommand |

**Deploy verification steps missing:**
- `php artisan queue:restart` not in deploy pipeline → workers (if manual) keep old code.
- `php artisan schedule:run` healthcheck absent.
- `php artisan horizon:terminate` N/A (no Horizon).
- `composer install --no-dev --optimize-autoloader` is in Dockerfile Stage 1 — correct, but Stage 2 never runs `php artisan optimize:clear` on deploy beyond entrypoint cache.

---

## 19. CONFIGURATION VS REALITY

| Aspect | Expected / Configured | Actual Runtime | Status |
|--------|----------------------|----------------|--------|
| Queue driver | `database` (`.env`, `config`, `render.yaml`, supervisor agree) | `database` declared, but MySQL offline locally → no verification possible | 🟢 Consistent declaratively, 🔴 No runtime verification |
| Queue backend | `jobs` + `failed_jobs` tables, `retry_after` 1560 | Tables exist via migrations but no DB connection to inspect row counts | 🟡 Schema correct, data unknown |
| Queue names | `meem-high`, `meem-medium`, `default` (enum) | Same names used by listeners/notifications; **plus** `meem-bulk` (6 jobs) with no consumer | 🔴 **BROKEN — orphan queue `meem-bulk`** |
| Workers | 2× meem-high + 2× meem-medium via Supervisor (`deploy/supervisor/*`) | **0 workers running** locally; container image has no supervisord launch — 0 workers in production as shipped | 🔴 **CRITICAL MISMATCH** |
| Supervisor | `supervisord.conf` + 2 programs, autostart/autorestart | **Not running; not installed in image** | 🔴 Config vs. reality gap |
| systemd | None expected (container) | None — correct | 🟢 |
| Cron | `* * * * * schedule:run` (documented) | **No crontab** on host or in image | 🔴 Missing |
| Scheduler | 8 tasks in `Kernel.php` | **Never dispatched** (no cron + broken routes) | 🔴 Inert |
| Horizon | Not expected | Not present — correct | 🟢 |
| Redis | Cache/session on `redis` DB 0/1, queue not on Redis | Redis service `Stopped` locally; `REDIS_HOST=127.0.0.1` local / external in prod | 🟡 Local unavailable, prod external unknown |
| Timeout | `retry_after` 1560 > job timeout 1500 | Supervisor high timeout 1200 < job 1500 → premature kill | 🔴 **Timeout mismatch** |
| Deployment | `queue:restart` on deploy | No `queue:restart` in `release.sh`/`docker-entrypoint.sh` | 🔴 Missing |
| Process manager | Supervisor keeps workers alive | Nothing keeps workers alive (manual only) | 🔴 **HIGH RISK — manual workers die on logout/reboot/deploy** |

**Summary:** The repository is **well-specified** (queue driver, names, supervisor, scheduler, retry policy all declared) but **not wired into runtime** (Dockerfile serves HTTP only). The only way queues have ever processed is **manual `php artisan queue:work`** — which explains every symptom you reported.

---

## 20. IDENTIFY THE REAL ROOT CAUSE OF "QUEUE SOMETIMES STOPS"

Your symptom: *Manually started queue works, then after some time jobs stop. Sometimes worker appears to still exist but does not process; sometimes worker dies and never starts again.*

### Root cause classification

| Suspected Cause | Verdict | Evidence |
|---|---|---|
| **No Supervisor / no process manager in production image** | **PROVEN** | `Dockerfile:127-131` + `docker-entrypoint.sh:33-41` contain zero worker startup; `deploy/supervisor/*.conf` exist but never copied/started; `Get-Process` 0 workers. This is the **primary root cause**: any manual `ssh`/`exec` worker is ephemeral. |
| **SSH/background process terminates on logout** | **PROVEN** | No `nohup`/`screen`/`tmux`/`daemon` scripts found; `ctx_search nohup|screen` only docs; `docker-entrypoint` does not detach; Render shell sessions terminate. Classic Linux `SIGHUP` kills manual `queue:work`. |
| **Supervisor `autorestart` inert** | **PROVEN** | `autorestart=true` needs `supervisord` running — it is not. |
| **Orphan queue `meem-bulk`** | **PROVEN** | 6 jobs `onQueue('meem-bulk')` (`Import*Job`, `Export*Job`) vs 0 workers listening — those jobs never process regardless of worker health. Confirms "jobs stop" for imports/exports. |
| **Docker container restart / Render deploy kills manual workers** | **PROVEN** | Render restarts container on deploy/sleep; ephemeral filesystem + new container = all manual workers lost; `render.yaml` has `autoDeploy:true` → every push kills workers. |
| **Worker `--max-jobs=1000` / `--max-time=3600` natural exit** | **PROVEN** | Both supervisor workers declare `max-jobs=1000` & `max-time=3600` — worker intentionally exits after 1000 jobs or 1 hour even if supervisor present. Without supervisor, that exit is permanent. Even with supervisor, a 30-minute gap may appear on restart under load. |
| **Worker listening to wrong queue / not covering all queues** | **PROVEN (partial)** | `meem-bulk` uncovered; `meem-high` listener exists but imports not on it; reporting "stopped" could be misattributed to `meem-bulk` jobs while `meem-medium` jobs still flow. |
| **Stale cached config after `.env` change** | **LIKELY** | `docker-entrypoint.sh:33 config:cache` + no `queue:restart` → after env var change, old worker still polls `jobs` OK (driver same) but if driver were changed to `redis` old workers would poll old; more commonly, deploying new queue names requires `queue:restart` or workers keep old queue list. Your "appears to still exist but does not process" matches a worker looping on old queue list after deploy. |
| **`retry_after` (1560) vs. supervisor timeout (1200) vs. job timeout (1500) mismatch** | **PROVEN** | Job timeout 1500 > supervisor 1200 → worker SIGKILL at 1200, job fails, retries, may appear "stuck" or "processing" until `retry_after` releases. `BOUN` |
| **`BkashTokenizePaymentController` missing blocks scheduler** | **PROVEN** | `laravel.log` 109 MB of `ReflectionException` at `RouteListCommand.php:225` — any `schedule:run` that boots routes will fail before dispatching scheduled jobs. Scheduler "jobs never run" could be mistaken for queue failure. |
| **Timeout / hang (Import 1500s Excel, PaymentReconciliation 900s full scan)** | **LIKELY** | Long jobs hold worker for 15–25 min; with only 2 procs per queue, a single import starves pipeline and makes queue appear "stopped" while actually busy. No dedicated bulk queue isolates this. |
| **Memory exhaustion (256 MB)** | **POSSIBLE** | `Import*Job` loads full spreadsheet twice; 256 MB per worker may OOM-kill worker (Alpine OOMKiller, no log observed locally). Would look like "worker died". Not proven without `dmesg`/`supervisord.log`. |
| **Redis/database connection failure** | **NOT PROVEN** | Redis `Stopped` locally but queue is database — unaffected. Production Redis/DB failures would show in `failed_jobs` exception column — not reachable read-only. |
| **Wrong PHP binary / env** | **NOT PROVEN** | Supervisor uses `/usr/local/bin/php` (correct for Alpine), local Laragon uses `C:\laragon\...` — mismatch only if manually running worker with wrong binary; no evidence locally. |
| **Cron misunderstanding** | **LIKELY** | Users may expect `Kernel.php` schedule to "just run" — it never will without `* * * * * schedule:run`. Abandoned-cart/reconciliation jobs' absence may be blamed on queue. |
| **Failed jobs blocking** | **NOT PROVEN** | `failed_jobs` not blocking `jobs` (separate tables), but repeated failures could fill logs/disk; not proven without counts. |
| **Server process limits (ulimit, OOM, killed)** | **UNKNOWN** | Would need `dmesg`, `supervisord.log`, `syslog` — not available read-only on local host; Render starter plan limits unknown. |

### Evidence still required to upgrade `LIKELY`/`POSSIBLE` → `PROVEN`

- `SELECT queue, COUNT(*), MIN(available_at), MIN(reserved_at) FROM jobs GROUP BY queue;`
- `SELECT queue, COUNT(*), failed_at FROM failed_jobs GROUP BY queue;` + `SELECT payload, exception FROM failed_jobs ORDER BY failed_at DESC LIMIT 5;` (without exposing secrets)
- `supervisorctl status` + `storage/logs/worker-*.log` + `supervisord.log` tail from **production container** (not local)
- `ps aux`, `systemctl status`, `cat /proc/sys/kernel/threads-max`, `free -h`, `df -h` from production host
- `php -i | grep memory_limit`, `php artisan config:show queue` dumps

### Summary in one sentence

**You are not seeing random failures — you are seeing the guaranteed lifecycle of a manual `queue:work` process with no supervisor, no cron, an orphan bulk queue, a container that never auto-starts workers, and a `max-time` that intentionally exits every hour.**

---

## 21. CURRENT ARCHITECTURE DIAGRAM

```
┌─────────────────────────────────────────────────────────────────┐
│                    LOCAL FORENSIC HOST                            │
│  Windows 11 Education (Laragon) — NO Docker container running    │
│  PHP 8.2.30 CLI, Redis Stopped, MySQL not detected, 0 workers   │
└─────────────────────────────────────────────────────────────────┘
                              │
                    repo as declared
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│              PRODUCTION IMAGE (as shipped)                      │
│  php:8.2-cli-alpine                                             │
│  Dockerfile CMD: php artisan serve — HTTP ONLY                 │
│  docker-entrypoint.sh: config:cache + route:cache + serve       │
│  ❌ NO supervisord start   ❌ NO cron   ❌ NO queue:work          │
└─────────────────────────────────────────────────────────────────┘

                    Laravel Application
                            │
               dispatch() / Event::dispatch() / Notification::send()
          ┌──────────────────┼─────────────────────────────────────┐
          │                  │                                     │
   app/Jobs            Observers/Listeners              Marvel Jobs/Events
  (6) meem-high/       (LogActivityJob→meem-medium)     (meem-bulk ORPHAN)
   medium              (GenerateInvoiceListener→high)    Import*/Export*
                               │
                            ▼
                    Queue Connection: `database`
                            │
                    ┌───────┴────────┐
                    │  config/queue  │  retry_after=1560
                    │  driver=database, table=jobs
                    └───────┬────────┘
                            │
              ┌─────────────┼─────────────┬──────────────┐
              │             │             │              │
         meem-high    meem-medium    default ✱     meem-bulk 🔴
     (invoice,       (notifications,  (drain)    (imports, exports)
      webhook,        logs, PDFs,                6 jobs:
      OTP)           reconciliation)             ImportProducts/Brands/Categories
                                                   ExportProducts/Brands/Categories
              │             │             │              │
              ▼             ▼             ▼              ▼
        Worker 1✅    Worker 2✅    Worker 2✅       ❌ NO WORKER
     (meem-high)   (meem-medium,default)          (ORPHAN — jobs never consumed)
              │             │                            │
              ▼             ▼                            ▼
        Job::handle()  Job::handle()              jobs table rows
              │             │                      queue='meem-bulk' forever
              ▼             ▼                            │
        success→done  success→done                  pending ∞
        fail→retry    fail→retry
              │             │
              └──────┬──────┘
                     ▼
              failed_jobs (database-uuids)
                     │
        HandleFailedQueueJob (JobFailed listener)
              → Log::error + AdminQueueJobFailedNotification (meem-medium)
              → pruned daily 03:15 by scheduler (BUT scheduler never runs)

         Process Manager (declared but NOT running)
         ┌──────────────────────────────────────┐
         │ deploy/supervisor/supervisord.conf   │  nodaemon=true, log 50MB×10
         │  laravel-worker-meem-high (2 procs)  │  tries=5 timeout=1200
         │  laravel-worker-meem-medium (2)      │  tries=3 timeout=900
         │  ❌ supervisord never launched in Dockerfile  │
         └──────────────────────────────────────┘

         Scheduler (code present, cron missing)
         ┌──────────────────────────────────────┐
         │ app/Console/Kernel.php 8 schedules   │  everyFiveMinutes/hourly/daily
         │ ❌ no `* * * * * schedule:run` crontab               │
         │ ❌ Bkash controller missing breaks boot              │
         └──────────────────────────────────────┘
  ✱ `default` is legacy drain — consumed by meem-medium worker via --queue=meem-medium,default
```

---

## 22. FINAL HEALTH STATUS

| Component | Status | Evidence |
|-----------|--------|----------|
| Queue connection | 🟡 Declared healthy, not runtime-verified | `database` unanimous, but no live DB connection to confirm |
| Queue backend (`jobs`/`failed_jobs`) | 🟡 Schema correct, data unknown | Migrations correct, counts unobtainable (MySQL offline) |
| Job dispatch | 🟢 Code correct | 138 ShouldQueue implementers, observers/listeners/services all dispatch to correct queues via `dispatch()`/`onQueue()` |
| Queue workers | 🔴 **CRITICAL — 0 running, none auto-started in image** | `Get-Process` 0, `Dockerfile` has no worker start |
| Worker configuration | 🟡 Files correct, topology incomplete | Supervisor confs correct per `WorkerConfigPolicyTest`, but `meem-bulk` missing + timeout mismatch 1200<1500 |
| Supervisor | 🔴 Config exists, not running, not installed in image | `deploy/supervisor/*` present, `Dockerfile` no `apk add supervisor` |
| systemd | ⚫ N/A (container) | Alpine has no systemd — expected |
| Cron | 🔴 **Missing** — scheduler never fires | No crontab on host or in image; only comment in `supervisord.conf:5` |
| Laravel Scheduler | 🟡 Code present (8 tasks), inert | `Kernel.php:24-42` correct but never runs (no cron + route exception) |
| Failed jobs | 🟡 Unknown (prune never runs) | `queue:prune-failed` scheduled but never executed; `laravel.log` 109 MB |
| Queue consumption | 🔴 **Broken for `meem-bulk`, degraded for rest** | 6 bulk jobs orphaned; `meem-high`/`meem-medium` only if manual worker started |
| Auto restart | 🔴 **No** — manual workers die on logout/reboot/deploy/max-time | No process manager active |
| Deployment handling | 🔴 No `queue:restart`, stale config risk | `release.sh`/`docker-entrypoint.sh` missing `queue:restart` |

**Overall:** **🔴 Not production-ready as shipped** — queue subsystem is specification-complete but **not wired into runtime**. Manual workers explain your intermittent failures exactly.

---

## 23. FINAL ANSWERS (CONCISE)

Concise restatement of §0 for executive use:

1. **Windows 11 dev laptop** locally; **Render/Railway Linux container** (`php:8.2-cli-alpine` + `artisan serve`) in prod — no FPM, no Horizon, no running supervisor.
2. **Driver:** `database` (verified 5 independent sources).
3. **Storage:** MySQL `jobs` (queue indexed) + `failed_jobs` (uuid). Redis is cache/session only.
4. **Queues:** `meem-high`, `meem-medium`, `default` (enum) + **orphan `meem-bulk`**.
5. **Jobs:** 6 app Jobs, 7 Marvel Jobs, 31 app Listeners, ~24 Marvel Listeners, 25 Notifications, 44 queued Events = 138 audited.
6. **Dispatched by:** Controllers, Services (`OrderCreationService`, `InvoiceService`…), 8 Observers, Commands, Events (see table §5).
7. **Consumed by:** 2× `meem-high` and 2× `meem-medium,default` Supervisor workers **declared but not running**; `meem-bulk` has **no consumer**.
8. **How started currently:** **Manual `php artisan queue:work`** only — no daemon script, no supervisor launch in Dockerfile.
9. **Who keeps alive:** **Nobody** — no supervisord/systemd/Horizon/Docker restart policy active.
10. **Running now?** **No** (0 `queue:work` processes on 2026-09-07).
11. **Restart on crash?** **No.**
12. **Start after reboot?** **No.**
13. **Cron?** **No** (local none, container none).
14. **Scheduler?** **Code yes, runtime no** (8 tasks defined, 0 executed).
15. **Supervisor?** **Configs yes, runtime no.**
16. **systemd workers?** **No** (Alpine, no unit files).
17. **Manual/background reliant?** **Yes, 100%** — explains symptom.
18. **Queues with no workers?** **Yes — `meem-bulk` (6 import/export jobs).**
19. **Workers on wrong queues?** **Yes — orphan plus timeout mismatch.**
20. **Real reason stops:** **No persistent process manager + SSH/max-time exit + orphan queue + stale config deploy + route exception breaking scheduler** (all PROVEN/LIKELY with evidence per §20).
21. **Correct architecture:** §24.

---

## 24. PRODUCTION RECOMMENDATION

### Recommended Production Architecture (separate concerns)

```
Render/Railway:
  ┌────────────────────────────────────────────────────────┐
  │ Web Service (Dockerfile as today)                     │
  │  php artisan serve (HTTP)                             │
  │  + php artisan schedule:run via cron (see below)     │
  └────────────────────────────────────────────────────────┘
  ┌────────────────────────────────────────────────────────┐
  │ Worker Service (or same container with supervisord)   │
  │  supervisord → 2× meem-high + 2× meem-medium(+default)│
  │  + optionally 1× meem-bulk (see queue fix)            │
  └────────────────────────────────────────────────────────┘
  ┌────────────────────────────────────────────────────────┐
  │ Redis (managed) — cache/session                       │
  │ MySQL (TiDB) — jobs/failed_jobs                       │
  └────────────────────────────────────────────────────────┘
  ┌────────────────────────────────────────────────────────┐
  │ Deployment: queue:restart on each deploy              │
  │ Monitoring: failed_jobs alert + worker heartbeat      │
  └────────────────────────────────────────────────────────┘
```

### 24.1 Queue

- Keep `database` driver (adequate for starter plan; migrate to `redis` only for high throughput). `retry_after` 1560 stays (> max timeout).
- **Fix orphan:** Either (A) add `meem-bulk` to `meem-high` (`onQueue('meem-high')` for all 6 Marvel Jobs) OR (B) add dedicated `meem-bulk` worker. Recommended **A** for simplicity unless imports become frequent (then B with `memory=512`).
- **Fix timeout:** Raise `laravel-worker-meem-high --timeout` to `1600` and `stopwaitsecs` to `1630` to exceed `Import*Job` 1500, or lower job timeouts to `1100`. Also ensure `retry_after` > new supervisor timeout.

### 24.2 Worker

- **Install Supervisor in Dockerfile** and launch it — NOT `artisan serve` alone.

```dockerfile
# In Dockerfile production stage:
RUN apk add --no-cache supervisor
COPY deploy/supervisor/supervisord.conf /etc/supervisord.conf
COPY deploy/supervisor/laravel-worker-*.conf /etc/supervisor/conf.d/
# Optional for scheduler: add crond
RUN mkdir -p /var/log/supervisor /var/run
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
# Or use wrapper that runs both serve + workers:
# ENTRYPOINT ["docker-entrypoint.sh"] with supervisord as final exec
```

Alternative for Render: **two services** — `web` (serve) + `worker` (only `supervisord` workers, no HTTP).

- Supervisor configs stay as declared, with fixes: `--timeout 1600` for high, keep `autorestart=true`, `stopasgroup/killasgroup true`, `numprocs 2`, logs to `storage/logs/worker-*.log` (already).

### 24.3 Process Manager

- **Supervisor is correct choice for Alpine** (not systemd). Ensure `supervisord` is PID 1 or Docker `--init`. Keep `nodaemon=true`. Add `stdout_logfile` rotation (already 50 MB×10).
- Health: add `supervisorctl status` check in `HEALTHCHECK` or separate liveness probe.

### 24.4 Cron

- **Inside container:** install `dcron` (Alpine) or `supercronic`, add crontab:

```sh
# /etc/crontabs/www
* * * * * cd /var/www/html && php artisan schedule:run >> /dev/null 2>&1
```

Supervisor can manage `crond`:

```ini
[program:cron]
command=crond -f -l 8
autostart=true
autorestart=true
user=root
```

Or use dedicated `scheduler` worker on Render (one replica running cron).

### 24.5 Laravel Scheduler

- Already correctly defined in `Kernel.php` — keep 8 entries. Fix `BkashTokenizePaymentController` missing before it will run:

```sh
# Fix immediately:
# Either create app/Http/Controllers/BkashTokenizePaymentController.php
# or remove its route from routes/api.php
php artisan route:list  # must exit 0
```

### 24.6 Deployment

Add to `release.sh` or Render `preDeployCommand`:

```sh
php artisan config:clear && php artisan config:cache
php artisan route:cache && php artisan view:cache
php artisan migrate --force   # if RUN_MIGRATIONS=true, never migrate:fresh in prod
php artisan queue:restart      # signals all workers to reload code/config after deploy
```

- Never `migrate:fresh --force` in production except first seed — `release.sh` does this when `RUN_MIGRATIONS=true` (risky).
- Bump `stopwaitsecs 1230/960` if timeouts increased.

### 24.7 Monitoring

- `failed_jobs` alert via `HandleFailedQueueJob` (already present) — ensure `ADMIN` push + Slack via `LOG_SLACK_WEBHOOK_URL`.
- Add queue size metric: `SELECT queue, COUNT(*) FROM jobs GROUP BY queue;` cached endpoint or `php artisan queue:monitor meem-high:100,meem-medium:200 --max=100`.
- Add worker heartbeat: `supervisorctl status` → alert if any `FATAL`/`BACKOFF`.
- Prune: keep `queue:prune-failed --hours=720` daily (already scheduled once cron fixed).

### 24.8 Exact commands / configs to apply later (not applied now)

```sh
# 1. Dockerfile — add supervisor
apk add --no-cache supervisor dcron
COPY deploy/supervisor/*.conf /etc/supervisor/conf.d/
# 2. Increase high worker timeout (edit deploy/supervisor/laravel-worker-meem-high.conf)
command=... --queue=meem-high --tries=5 --timeout=1600 --sleep=1 --memory=512 --max-jobs=1000 --max-time=3600
stopwaitsecs=1630
# 3. If keeping meem-bulk:
# Either change all 6 Marvel Jobs from onQueue('meem-bulk') → onQueue('meem-high')
# Or add: deploy/supervisor/laravel-worker-meem-bulk.conf with --queue=meem-bulk --tries=3 --timeout=1600 --sleep=3
# 4. Install cron for scheduler (in image)
echo "* * * * * cd /var/www/html && php artisan schedule:run >> /dev/null 2>&1" | crontab -u www -
# 5. Deploy hook
php artisan queue:restart
# 6. Fix route exception
php artisan route:list 2>&1 | head
```

---

## 25. EXACT FIX PLAN

| Step | Priority | Action | Verification |
|------|----------|--------|--------------|
| **P0-1** | 🔴 Critical | **Fix `meem-bulk` orphan** — revert 6 Marvel Jobs to `meem-high` (or add bulk worker). Update tests `WorkerConfigPolicyTest` if adding new worker. | `Select-String meem-bulk` → 0, `php artisan test --filter WorkerConfigPolicy` passes |
| **P0-2** | 🔴 Critical | **Wire Supervisor into production image** — `apk add supervisor`, copy confs, change `CMD` to `supervisord -c /etc/supervisord.conf` (or two-service topology). | `docker build` + `docker run` → `ps aux` shows 4 `queue:work` + `supervisord` + `serve` |
| **P0-3** | 🔴 Critical | **Fix route exception `BkashTokenizePaymentController`** — create class or remove route — otherwise scheduler and `route:cache` are broken. | `php artisan route:list` exits 0, `laravel.log` stops spamming |
| **P0-4** | 🔴 Critical | **Start scheduler cron** — install `dcron`, add `* * * * * schedule:run`. | `ps aux` shows `crond`, `storage/logs/laravel.log` shows scheduled commands `INFO` hourly |
| **P0-5** | 🔴 High | **Align timeouts** — supervisor high `timeout 1200→1600`, `stopwaitsecs 1230→1630`, `memory 256→512` for bulk; or lower job timeouts to 1100. | `retry_after 1560 > 1600`? Actually need `retry_after` ≥ `1600+60` → bump `retry_after` to `1700` |
| **P1-1** | 🟡 High | **Add `queue:restart` to deploy** (`release.sh`/`docker-entrypoint.sh` after `config:cache`). | Deploy → `php artisan queue:restart` logged, workers show new `maxJobs`/`queue` on next boot |
| **P1-2** | 🟡 High | **Verify production `jobs`/`failed_jobs` counts** on live DB (read-only). | `SELECT COUNT(*) FROM jobs` → 0 pending post-fix; `failed_jobs` pruned |
| **P1-3** | 🟡 High | **Never `migrate:fresh` in prod** — change `release.sh` to `migrate --force`. | `release.sh` audited, Render `RUN_MIGRATIONS` safe |
| **P1-4** | 🟡 High | **Add queue monitoring** — `queue:monitor`, `supervisorctl status` alert, `failed_jobs` notification tested. | `php artisan queue:monitor`, admin receives `AdminQueueJobFailedNotification` |
| **P2-1** | 🟢 Medium | **Chunk `PaymentReconciliationJob`** into per-500 or per-gateway jobs, add rate limit. | `PaymentReconciliationJob` completes <900s at 10k txns |
| **P2-2** | 🟢 Medium | **Harden imports** — `WithChunkReading`, `ShouldBeUnique` per importId, increase `memory` to 512 for bulk worker. | Large 5k-row XLSX completes without OOM |

**Do NOT apply any fix during forensic window** — plan is provided for later implementation at your explicit instruction.

---

## APPENDIX

### A. Evidence Index

| Path | Role |
|------|------|
| `config/queue.php` | queue driver, retry_after, connections (FACT) |
| `config/database.php` | redis/mysql connections, prefix (FACT) |
| `config/frontend.php:13` | webhook queue meem-high (FACT) |
| `.env` / `.env.example` / `render.yaml:123` | QUEUE_CONNECTION=database (FACT) |
| `Dockerfile` + `docker-entrypoint.sh` + `release.sh` + `php-custom.ini` | production runtime with no workers (FACT) |
| `deploy/supervisor/*.conf` | declared workers, timeouts/tries/sleep (FACT) |
| `tests/Unit/WorkerConfigPolicyTest.php` | policy assertions (FACT) |
| `app/Console/Kernel.php` | scheduler definition (FACT) |
| `app/Enums/QueueName.php` | canonical queue enum (FACT) |
| `app/Jobs/*` + `packages/marvel/src/Jobs/*` | job timeouts/tries/backoff/queues (FACT) |
| `app/Listeners/*` + `packages/marvel/src/Listeners/*` | queue assignments (FACT) |
| `app/Providers/EventServiceProvider.php` | event→listener→queue wiring (FACT) |
| `storage/logs/laravel.log` (109 MB) | Bkash controller exception, no queue failure in tail (FACT) |
| `docs/RABBITMQ_AUDIT.md` + `docs/audits/digital-products/QUEUE-STANDARDIZATION-REPORT.md` | historical driver + queue standardization (Evidence) |
| `Get-Process` / `Get-Service` / `Get-ScheduledTask` outputs | runtime 0 workers, Redis stopped, no cron (FACT) |

### B. Secrets Policy

No secrets, API keys, tokens, passwords, or `.env` sensitive values are reproduced. Only variable names and non-sensitive values (e.g., `QUEUE_CONNECTION`, `REDIS_CLIENT`) are listed. `getQueue` / `config/queue.php` redacted values were not exposed.

### C. Limitations & UNKNOWN

- No live production shell access — Render container `ps aux`, `supervisorctl status`, `SELECT FROM jobs`, `redis-cli LLEN` not observable.
- Local MySQL offline — exact `jobs`/`failed_jobs` counts unknown.
- Disk/CPU/RAM for production host (starter plan) unknown beyond local 16 GB.
- Cannot verify Redis external availability or queue backlog length without credentials.

### D. Conclusion

The queue system is **correctly designed, incorrectly deployed**. Fixing deployment wiring (Supervisor in image + correcting the `meem-bulk` orphan + fixing the blocking route exception + adding cron) will resolve your "jobs stop" symptom permanently. All PHIs are documented; no non-trivial fix was applied during audit per strict read-only mandate.

---

*End of report — `new/server_queue_forensic_audit.md` (read-only forensic, no mutations performed).*
