# Notification System Unification — PHASE 2.3 Adversarial Architecture Audit

**Mission type:** Independent, exhaustive, adversarial design review. READ-ONLY. No implementation started.
**Baseline:** HEAD `2f258c05f797cd8024d174da870755f08b08f6c0`, branch `main`, re-verified before and after the audit. Working tree contains exactly the six prior audit/design documents (untracked) plus this report. PHP 8.2.30, Laravel 10.30.1, kreait/firebase-php 6.7.0, guzzle 7.8.0 (pinned), MySQL unreachable (blocker B-1 — reported, not bypassed).

**Verdict (see §26): CONDITIONALLY-READY** — the architecture is fundamentally sound, but the design **cannot be approved as written**: 1 design-level P0 and 9 P1 defects were found, plus evidence gaps that block critical guarantees. Exact mandatory corrections are enumerated.

---

# 1. Executive verdict

The two-level ledger + canonical device registry + unified dispatcher architecture is structurally sound and addresses the verified defects of the current production system. However, an adversarial re-derivation of the state machine found that **the design's central delivery guarantee is overstated**: fencing protects database writes but cannot fence the external HTTP side-effect; a stale worker that passed the pre-send marker can deliver after a second worker has already delivered — **duplicates are possible and this must be the stated guarantee** (at-least-once), not "duplicate avoided" (2.2.3 §15.10). Beyond that honesty defect, the audit found a schema-design P0 (attempt→device FK conflicts with the documented unregister API), incomplete enforcement of the legacy-write shutdown, a retry-cap condition missing from the claim CAS, a fragile AUTO_INCREMENT namespace scheme that is actually unnecessary (owner-scoped resolution is inherently collision-safe), an identity-coverage gap for 6 of 9 payment emit sites, a missing ghost-blast guard (events lacking `ShouldDispatchAfterCommit`), raw token snapshots in the ledger, and several unspecified operational behaviors. Every finding carries file/line or authoritative external evidence; all acceptance criteria are restated measurably.

# 2. Baseline and repository integrity

| Check | Before | After |
|---|---|---|
| HEAD / branch | `2f258c0…` / main | identical |
| `git status --short` | 6 untracked docs | 6 prior docs + this report (nothing else) |
| Previous audit docs preserved | yes (byte-for-byte; never opened for write) | yes |
| PHP / Laravel / kreait / guzzle | 8.2.30 / 10.30.1 / 6.7.0 / 7.8.0 (pinned) | unchanged |
| DB availability | `SQLSTATE[HY000] [2002]` — unreachable | unchanged (all runtime validation impossible) |

# 3. Skills discovered and actually used

| Location | Inventory |
|---|---|
| `.agents/skills/` (project) | 20 skills: architecture, clean-code-guard, deploying-to-cloud, devops, docs-guard, ecommerce, engineering-core, find-skills, infer-conventions, laravel-best-practices, lean-ctx, product-engineering, security-engineering, self-improvement, tailwindcss-development, test-guard, testing, testing-best-practices, woo-guard, wp-guard (+ SKILLS_INDEX.md) |
| `.claude/skills/` (project) | clean-code-guard, docs-guard, test-guard, woo-guard, wp-guard |
| OpenCode-registered (user/global) | same set, exposed via the skill loader |

**Skills actually loaded and applied to this audit** (instructions read in full):
- **architecture** — drove the boundaries/ownership matrix (§5), concurrency/transaction review (§7), data-ownership and trade-off challenges (§9, §13).
- **security-engineering** — drove the threat-model of §15 (IDOR, token exposure, replay/idempotency, information disclosure, cross-user access).
- **testing** — drove the traceability matrix (§19): measurable acceptance criteria, weak-assertion flags, route-existence requirement for API tests (routes verified: routes/api.php:273-274, :323-328).

Not used (no fit): tailwindcss-development, woo-guard, wp-guard, deploying-to-cloud, docs-guard, clean-code-guard, test-guard (code/test review skills — no code exists to review), find-skills, self-improvement, infer-conventions, laravel-best-practices (no code written), product-engineering (requirements already frozen), ecommerce (no domain pricing logic in scope), lean-ctx (in active use throughout via tooling). No relevant skill is missing for this mission.

# 4. Documents reviewed

Read in full/cross-checked against source: PHASE_2_2_DESIGN, EXECUTION_TODO (working docs — content in author context, corrections sections re-read this round), NOTIFICATION_SYSTEM_FULL_AUDIT, NOTIFICATION_UNIFICATION_DISCOVERY, NOTIFICATION_UNIFICATION_DESIGN (2.0), NOTIFICATION_UNIFICATION_DESIGN_REVISION (2.1). Every design claim below was re-verified against repository/vendor source at the pinned HEAD, not taken from the documents.

# 5. Architectural review

## 5.A Ownership matrix (verified against code)

| Responsibility | Owner (design) | Conflicting writers? | Verdict |
|---|---|---|---|
| Device registration/reassignment | `DeviceRegistry` (both APIs adapt) | none (single write path) | OK |
| Device activation/deactivation | `DeviceRegistry` (reason-coded) | Path A job invalid-token pruning must route through it (today it inline-deletes: SendFcmNotificationJob.php:50 — must be migrated) | **Correction M-8** |
| Intent creation | `PushDispatcher` (claim) | FcmChannel creates job → dispatcher claims; B listener creates ledger rows separately (order_notifications — different scope) | OK |
| Delivery attempts | `PushDispatcher` + lease CAS | — | OK |
| FCM communication | `FcmService` (per-client factory) | sole caller is dispatcher after migration | OK |
| Preferences | `UserNotificationPreference` + shared helper | today 3 listeners + controller; design adds gate at claim (§12 below) | Correction M-5 |
| Order notification history | `order_notifications` (B/D listeners) — untouched | — | OK |
| Coupon delivery | Path C guards + ledger *recording only* | no double authority (guards unchanged) | OK |
| Retries | Laravel job tries (transport) + dispatcher attempt rows (device) | documented two-layer split | OK (see §8) |
| Invalid-token handling | classifier → DeviceRegistry.markInvalid | Path A's inline delete must be replaced | M-8 |

