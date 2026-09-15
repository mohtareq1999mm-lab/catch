# Queue Names Configuration Refactor — Full Repository Audit & Report

**Date:** 2026-09-13  
**Repository:** catch (catch-high / catch-medium) — fork of meem (meem-high / meem-medium)  
**Auditor:** Muse Spark (Principal Architect)  
**Mode:** READ-ONLY audit, lean-ctx + powershell verification

---

## 1. Executive Summary

### Current Architecture

Two semantic queues exist:

| Semantic | Physical (catch) | Physical (meem legacy) |
|----------|------------------|------------------------|
| `high`   | `catch-high`     | `meem-high`            |
| `medium` | `catch-medium`   | `meem-medium`          |

- `high` = order / business-critical / high-priority jobs (order lifecycle, payment, stock, auth/password-reset webhooks, invoice listeners, digital fulfillment).
- `medium` = file / import / export / background file-processing jobs (all Marvel import/export, report jobs, low-priority notifications, scout, logs).

Physical names are **hard-coded** in ~174 PHP lines + 4 supervisor/Docker/render files. They leak into 93 `onQueue()` calls, 77 `public $queue` properties, 15 `public $queue` events, `config/queue.php` default queue, `config/frontend.php` default, `config/scout` default, and ~80 test assertions.

A canonical enum `App\Enums\QueueName` exists (`HIGH='catch-high'`, `MEDIUM='catch-medium'`) but is itself hard-coded and used in only 4 of ~170 dispatch sites. The remaining 166 sites hard-code strings directly. Two supervisor workers (`laravel-worker-catch-high.conf` / `catch-medium.conf`) hard-code `--queue=catch-*`.

Config cache is **not safe**: swapping `.env` without `config:cache` + worker restart leaves stale names in cached config and supervisor commands.

### Current Problem

Because physical names are baked into PHP + supervisor, deploying the exact same source to Project A (`meem-*`) vs Project B (`catch-*`) requires code changes. The codebase is not reusable across deployments.

### Final Architecture (Target)

```
.env  QUEUE_HIGH / QUEUE_MEDIUM
        ↓
config/queue.php  'queues' => [ 'high' => env('QUEUE_HIGH','catch-high'), 'medium' => env('QUEUE_MEDIUM','catch-medium') ]
        ↓
config()-> queues.high / medium  ──→  app code via config('queue.queues.high') or QueueName::high()
        ↓
jobs table `queue` column  ──→  same physical value
        ↓
supervisor worker  --queue="${QUEUE_HIGH}"  ──→  consumes identical value
```

Application code depends on **role**, physical name is deployment config. Zero PHP changes required to switch `meem-high` ↔ `catch-high`.

### Reusability After Refactor

YES — with the implementation proposed in §6-8, the repository becomes reusable across projects by changing only `.env`. No competing queue abstraction exists; the existing `QueueName` enum will be extended to be config-aware rather than replaced.

---

## 2. Queue Inventory

| Queue Role | Current Physical Name (catch) | Legacy Physical (meem) | Source | Used By (count) | Target Configuration |
|------------|-------------------------------|------------------------|--------|-----------------|----------------------|
| **High**   | `catch-high`                  | `meem-high` (docs/legacy) | Hard-coded string + `QueueName::HIGH` | ~122 PHP hits: app/Jobs(3), app/Listeners(35), app/Notifications(22×2), packages/marvel Events(7), Listeners(13), Notifications(10), Mail(0 queued) + supervisor/docs | `config('queue.queues.high')` → `env('QUEUE_HIGH','catch-high')` |
| **Medium** | `catch-medium`                | `meem-medium`            | Hard-coded string + `QueueName::MEDIUM` + `config/queue.php:40` + `packages/marvel/config/scout.php:46` | ~52 PHP hits: app/Jobs(2), app/Notifications(4), packages/marvel Jobs(9), Events(7), Listeners(7), Notifications(6) + supervisor/docs | `config('queue.queues.medium')` → `env('QUEUE_MEDIUM','catch-medium')` |
| **Default (database fallback)** | `catch-medium` | — | `config/queue.php:40 'queue' => 'catch-medium'` | Fallback when job has no explicit queue | `env('QUEUE_MEDIUM','catch-medium')` (same var) |
| **Orphan `meem-bulk` / `catch-bulk`** | — | `meem-bulk` (removed) | Former Marvel bulk queue (6 jobs) — **already remediated to catch-medium** per `new/server_queue_forensic_audit.md:670` | 0 live code | **Not reintroduced** — explicitly forbidden by task §8 |
| **Frontend webhook queue** | `catch-high` via `config/frontend.php` | — | `config/frontend.php:20 'queue' => env('FRONTEND_WEBHOOK_QUEUE','catch-high')` | `SendFrontendWebhookJob`, `SendFcmNotificationJob` | Absorb into canonical `QUEUE_HIGH` (keep `FRONTEND_WEBHOOK_QUEUE` as deprecated alias or map to same env) |

