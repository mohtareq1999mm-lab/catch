# Pusher Import/Export Live Events Root Cause Audit

Date: 2026-09-14
Scope: Import + Export realtime delivery (Product/Category/Brand) via `App\Events\FileOperationEvent` on `private-users.{id}`

## 1. Executive Summary

Production symptom: first event (`queued` from controller) reaches Pusher/client; subsequent `progress`/`completed`/`failed` events from the same operation show `file-operation.event.dispatched` + `Broadcasting [...] on channels [private-users.1]` in logs but never arrive at Pusher.

**Proven root cause:** `Broadcasting [...] on channels [private-users.1] with payload:` is emitted **only** by `Illuminate\Broadcasting\Broadcasters\LogBroadcaster::broadcast()` (`vendor/laravel/framework/src/Illuminate/Broadcasting/Broadcasters/LogBroadcaster.php:44`). `PusherBroadcaster::broadcast()` never logs that line — it calls `$pusher->trigger()` directly (`vendor/laravel/framework/src/Illuminate/Broadcasting/Broadcasters/PusherBroadcaster.php:154-171`). Seeing that log in the worker process proves the worker's broadcast driver is `log`, not `pusher`. Therefore progress/terminal events dispatched from queue workers are written to the Laravel log file, never to the Pusher HTTP API. The `queued` event dispatched from the HTTP controller (web/php-fpm) uses the web process's broadcast driver (`pusher` per `.env`), so it succeeds. The delivery chain diverges by process.

Secondary compounding factor (pre-existing, now hardened): `config/broadcasting.php:13` defaults to `env('BROADCAST_DRIVER','log')`. Any process without `BROADCAST_DRIVER=pusher` in its environment (missing env, stale config cache, supervisor env inheritance) silently falls back to `log`. `FileOperationEvent` did not declare an explicit broadcast connection, so it inherited the process default. A single stale `php artisan config:cache` built when `BROADCAST_DRIVER=log` poisons all worker processes until cache is cleared and workers restarted.

## 2. Exact Root Cause

1. `config/broadcasting.php:13` = `env('BROADCAST_DRIVER','log')` — fallback is `log`.
2. `App\Events\FileOperationEvent` (`app/Events/FileOperationEvent.php:33`) implements `ShouldBroadcastNow` without `broadcastConnections()` / `broadcastVia()`, so `Illuminate\Broadcasting\BroadcastEvent::handle()` resolves `connections = [null]` → `BroadcastManager::connection(null)` → default driver.
3. Web process (API controller) has `BROADCAST_DRIVER=pusher` (from `.env` / `.env.example: BROADCAST_DRIVER=pusher`), so `broadcastFileOperationQueued()` (`app/Traits/BroadcastsFileOperationProgress.php:59`) → `FileOperationEvent::dispatch()` → `BroadcastManager::queue()` → `dispatchNow(new BroadcastEvent)` → `PusherBroadcaster::broadcast()` → `Pusher::trigger()` → Pusher → client ✓
4. Worker process (`queue:work database --queue=catch-medium` via `deploy/supervisor/laravel-worker-catch-medium.conf`) inherits env. If `BROADCAST_DRIVER` missing or config cache is stale (`storage/framework/cache/config.php` cached with `'broadcasting' => ['default'=>'log']`), the same `BroadcastEvent` resolves to `LogBroadcaster::broadcast()` → `$logger->info('Broadcasting [...]')` → file log only ✗. `file-operation.event.dispatched` is logged **after** `FileOperationEvent::dispatch()` returns, regardless of which broadcaster handled it, so it falsely appears as success even though `LogBroadcaster` never contacts Pusher.
5. No exception is thrown by `LogBroadcaster`, so `BroadcastsFileOperationProgress::dispatchFileOperationEvent()` try/catch does not log `file-operation.event.broadcast_failed`; it logs `file-operation.event.dispatched` as if delivery succeeded.

Evidence chain proves Case D/E distinction: `Laravel → queue → broadcast worker ✗` is actually `Laravel (worker) → LogBroadcaster → log file ✗ Pusher`.

## 3. Evidence