Path A/B/C responsibilities explicit; RabbitMQ transport and durable coupon guards preserved (RabbitMqCouponEventTransport untouched; NotificationRequestHandler guards untouched — verified). Email/SMS/database/broadcast channels unaffected by the design (verified: no via()/channel changes proposed for them).

## 5.B Device registry — adversarial scenarios

| Scenario | Design behavior | Verdict |
|---|---|---|
| Two users register the same token concurrently | `updateOrCreate(['token'])` race: both pass existence check, both INSERT → loser hits unique-violation 500 | **Defect DEF-13 (P2)**: requires upsert/catch-retry; mobile sees transient 500 today-equivalent behavior |
| Token reassignment while an attempt is pending | attempt sends to the token string (provider-level truth); the *previous* user's notification arrives on the device now owned by the new user | **Defect DEF-16 (P1)**: pre-send validation (exists + is_active + owner==intent.recipient) required; residual second-scale window documented |
| Quarantined token reactivation | only via fresh authenticated registration (reassignment) — cannot reactivate for a "wrong" user (the registering user is by definition the device holder) | OK |
| Token snapshot after ownership change | snapshot is audit-only; see §15 (raw token exposure) and §5-B row 2 | Corrections DEF-10/M-9 |
| Invalid-token deactivation after reassignment | token-level operation — correct regardless of owner | OK |
| Stale sweeper vs registration race | sweeper is report-only by default | OK |
| Path A inline token deletion | conflicts with "no silent deletion" rule and bypasses DeviceRegistry | **M-8** |

## 5.C Ledger relationship map — gaps found

`notification_intents` 1—N `notification_device_attempts`; attempts reference devices. **Defect DEF-2 (P0):** the design specifies `device_token_id NOT NULL FK` — but Path A's documented DELETE **hard-deletes** `device_tokens` rows (DeviceTokenController.php:44-46, contract doc "completely removed"). Consequences as designed: (a) FK RESTRICT breaks a documented API operation; (b) FK CASCADE silently destroys delivery records. **Correction:** `device_token_id` = plain `unsignedBigInteger NOT NULL`, **no foreign key** (soft reference), `token_hash` snapshot as the durable join key; attempt rows must survive device deletion; user deletion cascades device_tokens but **not** attempts (intents own them; retention prunes them). Also **DEF-3 (P2):** intent status derivation unspecified — define: intent flips to `completed` when no attempts remain non-terminal (updated by the last terminal attempt write and reconciled by the sweeper); an intent with **zero** attempts (no active devices at claim) must be `skipped`/`no_devices` at creation, never `dispatched` forever.

# 6. Duplicate/lost delivery — adversarial analysis

## 6.A The stale-worker HTTP-send race (mission spotlight)

Step-by-step with the actual 2.2.3 design:
1. Worker A claims (seq bumped), writes pre-send `attempted` marker (fence passed).
2. Worker A pauses before HTTP (GC/VM suspend/network partition) — arbitrarily long.
3. Lease expires; sweeper converts expired-`attempted` → `ambiguous` (seq bumped), sets cool-down.
4. After cool-down, worker B's one-shot CAS flips ambiguous→pending, claims (seq bumped), markers, **sends — FCM accepts**.
5. Worker A resumes and **sends the same request** — the fence cannot stop this: the external side effect is not guarded by `claim_seq`; FCM v1 has **no idempotency key** (authoritative external reference: Firebase Cloud Messaging HTTP v1 API reference, `projects.messages/send` — no deduplication/idempotency parameter exists).
6. Worker A's result write fails (seq mismatch, 0 rows, logged) — but the HTTP send already happened.

**Verdict: the duplicate IS possible under the proposed design.** The 2.2.3 claim "duplicate avoided by cool-down > normal send duration" is **incorrect as a guarantee** (it is a probability reduction only — a worker can pause longer than any configured cool-down). `claim_seq` protects database writes, not external effects; these are different guarantees (mission is right).

**Required corrections (M-1):**
1. State the guarantee honestly: **at-least-once delivery; duplicate device-display is possible** in exactly two documented windows: (W1) stale worker resumes after the sweeper's ambiguous conversion and re-sends; (W2) response lost after provider acceptance (attempted→ambiguous→one-shot retry). Both windows are bounded by the cool-down but not eliminated.
2. Make **client-side display deduplication a REQUIRED acceptance criterion** of rollout (mobile dependency, contract: notification carries a stable `notification_id`; server passes it; client suppresses duplicates) — without it, the rollout acceptance for push UX is explicitly "duplicates possible, rare".
3. Add **duplicate forensics**: both sends produce distinct `provider_message_id`s — the dispatcher must log/record every accepted message id against the attempt row (already designed) so duplicates are detectable and countable post-hoc.
4. Keep the cool-down (window narrowing) but re-label it a mitigation, not a prevention.

Everything else (normal path) holds: the marker-as-final-fence prevents *superseded-before-marker* workers from sending (the fence aborts them), lease-expired-never-marked claims are safely reclaimable, and definite-outcome paths never duplicate.

## 6.B Crash-window matrix (exhaustive)

