# Notification System Unification — PHASE 2 Target Architecture Design

**Status:** READ-ONLY design phase. No code, migrations, configuration, tests, or database records were modified. HEAD re-verified: `2f258c05f797cd8024d174da870755f08b08f6c0` (unchanged from PHASE 1).
**Evidence sources:** repository source at the pinned HEAD, installed vendor source (`vendor/kreait/firebase-php` 6.7.0), upstream maintainer documentation (beste/firebase-php Discussions #911, #970; Issue #929; CHANGELOG 7.5.0/7.0.0), prior PHASE 0/1 reports. No production data was assumed or invented.

---

# §2 First: Reconciliation of PHASE 1

## 2.1 N-001…N-024 ↔ D-001…D-024 mapping

All 24 previous-audit findings were re-validated at the pinned HEAD during PHASE 1 with identical line numbers. The mapping is 1:1 and unchanged:

N-001→D-001 · N-002→D-002 · N-003→D-003 · N-004→D-004 · N-005→D-005 · N-006→D-006 · N-007→D-007 · N-008→D-008 · N-009→D-009 · N-010→D-010 · N-011→D-011 · N-012→D-012 · N-013→D-013 · N-014→D-014 · N-015→D-015 · N-016→D-016 · N-017→D-017 · N-018→D-018 · N-019→D-019 · N-020→D-020 · N-021→D-021 · N-022→D-022 · N-023→D-023 · N-024→D-024.

No duplicate IDs exist. One severity refinement is proposed (not silently changed): **D-008** (simulated success) is upgraded Medium→**High**, because the false-success path writes `order_notifications.status='sent'` by default (`services.fcm.enabled=false`, config/services.php:46) — i.e., in any default deployment the order-status delivery ledger is contaminated without any action. Rationale documented here; the discovery report table itself is not edited (read-only phase).

## 2.2 D-025 and D-026 — provenance

- **D-025** (fresh Firebase `Factory` per token in Path B, PushNotificationService.php:80-81) — **new finding** in PHASE 1; not present in N-001…N-024. It is the performance twin of D-021's neighbor (FcmService already caches factories).
- **D-026** (no `.env.example`/template documenting `FIREBASE_*`, `FCM_*`, `QUEUE_HIGH/MEDIUM` keys; glob `.env*` returns nothing) — **new finding** in PHASE 1; not in the previous audit.
Both are classified Low/Info and are **pre-existing conditions** (they exist at the pinned HEAD), newly *discovered* — not regressions introduced by the previous audit.

## 2.3 Corrections and precision fixes to PHASE 1

| # | PHASE 1 statement | Correction (evidence) |
|---|---|---|
| C-1 | "all 27 classes onQueue high (27/27 verified)" | **Imprecise.** 23 user/order/payment classes use `config('queue.queues.high')`; **`AdminLoggedInNotification` (:19), `NewContactMessageNotification` (:18), `AdminQueueJobFailedNotification` (:29) use `medium`** (grep of `onQueue` re-run this phase). Blast/queue strategy (DEC-E) accounts for the real split. |
| C-2 | "3 queued jobs per user (DB+fcm+BR)" for blasts | **Refined.** Laravel queues **one** `SendQueuedNotifications` job per notifiable; the database channel write executes **inside** that job; the FCM job and the queued `NotificationSent` broadcast event are two further jobs. Verified framing matches `RealUserNotificationDatabasePersistenceE2ETest.php:364-366` ("TWO independent jobs are queued… processed INDEPENDENTLY"). Net effect for blasts: up to 3 jobs/user, DB write inside job 1 — the design conclusion (DEC-E) is unchanged. |
| C-3 | D-016 phrasing | Confirmed mechanism: `logSkipped` passes `$order->user_id` (nullable for guests) into a NOT NULL FK column; insert fails; swallowed by empty catch. Status stays **Verified (code-level)**, not runtime-tested (DB unreachable). |
| C-4 | D-011 "test-pinned admin exclusion" for flash-sale all-user blast | The admin exclusion is test-pinned for **wishlist fan-outs** (UserNotificationPhase3Test) and coupon blasts (UserNotificationTest). For `SendUserFlashSaleAvailableNotification` the admin exclusion exists in code (`UserType::USER` filter, :24-28) and is covered by `UserPromotionFlashSaleNotificationTest` listener tests. Severity stands at Medium; scope of test-pinning corrected. |

## 2.4 Claims that are Inferred rather than Verified (carried into design assumptions)

| Claim | Status | Handling in design |
|---|---|---|
| Batch endpoint returns failure in production today | Risk (external evidence: SDK maintainer + Firebase FAQ + issue #929 501 reports) | Design does not depend on it being *currently* failing; fix is required regardless |
| Kreait 6.7 per-item v1 error strings match `'not a valid FCM registration token'` | Inferred (vendor code path read; not live-fired) | DEC-I replaces string matching with exception-class mapping; sandbox live-fire remains a pre-rollout test |
| Mobile apps call which registration endpoints | **Unknown** (no mobile source in repo) | DEC-A/B migration plans are dual-API compatible; mobile confirmation is a listed dependency |
| Blast volume/queue pressure at production scale | Inferred from pattern | DEC-E metrics gate any sizing decision |
| `user_device_tokens` production overlap statistics | **Unknown** | Migration plan includes inventory + quarantine (never silent discard) |

## 2.5 Contradictions check

One internal inconsistency found and corrected above (C-1). One external contradiction stands and is deliberately **not resolved in this phase**: AGENTS.md states "RabbitMQ has been removed" while the repository ships and uses `RabbitMqCouponEventTransport` (DEC-H).

## 2.6 Exact API contracts that must remain compatible

| Contract | Evidence | Preservation requirement |
|---|---|---|
| `POST/DELETE /api/v1/general/device-tokens` — envelope `{status,message,success,data{uuid}}`; global-unique reassignment; DELETE returns 200 for foreign/unknown token; 401/422 matrix | docs/api/device-tokens.md (full contract doc) + DeviceTokenController.php:37,48 | Behavior-preserving; any semantic change (e.g., 404 on foreign delete) requires separate approval |
| `GET/PUT /api/v1/user/notification-preferences`; `POST /api/v1/user/devices/register`; `DELETE /api/v1/user/devices/{deviceId}` (404 if not owned); `GET /api/v1/user/notifications/history` (paginated `OrderNotification`) | routes/api.php:323-329; NotificationPreferencesController.php:20-102 | Envelope + status codes preserved; history **source** unchanged unless separately approved (DEC-F) |
| Marvel user `GET /notifications…` and admin `/admin/notifications…` (VIEW_/MANAGE_NOTIFICATIONS permissions) | packages/marvel/src/Rest/Routes.php:454-473; NotificationController | Untouched by this design |
| Broadcast channel authorization (`admin.notifications`, `users.{id}`, `user.{userId}.orders`, `order.{orderId}`) | routes/channels.php:18-33 | Untouched |
| ApiResponse envelope trait | packages/marvel/src/Traits/ApiResponse.php | Untouched |
| Push payload data keys (A: localized title/message + `action_url`, `order_id`, `resource_*`; B: `order_id`, `order_number`, `status`, `click_action`) | 27 notification classes; SendOrderPushNotification.php:45-51 | No payload-content change in this design (DEC-C removes only a duplicate send) |
| Coupon pipeline API/observability (run/recipient/user-state tables, event log) | app/Services/Coupon/Distribution/* | Path C internal guards preserved (DEC-G boundary) |

## 2.7 Channel-preservation confirmation

The design **does not remove or alter**: database channel writes (inside `SendQueuedNotifications`), broadcast events/Pusher (BroadcastMessage on configured queues; channels untouched), mail (all app mail sends + 21 Marvel mail-only notification classes, e.g. `NewOrderReceived` via `['mail']`), SMS (`SendOrderStatusSMS` + `SMSService`), email verification, Marvel listeners. Only **push delivery** is unified. DEC-C removes one duplicate **send**, not any channel.

---

# §4 DEC-A — Canonical Device Registry

## Option comparison

| Criterion | A. Evolve `device_tokens` | B. Evolve `user_device_tokens` | C. New registry |
|---|---|---|---|
| Global token uniqueness | **Already unique** (migration 2026_08_23:15) | Not unique (per-user unique :40) → requires destructive index change + backfill dedupe | Requires full table build + dual data migration |
| Ownership semantics | **Reassignment proven** in code + tests (FcmIntegrationTest:59-69); security-fix documented | Multi-user ownership possible (D-003) — must be redesigned anyway | Fresh design, no reuse |
| Existing API contract | Fully documented (docs/api/device-tokens.md), test-pinned endpoints | Documented informally only | Both APIs need re-pointing |
| Metadata (device_name, device_id, app_version, os_version, expires_at, is_active) | **Missing** — must be added | **Present** (:24-34) | Choose freely |
| `client`/Firebase project identity | Present (:16), controller-validated | Absent — Path B cannot target per-client projects at all | n/a |
| FK / cascade | users cascadeOnDelete (:14) | users cascade (:38) | New FK |
| Indexes | (user_id, client) + unique uuid | (user_id, is_active), device_id | Design new |
| Migration complexity | **Additive columns + one data-merge** | Destructive index rebuild + ownership rewrite + client column invention | Largest |
| Rollback safety | Column-drop reverse migration; additive = low risk | Index/unique rebuild on live data | Full table removal |
| Data-loss risk | Low (additive) | Medium (unique-key rebuild over unknown duplicates) | Low-Medium (two merges) |
| Mobile compatibility | **Zero change** (endpoint unchanged) | Zero change if endpoint kept | Zero if both endpoints re-pointed to same service |
| Extensibility | Good (platform/client/metadata on one row) | Good after redesign | Good |

**Recommendation: Option A — evolve `device_tokens`.** The alternatives are inferior because B must *first* fix the exact defect that unification exists to remove (multi-user token ownership) via a destructive unique-key rebuild over production data of unknown composition (M-2), and C duplicates both migrations' work while adding a third table lifetime. Option A adds the missing Path-B metadata as nullable columns, keeps the proven global-unique + reassignment core, and preserves the documented contract byte-for-byte.

## Target schema (additive only)

`device_tokens` gains: `device_name` string nullable, `device_id` string nullable, `app_version` string nullable, `os_version` string nullable, `is_active` boolean default true, `expires_at` timestamp nullable, `deactivated_at` timestamp nullable, `deactivated_reason` string nullable (logout|invalid_token|sweeper|manual), `platform` widened to enum('android','ios','web') to accept Path B's `web` value. New index `(is_active)` (sweeper/reads), existing `(user_id, client)` retained. **All metadata is preserved**; `platform`/`client`/`last_used_at`/`expires_at`/`is_active` are first-class; `device_id`/`device_name`/versions carry over from `user_device_tokens` during the merge.

## Conflict resolution (ownership)

On merge and on registration: a token maps to **exactly one** user (global unique). Merge rule per token: keep the row with the **most recent `last_used_at`**; all other rows are **not deleted** — they are copied to `device_token_merge_conflicts` (quarantine: token, losing_user_id, losing metadata, canonical_user_id, merged_at) for auditability. Runtime rule (already proven in Path A): any registration of an existing token **reassigns** it to the registering user and deactivates the association for prior owners implicitly (row is the association). Path B's `registerDevice` endpoint becomes an adapter over the same registry service, so its behavior converges (fixes D-003) while keeping its API envelope.

## Canonical model / service

`DeviceToken` (evolved model) + one `DeviceRegistry` service owning: register, reassign, unregister, deactivate-on-invalid, deactivate-on-logout (DEC-B), sweep-stale, and read-by-user-for-push. Both controllers call it; the FCM job and the unified dispatcher read through it. No other code may write token state (enforced by review + tests, not by language).

## Production-data inventory (precondition, not invented)

Row counts, duplicate-token counts across tables/users, metadata completeness are **UNKNOWN** (M-2, DB unreachable). The migration plan (§14) begins with a read-only inventory script executed in a controlled environment; conflict counts drive the merge report. No assumption about overlap statistics is embedded in the design.

---

# §5 DEC-B — Logout and Device Ownership

## Option evaluation

| Option | Privacy | UX | Mobile dependency | Data consequence |
|---|---|---|---|---|
| A. Deactivate token(s) of this device on logout | Correct on shared devices | User stops receiving push after logout (expected for most consumers; breaks "logged-out marketing reach" if any exists) | **None** — backend-only (soft-deactivate, reversible by re-register on next login) | Rows retained (`is_active=false` + reason) |
| B. Preserve token, detach account (token with user_id=null) | Same privacy as A for that account | Same | None | Loses "which user logged out here" unless reason column used; orphan rows accumulate |
| C. Keep current behavior (retain active association until re-registration) | Weak (D-004 persists) | Push continues after logout | None | Current behavior preserved |

**Recommendation: Option A as soft-deactivation, behind config `notifications.logout_deactivates_devices` default `false`.** Rationale: default-off preserves today's behavior exactly (rule 16 — no silent behavior change); the flag is the approval-switch for the privacy-correct policy. `deactivated_reason='logout'` makes the action reversible and auditable; a later re-login re-activates via normal registration. Option B is inferior: detaching creates ownerless rows and loses the association that Path A's reassignment model uses for account switching. This is a **business/product decision (Category B)** for the default value; the engineering mechanism is Category A.

## Explicit behavior table (post-design)

| Event | Canonical behavior |
|---|---|
| Logout (current device) | If flag on: deactivate rows matching the device for this user (reversible, reason=logout). If off: no change (today's behavior) |
| Login different account, same physical device | Registration endpoint re-registers token → **reassignment** (proven Path A semantics, now enforced on both APIs) |
| Token refresh (FCM rotation) | Client POSTs new token → normal upsert (new token row; old token becomes stale → sweeper/invalid-token path) |
| Account switching (explicit) | Same as login-switch |
| Multiple devices per account | Supported (per-user set); each row independent |
| Re-register existing token | Update user/client/platform/metadata + `last_used_at`; **no duplicate row** (global unique) |
| Invalid/expired tokens | Provider NotFound/UNREGISTERED → `is_active=false`, reason=invalid_token, **row retained** (rule 11: no silent deletion; hard-delete only via explicit retention decision) |
| Stale tokens | Scheduled sweeper deactivates rows with `last_used_at` older than N days (config; default proposal 90d) — never deletes |
| Account deletion/suspension | FK cascade already removes rows on hard delete (both current tables); ban flow unchanged |

**Mobile-team confirmation required (Category C):** which endpoints shipped apps call; whether the app unregisters on logout; token-refresh registration cadence. Nothing about mobile behavior is claimed beyond repository evidence (none exists).

---

# §6 DEC-C — Order Notification Ownership

## Evidence recap

- Cancel: `OrderService.php:1388-1392` fires `OrderStatusChanged` **and** `OrderCancelled` in one transaction; wired listeners send **two** pushes (B listener EventServiceProvider:168; A listener :152).
- Deliver: :1394-1396 same pattern (A listener :155-156; B listener :168).
- A's cancel/deliver notifications carry richer localized payloads (en+ar title/message, totals, `action_url`) and are test-pinned (OrderNotificationE2ETest asserts DB+BR for both).
- B's push for those statuses uses hardcoded en/ar maps with the missing-`out_for_delivery`-ar defect (D-017) and `click_action` data keys.
- B is the **only** push for processing/shipped/out_for_delivery/completed (A has no per-status notification for those).

## Options

1. **B owns all order pushes** (remove 'fcm' from `UserOrderCancelledNotification`/`UserOrderDeliveredNotification`): consolidates, but changes push content for cancel/deliver (loses A's payload keys → mobile deep-link contract risk, UNKNOWN M-3) and regresses localization (D-017 class bugs).
2. **A owns cancel/delivered push; B suppresses the send for statuses that already have an A push, keeping its ledger row** (status=`skipped`, reason=owned-by-notification-channel): zero payload change, zero DB/broadcast change, duplicate eliminated, D-017 avoided.
3. Single orchestrator rewrites both (largest change; rejected as unnecessary per "smallest design" rule).

**Recommendation: Option 2.** It is the smallest change that removes the duplicate without touching payload contracts, and it composes with DEC-D (preference gate at `FcmChannel` covers A-owned statuses).

## One logical event ⇒ one push rule

Order transition mapping (unchanged business logic — OrderService untouched):

| Status | Push owner | DB row | Broadcast | Notes |
|---|---|---|---|---|
| cancelled | A `UserOrderCancelledNotification` | A writes notifications row; **B writes order_notifications row (skipped/owner=A)** | A | B ledger preserved (history API contract) |
| delivered | A `UserOrderDeliveredNotification` | same pattern | A | same |
| processing/shipped/out_for_delivery/completed | B (unified dispatcher) | B ledger row as today | none today | unchanged |
| completed + PaymentSucceeded | **also** A `UserPaymentSucceededNotification` | as today | as today | Overlap is semantic (payment vs order event) — flagged as **business decision DEC-J**; default keep (no silent suppression) |

Refund (A7) already A-owned; no change.

---

# §7 DEC-D — Notification Preferences

## Enforcement point (one choke point)

`FcmChannel::send()` gains a preference gate before dispatching the push job:
1. Resolve `UserNotificationPreference::forUser($notifiable->getKey())`.
2. If `!$preferences->isChannelEnabled('push')` → skip (ledger `skipped`, reason=push_disabled).
3. If notification declares category `optional` and `!isEventEnabled($eventType,'push')` → skip (same).
4. Optional category additionally respects `isInQuietHours()` (config-gated).
5. Push locale = `$preferences->notification_language` (fallback app locale) — fixes D-012 at the single point.

`SendOrderPushNotification` keeps its existing (already-correct) preference flow and calls the same helper to avoid duplicated rules. **No preference object is consulted inside the FCM job** (job remains transport-only).

Notification classes declare a category via an opt-in method `notificationCategory(): string` (default `'transactional'` = current always-push behavior ⇒ **nothing is silently suppressed**; marketing classes explicitly opt into `'optional'`).

## Classification table (all push-bearing types; "Existing behavior" = no preference check except where noted)

| Notification type | Existing behavior | Proposed preference rule | Mandatory/Optional | Evidence | Approval required |
|---|---|---|---|---|---|
| UserOrderCreated/Delivered/Cancelled/Refunded | always push | push_enabled only (transactional floor) | **Mandatory** | A1,A5,A6,A7 listeners | No (default preserves) |
| UserPaymentSucceeded/Failed | always push | push_enabled only | **Mandatory** | A2,A3 | No |
| UserDigitalProductsAvailable | always push | push_enabled only | **Mandatory** | A8 | No |
| UserCouponAssigned/Used/Eligible | always push | push_enabled only | **Mandatory** (user-specific entitlement) | A12,A13 | No |
| AdminLoggedIn, NewContactMessage, NewOrder, AdminDigitalDeliveryFailed, AdminQueueJobFailed | always push to admins | **admin recipients — user prefs not applicable** (recipient class 'admin') | Mandatory (operational) | A9,A10,A1,A8,A18 | No |
| UserCouponAvailable | always push (blast) | push_enabled **and** event_preferences | Optional | A11 | **Yes — Category B** |
| UserPromotionAvailable/PriceDrop/EndingSoon | always push | same | Optional | A14,A16 | **Yes** |
| UserFlashSaleAvailable/PriceDrop/EndingSoon | always push | same | Optional | A14,A16 | **Yes** |
| UserProductPriceDrop/BackInStock/DiscountChanged | always push | same | Optional | A15 | **Yes** |
| UserAbandonedCart | always push | same | Optional | A17 | **Yes** |
| UserReviewApproved/Rejected | always push | same | Optional | §A | **Yes** |
| Order-status push (Path B, all statuses) | event prefs enforced today | unchanged (existing enforcement kept) | Mandatory (transactional floor) | SendOrderPushNotification.php:36-41 | No |

Safe default for any type not listed at implementation time: **transactional (push)** — i.e., absence of a category decision never suppresses an existing notification. No product policy has been invented: the Optional assignments above are *proposals* pending Category-B approval; until approved the code keeps today's behavior.

---

# §8 DEC-E — Blast and Queue Strategy

## Current mechanics (verified)

- Per-user fan-out preserves per-recipient DB rows, localization, and per-user dedupe keys. Each notification ⇒ 1 notification job (DB inside) + 1 FCM job + 1 broadcast event job.
- Listener queues: order/payment/coupon blasts on `high`; wishlist fan-outs via `NotifyWishlistUsersOfProduct` (chunked 500) on `medium` config per its docblock; `AdminLoggedIn`/`ContactMessage` on `medium`; everything else `high`.
- Installed transports: database queue (jobs/failed_jobs tables), Redis available, RabbitMQ (coupon only). No SQS/beanstalkd in use.

## Design (minimal, no new infrastructure)

1. **Blast listeners route to `medium`** (via `QueueName::medium()` in `viaQueue` for the six marketing listeners) so a flash-sale activation cannot starve transactional pushes on `high`. Transactional stays `high`.
2. **Chunked fan-out preserved** (`chunkById(500)` already present) — chunking bounds memory; queue depth is the pressure valve.
3. **Dispatch rate-cap (optional, config-gated)**: Redis-based token-bucket in the blast listeners (`RateLimiter`, installed) limiting notifications/second per blast run; default disabled (zero behavior change until ops validates).
4. **Failure recovery unchanged**: per-user jobs fail independently; `HandleFailedQueueJob` + failed_jobs already cover final failures.
5. **FCM throughput**: single multicast per user's device set (already chunked 500 = SDK limit in 6.7; 7.x removes the limit — changelog 7.5.0) — no change needed to job chunking on upgrade; keep 500 on 6.7.

**Rejected alternatives:** topic messaging (requires client-side subscription contract — mobile UNKNOWN, M-3); a single mega-job looping all users (defeats per-user retry/observability); new broker (violates no-new-infra rule).

## Capacity metrics required before enabling rate-cap/sizing (Category C — DevOps)

queue depth per physical queue; job age p95; FCM per-minute error rate by class; DB insert latency on `notifications`; worker saturation (WorkerConfigPolicyTest-documented topology: high tries=5/timeout=1300, medium tries=3/timeout=1300).

---

# §9 DEC-F — Notification History and Delivery Ledger

## Options

| Option | Verdict |
|---|---|
| Laravel `notifications` as ledger | Wrong scope: per-recipient content + read-state; no per-channel provider outcomes |
| `order_notifications` as ledger | Order-scoped only; schema lacks per-channel attempt identity; already contaminated by D-008 |
| Separate delivery-attempt ledger | **Selected** — isolates delivery truth without touching user-facing contracts |
| Minimal evolution of current schema | Mixed into `order_notifications` = scope violation |

## New `notification_deliveries` table (migration, additive)

Columns: `id`, `dedupe_key` (string 64, indexed), `channel` ('fcm'|'database'|'broadcast' — only 'fcm' is written initially), `recipient_id`, `notification_type`, `event_identity` (string 191), `client` nullable, `device_token_id` nullable, `provider` ('fcm'), `provider_message_id` nullable, `status` enum('queued','attempted','provider_accepted','failed','skipped','simulated') default 'queued', `error_class` nullable, `error_message` nullable, `attempts` int default 0, timestamps. Unique index `(dedupe_key, channel)` = idempotency claim (DEC-G). Index `(recipient_id, created_at)`.

## State semantics (binding)

| State | Meaning | Set by |
|---|---|---|
| requested | Dispatcher resolved recipients and created the send intent (implicit; ledger row birth) | dispatcher |
| queued | Ledger claim row written for (notification, channel); job not yet running | dispatcher |
| attempted | Provider request sent for this (notification, device or device-set) | FCM job |
| provider_accepted | Kreait `SendReport` success for the token/set | FCM job |
| failed | Permanent or exhausted-retry failure (error_class set) | FCM job |
| skipped | Preference/ownership/missing-device suppression (reason in error_message) | dispatcher/listener |
| simulated | `services.fcm.enabled=false` — no provider call; **explicitly not 'sent'** (fixes D-008) | FCM job |

**`delivered` is not used** — no delivery-evidence source exists (FCM provides none; APNs feedback not integrated). Provider acceptance ≠ device display.

## User-facing history vs delivery ledger — separated

- User-facing history: Laravel `notifications` + existing `/user/notifications/history` (order ledger) — **contracts untouched** (DEC-F does not change them; changing the history source is an explicitly-approved future change).
- `order_notifications`: preserved as-is (order-status flows continue writing it; the D-008 contamination stops because the unified dispatcher writes `simulated`/real states; the legacy B listener's `markAsSent(simulated)` call is replaced by the dispatcher's ledger write while the order_notifications row gets the real status).

---

# §10 DEC-G — Idempotency

## Logical event identity (derived only from existing stable identifiers)

| Family | event_identity | Source evidence |
|---|---|---|
| Order transitions | `order:{orderId}:{status}` | OrderService transition guard (`$previousStatus !== $status`, :1390-1396) |
| Payment | `payment:{orderId}:{gateway}:{reference}` (gateway ref from PaymentSucceeded event payload where present; fallback `{orderId}:{attempt}` recorded by emitting site) | webhook controllers |
| Refund | `refund:{refundId}:{action}` | RefundService |
| Coupon distribution | `coupon-elig:{couponId}:{treeHash}:{userId}` | existing guards (NotificationRequestHandler.php:73-94) — Path C keeps its own guards; ledger records outcomes only (no second source of truth) |
| Promotion/flash-sale activation | `{promotion|flashSale}:{subjectId}:{subjectUpdatedAt}` — event carries the model (PromotionActivated.php:14-16); observer state machine fires once per activation (test-pinned) | PromotionObserver.php:29/:63 |
| Wishlist/product events | `product:{productId}:{eventClass}:{triggeredAt}` (observer passes old/new values) | ProductObserver.php:154-176 |
| Cart reminder | `cart:{cartId}:reminder` — atomic `reminder_sent_at` claim already guarantees once (NotifyAbandonedCarts.php:41-48) | existing |
| Admin/security | `admin:{eventClass}:{subjectId}:{occurredAt}` | A9,A10,A18 |

Idempotency key = `sha256(event_identity|notification_type|recipient_id)`; uniqueness scope `(dedupe_key, channel)` in `notification_deliveries`.

## Flow and boundaries

1. Dispatcher (queued listener/notify path) computes key → `INSERT ... ON DUPLICATE KEY UPDATE id=id` claim (status `queued`). Claim happens in the **dispatcher's own transaction** — events are `ShouldDispatchAfterCommit` (test-pinned), so a rolled-back business transaction never leaves a claim (rule: no orphan claims).
2. FCM job loads the claim; if status ∈ {provider_accepted} → **return without sending** (duplicate prevention on retries/concurrent workers).
3. On first provider attempt: update `attempted`; on per-token outcome: update `provider_accepted`/`failed` (+ error_class).
4. Retries (tries/backoff): re-enter at step 2 — idempotent.
5. Retention: rows older than 90 days pruned by scheduled command (config; aligned with existing `queue:prune-failed --hours=720` pattern).

## Separation of concerns

- **Server-side dedupe** (this design): prevents duplicate *dispatch/send* for the same logical event+recipient+channel.
- **Client-side display dedupe** (mobile responsibility, DEC-G dependency): FCM `collapse_key`/notification id in data payload is **not** invented server-side here; adding a stable `notification_id` data key is a payload addition = mobile contract item (Category C; requires approval if adopted). Until then, dedupe key is internal only.
- Coupon pipeline: its durable guards remain authoritative for distribution state; the ledger never gates coupon sends (no second source of truth).

---

# §11 DEC-H — RabbitMQ Governance

Evidence: `RabbitMqCouponEventTransport` implements the `CouponEventTransport` contract consumed by the coupon distribution backbone (outbox publisher + consumers incl. `NotificationRequestHandler` → coupon notifications); php-amqplib 3.7 installed; Kernel schedule comments reference the transport; AGENTS.md claims "RabbitMQ has been removed". Conclusion: **RabbitMQ is an essential, active dependency of the existing coupon-notification pipeline** (Verified).

Recommendation (this phase): **preserve the transport; change nothing in Path C.** Document the discrepancy in the unification report and flag the AGENTS.md text for a separate governance correction (owner: repository maintainers — outside this task's scope, rule 15). A future transport migration (e.g., to Laravel queues) is explicitly out of scope and would be a new architecture decision requiring approval.

---

# §12 DEC-I — Firebase and Kreait Strategy

## Verified vendor facts (6.7.0 installed)

- `send()` → `SendMessage` → `https://fcm.googleapis.com/v1/projects/{projectId}/messages:send` (vendor Messaging/Http/Request/SendMessage.php:25) — **v1, healthy**.
- `sendMulticast()/sendAll()` → `SendMessages` → `POST https://fcm.googleapis.com/batch` (vendor Messaging/Http/Request/SendMessages.php:48; Messaging.php:73-93).
- Exception surface: `NotFound, InvalidArgument, InvalidMessage, AuthenticationError, QuotaExceeded, ServerError, ServerUnavailable, ApiConnectionFailed, MessagingError` (vendor Exception/Messaging — enumerated this phase).

## Authoritative external evidence (not assumptions)

- SDK maintainer (beste/firebase-php Discussion #911, 2024-07): v5.x migrated **single** sends to v1; **batch/multicast migrated only in 7.x**; "Version 4 and lower will not work with the new FCM APIs".
- Discussion #970 (2024-12): "In the 7.x releases, no deprecated/shut-down API endpoints are used… if you want to use FCM, you need to use a 7.c release".
- Firebase FAQ via Issue #929 (2024-09): legacy send/batch deprecated 2023-06-21, removed June 2024; real-world `501 Operation is not implemented` from the batch endpoint reported.
- CHANGELOG **7.5.0** (2023-06-27): "Replaced calls to deprecated FCM batch endpoints with asynchronous requests to the HTTP V1 API (#804/#805)"; removed the 500-message limit. **7.0.0**: PHP `~8.1.0 || ~8.2.0`. `sendMulticast()` still exists in 7.x (forwards to `sendAll()` — Discussion #970).

## Options

| Option | Endpoint correctness | Dependency change | Call-site change | Risk |
|---|---|---|---|---|
| A. Upgrade kreait to `^7.15` | Batch+single both on v1 (maintainer-confirmed) | Major version (composer) | **None** — `sendMulticast` API preserved; only public-contract API used today (Factory/Messaging/Notification/CloudMessage) | Type-strictness BC (7.0 notes: internal-only in practice); full-suite + sandbox live-fire required |
| B. Keep 6.7; replace `sendMulticast` with per-token `send()` loop | Uses verified v1 path | None | FcmService only | 6.x EOL; serial latency for large device sets; still carries dead batch code |
| C. New SDK/custom client | — | New dependency | All call sites | Largest; violates no-new-infra |

**Recommendation: Option A (upgrade to kreait `^7.15`)**, with Option B as the fallback if the dependency change is blocked. A removes the retired endpoint for **both** call sites at once, keeps the `sendMulticast`/`SendReport` semantics the error classifier needs, and is PHP 8.2-compatible. **No upgrade is performed in this phase.** Pre-rollout tests required (Category A + C): full suite on 7.x; sandbox live-fire verifying per-item report classes (`NotFound`/`QuotaExceeded`/`ServerError`) and v1 endpoint hits; vendor-diff review of `Messaging` contract.

---

# §13 Unified Target Architecture

## 13.1 Architecture diagram

```
                       ┌────────────────────────── Entry points (unchanged) ──────────────────────────┐
                       │ Events → Listeners · Schedulers · Observers · Webhooks · Controllers        │
                       └───────────────┬──────────────────────────────────────────┬─────────────────┘
                                       │                                          │
                     Laravel Notification path                                  Direct order-status path
                     (27 classes, channels DB+FCM+BR preserved)                 (SendOrderPushNotification)
                                       │                                          │
                                       ▼                                          ▼
                        ┌──────────────────────────┐                 ┌────────────────────────────┐
                        │ FcmChannel (payload      │                 │ Preference gate (shared    │
                        │ authority; +pref gate;   │                 │ helper; B keeps its own    │
                        │ +per-user locale)        │                 │ flow)                      │
                        └────────────┬─────────────┘                 └──────────────┬─────────────┘
                                     │ dispatch                                     │
                                     ▼                                              ▼
                    ┌─────────────────────────────────────────────────────────────────────────┐
                    │  SendFcmNotificationJob (queued envelope — name kept)                    │
                    │      └─► PushDispatcher  (AUTHORITATIVE push orchestration)              │
                    │            1. DeviceRegistry::activeTokensForUser()  (canonical read)    │
                    │            2. Ledger claim  (idempotency; DEC-G)                         │
                    │            3. FcmService.sendToClient()  (per-client multicast)          │
                    │            4. FcmErrorClassifier  (Kreait exception taxonomy)            │
                    │            5. Ledger outcome + token state transitions                   │
                    └─────────────────────────────────────────────────────────────────────────┘
                                                   │
                            ┌──────────────────────┼───────────────────────────┐
                            ▼                      ▼                           ▼
                 notification_deliveries   DeviceToken (canonical,     FCM provider (v1 API only;
                 (states: queued/attempted/ evolved schema)              kreait ≥7.15 recommended)
                 provider_accepted/failed/  global-unique token,        — batch endpoint gone
                 skipped/simulated)         is_active/expires_at/reasons
                            ▲
                            │ (not duplicated: coupon pipeline keeps its own state tables;
                            │  Path C outcomes *recorded* in ledger, never *gated* by it)

        Preserved untouched: database channel · broadcast/Pusher channels · mail (incl. 21 Marvel
        mail-only classes) · SMS listeners/service · RabbitMQ coupon transport · order_notifications
        (legacy ledger, still written by Path D listeners) · all API envelopes.
```

## 13.2 Standard push (single notification, single user)

```
Listener(queued) → $user->notify(N) → SendQueuedNotifications job
  ├ database: notifications row (unchanged)
  ├ fcm: FcmChannel.send
  │    payload = N->toDatabase() (unchanged authority)
  │    gate = Preferences.gate(user, N, 'push')   → skipped? ledger(skipped) + return
  │    locale = prefs.notification_language (fixes D-012)
  │    dispatch SendFcmNotificationJob(title, body, data, user_id)
  │         → PushDispatcher:
  │            claim = ledger.claim(key(type|event|user), 'fcm')      [queued]
  │            claim.status==provider_accepted? → return (dup)
  │            tokens = DeviceRegistry.active(user)   [is_active ∧ ¬expired]
  │            per client group: FcmService.sendToClient(...)
  │            outcomes → ledger(attempted/provider_accepted/failed/simulated)
  │            invalid tokens → DeviceRegistry.markInvalid(token)   [is_active=false, reason]
  └ broadcast: NotificationSent event (unchanged)
```

## 13.3 Multi-device multicast

Same as 13.2; PushDispatcher groups active tokens by `client` (per-Firebase-project), one multicast per client ≤500 (6.7 limit; lifted on 7.x). Per-item results processed individually (no whole-batch suppression).

## 13.4 Invalid-token handling

```
SendReport item error → FcmErrorClassifier:
  NotFound / UNREGISTERED            → invalid_token → DeviceRegistry.markInvalid(reason='invalid_token') + ledger failed(error_class=invalid_token)
  InvalidArgument / InvalidMessage   → invalid_payload → ledger failed (no retry, no token action)
  AuthenticationError                → auth_config → ledger failed (NO retry; ops alert via existing JobFailed path after rethrow)
  QuotaExceeded                      → rate_limited → retryable (backoff)
  ServerError / ServerUnavailable / ApiConnectionFailed → transient → retryable (backoff)
  unknown MessagingError             → permanent → ledger failed (no retry) + warning log
Whole-request transport exception   → rethrow after ledger(failed) → Laravel tries/backoff engage (fixes D-002)
```

## 13.5 Retries

`SendFcmNotificationJob: tries=3, backoff=[30,120]` (unchanged). Retry re-enters PushDispatcher; ledger claim + `provider_accepted` short-circuit ⇒ **no duplicate send**. Permanent classes never consume retries (skip rethrow). Exhausted ⇒ `failed()` logs + failed_jobs (existing alerting covers it).

## 13.6 Logout and account switching

```
logout (flag OFF today): no token change (current behavior preserved)
logout (flag ON): DeviceRegistry.deactivateForLogout(user, token?) → is_active=false, reason='logout' (reversible)
login-switch: register(token) → upsert ⇒ row reassigned to new user (both APIs via DeviceRegistry)
              prior association ceases implicitly (single row per token)
```

## 13.7 Order cancellation

```
OrderService::changeOrderStatus(cancelled)  [unchanged]
  event(OrderStatusChanged)  → B listener: prefs ok → ledger row (order_notifications, unchanged) →
                               status ∈ A-owned? → SKIP send (reason='owned_by_notification_channel')
  event(OrderCancelled)      → A listener → UserOrderCancelledNotification → DB+BR (unchanged)
                               → fcm via FcmChannel → gate → PushDispatcher ⇒ exactly ONE push
Result: one push, two ledgers, zero payload change, zero state-machine change.
```

## 13.8 Responsibility matrix

| Responsibility | Owner | Notes |
|---|---|---|
| Token registration/reassignment/unregister/deactivate/sweep | `DeviceRegistry` (single write path) | both APIs adapt to it |
| Push orchestration (recipients→tokens→provider→outcomes) | `PushDispatcher` | sole caller of FcmService |
| Payload construction | notification classes `toDatabase()` (A) / B title-body maps (unchanged) | unchanged |
| Recipient resolution | listeners (unchanged) | unchanged |
| Preference enforcement | `Preferences.gate` helper (FcmChannel + B listener) | one rule set |
| Queue dispatch | SendFcmNotificationJob (envelope, name kept) | tries/backoff unchanged |
| FCM provider calls | `FcmService` (per-client cache) | method surface unchanged |
| Error classification | `FcmErrorClassifier` | Kreait exception taxonomy |
| Delivery outcomes | `notification_deliveries` ledger | sole delivery truth |
| Coupon distribution state | Path C tables (unchanged) | ledger records only |
| User history | notifications table + existing APIs | unchanged |

---

# §14 Safe Migration and Rollback Plan

| # | Step | Preconditions | Exact expected behavior | Integrity checks | Failure condition | Rollback | Downtime | Mobile coordination |
|---|---|---|---|---|---|---|---|---|
| 1 | Schema preparation (additive migration: device_tokens columns + quarantine table; notification_deliveries) | M-2 inventory run in controlled env; backup taken | Tables/columns added; zero row changes; app unchanged (new columns nullable/defaulted) | `Schema::hasColumn` assertions; migration dry-run on scratch DB | migration failure ⇒ no partial deploy (single migration file, transactional DDL caveat MySQL: verify manually) | reverse migration drops added columns/tables (reversible, SAFE) | No | No |
| 2 | Data inventory (read-only script, controlled DB access) | Step 1 | Counts: rows/table, duplicate tokens across tables, same-token multi-user rows in Path B, metadata completeness | report artifact; no writes | DB access unavailable ⇒ STOP (UNKNOWN preserved, never assumed) | n/a | No | No |
| 3 | Conflict detection + quarantine | Step 2 | Every same-token multi-user row in `user_device_tokens` copied to `device_token_merge_conflicts`; **nothing deleted** | count(quarantined)+count(canonical) == count(source rows) | unresolvable ambiguity ⇒ park in quarantine, report | drop quarantine rows | No | No |
| 4 | Canonical data migration (merge `user_device_tokens` → `device_tokens`) | Steps 1-3; winners = max(last_used_at) | One row per token in canonical table (global unique holds); metadata carried; losers quarantined | token uniqueness satisfied; per-user active sets match expectation report | unique-key violation ⇒ abort batch, keep quarantine, no partial commit per chunk | restore = reverse merge script (from quarantine + source table) | No | No |
| 5 | Compatibility period (dual-write) | Step 4 | Both registration APIs write **canonical** table via `DeviceRegistry`; `user_device_tokens` becomes read-only legacy (no writes); Path B reads via registry (canonical) | API responses byte-identical (docs/api/device-tokens.md + B endpoints) | adapter bug ⇒ feature-flag to previous controllers (both retained one release) | flag off ⇒ previous behavior | No | No |
| 6 | Registration API transition | Step 5 stable | `registerDevice` (B) enforces global reassignment (D-003 fixed); `expires_at` honored; metadata saved | cross-account conflict test; contract test vs docs | mobile sends unexpected fields ⇒ validation unchanged (only semantics unify) | flag | No | Confirm app calls either endpoint (M-3) |
| 7 | Sending-path transition (unified dispatcher live) | Steps 5-6; DEC-I applied (7.x or fallback B) | All push goes through PushDispatcher + ledger; FcmChannel gate live (default transactional = no suppression); B listener suppresses A-owned statuses | ledger rows for every push; **no** `order_notifications.status='sent'` while simulated | dispatcher defect ⇒ feature flag `notifications.unified_push=false` reverts to legacy job/service paths (kept one release) | flag | No | No |
| 8 | DEC-B logout flag | Step 7 | Default OFF = current behavior; flip only on approval | — | — | flag | No | Confirm app unregister-on-logout (M-3) |
| 9 | Cutover (legacy path removal) | Step 7 green ≥1 full cycle + error dashboards clean | `PushNotificationService` + `UserDeviceToken` writes removed; `user_device_tokens` marked legacy | grep: zero send-path references; all 27 types still send (type matrix test) | any regression ⇒ re-enable flag (code kept one release) | re-deploy previous release | No | Only for eventual B-endpoint retirement |
| 10 | Verification | Step 9 | Test matrix (§16) green; production smoke: one sandbox push per client | ledger vs provider reports reconcile | — | — | No | No |
| 11 | Legacy-path removal (code) | Step 10 | Delete deprecated classes/flag code; keep `user_device_tokens` table (data) until retention decision | final grep audit | — | git revert | No | Table drop needs separate approval + mobile confirm |
| 12 | Rollback strategy (standing) | — | Feature flags (`notifications.unified_push`, `notifications.logout_deactivates_devices`) + additive-only migrations + quarantined data = reversible at every step | — | — | — | — | — |

**Never silently discarded:** conflicting tokens live in quarantine; invalid/stale tokens are deactivated (reason-coded), not deleted; `user_device_tokens` table is not dropped without separate approval.

---

# §15 File-by-File Implementation Plan

## Create

| File | Responsibility | Why | Dependencies | Tests | BC impact |
|---|---|---|---|---|---|
| `app/Services/Notifications/DeviceRegistry.php` | Single token write/read authority (register/reassign/unregister/deactivate/markInvalid/activeFor) | DEC-A canonical owner | DeviceToken model; controllers; dispatcher | unit+feature (ownership matrix) | none (new) |
| `app/Services/Notifications/PushDispatcher.php` | Authoritative push orchestration: claim→tokens→FcmService→classify→ledger | D-001/D-002/D-006/D-010 fixes in one place | DeviceRegistry, FcmService, FcmErrorClassifier, ledger model | unit (all error classes), integration | none (new) |
| `app/Services/Notifications/FcmErrorClassifier.php` | Kreait exception → category mapping (13.4 table) | one error policy | vendor exceptions | unit per class | none |
| `app/Models/NotificationDelivery.php` + migration | Delivery ledger (states, dedupe claim, retention) | DEC-F/DEC-G | PushDispatcher | unit+feature | additive table |
| Migration: `add_metadata_to_device_tokens` (+ `create_device_token_merge_conflicts`) | DEC-A additive schema | metadata preservation | DeviceRegistry | migration test on scratch DB | additive |
| `app/Console/Commands/SweepStaleDeviceTokens.php` | Deactivate stale tokens (config threshold) | D-024 | DeviceRegistry | feature | none |
| `app/Support/NotificationPreferences.php` (helper) | Shared gate logic (channel/event/quiet-hours/category) | DEC-D single rule set | UserNotificationPreference | unit | none |

## Modify

| File | Current | Proposed | Why | Tests | BC |
|---|---|---|---|---|---|
| `app/Services/Firebase/FcmService.php` | multicast + swallow (:34, :50-53) | keep surface; per-item mapping input to classifier; **no swallow** (rethrow whole-request after ledger) | D-001/D-002 | unit w/ fake Messaging | none external |
| `app/Jobs/SendFcmNotificationJob.php` | token resolution + prune inline | thin envelope → PushDispatcher | unification | existing FcmScopingTest must stay green | none |
| `app/Notifications/Channels/FcmChannel.php` | dispatch only | + preference gate + per-user locale | D-005/D-012 | feature: prefs on/off; locale | envelope unchanged |
| `app/Http/Controllers/Api/General/DeviceTokenController.php` | inline upsert (:27-35) | delegate to DeviceRegistry (identical semantics); platform set-if-provided (D-015) | DEC-A | FcmIntegrationTest green | contract identical |
| `app/Http/Controllers/Api/User/NotificationPreferencesController.php` | inline B upsert | delegate to DeviceRegistry (now global-unique) | D-003 | new conflict tests | envelope identical; **semantic change = approved design outcome** |
| `app/Models/DeviceToken.php` | fillable minimal | + metadata casts/reasons | DEC-A | model tests | additive |
| `app/Listeners/SendOrderPushNotification.php` | push via PushNotificationService | ledger row unchanged; send via PushDispatcher; suppress send for A-owned statuses (cancelled/delivered) w/ ledger reason | DEC-C/D-007 | OrderNotificationE2ETest + new single-push test | ledger preserved |
| `packages/marvel/src/Http/Controllers/UserController.php` | logout deletes Sanctum token only (:660-673) | + optional `DeviceRegistry::deactivateForLogout` behind config flag (default off) | DEC-B | feature flag tests | default = current behavior |
| blast listeners (`SendUserFlashSale*`, `SendUserPromotion*`, `SendUserCouponAvailable`) | viaQueue high | viaQueue medium + optional rate-cap | DEC-E | queue-name tests | timing only |
| marketing notification classes | no category | `notificationCategory(): 'optional'` (explicit opt-in list from DEC-D) | DEC-D | classification tests | behavior change ONLY after Category-B approval |
| `config/notifications.php` (new) | — | flags: unified_push, logout_deactivates_devices, sweep_days, blast rate-cap, ledger retention | flag-gated rollout | config tests | additive keys |

## Deprecate → Remove (only after cutover verification)

| File | Reason | Gate |
|---|---|---|
| `app/Services/Notifications/PushNotificationService.php` (incl. dead `registerDevice` :96-102, dead simulation branch :74-77) | sole caller migrated to PushDispatcher | Step 9 green |
| `app/Models/UserDeviceToken.php` | registry unified | Step 11 + mobile confirmation (M-3) |
| `user_device_tokens` table | legacy | separate approval + retention decision (rule 11) |
| Dead code: `FirebaseProjectResolver::projectId` → becomes used (project cross-check, D-021 fix) instead of removed; `OrderNotification::markAsDelivered` → left untouched this task (no unrelated cleanup) | — | — |

**Not touched:** all 27 notification payloads, OrderService, coupon pipeline internals, SMS/email/mail paths, marvel notification controllers, broadcast channels, existing migrations.

---

# §16 Comprehensive Acceptance Test Matrix

| # | Requirement (from design) | Test | Level | Mocks/fakes |
|---|---|---|---|---|
| T1 | New device registration (both APIs) → one canonical row | feature | DB | none needed |
| T2 | Same token repeatedly → same row updated | feature | DB | — |
| T3 | Same token, other account → reassignment (Path B now too) | feature | DB | — |
| T4 | Conflict rows quarantined during migration | migration test (scratch DB) | DB | — |
| T5 | Logout flag OFF = no change; ON = deactivate(reason=logout) | feature ×2 | DB | — |
| T6 | Re-login re-activates via registration | feature | DB | — |
| T7 | Invalid token → is_active=false + reason, row retained | unit w/ fake Messaging NotFound | — | Kreait exception fake |
| T8 | Stale sweeper deactivates by threshold | feature | DB | — |
| T9 | Every existing notification type still reaches FcmChannel | extended FcmIntegrationTest::test_all_notification_classes | feature | Queue::fake |
| T10 | All channels preserved: DB row + broadcast event + push per type | existing E2E suite green (unchanged) | feature | broadcast recorder |
| T11 | push_enabled=false → no push job; DB/broadcast unaffected | feature | — | — |
| T12 | event_preferences off for optional type → no push | feature | — | — |
| T13 | Optional category respects quiet hours (config) | unit+feature | — | frozen now() |
| T14 | Mandatory type ignores quiet hours | feature | — | — |
| T15 | Push locale = notification_language preference | unit | — | — |
| T16 | Invalid payload → failed(no retry), token untouched | unit | — | InvalidMessage fake |
| T17 | Auth error → failed + rethrow → failed_jobs row | feature | DB | AuthenticationError fake |
| T18 | Quota/transient → retry consumed, backoff schedule used | unit | — | fakes |
| T19 | Partial multicast: valid tokens delivered, invalid pruned, transient logged→retry subset | unit w/ MulticastSendReport fake | — | — |
| T20 | Whole-request exception → ledger failed + job retry engages (D-002 fixed) | feature | DB | fake Messaging |
| T21 | Exhausted retries → failed_jobs + admin alert payload sanitized | existing AsyncSafetyTest extended | feature | — |
| T22 | Idempotency: same key re-execution → no second provider call | unit+integration | DB | fake Messaging |
| T23 | Concurrent claim (two workers) → exactly one send | integration (unique-index arbitration) | DB | — |
| T24 | After-commit: rolled-back event ⇒ no claim, no job | existing AsyncSafetyTest semantics + new | feature | — |
| T25 | Cancel transition ⇒ exactly ONE user push (A-owned), B ledger row skipped/owner | feature | DB | Queue::fake |
| T26 | Deliver transition ⇒ same as T25 | feature | DB | — |
| T27 | Coupon pipeline unchanged: guards still gate; ledger records outcome only | existing NotificationPipelineTest green | feature | — |
| T28 | History APIs byte-identical (order ledger + preferences + marvel endpoints) | contract tests (response-shape snapshots) | feature | — |
| T29 | Simulated mode → ledger 'simulated', order_notifications ≠ 'sent' | unit+feature | — | — |
| T30 | No tokens/credentials in logs (PushNotificationService retired; dispatcher logs ids only) | unit asserting log context keys | — | Log::spy |
| T31 | Blast listeners on medium queue | queue-name test | — | — |
| T32 | device-tokens API contracts byte-identical (docs/api/device-tokens.md) | contract test vs doc envelope | feature | — |
| T33 | Migration reversibility (down() restores) | migration test | DB | — |
| T34 | DEC-I pre-rollout: sandbox live-fire on 7.x (endpoint + per-item classes) | manual/gated (never in CI) | external | — |

Every Critical/High design requirement maps to ≥1 test (T1-T9, T11, T16-T25, T27-T31).

---

# §17 Required Decision Table

| Decision ID | Recommended option | Alternatives | Key evidence | Benefits | Risks | External dependency | Category | Approval |
|---|---|---|---|---|---|---|---|---|
| **DEC-A** | Evolve `device_tokens` (Option A) | evolve B; new table | global-unique :15; documented contract; test-pinned reassignment; B's defect is the thing being fixed | smallest migration; metadata added; zero API change | merge over unknown data (M-2) | DB inventory (M-2) | A (engineering) | **Required** |
| **DEC-B** | Soft-deactivate on logout behind flag, default OFF | A always-on; B detach; C keep | logout code :660-673; D-004 | privacy switch without deploy | default-off = issue open until flip | mobile (M-3) + **product owner (default value)** | A mechanism / **B default** | **Required (default)** |
| **DEC-C** | A owns cancel/deliver push; B suppresses send, keeps ledger | B owns all; full rewrite | payload richness + test-pinned E2E; D-017 | zero payload change; duplicate removed | none identified | none | A | **Required** |
| **DEC-D** | choke-point gate + explicit category opt-in; transactional default | per-listener gates; config lists | FcmChannel single entry; existing prefs model | nothing silently suppressed | none until Category-B flips marketing types | **product owner (marketing optional list)** | A mechanism / **B list** | **Required (list)** |
| **DEC-E** | marketing blasts → medium + optional rate-cap | topics; mega-job; status quo | queue topology verified; WorkerConfigPolicyTest | protects transactional latency | none | DevOps metrics (M-6) | A (engineering) | Required |
| **DEC-F** | new `notification_deliveries` ledger; states fixed; no `delivered` | reuse order_notifications; notifications table | scope analysis §9 | honest observability; fixes D-008 | new table lifetime | — | A | Required |
| **DEC-G** | ledger-claim idempotency, keys from stable ids | client-only dedupe; no dedupe | §10 evidence per family | retry-safe | blast re-activation semantics (product) | mobile for payload notification_id (optional) | A | Required |
| **DEC-H** | preserve RabbitMQ; record doc discrepancy | migrate transport now | DEC-H §11 | Path C continuity | governance confusion persists | DevOps (M-7) | A + governance note | Required (preserve) |
| **DEC-I** | upgrade kreait `^7.15` | keep 6.7 + per-token loop | maintainer #911/#970; changelog 7.5.0; PHP ~8.1/8.2 | removes retired endpoint everywhere | major-version BC; live-fire gate | sandbox Firebase project (M-4) | A (engineering, dependency change) | **Required** |
| **DEC-J** | keep payment-push + completed-status push both (status quo) | suppress one | distinct event semantics (§6) | no silent change | perceived duplicates persist | **product owner** | **B** | Required |
| **DEC-K** | sweeper defaults (90d stale, deactivate-only) | delete after N days | rule 11 | bounded growth | none | — | A | Required (defaults) |

**If a decision is delayed:** DEC-A/DEC-F/DEC-G/DEC-C block implementation start; DEC-I has a fallback (Option B) but the batch-endpoint risk persists; DEC-B/DEC-D-list/DEC-J delays do **not** block — flags/defaults keep current behavior; DEC-E delay keeps current queue placement.

**Category C (external confirmations needed):** mobile endpoints/unregister behavior (M-3) · Firebase sandbox project (M-4) · DevOps worker/broker/metrics (M-5, M-6, M-7) · production env (M-1). **UNKNOWN carried:** production delivery reality (R-1), data overlap statistics (M-2).

---

# §18 Final Design Self-Review

| Question | Answer | Basis |
|---|---|---|
| Does every existing notification type have a destination? | **Yes** — all 22 trigger families map to preserved listeners/channels; push routes through the unified dispatcher; nothing removed before cutover gates | §13.1, §15 |
| Does any old path still independently send push? | During compatibility: Path B listener sends **via** the dispatcher (no independent provider call); after cutover: zero independent push paths (grep gate T/Step 9). `PushNotificationService` removed at Step 11 | §14 steps 7-11 |
| More than one token source of truth? | No — `device_tokens` canonical; Path B table read-only then legacy; ledger stores *outcomes*, not token state | DEC-A/DEC-F |
| Can one token belong to two active users? | No — global unique + reassignment enforced on both APIs (D-003 closed); merge quarantines conflicts | DEC-A |
| Can logout leave an unintended active association? | Default OFF preserves today's documented behavior (explicit, flagged, reversible); flag ON closes it. No *unintended* state — it is the approved-or-pending policy | DEC-B |
| Can a failed provider request be recorded as successful? | No — success requires a Kreait `SendReport` success item; simulated mode writes `simulated`, never `sent` (D-002/D-008 closed) | DEC-F states |
| Can a retry create duplicate notifications? | Ledger claim + `provider_accepted` short-circuit make re-execution a no-op send (D-010 closed); after-commit events prevent claim-orphaning | DEC-G |
| Can one order transition produce two pushes? | No — DEC-C ownership map; B suppresses send for A-owned statuses, ledger preserved (D-007 closed). Payment-vs-completed overlap deliberately left as DEC-J (business) | §6 |
| Can preferences be bypassed? | Path A gate at FcmChannel (single choke point) + B keeps its gate; both call one helper; admin recipients exempt by design (not user-scoped) (D-005 closed) | DEC-D |
| Can migration lose metadata or tokens? | No — additive columns carry all metadata; quarantine preserves conflicting rows; nothing deleted; reverse migrations defined | §14 |
| Can existing API clients break? | Contracts enumerated (§2.6) and preserved; the only semantic change (B-endpoint reassignment) is an approved design outcome aimed at closing a security defect | §2.6, §14 step 6 |
| Does the design claim delivery without evidence? | No — `delivered` state removed from the vocabulary; provider acceptance ≠ display, stated explicitly | DEC-F |
| Is any proposed component unnecessary? | Reviewed: DeviceRegistry, PushDispatcher, Classifier, ledger, sweeper, prefs helper, config flags — each maps to a verified defect or mandated consolidation; rejected alternatives (topics, mega-jobs, new broker, third table) documented | §8, §12 |

---

# FINAL GATE

**This is a design only. Nothing has been implemented. Approval of this document is not implied by its delivery.**

- **Technically recommended (engineering, Category A):** DEC-A Option A · DEC-C Option 2 · DEC-D mechanism · DEC-E medium+cap · DEC-F ledger · DEC-G ledger-claim idempotency · DEC-H preserve · DEC-I upgrade `^7.15` (fallback: per-token v1 on 6.7) · DEC-K deactivate-only sweeper.
- **Requires product-owner approval (Category B):** DEC-B default (logout privacy policy) · DEC-D marketing-optional list · DEC-J payment/completed push overlap · any future history-source change · `user_device_tokens` table retention/drop.
- **Requires mobile-team confirmation (Category C):** M-3 endpoints/unregister/refresh; optional payload `notification_id` contract (DEC-G).
- **Requires DevOps/production evidence (Category C):** M-1 env reality · M-2 data inventory (never invented) · M-4 sandbox live-fire · M-5 worker/broker topology · M-6 blast metrics · M-7 RabbitMQ status.
- **Remains UNKNOWN:** current production push delivery truth (R-1); token overlap statistics; mobile behavior; v1 error strings as surfaced by kreait 6.7.
- **Must be decided before implementation begins:** DEC-A, DEC-C, DEC-F, DEC-G, DEC-I (with fallback), DEC-H, DEC-K defaults — plus explicit approval of the file-by-file plan (§15) and the two product items you wish to settle now (DEC-B default, DEC-D list); everything else can proceed on flags/defaults.

**STOP — awaiting your explicit review and approval. No implementation will start until you approve the design and the decisions above.**