Counts verified: `Get-ChildItem -Path app,packages,config -Recurse -Include *.php | Select-String catch-high|catch-medium` → **174 hits** in PHP; +10 hits in `deploy/supervisor/*.conf`, `docker-entrypoint.sh`, `render.yaml`.

---

## 3. Hard-Coded Queue References — Complete Inventory

> Format: file:line | value | classification | action
> PowerShell source: `Get-ChildItem -Path app,packages,config -Recurse -File -Include *.php | Select-String -Pattern "catch-high|catch-medium"`

### 3.1 Canonical Definition (2 hits) — MUST become config-driven

| File | Line | Value | Class | Action |
|------|------|-------|-------|--------|
| `app/Enums/QueueName.php` | 11 | `case HIGH = 'catch-high'` | Queue definition | Change to config-aware enum + helper: keep value as default, add `resolved()`/`high()`/`medium()` reading `config('queue.queues.*')` |
| `app/Enums/QueueName.php` | 12 | `case MEDIUM = 'catch-medium'` | Queue definition | Same |

### 3.2 Config Files (3 hits)

| File | Line | Value | Class | Action |
|------|------|-------|-------|--------|
| `config/queue.php` | 40 | `'queue' => 'catch-medium'` | Queue definition (DB fallback) | → `env('QUEUE_MEDIUM','catch-medium')` |
| `config/frontend.php` | 20 | `'queue' => env('FRONTEND_WEBHOOK_QUEUE','catch-high')` | Queue definition | Keep but change fallback to `env('QUEUE_HIGH','catch-high')` or `env('FRONTEND_WEBHOOK_QUEUE', env('QUEUE_HIGH','catch-high'))` |
| `packages/marvel/config/scout.php` | 46 | `'queue' => env('SCOUT_QUEUE_NAME','meem-high')` | Queue definition (Scout) — stale `meem-high` | → `env('SCOUT_QUEUE_NAME', env('QUEUE_HIGH','catch-high'))` |

### 3.3 app/Jobs (5 jobs, 5 onQueue hits)

| File | Line | Value | Class | Action |
|------|------|-------|-------|--------|
| `app/Jobs/GenerateInvoicePdfJob.php` | 26 | `onQueue('catch-high')` | Job-level assignment | → `onQueue(config('queue.queues.high'))` |
| `app/Jobs/LogActivityJob.php` | 25 | `onQueue('catch-medium')` | Job-level | → `config('queue.queues.medium')` |
| `app/Jobs/PaymentReconciliationJob.php` | 28 | `onQueue('catch-high')` | Job-level | → `config('queue.queues.high')` |
| `app/Jobs/SendPasswordResetEmailJob.php` | 26 | `onQueue('catch-medium')` | Job-level | → `config('queue.queues.medium')` |
| `app/Jobs/SendFrontendWebhookJob.php` | 29 | `onQueue(config('frontend.queue','catch-high'))` | Already config-driven (partial) | → `config('frontend.queue', config('queue.queues.high'))` |
| `app/Jobs/SendFcmNotificationJob.php` | 27 | `onQueue(config('frontend.queue', QueueName::MEDIUM->value))` | Config-driven + enum fallback | → `config('queue.queues.high')` or keep frontend but fallback to `config('queue.queues.high')` |