- `LogBroadcaster.php:44`: `$this->logger->info('Broadcasting ['.$event.'] on channels ['.$channels.'] with payload:'.PHP_EOL.$payload);` — exact string observed in production logs.
- `PusherBroadcaster.php:154-171`: `try { $channels->chunk(100)->each(fn => $this->pusher->trigger(...)); } catch (ApiErrorException $e) { throw new BroadcastException(...); }` — no `Broadcasting` log line.
- `config/broadcasting.php:13`: `'default' => env('BROADCAST_DRIVER', 'log')`
- `.env.example: BROADCAST_DRIVER=pusher` vs `phpunit.xml: <server name="BROADCAST_DRIVER" value="log"/>` — log is the fallback; any env missing the var becomes log.
- `app/Traits/BroadcastsFileOperationProgress.php:305-337`: `dispatchFileOperationEvent()` logs `file-operation.event.dispatched` **after** `FileOperationEvent::dispatch()`, before any Pusher response inspection. `LogBroadcaster` does not throw, so dispatched log is written even though Pusher was never contacted.
- `deploy/supervisor/laravel-worker-catch-medium.conf`: `command=... artisan queue:work database ...` with no explicit `BROADCAST_DRIVER` in command env, relies on host env / config cache.
- Historical fix `docs/production-history.md:1789` B2: `BrandImportService::publishProgress` previously logged `brand.import.progress.dispatched` without calling `broadcastFileOperationProgress` — same false-positive log pattern; fixed to call real dispatch but left connection-default risk.

## 4. Why First Event Works

- `ProductImportController@import`, `CategoryImportController@import`, `BrandImportController@import`, and equivalent Export controllers call `broadcastFileOperationQueued()` **before** `ImportXxxJob::dispatch()` / `ExportXxxJob::dispatch()`.
- That call runs in the HTTP request lifecycle (php-fpm / Laravel web worker), which loads `.env` with `BROADCAST_DRIVER=pusher` and `shop.pusher.enabled=true`, and (if config cache exists) typically has fresh cache built during deploy. So default driver resolves to `pusher`, `PusherBroadcaster` triggers Pusher HTTP API, client receives `*.queued`.

## 5. Why Subsequent Events Fail

- Progress (`writeExplicitProgress` / `publishProgress` / `broadcastFileOperationProgress` at 5%/90%) and terminal (`broadcastFileOperationTerminal` after DB transition) are emitted from `ImportProductsJob::handle()`, `ImportCategoriesJob::handle()`, `ImportBrandsJob::handle()`, `ExportProductsJob::handle()`, `ExportCategoriesJob::handle()`, `ExportBrandsJob::handle()` — all `ShouldQueue` on `config('queue.queues.medium')` (`catch-medium` / `meem-medium`) and executed by the medium queue worker.
- That worker's `broadcasting.default` resolved to `log` (missing env / stale `config:cache`). Therefore every `FileOperationEvent` from the worker went to `LogBroadcaster`, producing the observed `Broadcasting [...]` log line but never calling `Pusher::trigger()`. No `broadcast_failed` is logged because `LogBroadcaster` never throws. The client never receives `*.progress` / `*.completed` / `*.failed`.

## 6. Import Flow (canonical, all entities)

```
POST /api/v1/{products|categories|brands}/import  (auth:sanctum, permission:import-*)
  → CategoryImportController@import / ProductImportController@import / BrandImportController@import
    → FormRequest validation
    → Import::create(['type'=>FileOperationType::{PRODUCT|CATEGORY|BRAND}_IMPORT, status=>'pending', created_by=>userId])
    → (trait) broadcastFileOperationQueued(FILEOP_QUEUED, kind, id, totalRows) → FileOperationEvent(ShouldBroadcastNow) → default broadcaster
      • web process: pusher → Pusher → client ✓ (first event)
    → ImportXxxJob::dispatch(id) on config('queue.queues.medium')  (tries 3, timeout 1200, backoff [60,120,240])
      → queue:work medium picks job
        → Import::whereIn(['pending','processing'])->update(status=>'processing')
        → new XxxImportService(id) → writeExplicitProgress(1.0) → broadcastFileOperationProgress(..., progress=1.0)
        → countRows() → update total_rows
        → writeExplicitProgress(2.0) → broadcast
        → Excel::import(ProductsImport|CategoriesImport|BrandsImport, file)
          • Product: flushProgress() every 10 rows or 30s → broadcastFileOperationProgress(..., smooth progress = processed/total*99)
          • Category: processRows() → writeExplicitProgress(10/60/80/99) + flushProgressTick every 20 rows
          • Brand: processRows() → writeExplicitProgress(10/80/99) + flushProgressTick every 20 rows
          • All via trait → FileOperationEvent::dispatch → worker default broadcaster
            • worker = log → LogBroadcaster → log file only ✗ (subsequent progress lost)
        → finalizeProgress() / atomic terminal update → broadcastFileOperationTerminal(CATEGORY|PRODUCT|BRAND_IMPORT_COMPLETED/FAILED/CANCELLED, status, hasErrors, {progress:100, counters, download_available})
          • worker = log → lost ✗
```

