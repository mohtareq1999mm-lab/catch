# Notification System Unification — PHASE 2.2 Corrected Design

**Status:** Read-only design phase. No code, migrations, configuration, Composer files, tests, or data modified. Original PHASE 2 and PHASE 2.1 documents preserved unchanged.
**HEAD:** `2f258c05f797cd8024d174da870755f08b08f6c0` (unchanged; re-verified before and after).
**Purpose:** resolve the ten P0 defects identified in the independent review of PHASE 2.1, correct the dependent P1 items, and present the corrected design for the mandatory approval gate.

**Classification discipline:** design mechanisms are labeled by their evidence status; **nothing in this document is Tested** (nothing executed; database unreachable — reported as blocker B-1, not bypassed).

---

# 1. Baseline (re-verified this session)

| Item | Value | Evidence |
|---|---|---|
| HEAD / branch / tree | `2f258c05…` / main / 4 untracked docs only | git status ×2 (before/after) |
| PHP | 8.2.30 | php -v |
| Laravel | 10.30.1 | composer.lock |
| kreait/firebase-php | 6.7.0 (+ kreait/firebase-tokens ^3.0); **no kreait/laravel-firebase** (direct SDK usage only) | composer.lock:3889, 3914 |
| guzzlehttp/guzzle | **pinned exactly 7.8.0 in composer.json** | composer.json require |
| Database | Unreachable (`SQLSTATE[HY000] [2002]`) | `php artisan migrate:status` — **blocker B-1** |
| Prior docs | 3 preserved + this document + TODO board | git status |

# 2. Change log vs PHASE 2.1 (defects owned explicitly)

| Defect | What 2.1 said | Defect | Correction (section) |
|---|---|---|---|
| DEF-01 (P0-01) | §5 rule 4: a token group with a *uniquely newest* `last_used_at` row was migrated **active** | Timestamp-only ownership inference | Any multi-row group → deactivated + quarantine; only single-source single-row groups migrate active (§3.1) |
| DEF-02 (P0-02) | §6.3 lease states did not separate "claimed, never sent" from "possibly transmitted" | Expired possibly-sent attempt could be reclaimed as pending | Pre-send `attempted` marker; state-specific lease-expiry semantics (§4.3) |
| DEF-03 (P0-03) | §6.5 lease CAS only | A worker that lost its lease could still write outcomes (last-writer-wins) | Monotonic `claim_seq` fencing on every outcome write (§4.4) |
| DEF-04 (P0-04) | §6.4 retry sketch: `WHERE intent_id=? AND status IN(...) OR (status='ambiguous' …)` | **Boolean precedence bug** — OR bypasses intent scoping and selects other intents' attempts | Canonical query with explicit grouping; all state SQL written grouped (§4.5) |
| DEF-05 (P0-05) | §6.6 "ambiguous retried once after cool-down" without persistent enforcement | Retry could be consumed repeatedly under worker crashes / sweeper interference | `ambiguous_retry_used` flag + one-shot CAS + terminal `ambiguous_exhausted` (§4.6) |
| DEF-06 (P0-06) | §6.1 dedupe key = sha256(event_identity \| **notification_type** \| recipient \| channel) while §7 simultaneously claimed the same transitionId suppresses the cancel/status double push | **Contradiction** — the type component makes cross-class suppression impossible | Per-class `dedupeScope()`; suppression works within a declared scope; distinct events stay distinct (§5.2) |
| DEF-07 (P0-07) | §7 coupon identity included `tree_hash` | Content-derived identity — a content change would mint a new event | Identity = `coupon-notify:{run_id}:{recipient_id}` (persisted PKs); tree_hash demoted to guard-only (§5.3) |
| DEF-08 (P0-08) | §13.4 classified by exception class alone | Kreait wraps response-less transport errors into generic `MessagingError` (vendor :119) — class-only classification could claim definite pre-send failure without evidence | Phase-aware classification with exception-chain walk; conservative ambiguous default (§6) |
| DEF-09 (P0-09) | §12 DELETE resolution "canonical id first, else legacy map" | **Numeric collision**: both tables use independent bigint sequences; old id 5 could resolve to an unrelated canonical device 5 | Legacy-space DELETE semantics + namespace separation via AUTO_INCREMENT bump + additive `device_uuid` (§7) |
| DEF-10 (P0-10) | §14 recommended `^7.24.1` without resolution evidence against pinned deps | **guzzle pin conflict**: 7.24.x requires `^7.9.2`, project pins exactly 7.8.0 | Resolution-verified options; recommendation updated; composer gate (§8) |

# 3. Corrected DEC-A — canonical registry and fail-safe merge (P0-01)

## 3.1 Revised merge algorithm

Same source model as 2.1 (group all `device_tokens` + `user_device_tokens` rows by token string). **Revised rules:**

