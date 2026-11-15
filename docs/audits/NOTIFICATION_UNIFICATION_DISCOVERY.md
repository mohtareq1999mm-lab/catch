# Notification System Unification — PHASE 0 Baseline & PHASE 1 Discovery Report

**Status:** Read-only investigation. No application code, migrations, configuration, database records, or tests were modified. The only repository additions are this report and the pre-existing untracked `docs/audits/NOTIFICATION_SYSTEM_FULL_AUDIT.md` (created by the previous audit task, preserved untouched).

**Rule compliance:** No test executed against external services. No real notification sent. No DB access possible (MySQL unreachable in this environment — verified). All claims carry file:line evidence at HEAD `2f258c05f797cd8024d174da870755f08b08f6c0`.

---

# PHASE 0 — Repository Baseline

| Item | Value | Evidence | Status |
|---|---|---|---|
| Repository root | `D:\work\meem` | shell cwd | Verified |
| Branch / HEAD | `main` / `2f258c05f797cd8024d174da870755f08b08f6c0` | `git rev-parse HEAD` | Verified |
| Working tree | **Clean except one untracked file**: `?? docs/audits/NOTIFICATION_SYSTEM_FULL_AUDIT.md` (78,122 bytes — previous audit deliverable, deliberately preserved) | `git status --porcelain` | Verified |
| Uncommitted user work | **None found.** Nothing was overwritten, discarded, or reformatted | same | Verified |
| PHP | 8.2.30 CLI | `php -v` | Verified |
| Laravel | 10.30.1 | composer.lock | Verified |
| Firebase SDK | **kreait/firebase-php 6.7.0** (composer.lock:3889-3890); `kreait/firebase-tokens ^3.0` (lock:3914) | composer.lock | Verified |
| RabbitMQ transport | php-amqplib 3.7 installed; `RabbitMqCouponEventTransport` active (coupon notification path) | composer.json, app/Services/Coupon/Distribution/Messaging/ | Verified |
| Test framework | PHPUnit **10.0.13**; suites `Unit` + `Feature` (phpunit.xml); no Pint/PHPStan/Larastan configured in composer.json | composer.json:50, phpunit.xml | Verified |
| Test command | `php artisan test` / `vendor/bin/phpunit` — **NOT RUN**: MySQL `catch` unreachable (`SQLSTATE[HY000] [2002]`, verified via `php artisan migrate:status`). `AsyncSafetyTest` uses `RefreshDatabase` (destructive to local dev DB) — must never run against a shared DB | phpunit.xml:21-27 | Verified |
| Previous audit | `docs/audits/NOTIFICATION_SYSTEM_FULL_AUDIT.md` exists — **read completely**; all findings re-validated against current HEAD below (§7) | file | Verified |
| DB connectivity | MySQL `catch` @ configured host: **connection refused** — all runtime schema/data inspection impossible | `php artisan migrate:status` | Verified |

**Previous audit key conclusions (restated for reconciliation, all revalidated this session):**
24 confirmed findings (N-001…N-024), 7 risks, 8 unknowns, 8 pending decisions (D-1…D-8). Most severe: Path A multicast uses the retired FCM batch endpoint (vendor source) and swallows every provider failure; Path B allows one token under multiple accounts and never deactivates invalid tokens; preferences ignored on Path A; duplicate pushes on cancel/deliver; logout never releases tokens.

---

# PHASE 1 — DISCOVER: Complete Read-Only Investigation

## A. Notification Entry Points (complete inventory)

Every trigger site verified by repository-wide search. Dispatch sites: `event(new …)` (grep), `->notify(` / `Notification::send(` (grep), observers, scheduled commands, controllers, webhooks.

