# Notification Unification — COMPLETE DEFECT CLOSURE MATRIX (Phase 3.1)

**Date:** 2026-11-14 · HEAD `2f258c05f797cd8024d174da870755f08b08f6c0` · READ-ONLY (no source changed)
**Rule applied:** a finding is **CLOSED** only when the final design (`NOTIFICATION_UNIFICATION_FINAL_DESIGN_CANDIDATE.md`, cited as FD §n) resolves its root cause. A paragraph mention, a test recommendation, or a supersession without proof is not closure. Nothing is **Tested**; verification columns name the proving test/mechanism, which requires scratch DB (B-1) or the listed gate.

**Numbering families (both preserved, no silent merging):**
- **N-xxx** — original full audit findings (§15 of FULL_AUDIT), mapped 1:1 to **D-xxx** discovery findings (DISCOVERY Output 6; mapping re-validated at HEAD with identical line numbers). D-025/D-026 added in discovery.
- **SEC-xxx** — original audit security findings.
- **2.2 change log DEF-01…DEF-10** — defects *of the 2.1 design*, aliased P0-01…P0-10 in the execution board.
- **P1-1…P1-13** — 2.1-era P1 review items.
- **2.2.3 C-1…C-8** — adversarial corrections to 2.2.
- **2.3 DEF-1…DEF-19** — defects *of the 2.2/2.2.3 design*. **DEF-9, DEF-12, DEF-15 do not exist** in the 2.3 register (verified by grep — numbering gaps, never issued; not hidden findings).
- **M-1…M-14** — 2.3 mandatory corrections.
- **V-xx** — independent re-verification findings (V-16 from the baseline pass; V-21 discovered this phase).
- **AF-xx** — final-design reconciliation findings introduced this phase.

---

## A. Discovery findings D-001…D-026 (= N-001…N-024 + D-025/026)