1. **Single-source, single-row, user exists** → migrate **active** (no inference involved — the row itself is the ownership record).
2. **Any group with more than one source row** (regardless of timestamps, sources, or uniqueness of the maximum): canonical row created **deactivated** — `is_active=false`, `deactivated_reason='migration_quarantine'`, `merge_verified=false`; owner column = deterministic ordering (NULLS-LAST on `last_used_at`, then source priority, then id — **cosmetic only**; the row can never receive pushes); **every** source row copied to quarantine.
3. **Orphaned users** (no surviving `users` row): quarantine only, no canonical row.
4. **Reactivation:** exclusively by a fresh authenticated registration through either API, which performs the standard global-unique **reassignment** (an explicit user action — not an inference).
5. Runtime registration of an already-existing token: reassigns ownership and activates — this is the proven Path A semantic (FcmIntegrationTest:59-69) and is **not** timestamp-based.
6. Idempotence: repeated migration runs produce identical canonical state (deterministic ordering, quarantine keyed by source ids).

**Acceptance:** no token is activated for any user because of a newer timestamp. VERIFIED-by-construction; production data shape remains **Unknown** (M-2).

# 4. Corrected delivery lifecycle — claim, send, ambiguity, fencing, queries (P0-02/03/04/05)

## 4.1 Tables (unchanged names, revised columns)

- `notification_intents`: id, dedupe_key (unique with channel), channel, recipient_id, notification_type (presentation record), **dedupe_scope**, event_identity, status(queued/dispatched/completed/failed/skipped), skip_reason, timestamps.
- `notification_device_attempts`: id, intent_id FK, device_token_id FK nullable, client, token_snapshot, status, provider_message_id, error_class, error_message, attempts, **claim_seq** (unsigned bigint, monotonic), claimed_by, claimed_until, **ambiguous_retry_used** (bool default false), **cool_down_until** (nullable), timestamps. UNIQUE(intent_id, device_token_id); index (status, claimed_until); index (device_token_id).

## 4.2 Creation and crash recovery (P1-1)

The dispatcher transaction: claim intent (insert-or-ignore on the unique key) → insert attempt rows (insert-or-ignore on unique(intent, device)). Crash before commit ⇒ nothing persisted ⇒ next job run re-executes cleanly. Crash after commit ⇒ rows exist; job continues/next run resumes from states. No half-created intent with missing attempts can cause a send (sends only happen on attempt rows under lease).

## 4.3 Attempt state machine with crash windows (P0-02)

```
pending ──claim CAS──► claimed(claim_seq++, claimed_by, claimed_until)
claimed ──pre-send marker (BEFORE HTTP initiation)──► attempted(sent_phase=open)
attempted ──definite outcome──► provider_accepted | failed(class)
attempted ──definite-not-transmitted (ConnectException / pre-send auth failure)──► pending (retryable)
attempted ──cannot rule out transmission──► ambiguous (+cool_down_until)
claimed  ──lease expiry (NO attempted marker)──► pending  [SAFE RECLAIM — never sent]
attempted ──lease expiry / crash──► ambiguous            [NOT reclaimable as pending]
ambiguous ──one-shot CAS──► pending (ambiguous_retry_used=1) → outcome or failed(ambiguous_exhausted)
simulated (provider disabled) → terminal simulated
skipped → recorded on the intent row (reason), no attempts created
```

Worker-crash windows (each with consequence):
1. Crash before claim → nothing; safe.
2. Crash after claim, before pre-send marker → lease expiry → **safe reclaim** (acceptance: possibly-unsent claim reclaimed).
3. Crash after pre-send marker, before/during/after HTTP → the attempt may or may not have been transmitted → **ambiguous** (conservative; single cool-down retry per DEF-05). This satisfies: *a possibly transmitted attempt must not be treated as an unsent claim*.
4. Crash after definite outcome write → persisted; safe.

## 4.4 Fencing (P0-03)

Every outcome write is conditional on the claim the worker holds:
`UPDATE notification_device_attempts SET status=?, … WHERE id=? AND claim_seq=?` (the seq captured at claim). Claim CAS increments `claim_seq`; a superseded worker's write matches 0 rows (logged at debug). Ownership verification: writes additionally carry `AND claimed_by=?`. Concurrent-worker tests (T-A2/A3 matrix) prove stale writes never modify current state. **Acceptance met.**

## 4.5 Retry-selection query — canonical form (P0-04)

**Owned 2.1 defect:** the revision sketch `WHERE intent_id=? AND status IN ('pending') OR (status='ambiguous' AND …)` — in SQL, `AND` binds tighter than `OR`, so the second disjunct matched **other intents'** ambiguous rows.

Canonical queries (MySQL 8, verified precedence rules; explicit parentheses mandatory and enforced in code review):