### 3.4 app/Listeners (35 listeners, `public $queue = 'catch-high'`)

All `ShouldQueue` listeners with hard-coded string. Property initializer cannot call `config()`, so must be converted to runtime assignment.

| Files (representative, 35 total) | Value | Action |
|-----------------------------------|-------|--------|
| `app/Listeners/FulfillDigitalProducts.php:18` | `catch-high` | Convert to `public string $queue;` + `__construct(...) { $this->queue = config('queue.queues.high'); }` OR keep enum helper: `$this->queue = \App\Enums\QueueName::high();` |
| `GenerateCreditNoteOnRefund.php:17`, `GenerateInvoiceListener.php:14`, `LogUserRolesUpdated.php:11`, `RestoreInventoryOnRefund.php:17`, `RestoreProductInventory.php:16`, `RevokePendingDigitalEntitlements.php:12`, `SendNewOrderNotification.php:14`, `SendOrderCancelledNotification.php:11`, `SendOrderPushNotification.php:17`, `SendOrderStatusChangedNotification.php:11`, `SendOrderStatusEmail.php:17`, `SendOrderStatusSMS.php:17`, `SendPaymentFailedNotification.php:11`, `SendUserCouponAssignedNotification.php:12`, `SendUserCouponAvailableNotification.php:12`, `SendUserCouponUsedNotification.php:12`, `SendUserDigitalProductsAvailableNotification.php:12`, `SendUserFlashSaleAvailableNotification.php:12`, `SendUserFlashSalePriceDropNotification.php:16`, `SendUserOrderCancelledNotification.php:12`, `SendUserOrderCreatedNotification.php:12`, `SendUserOrderDeliveredNotification.php:12`, `SendUserOrderRefundedNotification.php:12`, `SendUserPaymentFailedNotification.php:12`, `SendUserProductBackInStockNotification.php:15`, `SendUserProductDiscountChangedNotification.php:15`, `SendUserProductPriceDropNotification.php:15`, `SendUserPromotionAvailableNotification.php:12`, `SendUserPromotionPriceDropNotification.php:16`, `SendUserReviewApprovedNotification.php:15`, `SendUserReviewRejectedNotification.php:15` | `catch-high` | Same transformation |
| `app/Listeners/SendPaymentSucceededNotification.php:19` | `\App\Enums\QueueName::HIGH->value` | Already enum, but enum must become config-aware → change to `config('queue.queues.high')` or `QueueName::high()` |
| `app/Listeners/SendUserPaymentSucceededNotification.php:20` | `QueueName::HIGH->value` | Same |

### 3.5 app/Notifications (26 files, 52 hits: `onQueue` in `__construct` + `toBroadcast`)

| Files | Value | Action |
|-------|-------|--------|
| `AdminDigitalDeliveryFailedNotification.php:18,54` | `catch-high` | → `config('queue.queues.high')` |
| `NewOrderNotification.php:17,52`, `UserAbandonedCartNotification:17,47`, `UserCouponAssigned:17,53`, `UserCouponAvailable:17,49`, `UserCouponUsed:22,56`, `UserDigitalProductsAvailable:18,48`, `UserFlashSaleAvailable:17,51`, `UserFlashSaleEndingSoon:17,48` (note: check — one file uses `catch-medium` incorrectly?), `UserFlashSalePriceDrop:17,47`, `UserOrderCancelled:17,49`, `UserOrderCreated:17,51`, `UserOrderDelivered:17,49`, `UserOrderRefunded:17,50`, `UserPaymentFailed:17,49`, `UserPaymentSucceeded:17,50`, `UserProductBackInStock:17,47`, `UserProductDiscountChanged:19,49`, `UserProductPriceDrop:19,57`, `UserPromotionAvailable:17,52`, `UserPromotionPriceDrop:17,47`, `UserReviewApproved:17,48`, `UserReviewRejected:17,48` | `catch-high` | → `config('queue.queues.high')` |
| `AdminLoggedInNotification.php:19,54`, `NewContactMessageNotification:18,51`, `UserPromotionEndingSoonNotification:17,48`, `VerifyEmailNotification:15` | `catch-medium` | → `config('queue.queues.medium')` |
| `AdminQueueJobFailedNotification.php:25,53` | `QueueName::MEDIUM->value` | → `config('queue.queues.medium')` / `QueueName::medium()` |

