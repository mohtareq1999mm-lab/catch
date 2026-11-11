# PRODUCTION_NOTIFICATION_USAGE_INVENTORY.md

**Task:** Production Notification Device Usage Inventory — READ-ONLY DISCOVERY (no implementation authorized)
**Project:** Meem Commerce (Laravel 10.30.1, `redq/marvel` monolith)
**Report generated:** 2026-11-11 (observations collected 2026-11-11 13:20–13:40, machine local time +02:00)
**Repository HEAD at investigation:** branch `main`, commit `6cfb63a55de1a033a7a45be86ea78c67ad548e05` ("feat: add guest checkout and order claiming system", 2026-11-11 14:17:18 +0200)

**Investigation status: `DISCOVERY PARTIALLY COMPLETED`** — production database access, production logs, and production runtime configuration are **not available from this workspace**; all production-specific sections are explicitly BLOCKED per operating rule 14. Everything verifiable from the repository and the local environment has been verified and is reported separately and clearly labeled.

---

## 1. Executive Summary

1. **No production environment is reachable from this workspace.** The only configured database is the local development database `catch` on `127.0.0.1:3306` (MySQL 8.4.3, host machine `M-tareq`) — VERIFIED via the repository's own `error/database-target-verification.md` and a read-only runtime probe. The production deployment blueprint (`render.yaml`) stores its database credentials only in the Render dashboard (`sync: false`); no production connection string, host, or credentials exist in the repository or workspace. Per rule 14, **no query was executed against anything labeled "production"**.

2. **The declared production blueprint contains no notification provider configuration at all.** `render.yaml` declares zero `FIREBASE_CLIENT_*`, `FCM_ENABLED`, `FCM_CREDENTIALS_PATH`, or `PUSHER_APP_*` variables (grep across the entire file: no matches). If the live Render service mirrors this blueprint, Path A (Firebase) cannot initialize and Path B (Push) runs in simulation mode in production — **UNVERIFIED (UNKNOWN)** because the live dashboard may have drifted from the blueprint.

3. **Both device-token tables are empty in every database visible to this workspace** (local dev `catch`: `device_tokens` = 0, `user_device_tokens` = 0, `order_notifications` = 0, `user_notification_preferences` = 0). Actual production usage is UNKNOWN.

4. **The two push paths remain structurally disjoint at current HEAD** (re-verified): Path A reads exclusively `device_tokens` (`app/Jobs/SendFcmNotificationJob.php:42-43`); Path B reads exclusively `user_device_tokens` (`app/Services/Notifications/PushNotificationService.php:22`). No code reads both.

5. **Installed FCM SDK (kreait/firebase-php 6.7.0) still sends multicast via the retired batch endpoint** (`vendor/kreait/firebase-php/src/Firebase/Messaging/Http/Request/SendMessages.php:48` → `https://fcm.googleapis.com/batch`), and `FcmService` swallows all exceptions (`app/Services/Firebase/FcmService.php:50-53`), so any such failure would be silent. **No FCM provider HTTP call has ever been logged in this workspace** (0 matches for `fcm.googleapis.com`, `FCM send failed`, `FCM transient failure`, `FCM skipped` across a 137 MB local log) — production behavior remains UNKNOWN.

6. **No duplicate-send protection exists** (no `ShouldBeUnique`, no `lockForUpdate` in `SendFcmNotificationJob` or `SendOrderPushNotification` — grep at HEAD: 0 matches in both files).

7. **Incidental evidence that a larger, production-like dataset was once connected to this workspace:** a real-context (non-test, log channel `local`) Path B execution logged on 2026-10-11 10:31:14 for `user_id=616`; the local database's max `users.id` is 35, so that execution was running against a different database. Which database that was is UNKNOWN.

**Conclusion for the A/B/C decision:** insufficient production evidence exists today to select Option A or B. The only evidence-compatible interim posture is Option C (retain both, fix independently verified risks) — but per the task mandate, no option is selected or implemented here.

---

## 2. Environment and Access Verification