| # | Crash point | DB row state | Provider may have received? | Retry safe? | Duplicate possible? | Loss possible? |
|---|---|---|---|---|---|---|
| 1 | before intent claim | none | no | yes | no | no (event not yet in delivery pipeline) |
| 2 | after intent claim, before attempts | intent only | no | yes (attempts created next run) | no | no |
| 3 | attempts created, before claim | attempts `pending` | no | yes | no | no |
| 4 | after claim, before marker | `claimed` (lease) | no | yes — safe reclaim on expiry | no | no |
| 5 | after marker, before HTTP | `attempted` | no (yet) | via ambiguous path | **yes (W1-type only if resumed worker sends later)** | yes→no: ambiguous retry covers |
| 6 | during connection (TLS/TCP) | `attempted` | no (handshake incomplete) | yes (not-transmitted if errno 6/7/35) | no | no |
| 7 | during request transmission | `attempted` | **possibly** | ambiguous path | **yes (W2)** | no |
| 8 | provider accepted, response in flight | `attempted` | **yes** | ambiguous path | **yes (W2)** | no |
| 9 | response received, before outcome write | `attempted` | yes | row is stale-`attempted` → sweeper → ambiguous → retry | **yes (W2)** | no |
| 10 | during outcome write | transactional — all-or-nothing | yes | row persists or stays `attempted` | yes (W2) | no |
| 11 | after outcome write | terminal | yes | no retry (terminal) | no | no |
| 12 | job retry (framework) | per-state | — | dispatcher short-circuits terminal states | no (attempt-level cap) | no |
| 13 | worker shutdown (SIGTERM) | as at kill time | — | same as crash | — | no |
| 14 | deployment (worker restart) | as at kill time | — | `retry_after` covers | — | no |
| 15 | sweeper mid-reclaim | CAS — one winner | no | yes | no | no |
| 16 | sweeper during active send | `attempted`→`ambiguous` conversion loses the straggler's result | possibly yes | ambiguous retry | **yes (W1)** | no |
| 17 | DB unavailable post-acceptance | outcome lost; row stays `attempted` | yes | sweeper → ambiguous | yes (W2) | no |
| 18 | ambiguous CAS race | one winner | — | others get 0 rows | no | no |

**Unrecoverable-state check:** no state is permanently stuck *provided the sweeper is scheduled* (it performs lease reclaims, expired-attempted conversion, and one-shot ambiguous flips). If the sweeper is not scheduled, rows can stick in `claimed`/`attempted` — **operational requirement M-11** (sweeper cadence + stuck-alert). `ambiguous` without sweeper = stuck; with sweeper = bounded.

# 7. Concurrency, locking, fencing — verified against the SQL as written

- Claim CAS (2.2 §15.2): `status='pending' OR (status='claimed' AND claimed_until<now)` — **defect DEF-4 (P1): the guard does not include the attempts cap** (`AND attempts < 3` per C-8); as written, a device can exceed the cap. Also specify the `LAST_INSERT_ID(claim_seq+1)` read-back idiom so the worker learns its seq atomically.
- Marker write: missing `attempts=attempts+1` in the 2.2.3 §15.2 sketch while C-8 requires the increment at the marker — **DEF-7 (P2) doc inconsistency**; canonical marker SQL: `SET status='attempted', attempts=attempts+1 WHERE id=:id AND claim_seq=:mine AND claimed_by=:worker AND status='claimed'`.
- Ambiguous flip (15.3): conditions verified complete (status + flag + cool-down + CAS) ✓; seq bump present ✓.
- Stale worker capabilities tested against the SQL: cannot change state (seq/status guards) ✓; cannot clear another worker's lease (reclaim guard is status+lease, and a stale worker writing `claimed_until` would need to match seq — it can't) ✓; cannot consume another's retry (CAS) ✓; cannot reset a retry flag (no path) ✓; **can send HTTP after losing ownership (W1) — the only unwinsable race, accepted at-least-once (M-1)** ✓-honest; cannot deactivate a reassigned token incorrectly (token-level invalid marking is owner-independent — correct) ✓.
- Sweeper races: all CAS-guarded; two sweepers serialize ✓. Lock ordering: single-row updates; intent creation is a multi-row insert tx — no cross-row lock ordering with claim updates (inserts vs updates on different tables) ⇒ deadlock risk negligible (INFERRED; concurrency tests cover).
- Isolation: InnoDB REPEATABLE READ default — CAS uses current-read (locking reads) on UPDATE ⇒ safe. ✓
- **DEF-17 (P2):** `claimed_by` alone must not gate result writes when seq matches (worker identity is informational; seq is the fence) — 2.2.3 correctly requires both; keep both.

# 8. Retries and error classification

- Two retry layers verified: framework (`tries=3/backoff=[30,120]` on the job) and device-level (`attempts` cap). **DEF-5 (P1):** claim-CAS cap missing (above). Double-increment check: framework retry re-enters dispatcher → marker write again → attempts+1 per real send opportunity ✓; ambiguous flip +1 ✓; no path increments without a send opportunity ✓; no path fails to increment on a real send ✓.
- Indefinite re-selection check: terminal states excluded from all selection queries ✓ (verify SQL in M-2).
- **Error classification (errno/phase-based, supersedes 2.2 §6/§15.1):**

| Evidence source | Classification | Action |
|---|---|---|
| Pre-send (credential/OAuth/message construction) | definite-not-transmitted | → pending (normal retry, cap 3) → `failed(not_transmitted_exhausted)` |
| `ConnectException` errno 6 (DNS), 7 (connect), 35 (TLS handshake) | definite-not-transmitted | same |
| `ConnectException` errno **28** (timeout — connect *or* response-wait; vendor CurlFactory.php:163-169 maps both) | **ambiguous** | one-shot cool-down retry |
| `ConnectException` errno **52** (got nothing) | **ambiguous** | one-shot |
| Response present (Kreait converter output: NotFound/InvalidArgument/InvalidMessage/AuthenticationError/QuotaExceeded/ServerError/ServerUnavailable; or `BadResponseException`) | definite outcome per class | per-class table (invalid_token→deactivate; invalid_payload→failed no-retry; auth_config→fail-all+`fail($e)`; quota/server→transient) |
| Response-less `RequestException`/`TransferException`/unknown errno/Kreait generic `MessagingError` without chain evidence | **ambiguous** (conservative) | one-shot |