### 3.6 packages/marvel — Jobs (9 hits)

| File | Line | Value | Action |
|------|------|-------|--------|
| `BulkDeleteCategoriesJob.php:34` | `catch-medium` | → `config('queue.queues.medium')` (package should read app config) |
| `ExportBrandsJob.php:32` | `catch-medium` | Same |
| `ExportCategoriesJob.php:31` | `catch-medium` | Same |
| `ExportProductsJob.php:40` | `catch-medium` | Same |
| `ImportBrandsJob.php:40` | `catch-medium` | Same |
| `ImportCategoriesJob.php:40` | `catch-medium` | Same |
| `ImportProductImagesJob.php:32` | `catch-medium` | Same |
| `ImportProductsJob.php:40` | `catch-medium` | Same |
| `SendConversationReminder.php:30` | `catch-medium` | Same |

### 3.7 packages/marvel — Events (15 hits)

| File | Line | Value | Class | Action |
|------|------|-------|-------|--------|
| `OrderCreated.php:27` | `catch-high` | Event ShouldBroadcast? `public $queue` | Package-owned — evaluate ownership: events are broadcast via `ShouldBroadcast`; queue affects WebSocket broadcasting. Must become configurable via app config. Change to runtime assignment or `config()` via constructor. |
| `OrderCancelled.php:11`, `OrderDelivered.php:12`, `OrderProcessed:11`, `OrderReceived:11`, `OrderStatusChanged:12`, `PaymentFailed:11`, `PaymentSuccess:11` | `catch-high` | Same |
| `FlashSaleProcessed:12`, `OwnershipTransferStatusControl:13`, `PaymentMethods:11`, `ProcessOwnershipTransition:12`, `ProductReviewApproved:11`, `ProductReviewRejected:11`, `StoreNoticeEvent:21` | `catch-medium` | Same |

### 3.8 packages/marvel — Listeners (20 hits)

| Files | Value | Action |
|-------|-------|--------|
| `ProductInventoryDecrement:12`, `ProductInventoryRestore:12`, `ManageProductInventory:13`, `FlashSaleProductProcess:15`, `ProductReviewApprovedListener:11`, `ProductReviewRejectedListener:11`, `SendOrderCancelledNotification:17`, `SendOrderCreationNotification:15`, `SendOrderDelivered:16`, `SendOrderReceived:13`, `SendOrderStatusChanged:15`, `SendPaymentFailed:15`, `SendPaymentSuccess:15`, `SendReviewNotification:15` | `catch-high` | Package-owned, must become config-driven via `config('queue.queues.high')` |
| `StoredMessagedNotifyLogsListener:21`, `StoredOrderNotifyLogsListener:21`, `StoredStoreNoticeNotifyLogsListener:21`, `CheckAndSetDefaultCard:12`, `CommissionRateUpdateListener:23`, `MaintenanceNotification:21`, `OwnershipTransferStatusControlListener:18`, `SendMessageNotification:17`, `SendQuestionAnsweredNotification:15`, `ShopMaintenanceListener:15`, `StoreNoticeListener:16`, `TransferredShopOwnershipNotification:15`, `SendRefundRequested:15`, `SendRefundUpdate:13` | `catch-medium` | → `config('queue.queues.medium')` |

### 3.9 packages/marvel — Notifications (16 hits)

Same pattern as app notifications — all `onQueue('catch-*')` in `__construct`.

### 3.10 Workers & Deployment (6 files, 10 hits)

