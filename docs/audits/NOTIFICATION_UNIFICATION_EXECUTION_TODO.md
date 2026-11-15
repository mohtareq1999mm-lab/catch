# Notification System Unification — Execution TODO Board

**Baseline HEAD:** `2f258c05f797cd8024d174da870755f08b08f6c0` (branch `main`)
**Board rule:** a task is DONE only with recorded evidence; VERIFIED means evidence was re-checked against source. No task is DONE without evidence.
**Scope guard:** implementation tasks are **TODO / BLOCKED** until the PHASE 2.2 design gate is explicitly approved.

---

## Phase 0 — Baseline and repository safety

| ID | P | Task | Status | Evidence | Files affected | Tests required | Approval |
|---|---|---|---|---|---|---|---|
| T00 | P0 | Verify repo path + git tree | **VERIFIED** | HEAD `2f258c05f797cd8024d174da870755f08b08f6c0`; branch `main`; working tree = 4 untracked docs only (3 prior + this board); no user changes present | none | — | — |
| T01 | P0 | Record branch/HEAD/uncommitted | **VERIFIED** | same as T00; original+revised design docs preserved untouched | none | — | — |
| T02 | P0 | Locate + read audit/design docs | **DONE** | Read: NOTIFICATION_SYSTEM_FULL_AUDIT.md, NOTIFICATION_UNIFICATION_DISCOVERY.md, NOTIFICATION_UNIFICATION_DESIGN.md, NOTIFICATION_UNIFICATION_DESIGN_REVISION.md (all preserved) | none | — | — |
| T03 | P0 | Verify PHASE 2.1 findings still apply | **DONE** | Spot re-verification at same HEAD: batch endpoint (vendor SendMessages.php:48), FcmService swallow (FcmService.php:50-53), Path B multi-user ownership (user_device_tokens migration :40 + controller :58-72), logout no-op (UserController.php:660-673), preferences bypass (grep), dual-event dispatch (OrderService.php:1388-1396) — all confirmed unchanged; plus new P0 defects in 2.1 itself (DEF-04 query precedence, DEF-06 dedupe-key/type contradiction, DEF-09 id collision, DEF-10 guzzle pin conflict) | none | — | — |
| T04 | P0 | Inventory push paths + callers | **DONE (carried)** | Path A (27 notifications → FcmChannel → SendFcmNotificationJob → FcmService → device_tokens), Path B (SendOrderPushNotification → PushNotificationService → user_device_tokens), Path C (coupon RabbitMQ → NotificationRequestHandler), full dependency map in DISCOVERY doc §Outputs; re-greps this session: Kreait call sites still exactly 2 (FcmService.php:71-73; PushNotificationService.php:80-81) | none | — | — |
| T05 | P0 | Verify DB availability (non-mutating) | **VERIFIED — BLOCKER for test phase** | `php artisan migrate:status` → `SQLSTATE[HY000] [2002]` connection refused; DB remains unreachable. **Test-phase blocker (rule 15): reported, not bypassed.** Scratch-DB provisioning (e.g., `catch_verify` per phpunit.xml comment) requires user/DevOps action before any test runs | none | all | DevOps/user |
| T06 | P0 | Inventory tests + execution requirements | **DONE (carried)** | 33 notification-related test files mapped (DISCOVERY §13); `AsyncSafetyTest` uses RefreshDatabase (destructive — scratch DB mandatory); RealPusher tests hit external service (never run) | none | — | — |
| T07 | P0 | Baseline evidence report | **DONE** | This board + PHASE 2.2 design document (§1 baseline) | board, design | — | — |

---

## Phase 1 — P0 design defects (design resolutions; implementation gated)