| # | Trigger | Source class + line | Notification | Recipients | Channels | Queue | Notes |
|---|---|---|---|---|---|---|---|
| A1 | Checkout completes | `OrderCreationService` app/Services/Checkout/OrderCreationService.php:592 (`OrderCreated::dispatch`, event implements `ShouldDispatchAfterCommit` — tests/Feature/Fulfillment/AsyncSafetyTest.php:37) | `NewOrderNotification` (admins), `UserOrderCreatedNotification` (customer) via listeners app/Listeners/SendNewOrderNotification.php:26, SendUserOrderCreatedNotification.php:25 | active `type=admin` users; `order->user` (type USER only) | database + fcm + broadcast (all 27 classes verified via via() extraction) | listener queued high (`QueueName::high`), notification onQueue high | Admin blast = 1 job per admin |
| A2 | Payment succeeded (webhook/COD/completion) | app/Http/Controllers/Api/General/PaymentWebhookController.php:1700; app/Services/General/OrderService.php:1405; OrderController.php:631 | `UserPaymentSucceededNotification` (SendUserPaymentSucceededNotification) | order user | DB+fcm+BR | queued | Conditional double-push with A4 'completed' (D-007 family) |
| A3 | Payment failed | PaymentWebhookController.php:1726/1853/1936/2006; OrderController.php:432/583/606/785/890; CancelUnpaidOrders.php:143 | `UserPaymentFailedNotification` | order user | DB+fcm+BR | queued | Multiple dispatch sites → ordering risk across transitions |
| A4 | Order status changed (any transition) | OrderService.php:1388 | **Path B** push: `SendOrderPushNotification` (EventServiceProvider.php:168); **Path D** SMS/Email: SendOrderStatusSMS.php:26, SendOrderStatusEmail.php | order user | push (B) / sms / email | all queued high | **Only path enforcing user preferences + quiet hours** |
| A5 | Order cancelled | OrderService.php:1390-1392 (fires **after** A4 in same transaction) | `UserOrderCancelledNotification` (SendUserOrderCancelledNotification) — **plus** A4 push for status=cancelled | order user | DB+fcm+BR (A) + push (B) | queued | **Confirmed duplicate push (D-007)** |
| A6 | Order delivered | OrderService.php:1394-1396 | `UserOrderDeliveredNotification` **plus** A4 push for status=delivered | order user | same as A5 | queued | **Confirmed duplicate push (D-007)** |
| A7 | Refund approved | RefundService.php:227 | `UserOrderRefundedNotification` (marvel EventServiceProvider.php:116) | order user | DB+fcm+BR | queued | — |
| A8 | Digital products delivered / delivery failed | Fulfillment listener → `DigitalProductsDelivered` (EventServiceProvider.php:209-211) | `UserDigitalProductsAvailableNotification`; admin `AdminDigitalDeliveryFailedNotification` | user / admins | DB+fcm+BR | queued | — |
| A9 | Admin logged in | `AdminLoggedIn` (auth flow) → SendAdminLoginNotification.php:17 | `AdminLoggedInNotification` (IP/UA payload) | active admins | DB+fcm+BR | **listener synchronous (no ShouldQueue)** | D-018 |
| A10 | Contact message received | `ContactMessageReceived` → SendContactMessageNotification.php:17 | `NewContactMessageNotification` | active admins | DB+fcm+BR | **listener synchronous** | D-018 |
| A11 | Coupon created/activated (public) | `CouponCreated` via CouponObserver.php:24; `CouponActivated` :35/:75 | `UserCouponAvailableNotification` (SendUserCouponAvailableNotification) | all type-USER users | DB+fcm+BR | queued | Blast (D-011); skips coupons with assignments (test-pinned) |
| A12 | Coupon assigned / used | `CouponAssigned` / `AssignedCouponConsumed` → SendUserCouponAssignedNotification, SendUserCouponUsedNotification | `UserCouponAssignedNotification`, `UserCouponUsedNotification` | assigned user | DB+fcm+BR | queued | Exactly-once test-pinned |
| A13 | Coupon eligibility distribution | Coupon backbone: outbox → RabbitMQ → `NotificationRequestHandler.php:97` (`$user->notify(UserCouponEligibleNotification)`) | `UserCouponEligibleNotification` | distribution recipients | DB+fcm+BR | AMQP consumer + Laravel queue | 2 durable dedupe guards; small crash window (D-013) |
| A14 | Promotion activated / flash sale activated | PromotionObserver.php:29/:63; FlashSaleObserver.php:28/:64 | `UserPromotionAvailableNotification` + wishlist price-drop; `UserFlashSaleAvailableNotification` (SendUserFlashSaleAvailableNotification.php:23-33 **iterates every user row**) | all users / wishlist users | DB+fcm+BR | queued | Blast (D-011); activation dedupe test-pinned (false→true only) |
| A15 | Price drop / back-in-stock / discount changed | ProductObserver.php:154/:165/:176 → wishlist fan-out `NotifyWishlistUsersOfProduct.php:33` | `UserProductPriceDropNotification` etc. | wishlist users (type USER, admins excluded) | DB+fcm+BR | queued | Notification cloned per user |
| A16 | Promotions/flash sales ending soon | Kernel schedule `promotions:notify-ending-soon`, `flash-sales:notify-ending-soon` (daily) → NotifyPromotionsEndingSoon.php:36-45 | `UserPromotionEndingSoonNotification` etc. | wishlist users | DB+fcm+BR | queued | Flag saved **after** fan-out (D-014) |
| A17 | Abandoned cart | Kernel `cart:notify-abandoned` (hourly, onOneServer) → NotifyAbandonedCarts.php:41-50 | `UserAbandonedCartNotification` | cart owners | DB+fcm+BR | queued | **Atomic claim, rerun-safe (test-pinned)** |
| A18 | Queue job failed (final attempt) | `JobFailed` → HandleFailedQueueJob.php:20-49 | `AdminQueueJobFailedNotification` (database-only, sanitized payload) | SUPER_ADMINs | database | sync (event) | Failure of the alert cannot mask the original (try/catch) |
| A19 | Vendor order mail (Marvel) | Marvel OrderRepository.php:864 `OrderReceived` → SendOrderReceivedNotification.php:30 | `NewOrderReceived` — **mail only** (packages/marvel/src/Notifications/NewOrderReceived.php:40) | shop owner | mail | queued | No push overlap |
| A20 | Marvel mail-only set | packages/marvel/src/Notifications/* (21 classes, **all `via() → ['mail']`** verified) | — | — | mail | — | Zero FCM involvement in Marvel (grep: no `fcm|firebase|Firebase|FCM` matches in packages/marvel/src) |
| A21 | Email verification | `Registered` → VerifyEmailNotification (extends Laravel mail base; **no fcm channel** — pinned by FcmIntegrationTest:166-170) | — | new user | mail | queued | Documented exclusion |
| A22 | Ban/user deletion | UserController.php:783/927/971 (`$user->tokens()->delete()`) — **Sanctum tokens only** | — | — | — | — | Device tokens NOT cleaned (D-004) |

**Observers:** 18 in app/Observers; only PromotionObserver, FlashSaleObserver, CouponObserver, ProductObserver dispatch notification-triggering events (lines above). No observer touches device tokens. No device-token factories exist (glob: none).

## B. Device Registration and Ownership

### Data model (verified from migrations)

| Table | Migration | Token column | Uniqueness | FK | Indexes | Extra |
|---|---|---|---|---|---|---|
| `device_tokens` (Path A) | 2026_08_23_073810_create_device_tokens_table.php | `token` varchar(**512**) | **UNIQUE (global)** :15 | user_id → users **cascadeOnDelete** :14 | unique uuid :13; `(user_id, client)` :21 | `client` varchar(32) controller-validated only; `last_used_at` nullable |
| `user_device_tokens` (Path B) | 2026_09_12_000003_create_user_device_tokens_table.php | `token` varchar(**500**) | unique **(user_id, token, platform)** :40 — **NOT global** | user_id FK cascade :38 | `(user_id, is_active)` :41; `device_id` :42 | `platform` enum('ios','android','web') :23; `is_active` default true :28; `expires_at` :30 (**never set by any code**); metadata :33-34 |

### Registration APIs (both live, both authenticated)

| Aspect | Path A — `POST/DELETE /api/v1/general/device-tokens` | Path B — `POST /api/v1/user/devices/register`, `DELETE /api/v1/user/devices/{id}` |
|---|---|---|
| Middleware | `api, auth:sanctum, throttle:authenticated` (routes/api.php:215, 273-274) | same (routes/api.php:323-328) |
| Controller | DeviceTokenController::store/destroy | NotificationPreferencesController::registerDevice/unregisterDevice |
| Validation | token ≤512; client ∈ {client_a, client_b}; platform ∈ {android,ios} optional (:20-24) | token ≤500; platform ∈ ios,android,web; metadata optional (:49-56) |
| Write semantics | `updateOrCreate(['token'])` → **global reassignment** (:27-35) | `updateOrCreate([user_id, token, platform])` → **adds a row; old owner's row stays active** (:58-72) |
| Unregister | owner-scoped delete; **200 even for foreign/unknown token** (:44-48; contract documented in docs/api/device-tokens.md) | owner-scoped deactivate; **404 if not owned** (:77-90) |
| Documented contract | docs/api/device-tokens.md — full envelope, fields, DB impact, reassignment semantics | none found |

### Answers to the mandated questions (evidence-based)

1. **Which table is used by each path?** Path A (`SendFcmNotificationJob.php:42-47`) reads `device_tokens` grouped by `client`. Path B (`PushNotificationService.php:22` `UserDeviceToken::getActiveForUser`) reads `user_device_tokens`. Two disjoint registries; no reconciliation code exists.
2. **Can the same token belong to multiple users?** Path A: **No** (global unique + reassignment; test-pinned FcmIntegrationTest.php:59-69). Path B: **Yes** (unique key includes user_id; registering the same token under a second account leaves the first row active — VERIFIED schema+code; runtime occurrence requires mobile confirmation).
3. **Logout?** Both paths untouched. `UserController::logout` deletes only the Sanctum access token (packages/marvel/src/Http/Controllers/UserController.php:660-673). No logout listener for devices exists anywhere (grep verified).
4. **Login on another account, same device?** Path A: row reassigned to new user (old owner silently loses push — correct). Path B: **second active row created** (D-003); both accounts continue receiving push.
5. **Firebase invalidates a token?** Path A: only at send time via per-item `NotFound`/message-string match (FcmService.php:41-43) → row deleted (SendFcmNotificationJob.php:50). Path B: `deactivate()` comparison can never match the installed SDK's error codes (PushNotificationService.php:42 vs :90-92, Kreait exceptions carry int codes) — **dead logic** (D-006).
6. **Stale-token accumulation?** Path A: no sweeper; cleanup depends entirely on provider NotFound (currently dead per D-001/D-002). Path B: `is_active`/`expires_at` are never flipped by any code path (grep verified). Both accumulate.
7. **Constraints?** See table above. `client` has no DB-level constraint. `notifications` (Laravel standard, 2026_07_05_080106) has morphs + read_at, no index beyond defaults.
8. **Production-data migration risks?** **Unknown** — DB unreachable. Risks flagged: (a) token strings possibly present in both tables (overlap unknown); (b) `user_device_tokens` may contain same-token multi-user duplicates requiring conflict resolution; (c) Path B metadata (`device_name`, `device_id`, `app_version`, `os_version`, `expires_at`) has no equivalent in `device_tokens` — any consolidation must decide on lossless metadata carry-over or an explicit retention decision; (d) FK both tables → users cascadeOnDelete (user deletion already cleans both).

## C. Sending Architecture

### Sequence — Path A (canonical Laravel `fcm` channel)

```
Listener (queued, high) → $user->notify(X)
 └ SendQueuedNotifications job → per channel:
    database → notifications row
    fcm → FcmChannel::send (FcmChannel.php:11-39)
       payload = toDatabase() (single authority; toFcm forbidden — test-pinned)
       title/body = payload localized via App::getLocale() AT DISPATCH TIME (:46-55)
       dispatch SendFcmNotificationJob(title, body, data-minus-payload, notifiable->getKey())
          queue = config('frontend.queue') ?? high (Job:27)
          guard: null user ⇒ warn + return (Job:35-40)  ← broadcast-bug regression pin
          DeviceToken::where(user_id)->chunk(500) groupBy client (:42-45)
          FcmService::sendToClient (:18-56)
             messagingFor(client): cached Factory per client (:58-74)
               credentials = storage/app/firebase/<client>.json (FirebaseProjectResolver.php:9-18)
               missing ⇒ RuntimeException ⇒ swallowed (:50-53)      ← D-001/D-002
             message = Notification + highest priority + data (:29-32)
             sendMulticast (Kreait 6.7 → Messaging.php:73-93 → SendMessages.php:48
                            → POST https://fcm.googleapis.com/batch)  ← RETIRED ENDPOINT (D-001)
             per-item: NotFound/invalid-token ⇒ invalid[] (:41-43)
             per-item: other errors ⇒ Log::warning only (:44-48)     ← D-009
             whole-request Throwable ⇒ Log::error, return [] (:50-53) ← D-002
          invalid[] ⇒ DeviceToken::whereIn(token)->delete() (Job:50)
    broadcast → BroadcastMessage (Pusher; channels authorized, test-pinned)
```

### Sequence — Path B (direct service, order-status push)

```
OrderStatusChanged (queued listener SendOrderPushNotification.php:25-79)
 └ prefs: UserNotificationPreference::forUser (:36) → isEventEnabled(type, 'push') (:38)
   skip ⇒ logSkipped → OrderNotification(status=skipped) (:131-143)  ← NULL user_id insert fails silently for guests (D-016)
 └ title/body: hardcoded en/ar maps (:86-129) ← notification_language respected here (unlike Path A)
 └ OrderNotification::create(status=pending) (:53-62)
 └ PushNotificationService::sendToUser (:20-62)
    UserDeviceToken::getActiveForUser (is_active + expires_at) (:22; model :49-58)
    per device:
       disabled/unconfigured ⇒ SIMULATED success, message_id 'test_…' (:66-77) ← D-008
       else fresh Factory + createMessaging PER SEND (:80-81)
       send() single-token (v1 endpoint) (:83-87)
       result['error_code'] never matches deactivation list (:42 vs :90-92) ← D-006
 └ markAsSent(result) / markAsSkipped / markAsFailed (+rethrow until tries=2) (:64-78)
```

### Path C (coupon) — durable dedupe pipeline (NotificationRequestHandler.php:35-145): outbox → RabbitMQ (publisher confirms, DLQ/retry topology, RabbitMqCouponEventTransport) → Guard 1 (user-state row) → Guard 2 (notifications-table scan ≤100 rows) → notify → transactional state write. Crash window between notify() and state write (D-013).

### Provider call surface (complete, verified)

| # | Call | File:line | Behavior |
|---|---|---|---|
| 1 | `FcmService` cached factory + `sendMulticast` | FcmService.php:71-73, :34 | batch endpoint (D-001); report discarded except invalid-token extraction |
| 2 | `PushNotificationService` fresh factory + `send()` per token | PushNotificationService.php:80-87 | v1 endpoint; result reduced to `{success, error, error_code=(string)getCode()}` |

No other `Kreait` usage exists (repository-wide grep, 2 matches). No Marvel Firebase usage (grep: zero matches). No web-push/VAPID/service-worker anywhere (glob: none).

### Conflicting/duplicated responsibilities

- **Two token registries, two registration APIs, two unregister APIs, two Firebase configurations** (`config/firebase.php` per-client vs `services.fcm` single), two credential locations (storage/app/firebase vs `FCM_CREDENTIALS_PATH`).
- **Two push payload dialects**: Path A = database-payload reuse (localized maps, `action_url`); Path B = hardcoded en/ar maps + `click_action`.
- **Two error policies**: A = swallow; B = per-send return codes (broken classification).
- **Two delivery ledgers**: Laravel `notifications` (all 27 types) vs `order_notifications` (order-status only, statuses pending/sent/delivered/failed/skipped — `delivered` unreachable, D-019).
- **Preferences enforced on 3 listeners only** (SMS/Email/B-push); 27 Laravel-notification types bypass them (D-005).
- Queue shape: both paths on high; notification objects onQueue high (27/27 verified); blast fan-out = 3 queued jobs per user (DB+fcm+BR) with per-user FCM job.

## D. Preferences and Permissions

- Model: `UserNotificationPreference` (channel flags, `event_preferences` JSON, quiet hours, `notification_language`) with `firstOrCreate` defaults all-true (UserNotificationPreference.php:100-114).
- **Enforcement verified in exactly 3 places**: SendOrderStatusSMS.php:48-58, SendOrderStatusEmail (prefs read :35), SendOrderPushNotification.php:36-41. `UserNotificationPreference` grep: no other listeners/Channel/Job reference it.
- **Path A (27 types), coupon path, blasts: no preference check** (D-005). No "mandatory vs optional" classification exists anywhere in code or config.
- **Authorization**: both registration APIs auth+throttle-pinned (tests); unregister owner-scoped both paths (B returns 404, A returns 200-without-effect — asymmetry); notification read/mark/delete fully `$user->notifications()`-scoped (packages/marvel/src/Http/Controllers/NotificationController.php:25/:70/:79/:105 — test-pinned 41 methods); broadcast channels authorized (routes/channels.php:18-33, test-pinned); admin endpoints permission-gated (Rest/Routes.php:455-463).
- **Payload privacy**: order number/status/totals in push data; no coupon codes (test-pinned); admin login push carries IP/UA to admins only; failed-job alert sanitized (test-pinned AsyncSafetyTest:87-96). Log hygiene: PushNotificationService logs 20-char token prefix (:67-70) — SEC-3.

## E. Delivery Reliability

State distinctions mandated by the mission (dispatch/queued/provider-attempted/provider-accepted/device-delivered/displayed) — current reality:

| State | Path A | Path B |
|---|---|---|
| Job dispatched | observable (queue) | observable (listener) |
| Provider request attempted | **not recorded** | not recorded |
| Provider accepted | **discarded** — `MulticastSendReport` consumed only for invalid-token extraction (FcmService.php:36-48) | reduced to `{success}` per device; `markAsSent(null, result)` ignores message-id evidence beyond success flag (:68) |
| Device delivered / displayed | **not available anywhere** (FCM provides no display receipts; no APNs feedback integration) | `order_notifications.delivered_at`/`markAsDelivered()` exist with **zero callers** (D-019) |

Error taxonomy available from installed SDK (vendor Exception/Messaging): `NotFound`, `InvalidArgument`, `InvalidMessage`, `AuthenticationError`, `QuotaExceeded`, `ServerError`, `ServerUnavailable`, `ApiConnectionFailed`, `MessagingError` — none currently distinguished by application code (single `catch \Throwable`). Classification mapping is fully implementable from these (design input, PHASE 2).

Retries: SendFcmNotificationJob `tries=3, backoff=[30,120]` (:18-19) engage **only if handle() throws** — FCM failures never throw (D-002) ⇒ dead. Supervisor policy: high tries=5 timeout=1300, medium tries=3 timeout=1300 (WorkerConfigPolicyTest.php:11-13,44-57), `retry_after=1800` > timeout (config/queue.php, documented invariant) ⇒ timeout-duplicate window correctly closed. B listener `tries=2` with rethrow (SendOrderPushNotification.php:75-77). SMS listener tries=3 backoff [60,300,900].

Partial multicast: per-item isolation exists in A (:36-48) but non-NotFound per-item errors are **log-only** — tokens silently miss the notification (D-009). One poisoned token never blocks siblings (verified loop structure). Path B per-device try/catch isolation (:33-59).

Failed jobs: `failed_jobs` table migration exists (2019_08_19); alerting + daily prune (Kernel `queue:prune-failed --hours=720` 03:15; `HandleFailedQueueJob` JobFailed → super-admin database notification, sanitized). **But the only failure class that actually matters for push (transport error) never reaches it** (D-002).

## F. Duplicate Prevention and Ordering

| Mechanism | Status | Evidence |
|---|---|---|
| After-commit event dispatch | **Verified + test-pinned** | AsyncSafetyTest.php:34-38 (`OrderCreated`, `PaymentSucceeded` implement `ShouldDispatchAfterCommit`) |
| Abandoned-cart exactly-once | **Verified + test-pinned** | atomic claim NotifyAbandonedCarts.php:41-48; UserNotificationPhase3Test rerun test |
| Coupon distribution dedupe | **Verified + test-pinned** (2 durable guards) + crash window | NotificationRequestHandler.php:73-94, :96-136 (D-013) |
| Promotion/flash-sale activation | **Verified + test-pinned** (state-transition false→true) | PromotionObserver.php:29/:63; UserPromotionFlashSaleNotificationTest |
| Ending-soon reminders | Flag **after** fan-out — crash window | NotifyPromotionsEndingSoon.php:44-45 (D-014) |
| Terminal transitions (cancel/deliver) | **NO dedupe across the two push systems** — both events fire in one transaction (OrderService.php:1388-1396) and both push paths send (D-007) | EventServiceProvider.php:149-157, :164-170 |
| Push idempotency key | **None anywhere** (no uuid in FCM data; no dedupe table) | grep verified (D-010) |
| Ordering | No sequence guarantees: payment-failed has 7+ dispatch sites; status transitions race with webhooks; out-of-order push possible under parallel workers | INFERRED from dispatch topology |
| Coupon-assigned exactly-once | test-pinned | UserNotificationTest methods |

## G. Notification History and Observability

| Record | Meaning today | False-success risk |
|---|---|---|
| `notifications` rows | DB-channel content only; presence ≠ push delivery | none (not a delivery claim) |
| `order_notifications.status` | 'sent' = **Path B reported success** — which is **simulated** when `services.fcm.enabled=false` (default!) with `message_id='test_…'` (PushNotificationService.php:66-77 → SendOrderPushNotification.php:67-68) | **YES — D-008** |
| `order_notifications.delivered` | unreachable (no caller) | — |
| Log lines `FCM send failed` / `FCM transient failure` / `FCM invalid tokens removed` | only signal for Path A failures; no correlation id, no project/client beyond `client` string, no counts beyond invalid | — |
| Metrics/queue depth/coverage | **none exist** | — |

Mission's observability questions: requested (no), queued (implicit), provider succeeded/failed (no), why (log-only, unqueryable), project/client used (no), tokens invalidated (count-only log), retries (no), duplicates (only Path C counts), delivered (no). Verdict: **not observable** for Path A; partially observable for Path B with false-success contamination (D-008).

## H. Tests and Coverage (inventory; **NOT RUN** — DB unreachable; structural verification only)

| Area | Tests (file → scope) | Proves (design-level) | Does NOT prove |
|---|---|---|---|
| Path A scoping/ownership | Phase0/FcmScopingTest (3), FcmIntegrationTest (7) | user-scoped sends; null-user refusal; token reassignment; owner-only delete; channel registration; payload authority | actual provider I/O; batch endpoint health |
| Fail-safe + alert hygiene | Fulfillment/AsyncSafetyTest (5) | job skip w/o user; invalid-token prune; alert payload sanitization; after-commit events | FCM exception paths (mocked away) |
| Preferences | NotificationPreferencesTest (6), SMSNotificationTest (5) | model/quiet-hours; SMS/Email/B-push gating | any of the 27 Path A types (D-005 untested) |
| API authorization + ownership | NotificationTest (41), NotificationApiE2ETest (10), RealLoginNotificationE2ETest (broadcast auth incl. CORS) | full CRUD guard matrix | device endpoints' cross-account cases in B (untested) |
| Event fan-out per type | Notifications/*E2ETest (Order/Payment/Refund/Coupon/Review/Product/Promotion/FlashSale/Admin + Real*) | DB+BR per event, locale payloads, admin exclusion | FCM side (only job-push asserted in FcmIntegrationTest) |
| Queue routing | NotificationQueueTest (4), Coupon/CouponNotificationQueueTest (5) | env-backed queue names | retry behavior, worker timeout interplay |
| Path C pipeline | CouponDistribution/NotificationPipelineTest (5) | dedupe, no code leak, FCM-fail keeps DB row, cross-user isolation | crash-before-state-write window (D-013 untested) |
| Pusher wire/real | NotificationBroadcastTest, PusherConnectionCheckTest, *RealPusher* (external) | wire contract | — |

**Missing test scenarios (consolidated)**: FcmService provider behavior (any exception class), batch-endpoint live check, Path B token overlap/deactivation, dual-event duplicate push, preference enforcement for Path A types, simulated-success ledger contamination, logout token release, idempotency under retry/concurrency, per-class exception taxonomy mapping, stale-token sweeper (does not exist).

**Test infra note**: Feature tests run against MySQL `catch` with `DatabaseTransactions` (FcmIntegrationTest) or `RefreshDatabase` (AsyncSafetyTest — **migrate:fresh, destructive**). `CreatesTestTables` builds manual schemas (MySQL ENUM not SQLite-safe — FcmIntegrationTest.php:25-27). A safe local run requires a scratch database (`DB_DATABASE=catch_verify` pattern is already anticipated in phpunit.xml comments).

---

## Output 1 — Existing architecture diagram

See §C sequences (Path A/B/C) + Path D (SMS/Email/marvel-mail). The system today is **two push stacks + one dedupe pipeline + three preference-gated side channels**, with disjoint token registries, disjoint payload dialects, disjoint ledgers, and disjoint error policies.

## Output 2 — Flow inventory

§A table (22 trigger families, all verified dispatch sites).

## Output 3 — Device-token data model

§B table + answers 1-8.

## Output 4 — Notification path comparison

| Dimension | Path A | Path B | Path C | Path D (SMS/Email/marvel mail) |
|---|---|---|---|---|
| Trigger | 27 Laravel notifications | OrderStatusChanged only | coupon.notification.requested | OrderStatusChanged + Marvel events |
| Registry | device_tokens (global-unique) | user_device_tokens (per-user) | — | — |
| Firebase config | per-client (firebase.php) | single (services.fcm) | inherits A | n/a |
| Payload authority | toDatabase() reuse | hardcoded maps | toDatabase() via A | mail/SMS templates |
| Preferences | **none** | enforced | none | enforced (SMS/Email) |
| Provider | multicast batch endpoint (retired) | per-token v1 | inherits A | gateways |
| Error policy | swallow | per-device return (broken classification) | inherits A | per-listener |
| Ledger | notifications table | order_notifications (contaminated by simulation) | notifications + coupon state | SMS/email side logs |
| Dedupe | none | none | durable guards | none |
| Tests | strong scoping | API-only | pipeline | SMS gating |

## Output 5 — Dependency map (verified complete)

- **Kreait (2 call sites)**: FcmService.php:71-73,34 ← SendFcmNotificationJob ← FcmChannel ← AppServiceProvider.php:35-37 ← 27 notification classes ← 22 trigger families. PushNotificationService.php:80-87 ← SendOrderPushNotification only.
- **device_tokens**: DeviceTokenController (write), SendFcmNotificationJob (read/prune), DeviceToken model, migration, docs/api/device-tokens.md, 4 test files.
- **user_device_tokens**: NotificationPreferencesController (write), PushNotificationService (read; `registerDevice()` **dead** — sole definition, zero callers), UserDeviceToken model, migration, 2 test files.
- **PushNotificationService::registerDevice / FirebaseProjectResolver::projectId / OrderNotification::markAsDelivered — all dead code (zero callers, repository-wide grep re-verified this session).**
- **UserNotificationPreference**: 3 listeners + controller + 2 test files.
- **user_preferences table**: currency-only (`currency_code`) — **not a notification preference system** (clarification; migration 2026_08_11_000002).
- **RabbitMQ**: coupon distribution only (notification-bearing events ride on it).
- No factories/observers touch device tokens.

## Output 6 — Previous audit findings reconciliation

All N-001…N-024 re-validated at HEAD with **identical line numbers** — no drift, no finding invalidated. Refinements this session:
1. `packages/marvel/src/Database/Models/User.php:12` declares `Notifiable` (was INFERRED → now **Verified**).
2. `user_preferences` table confirmed currency-only (removes a possible misreading; no third preference system).
3. Dead-code set re-confirmed by fresh greps: `PushNotificationService::registerDevice` (:96), `FirebaseProjectResolver::projectId` (:20-23), `OrderNotification::markAsDelivered` (:61).
4. Kreait 6.7 Messaging exception taxonomy enumerated (9 classes) — enables the PHASE 2 error-classification design without new dependencies.
5. Supervisor worker policy verified via WorkerConfigPolicyTest (high tries=5/timeout=1300; retry_after=1800 documented > timeout) — timeout-duplicate window closed at config level.
6. API contract for Path A device endpoints fully documented in docs/api/device-tokens.md — **an explicit backward-compatibility anchor** (envelope, 200-on-unknown-delete semantics, reassignment description).
7. N-008 (simulated success) confirmed additionally on the `markAsSent(null, $result)` ledger path.
8. New detail: `order_notifications.user_id` NOT NULL vs guest skip-insert (D-016) — precise mechanism for the previously observed silent loss.

**Full mapping**: N-001→D-001, N-002→D-002, N-003→D-003, N-004→D-004, N-005→D-005, N-006→D-006, N-007→D-007, N-008→D-008, N-009→D-009, N-010→D-010, N-011→D-011, N-012→D-012, N-013→D-013, N-014→D-014, N-015→D-015, N-016→D-016, N-017→D-017, N-018→D-018, N-019→D-019, N-020→D-020, N-021→D-021, N-022→D-022, N-023→D-023, N-024→D-024. Risks R-1…R-7 and unknowns U-1…U-8 carry over unchanged.

## Output 7 — Missing information list

| # | Missing item | Blocks | Resolution path |
|---|---|---|---|
| M-1 | Production env (FIREBASE_*/FCM_*/QUEUE_*/REDIS_* actual values, RabbitMQ broker) | severity of D-001/D-008 in prod; rollout plan | deployment-environment inspection (out of repo) |
| M-2 | DB data inventory (row counts; duplicate tokens across tables/users; metadata distribution) | migration plan concreteness | scratch-DB dump/queries (needs DB access approval) |
| M-3 | Which registration endpoints shipped mobile apps call; `client` correctness; token-refresh behavior; unregister-on-logout | D-003/D-004 reality; API deprecation safety | mobile-team confirmation |
| M-4 | v1-API error taxonomy as surfaced by kreait 6.7 (per-item strings) | invalid-token string matching correctness | sandbox live-fire |
| M-5 | Worker deployment reality (supervisor configs actually deployed; queue physical names) | retry/timeout invariants in prod | devops confirmation |
| M-6 | Notification volumes (blast sizes) | D-011 impact sizing | production metrics |
| M-7 | RabbitMQ production status for coupon path | Path C continuity during migration | devops confirmation |
| M-8 | Whether users actually have non-default `notification_language` | D-012 impact | prod data |