| ID | Sev | Original problem / root cause | Source evidence | Previous proposed fix | Final resolution (FD §) | Failure scenario prevented | Verification | Dependencies | Status |
|---|---|---|---|---|---|---|---|---|---|
| D-001 (N-001) | P0 | Multicast targets retired FCM `POST /batch` endpoint (kreait 6.7 `SendMessages.php:48`) | vendor SendMessages.php:48; Messaging.php:73-93; composer.lock kreait 6.7.0 | 2.0/2.1: upgrade kreait ≥7.15; fallback per-token v1 loop | **D6 options A2/A1/B** (FD §15): A2 = kreait ^7.24.1 **+ guzzle ^7.9.2 + psr/log ^3.0.2 (V-21)**; A1 = ^7.15.0 only; B = stay 6.7 + per-token `send()` (v1 verified healthy). All remove the batch endpoint | Silent total Path A push failure at provider | Sandbox live-fire (M-4/B-6) + full suite on chosen version | **Composer gate (B-3)**; sandbox | **Blocked** (composer approval + resolution evidence) |
| D-002 (N-002) | P0 | Provider failures swallowed (`FcmService.php:50-53`); job "succeeds"; tries dead; no failed_jobs | FcmService.php:50-53; Job:18-19 | rethrow + ledger | FD §7/§8/§20: FcmService throw-through; classified outcomes; T5/T6/T7 transitions; `fail($e)` for auth_config (zero-retry manual failure, vendor InteractsWithQueue.php:50 verified) | Silent loss with false success | unit: exception→classified outcome; feature: ledger rows + failed_jobs row on auth_config | scratch DB | **Closed (design)** |
| D-003 (N-003) | P1 | One token active under multiple users (Path B unique(user_id,token,platform)) | migration :40; NotificationPreferencesController.php:58-72 | global-unique + reassignment | FD §5: canonical registry, both APIs delegate to DeviceRegistry; registration = reassignment (O-2) | Cross-user push leakage on shared devices | feature: register A then B ⇒ A inactive | M-3 mobile | **Closed (design)** |
| D-004 (N-004) | P1 | Logout retains tokens (Sanctum-only deletion) | UserController.php:660-673; E-13 proof (no device identifier in logout request) | D-2 decision: deactivate vs keep | FD §5.2 O-7 + D9: **default OFF (current behavior preserved)**; optional flag-gated current-device release requires mobile body-field contract (D14/D18-adjacent) | Privacy leak on shared devices (status quo preserved until approved) | feature per policy | **PO + Mobile (B-4/B-5)** | **Open (decision)** — default preserves behavior; no silent change |
| D-005 (N-005) | P1 | Preferences ignored on Path A (27 types) | FcmChannel (no preference read); UserNotificationPreference referenced by only 3 listeners | gate at FcmChannel | FD §12: tiers + **final gate at dispatcher claim time (M-5)**; shared helper; admin exemption (DEF-18); marketing list pending PO (D8) — **nothing suppressed until approval** | Opt-out violation | feature: push_enabled=false ⇒ no job; claim-time gate test | D8 PO list | **Closed (mechanism) / Open (PO list)** |
| D-006 (N-006) | P1 | Path B invalid-token deactivation dead (string vs int codes) | PushNotificationService.php:42 vs :90-92 | map Kreait taxonomy | FD §8 classifier: `NotFound`/report `unknownTokens()` → DeviceRegistry.markInvalid (token-level, O-4); Path B legacy path deleted at cutover | Dead-token churn, wasted quota | unit: NotFound ⇒ deactivated | sandbox for exact error classes (M-4) | **Closed (design)** |
| D-007 (N-007) | P1 | Duplicate push on cancel/deliver (dual events → two push systems) | OrderService.php:1388-1396; EventServiceProvider :149-157/:164-170 | DEC-C option 2 + shared transitionId | FD §10.2/§20 F4/F5: **both** — DEC-C suppression (B skips A-owned statuses, ledger row `skipped` with reason) AND shared scope `order.transition` + same `transition:{id}` (UNIQUE claim) — belt and braces | Duplicate user notifications | feature: cancel ⇒ exactly 1 user push | — | **Closed (design)** |
| D-008 (N-008) | P1 (upgraded from Medium in 2.0 §2.1) | Simulated mode writes false `sent` rows (`services.fcm.enabled=false` default) | PushNotificationService.php:66-77; SendOrderPushNotification.php:64-71 | explicit simulated state | FD §7 T13: ledger terminal `simulated` (never `provider_accepted`); `order_notifications` gets `skipped(provider_disabled)` (D13 — no enum change); false `sent` impossible by construction | Monitoring lies | unit: disabled ⇒ no 'sent' row | — | **Closed (design)** |
| D-009 (N-009) | P2 | Per-item transient errors log-only, never retried | FcmService.php:44-48 | retry per taxonomy | FD §8: `quota`/`server` transient → rethrow (framework tries); per-item terminal classes short-circuit; attempt-row states bound retries | Partial silent loss under transient provider errors | unit: transient ⇒ retry engaged | — | **Closed (design)** |
| D-010 (N-010) | P2 | No push idempotency key | SendFcmNotificationJob (whole) | notification uuid + client dedupe (D-7) | FD §9/§10: server-side intent UNIQUE claim (per channel) + **client contract `notification_id` = decision D18 (mobile)**; honest at-least-once with W1/W2 windows documented | Occasional duplicate displays | integration: double-run ⇒ one intent; client contract test | **Mobile (D18)** | **Partially Closed** (server-side closed; client contract is a mobile decision) |
| D-011 (N-011) | P2 | All-user blast fan-out (3 jobs/user on high) | SendUserFlashSaleAvailableNotification.php:23-33 | medium queue + optional rate cap | FD §14 four-layer matrix; **marketing→medium only after D8 PO approval**; optional rate-cap config-gated default-off (2.0 §8) | Notify-storm at scale | queue-topology tests (M-3 names) | **PO (D8)**, metrics (M-6-deferral) | **Partially Closed** (mechanism ready; list unapproved) |
| D-012 (N-012) | P2 | Push locale = worker locale; preference unused | FcmChannel.php:46-55 | preference-based locale | FD §13: locale = `notification_language` preference; never `App::getLocale()` for push; Marvel `App::setLocale` hazard separate (out of scope) | Wrong-language pushes | unit: ar-pref ⇒ ar title | U-8 (prod language data) | **Closed (design)** |
| D-013 (N-013) | P2 | Coupon crash window (notify before durable state write) | NotificationRequestHandler.php:96-136 | claim-then-notify | FD §20 F6: Path C **guards unchanged** (preserved by design); ledger UNIQUE prevents duplicate *intents*; residual crash window (notify→state-write) remains a Path-C-owned pre-existing condition — ledger records outcomes, does not gate (2.0 §9 separation). **Root cause is Path C's own pipeline; changing it would alter coupon business behavior — out of scope by rule; documented** | Rare duplicate coupon push on consumer crash | pipeline crash test (pre-existing Path C test design) | PO if change requested | **Open (documented, out-of-scope by business-immutability rule)** |
| D-014 (N-014) | P3 | Ending-soon flag saved after fan-out | NotifyPromotionsEndingSoon.php:36-45 | claim-before-fan-out (cart pattern) | **Pre-existing scheduler behavior; changing flag timing alters reminder delivery timing (business timing) — requires approval; design records it as accepted pre-existing risk with the cart-pattern remedy specified** (decision D26) | Rare duplicate reminder | rerun test (pattern exists) | **PO (D26)** | **Open (decision)** |
| D-015 (N-015) | P3 | Platform reset to 'android' on re-register | DeviceTokenController.php:32 | set-if-provided | FD §17: registration sets platform only when provided (implementation detail of the adapter) | Metadata corruption | feature: re-register w/o platform keeps 'ios' | — | **Closed (design)** |
| D-016 (N-016) | P3 | Guest skip-ledger insert fails silently (NOT NULL user_id) | SendOrderPushNotification.php:131-143; order_notifications migration :12 | nullable/omit | FD: **behavior preserved** (guest orders skip ledger rows as today — insert fails silently, pre-existing); unified pipeline never inserts guest ledger rows; nullable-column change would alter schema for telemetry only — decision D27 (default: no change) | Telemetry gap (status quo) | feature: guest cancel ⇒ no exception (unchanged) | PO (D27) | **Open (decision, default = preserve)** |
| D-017 (N-017) | P3 | Missing ar `out_for_delivery`; hardcoded maps | SendOrderPushNotification.php:98-104 | translation keys | FD §13: content preserved as-is initially; translation-key consolidation = decision D25 (approved change only) | Wrong language for that status | unit | PO (D25) | **Open (decision, default = preserve)** |
| D-018 (N-018) | P3 | Sync admin listeners in request path | SendAdminLoginNotification.php:10; SendContactMessageNotification.php:10 | ShouldQueue | **Pre-existing latency coupling; queueing changes delivery timing (business-observable) — decision D28; default preserve** | Latency coupling (status quo) | feature | PO (D28) | **Open (decision, default = preserve)** |
| D-019 (N-019) | P3 | `markAsDelivered` dead state | OrderNotification.php:61 | remove or wire | **Preserved as-is** (no delivery-evidence source exists — FD §9); removal is cleanup post-cutover (D29), never a schema/API change | Misleading schema (documented) | — | PO (D29) | **Open (cleanup decision)** |
| D-020 (N-020) | P3 | `PushNotificationService::registerDevice` dead code | PushNotificationService.php:96-102 | remove/consolidate | Deleted with the class at post-cutover cleanup (FD §22); registration consolidated into DeviceRegistry (both adapters) | Divergence risk | — | — | **Closed (design); deletion gated post-cutover** |
| D-021 (N-021) | P3 | `projectId()` unused; no project cross-check | FirebaseProjectResolver.php:20-23 | validate at factory build | FD §4 FcmService contract: validate resolved project id at factory build (unit: mismatch → exception); registration-time project binding remains a future mobile-dependent item (P1-6) | Wrong-project sends | unit | M-1 env | **Closed (design)** |
| D-022 (N-022) | Info | RabbitMQ governance drift (AGENTS.md "removed" vs active transport) | Kernel comments; RabbitMqCouponEventTransport | reconcile claim | **Transport preserved unchanged** (FD §14; DEC-H); AGENTS.md text correction is a documentation-governance item for maintainers — explicitly out of this task's scope; flagged in decision register (D30) | Governance confusion | — | maintainers | **Open (documentation governance, out of code scope)** |
| D-023 (N-023) | P2 | History API shows order ledger only | NotificationPreferencesController.php:92-102 | DEC-F canonical source decision | **Preserved unchanged** (changing the history source alters the mobile contract — DEC-F/D31 deferred; documented gap stands) | Incomplete user history (status quo) | contract test once decided | **PO + Mobile (D31)** | **Open (decision)** |
| D-024 (N-024) | P2 | No stale-token sweeper | absence (grep) | scheduled sweeper | FD §5.2 O-8 + 2.1 §15: sweeper **report-only default** (config off); enforcement only via provider-confirmed invalidity + PO approval (D11); inactivity ≠ invalidity (evidence-backed) | Unbounded growth, wasted sends (bounded mitigation: send-time invalid handling) | feature: report-only; enforcement gated | **PO (D11)** | **Partially Closed** (mechanism designed; enforcement is a PO decision) |
| D-025 | P3 | Fresh Firebase Factory per token (Path B) | PushNotificationService.php:80-81 | cache like FcmService | Path B legacy path retired at cutover (FD §22) — defect disappears with the retired implementation | Quota/latency churn | — | — | **Closed (design)** |
| D-026 | Info | No .env template documenting notification keys | glob `.env*` = none | add template | Documentation item in implementation plan (non-behavioral); owner: implementer at implementation time | Setup friction | — | — | **Open (doc task)** |