```sql
-- retry selection for one intent's job run
SELECT id FROM notification_device_attempts
 WHERE intent_id = :intentId
   AND (status = 'pending'
        OR (status = 'ambiguous'
            AND ambiguous_retry_used = 0
            AND cool_down_until IS NOT NULL AND cool_down_until <= :now));

-- lease reclaim (safe: never-sent claims only)
UPDATE notification_device_attempts
   SET status='pending', claimed_by=NULL, claimed_until=NULL
 WHERE intent_id = :intentId
   AND status='claimed' AND claimed_until < :now;   -- 'attempted'/'ambiguous' rows are structurally excluded

-- ambiguous one-shot flip (P0-05)
UPDATE notification_device_attempts
   SET status='pending', ambiguous_retry_used=1, attempts=attempts+1
 WHERE id=:id AND status='ambiguous' AND ambiguous_retry_used=0;
```

Query-isolation tests (T-A6): with two intents and mixed attempt states, the selection for intent X must return only X's rows (proven by seeding a foreign intent's eligible ambiguous row).

## 4.6 Ambiguous retry limit (P0-05)

Exactly one ambiguous retry per attempt row, enforced by `ambiguous_retry_used` (persistent state) consumed via the CAS above; the sweeper **never** writes `ambiguous_retry_used=0` (no code path does). Outcomes after the retry: `provider_accepted` / `failed(class)` / **`failed(ambiguous_exhausted)`** (terminal, second ambiguity). Repeated worker crashes cannot consume the retry twice (CAS) and cannot reset it (no reset path). Tests: repeated crashes + sweeper runs (T-A4).

## 4.7 Lease / timeout / retry_after invariant (P1-5)

- Attempt lease = `retry_after + 60s` (1800+60 = 1860s) — longer than any framework re-release window so a live or framework-retrying job can never race a reclaim.
- `SendFcmNotificationJob::$timeout = 1200` (explicit; ≤ worker timeout 1300 pinned by WorkerConfigPolicyTest:47; < retry_after 1800 per config/queue.php documented invariant).

# 5. Corrected event identity and deduplication (P0-06/07)

## 5.1 Identity sources (unchanged from 2.1 §7 where verified)

Order transitions: `transition:{transitionId}` minted at OrderService, persisted in `OrderTrackingEvent.metadata` in-transaction (synchronous listener verified), carried as plain-string event properties. Payment webhooks: `webhook:{provider}:{event_key}` (persisted `payment_webhook_events` UNIQUE key). COD completion: shares the order transitionId. Refund: `refund:{refund PK}`. Cart: claim-based `cart:{id}:reminder`. Admin login: `login:{sanctum_token_id}` (token created at UserController:565 before dispatch :571). Contact: `contact:{contact PK}`. Observer events (promotion/flash-sale/product): payload-frozen UUID minted in the synchronous observer.

## 5.2 Dedupe scope — correcting the 2.1 contradiction (P0-06)

**Owned defect:** the 2.1 key included `notification_type`, which makes the claimed cross-class suppression (cancel: A-notification vs B-push) impossible — the two intents would have different keys and both would send.

**Correction:** every push-emitting notification class declares:

- `eventIdentity(): string` — the §5.1 identity (default: throw/require override per class family).
- `dedupeScope(): string` — the suppression scope. Default = `"type:{classBasename}"` (each class independently suppresses its own duplicates). Classes that must share suppression declare a common scope: the cancel/deliver pair (A `UserOrderCancelledNotification`, `UserOrderDeliveredNotification`) and Path B's order-status push for those statuses declare `scope = "order.transition"` — same eventIdentity (the same transitionId), same scope ⇒ the second intent's claim is rejected by the unique key ⇒ exactly one push.

Distinct legitimate events remain distinct because either the eventIdentity differs (payment webhook id vs order transitionId) or the scope differs (`payment.event` vs `order.transition` — DEC-J status quo preserved: payment-succeeded push and order-completed push both deliver until the Product Owner decides otherwise).

Intent dedupe key = `sha256(dedupe_scope | event_identity | recipient_id | channel)`. The `notification_type` is stored on the intent (presentation record) but is **not** part of the key.

Tests (T-A7/T-A8): same scope+event ⇒ second claim rejected; distinct scope ⇒ both deliver; distinct event same scope (not currently designed — documented as invalid usage pinned by test).

## 5.3 Coupon identity (P0-07)

`event_identity = "coupon-notify:{run_id}:{recipient_id}"` — both are persisted PKs carried by the envelope (verified keys: NotificationRequestHandler.php:37). `tree_hash` is removed from the notification identity entirely; it remains a business field consumed exclusively by the existing durable guards (Guard 1/2), which are preserved untouched (RabbitMQ transport preserved). Content changes (title/body/translation) therefore cannot mint a new business event. Acceptance met; pipeline replay behavior unchanged (its own guards continue to converge).

# 6. Corrected error classification — transmission-phase aware (P0-08)

Vendor facts (verified this session): `ConnectException extends TransferException implements NetworkExceptionInterface` (connection never established ⇒ request not transmitted); `BadResponseException extends RequestException` with a guaranteed response; Kreait's `MessagingApiExceptionConverter` converts **responses** into typed exceptions (`AuthenticationError` :73, `NotFound` :77, `QuotaExceeded` :81, `ServerError` :90) and wraps **response-less** Guzzle errors into generic `MessagingError` (:111-119) — so the transport phase must be recovered from the exception chain (`getPrevious()`), not from the Kreait class alone.