- Kreait wraps response-less Guzzle errors into generic `MessagingError` (vendor MessagingApiExceptionConverter.php:111-119) ⇒ **the classifier must walk `getPrevious()`** — verified.
- `fail($e)`: verified vendor InteractsWithQueue.php:50 (manual failure, no retry consumption, `failed()` fires) ✓.
- Same failure retried by both layers? No — device-level terminal states short-circuit the dispatcher before any provider call; framework retries re-enter and short-circuit ✓.
- DEF-14 (P1, UNKNOWN): full composer resolution for any SDK option cannot be proven without running Composer — isolated verification procedure required (see §14).

# 9. Event identity and deduplication — site-by-site audit

| Event | Production dispatch sites (verified by grep) | Identity source | Gap? |
|---|---|---|---|
| `App\Events\OrderStatusChanged` | **OrderService.php:1388 only** | transitionId (minted at OrderService, persisted in OrderTrackingEvent.metadata in-transaction; synchronous listener RecordOrderStatusChangeInTimeline.php:9-47) | none — but ** marvel trait sites (OrderStatusManagerWithPaymentTrait.php:199-241) dispatch *Marvel\Events\** classes → marvel listeners → mail-only (verified) ⇒ no push intents ⇒ no identity needed; design must state this namespace split explicitly (**M-4**) |
| `App\Events\OrderCancelled` | OrderService.php:1391 only | same transitionId | none |
| `App\Events\OrderDelivered` | OrderService.php:1395 only | same transitionId | none |
| `App\Events\PaymentSucceeded` | **3 sites**: PaymentWebhookController.php:1700 (webhook event_key ✓), OrderService.php:1405 (shares cancel/completed transitionId ✓), **OrderController.php:631 (gateway success-callback — has `$transaction` in scope but NO designed identity)** | **DEF-6 (P1): identity gap** — add `txn:{transaction_id}` (transactions rows persisted; migration 2026_11_10_000001 adds attempt lifecycle) |
| `App\Events\PaymentFailed` | **9 sites**: 4 webhook (:1726/1853/1936/2006 — event_key ✓), OrderController :432/:583/:606/:785/:890 (manual paths — no identity), **CancelUnpaidOrders.php:143** (transaction rows just flipped to 'failed' at :138-140 — persisted ids available) | **DEF-6 (P1): identity gap** — same `txn:{id}` source (or shared cancel transitionId where the command cancels via OrderService — verify at implementation; default to txn ids) |
| `App\Events\RefundApproved` | RefundService.php:227 | refund PK ✓ | none |
| Cart reminder | NotifyAbandonedCarts atomic claim | claim-based ✓ | none |
| Admin login | UserController.php:571 (token created :565) | sanctum token PK ✓ | none |
| Contact | ContactMessageReceived | contact PK ✓ | none |
| Promotion/FlashSale/Product observer events | PromotionObserver :29/:63, FlashSaleObserver :28/:64, ProductObserver :154/:165/:176 | payload-frozen eventId (uuid, plain string prop) | none for identity; **DEF-11 (P1): events lack `ShouldDispatchAfterCommit` (verified — PromotionActivated.php) ⇒ mid-transaction dispatch ⇒ ghost blast on rollback. Remedy = add the interface (changes listener timing; needs approval)** |
| Coupon | NotificationRequestHandler | `coupon-notify:{run_id}:{recipient_id}` (PKs) ✓; tree_hash demoted ✓ | none |

**dedupeScope() three-case matrix — re-verified with a new finding:** scope declarations apply to Path A classes via a method; **Path B's push is not a notification class** (listener → dispatcher with explicit params) — **DEF-8 (P2): specify the dual entry contract** (FcmChannel passes `scope`+`identity`+`category` to the job; Path B passes them directly). Recipient/channel isolation: key includes recipient_id + channel ✓. Event replay after retention expiry: new intent (row deleted) ⇒ re-send — documented, bounded by 90d retention + queue-job lifetimes (P3 note). Concurrent intent creation: unique-key insert-or-ignore ✓. Hash collisions: full sha256 (64 hex chars, no truncation) ✓. Missing identity values: classes without an identity must fail closed (skip + ledger reason), never send unscoped — **add to acceptance criteria (M-6)**.

# 10. Queue topology — verified

All four marketing layers verified on `high` today (39 viaQueue matches; notifications onQueue high; BroadcastMessage onQueue high; SendFcmNotificationJob → `config('frontend.queue') ?? high`). The design's per-layer move is complete **provided the FCM-job queue is per-dispatch** (property set by FcmChannel) — verified feasible (Queueable::$queue honored at dispatch). Parent-high→child-high trap avoided because the child queues are set from the *notification category*, not the parent. Transactional latency unchanged. One more nested-job check: no other nested dispatches found in the blast path. **M-3 (P2):** queue-assignment tests must assert per-layer physical queue names via `QueueName::high()/medium()` (env-driven), not literals.

# 11. Preferences, locale, UX