| ID | P | Task | Status | Evidence | Files affected | Tests required | Approval |
|---|---|---|---|---|---|---|---|
| P0-01 | P0 | Device ownership conflicts — remove timestamp-only activation | **DONE (design)** | Revised merge rule: multi-row groups → deactivated canonical + quarantine (2.2 §3.1). Prior acceptance gap owned: 2.1 §5 rule 4 still activated a unique-max winner — corrected. Evidence base: user_device_tokens unique(user_id,token,platform) :40; device_tokens unique(token) :15 | design §3.1; later: migration + DeviceRegistry | migration tests (conflict/null/tie/orphan) | Design approval |
| P0-02 | P0 | Claim/send/ambiguous lifecycle | **DONE (design)** | Pre-send `attempted` marker before HTTP initiation; lease-expiry semantics per state; worker-crash windows enumerated (2.2 §4.1-4.3). Evidence: guzzle ConnectException extends TransferException (vendor :13) — request not transmitted; converter wraps response-less errors into generic MessagingError (vendor MessagingApiExceptionConverter.php:111-119) | design §4; later: PushDispatcher, ledger migrations | crash-window tests | Design approval |
| P0-03 | P0 | Stale worker fencing | **DONE (design)** | Monotonic `claim_seq` + conditional outcome writes `WHERE id=? AND claim_seq=?` (2.2 §4.4). Stale writes affect 0 rows | design §4.4; later: attempt model + dispatcher | concurrency tests | Design approval |
| P0-04 | P0 | Retry query precedence | **DONE (design)** | **2.1 defect owned**: revision §6.4 sketch `intent_id=? AND status IN(...) OR (...)` selects other intents' attempts. Corrected canonical SQL with explicit grouping (2.2 §4.5) + MySQL precedence note | design §4.5; later: repository queries | query-isolation tests | Design approval |
| P0-05 | P0 | Ambiguous retry limit | **DONE (design)** | `ambiguous_retry_used` flag + one-shot CAS ambiguous→pending; second ambiguity → terminal `failed(ambiguous_exhausted)`; sweeper never clears flag (2.2 §4.6) | design §4.6; later: sweeper + attempt model | repeated-crash/sweeper tests | Design approval |
| P0-06 | P0 | Event identity vs presentation | **DONE (design)** | **2.1 contradiction owned**: dedupe key included notification_type yet claimed cross-class suppression. New: per-class `dedupeScope()`; key = sha256(scope\|event_identity\|recipient\|channel); cancel A-class and B-push share scope; payment vs order-completed remain distinct (2.2 §5.2) | design §5; later: notification classes + dispatcher | same-event/distinct-event tests | Design approval |
| P0-07 | P0 | Coupon identity | **DONE (design)** | Identity = `coupon-notify:{run_id}:{recipient_id}` (envelope PKs verified: NotificationRequestHandler.php:37-57); tree_hash demoted to guard-only field (2.2 §5.3). RabbitMQ + durable guards preserved | design §5.3 | coupon replay/content-change tests | Design approval |
| P0-08 | P0 | Transmission-phase error classification | **DONE (design)** | Phase-aware table (2.2 §6): definite-not-transmitted = pre-send (credential acquisition) + `ConnectException` (vendor :13); definite outcome = response-converted Kreait exceptions (converter :55-108) + BadResponseException; **ambiguous** = response-less TransferException/timeouts and Kreait generic `MessagingError` wrapping them (converter :119); classifier walks exception chain | design §6; later: FcmErrorClassifier | per-class tests | Design approval |
| P0-09 | P0 | Legacy ID collision + rollback | **DONE (design)** | **2.1 defect owned**: canonical-first numeric resolution can collide (both bigint sequences). Fix: DELETE-by-id belongs to the legacy id-space (Path A deletes by token — verified DeviceTokenController.php:42-46); canonical AUTO_INCREMENT bumped above max(legacy id) at migration → disjoint namespaces; legacy-map-first resolution; additive `device_uuid` response field; reverse-migration limits documented (2.2 §7) | design §7; later: migration + controllers | old/new/foreign/unknown/reassigned id tests | Design approval |
| P0-10 | P0 | Kreait/PHP compatibility | **DONE (design)** | 7.24.1 composer.json (fetched): php `~8.1.0\|\|~8.2.0\|\|~8.3.0\|\|~8.4.0\|\|~8.5.0` ✓ BUT `guzzle ^7.9.2` ✗ vs pinned 7.8.0; 7.15.0 composer.json (fetched): php `~8.1.0\|\|~8.2.0\|\|~8.3.0\|\|~8.4.0` ✓ + guzzle `^7.5` ✓; 8.x php ~8.3+ ✗ excluded. Three options + recommendation (2.2 §8); no PHP-8.3-requiring version proposed | composer.json (gated) | full suite + sandbox live-fire (gated) | **Composer gate (rule 13)** |