| Phase (provable?) | Evidence | Classification | Action |
|---|---|---|---|
| Pre-send: credential/OAuth acquisition failure | Occurs before any HTTP send | **definite-not-transmitted** | attempt → pending (retryable); if persistent → auth_config terminal via `fail($e)` |
| Pre-send: `ConnectException` (chain) | Connection/TLS never established; HTTP request never sent | **definite-not-transmitted** | attempt → pending (retryable) |
| Response received: 4xx/5xx mapped by Kreait converter | Converter saw a response | **definite outcome** per class (invalid_token / invalid_payload / auth_config / quota / server) | per §10 policy |
| Response received: `BadResponseException` unconverted | Has response | definite outcome by status | mapped accordingly |
| No response, phase unknown: response-less `RequestException`/`TransferException`, read timeouts, and Kreait's generic `MessagingError` wrapping them | Transmission cannot be ruled out | **ambiguous** (conservative) | §4.3/4.6 ambiguous handling |

**Acceptance:** the system never records a definite pre-send failure without transmission-phase evidence. VERIFIED against vendor source; runtime behavior of each class remains sandbox-live-fire gated (M-4).

# 7. Corrected legacy-ID compatibility and rollback (P0-09)

**Owned defect:** 2.1's "canonical id first, else legacy map" is ambiguous — both id spaces are independent bigint sequences and can numerically collide.

**Verified contract facts:** Path A DELETE is token-string based (DeviceTokenController.php:42-46) — the numeric-DELETE contract belongs exclusively to the `user_device_tokens` world; `registerDevice` returns the full model including `id` (NotificationPreferencesController.php:74) and `unregisterDevice` deletes by it (:77-90).

**Correction — namespace separation (no API break):**
1. During migration, `ALTER TABLE device_tokens AUTO_INCREMENT = max(user_device_tokens.id) + 1` (single DDL statement) ⇒ canonical ids are **strictly greater** than every legacy id from that point.
2. `DELETE /api/v1/user/devices/{deviceId}` resolution order (owner-scoped throughout): (a) `device_token_legacy_refs.legacy_id → canonical` (covers all legacy ids ≤ legacy-max), else (b) canonical `device_tokens.id` (only reachable for ids > legacy-max, which no legacy row can have). An old id can therefore never resolve to a different canonical device through collision.
3. `registerDevice` response gains an additive `device_uuid` field (canonical UUID) — backward-compatible addition for future clients; the numeric `id` remains legacy-compatible by rule 1-2.
4. Reverse migration (rollback): canonical-only registrations (created post-migration, no legacy ref) receive **new** legacy ids in `user_device_tokens` on rollback; mobile clients that registered during the canonical era hold canonical ids that the rolled-back code cannot resolve — documented compatibility boundary requiring mobile coordination (re-register after rollback). No device is deleted or misidentified; the mapping table preserves full traceability.
5. Migration of a token already present in both tables: treated as one token group under §3.1 (all rows quarantined unless single-source-single-row).

**Acceptance:** old identifiers can never resolve to a different canonical device through numeric collision. Design VERIFIED; production id distribution **Unknown** (M-2) — the AUTO_INCREMENT bump is computed from actual data at migration time.

# 8. Corrected DEC-I — Composer-resolution-verified SDK strategy (P0-10)

**Resolution facts (composer.json of exact tags, fetched this session):**

| Version | php constraint | guzzle constraint | Notes |
|---|---|---|---|
| 6.7.0 (installed) | ^7.4\|^8.0 era | (6.x-era) | batch endpoint (retired) |
| **7.15.0** | `~8.1.0 \|\| ~8.2.0 \|\| ~8.3.0 \|\| ~8.4.0` ✓ | **`^7.5` ✓ compatible with pinned 7.8.0** | async v1 multicast since 7.5; `firebase/php-jwt ^6.3.2` — **lacks the CVE-2025-4659 remediation** (Messaging-only usage lowers exposure) |
| **7.24.1** | `~8.1.0 \|\| ~8.2.0 \|\| ~8.3.0 \|\| ~8.4.0 \|\| ~8.5.0` ✓ | **`^7.9.2` ✗ — conflicts with pinned 7.8.0** | includes `firebase/php-jwt ^6.10.2 \|\| ^7.0.2` security remediation; adds `cuyz/valinor` transitively |
| 8.6.0 (latest 8.x) | `~8.3.0 \|\| ~8.4.0 \|\| ~8.5.0 \|\| ~8.6.0` | — | **excluded — requires PHP ≥ 8.3** |