### 2.1 Repository state (VERIFIED)
- Branch: `main` — `git rev-parse --abbrev-ref HEAD`
- HEAD: `6cfb63a55de1a033a7a45be86ea78c67ad548e05`
- Last commit: `feat: add guest checkout and order claiming system` (2026-11-11 14:17:18 +0200)

### 2.2 Existing notification audit reports (INSPECTED)
- No notification-specific report file exists under `docs/audits/` (listing checked 2026-11-11; 19 audit files, none notification-related).
- The prior notification/device-token audit findings exist in this session's transcript and were **re-verified against the current repository** in section 8 below rather than trusted as proof.

### 2.3 Database connection identity (VERIFIED — no credentials printed)
Evidence chain:
1. `error/database-target-verification.md:4-31` — the workspace `.env` resolves `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_DATABASE=catch` (password redacted by that document). Its runtime probe confirmed `DATABASE()=catch`, `@@port=3306`, `@@hostname=M-tareq`, `VERSION()=8.4.3`.
2. This session's read-only probe: `SELECT DATABASE()` → `catch`; local DB contains 35 users, max `users.id` = 35, 255 orders.
3. Repo-wide search for remote connection strings (`tidb|mysql://|@tcp(|...`): no production connection material exists in the workspace (only `config/excel.php` incidental matches).

**Production architecture per blueprint** (`render.yaml`, VERIFIED content):
- Production DB: TiDB Cloud (MySQL-compatible), `DB_HOST` set via dashboard secret (`sync: false` — line ~112 area), port 4000.
- Redis with `meem_*` prefixes; `QUEUE_CONNECTION=database`; `QUEUE_HIGH=high`, `QUEUE_MEDIUM=medium` (lines 128-135).
- Workers: in-container Supervisor (referenced via `docker-entrypoint.sh`, which is **absent from the repo** — pre-existing finding, still true at HEAD); alternative commented-out Render worker services use `${QUEUE_HIGH:-high}` / `${QUEUE_MEDIUM:-medium}` (lines 163/169).
- `LOG_CHANNEL=stderr` (logs go to the Render dashboard — **not accessible from this workspace**).

### 2.4 Access verification outcome
- Production database credentials: **not present** in workspace → cannot connect → **production queries BLOCKED** (rule 14).
- `SHOW GRANTS FOR CURRENT_USER()` was not executed because there is no production connection to verify.
- The local dev DB was queried only with SELECT aggregates (see sections 3/14), which is outside the production rules and clearly labeled LOCAL DEV.

---

## 3. Production Database Inventory — **BLOCKED (rule 14)**

**No production SELECT was executed.** The inventory below is the ready-to-run, SELECT-only query pack for a maintainer with production access. It was syntax-validated by execution against the local dev database (aggregates only; no token values, no PII). Local dev results are shown as a clearly-labeled baseline.