Shared abstraction: `App\Traits\BroadcastsFileOperationProgress` (`broadcastFileOperationQueued/Progress/Terminal/dispatchFileOperationEvent`) + `App\Events\FileOperationEvent` (single event class, `broadcastAs()=eventName`, `broadcastOn()=PrivateChannel('users.'.$userId)`, `broadcastWith()=payload`). Bug is in the shared abstraction's connection resolution, so fix there once covers all entities.

## 7. Export Flow (canonical, all entities)

```
POST /api/v1/{products|categories|brands}/export  (export request)
  → XxxExportController@export
    → Import::create(['type'=>FILEOP_EXPORT, status=>'pending', created_by=>userId])
    → broadcastFileOperationQueued(EXPORT_QUEUED, kind, id) → web process pusher ✓
    → ExportXxxJob::dispatch(id) on medium
      → queue:work medium
        → atomic update status=>'processing'
        → broadcastFileOperationProgress(EXPORT_PROGRESS, kind, id, 5.0, status='processing') → worker log ✗
        → new XxxExport()->collection()->count() → store(filename, 'imports')
        → existence + filesize + ZipArchive validity checks
        → broadcastFileOperationProgress(EXPORT_PROGRESS, 90.0) → worker log ✗
        → cancel signal check (if cancel_{id}.json exists → delete file → status='cancelled' → broadcast CANCELLED → return)
        → atomic update status=>'completed' + file_path/file_name/counters
        → broadcastFileOperationTerminal(EXPORT_COMPLETED, 'completed', false, {progress:100, total=rowCount, download_available:true}) → worker log ✗
        → catch → status='failed' → broadcastFileOperationTerminal(EXPORT_FAILED) → worker log ✗
```

Product export: `ExportProductsJob` (`packages/marvel/src/Jobs/ExportProductsJob.php`) same 5%/90%/terminal pattern but dormant per `docs/architecture/realtime-file-operations.md` G3 — `GET /products/export` remains synchronous download; job exists but not wired to controller. Fix still applies when activated.

## 8. Broadcast Architecture

- Event: `App\Events\FileOperationEvent` (`app/Events/FileOperationEvent.php:33`) `implements ShouldBroadcastNow` (not queued), `InteractsWithSockets`, `Dispatchable`.
  - `broadcastOn(): [new PrivateChannel('users.'.$this->userId)]` → channel `private-users.{id}` (docs `realtime-file-operations.md` Channel: `Broadcast::channel('users.{id}', fn($user,$id)=>(int)$user->id===(int)$id)`).
  - `broadcastAs(): string { return $this->eventName; }` → e.g., `category.import.progress`, `category.import.completed`, `brand.export.failed`, etc. (constants `PRODUCT_IMPORT_PROGRESS` etc. `app/Events/FileOperationEvent.php:36-73`).
  - `broadcastWith(): array { return $this->payload; }` — safe whitelist only (kind, operation_type, id, operation_id, event, state, status, progress, percentage, progress_detail, processed_rows, success_rows, failed_rows, total_rows, has_errors, download_available, timestamp, message).
  - **Missing:** `broadcastConnections()` → defaults to `[null]` → default driver. After fix: `broadcastConnections(): ['pusher']`.
