# Notification System Unification — PHASE 2.1 Design Revision

**Mode:** Read-only source review + design amendment. No code, migrations, configuration, tests, Composer files, or database records modified. No real FCM sends. Original PHASE 2 design document preserved unchanged.
**Baseline re-verified before review:** HEAD `2f258c05f797cd8024d174da870755f08b08f6c0`, working tree contains only the three pre-existing audit documents (untracked). Re-verified after review: unchanged.
**Evidence sources:** repository source at pinned HEAD; installed vendor source (`vendor/kreait/firebase-php` 6.7.0, all cited files read directly); upstream maintainer docs (beste/firebase-php Discussions #911/#970, Issue #929, CHANGELOG 7.x, UPGRADE-7.0); Packagist + php.package.health dependency-resolution data for 7.15.0/7.24.1/8.6.0.

**Classification discipline:** every proposed design element is labeled Verified / Inferred / Unknown / Risk. **No design element is labeled Tested** (nothing was executed; DB remains unreachable).

---

# 1. Baseline and git-state verification

| Check | Before review | After review |
|---|---|---|
| HEAD | `2f258c05f797cd8024d174da870755f08b08f6c0` | same |
| Branch | `main` | `main` |
| Working tree | 3 untracked docs (audit, discovery, design) | identical — nothing added except this revision document |

# 2. Original design sections requiring correction

| # | Original section | Defect | Correction § |
|---|---|---|---|
| R-1 | §9 DEC-F / §10 DEC-G | Single `(dedupe_key, channel)` ledger row cannot represent per-device outcomes, per-client Firebase projects, partial multicast, leases | §6 |
| R-2 | §10 DEC-G `order:{orderId}:{status}` | Unstable when an order returns to a previous status; timestamp/hash identities unproven | §7 |
| R-3 | §4 DEC-A owner = max `last_used_at` | Unfail-safe conflict resolution | §5 |
| R-4 | §8 DEC-E "move listeners to medium" | Downstream notification/FCM/broadcast jobs still target `high` (verified) | §8 |
| R-5 | §5 DEC-B logout | Backend cannot identify the current physical device; deactivate-all rejected | §9 |
| R-6 | §13.4 error policy | AuthenticationError "no retry + rethrow + alert" contradictory | §10 |
| R-7 | §14 migration plan | "Dual-write vs read-only legacy" contradiction; rollback under-specified; zero-downtime unevidenced | §11 |
| R-8 | §14 step 6 / §2.6 | `user_device_tokens` DELETE-by-id compatibility ignored (ids are exposed to mobile) | §12 |
| R-9 | §7 DEC-D "Mandatory" | push_enabled=false suppresses "mandatory" — mislabeled; locale leakage hazard unaddressed | §13 |
| R-10 | §12 DEC-I `^7.15` | Not resolution-verified; 8.x incompatible; exact 7.x release needed | §14 |
| R-11 | §4 DEC-K 90-day sweep | `last_used_at` semantics mixed; inactivity ≠ invalidity; batch validate unusable on 6.7 (verified) | §15 |
| R-12 | §6 DEC-C ledger semantics | `order_notifications` enum/status verification for suppression + simulated incomplete | §16 |

# 3. New evidence gathered this phase (all Verified unless noted)

| # | Fact | Evidence |
|---|---|---|
| E-1 | `SendReport` exposes per-item `isSuccess()`, `error()`, `result()`, `messageWasSentToUnknownToken()`, `messageTargetWasInvalid()`, `messageWasInvalid()` | vendor SendReport.php:46-91 |
| E-2 | `MulticastSendReport` exposes `validTokens()`, `unknownTokens()`, `invalidTokens()`, `successes()`, `failures()` — per-token classification exists in SDK | vendor MulticastSendReport.php:98-170 |
| E-3 | `Messaging::validateRegistrationTokens()` in 6.7 is implemented as `sendMulticast(CloudMessage::new(), tokens, true)` → **same retired batch endpoint** — unusable pre-upgrade | vendor Messaging.php:105-116 + SendMessages.php:48 |
| E-4 | Single `validate()` = `send($message, true)` → per-message v1 endpoint — **healthy on 6.7** (dry-run token check possible per token) | vendor Messaging.php:100-103; SendMessage.php:25 |
| E-5 | Order timeline is synchronous and in-transaction: `RecordOrderStatusChangeInTimeline` has **no ShouldQueue** and writes `OrderTrackingEvent` inside `OrderService::changeOrderStatus`'s transaction (events dispatched inside the transaction closure, lines 1360-1412) | app/Listeners/RecordOrderStatusChangeInTimeline.php:9-47; OrderService.php:1388 |
| E-6 | `OrderStatusChanged` carries plain string props (`oldStatus/newStatus/changedBy/changedByType`) — plain strings survive queue serialization (unlike `SerializesModels` re-fetch) | app/Events/OrderStatusChanged.php |
| E-7 | `payment_webhook_events` table: durable per-provider event store, `UNIQUE(provider,event_key)`, `attempt_ref`, statuses received/processed/failed/dead — a **persisted payment event identity already exists** | migration 2026_11_10_000002 (whole file) |
| E-8 | PaymentSucceeded webhook emit site has `$eventId`/`$txnId`/`$gateway` in scope | PaymentWebhookController.php:~1690-1705 (context read) |
| E-9 | `RefundApproved` carries the persisted `Refund` model; emitted once in the canonical approval transaction after commit | app/Events/RefundApproved.php |
| E-10 | `AdminLoggedIn` is dispatched at `UserController::adminToken()` line 571 — **after** Sanctum token creation at :565 (a persisted token row exists at emit time) | packages/marvel/src/Http/Controllers/UserController.php:548-572 |
| E-11 | **Every** `SendUser*` blast listener runs on `QueueName::high()` (all 21 verified), all user notification classes `onQueue(config('queue.queues.high'))`, BroadcastMessage `onQueue(high)` for user classes, and `SendFcmNotificationJob` targets `config('frontend.queue') ?? high` globally | grep of viaQueue (39 matches) + onQueue grep + Job:27 |
| E-12 | `PromotionActivated`/`FlashSaleActivated`/`Product*` events implement **only** `Dispatchable, InteractsWithSockets, SerializesModels` — **no `ShouldDispatchAfterCommit`** ⇒ observer events enqueue mid-transaction (rollback can still fire a blast) | app/Events/PromotionActivated.php; FlashSaleActivated.php |
| E-13 | Logout endpoint has no device-identifying input; only `$request->user()` + `currentAccessToken()` (Sanctum session token ≠ device token; no correlation stored anywhere) | UserController.php:660-673 |
| E-14 | Path B `registerDevice` response returns the **full model incl. `id`**; `unregisterDevice` deletes by that `{deviceId}` — the id is part of the mobile contract | NotificationPreferencesController.php:58-74, :77-90; NotificationPreferencesTest.php:157-165 |
| E-15 | Path A job never updates `last_used_at` (registration-only); Path B updates on successful send (`touchLastUsed`) — mixed semantics | SendFcmNotificationJob (no update); PushNotificationService.php:39 |
| E-16 | `order_notifications.status` enum includes `pending|sent|delivered|failed|skipped`; model has `markAsSkipped` | migration 2026_09_12_000002:19; OrderNotification model |
| E-17 | `InteractsWithQueue::fail($e)` exists (Laravel 10) — allows failing a job **without consuming retries** while still triggering `failed()` | framework contract (Laravel 10 queue API) — Inferred (framework-standard, not executed) |
| E-18 | Kreait resolution data: 8.x (latest 8.6.0) requires PHP `~8.3||~8.4||~8.5||~8.6` → **incompatible**; 7.x latest **7.24.1** (2026-02-19) supports PHP 8.1/8.2 line, requires `guzzlehttp/guzzle ^7.5` (project has 7.8.0 ✓), bumps `kreait/firebase-tokens` ^3.0→^5.1, adds `google/cloud-storage ^1.30.1` transitively; 7.24.1 includes the firebase/php-jwt ^7.0.2 security remediation (CVE-2025-4659); `Factory::withHttpLogger/DebugLogger` deprecated in 7.24 (not used by project — grep) | php.package.health 7.15.0; Packagist listing; CHANGELOG 7.14/7.22/7.24.1 |
| E-19 | Locale leak hazard: Marvel queued mail job calls `App::setLocale()` (NewOrderReceived.php:51) — mutates worker-global locale for subsequent jobs on the same worker | packages/marvel/src/Notifications/NewOrderReceived.php:51 |

# 4. Revised DEC-A — Canonical device registry (supersedes original §4)

Option ranking unchanged (**A: evolve `device_tokens`**); the merge algorithm is replaced entirely.

## 5. Fail-safe conflicting-ownership migration algorithm (corrects R-3)

**Principle: a token with ambiguous ownership must never become pushable until a fresh authenticated registration establishes ownership.** `last_used_at` alone is not the decision-maker; it is only one ordering input.

Inputs: source rows from `device_tokens` (S1) and `user_device_tokens` (S2). Group all sources by `token` string.

Per token group, deterministic procedure:

1. **Missing-user rows** (`user_id` with no surviving `users` row): copy to quarantine (`device_token_merge_conflicts`: token, source, source_id, user_id, all metadata, reason=`orphaned_user`); **no canonical row**. (FK cascades make this rare; handled defensively.)
2. **Single-source, single-row, user exists** → canonical insert, `is_active=true`, metadata carried; source ids recorded in the legacy-ref map (§12).
3. **Multiple rows (any source mix) — ambiguity test**: order rows by ( `last_used_at` NULLS-LAST asc, then source priority S1>S2, then id asc ). A winner exists **only if** the maximum `last_used_at` value is non-null and strictly greater than every other non-null value (ties ⇒ no winner). Otherwise **no winner**.
4. **If a unique winner exists**: canonical row with winner's owner + metadata; `is_active=true`; all other group rows copied to quarantine (reason=`superseded_conflict`, referencing canonical id). *Residual:* a stale-but-max-timestamp row could theoretically win — mitigated by rule 6 below (the migration marks every merged row `merge_verified=false`; a config-gated post-migration validation pass can force re-authentication for specific tokens if ops requires; default = trust the winner, quarantine preserves full reversal).
5. **If no unique winner (including all-null timestamps and exact ties)**: canonical row is created **deactivated**: owner = first row in the deterministic ordering (cosmetic — see below), `is_active=false`, `deactivated_reason='migration_quarantine'`, `merge_verified=false`; **every** source row copied to quarantine (reason=`ambiguous_ownership`). **The token receives no pushes.**
6. **Tokens present in BOTH tables**: same procedure with combined sources; the S1 row (Path A) already holds global uniqueness — it participates as one candidate among the group.
7. **Reactivation path (only)**: fresh authenticated registration (either API) triggers the standard global-unique **reassignment**: the deactivated canonical row's owner is replaced by the registering user, `is_active=true`, reason cleared. Quarantine rows are never reactivated automatically.
8. Determinism guarantees: null `last_used_at` sorts as oldest (never wins against any non-null); equal timestamps never produce an active owner; deleted users never produce canonical rows; ordering is total (source priority then id) so repeated runs are idempotent.
9. Reconciliation: every canonical row stores `merged_from` (source, source_id list); quarantine stores full original rows → rollback re-imports (§11). **No source record is ever deleted.**

Classification: algorithm = Verified (deterministic by construction); production data shape = **Unknown** (M-2).

# 6. Revised delivery ledger — two-level model (corrects R-1)

Two tables, explicit relationship, per-device truth:

## 6.1 `notification_intents` — logical notification per (event, recipient, channel)

| Column | Type | Notes |
|---|---|---|
| id | bigint | |
| dedupe_key | char(64) | sha256(event_identity \| notification_type \| recipient_id) |
| channel | varchar(20) | 'fcm' initially |
| recipient_id | bigint | |
| notification_type | varchar(191) | class basename |
| event_identity | varchar(191) | §7 identity string |
| status | enum('queued','dispatched','completed','failed','skipped') | summary state |
| skip_reason | varchar(64) nullable | push_disabled/event_disabled/quiet_hours/owned_by_other_channel/no_devices |
| timestamps | | |

**UNIQUE(dedupe_key, channel)** = the idempotency claim (insert-or-ignore arbitration). Index (recipient_id, created_at); index (event_identity). Retention 90d (config).

## 6.2 `notification_device_attempts` — per-device truth

| Column | Type |
|---|---|
| id | bigint |
| intent_id | FK → notification_intents (cascade) |
| device_token_id | FK → device_tokens (nullable on token hard-delete; token string snapshot column retained) |
| client | varchar(32) |
| token_snapshot | varchar(512) |
| status | enum('pending','claimed','attempted','provider_accepted','failed','ambiguous','simulated') |
| provider_message_id | varchar(191) nullable |
| error_class | varchar(40) nullable |
| error_message | varchar(500) nullable |
| attempts | unsignedInt default 0 |
| claimed_by | varchar(64) nullable (worker id) |
| claimed_until | timestamp nullable (lease) |
| timestamps | |

**UNIQUE(intent_id, device_token_id)**; index `(status, claimed_until)` (lease sweep + retry selection); index `(device_token_id)`.

## 6.3 State transitions

```
intent: queued → dispatched (job running) → completed | failed | skipped(skip_reason)
attempt: pending → claimed(lease) → attempted → provider_accepted | failed(class) | ambiguous
         claimed(lease expired) → pending (lease reclaim)
         transient failure → pending (attempts++, backoff at job layer)
         permanent failure → failed (terminal)
         simulated mode → simulated (terminal, provider disabled)
```

## 6.4 Per-device independence (demonstration)

Devices A (accepted) and B (transient 503) under one intent: A's attempt row = `provider_accepted` (terminal success). B's row cycles `pending→claimed→attempted→pending` with `attempts++`. The retry selection query is `WHERE intent_id=? AND status IN ('pending') OR (status='ambiguous' AND claimed_until<now())` — A's row can never match ⇒ **a successful device cannot suppress retries for a failed device, and cannot be re-sent by them**. Claim is per-attempt-row, so concurrent workers serialize per device, not per intent.

## 6.5 Atomic claim/lease (prevents concurrent duplicate sends)

Claim = single-statement compare-and-set:
`UPDATE notification_device_attempts SET status='claimed', claimed_by=:worker, claimed_until=:now+lease WHERE id=:id AND (status='pending' OR (status='claimed' AND claimed_until < :now))` — affected-rows==1 ⇒ lease won. Lease duration > job `retry_after` (config; default 15 min). Re-claim after lease expiry handles worker death (a worker that died post-send but pre-update produces the **ambiguous** case below). Claim write and provider send are NOT one transaction (network call cannot span transactions) — the lease is the safety mechanism, not atomicity fiction.

## 6.6 Honest delivery semantics (no exactly-once claim)

FCM v1 has **no idempotency key**; a request whose response is lost (timeout/connection reset after server processing) cannot be distinguished from a non-delivered request. Therefore:
- Ambiguous outcome (`ApiConnectionFailed`, read-timeout after write): attempt row → `ambiguous`.
- **Policy:** ambiguous rows are **not** auto-retried within the job's retry cycle. A separate cool-down sweep (config; default 10 min) re-claims `ambiguous` rows once and retries **once**, deliberately accepting at-least-once duplication on that single device. With client-side dedupe (mobile dependency, §12/§17) the visible effect is nil; without it, a duplicate display is possible in the rare lost-response case. This is stated as **Risk** and is the honest floor: **at-least-once, single-ambiguity-retry**.
- The system therefore guarantees: no duplicate from *retryable* paths (deterministic outcomes); at-most-one extra send for *ambiguous* outcomes; never a false success record.

# 7. Revised event identity (corrects R-2)

Per-family identity, using existing persisted identifiers wherever they exist (rule: prefer existing stable ids; mint-and-persist only where none exists):

| Family | event_identity | Stability proof | Status |
|---|---|---|---|
| **Order transitions** (status change, cancel, deliver — incl. return-to-previous-status) | `transition:{transitionId}` — UUID minted **once per transition at the single authority** (OrderService) and **persisted into `OrderTrackingEvent.metadata` in the same DB transaction** (E-5: timeline listener is synchronous + in-transaction). The UUID is placed as a plain string property on `OrderStatusChanged`/`OrderCancelled`/`OrderDelivered` — plain strings survive queue serialization (E-6), so every retry re-uses the identical value | persisted + payload-frozen; return-to-previous-status creates a new tracking row ⇒ new identity | Verified (mechanism) |
| **Order-cancelled + status-cancelled duplicate suppression** | Both A (`OrderCancelled`) and B (`OrderStatusChanged`) pushes carry the **same** `transitionId` ⇒ ledger unique claim suppresses the second push automatically (belt-and-braces: the DEC-C ownership skip remains) | same persisted id | Verified (mechanism) |
| **Payment (gateway webhooks)** | `webhook:{provider}:{event_key}` — reusing the **existing** `payment_webhook_events.event_key` (UNIQUE(provider,event_key), E-7); event extended with a plain-string `paymentEventId` at the emit site where `$eventId` is already in scope (E-8) | persisted unique key | Verified |
| **Payment (COD/cashier completion)** | Shares the order `transitionId` (PaymentSucceeded emitted from OrderService:1403-1405 inside the same transition) | same persisted id | Verified (mechanism) |
| **Refund** | `refund:{refund->getKey()}` — event carries the persisted `Refund` model; emitted once per canonical approval (E-9). `Refund` re-fetch via SerializesModels returns the same row id | persisted PK; retries re-fetch same row | Verified |
| **Coupon distribution** | `coupon-elig:{couponId}:{treeHash}:{userId}` — existing durable guards + run/recipient PKs | existing persisted ids | Verified |
| **Cart reminder** | Claim-based: the atomic `reminder_sent_at` flip **is** the once-marker; identity `cart:{cartId}:reminder` | persisted claim column | Verified |
| **Promotion / FlashSale activation & Product observer events** | Event classes gain a plain-string `eventId` (UUID) **minted at the observer (synchronous, inside the save transaction)** and carried on the event — frozen in the queued payload ⇒ identical across retries; uniqueness by construction. Persistence: the value is recorded in `notification_intents.event_identity` at first claim. Explicitly **no timestamps and no content hashes** are used as identity. Residual: because these events lack `ShouldDispatchAfterCommit` (E-12), a mid-transaction dispatch can enqueue a blast for a state that later rolls back — flagged Risk with a proposed (approval-requiring) remedy: add `ShouldDispatchAfterCommit` to these events | payload-frozen uuid | Verified (mechanism) / Risk (dispatch timing, pre-existing) |
| **Admin login** | `login:{sanctum_token_id}` — token row is created at UserController:565 before dispatch at :571 (E-10); event extended with `token_id` (plain int) | persisted PK | Verified (mechanism) |
| **Contact message** | `contact:{contact_id}` (persisted contact row carried by the event) | persisted PK | Verified |
| **Queue-failure alert** | `jobfailed:{job uuid}` (database-uuids driver assigns the uuid to the failed job record) | persisted uuid | Verified (mechanism) |
| **Review / digital-delivery events** | `review:{reviewId}:{action}` / `delivery:{entitlementId}:{eventClass}` (persisted subject PKs carried by events) | persisted PKs | Verified (mechanism) |

**Stability rule enforced:** identity values that are *derived* (sha256 of content, `now()` timestamps) are **never** used as event identity; they may only appear as *claim* timestamps inside the ledger.

# 8. Revised DEC-E — Full queue topology (corrects R-4)

## 8.1 Verified current matrix (E-11)

| Layer | Current physical queue for marketing types |
|---|---|
| Blast listener (`CallQueuedListener`) | `high` (all SendUser* viaQueue = high) |
| `SendQueuedNotifications` (per user; contains the **database write**) | `high` (notification `onQueue(config('queue.queues.high'))`) |
| Broadcast event job (`NotificationSent`) | `high` (BroadcastMessage onQueue high) |
| `SendFcmNotificationJob` | `config('frontend.queue') ?? high` — **global, source-agnostic** |

Conclusion verified: moving only the listener is **insufficient** — three downstream layers still land on `high`.

## 8.2 Revised topology

For every marketing-category notification (list per DEC-D), **all four layers** move to `QueueName::medium()`:
1. Listener `viaQueue()` → medium.
2. Notification `onQueue()` → medium (marketing classes only; transactional classes untouched).
3. `BroadcastMessage->onQueue()` → medium (same classes).
4. `SendFcmNotificationJob` gains an explicit queue property; `FcmChannel` sets it from the notification's category (`medium` for optional/marketing, `high` default). The global `frontend.queue` fallback remains the default for transactional.

Wishlist fan-outs (price drop / back in stock / discount changed / ending soon) and coupon-available are marketing ⇒ medium. Abandoned-cart is classified with the pending DEC-D list (proposed marketing ⇒ medium if approved). Admin/notification-ops listeners stay as-is (high / medium per current).

## 8.3 Isolation proof requirement (tests)

New queue-topology tests assert, per marketing type, `Queue::assertPushedOn('…medium…', SendQueuedNotifications)`, broadcast job on medium, `SendFcmNotificationJob` on medium; and per transactional type all layers on high. Physical names resolved via `QueueName::medium()/high()` (env-driven; same mechanism as WorkerConfigPolicyTest).

# 9. Revised DEC-B — Logout device identity (corrects R-5)

**Proof (E-13):** `UserController::logout` receives no device identifier — the request carries only the Authorization header; the handler touches `$request->user()` and `currentAccessToken()` only. No table correlates Sanctum token ↔ device token. Therefore **the backend cannot identify the physical device performing the logout without a mobile API change** (e.g., client sending the FCM token or device_id in the logout body — a mobile contract change).

**Revised policy:**
1. Default stays **OFF** (current behavior: logout touches nothing) — unchanged until the device-identification contract exists and is approved.
2. **Deactivate-all-devices is explicitly rejected** — it would sever push for the user's other legitimate devices.
3. Engineering pre-work (no behavior change): logout handler gains an optional, ignored-if-absent body field `device_token` behind the same flag; when present and the flag is on, only the matching row(s) deactivate (reason=`logout`). This is a **backward-compatible request-body addition** (unknown fields currently ignored by the handler — no validation break), but the behavior contract requires **product-owner + mobile approval** (Category B/C).
4. Account switching remains solved by canonical reassignment (DEC-A) — no logout dependency.

# 10. Revised retry and error classification (corrects R-6)

Single coherent policy; `InteractsWithQueue::fail($e)` (E-17, Inferred-framework-standard) resolves the contradiction: permanent whole-request failures reach final failure **without consuming retries** and still trigger `failed()`/`JobFailed` alerting.

| Error class (Kreait taxonomy, E-1/E-2) | Scope | Action | Ledger state | Retry? | Alerts |
|---|---|---|---|---|---|
| `NotFound` / report `messageWasSentToUnknownToken()` / `unknownTokens()` | per device | `DeviceRegistry.markInvalid(reason='invalid_token')` (deactivate, retain row) | `failed(invalid_token)` | No | count log |
| `InvalidArgument` / `InvalidMessage` / report `messageTargetWasInvalid()`/`messageWasInvalid()` / `invalidTokens()` | per device / whole message | mark failed | `failed(invalid_payload)` | No (payload bug — fix code, not retry) | error log |
| `AuthenticationError` / credentials file missing / auth config | whole request | mark **all** attempt rows failed(auth_config) → `$this->fail($e)` → `failed()` → failed_jobs → existing `HandleFailedQueueJob` admin alert | `failed(auth_config)` | **No — zero retries consumed** | Yes (existing alert path) |
| `QuotaExceeded` | per request | rethrow (transient) | attempts remain pending | Yes (tries/backoff) | throttle log |
| `ServerError` / `ServerUnavailable` / `ApiConnectionFailed` (pre-send failure) | whole request | rethrow | attempts stay `pending` | Yes | log |
| `ApiConnectionFailed` / timeout **after** send uncertain | per device | ambiguous handling (§6.6) | `ambiguous` | once, after cool-down (at-least-once) | count log |
| Unknown `MessagingError` | per item | mark failed | `failed(permanent_unknown)` | No | warning log |
| Whole-request unexpected exception | — | rethrow after marking pending rows intact | — | Yes (existing tries/backoff) | via final failure |

Partial multicast: per-item processing drives per-attempt-row states; the job **completes normally** when only per-item terminal states occurred (success mixed with permanent failures) — no whole-job retry; transient per-item failures leave rows `pending` and the job **rethrows** so the scheduled retry re-enters the lease loop and touches only `pending` rows.

# 11. Revised migration and rollback (corrects R-7)

Terminology fix: original Step 5 said "dual-write" while making the legacy table read-only — contradictory. Revised: **Step 5 = "canonical-writes-only compatibility period"** (both APIs write canonical; legacy tables receive **no** writes).

| Aspect | Revised design |
|---|---|
| Application rollback | Previous release expects legacy tables. Because the legacy table went read-only during compatibility, rollback requires the **reverse-sync step below** — a feature flag alone does NOT restore stale legacy data. Both flag-off path (stay on new code, legacy adapters re-activated) and true app-version rollback are supported; both run reconciliation first |
| Data rollback/reconciliation | `reverse_sync` script: for every canonical row missing from `user_device_tokens`, insert a legacy row (id from `device_token_legacy_refs`, owner, metadata, `is_active` mirrored); for deactivated/quarantined canonical rows, insert legacy rows with `is_active=false`. Never deletes legacy rows. Run **before** serving traffic with rolled-back code |
| Schema rollback | **Additive objects are never auto-dropped.** Added columns/table are left in place on rollback (harmless, preserves audit + quarantine). Dropping requires a separate approved step after a retention decision (rule 11) |
| Quarantine restoration | `device_token_merge_conflicts` rows are source-of-truth copies; restoration re-imports them into the legacy table exactly as found (including old ids via the legacy-ref map) |
| Old mobile clients / old device ids | Both registration APIs keep envelopes + id semantics via the legacy-ref map (§12); old clients continue to work unchanged through both the migration and any rollback |
| MySQL DDL partial failure | Each migration = **single DDL statement** where possible (column adds are separate migrations); a failed multi-statement migration leaves partial schema — deployment gate: post-migration `Schema::hasColumn/hasTable` assertions before traffic. Instant-DDL availability for column adds is **Unknown** pending production MySQL version + table-size review (M-1/M-2) |
| Downtime | **Not claimed.** Additive column add on 8.0 is typically INSTANT, but this is **Unknown until production schema, engine version, and row counts are known** (M-1/M-2). Rollout plan carries an explicit downtime-assessment step; index creation (`is_active`) is INPLACE but time/volume-dependent |
| Cutover verification | unchanged from original §14 steps 9-11, plus §12 id-compat tests and §8 queue tests as gates |

# 12. Revised API backward compatibility — id continuity (corrects R-8)

**Verified contract (E-14):** `POST /api/v1/user/devices/register` returns the full `UserDeviceToken` model (including `id`); `DELETE /api/v1/user/devices/{deviceId}` deletes by that id, owner-scoped, 404 otherwise. Mobile clients can be expected to persist the returned id.

**Revised design:**
1. New mapping table `device_token_legacy_refs`: `legacy_user_device_token_id` (PK), `device_token_id` (FK canonical), `user_id`, created during migration for **every** migrated source row (both tables; Path A rows have no legacy refs — their own canonical id applies).
2. Post-migration `unregisterDevice`: resolve `{deviceId}` as (a) canonical `device_tokens.id` **owner-scoped**, else (b) `device_token_legacy_refs` → canonical id (**still owner-scoped** — mapping carries user_id), else 404. Old ids keep working for migrated devices.
3. Post-migration `registerDevice` response: returns the canonical row serialized to preserve the documented field set (`id`, `token`, `platform`, `device_name`, `device_id`, `app_version`, `os_version`, `is_active`, timestamps). Field set verified against the DEC-A evolved schema — superset-compatible; **no fields removed**.
4. Path A DELETE (`/general/device-tokens` by token) is token-string-based — unaffected by id continuity.
5. Tests added: delete by old id (migrated) ✓ owner; delete by old id **foreign owner** → 404; delete by new id ✓; unknown id → 404; reassignment then old-id delete → 404 (previous owner lost it) — asserting today's documented ownership rules continue.

# 13. Revised DEC-D — Preference semantics and locale isolation (corrects R-9)

**Tier definitions (explicit):**
- **Tier 0 — Channel master:** `push_enabled=false` suppresses **all** push for the user (including Tier 1). This is the *existing* semantic of the preference model (`isChannelEnabled('push')`) and is the approved baseline.
- **Tier 1 — Transactional:** sent unless Tier 0. Order/payment/refund/digital/coupon-entitlement/status pushes.
- **Tier 2 — Optional/marketing:** sent only if Tier 0 passes **and** per-event preference passes (and quiet hours per config). Marketing list remains **pending Product-Owner approval** (proposals unchanged from original §7 table; nothing is suppressed until approval).
- **Tier 3 — Unsuppressible (proposed, requires explicit Product-Owner approval):** would ignore `push_enabled` (e.g., security alerts). **No existing type is assigned Tier 3 by default**; the label "Mandatory" is **dropped** from the design vocabulary and replaced by the tiers above, precisely because Tier 1 is suppressible by the channel master unless the PO approves Tier 3 semantics.
- Admin-recipient notifications are not user-preference-scoped (recipient class exemption, unchanged).

**Identical rules on both paths:** `FcmChannel` (Path A) and `SendOrderPushNotification` (Path B) call the same `NotificationPreferences` helper; the helper returns allow/skip(+reason); both write ledger `skipped` rows with the same reason codes.

**Locale isolation (verified hazard E-19):** worker locale is process-global; Marvel's queued mail job calls `App::setLocale()` (NewOrderReceived.php:51) and can poison subsequent jobs on the same worker. Revised push design **never reads `App::getLocale()`** for payload resolution: `FcmChannel` resolves the display locale from the recipient's `notification_language` preference (fallback: the app's configured default locale constant, read once per dispatch) and **never mutates global locale**. The Marvel mail leak is flagged as a separate verified hazard (out of this task's code scope; fix = capture+restore locale in that mail job — recommendation only).

# 14. Revised DEC-I — Exact SDK recommendation (corrects R-10)

**Composer-resolution evidence (E-18):**
- Installed: `kreait/firebase-php 6.7.0`; guzzle 7.8.0; PHP 8.2.30; Laravel 10.30.1 (SDK is framework-agnostic).
- **8.x (8.6.0) requires PHP ~8.3+ → NOT installable. Excluded.**
- **7.x line: latest 7.24.1 (2026-02-19).** 7.0.0 baseline PHP 8.1/8.2; 7.9 added 8.3, 7.14 added 8.4, 7.22 added 8.5 — the 7.x line **remains PHP-8.2 compatible**. Requires `guzzlehttp/guzzle ^7.5` (7.8.0 ✓), bumps `kreait/firebase-tokens` ^3.0→^5.1 and adds `google/cloud-storage ^1.30.1`, `google/auth ^1.24`, `firebase/php-jwt ^6.3.2||^7` transitively (composer resolves; no conflicts with Laravel 10 known — SDK independent of illuminate).
- 7.24.1 includes the `firebase/php-jwt ^7.0.2` security remediation (CVE-2025-4659 / PKSA-y2cr-5h3j-g3ys) — an additional reason to prefer the latest 7.x.
- 7.5.0 replaced batch endpoints with **asynchronous per-message HTTP v1** requests and removed the 500-message limit (maintainer CHANGELOG); `sendMulticast()` still exists (forwards to `sendAll()`).
- Project call-surface audit (grep-verified: only Factory::withServiceAccount/createMessaging, Messaging::send/sendMulticast, Notification, CloudMessage used): all present in 7.x; parameter-typing BC noted by UPGRADE-7.0 as internal-facing ("should not affect your project in most cases"). `Factory::withHttpLogger/DebugLogger` deprecated in 7.24 — **not used** by this project.

**Recommendation (revised):** `"kreait/firebase-php": "^7.24.1"` — exact latest 7.x (7.24.1 at design time). **Fallback (unchanged, remains valid):** remain on 6.7.0 and replace `sendMulticast` with a per-token `send()` loop (v1 endpoint verified healthy in 6.7, vendor SendMessage.php:25) — no dependency change, with the documented latency/quota trade-off and 6.x EOL exposure. Per-token dry-run `validate()` is additionally **healthy on 6.7** (E-4) while `validateRegistrationTokens()` is batch-bound (E-3) and only becomes usable post-upgrade — this asymmetry is part of the DEC-K design.

**Required verification before rollout (unchanged gates):** full test suite on 7.x; sandbox live-fire (endpoint + per-item report classes + `unknownTokens()/invalidTokens()` semantics); vendor-diff of the `Messaging` contract; composer `--dry-run` resolution evidence recorded. No Composer files touched in this phase.

# 15. Revised DEC-K — Stale-token policy (corrects R-11)

**`last_used_at` semantics (verified, E-15):** Path A updates it **only at registration** (store) — sends never touch it; Path B updates it on **successful send** (`touchLastUsed`). It is therefore a mixed signal: "recently registered" (A) or "recently delivered-to" (B). **It is not a validity signal.**

**Inactivity ≠ invalidity:** a device dormant for 90 days may hold a perfectly valid FCM token (FCM does not expire tokens for inactivity; only the client/app state invalidates them). Deactivating on inactivity silently suppresses valid devices — rejected without explicit approval.

**Revised policy:**
1. Sweeper default **DISABLED** (config off). No automatic deactivation based on inactivity.
2. When enabled, sweeper runs in **report-only mode first** (inventory of dormant rows; no mutations) — ops reviews before any enforcement.
3. Deactivation requires **provider-confirmed invalidity**: post-upgrade, batch `validateRegistrationTokens()` (async v1, healthy on 7.x) over dormant rows → only `unknown` results deactivate (reason=`provider_invalid`); pre-upgrade, per-token `validate()` (E-4, healthy on 6.7) at controlled rates. Provider `valid` results **never** deactivate.
4. Real-time invalidity (send-time `unknownTokens()`) continues to deactivate immediately (DEC §10 row 1).
5. Inactivity *threshold* becomes a **reporting** dimension, not an enforcement one, until the Product Owner explicitly approves an enforcement policy (Category B).

# 16. Revised order-ledger semantics (corrects R-12)

**Verified (E-16):** `order_notifications.status` enum = `pending|sent|delivered|failed|skipped`; model `markAsSkipped()` exists; the user history API (`/user/notifications/history`) and tests read these rows.

**Path B suppression of duplicate push (DEC-C Option 2, retained):** when the transition is A-owned, the B listener still creates its `order_notifications` row with `status='skipped'` and `failure_reason='push_owned_by_notification_channel'` — **history completeness preserved**, no second push. (The original design's "no ledger row" variant is dropped.)

**Simulated mode (default `services.fcm.enabled=false`):** the unified dispatcher never calls `markAsSent` for a simulated send. The `order_notifications` row gets `status='skipped'`, `failure_reason='simulated_provider_disabled'` (the enum has no `simulated` value — adding one is a schema change requiring approval; the authoritative `simulated` state lives in `notification_device_attempts`), and the delivery ledger records `simulated` per device. **A false `sent` is impossible by construction** — `sent` is written only from a real `provider_accepted` outcome (or real per-device successes aggregated by Path B's legacy flow, which is removed at cutover).

`delivered` remains unused (no delivery-evidence source) — unchanged from the original design.

---

# 17. Revised architecture diagram

```
Entry points (22 families — unchanged)
   │
   ├─ Laravel notification path (DB+FCM+BR channels preserved)
   │    └ FcmChannel: payload (unchanged) → tier gate (§13) → locale from preference (no global read)
   │            └ SendFcmNotificationJob [queue = f(notification category)]
   │                 └ PushDispatcher
   │                      ├ event identity (§7) → notification_intents claim (UNIQUE dedupe_key,channel)
   │                      ├ DeviceRegistry.activeTokensForUser (canonical)
   │                      ├ per attempt row: lease claim → FcmService.sendToClient (per-client multicast)
   │                      ├ FcmErrorClassifier → per-device states (§10)
   │                      └ ledger outcomes + token transitions
   ├─ Path B order-status push (prefs kept) → same dispatcher; A-owned transitions ⇒ ledger row skipped
   ├─ Path C coupon pipeline (RabbitMQ preserved) → notify → same dispatcher
   └─ Path D SMS/Email/mail (unchanged)

Preserved: database channel · broadcast/Pusher · mail (incl. Marvel) · SMS · all API envelopes ·
order_notifications (legacy ledger) · RabbitMQ transport · business state transitions (OrderService untouched
except minting the transitionId it already persists via the timeline)
```

Sequence diagrams for the standard push / invalid token / retry / logout-switch are structurally as in the original §13, amended by: per-device lease claims, the intent+attempt two-level ledger, ambiguous-once-retry, and the queue-selection property on the FCM job.

---

# 18. Revised test matrix additions (extends original §16)

| # | New requirement | Test |
|---|---|---|
| T-A1 | Per-device independence: accepted device never re-sent while another device retries | unit w/ fake report {A accepted, B 503} → second handle() sends only B |
| T-A2 | Lease: two concurrent workers claim same attempt row → exactly one wins | integration, two handles + lease assertions |
| T-A3 | Lease expiry reclaim after worker death (no send recorded) → row re-claimed | integration |
| T-A4 | Ambiguous outcome → no in-job retry; cool-down sweep retries exactly once | unit + scheduled-command test |
| T-A5 | Identity stability: transition returns to previous status ⇒ distinct tracking row ⇒ distinct identity ⇒ second push delivered | feature |
| T-A6 | Same-transition A+B events share transitionId ⇒ single push | feature (cancels) |
| T-A7 | Webhook payment identity uses persisted `payment_webhook_events.event_key`; replay of same event ⇒ no duplicate payment push | feature |
| T-A8 | Queue isolation per layer for marketing types (listener/notification/broadcast/FCM all medium) | queue-assertion tests (§8.3) |
| T-A9 | Transactional types: all four layers remain high | same |
| T-A10 | Id continuity: old legacy-id DELETE for migrated device works; foreign owner 404; reassigned token old-id 404 | feature |
| T-A11 | Migration: ambiguous conflict group → canonical deactivated + quarantined; no push until re-registration | migration test (scratch DB) |
| T-A12 | Migration: null/tied timestamps deterministic ordering | migration test |
| T-A13 | Rollback reconciliation restores legacy rows incl. deactivated/quarantined; no deletions | scripted test on scratch DB |
| T-A14 | Simulated mode ⇒ order_notifications ≠ 'sent' (skipped+reason) and ledger = simulated | feature |
| T-A15 | Locale isolation: worker locale mutated by another job does not affect push locale resolution | feature (simulate prior setLocale) |
| T-A16 | Tier semantics: push_enabled=false suppresses Tier 1; Tier 2 also requires event pref; nothing Tier 3 | feature matrix |
| T-A17 | auth_config failure → fail() without retry consumption; failed_jobs + admin alert | feature |
| T-A18 | Sweeper disabled by default; report-only when enabled; only provider-unknown deactivates | feature |
| T-A19 | validate() dry-run on 6.7 hits v1 endpoint (no batch); post-upgrade batch validate healthy | vendor-level unit (gated sandbox) |
| T-A20 | Event without ShouldDispatchAfterCommit rollback behavior (promo blast) — documented pre-existing risk; regression guard if approved to change | feature |

# 19. Unresolved product decisions (Category B — unchanged/pending)

DEC-B logout default (now requires the device-identification contract first) · DEC-D marketing-optional list · DEC-J payment/completed overlap · Tier-3 unsuppressible semantics · sweeper enforcement policy (§15) · `user_device_tokens`/`order_notifications`-schema retention decisions · adding `simulated` enum value to `order_notifications` (rejected-by-default; ledger suffices).

# 20. Unresolved mobile/DevOps dependencies (Category C)

Mobile: which registration endpoints are live (M-3); unregister-on-logout behavior; token-refresh cadence; logout-body device-token contract (new, §9); optional payload `notification_id` for client-side dedupe; `client` correctness. DevOps: production env (M-1); DB inventory incl. table sizes for DDL downtime assessment (M-2); RabbitMQ/broker status (M-7); queue metrics (M-6); MySQL version for INSTANT-DDL evidence. Firebase sandbox project (M-4) for live-fire.

# 21. Explicit risks and unknowns

| # | Item | Class |
|---|---|---|
| X-1 | Batch endpoint currently failing in production (Path A) | Risk (authoritative external evidence; runtime unproven locally) |
| X-2 | Promo/flash/product events lack `ShouldDispatchAfterCommit` (E-12) — mid-transaction dispatch can enqueue a blast for rolled-back state | Risk (verified code; pre-existing) |
| X-3 | Ambiguous lost-response sends ⇒ at-most-one duplicate display without client dedupe | Risk (inherent to FCM) |
| X-4 | Marvel mail `App::setLocale` worker-poisoning (E-19) affects **any** locale-sensitive code running after it on the same worker — broader than push | Risk (verified) |
| X-5 | DDL downtime characteristics in production | Unknown (M-1/M-2) |
| X-6 | Production data overlap/conflict volumes | Unknown (M-2) |
| X-7 | kreait 7.x type-strictness interactions beyond the audited call surface | Risk (Low; call surface grep-verified minimal) |
| X-8 | `InteractsWithQueue::fail($e)` behavior details under the database queue driver | Inferred (framework-standard; not executed here) |

# 22. Final self-review (correction-focused)

| Question | Resolution |
|---|---|
| Single ledger vs per-device outcomes? | Two-level model (§6); intent unique claim + per-device attempt rows with leases |
| Can a success suppress another device's retry? | No — per-attempt-row states; retry selects only pending/ambiguous-expired (§6.4) |
| Exactly-once claimed? | **No** — at-least-once with single ambiguity retry, stated (§6.6) |
| Order identity stable across status returns? | Yes — persisted `transitionId` per transition row (§7) |
| Non-order identities stable across retries? | Yes — persisted PKs or payload-frozen properties; timestamps/hashes excluded (§7) |
| Conflict migration fail-safe? | Quarantine-all on ambiguity; deactivated canonical rows; reactivation only by fresh registration (§5) |
| Queue isolation proven per layer? | Full 4-layer matrix verified + per-layer tests required (§8) |
| Logout device identification? | Proven impossible without mobile contract; deactivate-all rejected; default unchanged (§9) |
| Permanent vs retry reconciliation? | `fail($e)` path for auth/config; per-item taxonomy table (§10) |
| Rollback honest? | Reverse-sync mandatory; additive schema never auto-dropped; no zero-downtime claim (§11) |
| Id compatibility? | Legacy-ref map + owner-scoped resolution + tests (§12) |
| "Mandatory" mislabel fixed? | Tier vocabulary; Tier 3 requires PO approval; marketing pending (§13) |
| Locale isolation? | Preference-driven, never global read; verified leak hazard flagged (§13, E-19) |
| Exact SDK version? | `^7.24.1` (resolution-verified); 8.x excluded; fallback defined (§14) |
| Sweeper honest? | Disabled by default; report-only; provider-confirmed invalidity only (§15) |
| Simulated never 'sent'? | By construction; enum gap documented; ledger carries truth (§16) |
| Original design doc preserved? | Yes — untouched; this revision supersedes the corrected sections |

---

# FINAL GATE — Decision table

| Decision | Revised recommendation | Status |
|---|---|---|
| DEC-A canonical registry + fail-safe merge | Evolve `device_tokens`; quarantine-ambiguous algorithm (§5) | Engineering recommendation — **approval required** |
| DEC-B logout | Default OFF; current-device logout requires new mobile contract; deactivate-all rejected (§9) | Mechanism: engineering; default + contract: **Product + Mobile** |
| DEC-C order ownership | A owns cancel/delivered push; B suppresses send, writes ledger row `skipped` (§16) | Engineering recommendation — approval required |
| DEC-D preferences | Tier vocabulary (0/1/2; Tier 3 only by PO approval); single helper both paths; preference-driven locale (§13) | Mechanism: engineering; marketing list + Tier 3: **Product Owner** |
| DEC-E queue isolation | Marketing types move **all four layers** to medium; per-layer tests (§8) | Engineering recommendation — approval required |
| DEC-F/G ledger + idempotency | Two-level ledger (intent + device attempts), leases, per-device truth, at-least-once honesty (§6) | Engineering recommendation — approval required |
| Event identity | transitionId (orders, persisted via timeline) · webhook event_key · refund PK · coupon ids · payload-frozen uuids for observers · login token id (§7) | Engineering recommendation — approval required |
| DEC-H RabbitMQ | Preserve transport; discrepancy documented | Unchanged — approval required (governance note separate) |
| DEC-I SDK | `kreait/firebase-php ^7.24.1` (8.x excluded); fallback per-token v1 on 6.7 (§14) | Engineering recommendation + dependency-change approval |
| DEC-K sweeper | Disabled default; report-only; provider-confirmed invalidity only (§15) | Engineering recommendation; enforcement policy = **Product Owner** |
| DEC-J overlap | Keep both pushes (status quo) until PO decides | **Product Owner** |
| Ledger/retention | 90-day retention; no `delivered` state; `order_notifications` schema unchanged | Engineering recommendation — approval required |

**Blocking unresolved issues (must be settled before PHASE 3):** approval of this revision (supersedes corrected sections of the original design) · DEC-A/DEC-C/DEC-E/DEC-F/G/DEC-I/identity designs above · M-2 DB inventory execution in a controlled environment (before any migration step) · DEC-I sandbox live-fire gate (before FCM code path ships).

**Exact implementation prerequisites:** approved revision + decisions → M-2 inventory read-only script → scratch-DB migration rehearsal → composer constraint change (7.24.1) with full-suite + live-fire gates → staged rollout behind `notifications.unified_push` flag with reverse-sync rollback artifact prepared.

**STOP — this revision is a corrected design proposal only. Nothing is implemented, and no part of it is pre-authorized. Awaiting explicit review and approval.**