### 3.1 Ready-to-run production query pack (SELECT-only)
```sql
-- [P-SCALE] scale context
SELECT (SELECT COUNT(*) FROM users) AS users, (SELECT COUNT(*) FROM orders) AS orders;

-- [P-DT-1] device_tokens totals
SELECT COUNT(*) AS total, SUM(last_used_at IS NULL) AS null_last_used,
       SUM(client IS NULL) AS null_client, SUM(platform IS NULL) AS null_platform,
       MIN(created_at) AS first_created, MAX(created_at) AS last_created,
       MAX(last_used_at) AS latest_activity
FROM device_tokens;
-- [P-DT-2] device_tokens by client
SELECT client, COUNT(*) AS c FROM device_tokens GROUP BY client;
-- [P-DT-3] device_tokens by platform
SELECT platform, COUNT(*) AS c FROM device_tokens GROUP BY platform;
-- [P-DT-4] device_tokens: tokens owned by >1 user (schema makes this 0; proves constraint)
SELECT COUNT(*) AS multi_user_tokens FROM
  (SELECT token FROM device_tokens GROUP BY token HAVING COUNT(DISTINCT user_id) > 1) t;

-- [P-UDT-1] user_device_tokens totals
SELECT COUNT(*) AS total, SUM(last_used_at IS NULL) AS null_last_used,
       SUM(device_id IS NULL) AS null_device_id,
       MIN(created_at) AS first_created, MAX(created_at) AS last_created,
       MAX(last_used_at) AS latest_activity
FROM user_device_tokens;
-- [P-UDT-2] by platform
SELECT platform, COUNT(*) AS c FROM user_device_tokens GROUP BY platform;
-- [P-UDT-3] by is_active
SELECT is_active, COUNT(*) AS c FROM user_device_tokens GROUP BY is_active;
-- [P-UDT-4] tokens owned by >1 user (KEY A/B RISK METRIC)
SELECT COUNT(*) AS multi_user_tokens FROM
  (SELECT token FROM user_device_tokens GROUP BY token HAVING COUNT(DISTINCT user_id) > 1) t;
-- [P-UDT-5] device_id shared by >1 user
SELECT COUNT(*) AS multi_user_device_ids FROM
  (SELECT device_id FROM user_device_tokens WHERE device_id IS NOT NULL
   GROUP BY device_id HAVING COUNT(DISTINCT user_id) > 1) t;
-- [P-UDT-6] expires_at state
SELECT SUM(expires_at IS NULL) AS null_expires, SUM(expires_at IS NOT NULL) AS has_expires,
       SUM(expires_at IS NOT NULL AND expires_at <= NOW()) AS expired
FROM user_device_tokens;

-- [P-ON-1] order_notifications by status
SELECT status, COUNT(*) AS c FROM order_notifications GROUP BY status;
-- [P-ON-2] by channel
SELECT channel, COUNT(*) AS c FROM order_notifications GROUP BY channel;
-- [P-ON-3] by event_type (actual column; no notification_type column exists)
SELECT event_type, COUNT(*) AS c FROM order_notifications GROUP BY event_type;
-- [P-ON-4] activity window
SELECT COUNT(*) AS total, MIN(created_at) AS first_created, MAX(created_at) AS last_created
FROM order_notifications;

-- [P-UNP-1] user_notification_preferences totals + push distribution
SELECT COUNT(*) AS total, SUM(push_enabled = 1) AS push_on, SUM(push_enabled = 0) AS push_off
FROM user_notification_preferences;
-- [P-UNP-2] users without preference records
SELECT (SELECT COUNT(*) FROM users) - (SELECT COUNT(DISTINCT user_id) FROM user_notification_preferences) AS users_without_prefs;

-- [P-NOTIF] Laravel notifications table (Path A database channel)
SELECT COUNT(*) AS total, MIN(created_at) AS first_created, MAX(created_at) AS last_created FROM notifications;

-- [P-QUEUE] queue health
SELECT (SELECT COUNT(*) FROM failed_jobs) AS failed_jobs, (SELECT COUNT(*) FROM jobs) AS pending_jobs;
```

### 3.2 LOCAL DEV baseline (NOT production — `catch` @ 127.0.0.1, TESTED via probe 2026-11-11 13:32)

| Query | Result |
|---|---|
| Scale | users=35, orders=255 |
| `device_tokens` | **total=0** (all aggregates NULL) |
| `user_device_tokens` | **total=0** (all aggregates NULL) |
| `order_notifications` | **total=0** by status/channel/event_type |
| `user_notification_preferences` | **total=0** |
| Laravel `notifications` (Path A DB channel) | **30 rows**, 2026-10-25 → 2026-10-31 |
| `failed_jobs` / `jobs` | **0 / 320 pending** |

Interpretation (INFERRED): device registration has never been exercised against the local dev DB; Path A's database channel has historical local activity; 320 pending jobs with 0 workers indicate queue workers were not running in dev.

---

## 4. Device Token Ownership Analysis

Schema-level semantics (VERIFIED from migrations + deployed DDL; production DATA UNKNOWN):