- **Gate timing (verified hazard + correction M-5):** 2.2 gates at `FcmChannel` (inside the queued notification job) — a preference change between that gate and the FCM job's actual send is not re-checked. **Correction:** move the final gate into the dispatcher at claim time (category passed as a job parameter; single shared helper; Path B keeps its listener gate for the `order_notifications` ledger + gets the dispatcher gate too). Path A/B parity then holds at the same instant.
- **Locale (verified):** `toDatabase()` payloads build explicit en/ar maps (checked UserCouponAssignedNotification.php:36-55 — the earlier suspected defect is retracted; the `app()->getLocale()` at :69 is inside the **dormant toMail()** which is not in `via()` ⇒ harmless). Push title/body resolved from `notification_language` preference (never `App::getLocale()`). Verified writer hazard remains: marvel `NewOrderReceived.php:51` `App::setLocale` inside a queued mail job can poison worker locale for subsequent jobs — flagged (separate fix, out of scope).
- Guests: B listener skips (no prefs row); A notifications only target resolved users ✓. Missing preference records: `firstOrCreate` defaults all-true — and it would **create preference rows for admin notifiables** as a side effect of gating (DEF-18, P3): gate helper must not create rows for non-user recipients; treat admins as preference-exempt.
- Simulated mode: `services.fcm.enabled=false` default ⇒ all pushes simulated until configured — ledger must show `simulated`, `order_notifications` gets `skipped`+reason (design ✓, retained).
- History: `order_notifications` rows continue (contract preserved); Laravel `notifications` untouched ✓.

# 12. Legacy API and mobile compatibility — adversarial

- **Key correction (M-2, P1):** the AUTO_INCREMENT namespace scheme is **over-weighted and fragile**: MySQL <8.0 reverts AUTO_INCREMENT counters to `max+1` on server restart (MySQL Reference Manual: "In MySQL 5.7 and earlier… the counter is not persisted; MySQL 8.0 persists it") — production version is UNKNOWN (M-1/M-2 evidence gap). Meanwhile the **owner-scoped map-first resolution is inherently collision-safe**: user X's legacy id 5 resolves via the map (X-owned row); user Y's canonical id 5 falls through to canonical lookup (Y-owned) — a numeric collision across users cannot misresolve because every branch is owner-scoped. **Correction:** make map-first owner-scoped resolution the primary correctness mechanism; keep the bump + re-assertion + zero-overlap query as defense-in-depth only.
- **DEF-4-legacy (P1, mission §12 challenge accepted): "a verification query is not a write-prevention mechanism."** The actual enforcement options: (1) **DB-level**: `REVOKE INSERT, UPDATE, DELETE ON user_device_tokens FROM <app user>` during the canonical period (strongest; DevOps approval + rollback grant required); (2) code-level gate + grep audit (default); (3) trigger-based rejection (rejected — operational burden). The design is **incomplete until option (1) or an approved equivalent is added**; code-level alone is classified as enforcement-by-convention.
- Mobile ID usage: `registerDevice` returns the full model incl. `id` (NotificationPreferencesController.php:74) — actual client persistence is UNKNOWN (M-3); both resolution branches are owner-scoped so either usage pattern is safe.
- Contracts verified: registration envelopes, DELETE semantics (200-token-based / 404-id-based), ownership scoping, throttle middleware (routes/api.php:273-274, 323-328). No envelope changes proposed except additive `device_uuid`.

# 13. Database and migration safety

- Additive-only claim: verified sound for CREATE TABLE (instant) and ENUM-append (`ALTER MODIFY` adding value at list end = metadata-only on 8.0). **Column add = INSTANT only on MySQL 8.0.12+; 5.7 rebuilds the table** — production version UNKNOWN ⇒ downtime **not** claimable (M-2). Index creation `(is_active)` = INPLACE but duration scales with rows (UNKNOWN).
- "One SQL statement ⇒ atomic": challenge accepted — single-statement DDL is atomic per statement, but a migration *file* with multiple statements can partially apply; the design's single-statement-per-migration rule must be pinned (M-10).
- Irreversible operations inventory: **none proposed** (all additive; reverse migrations defined; quarantine preserves sources; legacy tables retained). The only irreversible-risk item is data merge mis-assignment — mitigated by quarantine-all-conflicts.
- Backfill idempotence: upsert by token + quarantine keyed by source ids ✓; retry after partial migration safe ✓.
- **DEF-2 (P0)** FK conflict (§5.C) is the migration-safety headline; correction: soft-reference attempt rows (no FK to device_tokens), FK cascade only from intents (retention).
- Replay-after-retention: documented (§9). Big-int ranges: adequate. Metadata locks: column-add INSTANT avoids MDL stalls on 8.0; 5.7 rebuild takes locks — UNKNOWN until version+size known.

# 14. SDK / PHP / Guzzle / vendor compatibility — independent verification

Verified independently this round (exact composer.json of both candidate tags fetched):
- **A2** `^7.24.1`: php `~8.1.0||~8.2.0||~8.3.0||~8.4.0||~8.5.0` ✓ (8.2.30 OK); **guzzle `^7.9.2` — conflicts with the project's exact pin 7.8.0** ⇒ two-file Composer change; adds `cuyz/valinor` transitively; includes `firebase/php-jwt ^6.10.2||^7.0.2` (CVE-2025-4659 remediation).
- **A1** `^7.15.0`: php `~8.1.0||~8.2.0||~8.3.0||~8.4.0` ✓; guzzle `^7.5` ✓ (no guzzle change); lacks the JWT CVE fix.
- **B** stay 6.7.0 + per-token `send()`: v1 endpoint verified in installed vendor (SendMessage.php:25) — works today; batch validate unusable (Messaging.php:109) but single `validate()` healthy (:100-103).
- 8.x excluded (php ~8.3+).
- **DEF-14 (P1, UNKNOWN):** a package's composer.json does not prove the project graph resolves (other constraints: srmklive/paypal ^3.0, kreait/firebase-tokens 3.0→5.x bump, lcobucci/jwt, psr/* interplay). **Required isolated procedure (no project mutation):** copy composer.json+lock to a scratch dir → apply the candidate constraint → `composer update --dry-run` + `composer check-platform-reqs` → record the resolution; only then approve and apply in the real tree. 7.x `SendReport` API surface (`unknownTokens()/invalidTokens()`) is **INFERRED** from maintainer docs — must be verified from installed source at implementation time.