- Legacy: `App\Events\CategoryImportProgress` (`app/Events/CategoryImportProgress.php:16`) also `ShouldBroadcastNow` on same channel/event `category.import.progress`; retained for backward compat per `docs/architecture/realtime-file-operations.md:63`. Also needs same fix.
- Trait: `App\Traits\BroadcastsFileOperationProgress` (`app/Traits/BroadcastsFileOperationProgress.php:24`) — shared `shouldBroadcastFileOperation()` gate (`app.env !== testing` && `shop.pusher.enabled !== false`), `resolveFileOperationOwnerId()` (cached `Import::where('id',$id)->value('created_by')`), `broadcastFileOperationQueued/Cancelling/Progress/Terminal()` builders, `dispatchFileOperationEvent()` (try/catch, `FileOperationEvent::dispatch()`, `Log::info('file-operation.event.dispatched', ...)`, `Log::error('file-operation.event.broadcast_failed', ...)`). `broadcastFileOperationTerminal()` has per-process `fileOperationTerminalEmitted` guard + cross-process DB race check (`Import::where('id',$id)->value('status')` vs attempted status) to emit terminal at most once.
- Failure isolation: every broadcast is wrapped so Pusher outage never fails the business operation (ADR-002 Failure Isolation).

## 9. Queue Architecture

- `config/queue.php`: `'default' => env('QUEUE_CONNECTION','database')`, `'queues'=>['high'=>env('QUEUE_HIGH','catch-high'),'medium'=>env('QUEUE_MEDIUM','catch-medium')]`, `'connections.database'=>['driver'=>'database','table'=>'jobs','queue'=>env('QUEUE_MEDIUM','catch-medium'),'retry_after'=>1800]`.
- All import/export jobs: `onQueue(config('queue.queues.medium'))` → `catch-medium` / `meem-medium`. `tries 3` (imports) / `2` (exports), `timeout 1200` (imports) / `900` (exports), `backoff [60,120,240]`.
- Workers: `deploy/supervisor/laravel-worker-catch-medium.conf` (1 proc, `queue:work database --queue="${QUEUE_MEDIUM:-catch-medium}" --tries=3 --timeout=1300 --sleep=3 --memory=512 --max-jobs=500 --max-time=3600`, `stopwaitsecs=1400`) and `laravel-worker-catch-high.conf` (high queue, webhook etc.). Import/Export **never** on `catch-high`; broadcast via `ShouldBroadcastNow` is **not** queued (uses `dispatchNow`), so high worker is irrelevant to this bug except for `SendFrontendWebhookJob` etc.
- No queue rename needed; jobs correctly on medium; worker correctly consumes medium. The bug is not queue routing but broadcast connection resolution inside the job process.

## 10. Worker Architecture

- Single `catch-medium` worker handles all file operations plus other medium-queue jobs (`GenerateInvoicePdfJob`, `LogActivityJob`, `PaymentReconciliationJob`, import/export). Worker timeout `1300` > job timeout `1200` > `retry_after 1800` ensures no premature re-release (`config/queue.php:32` comment).
- Worker stability: `stopwaitsecs=1400` > `1300` allows graceful SIGTERM; `max-jobs=500` / `max-time=3600` recycles workers to prevent memory bloat from Excel processing (PhpSpreadsheet). No evidence of worker crash/restart causing lost events; logs show `Broadcasting` lines from worker, proving worker was alive for subsequent events.
- The worker processing the Import/Export job is the same process responsible for the broadcast (since `ShouldBroadcastNow` uses `dispatchNow` synchronously inside `handle()`), so no cross-worker handoff is involved. Separating broadcast to a dedicated queue would add latency, not fix this bug.

## 11. Transaction Analysis

- No `DB::transaction()` wraps the entire import; per-row `DB::beginTransaction()/commit()` in `ProductImportService::processProductRow()` etc., and short transactions per entity in `CategoryImportService::upsertCategories()`. Broadcasts are emitted **outside** transactions (progress) or **after** atomic `Import::where(...)->update(['status'=>...])` for terminals (`ExportCategoriesJob:98`, `ImportCategoriesJob:240`, `ImportProductsJob:220` etc.). Terminal rule: "Terminal events are emitted strictly AFTER the corresponding DB update (`broadcastFileOperationTerminal()` guard)" per `realtime-file-operations.md: Timing & Ordering Rules`.
- No `ShouldDispatchAfterCommit` / `after_commit` is used, nor needed, because `ShouldBroadcastNow` bypasses queue and `FileOperationEvent` payload is built from in-memory counters, not DB re-read, so transaction boundaries do not cause broadcast suppression. Cross-process terminal race guard in `broadcastFileOperationTerminal()` (`existing !== status` check) correctly suppresses duplicate terminal with warning `file-operation.event.terminal_suppressed_race`.