| Aspect | `device_tokens` (Path A) | `user_device_tokens` (Path B) |
|---|---|---|
| Token uniqueness | **Global UNIQUE** (`device_tokens_token_unique`) | **Composite UNIQUE** (`user_id, token, platform` = `unique_user_token_platform`) |
| Same physical device under 2 users | Impossible (constraint) | **Possible** — old user's row survives with `is_active=1` |
| Ownership transfer | Automatic on re-registration (`DeviceTokenController::store`, `app/Http/Controllers/Api/General/DeviceTokenController.php:27-35` — updateOrCreate keyed by token only) | Not supported — creates a second row (`NotificationPreferencesController::registerDevice`, `app/Http/Controllers/Api/User/NotificationPreferencesController.php:58-72`) |
| Deletion on unregister | Hard DELETE, owner-scoped (`DeviceTokenController.php:44-46`) | Soft deactivate `is_active=false`, owner-scoped (`NotificationPreferencesController.php:79-87`) |
| Deletion on logout | **None** (`packages/marvel/src/Http/Controllers/UserController.php:660-673` deletes only the Sanctum token) | **None** (same) |
| Token invalidation cleanup | Job deletes invalid tokens (`SendFcmNotificationJob.php:49-52`) | Deactivation check is dead logic (`PushNotificationService.php:42` compares string literals against `(string)$e->getCode()`; `:91`) |
| Expiry | No concept | `expires_at` column exists but **has zero writers repo-wide** (grep `'expires_at'` — only reads at `UserDeviceToken.php:54-55`) |
| Client (Firebase project) | `client` column (client_a/client_b, `DeviceTokenController.php:16`) | **No client column** |

Production ownership data (multi-user tokens, duplicates, distribution): **UNKNOWN** — blocked (use P-DT-4, P-UDT-4, P-UDT-5).

---

## 5. Mobile Endpoint Usage Evidence — **UNKNOWN**

- Endpoints in scope: `POST/DELETE /api/v1/general/device-tokens` (`routes/api.php:273-274`, group `['api','auth:sanctum','throttle:authenticated']` opened at 215) and `POST /api/v1/user/devices/register`, `DELETE /api/v1/user/devices/{deviceId}` (`routes/api.php:326-327`, group at 323-329).
- Access logs: Laravel does not log HTTP requests by default; local `laravel.log` contains **0 matches** for `device-tokens` and `devices/register` (137 MB scanned, TESTED 2026-11-11 13:33).
- Production access logs live in the Render dashboard (`LOG_CHANNEL=stderr`) — not accessible here.
- Local DB rows for both tables: 0 (cannot prove or disprove usage).
- Per the task rules, endpoint usage is NOT inferred from database rows. **Status: UNKNOWN for all four endpoints, in production.**

---

## 6. Firebase Configuration Audit

### 6.1 LOCAL environment (TESTED via read-only config probe, 2026-11-11 13:31)

| Item | Local value |
|---|---|
| `APP_ENV` | `local` |
| Config cache | **Uncached** (`bootstrap/cache/config.php` absent) |
| `firebase.clients.client_a.project_id` | **NULL** (env var absent) |
| `firebase.clients.client_a.credentials` | **NULL** |
| `firebase.clients.client_b.project_id` | **NULL** |
| `firebase.clients.client_b.credentials` | **NULL** |
| Credential files in `storage/app/firebase/` | **Directory does not exist** |
| `FirebaseProjectResolver::credentialsPath()` for either client | **Throws** "No Firebase credentials configured for client [...]" |
| → Path A FCM capability locally | **Cannot initialize** (exception swallowed by `FcmService.php:50-53` → silent) |
| `services.fcm.enabled` | **false** (env `FCM_ENABLED` absent) |
| `services.fcm.credentials` | **NULL** (`FCM_CREDENTIALS_PATH` absent) |
| → Path B locally | **Simulation mode** (`PushNotificationService.php:66-72` returns `success=true, message_id='test_…'` without any provider call) |
| `broadcasting.default` | `pusher` (Pusher app key/cluster present in local `.env`) |
| `queue.queues.high` / `medium` | `high` / `medium` (env absent → documented neutral fallback) |
| `frontend.queue` | `'high'` |

### 6.2 Production blueprint (`render.yaml`, VERIFIED)
- Grep `PUSHER|FIREBASE|FCM|BROADCAST` across the whole file: **no matches** — the blueprint declares none of these variables.
- Consequence if the live service matches the blueprint: **Path A unconfigured (broken silently), Path B in simulation, Pusher broadcasting unconfigured.** Live drift from the blueprint is possible → **UNKNOWN**.

