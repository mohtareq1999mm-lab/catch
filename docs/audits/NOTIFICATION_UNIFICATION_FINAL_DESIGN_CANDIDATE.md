# Notification System Unification — FINAL DESIGN CANDIDATE (Phase 3.1)

**Date:** 2026-11-14 · **HEAD:** `2f258c05f797cd8024d174da870755f08b08f6c0` (branch `main`, unchanged)
**Mode:** STRICT READ-ONLY design reconciliation. No code, migration, Composer, config, test, or data change was made. Nothing in this document is Tested; evidence classes are stated per section.
**Classification:** **CONDITIONALLY-READY** — architecture complete and internally consistent; approval blocked by the explicit gates in §23 (Composer graph resolution, Product-Owner decisions, Mobile confirmations, DB availability, sandbox live-fire).

**Supersession statement.** This document is the single authoritative proposed design. It supersedes, as *proposed design references*, the design portions of: `NOTIFICATION_UNIFICATION_DESIGN.md` (2.0), `NOTIFICATION_UNIFICATION_DESIGN_REVISION.md` (2.1), `NOTIFICATION_UNIFICATION_PHASE_2_2_DESIGN.md` (2.2 incl. 2.2.3), and resolves the audit corrections of `NOTIFICATION_UNIFICATION_PHASE_2_3_ADVERSARIAL_AUDIT.md` (2.3, DEF/M registers). The **discovery and audit evidence** in `NOTIFICATION_SYSTEM_FULL_AUDIT.md`, `NOTIFICATION_UNIFICATION_DISCOVERY.md`, and `NOTIFICATION_UNIFICATION_BASELINE.md` remains valid evidence input and is not superseded. Every prior finding's disposition is traced in `NOTIFICATION_UNIFICATION_DEFECT_CLOSURE_MATRIX.md`; decisions are registered in `NOTIFICATION_UNIFICATION_FINAL_DECISION_REGISTER.md`; adversarial review in `NOTIFICATION_UNIFICATION_FINAL_ADVERSARIAL_REVIEW.md`. Previous files were not modified.

---

# §1 Source-evidence hierarchy and reconciliation method

Priority used (highest first): current source at pinned HEAD → installed vendor source → migrations → tests (structural, NOT RUN) → git/config → prior audit evidence → prior design recommendations → assumptions. Document-vs-document contradictions were resolved by fresher primary evidence, not by document seniority:

| # | Contradiction between documents | Resolution evidence | Outcome |
|---|---|---|---|
| K-1 | 2.1 E-18 claimed kreait 7.24.1 requires guzzle `^7.5` (✓ with pinned 7.8.0); 2.2 §8 claimed `^7.9.2` (✗) | Exact-tag `composer.json` fetched from `raw.githubusercontent.com/kreait/firebase-php/7.24.1` this phase: `"guzzlehttp/guzzle": "^7.9.2"` | **2.2 correct; A2 requires the guzzle bump.** Additionally **new finding V-21**: 7.24.1 requires `psr/log ^3.0.2` while the project pins exactly `3.0.0` (composer.json:33) ⇒ **Option A2 requires three composer.json changes** (kreait, guzzle, psr/log), not two. Full graph resolution remains UNKNOWN (DEF-14 dry-run gate stands). |
| K-2 | 2.1 §6.1 ledger key excluded `channel` (unique on `(dedupe_key, channel)`); 2.2 §5.2 includes `channel` in the key | 2.2 is the later reconciliation of the dedupe model (P0-06 lineage) | Channel is **inside** the dedupe key; `UNIQUE(dedupe_key)` alone; `channel` kept as a filtering column, removed from uniqueness. |
| K-3 | 2.2 §4.5/§15.3 increment `attempts` in the ambiguous one-shot flip **and** C-8 defines `attempts` as incremented **at the pre-send marker** | C-8's own definition ("counts genuine transmission opportunities") | **Flip does NOT increment `attempts`.** Single accounting authority: the pre-send marker. Max 3 markers = 3 transmission opportunities. (Adversarial finding AF-1; see review doc.) |
| K-4 | 2.2.3 C-5 assumed tokens are "deactivated, never hard-deleted", but the Path A contract hard-deletes on user-initiated `DELETE /general/device-tokens` (docs/api/device-tokens.md; DeviceTokenController.php:44-46) | Behavior preservation rule: user-initiated DELETE is documented API behavior, not a defect | Two removal semantics coexist **by contract**: user-initiated DELETE = hard delete (unchanged); provider invalidity / sweeper = deactivate+retain. Ledger uses **soft references** so history survives either. (Resolves DEF-2/C-5 consistently.) |
| K-5 | Mission rule "every push must enter through Laravel Notifications" vs Path B's synchronous result contract (`order_notifications` row status depends on the send result available at listener time) | `SendOrderPushNotification.php:64-71` (result consumed to mark sent/skipped/failed) | Path B keeps its queued listener boundary but its delivery is executed by the **same single `PushDispatcher`** (registry read → ledger claim → FcmService → classifier). The 27 in-scope Laravel notification types are the unified application entry point; Path B's legacy listener is retained as an approved adapter into the same pipeline. Converting it to a Laravel notification class would change the externally observable `order_notifications` status semantics (async result unavailability) — documented as an optional post-cutover item requiring separate approval (D24). No competing delivery implementation remains. |
| K-6 | DEF numbering families: 2.2 §2 uses DEF-01…DEF-10 for 2.1's design defects (aliased P0-01…P0-10); 2.3 §20 uses DEF-1…DEF-19 for 2.2/2.2.3 design defects, with **no DEF-9, DEF-12, DEF-15** anywhere in the 2.3 register (verified by grep) | grep over the 2.3 document | Both families are accounted for separately in the closure matrix; the three missing IDs are documented as numbering gaps (never issued), not missing findings. |

---

# §2 Authoritative baseline (summary; full detail in BASELINE doc)

PHP 8.2.30 · Laravel 10.30.1 · `kreait/firebase-php 6.7.0` (lock ref `b091648`) · `guzzlehttp/guzzle 7.8.0` (pinned; lock ref `1110f66`) · `psr/log 3.0.0` (pinned) · queue default connection `database`, `queue.queues.high` → env `QUEUE_HIGH` · broadcast default `pusher` · RabbitMQ = isolated coupon-only backbone (preserved) · `device_tokens` (token globally unique, uuid, client) + `user_device_tokens` (unique per user+token+platform — multi-user ownership possible) · `order_notifications` (order-status ledger, `user_id` NOT NULL, `delivered` unreachable) · `payment_webhook_events` (UNIQUE(provider,event_key)) and `transactions` (payment attempt lifecycle + idempotency_key migrations verified) exist as persisted identity sources · Marvel User carries `Notifiable` (:12) with `receivesBroadcastNotificationsOn` → `admin.notifications` / `users.{id}` · **MySQL unreachable in this environment — blocker B-1; all test execution remains impossible** (reported, not bypassed).

Current state (verified): 27 app Laravel-notification classes (`via` = database+fcm+broadcast; AdminQueueJobFailed DB-only) · Path B single caller `SendOrderPushNotification` → `PushNotificationService` (simulated success when `FCM_ENABLED=false` default) · Path C coupon consumer already enters Laravel Notifications with two durable guards · 22 sanctioned dispatch sites, zero direct business-layer FCM calls · 21/21 Marvel notifications mail-only, 8 mutate `App::setLocale` (hazard flagged out of scope) · promotion/flash-sale/product observer events lack `ShouldDispatchAfterCommit` (DEF-11 verified) · payment emit sites: webhook controllers have persisted `event_key`; OrderController manual paths + CancelUnpaidOrders need per-site identity (DEF-6).

---

# §3 Target architecture