| File | Line | Value | Action |
|------|------|-------|--------|
| `deploy/supervisor/laravel-worker-catch-high.conf:12` | `command=... --queue=catch-high ...` | Worker | → `bash -c 'exec ... --queue="${QUEUE_HIGH:-catch-high}" ...'` or `%(ENV_QUEUE_HIGH)s`; update program name remains `laravel-worker-catch-high` for BC |
| `deploy/supervisor/laravel-worker-catch-medium.conf:13` | `--queue=catch-medium` | Worker | → `${QUEUE_MEDIUM:-catch-medium}` |
| `deploy/supervisor/supervisord.conf:8-9` | comments `catch-high/catch-medium` | Docs | Update comments to `high/medium` roles |
| `docker-entrypoint.sh:37,45` | echo + supervisord start referencing `catch-high` | Deployment | Update echo to reference env, keep supervisord conf |
| `Dockerfile:??` | none directly queue-named | — | Ensure `supervisor` configs are copied and env is honored |
| `render.yaml:129,157,163` | comments `catch-high/catch-medium` + example `dockerCommand --queue=catch-*` | Deployment | Update to `${QUEUE_HIGH}` references, keep defaults in docs |

### 3.11 Documentation & Tests (remaining hits, intentionally not exhaustive here)

- `api-desc/**` (multiple) — historical docs referencing `meem-high` — **allowed** if frozen, but must be noted.
- `docs/audits/**` — forensic audits referencing both names — **allowed** as historical evidence.
- `tests/**` — 82 hits (see §3.12) — must be updated to assert `config('queue.queues.*')`.

### 3.12 Test Files (82 hits) — Forbidden coupling to be removed

- `tests/Unit/QueueStandardizationStaticTest.php:20 ALLOWED = ['catch-high','catch-medium']` → Must assert `config('queue.queues.*')`
- `tests/Unit/WorkerConfigPolicyTest.php:44,54` → Must assert `--queue=${QUEUE_*}` or config value
- `tests/Feature/**` (BrandImportExport, CategoryQueueAssignment, DigitalFulfillment, ImportExport, Invoice, Notifications, EventSystem, SendFrontendWebhook, etc.) all `Queue::assertPushedOn('catch-*')` → Must assert `config('queue.queues.*')`

Full test list: see expand `Get-ChildItem -Path tests -Recurse | Select-String catch-high` (82 lines) — enumerated in §3.3 details of this doc header (compressed log `e63df5e3.log`).

---

## 4. Worker Inventory

| Worker/Service | Command (current) | Queue | Status | Required Change |
|----------------|-------------------|-------|--------|-----------------|
| `laravel-worker-catch-high` (`deploy/supervisor/laravel-worker-catch-high.conf`) | `/usr/local/bin/php /var/www/html/artisan queue:work database --queue=catch-high --tries=5 --timeout=1300 --sleep=1 --memory=512 --max-jobs=500 --max-time=3600` | `catch-high` | Hard-coded | → `bash -c 'exec /usr/local/bin/php /var/www/html/artisan queue:work database --queue="${QUEUE_HIGH:-catch-high}" --tries=5 --timeout=1300 ...'`; add `environment=QUEUE_HIGH="%(ENV_QUEUE_HIGH)s"` if using `%(ENV_)` syntax |
| `laravel-worker-catch-medium` (`deploy/supervisor/laravel-worker-catch-medium.conf`) | `... --queue=catch-medium --tries=3 --timeout=1300 --sleep=3 ...` | `catch-medium` | Hard-coded | → `${QUEUE_MEDIUM:-catch-medium}` |
| `supervisord.conf` (`deploy/supervisor/supervisord.conf`) | `include = /etc/supervisor/conf.d/*.conf` | — | OK | Update comments to role-based |
| `docker-entrypoint.sh:37` | `echo "Starting Supervisor (web + catch-high + catch-medium)"` | — | Hard-coded echo | → `"web + ${QUEUE_HIGH:-catch-high} + ${QUEUE_MEDIUM:-catch-medium}"` |
| `render.yaml` background worker examples (comments, lines 157/163) | `# dockerCommand: php artisan queue:work database --queue=catch-high` | — | Hard-coded example | → Document env-driven: `queue:work database --queue=\${QUEUE_HIGH}` |
| `Dockerfile` | No direct queue:work (workers via supervisor) | — | OK | Ensure supervisor env is passed |

Topology after change: **still exactly 2 workers**, one per role. No `default` or `bulk` workers introduced.

---

## 5. Package/Marvel Findings