### 6.3 Do the two paths point to the same Firebase project?
- Locally: **N/A** (neither is configured).
- Production: **UNKNOWN** — requires dashboard values (report only project IDs / booleans, never key material).

---

## 7. Notification Logs and Operational Health (LOCAL EVIDENCE ONLY)

Source: `storage/logs/laravel.log` (137,050,333 bytes, single file, channel name in each entry = `APP_ENV` at logging time). Scanned read-only 2026-11-11 13:32–13:34. **Production logs (Render stderr) are not accessible — all findings below are LOCAL.**

### 7.1 Pattern scan results (counts + classification)

| Pattern | Count | Channels | First / Last | Classification |
|---|---|---|---|---|
| `FCM send failed` | 0 | — | — | No local FCM provider failure ever logged |
| `FCM transient failure` | 0 | — | — | none |
| `FCM skipped` | 0 | — | — | none |
| `fcm.googleapis.com` | 0 | — | — | **No FCM HTTP evidence at all** |
| `FCM invalid tokens removed` | 13 | all `testing` | 2026-10-05 06:03:08 → 2026-11-11 11:59:43 | **Test artifacts** (e.g. `{"client":"client_a","count":1}`) |
| `SendFcmNotificationJob` | 1 | `testing` | 2026-10-12 07:25:46 | **HISTORICAL TESTED evidence** — "SendFcmNotificationJob failed permanently: Table 'catch.device_tokens' doesn't exist" — proves DB-level exceptions DO propagate to `failed()`; provider-level exceptions do not (swallowed) |
| `No active devices` | 6,618 + 1 | `testing` + 1×`local` | 2026-09-30 20:00:44 → 2026-11-11 13:14:51 | 6,618 test artifacts; **1 real-context entry** (see 7.2) |
| `Push notifications disabled` | 0 | — | — | The literal message would come from `PushNotificationService.php:67` — never logged here |
| `simulated` | 52 | `testing` + stack traces | 2026-10-04 → 2026-11-11 | Test-simulation messages (WMS "simulated database failure", ThrowingPusher "Simulated Pusher outage") — **not** Path B simulation logs |
| `Pusher` | 137 | mixed | 2026-10-13 → 2026-11-11 | Includes a **real Pusher HTTP attempt**: `Pusher error: cURL error 6: Could not resolve host: api-eu.pusher.com … apps/2173650/events?auth_key=<REDACTED>` (2026-10-13, channel `testing`) — app id 2173650, cluster `eu` are identifiers |
| `Queue job failed` (case-insensitive) | 819 `testing` + 7 `local` | mixed | 2026-09-30 → 2026-11-11 | Test/dev artifacts (table-not-found, sqlite, excel); **no notification-job failures** |
| `production.ERROR` | 4 | `production` | 2026-10-15 10:45–11:24 | **All four are local tinker (psysh) artifacts** (parse errors, duplicate-key insert on `governorates`) — this workspace was briefly run with APP_ENV=production; not server logs |

### 7.2 Key local log findings

1. **Real-context Path B execution against a non-local dataset (INFERRED):** `[2026-10-11 10:31:14] local.INFO: No active devices for user {"user_id":616}`. The local `catch` DB has max `users.id` = 35 (TESTED); `user_id=616` does not exist locally. Therefore, on 2026-10-11 the application was connected to **some other database with ≥616 users** and Path B's `PushNotificationService::sendToUser()` ran in real app context (with zero devices registered). Which database that was: **UNKNOWN**.
2. **Failure-propagation asymmetry — TESTED historically:** the single permanent FCM failure ever recorded was a *database* error (missing table) reaching `SendFcmNotificationJob::failed()`; provider-level failures cannot reach it because `FcmService` swallows them (`FcmService.php:50-53`).
3. **Secrets-in-logs finding (RISK, new):** the Pusher cURL failure log embeds the full request URL **including `auth_key`** (redacted here). Pusher's auth_key is the public app key, but the pattern shows signed request material can leak into logs; worth flagging for log hygiene.

### 7.3 Production operational health
**UNKNOWN** — production logs are streamed to the Render dashboard and were not accessible. Required evidence: exported stderr logs filtered for the patterns in 7.1.

