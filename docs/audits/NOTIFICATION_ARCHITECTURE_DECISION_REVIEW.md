# NOTIFICATION_ARCHITECTURE_DECISION_REVIEW.md

**Independent verification & final decision review** of `docs/audits/NOTIFICATION_ARCHITECTURE_DECISION.md` (the "original report").
**Task type:** READ-ONLY discovery · independent technical verification · architecture decision review · risk analysis. **No implementation authorized.**
**Review performed:** 2026-11-11 (external sources checked this date; all URLs in §3.4)
**Repository HEAD at review:** branch `main`, commit `8392120d790a0e0e6dc76371c80047b541635757` ("feat: add strict origin validation middleware and CORS configurations", 2026-11-11 17:57:00 +0200). Working tree clean; both prior audit reports are committed (commit `907d996`).

**Evidence classes:** VERIFIED · TESTED · INFERRED · UNKNOWN · RISK · REQUIRES APPROVAL.

---

## 1. Executive Verdict

**The original report's DECISION OUTCOME is correct and is confirmed: no final selection between Option A and Option B is supportable today; Option C is the required temporary posture. However, the original report contains material errors that must be corrected:**

| # | Error in original report | Severity | Correction |
|---|---|---|---|
| E-1 | **Decision-matrix arithmetic is wrong and internally inconsistent.** Option A's displayed formula `(25·5 + 20·3 + 15·4 + 5·5)/90` evaluates to 270/90 = **3.00**, not the claimed **4.28**; it also uses weight 15 for the 10%-weight delivery criterion and omits the 15·5 integrity term entirely. Option B's displayed formula `205/85` evaluates to **2.41**, not the claimed **2.94** (denominator 85 is wrong — scoreable weights sum to 75). | MATERIAL | Recalculated from scratch (§9): A = **4.33**, B(hardened) = **4.33**, B(as-is) = **2.73**, C = **3.30** (the only original computation that was correct). |
| E-2 | **Option B is defined inconsistently.** The original defines B as requiring the global-token-uniqueness change ("or keep composite — unacceptable: preserves the leak") yet scores B's security as 2 (composite semantics). Under its own definition (hardened B), the end-state security equals A's. | MATERIAL | B must be scored in its hardened form (the only form that fulfills unification's purpose). Corrected result: **A and B converge to identical end-state scores (4.33 scoreable-only)** — the A-vs-B choice rests entirely on production evidence, not on the matrix. |
| E-3 | **Target-design token uniqueness is structurally imprecise.** The original target says `token UNIQUE` (global). Official Firebase documentation proves an FCM registration token is **bound to its Firebase project/sender** — cross-project sends fail with `SENDER_ID_MISMATCH` (403). The correct uniqueness key is **(client, token)** — project-scoped — not bare `token`. The current `device_tokens.token` global UNIQUE is practically safe (cross-project token-string collisions are astronomically improbable) but structurally wrong, and the `client` field is **client-supplied and never validated against the token's actual project**. | MATERIAL (target design) | Target invariant corrected to: single live owner per **(client, token)**, reassignment scoped per (client, token) (§5). |
| E-4 | Minor: the original states Option A's scoreable weight as "90/100"; the actual scoreable weight is **75/100** (25% of decision weight — production/mobile 15% + migration 10% — is UNKNOWN). | MINOR | Corrected in §9 with explicit evidence-coverage percentages. |

**What the original got right (independently re-verified):** all four endpoint contracts (§4), the mutual-exclusivity proof (both endpoints' current ownership behaviors cannot coexist under one table invariant — §6), the writer/reader matrix, the delivery/reliability traces, the security findings, the strict production-evidence discipline, and the final "no forced decision" outcome. The corrected analysis makes the recommendation **stronger and more honest**: the scoreable matrix cannot distinguish A from B — only the production inventory can.

**Upgraded outcome classification (per the review's decision taxonomy):** from "no responsible selection" to **"Conditional architecture recommendation"**: the preferred *target architecture* is now well-defined and evidence-independent (canonical **(client, token)**-scoped registry, single-owner + last-registration-wins reassignment, per-endpoint response adapters); what remains conditional is (i) the migration FORM (A-table vs B-table), (ii) user approvals (§13), (iii) production evidence (§14).

---

## 2. Repository and HEAD Verification

| Item | Value | Status |
|---|---|---|
| Repository | `D:\work\meem` | VERIFIED |
| Branch / HEAD | `main` / `8392120d790a0e0e6dc76371c80047b541635757` (2026-11-11 17:57:00 +0200) | VERIFIED |
| Working tree | clean | VERIFIED |
| HEAD history during today's investigation | `6cfb63a` → `fe31ca5` → `8392120` | VERIFIED |
| Delta `6cfb63a..fe31ca5` | guest-cart files only | VERIFIED (diff --stat) |
| Delta `fe31ca5..8392120` | guest identity/CORS + `.env.example` +2 (guest cookie names only) + the two committed audit reports | VERIFIED (diff --stat + explicit `.env.example` diff) |
| **Notification code changes across both deltas** | **NONE** — every previously cited file/line re-confirmed identical | VERIFIED |
| Laravel / PHP | 10.30.1 / 8.2 (project runtime; Dockerfile `php:8.2-cli-alpine`) | VERIFIED |
| Packages | `kreait/firebase-php 6.7.0`, `pusher/pusher-php-server 7.2.4` | VERIFIED (composer.lock) |
| Prior reports | both committed, unmodified since creation | VERIFIED (git log) |

---

## 3. Independent Evidence Audit

### 3.1 Re-verified core claims (all VERIFIED at HEAD `8392120`; line numbers unchanged from the original report)

| Claim | Evidence | What it proves / does NOT prove |
|---|---|---|
| Path A reads only `device_tokens` | `app/Jobs/SendFcmNotificationJob.php:42-43` | Proves reader exclusivity in code; does NOT prove production data location |
| Path B reads only `user_device_tokens` | `app/Services/Notifications/PushNotificationService.php:22` | Same caveat |
| A-register reassignment semantics | `app/Http/Controllers/Api/General/DeviceTokenController.php:27-35` (updateOrCreate keyed by `token` alone) | Proves ownership-overwrite behavior; does NOT prove clients rely on it |
| B-register coexistence semantics | `app/Http/Controllers/Api/User/NotificationPreferencesController.php:58-72` (key includes `user_id`) | Proves second-active-row behavior for cross-user re-registration |
| Path A provider exceptions swallowed | `app/Services/Firebase/FcmService.php:50-53` (catch inside `sendToClient`; `sendMulticast` at `:34`) | Proves no retry/failed_jobs for provider errors; runtime frequency UNKNOWN |
| Path B simulation mode | `PushNotificationService.php:14,66-77` (`services.fcm.enabled` default false; fake `success`+`test_` id) | Proves code path; production env state UNKNOWN |
| Batch endpoint in installed SDK | `vendor/kreait/firebase-php/src/Firebase/Messaging/Http/Request/SendMessages.php:48` → `https://fcm.googleapis.com/batch` | Proves installed 6.7.0 uses the retired endpoint; does NOT prove production sends fail (no runtime evidence anywhere — local log: 0 FCM HTTP matches) |
| Cross-path duplicate dispatch (delivered/cancelled) | `app/Services/General/OrderService.php:1388,1391,1395` + wiring `EventServiceProvider.php:154-157,164-168` | Proves both paths fire on the same transitions; actual double-delivery requires dual-registered devices (production prevalence UNKNOWN) |
| Logout does not unregister tokens | `packages/marvel/src/Http/Controllers/UserController.php:668` (Sanctum token only) | Proves server behavior; client-side unregister behavior UNKNOWN |
| No duplicate-send protection | grep at HEAD: 0 matches for `ShouldBeUnique|lockForUpdate` in `SendFcmNotificationJob.php` and `SendOrderPushNotification.php` | Proves absence in these senders |
| Endpoint auth | `routes/api.php:215,273-274,323-329` (`auth:sanctum` + throttle groups) | Proves auth; not client usage |

### 3.2 TESTED items (from the prior sessions; nothing re-executed in this review)
- Local dev DB (`catch` @ 127.0.0.1): both token tables 0 rows; `notifications` 30 rows; `failed_jobs` 0 / `jobs` 320 pending; `users` max id 35 (SELECT aggregates only).
- Local log scan (137 MB): 0 matches for `fcm.googleapis.com`, `FCM send failed`, `FCM transient failure`, `FCM skipped`; 1 historical `SendFcmNotificationJob failed permanently` (missing table, 2026-10-12 — proves DB-level failures propagate while provider-level cannot); 1 real-context Path B run for `user_id=616` (2026-10-11) on a non-local dataset (identity UNKNOWN).
- These remain LOCAL evidence; production remains UNKNOWN.

### 3.3 SDK-level evidence (installed 6.7.0 + target 7.x, source-level)
- 6.7.0: `sendMulticast` → `SendMessages` → multipart POST `https://fcm.googleapis.com/batch` (VERIFIED, vendor source).
- 7.x (checked 2026-11-11): `sendMulticast`/`sendAll` are reimplemented as **pooled per-message requests** — `Messaging.php` (7.x) builds one `createSendRequestForMessage` per token and uses `messagingApi->pool(...)->wait()`; **no batch endpoint**; `BATCH_MESSAGE_LIMIT` deprecated since 7.5.0. Even `send()` routes through `sendAll([one])`.
- ⇒ Upgrading to the 7.x line fixes the batch-endpoint exposure **without changing the application's call sites** (`sendMulticast` API preserved).

### 3.4 External authoritative sources (all checked 2026-11-11)
| Claim | Source URL | Note |
|---|---|---|
| Token is sender/project-bound; cross-project send fails `SENDER_ID_MISMATCH` (403) | https://firebase.google.com/docs/cloud-messaging/error-codes (page updated 2026-11-10) | "A registration token is tied to a certain group of senders… If you switch to a different sender, the existing registration tokens won't work." |
| `messaging/mismatched-credential`: credential and token must belong to the same Firebase project | https://firebase.google.com/docs/reference/fcm/rest/v1/ErrorCode | Official REST error reference |
| Cross-project sending impossible; iOS supports multiple sender tokens per app instance (`retrieveFCMToken(forSenderID:)`) | https://github.com/firebase/firebase-android-sdk/issues/6189 (official Google response, Aug 2024) | Grounds (client, token) scoping AND the multi-token-per-device nuance |
| Legacy FCM APIs deprecated 2023-06-20; shutdown began 2024-07-22 | https://firebase.google.com/docs/cloud-messaging/migrate-v1 (updated 2025-12-18) | |
| Admin-SDK `sendAll`/`sendMulticast` deprecated in favor of per-message `sendEach` | https://firebase.google.com/docs/reference/admin/java/reference/com/google/firebase/messaging/FirebaseMessaging | Google's own SDKs moved off batching |
| Legacy API decommission timeline (residual endpoints to 2027) | https://firebase.google.com/docs/cloud-messaging/troubleshooting (updated 2026-10-28) | |
| kreait 8.6.0 requires PHP ~8.3+; **7.x requires ~8.1–8.5 (PHP 8.2-compatible)** | https://packagist.org/packages/kreait/firebase-php ; https://github.com/beste/firebase-php/blob/7.x/composer.json | 8.x NOT compatible with this project's PHP 8.2 |
| Package advisory CVE-2018-1000025 affects only ≥3.2.0,<3.8.1 — installed 6.7.0 NOT affected | https://packagist.org/packages/kreait/firebase-php/advisories | No security-forced upgrade |
| 7.x `sendMulticast` = pooled per-message sends (no batch) | https://github.com/beste/firebase-php/blob/7.x/src/Firebase/Messaging.php | Grounds the D8 remediation option |

---

## 4. Corrected API Contract Matrix

The original report's §7 contract extraction was **independently re-verified and is accurate** (trait wrapper `packages/marvel/src/Traits/ApiResponse.php:14-21` — `data` key present only when non-empty; error wrapper `app/Exceptions/Handler.php:226-236` — `status:false` bool + optional `errors`; 422/401 shapes `:178-187,:168-171`; the trait-vs-Handler `status` type asymmetry is real and documented). Exact frozen contracts (summary; full detail remains in the original §7):

| Endpoint | Success body (exact) | Error shapes | Behavior pins |
|---|---|---|---|
| POST /api/v1/general/device-tokens | `{status:200, message:"Device token registered", success:true, data:{uuid}}` | 422 Handler+`errors`; 401 Handler; 429 Handler | reassignment on any re-registration |
| DELETE /api/v1/general/device-tokens | `{status:200, message:"Device token removed", success:true}` — **no `data` key** | as above | owner-scoped delete; foreign token → silent 0-row delete |
| POST /api/v1/user/devices/register | `{status:200, message:"Device registered successfully", success:true, data:{<13 model fields>}}` | 422/401 Handler | **coexistence** for cross-user registration |
| DELETE /api/v1/user/devices/{deviceId} | `{status:200, message:"Device unregistered", success:true}` | 404 via **trait** shape `{status:404,…,success:false}` (`:83-85`) | owner-scoped soft deactivate |

Behavioral-vs-response distinction (per the review mandate): JSON responses and ownership behaviors are separately pinned; unification changes ONLY ownership behavior (never response JSON) and requires approval (D1). Test coverage note: `FcmIntegrationTest` pins 422/401 statuses only; `NotificationPreferencesTest` pins 200+`success` only — **success bodies are source-derived, not test-pinned** (a contract-test gap; §11 of the review = future work).

---

## 5. FCM Identity and Ownership Analysis (independent investigation — §4.1)

### 5.1 What an FCM token actually is (authoritative)
- A registration token is assigned **per Firebase app instance**, and is **cryptographically tied to its Firebase project/sender** at creation: sending it with a different project's credentials fails with `SENDER_ID_MISMATCH` (403) / `messaging/mismatched-credential` (§3.4, Firebase docs + official GitHub response).
- On iOS, **multiple sender tokens per app instance are possible** (per-sender `retrieveFCMToken(forSenderID:)`) — the same physical device can legitimately hold *different tokens for different projects*.
- Therefore the **correct logical uniqueness key is (firebase_project, token)** — in this application's vocabulary: **(client, token)**, since `client` (`client_a`/`client_b`, `DeviceTokenController.php:16`) selects the Firebase project via `firebase.clients.*` (`config/firebase.php:4-15`) and `FirebaseProjectResolver` (`:9-18`).
- Concept separation (mandated): **FCM token ≠ Firebase project/sender ≠ mobile app ≠ physical device ≠ installation ≠ user account.** `device_id` is client-supplied, optional, and unvalidated — it cannot establish physical-device identity (advisory metadata only).

### 5.2 Assessment of the candidate uniqueness keys
| Key | Verdict | Reasoning |
|---|---|---|
| `token` (global) — current `device_tokens` | **Structurally incorrect, practically safe** | Treats tokens from different projects as one identity; safe only because cross-project token-string collision is astronomically improbable. Also conflates a client mis-registration: the same string registered under a different `client` silently re-points the row to the wrong project. |
| `(client, token)` | **RECOMMENDED** | Matches the provider reality (sender-scoped tokens); matches the dispatch design (`SendFcmNotificationJob.php:46` groups by `client` and sends each group with its own project's credentials); handles the iOS multi-sender case correctly. |
| `(firebase_project, token)` | Equivalent to `(client, token)` once `client` ↔ project binding is 1:1 and validated | Same semantics; naming only. |
| `(user_id, token)` / `(user_id, token, platform)` — current Path B | **Rejected** | Ownership-in-key: same token under two accounts = two live rows = stale-owner delivery (the leak). Also lacks project scoping. |
| `(client, token, platform)` | Rejected as the *identity* key | One physical installation rotating between platforms is not a new identity; platform belongs as a mutable attribute. |

### 5.3 Server-side client↔token binding gap (NEW finding)
`client` is accepted from the request (`Rule::in(['client_a','client_b'])`) with **no verification that the token actually belongs to the declared project**. A client bug (or an attacker with a valid account) can register a project-B token under `client_a`; the row then receives sends with project-A credentials, which fail permanently with `mismatched-credential` — and `FcmService` swallows the failure (`:50-53`) → **silent permanent loss for that device**, with the invalid-token cleanup never triggered (the failure is a thrown exception swallowed at the top, not a per-item report). Classification: **RISK (verified code path; prevalence UNKNOWN)**. Remediation options (both REQUIRES APPROVAL — they change endpoint behavior for inputs that currently succeed): (a) post-registration binding check (e.g., Kreait `validateRegistrationTokens()` per client) with rejection; (b) async binding verification with deactivation. See D14.

### 5.4 Last-registration-wins: assessment (mandated neutrality)
- **What it provides:** a clean, enforceable security property — at most one live owner per (client, token); after an account switch, the previous account stops receiving pushes on that installation. It requires no trusted physical-device identity (which the application does not have — `device_id` is advisory).
- **What it costs (UX):** the previous account silently loses push on that device; if the app expects per-account pushes on a shared device, it must register per account context. There is **no Firebase-level requirement** for last-registration-wins — it is an application ownership policy (explicitly noted per the review mandate).
- **Alternative policies:** per-account registration requires the mobile app to send an account context with each registration and the server to keep multiple owners per token — which recreates the leak unless combined with device-bound session scoping the current schema cannot express. Given the evidence, last-registration-wins scoped by (client, token) is the defensible default; final adoption is **D1/D2 (REQUIRES APPROVAL)**.
- **Mobile-side duty (recommended, contract-neutral):** apps should call the unregister endpoint on logout (response contracts unchanged); server-side logout cleanup (D5) remains a separate policy decision.

---

## 6. Security Assessment

Original findings re-verified; one new finding (§5.3 binding gap). Ranked:

| # | Finding | Class | Evidence |
|---|---|---|---|
| 1 | Path B stale-owner exposure (coexisting active rows for one token) | Verified behavior; real-world prevalence UNKNOWN | `NotificationPreferencesController.php:58-72` + schema unique key |
| 2 | Client↔token project binding unvalidated → silent permanent device loss | RISK (new) | `DeviceTokenController.php:22` + `FcmService.php:50-53` |
| 3 | Logout keeps tokens live | Verified behavior | `UserController.php:668` |
| 4 | Path B invalid-token deactivation dead (`getCode()` string-compare) | Verified code / INFERRED runtime | `PushNotificationService.php:42,91` |
| 5 | Owner-scoped deletes OK on all unregister paths | Verified safe | `:44-46`, `:79-81` |
| 6 | Signed Pusher URLs (incl. `auth_key`) leak into failure logs | Verified local-log evidence | prior inventory §7.2 |
| 7 | Path A ignores user push preferences | Verified | no preference read in Path A chain |
| 8 | Broadcast payloads carry no sensitive PII (title/message + ids only) | Verified (construction) | `toDatabase` payload reuse |
| 9 | Firebase per-client isolation strict, but missing-credential exception swallowed | Verified | `FirebaseProjectResolver.php:9-18` + `FcmService.php:27-in-try` |

---

## 7. Delivery and Idempotency Assessment

Duplicate-delivery scenarios, independently classified (mandate: not everything order-related is a duplicate):

| Scenario | Same business event? | Same recipient? | Same device? | Same purpose/content? | Class | Evidence |
|---|---|---|---|---|---|---|
| Cross-path push on delivered/cancelled | YES (same transition) | YES | only if dual-registered | YES (same purpose; different content/format) | **VERIFIED mechanism** (dual-register prevalence UNKNOWN) | `OrderService.php:1388,1391,1395` + wiring |
| Queued-notification retry re-runs `database` + re-dispatches FCM job | YES | YES | YES | YES | **VERIFIED mechanism** (no per-channel idempotency; no `ShouldBeUnique`) | channel order + absence grep |
| Path B listener retry re-sends + duplicate `order_notifications` rows | YES | YES | YES | YES | VERIFIED | `SendOrderPushNotification.php:53-in-handle`, `:75-77` |
| Multiple devices of one user receiving one event | YES | YES | NO (different devices) | YES | **By design — not a defect** (multicast semantics) | `SendFcmNotificationJob.php:45-47` |
| Same user's devices across `client_a` and `client_b` both receiving | YES | YES | NO (different apps) | YES | **By design** (multi-app support) | `groupBy('client')` dispatch |
| Worker re-release after timeout → duplicate send | YES | YES | YES | YES | RISK (framework `retry_after` design; no idempotency) | `config/queue.php` comments |

**Idempotency identities (defined before any strategy — no new table asserted):**
- Business-event identity: `(event_class, business_entity_id, recipient_user_id, notification_purpose)`.
- Delivery identity: `(client, token)` — the (client, token)-scoped registry row.
- Strategy space (conceptual, approval-gated D6): per-path dedupe on event-identity before dispatch, and/or reuse of a single tracking row across attempts. Any mechanism adds schema → REQUIRES APPROVAL; none is selected here.

---

## 8. Corrected A/B/C Comparison

**Definitional correction first (E-2):** a unification option is only meaningful if it achieves the single-owner invariant. Therefore:
- **B-as-is** (composite key unchanged) preserves the leak → it fails unification's purpose → scored only for reference (2.73 scoreable-only).
- **B-hardened** (global→(client,token) uniqueness change + `client` + `uuid` added) is the valid form. Scored below.

**End-state convergence (key analytical correction):** with the corrected key, options A and B converge to the *same* end-state: one registry, one ownership invariant, one send service; both need superset columns (A needs B's metadata/is_active/expiry; B needs `client`+`uuid`+token-width 512; A needs platform widening to represent web rows); both require the same D1 ownership-behavior approval on the B register endpoint; both need adapters for the exact response JSON (feasible — verified field availability under superset schemas).

**Genuine asymmetries that remain (decision inputs, not score deciders):**
1. **Numeric-id continuity:** B-register/B-unregister expose numeric `user_device_tokens.id`; migrating B rows into a new/other table re-keys ids → clients caching ids break (404s). A-form (registry replaces `device_tokens`, B rows merged in) inherits this risk; B-form (registry replaces `user_device_tokens`) keeps B ids stable and instead must guarantee `uuid` backfill for A clients (uuid is generated server-side; no client caching evidence). Slight edge: **B-form on id-continuity** — but only if B endpoints are actually used (UNKNOWN).
2. **Where production data lives (UNKNOWN)** — the single decisive input for migration cost and conflict volume.
3. Multi-user token conflicts: guaranteed by B's semantics wherever account switches occurred; volume UNKNOWN (P-UDT-4/P-UDT-5).

**Option C:** preserves every contract and behavior byte-for-byte (VERIFIED — zero code change), but permanently retains: the leak (#6-1), dead deactivation (#6-4), simulation dishonesty, divergent configs, proven duplicate mechanisms (§7), and dual maintenance. It is safe only as *frozen behavior* — not secure.

**Option C+ (new alternative — see §10):** achieves the ownership invariant without schema migration.

---

## 9. Recalculated Decision Matrix (from scratch, visible formulas)

**Criterion definitions fixed for this review:** Security/Integrity/Delivery/Maintainability score the **end-state** of each option (post-migration, post-remediation); Production/mobile and Migration score **transition feasibility given current evidence**; API compatibility scores required response-adapter feasibility **plus** behavior-approval burden.

**Weights (task-fixed):** Security 25 · API compat 20 · Prod/mobile 15 · Integrity 15 · Delivery/idempotency 10 · Migration/rollback 10 · Maintainability 5. Sum = **100** ✓.

| Criterion (weight) | A | B-hardened | B-as-is (reference) | C |
|---|---|---|---|---|
| Security & isolation (25%) | 5 | 5 | 2 | 2 |
| API backward compat (20%) | 3 | 3 | 3 | 5 |
| Production & mobile compat (15%) | **U** | **U** | **U** | 5 |
| Integrity & ownership (15%) | 5 | 5 | 3 | 2 |
| Delivery & idempotency (10%) | 4 | 4 | 3 | 2 |
| Migration & rollback (10%) | **U** | **U** | **U** | 5 |
| Maintainability (5%) | 5 | 5 | 4 | 1 |

**Formulas (scoreable-only normalization): `Σ(score×weight) / Σ(scoreable weights)`**

- **A:** numerator = 25·5 + 20·3 + 15·5 + 10·4 + 5·5 = 125+60+75+40+25 = **325**; denominator = 25+20+15+10+5 = **75** → **325/75 = 4.33** (repeating 4.333…)
- **B-hardened:** identical end-state scores → 325/75 = **4.33**
- **B-as-is (reference):** 25·2 + 20·3 + 15·3 + 10·3 + 5·4 = 50+60+45+30+20 = **205**; /75 = **2.73**
- **C:** 25·2 + 20·5 + 15·5 + 15·2 + 10·2 + 10·5 + 5·1 = 50+100+75+30+20+50+5 = **330**; /100 = **3.30**

**Evidence coverage:** A and B-hardened: **75%** of decision weight (25% UNKNOWN); C: **100%**.

**Verification of the original report's arithmetic (E-1):**
- Original A: `(25·5+20·3+15·4+5·5)/90` = 270/90 = **3.00** — the report claims **4.28**; the formula itself is malformed (10%-weight criterion weighted 15; integrity term omitted; wrong denominator). Corrected: **4.33**.
- Original B: `205/85` = **2.41** — the report claims **2.94**; denominator wrong (85 vs 75). Corrected (hardened form): **4.33**; (as-is form): **2.73**.
- Original C: 330/100 = **3.30** — correct (the only correct original computation).

**Can UNKNOWNs reverse the ranking?**
- A vs B-hardened: **the scoreable matrix cannot separate them** (identical end-state). The A/B choice is decided entirely by the UNKNOWN 25%: *where production rows live* (determines merge direction/conflict volume), *which endpoints shipped apps call* (determines id-continuity exposure and behavior-change blast radius), and *multi-user token volume*.
- A/B-hardened vs C: C wins only while the unknowns remain unknown AND the user declines the ownership-behavior approval. If D1 is approved and production evidence arrives, A/B-hardened dominate C on security/integrity/delivery/maintainability (325/75 = 4.33 > 330/100 = 3.30) — with the caveat that a 4.33 scoreable-only score still carries 25% unevaluated weight.

**Are the options genuinely comparable?** After the end-state/transition clarification — yes. The original comparison mixed B's native semantics with its required change, which both understated B and overstated the A–B gap.

**False-confidence guard:** no option may be selected on the normalized score alone; for A/B, a quarter of the decision weight is unevaluated. The matrix is a decision *aid*; the binding constraints are the security invariant, the no-API-change mandate, the approval register, and the production evidence.

---

## 10. Alternative Architecture Assessment (§7 mandate)

### Alt-1 — Canonical registry + endpoint adapters (= corrected A/B-form target)
- Data model: single registry, UNIQUE (client, token); owner = latest registrant; superset columns.
- Ownership: single-owner + reassignment scoped per (client, token).
- Compatibility: response adapters per endpoint (§4 shapes preserved); behavior change on B-register (D1).
- Migration: dual-write window; conflict policy (D3); rollback via legacy-table retention.
- Security: achieves invariant fully. Complexity: moderate. **Preferred target.**

### Alt-2 — Canonical registry + separate delivery-target records
- Data model: registry (identity) + delivery-targets (per purpose/channel) rows.
- Verdict: **REJECTED (YAGNI)** — the FCM token *is* the delivery target; no proven requirement separates identity from delivery for a single-provider (FCM) system; adds joins and consistency burden without a demonstrated need. Revisit only if a second provider (e.g., APNs-direct) or per-purpose routing becomes a real requirement.

### Alt-3 — Transitional compatibility layer with legacy identity mapping
- Not a standalone architecture: it is the dual-write window + id-mapping mechanic inside Alt-1's migration (§12). Kept as migration equipment (D3 id-continuity option).

### Alt-4 — **Option C+ (guarded coexistence)** — NEW, not in the original report
- Data model: **unchanged** (both tables stay; zero migration).
- Mechanism: one shared ownership service consulted by BOTH register endpoints; on any registration of (client, token) the guard deactivates/deletes the token's live row in the *other* table (and reassigns within its own). Responses unchanged; ownership behavior changes on both endpoints (D1 + D10 approval).
- Security: achieves the single-owner invariant **immediately**, before any production evidence or migration.
- Costs: cross-table coupling (new failure mode at registration); dual maintenance persists (maintainability stays 1); delivery paths stay per-table (duplicate mechanisms remain until D4/D6).
- Rollback: disable the guard (feature flag); no data reversal needed beyond rows deactivated during the window (reconciliation query).
- Verdict: the **lowest-risk path to the security invariant** if the user wants the leak closed before the production inventory; it does NOT replace the eventual registry decision (A vs B form), and it still requires the same ownership-policy approval.

**Preference rule applied:** simplest design satisfying proven requirements → C+ for immediate risk reduction (if approved), Alt-1 for the end state. Alt-2 rejected; Alt-3 folded into migration mechanics.

---

## 11. Immediate Risk Mitigation Recommendations (Group A vs Group B)

### Group A-1 — Truly internal (no externally observable change; approvable as a batch)
| Fix | Evidence | Observable impact |
|---|---|---|
| Log hygiene: redact signed URLs/auth_key & token material from failure logs | prior inventory §7.2 (Pusher cURL URL with auth_key) | none (logs only) |
| Structured notification logging fields (user ref, client, token-hash prefix, attempt, provider status) | FcmService.php:45-47,52 omit ids | none (logs only) |
| Remove dead `PushNotificationService::registerDevice()` | zero callers (VERIFIED) | none |
| Document contracts (§4) as the canonical API reference | OpenAPI spec lacks all four endpoints | none |

### Group A-2 — Delivery-semantics changing (NOT API-contract changes, but observable outcomes change → explicit sign-off each)
| Fix | What changes | Approval |
|---|---|---|
| Retry classification in `FcmService` (rethrow retryable; permanent-fail classified) | failed pushes may be retried instead of silently dropped; failed_jobs become populated | D12 |
| Path B invalid-token deactivation fix (dead → live) | stale tokens start being deactivated; fewer dead sends | D13 |
| Simulation honesty: record `skipped/simulated` instead of `sent` | **`order_notifications.status` VALUES change — visible through `GET /user/notifications/history`** (response *shape* unchanged; data semantics change) | D11 |
| Duplicate-dispatch elimination (delivered/cancelled single-owner push; per-channel idempotency) | duplicate pushes stop | D4 + D6 |
| Path A honors `UserNotificationPreference` push gating | users with push-off stop receiving Path A pushes | D4 |
| Ownership guard (C+) on both register endpoints | previous owner stops receiving after account switch | D1 + D10 |
| Logout token policy | devices deactivate on logout (or not) | D5 |
| Client↔token binding validation | registrations with wrong-project tokens begin to fail (422) where they currently "succeed" silently | D14 |

### Group B — Architecture migration (blocked pending evidence + approvals)
Schema change; data migration + conflict policy (D3); ownership policy (D1/D2); mobile-team confirmation; production inventory; id-continuity decision; SDK upgrade (D8 — dependency approval; verified feasible: 7.x is PHP 8.2-compatible and removes the batch endpoint while preserving `sendMulticast` call sites).

---

## 12. Migration and Rollback Assessment

The original Phase-0/Phase-1 design stands (re-verified), with corrections applied:
- Uniqueness target corrected to **(client, token)** — conflict analysis must now be computed **per (client, token)** scope: Path B's coexisting rows for one token collapse into one scoped owner; the same token string under two *different* clients is NOT a conflict (different logical identities) — a correction to the original conflict model (which treated bare-token collisions as conflicts).
- B-form keeps B numeric ids stable (no id-mapping needed for B clients) but must backfill `uuid` for A responses; A-form keeps `uuid`/`client` native but needs the id-mapping for B clients (D3).
- Constraint change (B-form) on a populated table requires the approved conflict policy BEFORE the unique index can be created — the index creation itself is the enforcement point (REQUIRES APPROVAL; rollback = drop index + restore legacy tables during dual-write window; reconciliation = union-diff, no deletions).
- SDK remediation (D8) is independently deployable: 7.x preserves the `sendMulticast` call sites (§3.3) and is PHP 8.2-compatible; 8.x is not (PHP ≥8.3) — do not target 8.x on this stack.

---

## 13. Approval Register

| ID | Decision question | Options | Recommended | Security | API contract | Behavior | DB | Mobile | Migration/rollback | Required evidence | Blocking? |
|---|---|---|---|---|---|---|---|---|---|---|---|
| D1 | May `POST /user/devices/register` REASSIGN a token owned by another account (instead of creating a coexisting active row)? | approve / reject | approve (security) | closes leak #6-1 | responses unchanged | **changes ownership behavior** | none | possible client reliance on coexistence (UNKNOWN) | n/a (behavior-only) | mobile team answer on coexistence reliance | YES — all unification + C+ |
| D2 | Shared-device / multi-account policy (is last-registration-wins acceptable?) | adopt / alternative policy | adopt last-registration-wins per (client, token) | defines the invariant | none | as D1 | n/a | UX decision | n/a | product intent for shared devices | YES |
| D3 | Merge conflict policy + numeric-id continuity | policy options | most-recent `last_used_at` wins; id-mapping for cached ids | prevents wrong-owner assignment | 404 semantics for stale ids | migration-time only | conflict resolution rules | id-continuity question | defines rollback reconciliation | production P-UDT-4/5 counts | YES (migration) |
| D4 | Which path owns order-status pushes; Path A preference gating | A-owned / B-owned / keep-dual | single owner after D1 | removes duplicates | none | push frequency changes | none | content/format expectations | n/a | mobile payload-contract confirmation | YES |
| D5 | Logout token policy | deactivate / keep | deactivate-on-logout (client-initiated preferred) | privacy post-logout | none | logout behavior | none | re-login UX | n/a | product UX intent | YES |
| D6 | Idempotency mechanism (dedupe table / tracking reuse) | options | tracking-row reuse per event-identity | bounds duplicates | none | retry replay behavior | new schema | none | low | none (design approval) | YES |
| D7 | Production env provisioning (Firebase/Pusher vars) | configure / leave | configure after verification | enables real delivery | none | delivery starts | none | none | n/a | dashboard values (names/booleans) | YES (ops) |
| D8 | FCM batch-endpoint remediation | upgrade 7.x / per-token `send()` loop | **upgrade to 7.24.1** (verified: PHP 8.2-compatible; `sendMulticast` preserved; batch removed) | fixes silent-loss path | none | provider-failure surface changes (rethrow semantics still D12) | none | none | dependency change | composer update dry-run | YES (dependency approval) |
| D9 | Adopt **(client, token)** scoped uniqueness in the target registry | adopt / keep global | adopt (provider-correct) | prevents wrong-project rows | none | registration edge semantics | schema change | none | part of migration | none (design) | YES (migration design) |
| D10 | Implement C+ ownership guard (both register endpoints consult one guard) | yes / no | yes IF D1 approved and early leak closure wanted | immediate invariant | responses unchanged | ownership behavior both endpoints | none | same as D1 | flag-based rollback | mobile coexistence reliance answer | YES |
| D11 | Simulation honesty (`order_notifications.status` values change) | yes / no | yes | removes false delivery records | shape unchanged; **data values visible via history API change** | history contents | data semantics | clients reading `status` (UNKNOWN) | n/a | mobile answer on `status` field usage | YES |
| D12 | Enable real FCM retries (classify + rethrow) | yes / no | yes | bounded retries; failed_jobs populated | none | delivery timing/duplicates (needs D6) | none | none | low | none | YES |
| D13 | Fix Path B invalid-token deactivation | yes / no | yes | stale-token hygiene | none | fewer dead sends | none | none | low | none | YES |
| D14 | Server-side client↔token project binding validation | yes / no | yes (post-registration verify) | prevents silent wrong-project loss | none | today-succeeding bad registrations would 422/reject | none | none | low | none | YES |

No approval in this register is granted by any prior report or recommendation; each requires the user's explicit decision.

---

## 14. Final Recommendation and Decision Conditions

**Outcome classification: (B) Conditional architecture recommendation** — stronger than the original's "no responsible selection", justified by the corrected analysis:

1. **Target architecture (condition-independent):** a canonical device registry with **UNIQUE (client, token)**, single live owner = latest registrant (last-registration-wins, scoped), superset columns to reproduce both endpoints' exact response JSON via adapters, one FCM send service with per-client cached factories, classified retries, honest simulation records, event-identity idempotency, and (client, token)-scoped invalid-token cleanup. This target no longer depends on A-vs-B evidence — it is the *invariant* both migration forms must satisfy.
2. **Condition 1 — migration FORM (A-table vs B-table):** selected exclusively by the production inventory: where the live rows are (which table), which endpoints shipped apps call, and multi-user token volume (P-DT-4, P-UDT-4/5, endpoint-usage answers). Until then **no form is selected**.
3. **Condition 2 — approvals:** D1 (+D2) is the gate for *any* ownership-behavior change (unification OR C+). D8 (SDK 7.x) is independently approvable and verified compatible.
4. **Interim posture:** Option C exactly as-is (zero change) — optionally upgraded to **C+** (ownership guard) immediately after D1/D10 approval, which closes the security leak without schema migration while the inventory is gathered.
5. **What ends the temporary period:** delivery of the production inventory (§3.1 pack of the prior report), the mobile-team endpoint answers, and the D1/D2 decision. **Re-evaluation trigger:** any production incident involving cross-user notification exposure, duplicate-push complaints, or FCM delivery failure evidence should immediately trigger the inventory regardless of schedule.

---

## 15. Explicit List of Unresolved Unknowns

1. Production row populations/distributions for both token tables (+ multi-user token counts) — acquisition: SELECT-only pack under a verified read-only connection (`SHOW GRANTS FOR CURRENT_USER()` gate).
2. Which of the four endpoints shipped mobile builds call (and since when) — mobile team.
3. Whether any client caches numeric `deviceId` (B) or parses `data.uuid` strictly (A) — mobile team.
4. Whether any client relies on token coexistence across accounts — mobile team.
5. Live Firebase/Pusher env state (names/booleans) and real Firebase project IDs — Render dashboard.
6. Production FCM runtime behavior (batch endpoint viability) — one controlled lower-env send or log export.
7. Platform `web` real-world usage (P-UDT-2).
8. Identity of the non-local dataset behind the 2026-10-11 `user_id=616` Path B execution.
9. Whether the live Render service drifted from `render.yaml` (which declares zero notification config).
10. Mobile reliance on `order_notifications.status` values (affects D11 blast radius).

---

## 16. Safety and Non-Modification Confirmation

- No application code, tests, migrations, `.env`, configuration, routes, or deployment files were modified. The only file created is this review (`docs/audits/NOTIFICATION_ARCHITECTURE_DECISION_REVIEW.md`); the original decision report and the inventory report were **not** modified (both verified committed and untouched).
- No migrations, seeders, workers, schedulers, or dependency changes; no Composer/npm operations.
- No notifications (real or simulated) sent; no devices registered/unregistered; no database writes anywhere (this review performed zero DB access; all DB facts cite prior TESTED read-only probes).
- No production access attempted; production facts remain UNKNOWN and are labeled as such; no credentials searched for or exposed (all external claims carry public documentation URLs with check dates).
- No API contract changed or assumed changed; §4 documents the frozen contracts as implemented.
- Repository left untouched: branch `main`, HEAD `8392120d790a0e0e6dc76371c80047b541635757`, clean working tree except this report file.

*Review end. This is a verified decision review — implementation remains unauthorized.*