**Revised recommendation:** **Option A2 — `kreait/firebase-php ^7.24.1` together with `guzzlehttp/guzzle ^7.9.2`** (both composer.json changes are one approval gate; guzzle 7.8.0→7.9.2 is a patch-line minor bump; resolution must be verified with `composer update --dry-run` during implementation before lock change). **Option A1 (fallback):** `^7.15.0` alone — zero other dependency changes, compatible with the existing pin, but ships without the 2025 JWT CVE remediation (exposure limited to Messaging usage; Auth unused — grep-verified). **Option B (last resort):** remain on 6.7.0 with a per-token `send()` loop (v1 endpoint verified healthy in vendor). **No version requiring PHP 8.3 is proposed anywhere** — acceptance met. The full-suite + sandbox live-fire gates from 2.1 remain prerequisites for whichever option is approved.

# 9. P1 resolutions (evidence recorded)

| Item | Resolution |
|---|---|
| fail($e) | **Verified**: vendor `InteractsWithQueue.php:50` — `ManuallyFailedException`; manual failure does not consume retries and triggers `failed()` |
| order_notifications.failure_reason | **Verified**: migration :29 `text('failure_reason')`; `markAsSkipped()` writes it |
| Lease/timeouts | §4.7; worker flags pinned by WorkerConfigPolicyTest |
| Cross-project tokens | Client enum enforced at registration; wrong-project token → send-time NotFound → deactivated (correct); registration-time project binding = future mobile-dependent item |
| Retention/replay | Ledger 90d (config); post-retention replay creates a new intent (documented); coupon guards independent and durable |
| Queue topology | §10 — 4-layer matrix re-verified |
| Worker locale | Push locale from preference only (never `App::getLocale()`); verified writer hazard flagged: marvel `NewOrderReceived.php:51` `App::setLocale` (separate fix recommendation) |
| Legacy-id consumers | **Unknown** — mobile confirmation (M-3); design is id-usage-agnostic (both resolution branches owner-scoped) |
| Intent/attempt crash recovery | §4.2 |
| Token race lookup→send | Attempts snapshot the token string; provider truth is token-level; post-reassignment outcome still recorded against the intent's attempt row; token row itself is never mutated by the send path (only invalid-marking, which is token-level and correct) |

# 10. Corrected queue topology (unchanged from 2.1 §8, re-verified)

All four layers verified on `high` for marketing types this session (39 viaQueue matches). Marketing types move **listener + notification + BroadcastMessage + FCM-job** to `QueueName::medium()`; the FCM job gains an explicit queue property set by `FcmChannel` from the notification category. Per-layer tests are release gates. Transactional layers untouched.

# 11. Corrected preference/locale policy (unchanged core, clarified)

Tier 0 (channel master `push_enabled`) / Tier 1 (transactional) / Tier 2 (marketing: channel master **and** event preference; quiet hours config-gated) / Tier 3 (unsuppressible — **no existing type assigned**; requires explicit Product-Owner approval). Single shared helper used by `FcmChannel` and the Path B listener. Push locale = recipient `notification_language` (never global read). Marketing list remains **pending PO approval** — until approved, all existing notifications keep today's behavior (transactional default).

# 12. Corrected file-by-file implementation map (scope-frozen at approval)

Create: `DeviceRegistry`, `PushDispatcher`, `FcmErrorClassifier` (phase-aware), `NotificationDelivery` (intents+attempts models/migrations), `device_token_legacy_refs` migration, `device_tokens` additive-columns migration (incl. AUTO_INCREMENT bump step), `SweepStaleDeviceTokens` (report-only default), preferences helper, `config/notifications.php`. Modify: `FcmService` (throw-through), `SendFcmNotificationJob` (envelope + queue property + timeout), `FcmChannel` (gate + locale + scope), both registration controllers (delegate to DeviceRegistry; id resolution §7), `DeviceToken` model, `SendOrderPushNotification` (dispatcher + A-owned suppression), `UserController::logout` (flag-gated optional body field, default no-op), marketing listeners + classes (queue + scope declarations), events (transitionId/eventId/paymentEventId/token_id plain properties). Remove (post-cutover only): `PushNotificationService`, `UserDeviceToken` (table retained until retention decision). Untouched: OrderService business logic (transitionId minting is additive), SMS/email/mail/broadcast/database channels, Marvel mail-only notifications, RabbitMQ coupon pipeline internals, all API envelopes.

# 13. Migration / rollback / compatibility (summary; details per 2.1 §14 as corrected)

Additive-only schema; single-statement DDL steps; post-migration schema assertions; AUTO_INCREMENT namespace bump computed from actual data; quarantine table preserves all source rows; **canonical-writes-only** compatibility period; rollback = app rollback **plus mandatory reverse-sync** (legacy rows recreated for canonical-only registrations; quarantined rows re-imported verbatim; nothing deleted); old clients work pre/post migration (§7); downtime not claimed — assessed after M-1/M-2 evidence.

# 14. Decision table (final, for the approval gate)

