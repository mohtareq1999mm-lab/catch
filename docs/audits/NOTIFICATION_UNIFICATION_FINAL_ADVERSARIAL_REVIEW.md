# Notification Unification — FINAL ADVERSARIAL REVIEW (Phase 3.1)

**Date:** 2026-11-14 · HEAD `2f258c05f797cd8024d174da870755f08b08f6c0` · READ-ONLY
**Reviewer stance:** independent engineer who did not write the design. Objective: find reasons the design should NOT be accepted. The design under review is `NOTIFICATION_UNIFICATION_FINAL_DESIGN_CANDIDATE.md` (FD). Nothing below is Tested; scenario outcomes are design-derived, with the proving test named.

---

## §1 Failure-scenario exercise (46 scenarios)

Legend: **Dup** = duplicate delivery possible · **Loss** = data/notification loss possible · **XU** = cross-user exposure possible · **Stuck** = unrecoverable state without operator · **Rec** = recovery path. "Design-proven" = the FD's SQL/state machine demonstrably handles it; "residual (documented)" = bounded, honestly stated residual; "OPEN" = gated on a decision.

| # | Scenario | Expected | Proposed behavior | Dup/Loss/XU/Stuck | Recovery | Required test |
|---|---|---|---|---|---|---|
| 1 | Two workers claim the same attempt | exactly one winner | CAS + seq bump — loser affects 0 rows | none | automatic | T-A2 concurrency |
| 2 | Stale worker resumes after lease expiry, marker already written, sends | no duplicate if fenced | **HTTP send happens anyway (W1)** — fence guards DB, not an authorized external request; result write fails (0 rows, logged) | **Dup: yes (bounded by cool-down, not eliminated)** | forensic log `push.send.accepted` | T-A29 (window proof) |
| 3 | Stale worker resumes after lease expiry, marker NOT yet written | abort without send | T3 marker fence fails (seq bumped by reclaim) ⇒ worker aborts pre-HTTP | none | automatic | T-A22 |
| 4 | FCM accepts, response lost (timeout after processing) | retry once, possible duplicate | T7 ambiguous → T10 one-shot flip → retry send (W2) → definite outcome or `failed(ambiguous_exhausted)` | **Dup: yes (single retry)** | forensic log; terminal state | T-A23/A4 |
| 5 | FCM rejects (4xx/5xx response present) | classified definite outcome | per-class table: invalid_token → markInvalid; invalid_payload → failed; auth_config → fail-all + `fail($e)`; quota/server → retry | none | automatic | T-A21 unit matrix |
| 6 | DB unavailable after provider acceptance | outcome eventually recorded | row stays `attempted` → sweeper T9 → ambiguous → one-shot retry (W2 dup possible) | Dup: rare | sweeper | sweeper test |
| 7 | Worker crash between marker and HTTP | never-sent must be recoverable | `attempted` (attempts+1 already) → T9 ambiguous → one-shot | Dup: only W1-type if resumed worker sends | sweeper/flip | crash-window test |
| 8 | Sweeper converts expired-`attempted` while worker is mid-send | conservative handling | straggler's result write fails (seq bumped at T9) — logged; row conservative-ambiguous | Dup: rare (W1) | forensics | T-A9b |
| 9 | Token reassigned (A→B) while attempt pending | no push to wrong intent | T12 pre-send validation (owner ≠ recipient) ⇒ `skipped(skipped_reassigned)` | none (residual: reassignment *during* HTTP send — seconds-scale, documented XU-adjacent privacy residual) | automatic | pre-send validation test |
| 10 | Invalid-token response arrives after reassignment | must not corrupt B's registration wrongly | O-4: token-level markInvalid — the token string is dead for everyone; B's registration deactivated **correctly**; old intent's attempt `failed(invalid_token)` claim-fenced | none | automatic | token-level test |
| 11 | Duplicate event dispatch (same identity+scope+recipient+channel) | one send | second intent claim insert-or-ignore loses UNIQUE | none (counted duplicate) | automatic | T-A7 |
| 12 | Same event via Path A and Path B | one push | shared `order.transition` scope + same transitionId + DEC-C suppression (double fence) | none | automatic | dual-entry test |
| 13 | Coupon replay after content change | no new event | identity = run/recipient PKs; tree_hash guard-only | none | Path C guards (unchanged) | coupon replay test |
| 14 | Queue redelivery of the notification job | idempotent | terminal attempt states short-circuit dispatcher before any provider call | none | automatic | redelivery test |
| 15 | Deployment/worker restart during jobs | no dup/loss | lease 1860s > retry_after 1800; job timeout 1200 < worker 1300 | none | retry_after | invariant test |
| 16 | Rollback with canonical-era registrations | documented friction | reverse-sync + boundary re-assertion; canonical ids unresolved by old code ⇒ **mobile re-registration required (M-12)** | none (documented) | runbook | rollback drill |
| 17 | Legacy writes during canonical period (old code reactivated) | collision-proof | code-gate default; REVOKE option (D20); map-first resolution never misresolves owner-scoped; boundary re-assertion restores namespace | none if D20 followed; **enforcement-by-convention risk if REVOKE declined** | runbook | T-A24 |
| 18 | Two users register same token concurrently | no 500, deterministic owner | registry upsert catches duplicate-key ⇒ re-read/re-apply (DEF-13 fix) | none | automatic | concurrency test |
| 19 | User disables push after intent creation, before send | honor opt-out | claim-time gate (M-5) — final enforcement at dispatcher claim | none | automatic | T-A19b |
| 20 | User changes language mid-pipeline | per-send locale | locale read at dispatch from preference (per job) — window: change after read, before build | minor | automatic | locale test |
| 21 | Claim refused at attempts cap (4th) | terminal | T2 guard `attempts<3` fails → T2b `failed(not_transmitted_exhausted)` | none | terminal | T-A28 |
| 22 | Ambiguous CAS race (two flippers) | one winner | CAS conditions complete (status+flag+cooldown+attempts<3) | none | automatic | T-A23 |
| 23 | Worker timeout (1300s) vs lease | no dup | lease 1860 > all windows | none | automatic | invariant |
| 24 | Provider 429 rate limit | retry with Retry-After | converter :122 honored; transient → rethrow (framework backoff) | none | automatic | unit |
| 25 | Simulated mode (`FCM_ENABLED=false` default) | no false success | T13 ledger `simulated`; order_notifications `skipped(provider_disabled)` | none | automatic | unit: no 'sent' row |
| 26 | Partial migration failure mid-chunk | no partial state | chunk-abort-safe; quarantine keyed by source ids; re-run idempotent | none | re-run | migration drill |
| 27 | Retention deletes attempt before late replay | documented replay | post-retention replay ⇒ new intent ⇒ re-send (bounded by 90d + queue lifetimes) | Dup: documented | retention config | retention test |
| 28 | Ghost blast on transaction rollback (observer events) | none | **possible today: events lack after-commit (verified)** — mitigation = D19 (timing change approval) | Dup: yes until D19 | D19 | T-A31 |
| 29 | Intent with zero attempts (no devices) | not stuck | `skipped(no_devices)` at claim (DEF-3) | none | automatic | zero-device test |
| 30 | Result-write lost (worker dies after send, before outcome write) | eventually reconciled | row `attempted` → T9 → ambiguous → one-shot (W2 dup possible) | Dup: rare | sweeper | crash test |
| 31 | Two dispatchers create the same intent concurrently | one row | UNIQUE(dedupe_key) insert-or-ignore | none | automatic | concurrency test |
| 32 | Attempt for hard-deleted device row (user DELETE during flight) | history survives | soft-ref + token_hash; attempt continues to token string (provider truth); outcome recorded; no FK violation | none | automatic | T-A10b |
| 33 | Path B synchronous call with no devices | ledger row preserved semantics | dispatcher returns zero-attempt outcome ⇒ `markAsSkipped('No active devices')` unchanged | none | automatic | contract test |
| 34 | Preference row missing (first-user) | default allow (transactional) | firstOrCreate defaults all-true; admins exempt (no row creation — DEF-18) | none | automatic | prefs test |
| 35 | Guest order status change | no crash, no row | B listener skips guests (unchanged); ledger never inserts guest rows (D27 default preserve) | none | automatic | guest test |
| 36 | `notification_language` invalid value | fallback | preference resolver falls back to default language constant | none | automatic | locale test |
| 37 | Event without designed identity (new emit site added later) | fail-closed | `skipped(identity_unavailable)` — never unscoped send, never random identity (M-6) | none (push skipped — visible in ledger) | add identity | fail-closed test |
| 38 | Broadcast/Pusher outage | channels independent | broadcast job fails independently (failed_jobs); DB + FCM unaffected | Loss: broadcast-only | queue retry | channel-isolation test |
| 39 | Queue outage (database driver down) | documented window | dispatch after commit; job lost before claim ⇒ notification lost (R-2) — **honest loss window**; stuck-intent alerts don't apply (intent never created) | Loss: yes (pre-existing queue semantics, documented) | queue monitoring (OPS) | ops runbook |
| 40 | enqueue of a marketing blast while medium queue has no worker | visibility | medium worker exists (WorkerConfigPolicyTest); misconfig would starve marketing only | Loss: marketing until worker | ops | config test |
| 41 | `claimed_by` collision (two workers same id string) | seq is the fence | result writes require seq+owner+status; owner string collision cannot bypass seq | none | automatic | fencing test |
| 42 | AUTO_INCREMENT revert (≤5.7 restart) mid-canonical | no misresolution | map-first owner-scoped primary (collision-immune); zero-overlap query detects | none (detection) | boundary recompute | T-A24b |
| 43 | Old mobile sends legacy id after cutover | works | legacy-map-first resolution (owner-scoped) → canonical | none | automatic | old-id tests (5 cases per 2.1 §12.5) |
| 44 | Old mobile sends legacy id of *another user's* device | 404 | owner-scoped resolution: map entry carries user_id; mismatch ⇒ not owned ⇒ 404 | none | automatic | foreign-id test |
| 45 | Ledger dedupe key collision (sha256) | practically impossible | full 64-hex, no truncation; collision ⇒ second claim rejected (harmless: one push missed, logged) | Loss: theoretical-only | log alert | n/a |
| 46 | FcmService credential file missing (prod misconfig) | loud failure | classified definite-not-transmitted → `failed(auth_config)` → `fail($e)` → failed_jobs → existing admin alert (D-002 fix) | none | ops | unit + feature |