---

## 8. Queue and Delivery Reliability (re-verified at HEAD 2026-11-11)

| # | Check | Result | Evidence |
|---|---|---|---|
| 1 | Path A uses Laravel Notifications + `FcmChannel` | VERIFIED | `app/Providers/AppServiceProvider.php:35` (`ChannelManager::extend('fcm', …)`) |
| 2 | Path A reads exclusively `device_tokens` | VERIFIED | `app/Jobs/SendFcmNotificationJob.php:42-43` (`DeviceToken::query()->where('user_id',…)`) |
| 3 | Path B uses `PushNotificationService` | VERIFIED | `app/Listeners/SendOrderPushNotification.php:23` |
| 4 | Path B reads exclusively `user_device_tokens` | VERIFIED | `app/Services/Notifications/PushNotificationService.php:22` (`UserDeviceToken::getActiveForUser`) |
| 5 | Kreait SDK version | VERIFIED | composer.lock: `kreait/firebase-php 6.7.0`, `pusher/pusher-php-server 7.2.4` |
| 6 | Actual FCM send implementation | VERIFIED | `FcmService::sendToClient` → `sendMulticast` (`app/Services/Firebase/FcmService.php:34`) |
| 7 | Retired batch endpoint risk | VERIFIED (code) / UNKNOWN (runtime) | `vendor/kreait/firebase-php/src/Firebase/Messaging/Http/Request/SendMessages.php:48` → `https://fcm.googleapis.com/batch`; **zero** local or log evidence of any FCM HTTP call ever succeeding or failing |
| 8 | Exception handling / retry | VERIFIED | `FcmService.php:50-53` swallows all `Throwable`; `SendFcmNotificationJob.php:18-19` `$tries=3/$backoff=[30,120]` are dead code for provider errors; Path B listener `tries=2` with final-attempt swallow (`SendOrderPushNotification.php:75-77`) |
| 9 | Failed sends → Queue propagation | VERIFIED (asymmetric) | Provider failures: never propagate. DB failures: propagate — historically TESTED (log 2026-10-12 permanent failure reached `failed()`) |
| 10 | Duplicate delivery possible | VERIFIED | No `ShouldBeUnique` / `lockForUpdate` in `SendFcmNotificationJob` or `SendOrderPushNotification` (grep: 0 matches at HEAD); queued-notification retry re-runs all channels |
| 11 | Logout unregisters FCM tokens | VERIFIED — **it does not** | `packages/marvel/src/Http/Controllers/UserController.php:668` deletes only the Sanctum access token |
| 12 | Ownership transfer handled safely | VERIFIED (schema-level) | Path A: automatic reassignment (global UNIQUE + updateOrCreate by token). Path B: second row created, previous user's row stays active (RISK) |

---

## 9. Path A vs Path B Comparison

| Aspect | Path A | Path B |
|---|---|---|
| Trigger | 25+ business events → queued listeners → `$user->notify()` | `OrderStatusChanged` event only |
| Registration endpoint | `POST/DELETE /api/v1/general/device-tokens` | `POST /api/v1/user/devices/register`, `DELETE /api/v1/user/devices/{deviceId}` |
| Laravel Model | `App\Models\DeviceToken` (relation targets `App\Models\User` — a scaffold stub!) | `App\Models\UserDeviceToken` (relation targets the real `Marvel\Database\Models\User`) |
| Database table | `device_tokens` | `user_device_tokens` |
| Sending class | `FcmChannel` → `SendFcmNotificationJob` → `FcmService` | `SendOrderPushNotification` → `PushNotificationService` |
| Firebase service | Per-client factories (`client_a`/`client_b`), runtime-cached | Single project, **re-created per device**, no caching |
| Queue | `frontend.queue` ∥ `queue.queues.high`; tries=3/backoff=[30,120] (**dead code** for provider errors) | `queue.queues.high`; tries=2 |
| Retry behavior | None for provider failures (swallowed) | Limited: rethrow until attempts < tries, then swallow; new tracking row per attempt |
| Failure handling | Log-only (`FCM send failed` / per-item transient warning) | `order_notifications` status machine (pending/sent/skipped/failed) — but simulated sends recorded as `sent` |
| Delivery tracking | None (log lines only) | `order_notifications` table |
| Local config state | Cannot initialize (no Firebase config) | Simulation mode (records fake `sent`) |
| Production state | UNKNOWN | UNKNOWN |