```
Business events / application actions (22 trigger families — UNCHANGED)
        │
        ▼
Laravel Notification API  ($user->notify() / Notification::send())   ← single application-facing entry
        │                                    │
        │ Path A (27 classes)                │ Path B adapter (order-status push; queued listener
        │ SendQueuedNotifications job        │ keeps its ledger + preference flow)  ← K-5
        │ channels: database | fcm | broadcast
        ▼                                    ▼
┌────────────────────────────── CENTRAL PUSH PIPELINE ──────────────────────────────┐
│ FcmChannel (payload authority = toDatabase(); preference+locale resolution)       │
│        └ SendFcmNotificationJob (explicit per-dispatch queue; timeout 1200)       │
│              └ PushDispatcher  (AUTHORITATIVE push orchestration — ONE owner)     │
│                   1. identity+scope → intent claim (UNIQUE dedupe_key)            │
│                   2. DeviceRegistry::activeTokensForUser() (canonical read)       │
│                   3. attempt rows created atomically with claim (frozen snapshot) │
│                   4. per attempt: CAS claim → pre-send marker → FcmService send   │
│                   5. FcmErrorClassifier (phase/errno-aware) → outcomes            │
│                   6. ledger writes + DeviceRegistry token-state transitions       │
└──────────────────────────────────────────────────────────────────────────────────┘
        │                    │                              │
        ▼                    ▼                              ▼
  notification_intents  device_tokens (canonical)   FCM v1 API only (per-message sends;
  + notification_device_attempts (soft-ref tokens;   no retired batch endpoint)
  token_hash; no raw token)    quarantine + legacy map
        │
        └ coupon Path C (RabbitMQ transport + durable guards UNCHANGED) → notify → same pipeline
          its outcomes are RECORDED in the ledger, never gated by it

Preserved untouched: database channel rows · broadcast/Pusher events + channel auth ·
mail (incl. 21 Marvel mail-only classes) · SMS listeners · order_notifications contract ·
all API envelopes · RabbitMQ coupon transport · business triggers/recipients/meaning.
```

**Entry-point rules (binding):**
1. Every in-scope push is delivered only by `PushDispatcher` (single orchestration owner). No other component may call `FcmService`.
2. `FcmService` is the only Kreait call site; it never mutates device state; it reports classified results.
3. Business services never import Firebase, never select devices, never compute delivery state.
4. `DeviceRegistry` is the single writer of token state (registration, reassignment, unregister, invalid, logout, sweep). The current inline hard-delete in `SendFcmNotificationJob.php:50` is migrated into `DeviceRegistry` (M-8).
5. Laravel Notifications remain the application-facing entry for all 27 in-scope classes; Path B is the single documented adapter exception (K-5) with its alternative recorded as decision D24.
6. Database-channel rows and broadcast events keep today's payloads, timing, and authorization — byte-for-byte contracts.

---

# §4 Responsibility ownership matrix (one owner per concern)

| Concern | Owner | Input contract | Output contract | Allowed deps | Forbidden deps | May mutate | Must never mutate |
|---|---|---|---|---|---|---|---|
| Business event | Existing domain (OrderService, observers, webhooks, schedulers) | — | unchanged events | domain deps | notification internals | business state | anything notification-side |
| Notification entry | Laravel Notifications (`notify`, `Notification::send`) | notifiable + notification | channel fan-out | framework | provider SDKs | queue dispatch | business state |
| Notification content | notification class `toDatabase()` (single authority; `toFcm` forbidden — test-pinned) | notifiable | localized payload array | lang keys, models | provider, registry, ledger | — | business state, global locale |
| Recipient policy | existing listeners (unchanged) | event | notifiable(s) | models | provider/registry | — | recipients semantics |
| Channel eligibility | `via()` per class + central category policy (§13) | notification class | channel set | — | provider | — | add ineligible channels silently |
| Device registry | `DeviceRegistry` | register/unregister/invalid/logout/sweep commands | token rows, active sets | DeviceToken model | Kreait, ledger | `device_tokens`, quarantine, legacy map | ledger, intents |
| FCM provider call | `FcmService` | client, title, body, data, token collection | classified per-item report | Kreait SDK | registry, ledger | — | token rows |
| DB persistence | Laravel database channel (unchanged) | notification | `notifications` rows | — | — | notifications rows | delivery ledger |
| Broadcast | Laravel broadcast channel (unchanged) | notification | Pusher events | — | — | queue dispatch | delivery ledger |
| Push orchestration | `PushDispatcher` | envelope (title/body/data/user/scope/identity/category/queue) | per-attempt outcomes | registry, FcmService, classifier, ledger | business services | intents, attempts | token rows (except via DeviceRegistry calls), notifications rows |
| Preferences | `NotificationPreferences` helper (ONE rule set) | user, category, channel | allow/skip(+reason) | UserNotificationPreference | provider | preference rows (user-created via API only) | worker locale |
| Localization | preference-based resolver in FcmChannel (§14) | payload maps | display strings | preference row | `App::getLocale()` for push | — | global locale |
| Idempotency | intent claim (UNIQUE dedupe_key) | scope+identity+recipient+channel | claim row or duplicate-skip | ledger models | — | intents | attempts membership after freeze |
| Delivery attempts | `notification_device_attempts` rows via PushDispatcher | envelope | attempt states | ledger models | — | attempt rows | intents of other events |
| Retry | PushDispatcher lease/claim machinery + framework tries (two documented layers) | attempt states | bounded retries | ledger | — | attempt rows | other intents' rows |
| Error classification | `FcmErrorClassifier` | Throwable (chain-walked) | phase + class + action | vendor taxonomy | registry | — | — |
| Observability | ledger + structured logs + scheduled sweeper/alerts | events from pipeline | queryable states/counters | — | — | sweeper-owned transitions | results, dedupe flags |

No two components share a write responsibility. The three previous competing implementations (`PushNotificationService` direct-FCM, inline token deletion in the job, dual registries) are retired as *delivery authorities*; the names may survive only as thin adapters that delegate (see §19 cutover).

---

# §5 Device registry design (canonical)

## 5.1 Canonical table — `device_tokens` evolved (additive only)

Existing columns preserved: `id`, `uuid` (unique), `user_id` FK→users **cascadeOnDelete** (unchanged), `token varchar(512)` **UNIQUE (global)**, `client varchar(32)`, `platform varchar(16) default 'android'` (already accepts `ios`/`web` — **no widening migration needed**; supersedes 2.0 §4 "enum widening" with schema evidence), `last_used_at`, timestamps, index `(user_id, client)`.

New columns (single additive migration, one DDL statement per step): `device_name varchar(255) NULL`, `device_id varchar(255) NULL`, `app_version varchar(50) NULL`, `os_version varchar(50) NULL`, `is_active TINYINT(1) NOT NULL DEFAULT 1`, `expires_at TIMESTAMP NULL`, `deactivated_at TIMESTAMP NULL`, `deactivated_reason VARCHAR(32) NULL` (`logout|invalid_token|sweeper|manual|registration_replaced`), `merge_verified TINYINT(1) NOT NULL DEFAULT 1`, index `(is_active)`. Rationale: carries Path B metadata losslessly into the canonical registry (evidence: `user_device_tokens` :22-34); `is_active` index supports eligibility reads and sweep reports.

## 5.2 Ownership rules (one policy)

| Rule | Statement | Source of correctness |
|---|---|---|
| O-1 | A token maps to exactly **one** user (global unique). | existing UNIQUE(token) + test-pinned reassignment (FcmIntegrationTest:59-69) |
| O-2 | Registering an existing token **reassigns** it to the registering user and activates it (explicit user action — never timestamp inference). | proven Path A semantics; merge algorithm §5.4 rule 4 |
| O-3 | Only fresh authenticated registration reactivates a deactivated/quarantined token. | P0-01 resolution |
| O-4 | Invalid-token response deactivates the token **at token level** (`is_active=false`, reason=`invalid_token`), retained, never silent-deleted — correct regardless of current owner because FCM unregistered means the token string is dead for everyone. | D.3 analysis |
| O-5 | User-initiated `DELETE /general/device-tokens` remains a **hard delete** (documented contract). Ledger soft-refs survive it. | K-4 |
| O-6 | `DELETE /user/devices/{deviceId}` remains owner-scoped deactivate + 404-if-not-owned (unchanged). | verified controller |
| O-7 | Logout touches nothing by default; optional flag-gated current-device release needs the mobile body-field contract (D9/D14). | E-13 proof |
| O-8 | Sweepers are **report-only by default**; enforcement requires provider-confirmed invalidity and PO approval (D11). | 2.1 §15 (DEC-K) |