## Phase 2 — P1 dependency review

| ID | P | Item | Status | Evidence / resolution | Approval |
|---|---|---|---|---|---|
| P1-1 | P1 | Intent/attempt creation crash recovery | **DONE (design)** | Intent claim = insert-or-ignore (unique key); attempt rows re-created idempotently via unique(intent, device); dispatcher transaction boundaries specified (2.2 §4.2) | Design approval |
| P1-2 | P1 | Token ownership race lookup→send | **DONE (design)** | Attempts snapshot token string; token-level provider truth; post-reassignment outcomes recorded on the intent's attempt row; documented (2.2 §9.3) | Design approval |
| P1-3 | P1 | Coupon event identity proof | **DONE** | See P0-07 | Design approval |
| P1-4 | P1 | order_notifications failure_reason existence | **VERIFIED** | migration 2026_09_12_000002:29 `text('failure_reason')`; model markAsSkipped writes it | — |
| P1-5 | P1 | Lease vs timeout vs retry_after | **DONE (design)** | Lease = 1800s (=retry_after) + 60s margin; job `$timeout=1200` explicit; worker timeout 1300 pinned by WorkerConfigPolicyTest:47; invariant retry_after > lease-worker window preserved (2.2 §4.7) | Design approval |
| P1-6 | P1 | Firebase project selection / cross-project tokens | **DONE (design)** | Per-client strict resolution unchanged; wrong-project token → send-time NotFound → deactivated (correct); registration-time project binding = future mobile-dependent enhancement (2.2 §9.4) | Mobile dependency noted |
| P1-7 | P1 | Retention/replay interaction | **DONE (design)** | Ledger retention 90d; post-retention replay of an old event creates a new intent (documented behavior; coupon guards are independent and durable) (2.2 §9.5) | PO (retention period) |
| P1-8 | P1 | `$this->fail($e)` behavior | **VERIFIED** | vendor InteractsWithQueue.php:50 → ManuallyFailedException; manual failure does not consume retries; `failed()` fires | — |
| P1-9/10 | P1 | Queue topology / marketing placement | **DONE (design)** | 4-layer matrix re-verified this session (39 viaQueue matches, all high); per-layer move + per-layer tests (2.2 §10) | Design approval |
| P1-11 | P1 | Preference policy parity | **DONE (design)** | Single helper used by FcmChannel + B listener (2.2 §11) | PO for marketing list |
| P1-12 | P1 | Worker locale isolation | **VERIFIED hazard / DONE (design)** | Only getLocale *read* in app notifications (UserCouponAssignedNotification.php:69 — replaced by preference read); verified writer hazard: marvel NewOrderReceived.php:51 `App::setLocale` (flagged, separate fix) | Design approval; marvel fix separate |
| P1-13 | P1 | Legacy ID consumers | **UNKNOWN** | Mobile clients may persist registerDevice-returned ids (code-verified response shape); actual usage requires mobile confirmation (M-3) | Mobile |

---

## Phase 3 — Design gate

| ID | Task | Status | Evidence |
|---|---|---|---|
| G-1 | PHASE 2.2 corrected design document | **DONE** | docs/audits/NOTIFICATION_UNIFICATION_PHASE_2_2_DESIGN.md (change log vs 2.1 included) |
| G-2 | Present remaining decisions together | **DONE** | 2.2 §14 decision table |
| G-3 | STOP for explicit approval | **ACTIVE** | Implementation (Phases 4-8 of the mission) blocked until approval |

## Phases 4+ — Implementation / Testing / Audit loop

| ID | Task | Status | Notes |
|---|---|---|---|
| I-01..I-14 | File-by-file implementation per approved 2.2 §12 | **TODO — BLOCKED on design approval** | Frozen scope recorded at approval |
| TST-01..TST-22 | Test matrix per approved design | **TODO — BLOCKED also on DB availability (T05 blocker)** | Scratch DB required |
| AUD-1.. | Audit→fix→retest loop | **TODO** | Starts after implementation |

---

## Phase 2.2.3 — final design corrections (review round; READ-ONLY)