## B. Security findings SEC-1…SEC-10

| ID | Sev | Problem | Final resolution (FD §) | Verification | Status |
|---|---|---|---|---|---|
| SEC-1 | High | Same-token multi-account push leakage | FD §5 O-1/O-2 (global unique + reassignment on both APIs) | feature: register A then B ⇒ A inactive | **Closed (design)** |
| SEC-2 | High | Logout retains tokens | D9 default OFF (preserve) + optional flag-gated release w/ mobile contract | feature per policy | **Open (PO+Mobile decision)** |
| SEC-3 | Medium | Token prefix in logs (PushNotificationService.php:67-70) | Path deleted at cutover; zero-token logging is an implementation acceptance criterion (M-14) | grep audit post-cutover | **Closed (design)** |
| SEC-4 | Medium | No client→project binding at registration | Send-time NotFound → markInvalid (correct); registration-time binding = future mobile-dependent enhancement (P1-6; not invented without mobile input) | sandbox live-fire | **Partially Closed** (documented residual) |
| SEC-5/6/7/8/9/10 | Low/Info | Metadata handling; DELETE 200-probing; auth/throttle; payload hygiene; broadcast auth; alert hygiene | **Unchanged by design** (behavior preservation); channel/auth untouched | existing test-pinned coverage (NOT RUN — B-1) | **Closed (no change required)** |