## 5.3 Registration identity without raw-token exposure (D.2 answer)

- Raw FCM tokens live **only** in `device_tokens.token` (bearer credential; never in the ledger, never in logs — the current 20-char-prefix log line in `PushNotificationService.php:67-70` is deleted at cutover).
- Delivery history references a registration by: `device_token_id` (soft integer ref to the row id at attempt time — ids are not reused) + `token_hash` = `SHA2(token, 256)` (stable token-string identity, opaque) + `client` + intent `recipient_id`. This identifies *which registration instance and which token string* were used without exposing the raw token, and survives hard deletion of the token row.

## 5.4 Migration merge algorithm (final; supersedes 2.1 §5 winner-by-timestamp)

Group all rows of both source tables by token string. Per group:
1. Rows whose `user_id` has no surviving `users` row → quarantine (`orphaned_user`), no canonical row.
2. Single-source, single-row, user exists → canonical insert **active**, metadata carried, legacy refs recorded.
3. **Any** group with more than one source row (any source mix, any timestamps) → canonical row created **deactivated** (`is_active=false`, reason=`migration_quarantine`, `merge_verified=false`); owner column filled from a deterministic ordering (NULLS-LAST `last_used_at`, then source priority, then id — **cosmetic only**; the row can never receive pushes); **every** source row copied to quarantine. (P0-01: no timestamp-only activation.)
4. Reactivation exclusively via O-2/O-3 (fresh authenticated registration).
5. Idempotent re-runs produce identical canonical state; quarantine keyed by source ids; **no source record is ever deleted** (rollback re-imports verbatim).

Classification: algorithm VERIFIED-by-construction; production data shape UNKNOWN (M-2/B-1).

## 5.5 Registry helper tables

- `device_token_legacy_refs`: `legacy_user_device_token_id unsignedBigInteger PK`, `device_token_id unsignedBigInteger NOT NULL` (**soft reference — no FK**, so the documented hard DELETE can never break or cascade), `user_id`, `created_at`, index `(device_token_id)`. One row per migrated Path B source row.
- `device_token_merge_conflicts` (quarantine): `id`, `token`, `source_table`, `source_id`, `user_id`, full source metadata, `reason` (`orphaned_user|ambiguous_ownership|superseded_conflict`), `canonical_device_token_id unsignedBigInteger NULL` (soft ref), `merged_at`, timestamps. Retention per D12; contains tokens + historical owner ids → DB-access-only surface.

---

# §6 Delivery ledger design (two levels)

## 6.1 `notification_intents` — one logical (event, recipient, channel) notification