| ID | P | Task | Status | Evidence | Files affected | Tests required | Approval |
|---|---|---|---|---|---|---|---|
| C-1 | P0 | ConnectException reclassification (errno-based) | **DONE (design)** | Vendor CurlFactory.php:163-169 — `$connectionErrors` includes **28 (timeout) and 52 (got nothing)** alongside 6/7/35; :208-210 maps all → ConnectException ⇒ class alone cannot prove non-transmission. Revised phase/errno classifier (2.2 §15.1); ambiguous = conservative default; chain-walk required (Kreait wraps response-less errors, converter :111-119) | design §15.1; later: FcmErrorClassifier | T-A21 | Design approval |
| C-2 | P0 | Fencing for every transition | **DONE (design)** | Full guard matrix (2.2 §15.2): claim/reclaim/retry flips **bump** claim_seq; marker + result writes are seq+owner+status-conditional; **pre-send marker is the final fence — worker aborts without sending if superseded**; sweeper never touches results/flags | design §15.2 | T-A22 | Design approval |
| C-3 | P0 | Ambiguous retry CAS fixed | **DONE (design)** | Corrected SQL requires status='ambiguous' AND flag=0 AND cool-down expired AND row-lock CAS; seq bump included (2.2 §15.3); terminal `failed(ambiguous_exhausted)` | design §15.3 | T-A23 | Design approval |
| C-4 | P0 | Legacy-write shutdown + boundary re-assertion | **DONE (design)** | One-time bump insufficient if legacy inserts resume: rollback runbook **mandatory** boundary recompute (`max(legacy_max, canonical_max)+1` → ALTER) before reactivating legacy-writing code; migration start-guard; zero-overlap verification query; grep gate for legacy writes (2.2 §15.4) | design §15.4; later: migration + runbook | T-A24 | Design approval |
| C-5 | P0 | MySQL nullable-unique resolution | **DONE (design)** | MySQL 8.0 Reference Manual §17.6.2.1 (Unique permits multiple NULLs) + §15.1.15 (external source identified). `device_token_id` → **NOT NULL** (tokens deactivated, never hard-deleted); unique(intent, device) fully constrains; hard-delete future option requires restructuring precondition (2.2 §15.5) | design §15.5; later: ledger migration | T-A25 | Design approval |
| C-6 | P0 | Frozen target-device snapshot | **DONE (design)** | Attempts created atomically with intent claim; replay loads existing attempt rows only, never re-queries registry; late-registered devices join future intents only (2.2 §15.6) | design §15.6 | T-A26 | Design approval |
| C-7 | P0 | Dedupe test matrix corrected | **DONE (design)** | Explicit three-case matrix: same event+same scope → suppressed; **different** event+same scope → allowed; same event+**different** scope → allowed (2.2 §15.7; supersedes §5.2 wording) | design §15.7 | T-A27 | Design approval |
| C-8 | P0 | Retry caps for not-transmitted | **DONE (design)** | attempts incremented at pre-send marker; max 3 transmission opportunities (tries=3/backoff [30,120] aligned); ≥3 ⇒ claim refused; terminal `failed(not_transmitted_exhausted)`; never escalates to ambiguous; never duplicates (2.2 §15.8) | design §15.8 | T-A28 | Design approval |

**2.2.3 verification:** HEAD re-verified before and after (`2f258c05f797cd8024d174da870755f08b08f6c0`); working tree unchanged except the two updated working documents (original PHASE 2 / 2.1 / audit docs preserved byte-for-byte). No code, migration, Composer, configuration, or test execution. No design element marked Tested.

---

## Blockers register

| # | Blocker | Impact | Owner |
|---|---|---|---|
| B-1 | MySQL unreachable in this environment | All test execution impossible (rule 15: reported, not bypassed) | User/DevOps — provision scratch DB (e.g., `catch_verify`) |
| B-2 | Design approval gate (mission §4) | Implementation frozen | User |
| B-3 | Composer change approval (kreait + possibly guzzle) | DEC-I execution | User |
| B-4 | Product Owner decisions (mission §5) | Scope items deferred to flags/defaults | User |
| B-5 | Mobile confirmations (M-3, logout contract, legacy-id usage) | Several compatibility behaviors | Mobile team |
| B-6 | Firebase sandbox project (M-4) | Live-fire gate | DevOps |