# 15. Security and privacy review (security-engineering skill applied)

- **DEF-10 (P1):** `token_snapshot` (raw FCM token) in `notification_device_attempts` extends token exposure surface (90-day ledger). FCM registration tokens are bearer credentials for the send API. **Correction:** store `token_hash` (sha256) + `device_token_id` soft-reference; raw token lives only in `device_tokens` (read at send); logs never carry tokens (verified: current code logs prefixes — PushNotificationService.php:67-70 — that path is deleted at cutover).
- Cross-user exposure via stale jobs: bounded by pre-send validation (M-7) + at-least-once residual (§6.A) — content is the previous owner's notification on a device the new owner now controls; window seconds-scale; client dedupe/ownership semantics documented for mobile.
- IDOR: device DELETE owner-scoped (both APIs verified); notifications read/mark/delete scoped (`$user->notifications()`, marvel NotificationController) ✓; admin endpoints permission-gated ✓.
- Quarantine table: contains tokens + historical owner ids — DB-access-only; add to retention policy (D12).
- Queue payloads contain title/body/data (order numbers) — pre-existing exposure (jobs table); not worsened by design; note for ops.
- Privilege escalation: none introduced. Webhooks/payments untouched. Rate limits: existing `throttle:authenticated` unchanged; new dispatcher adds no public surface.

# 16. Observability and operations

Operators can answer: pending/stuck intents (status queries + stuck-alert), expired claims (sweeper logs), ambiguous attempts (ledger), provider errors by class (error_class), invalid tokens (device reason), queue backlog (queue depth — external), retry exhaustion (terminal classes), duplicate suppression (duplicate_skipped counter), simulated delivery (ledger state), sweeper actions (logs), migration progress (runbook). **M-11 (P2):** sweeper cadence (default every minute; config) + stuck-state alert threshold (e.g., `claimed` older than 2×lease, `dispatched` intents older than 1h) + dashboard counters by (status, error_class, client). Provider-accepted ≠ device-received ≠ displayed ≠ opened — states defined; no delivered state claimed ✓.

# 17. Product decisions and unresolved requirements (D1–D16)

All D1–D16 stand as presented; **reclassifications required:** D9 (logout default) is product+mobile (correctly labeled); **D11 sweeper enforcement** is product (correctly labeled); **D6 Composer** is engineering+approval (correctly labeled) — but note A2 changes a *pinned* dependency (guzzle 7.8.0), raising its operational weight. **D13** (no schema enum for simulated) correctly avoids a schema change. No decision is mislabeled engineering-only while changing product behavior — except **M-1's client-dedupe requirement**, which converts a mobile dependency into a rollout acceptance criterion (mobile decision required before cutover, not before implementation).

# 18. Failure-scenario exercise (32 scenarios)

| # | Scenario | Design behavior | Risk class | Status |
|---|---|---|---|---|
| 1 | Two workers claim same attempt | CAS+seq — one wins | none | design-proven |
| 2 | Old worker resumes after lease expiry (sent) | result write fails (seq); HTTP already sent ⇒ duplicate | duplicate (W1) | **real** (§6.A) |
| 3 | Old worker resumes after lease expiry (never sent) | marker fence fails ⇒ aborts | none | design-proven |
| 4 | FCM accepts, response lost | attempted→ambiguous→one-shot retry ⇒ duplicate possible (W2) | duplicate | **real** |
| 5 | FCM rejects after connection established | definite outcome by class | none | design-proven |
| 6 | DB down after acceptance | outcome lost; sweeper → ambiguous ⇒ retry ⇒ possible duplicate | duplicate | real, bounded |
| 7 | Worker crashes after marker | ambiguous path | duplicate possible | real |
| 8 | Sweeper during active send | straggler result lost; conservative-ambiguous | duplicate rare | real (documented) |
| 9 | Token reassigned while attempt pending | pre-send validation → skipped(reassigned) | none | **requires M-7** |
| 10 | Token reassigned during HTTP send | residual window | privacy residual | documented |
| 11 | Duplicate event dispatched simultaneously | unique dedupe_key ⇒ one intent | none | design-proven |
| 12 | Same event via Path A and Path B | shared scope+identity ⇒ suppressed | none | design-proven |
| 13 | Coupon replay after content change | run/recipient PK identity ⇒ same intent | none | design-proven |
| 14 | Queue redelivery of notification job | FCM job short-circuits on terminal states | none | design-proven |
| 15 | Deployment during running jobs | retry_after/lease cover | none | design-proven |
| 16 | Rollback with canonical-era registrations | reverse-sync creates legacy rows; canonical-era ids unresolved on old code — re-register needed | mobile | documented (M-12) |
| 17 | Legacy app writes during migration | stopped only by code convention unless REVOKE applied | **enforcement gap** | DEF-4 (M-6) |
| 18 | Two users register same token simultaneously | unique-violation 500 on loser | UX | DEF-13 |
| 19 | User disables push after intent creation | dispatcher claim-time gate catches it (M-5) | none | requires M-5 |
| 20 | User changes language mid-pipeline | locale read at dispatch (per job) — partial window | minor | documented |
| 21 | Invalid token reported after reassignment | token-level deactivation (correct) | none | design-proven |
| 22 | DB deadlock on claim | single-row CAS; retry via job | negligible | design-proven |
| 23 | Duplicate registration request (same user) | upsert semantics | none | DEF-13 fix |
| 24 | Missing device row at attempt creation | pre-send validation → skipped | none | **requires M-7** |
| 25 | Null device reference | eliminated by NOT NULL soft-ref (C-5 revised) | none | design-proven |
| 26 | Ambiguous CAS race | one winner | none | design-proven |
| 27 | Normal retry exhaustion | attempts cap ⇒ `not_transmitted_exhausted` | none | **requires M-2-cap** |
| 28 | Worker timeout (1300s) | job `$timeout=1200`; lease 1860s | none | design-proven |
| 29 | Provider rate limit (429) | quota/transient → retry with Retry-After honored (converter :122) | none | design-proven |
| 30 | Provider disabled (simulated) | ledger `simulated`; no false sent | none | design-proven |
| 31 | Partial migration failure | quarantine preserves sources; re-run idempotent | none | design-proven |
| 32 | Retention cleanup during replay | post-retention replay ⇒ new intent ⇒ re-send (documented) | P3 | documented |
| 33 | Ghost blast on rollback (promo events) | **no guard today** | ghost delivery | **DEF-11 (M-9)** |
| 34 | Worker-locale poisoning affects DB payload | verified retracted for coupon class; marvel mail writer hazard remains (separate) | none-for-push | verified |