| Decision | Recommendation | Category | Approval |
|---|---|---|---|
| D1 Canonical registry + fail-safe merge (§3) | Evolve `device_tokens`; quarantine-ambiguous; no timestamp activation | Engineering | **Required** |
| D2 Delivery lifecycle (claims/leases/fencing/ambiguity) (§4) | Two-level ledger + pre-send marker + claim_seq fencing + one-shot ambiguity retry | Engineering | **Required** |
| D3 Event identity + dedupe scopes (§5) | transitionId/webhook-key/PK identities; per-class dedupeScope | Engineering | **Required** |
| D4 Error classification (§6) | Phase-aware; conservative ambiguous default | Engineering | **Required** |
| D5 Legacy-ID namespaces (§7) | AUTO_INCREMENT separation + legacy-map-first + `device_uuid` | Engineering | **Required** |
| D6 SDK (§8) | **A2: kreait ^7.24.1 + guzzle ^7.9.2** (A1: ^7.15.0-only fallback; B: no-upgrade fallback) | Engineering + **Composer gate** | **Required (both changes or chosen alternative)** |
| D7 Queue topology (§10) | Marketing → all four layers medium | Engineering | **Required** |
| D8 Preferences tiers (§11) | Tier 0/1/2 now; Tier 3 none by default | Mechanism: engineering; **marketing list + Tier 3: Product Owner** | **Required (mechanism)** |
| D9 Logout | Default OFF; current-device logout requires mobile contract; deactivate-all rejected | **Product Owner + Mobile** | **Required (default)** |
| D10 Payment/completed overlap (DEC-J) | Keep both pushes (status quo) | **Product Owner** | **Required** |
| D11 Sweeper | Report-only default; provider-confirmed invalidity only | Engineering default; enforcement: **Product Owner** | **Required (default)** |
| D12 Retention | Ledger 90d; legacy tables retained until separate decision | Engineering default; period: **Product Owner** | **Required (default)** |
| D13 Simulated enum | No schema enum change; `simulated` lives in the ledger; `order_notifications` gets `skipped`+reason | Engineering | **Required** |
| D14 Mobile contracts | `device_uuid` additive field; logout body field (flag-gated, no-op default) | **Mobile + Product** | **Required** |

**Product-Owner decisions outstanding (mission §5):** D9 default · D8 marketing list · Tier-3 semantics · D10 · D11 enforcement · D12 period · D13 · any mobile-contract changes. Each carries options + impact in the sections above; **none is assumed approved**.

**Mobile/DevOps dependencies:** M-3 (endpoints, unregister-on-logout, refresh cadence, legacy-id usage), logout contract, `device_uuid` adoption · M-1/M-2 (env + DB inventory + DDL assessment) · M-4 (sandbox live-fire) · M-6/M-7 (queue metrics, RabbitMQ status).

**Blockers:** B-1 DB unreachable (all tests; scratch DB required) · design approval (this gate) · composer approval (D6) · PO decisions (above).

---

# 15. PHASE 2.2.3 CORRECTIONS — final design fixes (read-only review round)

Each correction below supersedes the referenced 2.2 subsection. Evidence status is stated per item; **nothing is Tested**.

## 15.1 C-1 — `ConnectException` does not prove non-transmission (supersedes §6 rows)

**Evidence (vendor, verified this round):** `vendor/guzzlehttp/guzzle/src/Handler/CurlFactory.php:163-169` — `static $connectionErrors` maps **`CURLE_OPERATION_TIMEOUTED` (28), `CURLE_COULDNT_RESOLVE_HOST` (6), `CURLE_COULDNT_CONNECT` (7), `CURLE_SSL_CONNECT_ERROR` (35), `CURLE_GOT_NOTHING` (52)**; lines 208-210 construct `ConnectException` for **any** of these. Therefore:
- errno **6 / 7 / 35** → connection or TLS handshake never completed ⇒ HTTP request never transmitted ⇒ **definite-not-transmitted** (safe retry).
- errno **28** (operation timeout) → **ambiguous** — the timeout may occur in the connect phase *or* while awaiting the response after the request was sent; Guzzle does not distinguish.
- errno **52** (got nothing / empty reply) → **ambiguous** — the server may have received and processed the request before closing without responding.
- No errno / other errnos / response-less `RequestException` / bare `TransferException` / unknown → **ambiguous** (conservative default).

**Revised classification (supersedes 2.2 §6):** the classifier extracts the transmission phase from, in order of precedence: (1) pre-send failures (credential/OAuth acquisition, message construction — provably before any HTTP send); (2) `ConnectException::getHandlerContext()['errno']` (fallback: parse `cURL error N:` from the message — Kreait wraps response-less errors into generic `MessagingError`, vendor `MessagingApiExceptionConverter.php:111-119`, so the **exception chain must be walked**); (3) a present response (`BadResponseException` / Kreait converter output ⇒ definite outcome by status/class). **Ambiguous is the conservative fallback whenever transmission cannot be ruled out.** The 2.2 §6 table's "ConnectException → definite-not-transmitted" row is voided and replaced by the errno-based rule above.