## 12. Event Serialization Analysis

- Each `dispatchFileOperationEvent()` creates a **new** `FileOperationEvent($userId, $eventName, $payload)` with a freshly built immutable `$payload` array (`array_merge` canonical keys). No mutable shared event object is reused across progress ticks. Therefore rapidly dispatched `progress 80/99/100` do **not** suffer serialization clobbering — each `BroadcastEvent` clones the event (`new BroadcastEvent(clone $event)` in `BroadcastManager::queue()`).
- No `SerializesModels` on the event (payload is scalar array), so queue serialization is not a factor (event is `dispatchNow`). No ordering guarantee is provided by Pusher, but payload `timestamp` (`now()->toIso8601String()`) and `progress` are authoritative; client reconciles via `fetchOperationStatus()` per `resources/js/file-operations.js:266`.

## 13. Channel Analysis

- All events: `new PrivateChannel('users.'.$this->userId)` → wire name `private-users.{id}` (Laravel prefixes `PrivateChannel` with `private-`). Logs confirm `private-users.1` consistently for all operations, matching `Broadcast::channel('users.{id}', ...)` in `channels.php`. No inconsistency between `users.1` / `private-users.1.*`. Owner resolution via `Import::where('id',$operationId)->value('created_by')` is authoritative; foreign users are denied at auth (`POST /api/v1/broadcasting/auth`), verified by `FileOperationSecurityTest` (channel IDOR). No channel rename required.

## 14. Event Name Analysis

- `FileOperationEvent::broadcastAs()` returns `$this->eventName` verbatim. Constants: `product.import.queued/progress/completed/failed/cancelling/cancelled`, `category.import.*`, `brand.import.*`, `product.export.*`, `category.export.*`, `brand.export.*`, `category.bulk-delete.*` (`app/Events/FileOperationEvent.php:36-73`). No mutation, no namespace prefix (Echo listens with leading dot: `.product.import.progress` per `resources/js/file-operations.js:145`). All six entities use same inventory, verified in `FileOperationEventContractTest`. No duplicate event classes with conflicting `broadcastAs` — `CategoryImportProgress` uses same name `category.import.progress` on same channel for legacy compat. No rename needed.

## 15. Pusher Configuration

- `config/broadcasting.php: pusher` connection: `driver=>pusher`, `key=>env('PUSHER_APP_KEY')`, `secret=>env('PUSHER_APP_SECRET')`, `app_id=>env('PUSHER_APP_ID')`, `options=>['cluster'=>env('PUSHER_APP_CLUSTER'),'useTLS'=>true]`. `default` currently `env('BROADCAST_DRIVER','log')` — fallback is `log` (risky). `.env.example: BROADCAST_DRIVER=pusher`, `PUSHER_ENABLED=true`, `PUSHER_APP_ID/KEY/SECRET=7b3b...`, `PUSHER_APP_CLUSTER=ap2`, `MIX_PUSHER_APP_KEY/CLUSTER` mirroring. `packages/marvel/config/shop.php:124` `pusher.enabled=>env('PUSHER_ENABLED', false)`. Production must have `BROADCAST_DRIVER=pusher` and `PUSHER_ENABLED=true`; verification: web process successfully pushed `queued` event, so credentials are valid. Worker staleness, not credential invalidity, is the cause. No secrets printed in this report.

## 16. Failed Jobs

- `jobs` table (database queue) holds pending/processing jobs on `catch-medium`. Broadcast failures via `LogBroadcaster` do **not** create `failed_jobs` rows (no exception). `PusherBroadcaster` would create `failed_jobs` only if `ApiErrorException` propagated out of `ExportXxxJob::handle()` — but import/export `catch (Throwable $e)` blocks re-throw after `status=failed` update, so broadcast failure inside `dispatchFileOperationEvent` is caught and never fails the operation. Therefore `failed_jobs` inspection around `operation_id=2` at `07:46:10` shows no broadcast-related failures, consistent with `LogBroadcaster` path (no exception). After fix, Pusher outage will log `file-operation.event.broadcast_failed` + `report($e)` but still not fail the operation.

## 17. Production Log Correlation (example 07:46:10, operation_id=2)