# 19. Requirements traceability matrix (key rows)

| Requirement | Design § | Evidence | Measurable acceptance criterion | Test | Status |
|---|---|---|---|---|---|
| No timestamp-based activation | 2.2 §3.1 | merge algorithm | multi-row groups ⇒ canonical rows all `is_active=false`+quarantined; only single-row groups active | T-A11/A12 | design-proven |
| Possibly-sent attempt ≠ unsent claim | 2.2 §15.2/15.3 | marker/lease SQL | expired `attempted` never re-enters `pending` except via one-shot CAS | T-A3/A23 | design-proven |
| Stale worker cannot mutate state | §15.2 | fencing SQL | every state write fails with 0 rows on seq/owner/status mismatch | T-A22 | design-proven |
| Stale worker cannot send | §6.A | FCM has no idempotency (external ref) | **not achievable** ⇒ acceptance = at-least-once + client dedupe contract + duplicate forensics | T-A29 (new) | **corrected M-1** |
| Retry cap | §15.8/C-8 | cap semantics | no claim when attempts≥3; terminals distinct | T-A28 | **requires M-2 (SQL guard)** |
| Query isolation | §15.4-15.5 | canonical SQL | selection returns only parent intent's rows | T-A6 | design-proven |
| Legacy-write shutdown | §15.4 | runbook | **mechanism must be enforced, not verified** | T-A24 | **DEF-4/M-6** |
| No FK lifecycle break | §5.C correction | unregister API contract | unregister succeeds while attempts exist; attempts survive | T-A10b (new) | **DEF-2 (P0) — M-3** |
| Identity coverage all emit sites | §5 (2.2) / §9 (this audit) | grep of dispatch sites | every push-bearing site has a designed identity | T-A30 (new) | **DEF-6 — M-4** |
| SDK resolution | §8 (2.2) | composer.json of tags | dry-run resolution recorded in scratch | — | **UNKNOWN — M-13** |
| Ghost-blast guard | — | PromotionActivated.php (no afterCommit) | blast intents exist only post-commit | T-A31 (new) | **DEF-11 — M-9** |
| Preference at send | §11 (this audit) | FcmChannel gate position | gate evaluated at claim time for both paths | T-A19b (new) | **M-5** |

# 20. Consolidated defect register

| ID | Sev | Section | Defect | Evidence | Consequence | Required correction | Approval impact |
|---|---|---|---|---|---|---|---|
| **DEF-2** | **P0** | 2.2 §6.2 / 2.2.3 C-5 | `device_token_id NOT NULL FK` conflicts with Path A hard-delete (unregister API) — RESTRICT breaks the API; CASCADE destroys delivery records | DeviceTokenController.php:44-46; docs/api/device-tokens.md | documented API breaks or delivery-record loss | soft-reference: no FK, NOT NULL, `token_hash` join; attempts survive device deletion; FK cascade only intent→attempts (retention) | design re-approval required |
| DEF-1 | P1 | 2.2.3 §15.10 | "duplicate avoided by cool-down" overstated; stale-worker HTTP send (W1) and lost-response retry (W2) can duplicate | §6.A; FCM v1 no idempotency (external ref) | false guarantee; rollout acceptance wrong | state at-least-once; require client dedupe as rollout criterion; duplicate forensics via message ids | mobile dependency elevated |
| DEF-4 | P1 | 2.2 §15.2/C-8 | claim CAS missing `attempts<3` guard; `LAST_INSERT_ID` seq read-back unspecified | SQL as written | unbounded retries possible | add cap to claim guard + seq read-back idiom | design patch |
| DEF-5 | P1 | legacy-write shutdown (2.2.3 C-4) | enforcement mechanism absent (verification query ≠ prevention) | mission §12 | legacy inserts during compatibility can collide | DB-grant REVOKE option (DevOps) or approved equivalent + code gate + audit | DevOps decision required |
| DEF-6 | P1 | event identity (2.2 §7) | 6 of 9 `PaymentFailed` + 1 of 3 `PaymentSucceeded` emit sites lack designed identities | OrderController.php:631, :432/:583/:606/:785/:890; CancelUnpaidOrders.php:143 | unclaimed identities ⇒ fail-closed skips (lost pushes) or undefined behavior | per-site identity table (`txn:{transaction_id}`; persisted transaction rows verified) | design patch |
| DEF-10 | P1 | ledger schema | raw `token_snapshot` in ledger | §15 review | token exposure surface +90d | store `token_hash`; raw token only in device_tokens | security patch |
| DEF-11 | P1 | observer events | no `ShouldDispatchAfterCommit` ⇒ ghost blast on rollback | PromotionActivated.php; FlashSaleActivated.php | blast for reverted state | add interface (timing change) — needs explicit approval | approval item |
| DEF-14 | P1 | SDK (2.2 §8) | dependency graph resolution UNKNOWN; 7.x SendReport surface unverified from installed source | §14 | broken deploy risk | scratch-dir `composer update --dry-run` + `check-platform-reqs` + post-install vendor diff — gate before lock change | Composer gate |
| DEF-16 | P1 | pre-send validation | no exists/active/owner re-check before send | §5-B/§18 rows 9,24 | push to unregistered/reassigned device | pre-send validation step (before marker) | design patch |
| DEF-3 | P2 | intent status | derivation + zero-attempt case unspecified | §5.C | stuck `dispatched` intents | define transitions + `skipped/no_devices` | doc patch |
| DEF-7 | P2 | marker SQL | missing `attempts=attempts+1` | §7 | counter drift | canonical marker SQL | doc patch |
| DEF-8 | P2 | scope interface | Path B entry (non-notification-class) unspecified | §9 | implementation ambiguity | dual-entry contract (params) | doc patch |
| DEF-13 | P2 | registration race | concurrent same-token upsert 500 | §5-B | transient API failure | upsert/catch-retry | doc patch |
| DEF-17 | P2 | namespace fragility | AUTO_INCREMENT scheme fragile <8.0 restarts | §12 | collision risk if primary mechanism | owner-scoped map-first = primary; bump = defense-in-depth | design patch |
| DEF-18 | P2 | sweeper/ops | cadence, stuck-alerts, admin-pref-row side effect | §16 | silent stuck states | cadence config + alerts + no-create-for-admins | doc patch |
| DEF-19 | P3 | DB-payload locale | coupon class verified OK; marvel mail writer hazard separate | §11 | — | separate fix recommendation | out of scope |