## 15.2 C-2 — `claim_seq` fencing for **every** state transition (extends §4.4)

Monotonic invariant: `claim_seq` is never decremented or reset; every state-changing write carries explicit status/seq/owner guards and affects **0 rows** when superseded (logged at debug). Full matrix:

| Transition | Guard (all rows of `notification_device_attempts`) | `claim_seq` effect |
|---|---|---|
| Claim (from `pending`, or lease-expired `claimed`) | `status='pending' OR (status='claimed' AND claimed_until < :now)` | **bumped** (atomic read via `SET claim_seq = LAST_INSERT_ID(claim_seq+1)` then `SELECT LAST_INSERT_ID()`) |
| Pre-send `attempted` marker | `id=:id AND claim_seq=:mine AND claimed_by=:worker AND status='claimed'` | not bumped — **this write is the final fence executed BEFORE HTTP initiation; if it affects 0 rows the worker MUST NOT send** |
| Result write (`provider_accepted` / `failed(class)` / `ambiguous`) | `id=:id AND claim_seq=:mine AND claimed_by=:worker AND status='attempted'` | not bumped |
| Lease-expiry reclaim (`claimed` → `pending`) — lazy or sweeper | `status='claimed' AND claimed_until < :now` | **bumped**, `claimed_by/claimed_until` cleared |
| Expired-`attempted` conversion → `ambiguous` (sweeper) | `status='attempted' AND claimed_until < :now` | **bumped**, `cool_down_until` set — a straggler worker's later result write then fails on seq (its result is logged and lost; the row is conservative-ambiguous) |
| Ambiguous one-shot retry flip → `pending` | see C-3 | **bumped**, claim ownership cleared |

Sweeper restrictions: it **never** writes results, never touches `ambiguous_retry_used`, and never modifies rows in terminal states (`provider_accepted`, `failed`). The marker-as-final-fence additionally closes the send-after-supersede window: a worker whose claim was reclaimed (status `pending`, seq unchanged) or re-claimed (seq bumped) fails the marker write and aborts without sending.

## 15.3 C-3 — Ambiguous one-shot CAS corrected (supersedes §4.6 sketch)

```sql
UPDATE notification_device_attempts
   SET status = 'pending',
       ambiguous_retry_used = 1,
       claim_seq = claim_seq + 1,
       claimed_by = NULL, claimed_until = NULL,
       attempts = attempts + 1
 WHERE id = :id
   AND status = 'ambiguous'
   AND ambiguous_retry_used = 0
   AND cool_down_until IS NOT NULL
   AND cool_down_until <= :now;
```

All four conditions are required (status, unused flag, expired cool-down, and the row-lock CAS itself as the concurrency control — affected-rows==1 wins; concurrent workers serialize). The seq bump invalidates any stale claim. If the retried send is ambiguous again ⇒ `failed(ambiguous_exhausted)` (terminal). The sweeper has **no code path** that clears `ambiguous_retry_used`. Repeated crashes cannot consume the retry twice.

## 15.4 C-4 — Legacy-write shutdown and namespace re-assertion (extends §7)

**Owned defect:** the one-time AUTO_INCREMENT bump is only valid while legacy tables receive **no** INSERTs; a rollback that resumes old code would re-allocate legacy ids past the bump and collide with canonical ids.

**Correction — enforced invariant + runbook:**
1. During the canonical-writes-only period, **no application code path writes legacy tables** (adapters write canonical only). Enforcement: grep gate in the implementation audit (no INSERT/update on `user_device_tokens` outside the migration scripts).
2. The migration refuses to run if `legacy AUTO_INCREMENT ≥ canonical AUTO_INCREMENT` at start (re-run safety) and records the asserted boundary in the migration history.
3. **Rollback runbook mandatory step (before reactivating any legacy-writing code path):** recompute `boundary = max(legacy_max, canonical_max) + 1` → `ALTER TABLE device_tokens AUTO_INCREMENT = boundary` (idempotent, scripted). Same re-assertion on forward re-cutover. DELETE-by-id resolution uses the **current asserted boundary**: ids ≤ boundary−(legacy range) resolve via the legacy map; ids above it are canonical-only.
4. Post-rollback verification check: zero canonical ids overlapping the legacy id range (runbook query); violation = STOP and reconcile.
5. Mobile compatibility unchanged (owner-scoped resolution both branches; §7).

## 15.5 C-5 — MySQL nullable unique-key resolution

**Evidence (external source identified):** MySQL 8.0 Reference Manual §17.6.2.1 (InnoDB index characteristics — Unique: "Permits Multiple NULL Values: **Yes**") and §15.1.15 ("A UNIQUE index permits multiple NULL values for columns that can contain NULL").