---

## 10. Production Risks Ranked by Severity

| Rank | ID | Risk | Status |
|---|---|---|---|
| 1 | R1 | **Production delivery capability entirely unknown; blueprint declares no notification config** — if live, Path A is silently broken and Path B records fake successes | VERIFIED (blueprint absence) + UNKNOWN (live drift) |
| 2 | R2 | FCM batch endpoint retired; SDK 6.7.0 sends multicast there; failures swallowed silently | VERIFIED (code) / UNKNOWN (runtime) |
| 3 | R3 | No duplicate-send protection (no unique locks); queued-notification retries re-send | VERIFIED |
| 4 | R4 | Disjoint token tables → notification loss (device on one path misses other path's events) + duplication (delivered/cancelled fire both paths — `OrderService.php:1388,1390-1396`) | VERIFIED (code); production impact UNKNOWN |
| 5 | R5 | Path B simulation can record `sent` without any provider call (`PushNotificationService.php:66-77` + `SendOrderPushNotification.php:67-68`) | VERIFIED |
| 6 | R6 | Deployment worker artifacts referenced by Dockerfile are absent from the repo (`deploy/supervisor/*`, `docker-entrypoint.sh`, `release.sh`) | VERIFIED (pre-existing, still true at HEAD) |
| 7 | R7 | Signed Pusher request URLs (incl. `auth_key`) can leak into failure logs | VERIFIED (local log evidence) |
| 8 | R8 | Logout does not unregister devices; devices keep receiving pushes after logout | VERIFIED |
| 9 | R9 | Path B invalid-token deactivation is dead logic (string-vs-numeric code comparison) | VERIFIED code / INFERRED runtime |
| 10 | R10 | `user_device_tokens.expires_at` has zero writers (dead column; stale tokens never expire) | VERIFIED |
| 11 | R11 | `App\Models\DeviceToken::user()` targets a scaffold `App\Models\User`, not the real user model | VERIFIED (latent) |

---

## 11. Evidence Gaps and Unknowns

1. Production row counts/distributions for all four tables (blocked — use section 3.1 pack).
2. Which database was connected on 2026-10-11 (the `user_id=616` execution) — a larger dataset was reachable from this workspace at some point; its identity is unknown.
3. Whether the live Render service env matches `render.yaml` (dashboard drift).
4. Production FCM behavior (batch endpoint viability) — zero runtime evidence anywhere.
5. Mobile endpoint usage for all four endpoints — no access logs available; DB rows alone may not be used as proof per task rules.
6. Whether production has multiple Firebase projects (client_a/b) actually configured.
7. Historical intent for `user_device_tokens` (migration comment says "as specified, distinct from existing" — decision record not found in repo).

---

## 12. Option A / B / C Architectural Comparison (evidence required — NO selection)

### Option A — unify on `device_tokens`
- Migration complexity: merge `user_device_tokens` → `device_tokens`; requires adding `is_active`, `device_id`, `device_name`, `app_version`, `os_version`, `expires_at` to `device_tokens`; conflict rule for the same token under two users **undefined** (needs product decision); `platform='web'` rows are invalid under Path A validation (`DeviceTokenController.php:23` allows only android/ios).
- Data loss: unmerged rows from the losing side silently stop receiving Path B events.
- Backward compat: `/user/devices/register` contract must be preserved (response shape, ownership semantics change from "per-user row" to "global token reassignment" — **behavioral change visible to clients**).
- Firebase: client_a/b preserved (good).
- Tests: registration/ownership tests for both endpoints post-unification; duplicate-token conflict tests.
- **Blocked by:** production inventory (P-DT-*, P-UDT-*), endpoint usage evidence, conflict-rule decision.

### Option B — unify on `user_device_tokens`
- Migration complexity: merge `device_tokens` → `user_device_tokens`; **requires adding a `client` column** (Path A's multi-project dispatch `groupBy('client')` depends on it); composite unique changes ownership-transfer semantics of `/device-tokens` (automatic reassignment becomes "new row, old row stays" — **behavioral change**).
- Data loss: same as A, opposite direction; `platform` column is ENUM — dev DB shows the live DDL enum has exactly ios/android/web (adequate).
- Firebase: single-project assumption currently baked into `PushNotificationService` — client support must be added before it can serve Path A.
- Tests: as A, mirrored.
- **Blocked by:** same evidence as A, plus `client` support design.

### Option C — retain both tables; fix independently verified risks
- No schema change; compatible with all current clients.
- Risk-reduction scope (not implemented here): stop recording simulated sends as `sent`; fix dead deactivation logic; per-attempt tracking-row reuse; unique locks for FCM job; decide whether delivered/cancelled should fire both paths (product decision); add token cleanup on logout (product decision).
- **This is the only posture compatible with today's evidence**, but the A/B/C *final* decision still requires the production inventory.

---

## 13. Recommended Next Decision

**The A/B/C architecture decision is NOT yet supportable — no production usage evidence exists.** Recommended sequence (all read-only, no code changes):

1. **Grant production visibility** via one of: (a) a Render dashboard log export for the service (stderr) filtered on the patterns in section 7.1; (b) a **SELECT-only** TiDB user (grant `SELECT` only) whose grants are verified in-session via `SHOW GRANTS FOR CURRENT_USER()` before any query; (c) dashboard confirmation of the live env var *names and boolean states* (`FCM_ENABLED`, `FIREBASE_CLIENT_*`, `PUSHER_APP_*`) without printing values.
2. **Run the section 3.1 query pack** (P-SCALE … P-QUEUE) and record results with timestamps.
3. **Ask the mobile team** which registration endpoint(s) the shipped apps call (resolves section 5 without log access).
4. Only then, hold the A/B/C decision with: production table populations, endpoint usage, live config state, and the R1–R11 risk list as inputs.

---

## 14. Exact Read-Only SQL Queries Used

**Executed (LOCAL DEV `catch` @ 127.0.0.1 only — labeled; aggregates only, no PII/token values):** the full set in section 3.1 was executed as-is on 2026-11-11 13:32:28 via a fixed SELECT-only probe script, plus:
```sql
SELECT DATABASE() AS d;
SELECT COUNT(*) AS c FROM users WHERE id = 616;
SELECT MAX(id) AS m FROM users;
```
(Also `SHOW CREATE TABLE device_tokens; SHOW CREATE TABLE user_device_tokens;` earlier in this session — DDL only.)

**Production pack:** identical to section 3.1 — NOT executed (access absent; rule 14).

---

## 15. Safety and Non-Modification Confirmation

- **No application files were modified.** Only new report files were created (`docs/audits/PRODUCTION_NOTIFICATION_USAGE_INVENTORY.md` — this file — and read-only probe scripts in the external approved temp directory `C:\Users\mohta\AppData\Local\Temp\opencode\`, outside the repository).
- **No migrations were executed.**
- **No `.env` or configuration values were changed or printed** (only key-name presence and non-secret identifiers; Pusher `auth_key` redacted).
- **No production database connection was made** (none exists in the workspace); **no production records were changed; no production schema was modified.**
- **Local dev DB (`catch`): only SELECT aggregates + SHOW CREATE TABLE were executed; zero writes, zero schema changes** (independently confirmed: counts of users/orders consistent with prior known values 35/255).
- **No real notifications were sent** (FCM, Pusher, email, SMS — none).
- **No production jobs were triggered.** No queue workers, schedulers, or seeders were started.
- **No tests were run against production.** No tests were run at all in this phase.
- **All executed SQL statements were SELECT-only** (plus read-only DDL statements).
- **Read-only database access was independently verified:** for the local DB, verification was by construction (fixed SELECT-only scripts) and a prior data-loss-incident gate in the repo (`error/DATA-LOSS-INCIDENT.md`) was respected. **Production read-only verification could NOT be established — production queries were therefore not executed, per rule 14.**

---

*Report end. This document is discovery evidence only; it authorizes no implementation.*