**Previously reported issues resolution matrix:** P0-01 resolved (with runtime-activation clarification: only explicit registration activates) · P0-02 resolved (marker-fence improvement) · P0-03 resolved (full-transition fencing, C-2) · P0-04 resolved (canonical SQL; cap guard gap = DEF-4) · P0-05 resolved (C-3 CAS verified complete) · P0-06 resolved (scope model; DEF-8 doc gap) · P0-07 resolved · P0-08 resolved-as-superseded by C-1 errno model (this round) · P0-09 partially resolved (map-first primary per DEF-17; enforcement gap = DEF-5) · P0-10 resolved-as-options (resolution UNKNOWN = DEF-14). 2.2.3 C-1 superseded-and-corrected (errno model) · C-2 resolved (DEF-7 doc gap) · C-3 resolved · C-4 partially (DEF-5 enforcement) · C-5 superseded (DEF-2 P0) · C-6 resolved · C-7 resolved · C-8 partially (DEF-4 guard gap).

# 21. Recommended corrections in priority order

M-1 (honesty: at-least-once + client dedupe criterion + duplicate forensics) · M-2 (claim-CAS cap + seq read-back; canonical SQL block) · **M-3 (DEF-2 ledger FK fix — P0)** · M-4 (per-site payment identity table + namespace split statement) · M-5 (claim-time preference gate) · M-6 (legacy-write enforcement mechanism — REVOKE option decision) · M-7 (pre-send validation step) · M-8 (Path A invalid-token deletion → DeviceRegistry) · M-9 (ShouldDispatchAfterCommit decision for observer events) · M-10 (single-statement migration rule pinned) · M-11 (sweeper cadence + stuck alerts) · M-12 (rollback-era mobile re-registration documented as contract) · M-13 (SDK isolated resolution procedure) · M-14 (token_hash ledger + zero-token logging acceptance).

# 22. Outstanding Product Owner decisions

Unchanged from 2.2 §14 (D9, D8-list, Tier-3, D10, D11-enforcement, D12-retention, D13, D14) **plus elevated**: client-dedupe contract adoption (from M-1), legacy-write enforcement choice (from DEF-5/M-6), ghost-blast guard approval (DEF-11/M-9), SDK option (D6 with A2/A1/B trade-offs).

# 23. Outstanding Mobile/DevOps dependencies

Mobile: M-3 (endpoints, ID persistence, refresh cadence), logout contract (D9), `device_uuid`/`notification_id` adoption, rollback-era re-registration (M-12). DevOps: scratch DB (B-1), DB inventory + MySQL version for DDL assessment (M-2-deferral), REVOKE grant option (DEF-5), queue metrics, RabbitMQ status, sandbox Firebase live-fire (M-4-gate), Composer isolated resolution procedure (DEF-14).

# 24. Exact blockers

B-1 MySQL unreachable (all test execution) · B-2 design approval (this gate + mandatory corrections M-1…M-14) · B-3 Composer gate · B-4 PO decisions · B-5 mobile confirmations · B-6 sandbox project.

# 25. Unexpected file changes

None. After-audit git status: identical 6 prior docs + this report only.

# 26. Final approval recommendation

**Verdict: CONDITIONALLY-READY.** No fundamental architectural blocker exists — the canonical registry, two-level ledger, phase-aware classification, and dispatcher consolidation survive adversarial re-derivation. Approval is conditional on: (1) applying corrections M-1…M-14 to the design (one P0: DEF-2 ledger FK; the rest are bounded P1/P2 patches with exact fixes specified above), (2) resolving the Composer resolution UNKNOWN via the isolated procedure (DEF-14), (3) the standing Product-Owner/Mobile/DevOps decisions. A re-audit of the amended design (targeted, delta-review) must confirm the corrections before PHASE 3.

---

**STOP.** No implementation, no migration, no Composer change, no test execution, no approval of D1–D16. All previous audit documents preserved untouched; this report is the only new file.