```
07:46:10 file-operation.event.dispatched event=category.import.progress operation_id=2 user_id=1 channel=private-users.1
07:46:10 Broadcasting [category.import.progress] on channels [private-users.1] with payload:{...}
07:46:11 Broadcasting [category.import.progress] on channels [private-users.1] with payload:{...}
07:46:12 Broadcasting [category.import.completed] on channels [private-users.1] with payload:{...}
```

- `file-operation.event.dispatched` proves `dispatchFileOperationEvent()` reached `FileOperationEvent::dispatch()` + returned without exception.
- `Broadcasting [...]` proves `LogBroadcaster` handled it (file log), **not** `PusherBroadcaster`. Pusher's API was never called for these lines (verified by absence of `Pusher::trigger()` HTTP access log and by `RecordingPusher` vs `LogBroadcaster` contract).
- No `file-operation.event.broadcast_failed` or `PusherException`/`BroadcastException` around that timestamp → no throw, consistent with `LogBroadcaster`.
- Pusher dashboard (Channels > Stats / Debug console) shows only `category.import.queued` for that operation_id window; no `progress`/`completed` — confirms Case C (`Laravel X Pusher`) for worker events.

## 18. Import Coverage

| Entity | Queued | Progress | Completed | Completed_with_errors | Failed | Cancelling | Cancelled | Status |
|---|---|---|---|---|---|---|---:|---|
| Product | `product.import.queued` via controller | `product.import.progress` via `ProductImportService::writeExplicitProgress/flushProgress` + job terminals | `product.import.completed` (incl. with_errors) | `product.import.failed` | `product.import.cancelling` | `product.import.cancelled` | All use shared trait, fixed |
| Category | `category.import.queued` | `category.import.progress` via `CategoryImportService::publishProgress` (10/60/80/99 + tick) | `category.import.completed` | `category.import.failed` | `category.import.cancelling` | `category.import.cancelled` | Fixed |
| Brand | `brand.import.queued` | `brand.import.progress` via `BrandImportService::publishProgress` (10/80/99 + tick) | `brand.import.completed` | `brand.import.failed` | `brand.import.cancelling` | `brand.import.cancelled` | Fixed (previous false log removed) |

Product/Brand/Category all share `FileOperationEvent` + trait; no entity-specific duplication.

## 19. Export Coverage

| Entity | Queued | Progress 5% | Progress 90% | Completed | Failed | Cancelling | Cancelled | Status |
|---|---|---|---|---|---|---|---|---|
| Product | `product.export.queued` | `product.export.progress` (5,90) | `product.export.completed` | `product.export.failed` | `product.export.cancelling` | `product.export.cancelled` | Job exists (`ExportProductsJob`), controller sync path per G3; trait fix applies when async wired |
| Category | `category.export.queued` | `category.export.progress` (5,90) | `category.export.completed` | `category.export.failed` | `category.export.cancelling` | `category.export.cancelled` | Active (`ExportCategoriesJob`), fixed |
| Brand | `brand.export.queued` | `brand.export.progress` (5,90) | `brand.export.completed` | `brand.export.failed` | `brand.export.cancelling` | `brand.export.cancelled` | Active (`ExportBrandsJob`), fixed |

All exports use `broadcastFileOperationProgress(5%/90%)` + `broadcastFileOperationTerminal(...completed/failed...)` pattern; no `progress.json` signal file (unlike imports).

## 20. Minimal Fix

1. **Make broadcast connection explicit** — add to both broadcast events so they never inherit a stale/log default:
   - `app/Events/FileOperationEvent.php`: implement `public function broadcastConnections(): array { return ['pusher']; }` and set fallback default to `pusher` in config.
   - `app/Events/CategoryImportProgress.php`: same `broadcastConnections(): ['pusher']`.
2. **Harden default fallback** — `config/broadcasting.php:13` `'default' => env('BROADCAST_DRIVER', 'pusher')` (was `'log'`). No queue rename, no worker restart command change, no payload/channel/event rename.
3. **Deployment:** `php artisan config:clear` + `php artisan config:cache` (if used) + `supervisorctl restart laravel-worker-catch-medium:*` to reload env. No code migration.
4. **Preserved:** existing Pusher channel names (`private-users.{id}`), event names, payload shape, operation state machine, API responses, queue names (`catch-medium`/`catch-high`), cancellation behavior (signal file + atomic DB + terminal guard), file generation (existence/ZipArchive checks before `completed` with `download_available=true`).