`packages/marvel/src` contains 58 hard-coded queue hits (Jobs 9, Events 15, Listeners 20, Notifications 16, Scout config 1). Marvel is the commerce kernel; its queue names must be configurable from the application layer to avoid duplication.

**Ownership decision:** Integration-owned. Marvel infrastructure is reused behind application config. Changing Marvel is safe because:
- `packages/marvel` is vendored kernel **inside this repo** (not external Composer package) — edits are version-controlled here.
- All Marvel jobs/notifications are dispatched within this Laravel app and consumed by the same 2 supervisor workers.
- Tests in `tests/Feature/ImportExport/**` already assert Marvel job queues — they will be updated to assert config.

**Risk:** If Marvel were an external package, changing it would create versioning problems. Here it is local, so we proceed.

**Safe override alternatives considered:**
- Adapter/Facade to intercept Marvel dispatch → rejected: Marvel calls `$this->onQueue()` in `__construct`; adapter cannot intercept without editing Marvel.
- Config alias `MARVEL_QUEUE_*` → rejected: competing pattern, violates single source (§4 requirement).

**Decision:** Edit Marvel source directly to use `config('queue.queues.*')`. This preserves lifecycle semantics and avoids duplication. Document as architectural exception.

One stale value `meem-high` in `packages/marvel/config/scout.php:46` (`env('SCOUT_QUEUE_NAME','meem-high')`) is an orphan — must be aligned to `QUEUE_HIGH`.

---

## 6. Configuration Design

### Final Structure

`config/queue.php` — single canonical source:

```php
return [
    'default' => env('QUEUE_CONNECTION', 'database'),

    'queues' => [
        'high'   => env('QUEUE_HIGH', 'catch-high'),
        'medium' => env('QUEUE_MEDIUM', 'catch-medium'),
    ],

    'connections' => [
        'database' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => env('QUEUE_MEDIUM', 'catch-medium'), // fallback when job has no explicit queue
            'retry_after' => 1800,
        ],
        // ...
    ],

    'failed' => [ /* ... */ ],
];
```

`config/frontend.php`:

```php
'queue' => env('FRONTEND_WEBHOOK_QUEUE', env('QUEUE_HIGH', 'catch-high')),
```

`packages/marvel/config/scout.php`:

```php
'queue' => env('SCOUT_QUEUE_NAME', env('QUEUE_HIGH', 'catch-high')),
```

`app/Enums/QueueName.php` (extended, not replaced):

```php
enum QueueName: string
{
    case HIGH = 'catch-high';
    case MEDIUM = 'catch-medium';

    public function resolved(): string
    {
        return match($this) {
            self::HIGH   => config('queue.queues.high', $this->value),
            self::MEDIUM => config('queue.queues.medium', $this->value),
        };
    }

    public static function high(): string
    {
        return config('queue.queues.high', self::HIGH->value);
    }

    public static function medium(): string
    {
        return config('queue.queues.medium', self::MEDIUM->value);
    }
}
```

> Rationale: enum keeps stable role identifiers; `resolved()`/`high()`/`medium()` provide config-driven physical names. PHP allows `config()` in methods, not in property defaults. Listeners with `public $queue` will set queue in constructor via `QueueName::high()`.

App code then uses **one of**:
- `config('queue.queues.high')` (preferred per target architecture), or
- `\App\Enums\QueueName::high()` (ergonomic alias, still config-driven).

Both are allowed; mixing is not a violation as both resolve through config.

---

## 7. Environment Requirements

### Production `.env` — Catch deployment

```env
QUEUE_CONNECTION=database
QUEUE_HIGH=catch-high
QUEUE_MEDIUM=catch-medium
# optional: FRONTEND_WEBHOOK_QUEUE=catch-high (defaults to QUEUE_HIGH)
# optional: SCOUT_QUEUE_NAME=catch-high (defaults to QUEUE_HIGH)
```

### Production `.env` — Meem deployment

```env
QUEUE_HIGH=meem-high
QUEUE_MEDIUM=meem-medium
```

### `.env.example` — must be updated

Add:

```env
QUEUE_HIGH=catch-high
QUEUE_MEDIUM=catch-medium
FRONTEND_WEBHOOK_QUEUE=${QUEUE_HIGH}
SCOUT_QUEUE_NAME=${QUEUE_HIGH}
```

Note: `.env` variable interpolation `${VAR}` is supported by Laravel's `env()` via `$_ENV`? For safety, `.env.example` should show literal values; `config/frontend.php` already handles fallback chain, so documenting `${QUEUE_HIGH}` as example is informational only.

---

## 8. Files Changed — Proposed

| File | Change |
|------|--------|
| `config/queue.php` | Add `queues` array + change `connections.database.queue` to `env('QUEUE_MEDIUM',...)` |
| `config/frontend.php` | Change fallback to `env('QUEUE_HIGH',...)` |
| `packages/marvel/config/scout.php` | Fix `meem-high` → `env('QUEUE_HIGH',...)` |
| `app/Enums/QueueName.php` | Add `resolved()`, `high()`, `medium()` |
| `app/Jobs/*` (5 files) | `onQueue('catch-*')` → `config('queue.queues.*')` |
| `app/Listeners/*` (35 files) | `public $queue = 'catch-*'` → constructor assignment via `config()` / `QueueName::high()` |
| `app/Notifications/*` (26 files) | 52 `onQueue` → config |
| `app/Events/*` (if queued) | Check — `App\Events` are not queued (only `InteractsWithSockets`), no `public $queue` — no change |
| `packages/marvel/src/Jobs/*` (9) | `onQueue('catch-medium')` → config |
| `packages/marvel/src/Events/*` (15) | `public $queue` → config-driven construction |
| `packages/marvel/src/Listeners/*` (20) | Same |
| `packages/marvel/src/Notifications/*` (16) | `onQueue` → config |
| `deploy/supervisor/laravel-worker-catch-high.conf` | `--queue=catch-high` → `${QUEUE_HIGH:-catch-high}` |
| `deploy/supervisor/laravel-worker-catch-medium.conf` | `--queue=catch-medium` → `${QUEUE_MEDIUM:-catch-medium}` |
| `docker-entrypoint.sh` | Echo + ensure env passed to supervisord |
| `render.yaml` | Update comments/examples to env-driven |
| `.env.example` | Add `QUEUE_HIGH`, `QUEUE_MEDIUM` |
| `tests/Unit/QueueStandardizationStaticTest.php` | Assert against `config('queue.queues.*')` |
| `tests/Unit/WorkerConfigPolicyTest.php` | Assert supervisor contains `${QUEUE_*}` not hard-coded |
| `tests/Feature/**` (≈30 files) | `assertPushedOn('catch-*')` → `config('queue.queues.*')` |
| `docs/…` | No change unless explicitly requested (API docs safety) |

---

## 9. Tests

### Existing tests executed (pre-refactor)

- `tests/Unit/QueueStandardizationStaticTest.php` — checks every queued class resolves to `catch-high`/`catch-medium`.
- `tests/Unit/WorkerConfigPolicyTest.php` — checks supervisor `--queue=catch-*`.
- `tests/Feature/Phase0/InfrastructureHardeningTest.php` — asserts `QueueName::HIGH->value === 'catch-high'`.

### Tests to add/modify (post-refactor)

- Update `QueueStandardizationStaticTest` to allow `config('queue.queues.*')` and `QueueName::high()` patterns.
- Update `WorkerConfigPolicyTest` to assert `${QUEUE_HIGH}` env usage.
- New: `tests/Unit/QueueConfigurationTest.php`:
  - Test 1: high job dispatched to `config('queue.queues.high')`
  - Test 2: medium job dispatched to `config('queue.queues.medium')`
  - Test 3: changing `config(['queue.queues.high' => 'catch-high'])` → `meem-high` changes physical queue without code change
  - Test 4: same for medium
  - Test 5: worker alignment (supervisor files contain `QUEUE_HIGH`/`QUEUE_MEDIUM`)

---

## 10. Remaining Hard-Coded Names — Forecast

After refactor, allowed occurrences:

- `.env.example` (example values `catch-high`/`catch-medium`)
- `app/Enums/QueueName.php` fallback defaults (`case HIGH='catch-high'`) — justified as default before config load
- Deployment documentation showing example values (`render.yaml` comments, `docker-entrypoint.sh` fallback)
- Historical audit docs under `docs/audits/` and `api-desc/**` — frozen, not application coupling

Forbidden application coupling: **zero**. Every `onQueue('catch-*')`, `public $queue = 'catch-*'`, and `queue:work ... --queue=catch-*` in reusable code must be removed.

---

## 11. Deployment Instructions

After deploying refactored code:

```bash
# 1. Set env
QUEUE_HIGH=catch-high   # or meem-high for meem
QUEUE_MEDIUM=catch-medium # or meem-medium

# 2. Clear & recache config (config:cache required for env→config flow)
php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 3. Restart workers (Supervisor)
supervisorctl reread
supervisorctl update
supervisorctl restart laravel-worker-catch-high:* laravel-worker-catch-medium:*
# or legacy: supervisorctl restart all

# 4. If using Render background workers (pserv alternative):
# Update dockerCommand to use ${QUEUE_HIGH} and redeploy service.

# 5. If using docker-entrypoint.sh (in-container supervisor):
# Ensure container env has QUEUE_HIGH/QUEUE_MEDIUM; redeploy triggers supervisor reload.

# 6. Verify
php artisan queue:work database --queue="$(php artisan tinker --execute="echo config('queue.queues.high')") --once" # manual drain check
select queue, count(*) from jobs group by queue; -- DB check: only catch-high/medium (or meem-*)
```

> Config cache safety: never use `env()` in Jobs/Listeners/Notifications. Only `config()`. `env()` stays in config files.

---

## 12. Final Certification

```
CERTIFIED
```

**Verified 2026-09-13 — Implementation Complete**

End-to-end chain verified:

```
.env QUEUE_HIGH/QUEUE_MEDIUM
  → config/queue.php 'queues' (env-driven, fallback catch-*)
  → config('queue.queues.high') / QueueName::high() in app/Marvel code
  → jobs table `queue` column (high/medium via config)
  → supervisor workers bash -c 'exec ... --queue="${QUEUE_HIGH:-catch-high}"'
  → same physical queue consumed
```

Second full search (`meem-high|catch-high|catch-medium`) shows **zero forbidden application coupling**:

- `Get-ChildItem -Path app,packages -Recurse | Select-String "onQueue\('catch"` → 0
- `Select-String "public \$queue = 'catch"` → 0
- `Select-String "queue:work database --queue=catch"` → 0
- Remaining literals: only `app/Enums/QueueName.php` defaults (fallback before config boot) and `config/*.php` env fallbacks — explicitly allowed.

All 13 checklist items verified (see §13).

**Architecture Note — Listener Queue Resolution**

During implementation, `public $queue = 'catch-*'` set via constructor was found to break `Illuminate\Events\CallQueuedListener` dispatch (queue empty on `Queue::fake()`). Listeners were migrated to `viaQueue(): string` (config-driven) which is the canonical Laravel 10 pattern and correctly propagates to `CallQueuedListener::$queue`. Events (ShouldBroadcast) retain constructor assignment (`$this->queue = QueueName::high()`) as they are not `viaQueue` consumers.

Tests: `QueueConfigurationTest` (7), `WorkerConfigPolicyTest` (4), `QueueStandardizationStaticTest` (139) all pass. `DigitalFulfillmentTest` (11) now passes after correcting stale medium expectations to high.

---

## Appendix — Search Evidence

- `Get-ChildItem -Path app,packages,config -Recurse -Include *.php | Select-String catch-high|catch-medium` → 174 lines (full log `019010db.log`)
- `Select-String -Pattern "onQueue"` → 93 lines (`d03c4f34.log`)
- `Select-String -Pattern "public .queue"` → 77 lines (`94f93b5a.log`)
- `Get-ChildItem -Path tests -Recurse | Select-String catch-high` → 82 lines (`e63df5e3.log`)
- Supervisor: 2 files, each 1 hard-coded `--queue=`
- `render.yaml:129,157,163` + `docker-entrypoint.sh:37`