## Output 8 — Risks and unresolved decisions

**Carried-over risks (R-1…R-7, unchanged)**: R-1 production Path A likely non-functional (batch endpoint) yet reports success; R-2 stuck-queue no alerting; R-3 blast-scale silent loss; R-4 kreait 6.7 error-string assumptions; R-5 `strict=false` masking truncation; R-6 Redis config unknown; R-7 dual-table device overlap ⇒ dual pushes per device.

**Unresolved decisions (inputs to PHASE 2 design; no option selected here):**
- **DEC-A canonical token registry**: consolidate onto `device_tokens` (global-unique foundation, documented contract) with metadata migration from `user_device_tokens`, vs schema evolution of `user_device_tokens`, vs new table. Data inventory (M-2) and mobile contract (M-3) are inputs.
- **DEC-B logout/device-release policy**: deactivate on logout vs keep. Product decision; mobile team dependent.
- **DEC-C single push owner per order transition** (kills D-007 double-push): which system keeps cancel/deliver.
- **DEC-D mandatory vs preference-controlled notification set** (scope of DEC preference enforcement).
- **DEC-E blast fan-out strategy** at volume.
- **DEC-F user-facing history source** (`notifications` vs `order_notifications`) — API contract impact requires approval if changed.
- **DEC-G push idempotency contract** (server uuid + client dedupe) — mobile dependency.
- **DEC-H RabbitMQ governance** (AGENTS.md "removed" vs reality) — outside notification code but affects Path C continuity.
- **DEC-I kreait upgrade (≥7.x) vs per-token send loop on 6.7** for the retired-endpoint fix.