Total diff: 2 event files + 1 config line. Verified via `RecordingPusher` harness — web and worker both now hit `PusherBroadcaster` even when `broadcasting.default` is forced to `log` in test.

## 21. Regression Risks

- **If `PUSHER_APP_*` missing on worker:** `PusherBroadcaster` will throw `BroadcastException` (wrapping `ApiErrorException`) synchronously inside `handle()`; `dispatchFileOperationEvent()` catches it, logs `file-operation.event.broadcast_failed`, reports, but does **not** fail the import/export (failure isolation). Risk is silent revert to no-realtime (status endpoint recovery still works) — mitigated by deployment check that `PUSHER_ENABLED=true` and `BROADCAST_DRIVER=pusher` are set in worker env.
- **Config cache stale:** explicit `broadcastConnections=['pusher']` still reads `config('broadcasting.connections.pusher')` from cache; if cache is stale with old credentials, Pusher auth will fail (401) and be logged. Mitigated by `config:clear`/`config:cache` on deploy.
- **Rate/flood:** `ShouldBroadcastNow` via `dispatchNow` is synchronous, so rapid progress bursts (e.g., Product `flushProgress` every 10 rows) will issue sequential HTTP `trigger()` calls; Pusher may throttle at >100 req/s per app — our max is <1 req/s for file ops, safe. No throttling added.

## 22. Test Plan

- **Unit:** `FileOperationEventContractTest` — channel `private-users.{id}`, `broadcastAs` passthrough, payload whitelist, JSON serializable, plus `broadcastConnections() === ['pusher']`.
- **Feature (RecordingPusher):** `ProductImportBroadcastTest` (writeExplicitProgress → owner channel, not stranger, terminal exactly once, no broadcast without owner), `BrandImportBroadcastTest` (real dispatch, finalize purity, false log removed), `CategoryImportProgressBroadcastTest`, `ExportBroadcastTest` (category export completed/failed after DB transition, retry silence), `BulkDeleteBroadcastTest` (chunk progress, terminal exactly once), `BroadcastFailureIsolationTest` (ThrowingPusher → operation still succeeds, failure logged), `ImportPayloadCanonicalTest` (payload shape via real broadcaster).
- **Negative:** `app.env=testing` gate disables broadcast (existing `shouldBroadcastFileOperation`); `shop.pusher.enabled=false` disables; unauthenticated `broadcasting/auth` fails (FileOperationSecurityTest).
- **Integration (optional, not in CI):** `CategoryImportProgressRealPusherTest` with live credentials (requires `PUSHER_*`).

## 23. Production Verification Plan

1. Deploy fix + `config:clear` + `supervisorctl restart ...`.
2. Verify `php artisan config:get broadcasting.default` == `pusher` on both web and worker containers; verify `BroadcastManager::connection('pusher') instanceof PusherBroadcaster`.
3. **Category Import canary:** `POST /api/v1/categories/import` (small 10-row file, Idempotency-Key). Expect: `queued` (web, pusher) → 3-4× `progress` (worker, pusher, progress 10/60/80/99) → `completed` (worker, pusher, progress 100, download_available=false if no errors). Correlate: `storage/logs/laravel.log` must **not** contain `Broadcasting [category.import.progress]` for that operation_id (pusher does not log it); must contain `file-operation.event.dispatched` 5-6×; Pusher Debug Console must show 5-6 events on `private-users.{testUserId}` with same `operation_id`; HTTP status `GET /api/v1/categories/import/{id}` must progress `pending→processing→completed`.
4. **Category Export canary:** `POST /api/v1/categories/export` → `queued` → worker `progress 5` → worker `progress 90` → `completed` with `download_available=true` only after file exists and ZipArchive validated; download `GET /api/v1/categories/export/{id}/download` must return XLSX.
5. Repeat for `Brand` and `Product` (product import includes variant/image phases, 99% explicit progress). Check `jobs`/`failed_jobs` empty for these ids; check Pusher Stats for message count increase; check frontend `subscribeFileOperations(userId)` receives `onProgress`/`onTerminal` callbacks.
6. Rollback: if Pusher outage, ops still complete via status polling; no data loss.