**Correction:** `notification_device_attempts.device_token_id` is **NOT NULL** FK. Tokens are deactivated, never hard-deleted, in the unified design (DEC-A §3, DEC-K §15 of 2.1; rule 11), so the nullable case does not arise; `UNIQUE(intent_id, device_token_id)` then fully guarantees one attempt row per device per intent. The `token_snapshot` string is retained for auditability. **Conditional:** if a future Product-Owner decision ever approves token hard-deletion, the retention migration must first restructure attempt references (e.g., archive rows or add a token-hash surrogate key) — explicitly out of approved scope until decided.

## 15.6 C-6 — Frozen target-device snapshot (extends §4.2)

Attempt rows are created **in the same transaction as the intent claim**; the attempt-row membership **is** the frozen target snapshot. On any replay/retry the dispatcher loads **only existing attempt rows** for the intent — it never re-queries `DeviceRegistry`. A device registered after intent creation is never added to that intent (it will be included in future intents). The atomic creation removes the crash window that would otherwise force re-derivation. Test: register a device between intent creation and job retry ⇒ no new attempt row (T-A9 in the matrix addendum).

## 15.7 C-7 — Dedupe test matrix corrected (supersedes §5.2 test wording)

| event_identity | dedupe_scope | Expected outcome | Test ID |
|---|---|---|---|
| same | same | second claim **rejected** (suppression — e.g., cancel transition's A-notification vs B-push) | T-A7 |
| **different** | same | **both intents created** (allowed — e.g., two distinct order transitions sharing `order.transition` scope) | T-A8a |
| same | **different** | **both intents created** (allowed — e.g., payment push vs order-completed push; DEC-J) | T-A8b |

The prior wording ("distinct scope ⇒ both deliver") is superseded by this explicit three-case matrix.

## 15.8 C-8 — Normal retry policy for definite-not-transmitted failures (extends §4)

- `attempts` counter semantics: incremented **at the pre-send marker** (counts genuine transmission opportunities) — claims and lease reclaims do not increment it.
- Maximum normal attempts per device per intent: **3** (aligned with `SendFcmNotificationJob: tries=3, backoff=[30,120]`).
- The lease/claim path refuses new claims when `attempts >= 3` ⇒ terminal; the job-level `tries` remains a belt-and-braces second guard.
- Terminal state on exhaustion of definite-not-transmitted failures: **`failed(not_transmitted_exhausted)`** — distinct from `failed(ambiguous_exhausted)`; intent → `failed`; no further sends.
- Definite-not-transmitted failures **never** escalate to `ambiguous` (transmission was ruled out) and can never produce duplicates (nothing was sent); they may exhaust retries quickly without ambiguity debt.

## 15.9 Updated decision entries

- **D2 (lifecycle)** now includes: full-transition fencing (C-2), marker-as-final-fence, corrected ambiguous CAS (C-3), frozen snapshot (C-6), retry caps (C-8).
- **D4 (classification)** now errno/phase-based (C-1); ambiguous remains the conservative default.
- **D5 (IDs/rollback)** now includes the legacy-write shutdown invariant and boundary re-assertion runbook (C-4).
- **D15 (new):** attempt rows immutable in membership; `device_token_id NOT NULL` (C-5, C-6).
- **D16 (new):** retry caps — 3 transmission opportunities; `not_transmitted_exhausted` / `ambiguous_exhausted` terminal states (C-8).

## 15.10 Test-matrix addendum (extends §16)

| ID | Requirement | Level |
|---|---|---|
| T-A21 | errno 6/7/35 → not-transmitted retry; errno 28/52/unknown → ambiguous; errno extraction from handler context and Kreait-wrapped chain | unit |
| T-A22 | Every fenced transition rejects stale seq/owner writes; marker fence aborts send on supersede | unit/concurrency |
| T-A23 | Ambiguous CAS: concurrent flips — exactly one wins; sweeper cannot clear flag | concurrency |
| T-A24 | Rollback boundary re-assertion: legacy inserts after rollback cannot collide (boundary recomputed) | migration/scripted |
| T-A25 | `UNIQUE(intent, device)` enforces single attempt row (NOT NULL ref) | migration/DB |
| T-A26 | Late-registered device never joins an existing intent | feature |
| T-A27 | Dedupe three-case matrix (C-7) | feature |
| T-A28 | attempts cap: 4th claim refused; `not_transmitted_exhausted` terminal | unit/feature |

**Unresolved risks after corrections:** errno table is cURL-version-dependent (new libcurl versions may introduce errnos ⇒ unknown errno = ambiguous by default — safe); Guzzle "easy handle" edge paths (response-creation exceptions, :171-181) produce `RequestException` **with** a response ⇒ definite-outcome path already covers them; in-flight conversion of an expired-`attempted` row (slow worker) can lose the straggler's result write (logged, conservative-ambiguous; duplicate avoided by cool-down > normal send duration — config-tuned).

---

**STOP — PHASE 2.2 design gate (as amended by 2.2.3).** Implementation begins only after explicit approval of this corrected design (D1–D16; unapproved PO items proceed as flags/defaults preserving current behavior). No code, migration, Composer, configuration, or data change has been made. Nothing in this document is Tested.
