# NOTIFICATION_ARCHITECTURE_DECISION.md

**Project:** Meem Commerce
**Task type:** READ-ONLY DISCOVERY + ARCHITECTURE DESIGN + DECISION ANALYSIS — **Implementation authorization NOT granted.**
**Generated:** 2026-11-11
**Verified repository HEAD at analysis time:** branch `main`, commit `fe31ca5e4757c580b079f601b69c43dac6927c8f` (moved during the day from `6cfb63a55de1a033a7a45be86ea78c67ad548e05`; the delta touched only guest-cart files — see §4)
**Primary constraint:** **NO API request or response contract changes** without explicit, separately documented approval.

**Evidence classes used:** VERIFIED (current source/schema) · TESTED (executed read-only in an allowed local environment) · INFERRED (derived, not directly proven) · UNKNOWN (insufficient evidence) · RISK (requires mitigation).

---

## 1. Executive Summary

The application has two fully independent push-notification paths built on two device-token tables with **different, security-relevant ownership semantics**:

- **Path A** (`device_tokens`): global token uniqueness; re-registration by a different user **reassigns** the token (old owner's row is gone). This is the security-correct invariant: it prevents a previous account from continuing to receive notifications on a device now used by another account. 25+ notification classes deliver through it (`via() = ['database','fcm','broadcast']`).
- **Path B** (`user_device_tokens`): composite uniqueness `(user_id, token, platform)`; the same token can be **active under two users simultaneously** (verified schema + controller logic). This creates a cross-user content-exposure path (the new account's device keeps displaying the previous account's notifications) and double-push scenarios, but it also carries the only per-device metadata (device name/id, app version) and the only delivery-tracking table (`order_notifications`).

**Conclusion of the decision analysis:**

1. **Perfect preservation of both endpoints' externally observable behavior (including registration ownership behavior) is only achievable with Option C (two tables).** Any unification changes exactly one endpoint's ownership behavior (the security-incorrect one on Path B) and therefore requires the owner's explicit approval (§21-D1).
2. Because production row populations, endpoint usage, and live configuration remain UNKNOWN (production access unavailable — §12), migration safety for A or B **cannot be assumed**. Per the decision rules, a final A/B selection is **not supportable today**.
3. **Recommendation: adopt Option C as the explicitly temporary operational posture** (the only option that satisfies the no-API-change constraint without approval), with the risk-reduction list (§16/§13 Phase-0) and the documented target architecture (§17) as the approved-in-principle end state, contingent on the evidence in §22.

All four existing endpoints' exact request/response contracts are frozen in §7 from source; the generated OpenAPI spec (`storage/api-docs/api-docs.json`, 546 KB) does **not** document any of them, so §7 is currently the only complete contract inventory.

---

## 2. Investigation Scope and Safety

- Read-only repository inspection, route/migration/test/source analysis; SELECT-only aggregate probes against the **local dev database** (`catch` @ 127.0.0.1) in the prior inventory session; no writes anywhere.
- Not performed (and not permitted): code/migration/config/test modifications, dependency changes, migrations, seeders, workers, notifications, device registration, production access attempts.
- Production database, Render logs, and live environment configuration remain **unavailable** (re-checked this session — §12). All production statements are UNKNOWN or explicitly sourced from the deployment blueprint.
- One new file created: this report. The prior report `docs/audits/PRODUCTION_NOTIFICATION_USAGE_INVENTORY.md` was **not modified** (verified working-tree: it is the only untracked entry).

---

## 3. Repository and HEAD Verification

| Item | Value | Status |
|---|---|---|
| Repository path | `D:\work\meem` | VERIFIED |
| Branch | `main` | VERIFIED |
| HEAD | `fe31ca5e4757c580b079f601b69c43dac6927c8f` — "feat: implement guest cart management, origin validation, and merge functionality" (2026-11-11 15:34:09 +0200) | VERIFIED |
| Previous known HEAD | `6cfb63a55de1a033a7a45be86ea78c67ad548e05` (2026-11-11 14:17:18 +0200) | superseded |
| Working tree | 1 untracked entry only: `docs/audits/PRODUCTION_NOTIFICATION_USAGE_INVENTORY.md` | VERIFIED |
| Laravel / PHP | 10.30.1 / 8.2 (composer.json `laravel/framework: 10.30.1`; runtime probe earlier today) | VERIFIED |
| Notification-relevant packages | `kreait/firebase-php 6.7.0`, `pusher/pusher-php-server 7.2.4` | VERIFIED (composer.lock) |
| Delta `6cfb63a..fe31ca5` | 3 files: `GuestCartController.php`, `GuestOrigin.php`, `config/cors.php` — **zero notification-related changes** | VERIFIED (git diff --stat) |

---

## 4. Previous Report Revalidation

Every cited notification finding from the prior audit + inventory was re-checked at the new HEAD. The notification code is byte-identical (same line numbers re-confirmed this session):

| Prior finding | Status at `fe31ca5` | Fresh evidence |
|---|---|---|
| Path A reads only `device_tokens` | Still verified | `app/Jobs/SendFcmNotificationJob.php:42-43` |
| Path B reads only `user_device_tokens` | Still verified | `app/Services/Notifications/PushNotificationService.php:22` |
| `SendFcmNotificationJob` queue/tries | Still verified | `:27` (`frontend.queue` ∥ high), `:18-19` (`tries=3`, `backoff=[30,120]`) |
| `FcmService` swallows all `Throwable` | Still verified | `app/Services/Firebase/FcmService.php:50-53`; `sendMulticast` at `:34` |
| `PushNotificationService` simulation mode | Still verified | `:14` (`services.fcm.enabled` default false), `:66-77` (fake `success`, `message_id='test_'.uniqid()`) |
| `SendOrderPushNotification` tries=2 + final-attempt swallow | Still verified | `:15-21`, `:75-77`, `markAsSent` `:68` |
| Double-dispatch on delivered/cancelled | Still verified | `app/Services/General/OrderService.php:1388` (OrderStatusChanged) + `:1391` (OrderCancelled) + `:1395` (OrderDelivered) |
| Logout does not unregister tokens | Still verified | `packages/marvel/src/Http/Controllers/UserController.php:668` (Sanctum token only) |
| Kreait batch endpoint | Still verified | `vendor/kreait/firebase-php/src/Firebase/Messaging/Http/Request/SendMessages.php:48` |
| No `ShouldBeUnique`/locks in both senders | Still verified | grep: 0 matches in `SendFcmNotificationJob.php`, `SendOrderPushNotification.php` |
| Production data/usage UNKNOWN | Still UNKNOWN (re-checked; no new access) | — |
| Not rechecked | Marvel-kernel mail/SMS sweep details (out of scope for this decision; no changes landed in `packages/marvel` per diff) | — |

---

## 5. Complete Architecture Map

```
                          ┌─────────────────────────── PATH A ────────────────────────────┐
Business events            │ Event → queued listener (viaQueue=high) → Notification       │
(orders, payments, coupons,│ (ShouldQueue, onQueue high) via()=['database','fcm','broadcast']
promotions, flash sales,   │   ├─ DatabaseChannel  → notifications table (sync in job)     │
reviews, products,         │   ├─ FcmChannel (custom) → dispatch(SendFcmNotificationJob)   │
digital products, admin)   │   │     queue = frontend.queue ∥ high; tries=3 backoff=[30,120]
                           │   │     → DeviceToken (user_id scope, chunk 500, groupBy client)
                           │   │     → FcmService::sendToClient → Kreait 6.7 sendMulticast
                           │   │        → POST https://fcm.googleapis.com/batch  ⚠ retired
                           │   │     → invalid tokens deleted from device_tokens
                           │   └─ BroadcastChannel → BroadcastNotificationCreated →        │
                           │         BroadcastEvent job (high) → Pusher (users.{id}/       │
                           │         admin.notifications)                                  │
                          └───────────────────────────────────────────────────────────────┘

                          ┌─────────────────────────── PATH B ────────────────────────────┐
OrderService:1388          │ OrderStatusChanged (ShouldDispatchAfterCommit)                │
                           │   → SendOrderPushNotification (queued high, tries=2)          │
                           │      ├─ UserNotificationPreference::isEventEnabled gate       │
                           │      ├─ OrderNotification::create (pending)                   │
                           │      └─ PushNotificationService::sendToUser                   │
                           │          → UserDeviceToken::getActiveForUser                  │
                           │          → per-device Kreait send()  [SIMULATED if disabled]  │
                           │          → markAsSent / markAsSkipped / markAsFailed          │
                          └───────────────────────────────────────────────────────────────┘

Registration endpoints (both auth:sanctum + throttle:authenticated):
  POST/DELETE /api/v1/general/device-tokens        → App\Models\DeviceToken        (Path A)
  POST /api/v1/user/devices/register               → App\Models\UserDeviceToken    (Path B)
  DELETE /api/v1/user/devices/{deviceId}           → App\Models\UserDeviceToken    (Path B)

Support: HandleFailedQueueJob (JobFailed → log + admin DB alert), UserNotificationPreference,
OrderNotification history API, Marvel NotifyLogs (separate, mail/SMS era), firebase.clients.{a,b}.
```

---

## 6. Writer/Reader Matrix

### `device_tokens`
| Role | Class:method | Evidence |
|---|---|---|
| Writer (create/update + ownership reassignment) | `DeviceTokenController::store` — `DeviceToken::updateOrCreate(['token'=>…], …)` | `app/Http/Controllers/Api/General/DeviceTokenController.php:27-35` |
| Writer (delete, owner-scoped) | `DeviceTokenController::destroy` | same file `:44-46` |
| Reader (dispatch) + Writer (invalid-token delete) | `SendFcmNotificationJob::handle` — `DeviceToken::query()->where('user_id',…)->chunk(500)` / `whereIn('token',$invalid)->delete()` | `app/Jobs/SendFcmNotificationJob.php:42-50` |
| Hook | `DeviceToken::booted()` (uuid generation) | `app/Models/DeviceToken.php:15-22` |
| Cascade | FK `users` ON DELETE CASCADE | migration `2026_08_23_073810:14` |
| Scheduled cleanup | **None** (no command references the table — repo-wide grep) | VERIFIED absence |

### `user_device_tokens`
| Role | Class:method | Evidence |
|---|---|---|
| Writer (create/update; **no reassignment**) | `NotificationPreferencesController::registerDevice` — `UserDeviceToken::updateOrCreate([user_id,token,platform], …)` | `app/Http/Controllers/Api/User/NotificationPreferencesController.php:58-72` |
| Writer (soft deactivate, owner-scoped) | `NotificationPreferencesController::unregisterDevice` — `where(user_id)->where(id)` + `deactivate()` | same file `:79-87` |
| Reader (dispatch) + Writer (touchLastUsed; deactivate — dead logic) | `PushNotificationService::sendToUser` | `app/Services/Notifications/PushNotificationService.php:22,39,42-44` |
| Writer (unused — zero callers repo-wide) | `PushNotificationService::registerDevice` | same file `:96-102` |
| Cascade | FK `users` ON DELETE CASCADE | migration `2026_09_12_000003:38` |
| Scheduled cleanup | **None** | VERIFIED absence |

### `order_notifications` (Path B tracking)
Writers: `SendOrderPushNotification` (`:53` pending, `:134` skipped-row), `SendOrderStatusEmail` (`:54,145`), `SendOrderStatusSMS` (`:86,184`); status updates via `markAs*` (`OrderNotification.php:51-85`); reader: `NotificationPreferencesController::notificationHistory` (`:96`). No cleanup command (VERIFIED absence).

### `notifications` (Laravel DB channel — Path A side effect)
Writers: `DatabaseChannel` inside `SendQueuedNotifications` for every `via()`-including-`database` notification; readers: notification REST API (`NotificationTest` coverage) and `broadcast` channel payload reuse.

**Exhaustiveness caveat:** Marvel kernel contains GraphQL/REST repositories that could theoretically reference these models dynamically; a repo-wide grep for all class names found only the entries above, and no Marvel service provider wires any device-token model. Dynamic-invocation risk is assessed LOW but not zero (framework string-based resolution, e.g. `Shop::call`, targets only Marvel controllers — none of which touch device tokens; VERIFIED by grep in `packages/marvel`).

---

## 7. API Contract Freeze Matrix

All four endpoints inspected from source (controllers, trait, lang files, exception handler, routes, tests). **Tests pin only error statuses and partial fields — the full success bodies below are source-derived, not test-pinned.**

### Shared response machinery
- Success wrapper: `Marvel\Traits\ApiResponse::apiResponse($message,$status,$success=true,$data=[])` → `{"status":<int>,"message":<string>,"success":<bool>}` + **`data` key present only when non-empty** (`packages/marvel/src/Traits/ApiResponse.php:14-21`). Messages translated via `message.*` lang keys (`:24-40`).
- Error wrapper (exceptions): `App\Exceptions\Handler::handleJsonResponse` → `{"message":<string>,"status":false,"success":false}` + optional `errors` (`app/Exceptions/Handler.php:226-236`). 422 → message `message.ERROR.INVALID_DATA`, `errors` = field→messages map (`:178-187`). 401 → `message.ERROR.UNAUTHENTICATED` (`:168-171`). JSON returned for any `/api` path (`:86-91`).
- Note the deliberate asymmetry (VERIFIED): trait errors carry `status:<int code>`; Handler errors carry `status:false` (bool) — documented as intentional backward compatibility in `Handler.php:219-225`.

### 7.1 `POST /api/v1/general/device-tokens` (Path A register)
- Route: `routes/api.php:273` inside `prefix('v1/general')` (L58) + group `['api','auth:sanctum','throttle:authenticated']` (L215).
- Controller: `DeviceTokenController::store` (`:18-38`).
- Request: `token` required|string|max:512; `client` required|in:client_a,client_b; `platform` optional|in:android,ios (default applied `'android'`).
- Behavior: `DeviceToken::updateOrCreate(['token' => $token], [user_id, client, platform, last_used_at])` — **global-unique token; a token already owned by another user is REASSIGNED** (old owner's row is updated, not duplicated).
- Success 200: `{"status":200,"message":"Device token registered","success":true,"data":{"uuid":"<uuid>"}}` (data from `:37`; lang `resources/lang/en/message.php:552`, ar `:467`).
- 422: Handler shape with `errors` map (e.g. `{"client":["The selected client is invalid."]}`) — TESTED by `tests/Feature/FcmIntegrationTest.php:85` (asserts 422 + `assertJsonValidationErrors(['client'])`).
- 401: Handler shape (`message.ERROR.UNAUTHENTICATED`) — TESTED `FcmIntegrationTest.php:92` (asserts 401).
- Throttling: `throttle:authenticated` may emit 429 via `HttpException` branch (`Handler.php:189-192`) — shape `{"message":<translated>,"status":false,"success":false}`.

### 7.2 `DELETE /api/v1/general/device-tokens` (Path A unregister)
- Route: `routes/api.php:274` (same groups).
- Request: `token` required|string|max:512 (`:42`).
- Behavior: `DeviceToken::where('token',…)->where('user_id', $user->id)->delete()` — **owner-scoped hard delete; deleting a token owned by someone else silently affects 0 rows** (no error).
- Success 200: `{"status":200,"message":"Device token removed","success":true}` — **no `data` key** (empty data omitted by trait `:19`).
- 401/422/429: as 7.1.

### 7.3 `POST /api/v1/user/devices/register` (Path B register)
- Route: `routes/api.php:326` inside `prefix('v1/user')` (L323) + same middleware; controller adds its own `auth:sanctum` (`NotificationPreferencesController.php:15-18`).
- Request: `token` required|string|max:500; `platform` required|in:ios,android,web; optional `device_name|max:255`, `device_id|max:255`, `app_version|max:50`, `os_version|max:50` (`:49-56`).
- Behavior: `UserDeviceToken::updateOrCreate([user_id, token, platform], [metadata…, is_active=true, last_used_at=now()])` — **same token under a DIFFERENT user creates a SECOND active row (no reassignment)** (key includes `user_id`; unique index `(user_id, token, platform)` permits it).
- Success 200: `{"status":200,"message":"Device registered successfully","success":true,"data":{<full UserDeviceToken model>}}` — model attributes: `id, user_id, token, platform, device_name, device_id, is_active, last_used_at, expires_at, app_version, os_version, created_at, updated_at` (fillable `UserDeviceToken.php:14-25`; casts `:27-31`; lang en `:633`, ar `:542`). Exact attribute list confirmed from the model; JSON field set = model attributes (no `$hidden`).
- 422/401: Handler shapes (TESTED partially: `tests/Feature/NotificationPreferencesTest.php:142-143` asserts 200+`success=true` for success; validation shape not body-pinned by tests).

### 7.4 `DELETE /api/v1/user/devices/{deviceId}` (Path B unregister)
- Route: `routes/api.php:327` (`whereNumber('deviceId')`).
- Behavior: row lookup by `user_id + id`; if absent → `apiResponse(__('message.ERROR.DEVICE_NOT_FOUND'), 404, false)` (`:83-85`) — **trait shape** `{"status":404,"message":"Device not found.","success":false}` (int `status`, no `data`); if found → `$device->deactivate()` (soft, `is_active=false`; row retained).
- Success 200: `{"status":200,"message":"Device unregistered","success":true}` — no `data` (lang en `:635`, ar `:544`).
- 401/429: Handler shapes.

### 7.5 Compatibility assessment per option (summary; full matrix in §9)
- **Option C:** all four contracts byte-identical. ✓
- **Option A (unify on device_tokens superset):** A-pair endpoints unchanged. B-register response `data` fields (`device_name`, `device_id`, `app_version`, `os_version`, `expires_at`, `is_active`) do not exist on `device_tokens` today → require schema extension + a response-shaping adapter; **B registration ownership behavior changes** (2nd-user registration reassigns instead of creating a 2nd active row) → requires approval; B unregister `{deviceId}` identity namespace changes (row ids re-keyed) → clients caching numeric device ids would 404 → mitigation: keep id continuity during migration or treat as behavior change requiring approval.
- **Option B (unify on user_device_tokens superset):** B-pair endpoints unchanged; A-register response `data` (`uuid`) requires adding a `uuid` column + adapter; A ownership behavior already reassignment-compatible; A validation must stay android/ios-only (union platform enum in DB is fine — validation untouched); A token max length 512 vs B column 500 → schema widen required to preserve request contract (`max:512` accepted values must not start failing).

---

## 8. Mobile Consumer Compatibility

| Endpoint | Classification | Evidence |
|---|---|---|
| `POST/DELETE /api/v1/general/device-tokens` | **UNKNOWN — treat as ACTIVE** (documented as the mobile contract) | `docs/fcm-architecture.md` §4a explicitly instructs mobile clients to register here (HISTORICAL DOCUMENTATION); no repo-internal caller; published-app usage cannot be proven from this repository |
| `POST /api/v1/user/devices/register` | **UNKNOWN — treat as potentially ACTIVE** | No frontend callers (`resources/js` grep: 0), **absent from generated OpenAPI** (`storage/api-docs/api-docs.json` grep: 0 matches), no integration tests beyond `NotificationPreferencesTest`; no evidence of any external consumer |
| `DELETE /api/v1/user/devices/{deviceId}` | **UNKNOWN — treat as potentially ACTIVE** | same |
| (context) `GET /api/v1/user/notifications/history` | out of decision scope | — |

- No mobile application source exists in this repository; **deployed-app behavior cannot be proven from here** (rule stated explicitly per task).
- Local dev DB rows for both token tables: 0 (TESTED) — not usable as proof either way.
- `error/api-contracts/README.md` confirms route-level JSON examples were never generated (NOT STARTED).
- **Questions the mobile team must answer:** (1) which of the four endpoints each shipped app version calls; (2) whether any client caches the numeric `deviceId` from `user_device_tokens` (affects re-keying); (3) whether any client depends on the token-coexistence behavior (two accounts on one device); (4) which `client` value Path A apps send (`client_a`/`client_b`) and whether multiple Firebase apps are shipped; (5) whether any client parses `data.uuid` strictly.

---

## 9. Database and Ownership Analysis

### 9.1 Schema facts (VERIFIED from migrations + deployed DDL probes in the prior session)

`device_tokens` (`database/migrations/2026_08_23_073810_create_device_tokens_table.php`):
- PK `id`; UNIQUE `uuid`; `token` varchar(512) **UNIQUE global** (`device_tokens_token_unique`); FK `user_id`→users CASCADE; `client` varchar(32); `platform` varchar(16) default 'android'; `last_used_at`; timestamps; index `(user_id, client)`. No `is_active`, no metadata, no expiry, no soft deletes.

`user_device_tokens` (`database/migrations/2026_09_12_000003_create_user_device_tokens_table.php`):
- PK `id`; FK `user_id`→users CASCADE; `token` varchar(500); `platform` ENUM('ios','android','web') default 'web'; `device_name`, `device_id` (indexed), `is_active` (default 1), `last_used_at`, `expires_at` (**zero writers repo-wide**), `app_version`, `os_version`; timestamps; **UNIQUE composite** `(user_id, token, platform)` named `unique_user_token_platform`; index `(user_id, is_active)`.

### 9.2 Ownership scenario matrix (rows shown as: A = device_tokens world, B = user_device_tokens world, C = both worlds unchanged)

| # | Scenario | A (global unique + reassign) | B (per-user composite) | C (status quo) |
|---|---|---|---|---|
| 1 | User A registers T | row{A,T} | row{A,T,plat} active | A-row + nothing in B (different endpoints!) |
| 2 | A re-registers T | same row updated (idempotent) | same row updated (idempotent) | each path idempotent for same user |
| 3 | **User B registers T** | **A's row REASSIGNED to B — A stops receiving** (secure) | **SECOND row {B,T,plat}; A's row stays ACTIVE → A's notifications keep arriving on B's device** (leak + double-delivery to the physical device) | Path A: secure reassign; Path B: leak persists |
| 4 | A logs out | token unchanged (no cleanup) | token unchanged | no cleanup anywhere (VERIFIED) |
| 5 | B logs in on same device | app re-registers T → reassignment under A | 2nd row coexists | mixed semantics |
| 6 | A logs back in | re-registers T → reassigns back | 3rd… row or update if same platform | mixed |
| 7 | T becomes invalid (FCM UNREGISTERED) | deleted by `SendFcmNotificationJob:50` | deactivation check is dead logic (`PushNotificationService.php:42` vs `:91`) → stays active | A cleans; B accumulates |
| 8 | Same token, different platform value | platform overwritten (single row) | **2nd row (platform in unique key)** | divergent |
| 9 | One physical device, multiple apps/clients | supported via `client` column (client_a/b) | not representable (no client column) | A-only capability |
| 10 | One user, multiple devices | multiple rows (user_id,client index) | multiple rows | both fine |
| 11 | Shared device between users | last-writer-wins (secure) | coexisting active rows (insecure) | divergent |
| 12 | Uninstall + reinstall | new FCM token → new row; old row lingers until first failed send deletes it | new row; old row stays active forever (dead expiry + dead deactivation) | B accumulates |
| 13 | Token rotation | re-registration replaces row (token key) | new row per (token); old row active | B accumulates |
| 14 | Old token remains active | only until next send attempt (self-healing) | indefinitely | B risk |
| 15 | Two concurrent registrations, same token | UNIQUE(token) → one row; second update wins (no error) | composite unique → one row per (user,plat); different users → two rows; same user+plat → single row, race benign (updateOrCreate) | — |

**Ownership invariant conclusion (VERIFIED reasoning, not taste):** the FCM token identifies a *physical app installation*, not an account. The correct security invariant is **one live token row per FCM token, owned by the account that most recently registered it** — i.e., Path A's global uniqueness + reassignment. Path B's composite model violates this for account switches (scenario 3/5) and cannot be fixed by preference gating (preferences are per-user, not per-device-row).

`device_id` reliability: client-supplied, optional, unverified — **must not** be treated as a global physical-device identity (RISK; treat as advisory metadata only).

**Product decision required:** policy for shared/multi-account devices (is last-registered-wins acceptable? should the previous account receive any signal?) — §21-D2.

---

## 10. Notification Delivery and Reliability (re-verified at HEAD)

- **Path A:** event → queued listener (`viaQueue` = `QueueName::high()` = `config('queue.queues.high')`) → `SendQueuedNotifications` (high) runs channels sequentially: database → fcm → broadcast. FCM channel only `dispatch()`es `SendFcmNotificationJob` (queue `frontend.queue` ∥ high). The FCM job targets only the notifiable's tokens (security fix, `:32-40`), chunks 500, groups by client, `sendMulticast` per client-group. Invalid tokens deleted (`:49-52`). **All provider exceptions swallowed** (`FcmService.php:50-53`) → `tries/backoff` are dead for provider errors; **database-level errors propagate** (historically TESTED: "SendFcmNotificationJob failed permanently — Table 'catch.device_tokens' doesn't exist", local log 2026-10-12, channel `testing`).
- **Installed SDK send APIs (VERIFIED, `vendor/kreait/firebase-php/src/Firebase/Contract/Messaging.php`):** `send()` (single message over the supported v1 `:send` endpoint, interface line 33), `sendMulticast()`/`sendAll()` (both go through the batch multipart request — `SendMessages.php:48` → `https://fcm.googleapis.com/batch`). The installed SDK therefore offers a **supported per-token alternative (`send()`)** — remediation does not strictly require an SDK upgrade, but an upgrade is the durable fix (6.7.0 is far behind the maintained line).
- **Batch endpoint status:** code-level VERIFIED (installed 6.7.0 path); runtime level UNKNOWN — zero FCM HTTP evidence in the local log; production runtime UNKNOWN. Per task rules, it is **not** asserted that production delivery is broken.
- **Path B:** event → `SendOrderPushNotification` (high, tries=2) → preference gate → `OrderNotification::create(pending)` → `PushNotificationService::sendToUser` → per-device `Kreait … send()` with **per-call Factory construction** (no caching) → simulation when `services.fcm.enabled=false` or credentials missing (`:66-77` fake `success`+`test_` id) → `markAsSent/Skipped/Failed`. Provider exceptions are converted to `success:false` results (never thrown); the listener rethrows only while `attempts() < tries` and swallows on the last attempt (`:75-77`); **each attempt creates a NEW `order_notifications` row** (`:53` outside try) → retry duplicates tracking rows (RISK).
- **Duplicate-delivery analysis (proven, not assumed):**
  1. *Cross-path:* transition to `delivered` fires `OrderStatusChanged` (→ Path B push) **and** `OrderDelivered` (→ Path A push via `SendUserOrderDeliveredNotification`) for the same order, same recipient, same physical device when the device is registered in both tables — same purpose ("order delivered"), different content/format. Same for `cancelled` (`:1388/:1391`). VERIFIED event wiring (`EventServiceProvider.php:154-157, 164-168`).
  2. *Within Path A:* a retried `SendQueuedNotifications` re-runs `database` + re-`dispatch`es the FCM job (no channel-level idempotency; no `ShouldBeUnique` on the job). INFERRED→RISK (framework mechanics verified; frequency unmeasured).
  3. *Within Path B:* listener retry re-sends to all devices and creates duplicate tracking rows. VERIFIED code path.
- **Idempotency strategy (proposed, not implemented):** business-event identity = (event class, order/notification id, recipient id); delivery scope = (token row id or token string). A per-path dedupe table or `order_notifications` reuse keyed on (listener attempt, event identity) would bound duplicates — requires approval as it adds a table/column (§21-D6).

---

## 11. Security and Privacy Review

| # | Area | Finding | Class |
|---|---|---|---|
| 1 | Cross-user content exposure | Path B coexistence: after account switch, previous account's pushes continue on the device | **Verified vulnerability-class behavior** (code+schema); exploitation requires an account switch on a shared device |
| 2 | Logout | Tokens not unregistered → pushes continue post-logout | Verified behavior; privacy risk |
| 3 | Token reassignment (A) | Secure last-writer-wins; but reassignment is **silent** — no audit event | Verified; INFERRED auditability gap |
| 4 | Unregister authorization | Both destroy paths owner-scoped (`DeviceTokenController.php:44-46`; `NotificationPreferencesController.php:79-81`) | Verified safe |
| 5 | Device ID spoofing | `device_id` client-supplied, unvalidated uniqueness → cannot be trusted as identity | Verified (schema) — advisory only |
| 6 | Invalid-token cleanup | A: verified working path. B: dead logic (string vs `getCode()`) → stale tokens | Verified code / INFERRED runtime |
| 7 | Notification content privacy | Path A broadcast payload = localized title/message + resource ids only (prior tests: coupon-code non-leak PASSED — inspected, not re-run this session); FCM `data` = same payload minus title/message | Verified (payload construction) |
| 8 | Credentials/signed-request logging | Pusher failure logs embed full signed URL incl. `auth_key` (redacted in prior report) | Verified local-log evidence |
| 9 | Firebase client isolation | Strict per-client resolver, no fallback (`FirebaseProjectResolver.php:9-18`) — but missing-credential exception is swallowed silently (`FcmService.php:27` inside try) | Verified |
| 10 | Preferences gating | Applied ONLY in Path B; Path A ignores `UserNotificationPreference` entirely | Verified — inconsistency (user "push off" does not stop Path A) |
| 11 | Auth on endpoints | Both register/unregister pairs behind `auth:sanctum` (+ throttle) | Verified |

---

## 12. Production Evidence and Unknowns

Re-checked this session: production DB access, Render stderr logs, and live env state remain **unavailable**. Prior inventory (`docs/audits/PRODUCTION_NOTIFICATION_USAGE_INVENTORY.md`) stands; nothing in it was invalidated by the HEAD move.

Still UNKNOWN (with acquisition procedure):
1. Row counts/distributions for `device_tokens`, `user_device_tokens`, `order_notifications`, `user_notification_preferences` — run the SELECT-only pack in the inventory report §3.1 via a **verified read-only connection** (verify with `SHOW GRANTS FOR CURRENT_USER()`; refuse unless SELECT-only).
2. Multi-user token rows in Path B (P-UDT-4/P-UDT-5) — decides the real-world severity of the leak (§11-1).
3. Endpoint usage by shipped apps — mobile team confirmation (§8 questions).
4. Live Firebase/Pusher env state — Render dashboard, names/booleans only.
5. Actual Firebase project identifiers — dashboard/service-account metadata (non-secret IDs only).
6. Production FCM runtime behavior (batch endpoint) — one controlled test send in a lower environment or log export.
7. Identity of the dataset behind the 2026-10-11 `user_id=616` real-context Path B execution — unknown database reachable from this workspace historically.
8. Real use of platform `web` (P-UDT-2).

---

## 13. Option A Analysis — unify on `device_tokens` (superset-compatible form)

- **Schema changes:** add `uuid`(already there), `is_active`, `device_id`, `device_name`, `app_version`, `os_version`, `expires_at` to `device_tokens`; widen `platform` to include 'web' (DB-level only — endpoint validation untouched); keep `token` varchar(512) global UNIQUE.
- **Data migration:** merge Path B rows → token-keyed upsert. **Conflicts (same token, 2 users) are guaranteed to exist wherever Path B had account switches** — ownership resolution requires an approved policy (§21-D3); platform fan-out (scenario 8) collapses multiple B rows into one A row (information loss on device_name/app_version per platform — acceptable if approved).
- **Path B compatibility:** `PushNotificationService`/`SendOrderPushNotification` must be re-pointed (reader swap) or retired into Path A dispatch; preferences gate stays (listener-level, not channel-level).
- **Client/project identity:** preserved (client_a/b native).
- **Web platform:** representable after platform widen; Path A endpoint still rejects 'web' input (contract frozen).
- **Active/inactive semantics:** Path A is hard-delete; B's soft-deactivate semantics must be re-expressed (delete vs `is_active`) — unregister endpoint behavior for B (soft) must be preserved by the adapter to keep observable behavior (row disappears from `getActiveForUser` either way; but the B history/id semantics change — see API note).
- **API preservation:** A-pair byte-identical; B-register needs response adapter (map new columns into the exact 13-field JSON) + **approved ownership-behavior change**; B-unregister needs id-continuity strategy (numeric ids change) — RISK for clients that cached ids.
- **Mobile compatibility:** good for Path A (native); B clients need behavioral approval + id continuity.
- **Rollback:** new columns are additive (safe); data merge is the irreversible part — requires pre-migration full backup + reconciliation queries; rollback = restore merge source rows.
- **Verdict:** strongest security + integrity end-state; blocked on production evidence + two approvals (ownership behavior; shared-device policy).

## 14. Option B Analysis — unify on `user_device_tokens` (superset-compatible form)

- **Schema changes:** add `client`, `uuid` (unique) to `user_device_tokens`; widen `token` to 512 (preserve A's `max:512` request contract); **change uniqueness from composite to global token unique** (or keep composite and lose reassignment — unacceptable: it preserves the leak); platform ENUM already covers union.
- **Data migration:** merge Path A rows; fewer semantic conflicts (A already enforces one-owner-per-token); B's existing coexisting rows become conflicts under the new global uniqueness → same approved policy needed.
- **Path A compatibility:** `FcmChannel`/`SendFcmNotificationJob` reader swap; `groupBy('client')` preserved via new column; invalid-token delete re-targets new table.
- **Client/project identity:** must be ADDED to B's model — Path A's multi-Firebase-project design is currently B-native-impossible (migration task).
- **Metadata/expiry:** B-native strengths retained (`device_id` index, `expires_at`, app_version) — A's missing metadata is gained.
- **API preservation:** B-pair byte-identical; A-register needs `uuid` backfill + adapter; A ownership behavior unchanged (reassignment already its semantic); B ownership behavior changes (coexistence → reassignment) → approval required; id namespace for A-pair is token-based (no numeric ids exposed) → **A side has no cached-id risk — an advantage over Option A**.
- **Rollback:** same additive/backup principles; plus uniqueness-constraint reversal is a schema rollback.
- **Verdict:** preserves more per-device metadata and avoids numeric-id exposure risk; costs more migration work (client column + constraint change) and depends on B's table actually being in production use (UNKNOWN).

## 15. Option C Analysis — retain both tables

- **Long-term maintenance cost:** every new notification feature must consider two token stores, two config sources (`firebase.clients.*` vs `services.fcm.*`), two reliability models (swallow vs simulate), two tracking models. VERIFIED divergence today: 5 of 5 compared dimensions differ (§9/§10).
- **Independent ownership risks:** Path B leak (§11-1) persists; Path A secure.
- **Duplicate notification risks:** delivered/cancelled cross-path duplicates persist (§10); preferences inconsistency (Path A ungated) persists.
- **Divergent configuration/retry/tracking:** documented in §10; production config state unknown per path.
- **Can both paths be maintained without data synchronization?** Yes — they are already fully decoupled (that is the problem: no shared identity). No sync exists today; none needed for C to function.
- **Avoiding conflicting notifications under C:** requires the product decision on which path owns order-status pushes (§21-D4) — without it, C is not merely "temporary", it is semantically ambiguous.
- **Honesty note:** C is NOT safe-by-default; it is safe only as *frozen behavior* (the no-API-change constraint) combined with the explicitly approved non-behavioral fixes (config provisioning, logging, retry semantics — internal changes that don't alter contracts).

---

## 16. Weighted Decision Matrix

Weights per task. Scores 1–5; `U` = UNKNOWN (evidence missing — per rules no invented score). Weighted normalized score computed over scoreable criteria only; UNKNOWN criteria are excluded from both numerator and denominator and flagged.

| Criterion (weight) | A | B | C |
|---|---|---|---|
| Security & cross-user isolation (25%) | 5 — global unique + reassignment eliminates stale-owner delivery (§9.2-3) | 2 — per-user coexistence is the leak (schema+controller VERIFIED) | 2 — retains B's leak as-is |
| API backward compatibility (20%) | 3 — responses preservable via superset+adapter (feasibility VERIFIED §7.5); ownership-behavior change on B needs approval; B id re-key risk | 3 — mirrored; advantage: no numeric-id exposure for A clients | 5 — zero change |
| Production & mobile compatibility (15%) | **U** — production populations/usage unknown; migration safety not assumable | **U** — same | 5 — no migration required |
| Data integrity & ownership semantics (15%) | 5 — single invariant, self-healing invalid tokens | 3 — richer metadata but leak invariant + dead expiry/deactivation | 2 — two divergent invariants, no sync |
| Delivery reliability & idempotency (10%) | 4 — one send path; idempotency still to be built | 3 — must add client support + fix deactivation | 2 — two divergent paths; proven duplicate dispatch on delivered/cancelled |
| Migration & rollback safety (10%) | **U** — production data unknown | **U** | 5 — no migration |
| Long-term maintainability (5%) | 5 | 4 | 1 — permanent dual systems |
| **Scoreable total (max weight)** | **90/100 → 4.28 (score-weighted)** | **85/100 → 2.94** | **100/100 → 3.30** |

Computation detail — A: (25·5 + 20·3 + 15·4 + 5·5) / 90 = 4.28; B: (25·2 + 20·3 + 15·3 + 10·3 + 5·4) / 85 = 2.94; C: (25·2 + 20·5 + 15·5 + 15·2 + 10·2 + 10·5 + 5·1) / 100 = 3.30.

**Decision-rules application:**
- A and B both require an approved ownership-behavior change on the B register endpoint (and A additionally an id-continuity decision) → neither is recommendable in breaking form; adapters can preserve *response* contracts but not *ownership behavior* without approval.
- Production compatibility and migration safety are UNKNOWN for A and B → per rules, these criteria cannot be scored, and a final selection cannot be made responsibly.
- **Therefore: no final A/B selection today. C is the only option scoreable on all criteria and is the required temporary posture.**

---

## 17. Recommended Target Architecture (conceptual — pending approval + evidence)

**Target invariant (the security-correct model, from §9.2):** *one live row per FCM token; the row's owner is the account that most recently registered that token; re-registration by another account reassigns ownership; invalid tokens are removed by the sender, never left active.*

**Logical model (superset schema — conceptual, not a migration):**
```
device_registry (conceptual)
  id, uuid (public id for Path-A response)
  user_id → users (current owner)
  token UNIQUE           -- global FCM token identity
  client ('client_a'|'client_b')   -- Firebase project/app
  platform ('ios'|'android'|'web')
  is_active, last_used_at, expires_at (optional future)
  device_name, device_id (advisory), app_version, os_version
  created_at, updated_at
```
- Physical-device identity ≠ token ≠ client ≠ user ≠ platform — five distinct concepts; only the token is an immutable provider identity.
- `device_id` advisory; never used for ownership or dedupe of identity.

**Flows (conceptual):**
1. *Registration:* validate per-endpoint (frozen contracts) → resolve owner = authenticated user → upsert by `token` → set client/platform/metadata → activate.
2. *Re-registration / rotation:* same as 1 (token key) — old metadata refreshed; rotation produces a new token → new row; old row retired on first send failure.
3. *Logout:* (policy decision D5) — recommended: deactivate rows owned by the logged-out session's user for that token (requires client to call unregister, or a token-bound logout).
4. *Account switch:* re-registration by new user reassigns (previous owner loses the row) — the approved behavior change (D1).
5. *Unregistration:* A: owner-scoped delete by token; B: owner-scoped soft-deactivate by id (ids preserved or remapped with client notice).
6. *Invalid-token cleanup:* sender collects per-item UNREGISTERED/NotFound results → delete/deactivate; reactivation impossible for dead tokens.
7. *Dispatch:* single FCM service (per-client cached factories); Path B's order-status push becomes a caller of the same service (preferences gate stays at listener level).
8. *Retry/idempotency:* job-level `tries/backoff` made real by classifying provider errors (retryable vs permanent); dedupe keyed on (event identity, token).
9. *Delivery tracking:* one tracking table (reuse `order_notifications` shape) written per dispatch attempt — reused across attempts instead of new rows.
10. *Preferences:* single gate applied at dispatch entry for push (behavioral parity decision D4).
11. *Firebase config:* one canonical config namespace; simulation mode must NEVER record `sent` (record `skipped:simulated` — internal change, no contract impact).
12. *Observability:* structured logs (user ref, client, token-hash prefix, job, queue, attempt, provider status, error class); no token material; no signed URLs.

**Compatibility adapters (conceptual):**
- A-register: unchanged controller; underlying store swapped; response builder emits `{"uuid": …}` from the registry row (uuid retained).
- B-register: unchanged validation; internal upsert by token; response builder emits the exact 13-field object (all fields present under superset schema).
- B-unregister: by `id` — during a migration window, keep an id-mapping (`legacy_id → new_id`) so cached numeric ids keep resolving, or return the same 404 semantics if a legacy id was invalidated by the approved merge policy (requires approval).
- Guarantee: external JSON shapes unchanged; only ownership *behavior* changes, and only with explicit approval.

---

## 18. API Backward Compatibility Strategy

| Endpoint | Existing request | Existing response (§7 exact) | Under target (post-approval) |
|---|---|---|---|
| POST /general/device-tokens | frozen | frozen (uuid in data) | identical response; behavior identical (already reassignment) |
| DELETE /general/device-tokens | frozen | frozen (no data key) | identical |
| POST /user/devices/register | frozen | frozen (13-field data) | identical shape; **ownership behavior changes only with D1 approval** |
| DELETE /user/devices/{deviceId} | frozen | frozen (200 / 404 trait shape) | identical shape; id continuity via mapping (D3) |
| Validation enums | frozen (A: android/ios; B: ios/android/web) | — | DB-level union allowed; per-endpoint validation untouched |

No response field is silently renamed/removed in any option as designed; where a field could not exist (A on B-table / B on A-table without schema extension), the design REQUIRES the schema extension first — unification without the superset columns is contract-breaking and rejected.

---

## 19. Migration and Rollback Strategy (design only)

**Phase 0 — C posture (recommended now; no migration):**
- Inventory queries (§3.1 pack) under a verified read-only connection; mobile-team endpoint confirmation; dashboard env-name confirmation.
- Zero code/config changes (per authorization).

**Phase 1 (only after evidence + approvals) — whichever target wins (A-form or B-form):**
1. *Pre-migration inventory:* full counts, duplicate-token sets (P-DT-4/P-UDT-4/P-UDT-5), per-token multi-user lists (counts only), platform/client distributions, expired/stale populations.
2. *Validation:* reconcile counts pre/post; checksum aggregates.
3. *Conflict handling:* every token with >1 owner is a **conflict requiring the approved policy** (D3): default proposal = most-recent `last_used_at` wins; ties → manual review list; never silent.
4. *Ownership conflicts:* same as 3; report per-token resolution table (ids redacted).
5. *Platform/client mapping:* Path B rows get `client` from their app's registration history (requires mobile-team mapping or default to `client_a` with approval).
6. *Missing metadata:* null-fill; never invent.
7. *Expired/invalid tokens:* retain rows flagged (no destructive cleanup until safety proven).
8. *Backfill:* dual-write window (both tables written) while readers migrate one at a time; dual-read with fallback for one release; cutover by reader (Path B reader first — lower blast radius), then writers.
9. *Cutover:* feature-flag per path; canary order-status push first.
10. *Monitoring:* delivery-rate, invalid-token rate, failed_jobs, tracking-row status distribution, duplicate-dispatch detector (same event id + token within window).
11. *Rollback conditions:* delivery regression > threshold, conflict rate above estimate, contract test failure, customer reports of lost/duplicate pushes.
12. *Rollback procedure:* re-point readers/writers to legacy tables (kept intact during dual-write window); reconcile rows created during the window.
13. *Reconciliation after rollback:* union-diff both tables; report deltas; no deletions.
14. *API verification:* §7 contract tests re-run (exact JSON + status codes) pre/post.

---

## 20. Future Test Strategy (planned, not executed)

**API contract tests (exact-JSON):**
- A-register success: exact keys `{status:200, message, success:true, data:{uuid}}`; types; absent `data` for DELETE (assert key absence).
- A-register 422: `{"message":<INVALID_DATA msg>,"status":false,"success":false,"errors":{client:[…],token:[…]}}`; invalid client value; missing token; token >512; platform not in set.
- A-unregister: owner deletes own (200, no data), other user's token (200, 0 rows affected — behavior pin), missing token 422.
- B-register success: exact 13-field `data`; 422 shape; platform 'web' accepted; `max:500` boundary.
- B-unregister: own id → 200; other user's id → exact 404 trait-shape `{"status":404,…,success:false}`; nonexistent id → 404.
- 401 for all four (exact Handler shape); 429 shape via throttle.

**Ownership tests:** same-user re-register idempotent; different-user re-register → reassignment (A semantics post-D1) and old owner receives nothing; logout → still registered (documented behavior) / post-D5 behavior; shared-device flow; rotation; concurrent same-token registration (race: one row wins under unique); unregister authorization.

**Delivery tests (mocked providers only):** successful multicast → invalid-token list empty; provider timeout → job retry (post-fix) — today documents swallow; invalid token → deletion; Firebase auth failure → permanent-fail classification (post-fix); queue retry idempotency (dedupe); **simulation mode must record `skipped:simulated`, never `sent`**; duplicate event dispatch → single FCM send (post-dedupe).

**Migration tests (when authorized):** duplicate-token merge; ownership conflict resolution per policy; missing metadata null-fill; legacy clients (both endpoints) unaffected; rollback restore; reconciliation diff = 0.

---

## 21. Decisions Requiring My Approval

- **D1 — Path B ownership-behavior change:** allow `POST /user/devices/register` to REASSIGN a token (instead of creating a coexisting active row) when another account registers it. Security rationale §9.2/§11-1. Required for ANY unification.
- **D2 — Shared-device / multi-account policy:** is last-registered-wins acceptable; any user-facing signal desired.
- **D3 — Conflict policy + id continuity:** merge conflict resolution (proposed: most-recent `last_used_at` wins; manual list otherwise) and whether to guarantee `user_device_tokens.id` continuity for cached client ids (mapping table) or accept 404s for stale ids.
- **D4 — Order-status push ownership:** which path owns order-status pushes during/after C (eliminates delivered/cancelled double-push) and whether Path A should honor `UserNotificationPreference` push gating.
- **D5 — Logout token policy:** deactivate-on-logout vs keep (privacy vs re-login convenience).
- **D6 — Idempotency/dedupe mechanism:** new dedupe table/tracking reuse — adds schema (approval needed).
- **D7 — Production provisioning:** set real `FIREBASE_CLIENT_*` / `FCM_ENABLED` / Pusher vars in the live environment (ops change).
- **D8 — FCM remediation approach:** SDK upgrade vs per-token `send()` loop (both preserve behavior; upgrade touches dependencies — needs approval per rules).

---

## 22. Blockers and Required Evidence (to finalize A vs B)

1. Production table populations + multi-user token counts (§3.1 pack, verified read-only connection) → decides real conflict volume and which table holds live data.
2. Mobile team answers (§8) → decides which endpoints/clients are actually live.
3. Live env confirmation (dashboard) → decides whether Path A even functions today.
4. Production/lowest-env FCM runtime probe → decides batch-endpoint urgency.
5. D1–D8 approvals → unblocks target design implementation planning.

---

## 23. Final Recommendation

1. **Final architecture recommendation: NO FINAL SELECTION between A and B is supportable today.** The weighted matrix cannot complete two criteria for A/B (production compatibility, migration safety — UNKNOWN), and both require approval-gated behavior changes.
2. **Temporary operational posture (recommended, effective immediately, zero code change): Option C — retain both tables and both paths exactly as they are.** Rationale: it is the only option that (a) satisfies the absolute no-API-change constraint without approval, (b) requires no production data assumptions, and (c) is fully reversible. C's known risks are accepted *as frozen behavior*; the independently-verifiable internal fixes (config provisioning D7, simulation-honesty, retry classification, logging hygiene) are queued behind approvals and are NOT implemented here.
3. **Approved-in-principle target direction (contingent):** unified superset registry with global token ownership (Path A semantics), both endpoint contracts preserved via adapters, migration designed in §19 behind evidence gates.
4. This report is a decision proposal. **No implementation is authorized or performed.**

---

## 24. Safety and Non-Modification Confirmation

- No application code, tests, migrations, `.env`, configuration, routes, or deployment files were modified. Only this report file was created (`docs/audits/NOTIFICATION_ARCHITECTURE_DECISION.md`).
- No migrations/seeders executed; no dependencies changed; no workers/schedulers started.
- No notifications (real or simulated) were sent; no devices registered/unregistered.
- No database records were changed; the only DB interactions this phase were read-only repository inspections; prior-session local probes were SELECT/DDL-read-only aggregates on the clearly-labeled local dev DB.
- No production access was attempted; production evidence remains UNKNOWN and is labeled as such.
- No API contract was changed or assumed changed; §7 documents existing contracts exactly as implemented.
- No test was executed or modified in this phase; test evidence in this report is inspection-based unless explicitly marked TESTED with its command/session.
- Repository state left untouched: branch `main`, HEAD `fe31ca5e4757c580b079f601b69c43dac6927c8f`, working tree unchanged except this report.

*Report end. Decision proposal only — awaiting explicit review and approval.*