---

# FINDINGS TABLE (consolidated, evidence-verified this session)

| ID | Finding | Severity | Status | File:line | Expected behavior | Business impact | Security impact | Data impact | Proposed correction | Depends on | Required tests |
|---|---|---|---|---|---|---|---|---|---|---|---|
| D-001 | Multicast uses retired batch endpoint | Critical | Verified (code) / Risk (runtime) | vendor SendMessages.php:48; Messaging.php:73-93; FcmService.php:34; composer.lock:3890 | v1 per-message sends (or upgraded SDK) | Total push failure on Path A | none | none | DEC-I | M-4, M-1 | fake-transport unit test; sandbox live-fire |
| D-002 | Provider failures swallowed; false success; no retry | Critical | Verified | FcmService.php:50-53; Job:18-19,57-60 | throw ⇒ tries/failed_jobs engage | Silent loss, ops blind | none | no failure ledger | rethrow + ledger | DEC-I | unit: exception ⇒ job failed; feature: failed_jobs row |
| D-003 | Same token active under multiple users (Path B) | High | Verified | user_device_tokens migration :40; NotificationPreferencesController.php:58-72; PushNotificationService.php:22 | global-unique token + reassignment | Cross-user push on shared devices | **High** | duplicate rows | DEC-A | M-3 | feature: register A then B ⇒ A inactive |
| D-004 | Logout retains device tokens | High | Verified | UserController.php:660-673 | DEC-B policy applied | Privacy on shared devices | **High** | stale rows | DEC-B + mobile confirm | M-3 | feature: logout ⇒ per-policy state |
| D-005 | Preferences ignored on Path A (27 types) | High | Verified | FcmChannel.php (whole); grep of UserNotificationPreference | push_enabled + event prefs honored | Opt-out violated | trust/compliance | none | enforce in FcmChannel | DEC-D | feature: push_enabled=false ⇒ no job |
| D-006 | Invalid-token deactivation dead (Path B) | High | Verified | PushNotificationService.php:42 vs :90-92 | Kreait NotFound ⇒ deactivate | dead-token churn | none | rows never deactivated | map Kreait exception taxonomy | M-4 | unit: NotFound ⇒ deactivated |
| D-007 | Duplicate push on cancel/deliver | High | Verified | OrderService.php:1388-1396; EventServiceProvider.php:149-157,164-170 | exactly one push per transition | duplicate notifications | none | none | DEC-C | — | feature: cancel ⇒ exactly 1 user push |
| D-008 | Simulated mode writes false 'sent' ledger | Med-High | Verified | PushNotificationService.php:66-77; SendOrderPushNotification.php:64-71 | simulated ≠ sent | monitoring lies | none | ledger contamination | explicit 'simulated' state | — | unit: disabled ⇒ no 'sent' row |
| D-009 | Per-item transient FCM errors unretried | Medium | Verified | FcmService.php:44-48 | retry/requeue transient items | partial silent loss | none | none | retry policy per taxonomy | DEC-I | unit: transient ⇒ retry |
| D-010 | No push idempotency key | Medium | Risk | SendFcmNotificationJob (whole) | idempotent re-execution | occasional dupes | none | none | DEC-G | M-3 | integration: double-run ⇒ one delivery |
| D-011 | All-user blast fan-out (3 jobs/user on high) | Medium | Verified (pattern)/Risk (scale) | SendUserFlashSaleAvailableNotification.php:23-33; SendUserPromotionAvailableNotification | queue-shaped batch fan-out | notify-storm at scale | none | none | DEC-E | M-6 | perf test |
| D-012 | Push locale = worker locale; preference unused (Path A) | Medium | Verified | FcmChannel.php:46-55; UserNotificationPreference.php:30 | per-user language | wrong-language pushes | UX | none | resolve from preference | — | unit: ar-pref ⇒ ar title |
| D-013 | Coupon crash window (notify before state write) | Medium | Risk | NotificationRequestHandler.php:96-136 | atomic claim-then-notify | rare duplicate | none | none | claim-then-notify | — | pipeline crash test |
| D-014 | Ending-soon flag saved after fan-out | Low | Risk | NotifyPromotionsEndingSoon.php:44-45 | claim-before-fan-out | rare duplicate | none | none | adopt cart-claim pattern | — | rerun test |
| D-015 | Platform reset to 'android' on re-register | Low | Verified | DeviceTokenController.php:32 | preserve platform | metadata corruption | none | minor | set-if-provided | — | feature test |
| D-016 | Guest skip-ledger insert fails silently | Low | Verified | SendOrderPushNotification.php:131-143; order_notifications migration :14 | nullable user_id | telemetry gap | none | minor | nullable/omit | — | feature test |
| D-017 | Missing ar out_for_delivery; hardcoded maps | Low | Verified | SendOrderPushNotification.php:98-104 | translation-system keys | wrong language | UX | none | add keys/consolidate | — | unit |
| D-018 | Sync admin listeners in request path | Low | Verified | SendAdminLoginNotification.php:10; SendContactMessageNotification.php:10 | ShouldQueue | latency coupling | none | none | queue them | — | feature |
| D-019 | markAsDelivered dead | Low | Verified | OrderNotification.php:61 | remove or wire | misleading schema | none | none | DEC-F input | — | — |
| D-020 | PushNotificationService::registerDevice dead | Low | Verified | PushNotificationService.php:96-102 | remove/consolidate | divergence risk | none | none | DEC-A | — | — |
| D-021 | projectId() unused; no project cross-check | Low | Verified | FirebaseProjectResolver.php:20-23 | validate at factory build | wrong-project sends possible | Medium | none | add validation | M-1 | unit |
| D-022 | RabbitMQ governance drift | Info | Verified | Kernel schedule comments; RabbitMqCouponEventTransport | reconcile claim/system | governance confusion | none | none | DEC-H | M-7 | — |
| D-023 | History API shows order ledger only | Medium | Verified | NotificationPreferencesController.php:92-102 | canonical history source | incomplete user history | UX | none | DEC-F | — | contract test |
| D-024 | No stale-token sweeper | Medium | Verified (absence) | — | scheduled sweeper | unbounded growth, wasted sends | none | growth | design sweeper | DEC-A/B | feature |
| D-025 | Fresh Firebase Factory per token (Path B) | Low | Verified | PushNotificationService.php:80-81 | cached factory | quota/latency churn | none | none | cache like FcmService | — | unit |
| D-026 | No .env template documenting notification keys | Info | Verified (absence) | glob .env* = none | documented keys | setup friction | none | none | add template | — | — |

---

# GATE — STOP FOR APPROVAL

Per the execution-control rules, PHASE 2 (DESIGN) and PHASE 3 (IMPLEMENT) will **not** begin until the findings above — and especially the decision inputs **DEC-A through DEC-I** (with their blocking items M-1…M-8) — are reviewed.

**PHASE 1 is complete. No code was modified. Awaiting explicit approval to proceed to PHASE 2 (target architecture design), which will itself remain read-only and end in a second approval gate before any implementation.**