**Scenario verdict:** no scenario causes unrecoverable state or unbounded duplication. Duplicates are confined to W1/W2 (documented, bounded, forensically detectable). Cross-user exposure is confined to the seconds-scale reassignment-during-send residual (documented). Loss windows: queue-loss (pre-existing, documented) and fail-closed identity-unavailable skips (visible in ledger). The state machine cannot wedge: every non-terminal state has an authorized transition and a sweeper safety net (M-11).

---

## §2 Contradiction review across design surfaces

| Surface pair | Checks performed | Result |
|---|---|---|
| Responsibility matrix ↔ state machine | every transition's actor appears as an owner in §4 of FD; no component writes state it does not own (sweeper restrictions verified) | **Consistent** |
| State machine ↔ SQL conditions | all 13 transitions T1–T13 carry complete atomic guards incl. seq/owner/status/lease/cap/cooldown; T2 includes the DEF-4 cap; T3 is the sole `attempts` increment (AF-1); T10 excludes attempts increment; terminals excluded from all selection guards | **Consistent (as amended)** |
| State machine ↔ sequence flows | all 18 flows (FD §20) reference only legal transitions; F4/F5 use DEC-C + scope suppression together; F13/F14 match W2/W1 wording | **Consistent** |
| SQL ↔ DB schema | soft-ref device_token_id NOT NULL + UNIQUE(intent, device) — no nullable-uniqueness reliance; FK only intent→attempts (cascade = designed deletion); legacy-refs/quarantine soft-ref only | **Consistent** |
| Schema ↔ queue topology | no queue column in ledger (queue is per-dispatch job property) — routing lives in code, not data | **Consistent** |
| Queue topology ↔ API contracts | no API surface change from queue routing; `device_uuid` additive only | **Consistent** |
| API contracts ↔ ownership matrix | both registration endpoints delegate to DeviceRegistry (single writer); envelopes preserved; hard-delete vs deactivate coexistence documented (K-4) | **Consistent** |
| Decision register ↔ design text | every OPEN decision has a default-preserving behavior in FD (D9 off, D10 keep, D11 report-only, D19 not-yet-applied, D20 code-gate default, D24-D28 preserve) | **Consistent** |
| Rollback ↔ cutover | §18 steps reference the exact artifacts §19 creates; boundary re-assertion appears in both; reverse-sync precedes traffic | **Consistent** |
| Closure matrix ↔ FD | every Closed finding cites an FD section that resolves the root cause; no closure cites a paragraph mention only | **Consistent** |