| Column | Type | Constraint / rationale |
|---|---|---|
| id | bigint unsigned PK | |
| dedupe_key | CHAR(64) | `SHA2(dedupe_scope \| event_identity \| recipient_id \| channel, 256)` — full 64-hex, no truncation (collision-proof practicality); **UNIQUE(dedupe_key)** = the idempotency claim (K-2) |
| channel | VARCHAR(20) NOT NULL | `'fcm'` initially (DB/BR outcomes are Laravel-native; recorded by those channels' existing mechanisms, not this ledger) |
| recipient_id | bigint unsigned NOT NULL | user id (admins included; preference-exempt per §13) |
| notification_type | VARCHAR(191) NOT NULL | class basename — presentation record only, **not** part of the key (P0-06 resolution) |
| dedupe_scope | VARCHAR(64) NOT NULL | scope declaration (§11) |
| event_identity | VARCHAR(191) NOT NULL | §11 identity string; stability rule: never a hash of content, never `now()` |
| status | ENUM('queued','dispatched','completed','failed','skipped') DEFAULT 'queued' | DEF-3 derivation in §7.2 |
| skip_reason | VARCHAR(64) NULL | `push_disabled|event_disabled|quiet_hours|owned_by_other_channel|no_devices|identity_unavailable` |
| timestamps | | retention 90d (D12) |

Indexes: UNIQUE(dedupe_key); `(recipient_id, created_at)`; `(event_identity)`. **No FK** (self-contained lifecycle).

## 6.2 `notification_device_attempts` — per-device truth

| Column | Type | Constraint / rationale |
|---|---|---|
| id | bigint unsigned PK | |
| intent_id | bigint unsigned NOT NULL, **FK → notification_intents ON DELETE CASCADE** | attempts die with their intent (retention); FK only where deletion is designed |
| device_token_id | unsignedBigInteger NOT NULL — **plain soft reference, NO foreign key** | **DEF-2 (P0) resolution:** a FK (RESTRICT) would break the documented `DELETE /general/device-tokens` hard-delete; CASCADE would destroy delivery history. Soft ref + hash survives both. |
| token_hash | CHAR(64) NOT NULL | opaque join key; **replaces the raw `token_snapshot`** (DEF-10/M-14: no bearer credential in a 90-day ledger) |
| client | VARCHAR(32) NOT NULL | per-Firebase-project routing evidence |
| status | ENUM('pending','claimed','attempted','provider_accepted','failed','ambiguous','simulated','skipped') DEFAULT 'pending' | §7.1 machine; `skipped` = pre-send validation refusal (M-7) with reason in `error_class` |
| provider_message_id | VARCHAR(191) NULL | **latest** accepted message id (forensics: every accepted id also written to structured log `push.send.accepted` keyed by attempt id — M-1-3) |
| error_class | VARCHAR(40) NULL | classifier class / skip reason |
| error_message | VARCHAR(500) NULL | sanitized (no tokens, no PII) |
| attempts | INT UNSIGNED NOT NULL DEFAULT 0 | = number of pre-send markers (transmission opportunities); **single accounting authority** (K-3/AF-1) |
| claim_seq | BIGINT UNSIGNED NOT NULL DEFAULT 0 | monotonic; never decremented (C-2) |
| claimed_by | VARCHAR(64) NULL | worker id (informational + fence co-guard) |
| claimed_until | TIMESTAMP NULL | lease (1860s = retry_after 1800 + 60 margin) |
| ambiguous_retry_used | TINYINT(1) NOT NULL DEFAULT 0 | one-shot ambiguity flag (P0-05); no code path clears it |
| cool_down_until | TIMESTAMP NULL | set on ambiguous conversion |
| timestamps | | |

Constraints: **UNIQUE(intent_id, device_token_id)** — with the soft ref NOT NULL this fully guarantees one attempt row per (intent, registration instance); index `(status, claimed_until)` (lease sweep/retry selection); index `(device_token_id)`; index `(token_hash)`. MySQL note (documented, not relied upon): InnoDB unique indexes permit multiple NULLs (8.0 Reference Manual §17.6.2.1) — the design does **not** depend on nullable-uniqueness anywhere (C-5 evidence retained).

---

# §7 State machines (complete, every transition authorized)

## 7.1 Attempt machine

| # | Transition | Authorized actor | Required current state | Atomic guard (SQL) | `claim_seq` | Counters / cooldown |
|---|---|---|---|---|---|---|
| T1 | create attempt rows | PushDispatcher (in the claim transaction) | intent being claimed | insert-or-ignore on UNIQUE(intent, device) | initial 0 | attempts=0 |
| T2 | claim (or lease-expired reclaim) | dispatcher worker | `pending`, or `claimed` with expired lease, **AND attempts<3** | `UPDATE … SET status='claimed', claim_seq=LAST_INSERT_ID(claim_seq+1), claimed_by=:worker, claimed_until=:now+1860s WHERE id=:id AND attempts<3 AND (status='pending' OR (status='claimed' AND claimed_until<:now))` then `SELECT LAST_INSERT_ID()` | **bumped** (atomic read-back idiom — DEF-4 fix) | none |
| T2b | claim refused (attempts≥3) | same worker | `pending` AND attempts≥3 | `UPDATE … SET status='failed', error_class='not_transmitted_exhausted' WHERE id=:id AND status='pending' AND attempts>=3` | not bumped | terminal (C-8) |
| T3 | pre-send marker (**final fence — executed BEFORE HTTP initiation**) | claim owner | `claimed` | `UPDATE … SET status='attempted', attempts=attempts+1 WHERE id=:id AND claim_seq=:mine AND claimed_by=:worker AND status='claimed'` | not bumped | **attempts+1** (K-3: the only increment path) |
| T4 | provider accepted | claim owner | `attempted` | `… SET status='provider_accepted', provider_message_id=:id … WHERE id=:id AND claim_seq=:mine AND claimed_by=:worker AND status='attempted'` | not bumped | — |
| T5 | definite failure (response-present classes) | claim owner | `attempted` | `… SET status='failed', error_class=… WHERE id=:id AND claim_seq=:mine AND claimed_by=:worker AND status='attempted'` | not bumped | — |
| T6 | definite-not-transmitted (pre-send failure; `ConnectException` errno 6/7/35) | claim owner | `attempted` (or `claimed` if failed before marker) | result write as T5 with class, **or** for claimed-without-marker: `… SET status='pending' … WHERE id=:id AND claim_seq=:mine AND claimed_by=:worker AND status='claimed'` | not bumped (pending keeps row retryable) | none (never escalates to ambiguous) |
| T7 | ambiguous (errno 28/52/unknown; response-less; lost response) | claim owner | `attempted` | `… SET status='ambiguous', cool_down_until=:now+cooldown WHERE id=:id AND claim_seq=:mine AND claimed_by=:worker AND status='attempted'` | not bumped | cool_down set |
| T8 | lease expiry of **unmarked** claim → safe reclaim | dispatcher retry sweep or scheduled sweeper | `claimed` AND lease expired | `… SET status='pending', claim_seq=claim_seq+1, claimed_by=NULL, claimed_until=NULL WHERE id=:id AND status='claimed' AND claimed_until<:now` | **bumped** | none — **structurally cannot touch `attempted` rows** |
| T9 | expired `attempted` → ambiguous conversion | sweeper only | `attempted` AND lease expired | `… SET status='ambiguous', claim_seq=claim_seq+1, cool_down_until=:now+cooldown WHERE id=:id AND status='attempted' AND claimed_until<:now` | **bumped** | cool_down set; straggler's later result write fails (logged, conservative-ambiguous) |
| T10 | ambiguous one-shot flip | worker via CAS | `ambiguous`, flag=0, cooldown expired, attempts<3 | `… SET status='pending', ambiguous_retry_used=1, claim_seq=claim_seq+1, claimed_by=NULL, claimed_until=NULL WHERE id=:id AND status='ambiguous' AND ambiguous_retry_used=0 AND cool_down_until IS NOT NULL AND cool_down_until<=:now AND attempts<3` | **bumped** | **attempts NOT incremented here** (K-3); the subsequent marker counts the retry send |
| T11 | second ambiguity → terminal | claim owner | `attempted` after a flagged retry | T5 with class `ambiguous_exhausted` (flag already 1 blocks any further flip) | not bumped | terminal |
| T12 | pre-send validation refusal | dispatcher at claim/pre-send | device missing / inactive / owner ≠ intent.recipient | `… SET status='skipped', error_class='skipped_reassigned'|'skipped_unregistered' … WHERE id=:id AND claim_seq=:mine AND claimed_by=:worker AND status='claimed'` | not bumped | none |
| T13 | simulated outcome | claim owner | `attempted` (provider disabled at send decision) | T4-style write with status='simulated', error_class='provider_disabled' | not bumped | terminal — **never recorded as provider_accepted** (D-008 fix) |

Sweeper restrictions (C-2): never writes results, never touches `ambiguous_retry_used`, never modifies terminal rows (`provider_accepted`, `failed`, `simulated`, `skipped`). Every stale write (wrong seq/owner/status) affects **0 rows** and is logged at debug.

**Cap enforcement summary (retry limits cannot be bypassed):** `attempts` increments only at T3 (marker). T2's claim guard `attempts<3` is atomic — an application-side check alone is never trusted (DEF-4). The framework job `tries=3/backoff=[30,120]` is a second, independent guard; terminal attempt states short-circuit the dispatcher before any provider call, so framework retries cannot re-send. `fail($e)` (auth_config) fails without consuming framework retries (verified vendor InteractsWithQueue.php:50).

## 7.2 Intent machine (DEF-3 resolution)

| Transition | Actor | Guard |
|---|---|---|
| queued → dispatched | dispatcher claim (insert-or-ignore wins) | attempts created **atomically in the same transaction** (C-6 frozen snapshot) |
| zero devices at claim | dispatcher | intent = **`skipped`**, skip_reason=`no_devices`, **no attempt rows** — never left `dispatched` |
| dispatched → completed | worker writing the **last** terminal attempt | all attempts ∈ {provider_accepted, simulated, failed, skipped, ambiguous→(terminal by T11)} AND ≥1 accepted/simulated |
| dispatched → failed | same | all attempts terminal AND none accepted/simulated |
| dispatched → skipped | dispatcher (preference gate refusal at claim) or all attempts `skipped` | skip_reason set |
| reconciliation | sweeper | rolls up intents whose attempts are all terminal but status still `dispatched` (result-write-loss safety net); **never invents outcomes** |

---

# §8 Error classification (phase/errno-aware; C-1 + 2.3 §8 consolidated)

Classifier walks `getPrevious()` chain (Kreait wraps response-less Guzzle errors into generic `MessagingError` — vendor `MessagingApiExceptionConverter.php:111-119`); class name alone is never trusted for transport state (K-1 evidence family).

| Evidence source | Classification | Action |
|---|---|---|
| Pre-send: credential/OAuth acquisition, message construction | **definite-not-transmitted** | T6 → pending (normal retry, cap 3); persistent ⇒ `failed(auth_config)` via `fail($e)` (zero retries consumed, `failed()` fires, existing JobFailed alerting) |
| `ConnectException` errno **6** (DNS), **7** (connect), **35** (TLS handshake) — vendor CurlFactory.php:163-169 | **definite-not-transmitted** (request never sent) | T6 |
| `ConnectException` errno **28** (timeout — connect *or* response-wait) | **ambiguous** | T7 (one-shot retry) |
| `ConnectException` errno **52** (got nothing) | **ambiguous** | T7 |
| Response present: Kreait converter output (`NotFound`, `InvalidArgument`, `InvalidMessage`, `AuthenticationError`, `QuotaExceeded`, `ServerError`, `ServerUnavailable`) or `BadResponseException` | **definite outcome** per class: `invalid_token`→DeviceRegistry.markInvalid + `failed(invalid_token)`; `invalid_payload`→`failed` no-retry; `auth_config`→fail-all + `fail($e)`; `quota`/`server`→transient rethrow (Retry-After honored, converter :122) | T4/T5 + token transition |
| Response-less `RequestException`/`TransferException`/unknown errno/Kreait generic `MessagingError` without chain evidence | **ambiguous (conservative default)** | T7 |
| Whole-request unexpected exception | rethrow after ledger note — framework tries engage (D-002 fix) | — |

Unknown errnos (future libcurl versions) default to ambiguous — safe. errno table is cURL-version-dependent and pinned to the installed Guzzle's mapping (evidence recorded).

---

# §9 Delivery guarantees (honest)

- **Guarantee: at-least-once per attempt row.** Not exactly-once. FCM HTTP v1 has **no idempotency key** (verified authoritative API reference — `projects.messages/send` exposes no dedup parameter); `claim_seq`, row locks, and the local lease fence **database writes only** — they cannot fence an already-authorized external HTTP request.
- **Duplicate windows (bounded, documented, not eliminated):**
  - **W1** — stale worker passed its pre-send marker, paused, lease expired, sweeper converted to ambiguous (T9), second worker retried and sent, first worker resumes and sends the same request. T3's fence prevents *superseded-before-marker* workers from sending, but a worker that already marked can always send. Cool-down length reduces the probability; it cannot make it zero.
  - **W2** — response lost after provider acceptance (T7 → T10 retry) — the retry send can duplicate an already-delivered push.
- **Loss windows:** none beyond queue-loss (a committed event whose queued job is lost before claim — Laravel queue semantics, mitigated by standard queue monitoring R-2). No designed path drops a claimed send silently: every non-terminal state has an authorized transition path and a sweeper safety net.
- **Duplicate forensics:** every accepted send writes `push.send.accepted` (attempt_id, provider_message_id, client) to structured logs; `provider_message_id` holds the latest; more than one accepted id for one attempt ⇒ duplicate occurred (countable post-hoc).
- **Client-side deduplication contract (mobile dependency — decision D18):** every push data payload carries a stable `notification_id` (= the attempt's parent intent id + recipient); the client suppresses duplicate displays. Adoption is a rollout acceptance criterion for push UX; without it the honest UX statement is "duplicates possible, rare".
- **Never claimed:** device-delivered, displayed, or opened states (FCM provides no display receipts; APNs feedback not integrated). `delivered` is unused everywhere (existing `order_notifications.delivered` remains dead — documented).

---

# §10 Event identity and deduplication (D.5)

## 10.1 Identity table (all push-emitting sources; stability rule: persisted or payload-frozen only; never content-hash, never `now()`, never transient random)

| Family | event_identity | Generation point | Persistence point | Propagation | Verification |
|---|---|---|---|---|---|
| Order transitions (status/cancel/deliver, incl. return-to-previous) | `transition:{transitionId}` | OrderService mint | `OrderTrackingEvent.metadata` in-transaction (synchronous listener RecordOrderStatusChangeInTimeline — verified) | plain string prop on `OrderStatusChanged`/`OrderCancelled`/`OrderDelivered` (plain strings survive queue serialization — E-6) | mechanism VERIFIED |
| Payment webhooks | `webhook:{provider}:{event_key}` | webhook controller (key in scope) | `payment_webhook_events` UNIQUE(provider,event_key) (migration verified) | event prop | VERIFIED |
| Payment manual/gateway-callback sites (OrderController :432/:583/:606/:631/:785/:799/:890) | `txn:{transaction_id}` — the persisted transaction row the site is failing/completing | emit site resolves the transaction row in scope | `transactions` rows (attempt-lifecycle migration verified) | event prop | sites verified by grep this phase (:631 `$transaction` in scope per 2.3; implementer must verify each of the 7 resolves a persisted transaction row) |
| Payment failure from unpaid-order reaper (CancelUnpaidOrders.php:138-143) | `txn:{transaction_id}` — rows flipped to `failed` at :138-140 immediately before emit :143 | same | same | same | VERIFIED (read this phase) |
| COD completion | shares the order transitionId | OrderService | as above | as above | VERIFIED |
| Refund | `refund:{refund PK}` | RefundService | Refund row | model on event | VERIFIED (E-9) |
| Coupon distribution | `coupon-notify:{run_id}:{recipient_id}` | envelope (persisted PKs) | coupon run/recipient tables | envelope | VERIFIED (P0-07; tree_hash demoted to guard-only) |
| Cart reminder | claim-based `cart:{cartId}:reminder` | atomic claim | `reminder_sent_at` column | — | VERIFIED (test-pinned) |
| Promotion/FlashSale/Product observer events | payload-frozen UUID minted in the synchronous observer | observer | first intent claim persists it (`event_identity`) | plain string prop | mechanism VERIFIED; dispatch-timing risk = DEF-11/D19 |
| Admin login | `login:{sanctum_token_id}` | UserController (token created :565 before dispatch :571) | sanctum token row | int prop | VERIFIED (E-10) |
| Contact message | `contact:{contact PK}` | event | contact row | model | VERIFIED |
| Queue-failure alert | `jobfailed:{job uuid}` | failed-job record | failed_jobs | — | VERIFIED mechanism |
| Review / digital-delivery | `review:{reviewId}:{action}` / `delivery:{entitlementId}:{eventClass}` | event | persisted PKs | model props | VERIFIED mechanism |

**Fail-closed rule (M-6):** any push-emitting site that cannot produce a stable persisted identity skips the push and records ledger `skipped(identity_unavailable)`. It must never send unscoped and must never mint a random/transient identity.

## 10.2 Namespace split statement (M-4)

`Marvel\Events\*` order events (fired by `OrderStatusManagerWithPaymentTrait` :199-241) route to Marvel listeners → **mail-only** notifications (verified: 21/21 `['mail']`) ⇒ no push intents ⇒ no push identity required. `App\Events\*` order events route to the app pipeline. The two namespaces must not be cross-wired for push (today the marvel provider wires `App\Listeners\SendUserOrderDeliveredNotification` onto `Marvel\Events\OrderDelivered` — that listener is **push-bearing**; verification that the Marvel `OrderDelivered` event is actually dispatched in production paths is a cutover-time grep gate; if it fires, the dual-wiring is a live duplicate risk and the marvel-provider registration of the App listener must be evaluated under the no-business-change rule before any change).

## 10.3 Dedupe scope model and matrix (P0-06 + C-7)

Each push-emitting notification class declares `eventIdentity()` and `dedupeScope()`. Default scope = `type:{classBasename}` (each class suppresses its own duplicates). The cancel/deliver pair (`UserOrderCancelledNotification`, `UserOrderDeliveredNotification`) and Path B's order-status push declare the shared scope `order.transition` (same transitionId ⇒ second claim rejected by UNIQUE key — belt-and-braces with DEC-C option 2 suppression). Path B (adapter entry) passes scope+identity+category directly into the dispatcher envelope (DEF-8 dual-entry contract). Key = `SHA2(scope|identity|recipient|channel)`. **Intent dedupe key includes channel; per-channel claims are independent** (K-2).

| event_identity | dedupe_scope | Outcome | Test |
|---|---|---|---|
| same | same | second claim **rejected** (suppression) | T-A7 |
| **different** | same | **both intents created** (allowed — e.g., two distinct transitions sharing `order.transition`) | T-A8a |
| same | **different** | **both intents created** (allowed — e.g., payment push vs order-completed push; DEC-J status quo) | T-A8b |

---

# §11 Channel independence (D.9)

- Each notification's `via()` defines channel eligibility (existing: `['database','fcm','broadcast']`; `AdminQueueJobFailedNotification` database-only; mail-only classes untouched). The pipeline never forces an ineligible channel.
- **Database** outcome: Laravel `notifications` row — written by the database channel inside `SendQueuedNotifications`, independent of FCM. A failed push never erases or invalidates it (structurally true today; preserved).
- **Broadcast** outcome: `NotificationSent`/`BroadcastMessage` job → Pusher; failures fail that job independently (`failed_jobs`); never touch FCM or DB rows.
- **FCM** outcome: ledger (`intents`+`attempts`) — per-device truth; FCM failure never re-sends DB or broadcast (different channels, different claims: the dedupe key is channel-scoped per K-2).
- One channel's retry never re-triggers another channel (retry selection queries are attempt-row-scoped and channel lives in the intent).
- Recipient without FCM device: intent `skipped(no_devices)` at claim (DEF-3) — DB and broadcast unaffected.
- Channel-specific payload contracts preserved byte-for-byte: A's localized-map + `action_url`/`resource_*`/`order_*` data keys; B's `click_action`/`order_number`/`status` keys; broadcast `broadcastType()` names.

---

# §12 Preferences and eligibility (D.12)

- **Tier 0** — channel master `push_enabled=false` suppresses all push for the user (existing model semantic).
- **Tier 1** — transactional (order/payment/refund/digital/coupon-entitlement/order-status): sent unless Tier 0.
- **Tier 2** — marketing (proposed list pending **PO approval** — nothing suppressed until approved): channel master **and** event preference (+ quiet hours, config-gated).
- **Tier 3** — unsuppressible: **no type assigned**; requires explicit PO approval to define.
- Admin recipients: preference-exempt; the gate helper must **not create** preference rows for non-user recipients (DEF-18).
- **Gate position (M-5):** final enforcement at **dispatcher claim time** (category passed in the envelope), for both Path A and Path B — a preference change between notification dispatch and send is still honored. FcmChannel performs an early gate for fast-fail (writes nothing if skipped — the queued notification job still delivers the DB row and broadcast, which is correct: DB/BR are not push).
- Skip reasons recorded on intents: `push_disabled|event_disabled|quiet_hours|owned_by_other_channel`.
- Existing Path B preference flow (event prefs for order-status push) is preserved and now shares the same helper (one rule set).

---

# §13 Localization (D.11)

- Push display locale = recipient's `notification_language` preference (fallback: configured default language constant), resolved in FcmChannel — **never `App::getLocale()` for push** (fixes D-012; worker-locale poisoning cannot affect push language).
- Payload en/ar maps are built by `toDatabase()` at construction (unchanged content — business semantics preserved).
- **Marvel mail `App::setLocale` hazard** (NewOrderReceived.php:51 et al., 8 classes): flagged, **out of this mission's scope** — any fix (capture/restore locale) changes Marvel mail behavior timing and is a separate approved item. Push path never mutates global locale (K-5 boundary).
- D-017 (missing ar `out_for_delivery` in B's hardcoded maps): when Path B delivery moves into the unified pipeline, B's title/body maps remain as-is initially (content preservation); translation-key consolidation is a separate approved change (decision D25).

---

# §14 Queue topology (D.10, V-16)

**V-16 fix (binding):** `SendFcmNotificationJob` **stops reading `config('frontend.queue')` entirely.** The job takes an explicit `queue` constructor parameter defaulting to `QueueName::high()`; `FcmChannel` sets it from the notification's declared push queue (`pushQueue()` default high; `medium` for PO-approved marketing classes). No notification job silently inherits unrelated configuration.

| Layer | Current (marketing types) | Target |
|---|---|---|
| Blast listener | high (21 SendUser* viaQueue) | `medium` **only for PO-approved marketing list** (D8); unchanged until approved |
| `SendQueuedNotifications` (contains DB write) | notification `onQueue(high)` | same rule per class |
| Broadcast event job | `BroadcastMessage onQueue(high)` | same rule per class |
| `SendFcmNotificationJob` | `config('frontend.queue') ?? high` (V-16 latent defect) | explicit per-dispatch property (above) |

Transactional layers unchanged. Failed jobs: existing `failed_jobs` + SUPER_ADMIN alert + daily prune unchanged. RabbitMQ coupon transport: **untouched** (isolated backbone; publisher confirms; `NotificationRequestHandler` guards unchanged). Worker counts/priorities: not modified in this phase (evidence-gated). Queue-assignment tests assert per-layer physical names via `QueueName::high()/medium()` (M-3), never literals.

---

# §15 SDK and Composer strategy (D.13, DEF-14, V-21)

Exact-tag metadata fetched this phase (primary evidence, resolves K-1):

| Option | Constraint set | Guzzle | psr/log | JWT CVE-2025-4659 | Notes |
|---|---|---|---|---|---|
| **A2 (recommended)** `kreait ^7.24.1` | php `~8.1||~8.2||~8.3||~8.4||~8.5` ✓ 8.2.30 | **`^7.9.2` — requires bump from pinned 7.8.0** | **`^3.0.2` — requires bump from pinned 3.0.0 (V-21, new)** | included (`firebase/php-jwt ^6.10.2||^7.0.2`) | adds `cuyz/valinor ^2.2.1`, `google/auth ^1.45`, `google/cloud-storage ^1.48.7`, `kreait/firebase-tokens ^5.2`, `lcobucci/jwt ^5.3`, `mtdowling/jmespath.php ^2.8` transitively — **graph resolution UNKNOWN** (srmklive/paypal, sanctum lcobucci ranges unknown) |
| A1 (fallback) `kreait ^7.15.0` | php `~8.1||~8.2||~8.3||~8.4` ✓ | `^7.5` ✓ pinned 7.8.0 | unchanged | **absent** | zero other dependency changes |
| B (last resort) stay 6.7.0 | unchanged | unchanged | unchanged | n/a | per-token `send()` loop on the healthy v1 endpoint (vendor SendMessage.php:25); batch `validateRegistrationTokens()` unusable on 6.7 (Messaging.php:105-116); 6.x EOL exposure |

8.x excluded (php ~8.3+). **No version requiring PHP ≥8.3 is proposed anywhere.**

**Composer gate (DEF-14, binding):** isolated scratch-dir procedure before any lock change: copy `composer.json`+`composer.lock` to a scratch directory → apply the candidate constraints → `composer update --dry-run` + `composer check-platform-reqs` → record the full resolution + transitive diff. Only a recorded successful resolution may convert D6 to approved. Post-install: vendor-diff of the `Messaging` contract; verify 7.x `SendReport` surface (`unknownTokens()/invalidTokens()`) from installed source (currently INFERRED from maintainer docs). Then: full suite + sandbox live-fire (M-4/B-6).

---

# §16 Database contracts (D-phase G; no database was changed — specification only)

| Table | Status | Key constraints + rationale |
|---|---|---|
| `device_tokens` | evolve (additive) | UNIQUE(token) — global reassignment foundation (proven); FK users cascade — user deletion cleans devices (existing); soft columns nullable — backfill-safe; `is_active` NOT NULL default — eligibility filter; **no FK added to attempts** (DEF-2) |
| `user_device_tokens` | frozen read-only at cutover; retained until retention decision | source rows are the rollback source-of-truth; never dropped in this design |
| `device_token_merge_conflicts` | new | quarantine preserves every source row byte-for-byte; reason-coded; soft canonical ref |
| `device_token_legacy_refs` | new | soft refs only (documented DELETE must never break); PK = legacy id; owner-scoped resolution input |
| `notification_intents` | new | UNIQUE(dedupe_key) = idempotency claim; no FK (independent lifecycle, 90d retention) |
| `notification_device_attempts` | new | FK→intents CASCADE only (retention-designed deletion); **soft device ref + token_hash** (DEF-2/DEF-10); UNIQUE(intent, device_token_id) NOT NULL — single attempt per registration per intent, no nullable-uniqueness reliance |
| `order_notifications` | unchanged (schema) | B listeners keep writing it; `skipped`+`failure_reason` used for suppression/simulated honesty (D13); no enum change |
| `notifications` | unchanged | Laravel standard |
| `payment_webhook_events`, `transactions` | unchanged | persisted identity sources (E-7, DEF-6) |

MySQL behaviors (documented, evidence-backed): unique indexes permit multiple NULLs (8.0 §17.6.2.1) — not relied upon; AUTO_INCREMENT persists in 8.0, **reverts to max+1 on restart in ≤5.7** (namespace scheme is therefore defense-in-depth only — DEF-17); column ADD is INSTANT only on 8.0.12+ (5.7 rebuilds — downtime NOT claimable until production version+row-counts known, M-2); ENUM-append is metadata-only on 8.0; `strict=false` masks truncation (R-5 — flagged, out of scope).

**Migration order:** (1) additive schema (intents, attempts, quarantine, legacy-refs, device_tokens columns) — each DDL statement its own migration; (2) read-only inventory script (M-2); (3) legacy-write freeze verification (D20); (4) AUTO_INCREMENT boundary bump (computed from actual data); (5) merge with quarantine (chunked, abort-safe per chunk); (6) adapters switch to DeviceRegistry; (7) cutover verification (§19). **Backfill:** merge is the backfill (idempotent, upsert by token + quarantine keyed by source ids). **Rollback:** §18.

---

# §17 API contracts (all endpoints; no invented breaking change)

| Endpoint | Current contract (verified) | Target | Compatibility / mobile impact |
|---|---|---|---|
| `POST /api/v1/general/device-tokens` | auth+throttle; token≤512, client∈{client_a,client_b}, platform∈{android,ios} optional; upsert by token → reassignment; response `{uuid}` | unchanged envelope; delegates to DeviceRegistry; **additive `device_uuid` field** in data (D14); platform-preservation fix D15 applies (set-if-provided) | byte-identical plus additive field; mobile adoption optional |
| `DELETE /api/v1/general/device-tokens` | token≤512; owner-scoped; **200 always** (incl. unknown/foreign — documented idempotency); hard delete | unchanged (hard delete preserved — K-4) | none |
| `POST /api/v1/user/devices/register` | token≤500, platform∈{ios,android,web}, metadata; returns **full model incl. `id`**; today creates a second active row per user (D-003) | same envelope; **global reassignment semantics** (fixes D-003); response serialized to preserve the documented field set + additive `device_uuid`; `id` = canonical id resolved per §17.1 | envelope preserved; `id` values change meaning for migrated devices (legacy ids keep working per §17.1 — M-3 mobile confirmation required) |
| `DELETE /api/v1/user/devices/{deviceId}` | owner-scoped deactivate; 404 if not owned | same; `{deviceId}` resolution = legacy-map-first (owner-scoped) → canonical id | old ids keep working for migrated devices; foreign/unknown → 404 unchanged |
| `GET/PUT /api/v1/user/notification-preferences` | CRUD + quiet hours + language en/ar | unchanged (Tier-2 marketing list enforcement arrives only after D8 approval) | none until approved |
| `GET /api/v1/user/notifications/history` | paginated `OrderNotification` (order-scoped ledger) | unchanged (DEC-F history-source decision deferred, D-023 stands as documented gap) | none |
| Marvel notification APIs + admin routes | `$user->notifications()`-scoped; VIEW_/MANAGE_NOTIFICATIONS permissions | untouched | none |
| Broadcast channel authorization | `admin.notifications` (admin), `users.{id}`, `user.{userId}.orders`, `order.{orderId}` (owner) | untouched | none |

**17.1 Numeric id compatibility (D5 legacy-ID; final):** primary correctness mechanism = **owner-scoped map-first resolution** (legacy `device_token_legacy_refs` → canonical; else canonical id — both branches owner-scoped; a numeric collision across users cannot misresolve because every branch checks ownership). Defense-in-depth: AUTO_INCREMENT boundary bump at migration (canonical ids start above max legacy id) + start-guard (migration refuses if boundary invalid) + post-migration zero-overlap verification query. **Enforcement of the legacy-write freeze (DEF-5/M-6):** code-level gate (adapters write canonical only) + implementation-audit grep gate are the default; the strongest option — `REVOKE INSERT, UPDATE, DELETE ON user_device_tokens FROM <app db user>` during the canonical period (reversible GRANT on rollback) — is a **DevOps decision (D20)**, not assumed. Trigger-based rejection: rejected (operational burden). Boundary re-assertion is a mandatory rollback runbook step (§18).

---

# §18 Rollback procedure (D.14)

1. **Trigger conditions:** post-cutover defect, mobile-contract breakage, provider regression.
2. **In-flight handling:** queued jobs drain or are killed; canonical ledger rows remain (dormant under old code — harmless); **no half-migrated state is exposed** (merge is chunk-abort-safe with quarantine).
3. **Reverse-sync FIRST (before serving traffic with rolled-back code):** for every canonical row missing from `user_device_tokens`, insert a legacy row (id from `device_token_legacy_refs` where present, else **new** legacy id for canonical-only registrations; owner, metadata, `is_active` mirrored); quarantined rows re-imported verbatim (including old ids via the map). Never deletes legacy rows. Deactivated/quarantined canonical rows → legacy rows with `is_active=false`.
4. **Boundary re-assertion (mandatory, scripted, idempotent):** `boundary = max(legacy_max_id, canonical_max_id) + 1` → `ALTER TABLE device_tokens AUTO_INCREMENT = boundary` — before reactivating any legacy-writing code path (C-4). Same assertion on re-cutover.
5. **Feature-flag rollback path (no app-version rollback):** both registration controllers retain their previous implementations behind a config flag for one release; flag-off restores previous behavior after reverse-sync.
6. **Duplicate prevention during rollback:** rolled-back code re-enters the documented pre-unification defect state (D-001…D-007 known) — the rollback returns to a known baseline; the unified pipeline's ledger remains (rows are additive and inert); re-cutover resumes with frozen snapshots intact.
7. **Old mobile compatibility:** clients registered during the canonical era hold canonical ids the old code cannot resolve — **documented contract: re-registration required after rollback (M-12)**; no device is deleted or misidentified; the map preserves traceability.
8. **Ledger preservation:** `notification_intents`/`attempts`/quarantine/legacy-refs are **never dropped** on rollback (additive objects are never auto-dropped; dropping requires a separate approved retention step).
9. **Verification queries:** zero canonical ids overlapping the asserted legacy boundary; reverse-sync counts reconcile (canonical count = legacy count + canonical-only count); API smoke tests on both endpoints.
10. **Irreversible/risk items (honest):** time elapsed on canonical registrations (mobile re-registration friction on rollback); quarantine metadata is authoritative only if source rows are still intact (they are — never deleted); **no destructive step exists in the design**; the only data-mis-assignment risk is merge ambiguity — mitigated by quarantine-all-conflicts.

---

# §19 Cutover sequence (enforceable deployment order)

| # | Step | Gate / write fence |
|---|---|---|
| 1 | Ship additive schema + new components **dormant** (flagged off) | zero behavior change |
| 2 | Read-only inventory script (M-2) | STOP if DB access unavailable (never assume) |
| 3 | Legacy-write freeze: adapters route all registration writes to DeviceRegistry (canonical); legacy tables receive no writes | code gate + grep audit; REVOKE option per D20 |
| 4 | Boundary bump (`ALTER … AUTO_INCREMENT = max(legacy)+1`) | only after freeze verified |
| 5 | Merge with quarantine (chunked; abort-safe) | zero-overlap query + count reconciliation |
| 6 | Activate unified dispatcher for Path A (flag on) | queue-topology tests + dedupe tests green (scratch DB — B-1) |
| 7 | Activate Path B adapter (delivery via PushDispatcher; DEC-C suppression; simulated honesty) | order_notifications contract tests green |
| 8 | Composer change (D6) with isolated dry-run evidence, full suite, sandbox live-fire (M-4) | recorded resolution required |
| 9 | Deprecation cleanup (delete `PushNotificationService`, inline deletes, dead code) — **post-cutover only**, separate reviewable change | rollback path remains until retention decision (D12) |

---

# §20 Sequence flows (Phase F; all agree with §7 machines and §4 ownership)

**F1 Normal order notification.** Checkout commits → `OrderCreated` (after-commit) → queued listener (high) → `$user->notify(UserOrderCreatedNotification)` → `SendQueuedNotifications` (high): database row (unchanged) → fcm: FcmChannel resolves payload + preference/locale → dispatch SendFcmNotificationJob (explicit high) → PushDispatcher: identity `transition/order`+scope → intent claim (UNIQUE) → DeviceRegistry.activeTokens → attempts created atomically → per attempt T2 claim → T3 marker (attempts+1) → FcmService.sendToClient (v1) → T4 provider_accepted (message id logged) → intent rollup completed. Observable: notifications row + ledger rows + accepted id.

**F2 Payment success (webhook).** Webhook verified → event (after-commit) with `webhook:{provider}:{event_key}` → listener → notify → same pipeline as F1. Gateway-callback site (OrderController:631) carries `txn:{transaction_id}`.

**F3 Payment failure (manual site).** Site resolves its persisted transaction row → `txn:{id}` identity → event (after-commit) → pipeline. If no persisted identifier can be resolved ⇒ **fail-closed**: intent `skipped(identity_unavailable)`, no send, alert log (M-6).

**F4 Order cancellation.** OrderService (unchanged) fires `OrderStatusChanged` + `OrderCancelled` with the same `transition:{id}` → B listener: preference gate ok → writes `order_notifications` row → status is A-owned ⇒ **send suppressed** (reason `owned_by_notification_channel`) → A listener → `UserOrderCancelledNotification` → DB+BR (unchanged) + fcm → dispatcher: scope `order.transition` + same identity ⇒ any second claim rejected by UNIQUE. **Exactly one push.**

**F5 Order delivery.** Same as F4 with `UserOrderDeliveredNotification`.

**F6 Coupon RabbitMQ notification.** Outbox → RabbitMQ (publisher confirms) → `NotificationRequestHandler` (guards unchanged) → `$user->notify(UserCouponEligibleNotification)` (`coupon-notify:{run}:{recipient}`) → pipeline → ledger records outcomes (never gates). Replay: guards converge; ledger UNIQUE prevents duplicate intents.

**F7 Marketing notification (after D8 approval).** Listener viaQueue medium → notification onQueue medium → BR job medium → FcmChannel sets job queue medium → dispatcher: Tier-2 gate (channel master AND event pref AND quiet hours) → send. Until approval: all layers stay on current queues (no silent change).

**F8 No registered device.** Dispatcher claim → zero active tokens → intent `skipped(no_devices)`, zero attempts (DEF-3) → no FCM calls; DB row + broadcast already delivered independently.

**F9 DB success + BR failure + FCM success.** DB row persists; broadcast job fails → `failed_jobs` + alert (broadcast channel independent); FCM attempt row completes `provider_accepted`. No cross-contamination; FCM ledger untouched by broadcast failure.

**F10 DB success + FCM failure.** DB row persists; attempt row cycles T2→T3→T5/T7 (bounded); terminal `failed(class)`; intent `failed` rollup. DB row never invalidated.

**F11 Duplicate event dispatch.** Two dispatches of the same event/identity/recipient/channel → second intent claim insert-or-ignore loses (UNIQUE dedupe_key) → counted `duplicate`, no second send. Concurrent inserts serialize on the unique key (one winner, loser no-ops).

**F12 Worker crash before FCM request.** Claim without marker → lease expires → T8 safe reclaim → retry. Possibly-unsent claim is reclaimable by design.

**F13 Worker crash after provider acceptance (response lost).** T7 ambiguous (sweeper if lease expired) → T10 one-shot flip (flag) → retry send (possible duplicate W2) → definite outcome or `failed(ambiguous_exhausted)`. Forensics: two accepted ids in `push.send.accepted`.

**F14 Stale worker after lease expiry.** Marker not yet written: T3 fence fails on reclaim (seq bumped) ⇒ worker aborts without sending. Marker already written: worker may still send (W1 duplicate — documented, at-least-once); its result write affects 0 rows (logged).

**F15 Device reassignment during delivery.** Pre-send validation (T12) catches owner≠recipient before marker ⇒ skipped(reassigned). Reassignment **during** the HTTP send: residual seconds-scale window (documented privacy residual); outcome writes stay on the old intent's attempt row; token-level invalid responses deactivate the token (correct for the new owner — O-4).

**F16 Transaction rollback.** Observer events without after-commit (DEF-11): blast may enqueue mid-transaction ⇒ ghost intent on rollback — **mitigation requires D19 approval** (add `ShouldDispatchAfterCommit`; timing change). With after-commit events (order/payment/coupon families — verified): no ghost; identity persisted in-transaction survives.

**F17 Invalid-token response after reassignment.** Response classified `invalid_token` → DeviceRegistry.markInvalid(token) — token-level, owner-independent (O-4): the token string is dead for everyone; the *new* owner's registration is deactivated (correct — the token is invalid), the *old* intent's attempt records `failed(invalid_token)` claim-fenced. No cross-intent corruption.

**F18 Rollback to legacy.** §18 sequence: reverse-sync → boundary re-assertion → flag-off or app rollback → legacy endpoints active again; canonical-era ids require mobile re-registration (M-12); ledger rows inert; re-cutover re-asserts boundary.

---

# §21 Observability and operations (M-11)

- Answerable: pending/stuck intents (status+age queries), expired claims (sweeper logs), ambiguous attempts (ledger), error classes (error_class), invalid tokens (device reason), retry exhaustion (terminal classes), duplicate suppression (claim-reject counter), simulated vs real (distinct ledger states), sweeper actions (logs), migration progress (runbook counts).
- Sweeper cadence: config, default every minute (lease reclaims T8, expired-attempted conversion T9, intent reconciliation); stuck-state alerts: `claimed` older than 2×lease, `dispatched` intents older than 1h, ambiguous backlog threshold.
- Dashboard counters by (status, error_class, client). Queue-depth metrics remain an external DevOps item (R-2).
- Provider-accepted ≠ delivered ≠ displayed ≠ opened — enforced vocabulary; no delivered state claimed.

---

# §22 What is retained / replaced / retired

**Retained:** Laravel notification classes + payload authority; database/broadcast channels and contracts; Path C pipeline (transport + guards); order_notifications contract; preference model; API envelopes; Marvel mail path (untouched); queue workers/topology (pending D8); device API envelopes.
**Replaced (internals):** Path A delivery internals (swallow → classifier+ledger+rethrow); Path B delivery internals (PushNotificationService direct-FCM → shared PushDispatcher); invalid-token handling (inline delete → DeviceRegistry); queue selection (V-16 read → explicit property); FCM multicast transport (retired batch endpoint → v1 per-message via D6 options).
**Retired (as delivery authorities, post-cutover):** `PushNotificationService` delivery role (class deleted in cleanup; its `registerDevice` dead method goes with it); dual-registry write path for sends; raw token snapshots; inline token deletion in SendFcmNotificationJob.

---

# §23 Open items and acceptance gates

The design is **CONDITIONALLY-READY**. It cannot be declared APPROVAL-READY until:
1. **B-2** — explicit user approval of this design + the decision register (D1–D26; §decision-register doc).
2. **DEF-14/B-3** — Composer graph resolution recorded via the isolated dry-run procedure (V-21 pin conflict makes A2 a three-file change; A1/B remain fallbacks).
3. **B-1** — scratch database available (all tests; nothing is Tested today).
4. **D19** — ghost-blast guard decision (ShouldDispatchAfterCommit on observer events — timing change approval).
5. **D20** — legacy-write enforcement mechanism choice (REVOKE vs code-gate default).
6. **D18** — client-dedupe contract adoption (mobile).
7. **M-4/B-6** — sandbox Firebase live-fire (error-taxonomy + endpoint verification).
8. **PO decisions** D8/D9/D10/D11/D12/D13/D24/D25 defaults.
9. **Cutover-time greps:** Marvel `OrderDelivered` dispatch reality (§10.2); every DEF-6 site resolves a persisted transaction row.

No item above is silently assumed. Every P0 has a technically complete resolution in this document (closure matrix: `NOTIFICATION_UNIFICATION_DEFECT_CLOSURE_MATRIX.md`).

---

**STOP — design candidate only. No implementation is authorized by this document. Awaiting explicit user review and approval.**