## C. 2.1 design defects — DEF-01…DEF-10 (aliased P0-01…P0-10; resolved by 2.2, re-verified by 2.3, finalized here)

| ID | Sev | Defect (of 2.1 design) | Final resolution (FD §) | Verification | Status |
|---|---|---|---|---|---|
| DEF-01 (P0-01) | P0 | Timestamp-only activation in merge | FD §5.4: multi-row groups → deactivated+quarantine; activation only via fresh registration (O-2/O-3) | T-A11/A12 | **Closed** |
| DEF-02 (P0-02) | P0 | Lease states conflated possibly-sent with never-sent | FD §7.1: pre-send `attempted` marker; T8 reclaim touches only unmarked claims; T9 expired-attempted→ambiguous | T-A3/A23 | **Closed** |
| DEF-03 (P0-03) | P0 | Last-writer-wins outcomes | FD §7.1: `claim_seq` fencing on every transition (C-2 matrix), 0-row stale writes | T-A22 | **Closed** |
| DEF-04 (P0-04) | P0 | Retry SQL boolean-precedence bug (selects other intents' rows) | FD §6/§7: canonical grouped SQL; per-intent selection only | T-A6 query isolation | **Closed** |
| DEF-05 (P0-05) | P0 | Ambiguous retry unenforceable | FD §7.1 T10/T11: `ambiguous_retry_used` one-shot CAS + `failed(ambiguous_exhausted)`; sweeper never clears flag | T-A23/A4 | **Closed** |
| DEF-06 (P0-06) | P0 | Dedupe key/type contradiction (cross-class suppression impossible) | FD §10.3: per-class `dedupeScope()`; key excludes type; C-7 three-case matrix | T-A7/A8a/A8b | **Closed** |
| DEF-07 (P0-07) | P0 | Coupon identity included tree_hash | FD §10.1: `coupon-notify:{run_id}:{recipient_id}`; tree_hash guard-only | coupon replay test | **Closed** |
| DEF-08 (P0-08) | P0 | Class-only error classification | FD §8: phase/errno-aware + chain-walk + conservative ambiguous | T-A21 | **Closed** |
| DEF-09 (P0-09) | P0 | Canonical-first DELETE resolution collision | FD §17.1: owner-scoped map-first primary (inherently collision-safe) + bump as defense-in-depth (DEF-17(2.3) reconciliation) | T-A24 + old/new/foreign id tests | **Closed** |
| DEF-10 (P0-10) | P0 | SDK recommendation unverified against pins | FD §15 + K-1: exact-tag evidence; A2 now a **three-file** change (V-21); graph resolution UNKNOWN → DEF-14 dry-run gate | composer dry-run record | **Blocked (B-3)** |

## D. 2.1 P1 review items P1-1…P1-13

| ID | Item | Final resolution (FD §) | Status |
|---|---|---|---|
| P1-1 | Intent/attempt creation crash recovery | FD §7.1 T1: atomic claim+attempts transaction (C-6 frozen snapshot) | **Closed** |
| P1-2 | Token race lookup→send | FD §20 F15: pre-send validation (M-7) + seconds-scale residual documented; token-level provider truth | **Closed** |
| P1-3 | Coupon event identity proof | FD §10.1 (P0-07 resolution) | **Closed** |
| P1-4 | order_notifications.failure_reason | exists (migration :29) — used for skip reasons (D13) | **Closed** |
| P1-5 | Lease vs timeout vs retry_after | FD §6.2/§7: lease 1860s; job timeout 1200; worker 1300 pinned; retry_after 1800 invariant | **Closed** |
| P1-6 | Cross-project tokens | send-time NotFound → markInvalid (correct); registration-time binding = future item w/ mobile | **Partially Closed (documented)** |
| P1-7 | Retention/replay interaction | 90d retention; post-retention replay = new intent (documented); coupon guards independent | **Closed (documented)** |
| P1-8 | `fail($e)` semantics | vendor-verified manual failure, no retry consumption | **Closed** |
| P1-9/10 | Queue topology/marketing placement | FD §14 four-layer matrix + V-16 fix; per-layer tests | **Closed (mechanism); D8 list pending** |
| P1-11 | Preference policy parity | FD §12 shared helper + claim-time gate (M-5) | **Closed** |
| P1-12 | Worker locale isolation | push never reads `App::getLocale()`; Marvel writer hazard flagged separate (out of scope) | **Closed for push; Marvel hazard documented** |
| P1-13 | Legacy-id consumers unknown | FD §17.1 id-usage-agnostic design (owner-scoped both branches); M-3 remains | **Partially Closed (M-3 open)** |

## E. 2.2.3 corrections C-1…C-8 (superseded-and-finalized)

| ID | Correction | Final design location | Status |
|---|---|---|---|
| C-1 | errno-based ConnectException classification (28/52 ambiguous) | FD §8 (consolidated with 2.3 §8) | **Closed** |
| C-2 | claim_seq fencing for every transition | FD §7.1 transition table T1-T13 | **Closed** |
| C-3 | ambiguous one-shot CAS conditions | FD §7.1 T10 — **amended by AF-1: attempts NOT incremented at flip** (K-3) | **Closed (as amended)** |
| C-4 | legacy-write shutdown + boundary re-assertion | FD §17.1 + §18 step 4 + §19 step 3; enforcement mechanism = decision D20 | **Partially Closed (D20 open)** |
| C-5 | MySQL nullable-unique; NOT NULL device ref | FD §6.2 — **amended by DEF-2(2.3): soft reference, no FK** (K-4) | **Closed (as amended)** |
| C-6 | Frozen target-device snapshot | FD §7.1 T1 (atomic claim+attempts; replay loads existing rows only) | **Closed** |
| C-7 | Dedupe three-case matrix | FD §10.3 | **Closed** |
| C-8 | Retry caps for not-transmitted | FD §7.1 T2 (claim-guard cap) + T2b terminal; **AF-1: marker is sole attempts increment** | **Closed (as amended)** |

## F. 2.3 audit defects DEF-1…DEF-19 (register items; gaps at 9/12/15 documented)

| ID | Sev | Defect | Final resolution (FD §) | Verification | Status |
|---|---|---|---|---|---|
| DEF-2 | **P0** | `device_token_id NOT NULL FK` conflicts with documented Path A hard-delete (RESTRICT breaks API; CASCADE destroys history) | FD §6.2: **soft reference (no FK) + token_hash**; attempts survive deletion; FK cascade only intent→attempts (retention-designed) | T-A10b: unregister succeeds while attempts exist; history intact | **Closed** |
| DEF-1 | P1 | "Duplicate avoided" overstated (W1/W2 windows) | FD §9: honest at-least-once; client-dedupe contract = D18 rollout criterion; duplicate forensics (§9) | T-A29 | **Closed (design); D18 open** |
| DEF-4 | P1 | Claim CAS missing attempts cap; seq read-back unspecified | FD §7.1 T2 (`attempts<3` in guard + LAST_INSERT_ID idiom) + T2b | T-A28 | **Closed** |
| DEF-5 | P1 | Legacy-write shutdown not enforced (verification ≠ prevention) | FD §17.1 + D20 (REVOKE option vs code-gate default) | T-A24 | **Open (D20 decision)** |
| DEF-6 | P1 | 6 of 9 PaymentFailed + 1 of 3 PaymentSucceeded sites lack identity | FD §10.1: `txn:{transaction_id}` per-site table (persisted transaction rows; CancelUnpaidOrders :138-143 verified this phase); fail-closed rule (M-6) for any site without persisted identity | per-site verification at implementation + T-A30 | **Closed (design; per-site grep gate at cutover)** |
| DEF-10 | P1 | Raw token snapshot in ledger | FD §6.2: token_hash only; raw token only in device_tokens; zero-token logging (M-14) | schema review + grep audit | **Closed** |
| DEF-11 | P1 | Observer events lack ShouldDispatchAfterCommit → ghost blast on rollback | Verified again this phase (grep: PromotionActivated/FlashSaleActivated/Product* absent from the 13 after-commit event files). FD §20 F16: remedy = add interface — **timing change requires approval (D19)** | T-A31 | **Open (D19 approval)** |
| DEF-14 | P1 | Composer graph resolution UNKNOWN; 7.x SendReport surface INFERRED | FD §15: isolated scratch-dir dry-run + check-platform-reqs + post-install vendor diff gate; **V-21 strengthens the case** (second pin conflict: psr/log) | dry-run record | **Blocked (B-3)** |
| DEF-16 | P1 | No pre-send exists/active/owner re-check | FD §7.1 T12 (validation before marker; skipped reasons) | T-A feature: reassigned device ⇒ skipped | **Closed** |
| DEF-3 | P2 | Intent status derivation + zero-attempt case unspecified | FD §7.2 full intent machine incl. `skipped(no_devices)` at claim + sweeper reconciliation | feature tests | **Closed** |
| DEF-7 | P2 | Marker SQL missing attempts increment | FD §7.1 T3 canonical marker SQL (attempts+1 at marker — AF-1 single authority) | T-A28 | **Closed** |
| DEF-8 | P2 | Path B entry (non-notification-class) unspecified | FD §3/K-5: adapter envelope contract (scope+identity+category+queue params) | feature test both entries same pipeline | **Closed** |
| DEF-13 | P2 | Concurrent same-token registration → unique-violation 500 | DeviceRegistry upsert semantics: catch duplicate-key ⇒ re-read/re-apply (documented implementation requirement) | concurrency test | **Closed (design)** |
| DEF-17 | P2 | AUTO_INCREMENT namespace fragile (≤5.7 restarts) | FD §17.1: map-first owner-scoped resolution = **primary**; bump = defense-in-depth | T-A24 | **Closed** |
| DEF-18 | P2 | Sweeper cadence/alerts unspecified; admin pref-row side effect | FD §21 (cadence default 1m + stuck alerts) + FD §12 (no pref-row creation for non-user recipients) | feature tests | **Closed (defaults documented)** |
| DEF-19 | P3 | DB-payload locale verified OK; Marvel mail hazard separate | FD §13 (out-of-scope flag stands) | — | **Closed (documented out-of-scope)** |
| DEF-9/12/15 | — | **Do not exist** — numbering gaps in the 2.3 register (grep-verified; never issued) | documented here; not hidden findings | — | **N/A (documented)** |

## G. M-1…M-14 mandatory corrections (2.3 §21)

| ID | Correction | Where resolved | Status |
|---|---|---|---|
| M-1 | Honest at-least-once + client-dedupe criterion + forensics | FD §9 (windows W1/W2; forensics; D18) | **Closed (design); D18 open** |
| M-2 | Claim-CAS cap + seq read-back; canonical SQL | FD §7.1 T2/T2b | **Closed** |
| M-3 | Ledger FK fix (DEF-2 P0) | FD §6.2 soft-ref | **Closed** |
| M-4 | Per-site payment identity table + namespace split | FD §10.1/§10.2 | **Closed (cutover grep gate)** |
| M-5 | Claim-time preference gate | FD §12 | **Closed** |
| M-6 | Legacy-write enforcement mechanism | FD §17.1 + **D20** | **Open (decision)** |
| M-7 | Pre-send validation step | FD §7.1 T12 | **Closed** |
| M-8 | Path A inline deletion → DeviceRegistry | FD §4 rule 4 | **Closed** |
| M-9 | ShouldDispatchAfterCommit decision | **D19** | **Open (approval)** |
| M-10 | Single-statement migration rule pinned | FD §16/§19 step 1 | **Closed** |
| M-11 | Sweeper cadence + stuck alerts | FD §21 | **Closed (defaults)** |
| M-12 | Rollback-era mobile re-registration contract | FD §18 step 7 | **Closed (documented; mobile informed)** |
| M-13 | SDK isolated resolution procedure | FD §15 | **Blocked (B-3)** |
| M-14 | token_hash ledger + zero-token logging | FD §6.2 + acceptance criterion | **Closed (design)** |

## H. Independent verification findings (V-series)

| ID | Sev | Finding | Resolution | Status |
|---|---|---|---|---|
| V-16 | P2 | `SendFcmNotificationJob` queues on `config('frontend.queue')` (webhook key) — latent misrouting | FD §14: explicit per-dispatch queue property; frontend.queue read removed | **Closed (design)** |
| V-21 | P1 | **New this phase:** kreait 7.24.1 requires `psr/log ^3.0.2`; project pins exactly `3.0.0` (composer.json:33) ⇒ A2 requires **three** composer.json changes (with guzzle). Graph resolution even less likely without explicit approval | FD §15 + D6 evidence updated | **Open (feeds B-3)** |
| AF-1 | P2 | **New this phase:** 2.2 §4.5/§15.3 increment attempts at the ambiguous flip while C-8 defines marker-only increments — double-count risk | FD §7.1 K-3/AF-1: flip does not increment; marker is the single authority; cap semantics = transmission opportunities | **Closed (design amendment)** |
| K-1…K-6 | — | Document-vs-document contradictions (SDK metadata; ledger key composition; attempts accounting; token deletion semantics; Path B entry; DEF numbering gaps) | FD §1 resolution table | **Closed** |

## I. Risks R-1…R-7 and Unknowns U-1…U-8 (information register — not defects; disposition)

| ID | Disposition in final design |
|---|---|
| R-1 (Path A non-functional in prod) | Fixed by D6 options (endpoint + failure visibility); production verification = M-4 sandbox + U-2 |
| R-2 (stuck-queue no alerting) | Out of notification-code scope; queue-depth metrics listed as DevOps item (FD §21); stuck-intent alerts added |
| R-3 (blast-scale silent loss) | D-002 fix + ledger visibility; blast shaping = D8/D11 |
| R-4 (6.7 error-string assumptions) | Replaced by exception-class taxonomy (classifier); sandbox live-fire gate |
| R-5 (strict=false truncation masking) | Documented; out of scope (DB-mode change = separate decision) |
| R-6 (Redis config unknown) | DevOps confirmation item (M-1) |
| R-7 (dual-table overlap ⇒ dual pushes) | Resolved by canonical merge + DEC-C suppression + shared dedupe scope |
| U-1…U-8 | Carried as M-1…M-8 missing-information items in DISCOVERY; blockers B-1/B-5/B-6; none assumed resolved |

## J. Execution-board items and blockers

| Item | Disposition |
|---|---|
| T00-T07 (Phase 0) | VERIFIED/complete (baseline doc) |
| P0-01…P0-10, P1-1…P1-13, C-1…C-8 | Finalized in §C/§D/§E above |
| G-1/G-2 (design + decision presentation) | Superseded by FD + final decision register |
| G-3 (STOP for approval) | **Still ACTIVE** — this matrix does not grant approval |
| I-01…I-14, TST-01…TST-22, AUD-1… | TODO — blocked on design approval + B-1 |
| Blockers B-1…B-6 | Carried unchanged: B-1 DB unreachable · B-2 design approval · B-3 composer gate · B-4 PO decisions · B-5 mobile confirmations · B-6 sandbox |

## K. Closure statistics

| Status | Count | Items |
|---|---|---|
| Closed (design) | 63 | D-001/2/3/5-12/15/20/21/25, SEC-1/3/5-10, DEF-01…08 (2.2), DEF-2/1/4/16/3/7/8/13/17/18/19 (2.3), P1-1/2/3/4/5/8/9-10/11(push)/12(push), C-1…C-8 (as amended), M-1/2/3/4/5/7/8/10/11/12/14, V-16, AF-1, K-1…K-6 |
| Partially Closed | 9 | D-010/D-011/D-024, SEC-4, P1-6/P1-13, C-4(→D20), M-6(→D20), D-022 |
| Open (explicit decision/approval) | 14 | D-004 (D9), D-013 (Path C window — business rule), D-014 (D26), D-016 (D27), D-017 (D25), D-018 (D28), D-019 (D29), D-023 (D31), DEF-5 (D20), DEF-11 (D19), DEF-14 (B-3), M-6 (D20), M-9 (D19), M-13 (B-3), V-21 (B-3) |
| Blocked | 3 | D-001 (composer+sandbox), DEF-14 (composer), M-13 (composer) |
| N/A documented | 3 | DEF-9/12/15 (numbering gaps) |

Every P0 finding (D-001, D-002, DEF-01…DEF-10, DEF-2) has a technically complete resolution recorded above; the P0s that remain unexecuted are gated on the listed approvals (composer/DB/approval), not on missing design.