**No unresolved contradiction remains between design surfaces.** One cross-document evidence contradiction (SDK metadata, K-1) was resolved with primary evidence this phase; one SQL-accounting contradiction (K-3/AF-1) was resolved in favor of C-8's own definition.

---

## §3 Independent review findings (this reviewer's own)

| ID | Sev | Finding | Disposition |
|---|---|---|---|
| FR-1 | P2 | The ambiguous-flip `attempts` increment (2.2 §15.3) contradicted C-8's marker-only definition — a real double-count that would halve the effective retry budget on the ambiguous path | **Resolved in FD** (AF-1/K-3): flip does not increment; cap semantics = transmission opportunities |
| FR-2 | P1 | V-21: kreait 7.24.1 requires `psr/log ^3.0.2` vs pinned `3.0.0` — **Option A2 is a three-file Composer change**, strengthening DEF-14's "graph resolution unknown" to "demonstrably conflict-laden" | **Recorded** in FD §15 + decision D6; dry-run gate mandatory |
| FR-3 | P2 | 2.2.3 C-5's "tokens deactivated, never hard-deleted" silently contradicted the documented DELETE contract — would have been an unflagged API behavior change | **Resolved in FD** (K-4): both removal semantics coexist by contract; ledger soft-refs survive both |
| FR-4 | P3 | DEF numbering gaps (9/12/15) in the 2.3 register could hide findings from a reader trusting sequential IDs | **Documented** in CM (numbering gaps, never issued) |
| FR-5 | P2 | The marvel EventServiceProvider wires the **push-bearing App listener** `SendUserOrderDeliveredNotification` onto `Marvel\Events\OrderDelivered` — if that Marvel event fires in production, a duplicate-delivery path exists **outside** the app pipeline's dedupe reach | **Recorded** as FD §10.2 cutover-time grep gate + §10.2 namespace rule; no change made (Marvel boundary); resolution requires verifying Marvel `OrderDelivered` dispatch reality at cutover — **residual risk documented** |
| FR-6 | P3 | `sendMulticast` chunk limit 500 (6.7) is preserved by design on Option B; on 7.x the limit is lifted — per-user device sets far below 500 make this immaterial | Documented (no action) |
| FR-7 | P2 | Queue-loss window (committed event, job never claimed) is a genuine loss window that no ledger can observe (intent never created) | **Honestly documented** (scenario 39); mitigation is queue monitoring (OPS), not notification code — matches the mission's honesty requirement |
| FR-8 | P3 | Sweeper without a schedule = stuck states (claimed/attempted forever) | **Gated** by D21 defaults + stuck-alerts; operational requirement recorded |

## §4 Residual risks (accepted and documented, none P0/P1 design defects)

1. **At-least-once with W1/W2 duplicate windows** — provable consequence of "no provider idempotency key"; client-dedupe contract (D18) is the UX mitigation; forensics detect occurrences. *Not a design defect: it is the honest optimum for the provider's actual guarantees.*
2. **Seconds-scale reassignment-during-send residual** — pre-send validation cannot close a window that opens after validation and before the HTTP send completes.
3. **Path C crash window (D-013)** — pre-existing, business-owned pipeline; ledger records outcomes without gating; changing Path C internals would alter coupon business behavior (out of scope by rule).
4. **Ghost-blast on rollback for observer events** — exists today; remedy specified and gated on D19 (timing change approval). Not silently applied.
5. **Legacy-write freeze enforcement** — default is convention + audit; DB-enforced fence requires the D20 choice.
6. **Queue-loss window** — pre-existing Laravel queue semantics; monitoring is the mitigation (R-2/OPS).
7. ** marvel namespace dual-wiring (FR-5)** — verified wiring fact; dispatch reality requires cutover-time verification; any change touches Marvel (protected) and needs the no-business-change evaluation.

## §5 Verdict

**CONDITIONALLY-READY.** The reconciled design (FD, as amended by AF-1 and the K-resolutions) survives 46 adversarial scenarios with no unresolved P0/P1 *design* defect. The blocking items are environmental/decisional, not architectural: Composer graph resolution (DEF-14/V-21, three-file change under D6), Product-Owner decisions (D8-D12, D18-D20, D24-D28, D31), Mobile confirmations (M-3, D9, D14, D18), DB availability (B-1 — nothing is Tested), MySQL/DDL evidence (D22), sandbox live-fire (M-4/B-6), and the design-gate approval itself (B-2). The two residual design-adjacent risks (FR-5 marvel dual-wiring; queue-loss window) are documented with cutover-time verification gates, not hidden.

**This review authorizes nothing. Implementation remains blocked on the explicit gates above.**
