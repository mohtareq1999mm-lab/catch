# Digital Products — Architecture Gap Analysis (Workstream 1)

- **Date:** 2026-08-24
- **Basis:** `current-state.md` (same directory). Every gap below was verified against the working tree; file:line references are first-hand.
- **Classifications used:** PASS · GAP · BUG · ARCHITECTURAL DECISION · BLOCKED · DEFERRED

> **W4 STATUS ADDENDUM (2026-08-24):** G4/DIG-004 RESOLVED (server-side finfo + strict ext-MIME pairing via registry; client MIME never persisted) and G11/DIG-011 RESOLVED (compensating upload lifecycle; row-in-tx/file-after-commit delete with drift warning). Upload surface remains DOCUMENT(pdf)-only by design. Legacy AdminTest fixtures modernized to real PDF bytes.
>
> **W2 STATUS ADDENDUM (2026-08-24):** Workstream 2 (Asset Type Registry) landed. Resolution state per gap: G1 → *metadata resolved* (taxonomy declared; pipeline still PDF-only by design until W4), G2 → *resolved at registry level* (types/categories modeled; DB column semantics unchanged — W3), G4 → *choke-point prepared* (whitelists centralized; sniffing still owed — OPEN), G8 → **FIXED**, all others unchanged. Details in §1 per-gap "W2 update" lines.

---

## 1. Gap Register (G1–G12)

### G1 — PDF-only hardcoding — **GAP** (intentional MVP scope, now a blocker for the target)
| Aspect | Detail |
|---|---|
| Locations | `config/digital.php:20–21` (`allowed_mimes=['pdf']`, pdf MIME list); `DigitalAssetService::assertUploadAllowed()` L74–82 (extension + MIME whitelists); translations en L486–487 / ar L291–292 / de L9–10 ("must be a valid PDF document", "Only PDF files are accepted"); `DigitalAssetCreateRequest` mimes rule driven by the same config key |
| Impact | Cannot accept any of the 10 target asset classes |
| Fix direction | Asset-type registry (Workstream 2) becomes single source of truth; messages become type-generic |
| **W2 update** | **Metadata resolved.** Registry now owns all whitelists (`activeExtensions()/activeMimeTypes()/activeMaxKb()`); both consumers rewired; legacy config keys deprecated+unconsumed (grep-proven). Pipeline remains PDF-only by explicit design — remaining work is W4 activation, plus type-generic translation messages when new types go live |

### G2 — Type taxonomy dormant — **GAP**
| Aspect | Detail |
|---|---|
| Locations | `DigitalAsset` constants L14–16 (`TYPE_FILE`, dormant `TYPE_LICENSE`, `TYPE_ACTIVATION_CODE`); `ACTIVE_TYPES=[FILE]` L19 gates FormRequest `Rule::in`; migration comment "FILE is the only implemented type" (120200:19); no URL/ACCESS concept anywhere |
| Impact | Domain cannot distinguish FILE vs URL vs LICENSE vs ACCESS delivery semantics |
| Note | Existing `ACTIVATION_CODE` constant will be superseded by the approved A2 key-pool model (LICENSE + keys table) — documented to avoid dead enum drift |
| **W2 update** | **Registry-level resolved.** `DigitalAssetType` (FILE/URL/LICENSE/ACCESS) + `DigitalAssetCategory` enums created; URL/LICENSE/ACCESS declared inert in config with capability metadata. DB column still stores strings — enum-backed casting/columns remain W3 work. ACTIVATION_CODE model constant retained dormant |

### G3 — Missing schema capabilities — **GAP → RESOLVED (W3, 2026-08-24)**
| Missing column/concept | Consequence | W3 resolution |
|---|---|---|
| `checksum` | No integrity verification… | ✅ column added (string(64) NULL); computation = W4 |
| `display_name`, `extension` | … | ✅ added |
| `status` on assets | … | ✅ added (default `active`, existing rows backfilled) |
| `metadata` JSON | … | ✅ added (array cast) |
| `external_url` (+ nullable `path`) | URL assets physically unrepresentable (`path` NOT NULL) | ✅ path → NULLable (verified safe: only FILE rows exist; FILE flow byte-identical) + external_url TEXT |
| encrypted secret storage | No LICENSE/ACCESS capability | ✅ `secret` TEXT via `'encrypted'` cast + hidden; license pool table `digital_license_keys` with encrypted_key cast |
| `expires_at` on entitlements | No access expiration concept | ✅ added (enforcement = later workstream) |
| indexes | `(product_id,status)` needed once status filtering exists | ✅ added alongside preserved `(product_id,sort_order) |

Remaining schema-adjacent note: "one live allocation per entitlement" cannot be a portable partial unique index → enforced by the future LicenseService (documented decision).

### G4 — Client MIME trusted — **BUG (security)** → ledger DIG-004
| Aspect | Detail |
|---|---|
| Evidence | `DigitalAssetService::store()` persists `$file->getClientMimeType()` (L46); `assertUploadAllowed()` validates against `$file->getMimeType()` (L79) which derives from client-supplied headers via `UploadedFile`, not deep content inspection |
| Attack | Multipart MIME spoofing: a polyglot file with `.pdf` extension and crafted bytes could pass extension check while `getMimeType()` (finfo on partial content) is coaxed; stored mime then drives delivery `Content-Type` (DownloadController L128) |
| Mitigations already present | Attachment disposition (`Content-Disposition: attachment`), `no-store`, sanitized filename, private disk, PDF-only whitelist — residual risk LOW but violates Phase-4 requirement #9 ("validate actual file content") |
| Fix direction | Server-side finfo full-file detection + extension↔sniffed-category cross-check in registry validation |
| **W2 update** | **Choke-point prepared, defect OPEN.** All whitelists now flow through `AssetTypeRegistry` (single integration point for W4 sniffing). Client-MIME dependencies remain at `DigitalAssetService.php:46` + `getMimeType()` — see ledger DIG-004 (OPEN) |

### G5 — Missing CRUD/management surface — **GAP**
| Missing piece | Requirement source |
|---|---|
| `GET digital-assets/{uuid}` (SHOW) | Phase 7 |
| File replacement endpoint (explicit operation) | Phase 8 |
| Admin entitlement listing/filtering | Phases 11–13 admin oversight |
| Entitlement limit override (incl. unlimited), revoke, restore endpoints | Phase 12–13; `download_limit` is mass-assignable at code level but NO endpoint writes it today (verified) |
| License pool CRUD/reveal endpoints | A2 |

### G6 — Delivery layer assumes FILE attachment — **GAP**
Evidence: `DigitalDownloadController@download` ends in a single `$disk->response(...)` attachment path (L127–131); entitlement index payload hardcodes `download_url` for every asset regardless of future type (L43–50). No strategy dispatch point exists. Target requires per-type resolution (stream/ranged-stream/inline/redirect/reveal/grant).

### G7 — License/access handling absent — **GAP**
| **W5 update** | **RESOLVED.** LICENSE: pool table (W3) + bulk-import endpoint gated manage-digital-licenses; locked idempotent allocation inside the fulfillment transaction; one-time customer reveal; secrets Crypt-at-rest and never serialized. ACCESS: single encrypted credential on the asset, re-revealable. URL assets: SSRF-safe static validation + controlled disclosure on the authenticated listing; server never fetches/proxies. Evidence: DigitalExternalUrlLicenseTest 16/16, MySQL concurrency harness 11/11, SSRF probe 20/20 |
No license models, tables, services, endpoints, or translations exist. Legacy PickBazar license-verification code (InstallCommand/AuthMutator) is disabled scaffolding unrelated to product licensing. Full build required for A2.

### G8 — `.env.example` missing digital env vars — **BUG (ops)** → ledger DIG-008 — **FIXED (W2)**
Verified: grep for `DIGITAL_` across `.env.example` returned zero matches. `config/digital.php` consumes `DIGITAL_PRODUCTS_ENABLED`, `DIGITAL_MAX_UPLOAD_KB`, `DIGITAL_DOWNLOAD_LIMIT`, `DIGITAL_SIGNED_URL_TTL`, plus the new A1 gate `DIGITAL_ALLOW_SOFTWARE_ASSETS`. **W2 fix:** all five documented with safe matching defaults; regression test asserts presence; prefix-normalized coverage check passes.

### G9 — ar translation gap claim — **NOT REPRODUCED (PASS)**
The initial discovery pass reported `ITEM_TYPE_IMMUTABLE_*` missing from `resources/lang/ar/message.php`. Direct byte-level verification proves all nine feature keys present at ar L289–297 with valid UTF-8 Arabic content (console `???` was PowerShell codepage display). **Recorded as verified-PASS, not converted from BLOCKED/PASS without evidence.**

### G10 — Cache layer — **NOT APPLICABLE (PASS by design)**
Zero cache usage in digital paths (verified by search over services/controllers/listeners). Reads are index-backed with eager-load reuse. Per Phase-18 rule, no caching will be introduced; audit evidence will document MISS/HIT as N/A unless Workstreams 2–12 add a justified use.

### G11 — Non-transactional filesystem operations inside DB transactions — **BUG (data integrity)** → ledger DIG-011
| Manifestation | Trace | Failure window |
|---|---|---|
| G11a orphan FILE | `store()`: `putFileAs` L28–32 succeeds → `DigitalAsset::create()` L40–49 throws → DB tx rolls back → file remains on private disk with no row | upload failure path |
| G11b orphan ROW | `delete()`: `Storage::delete(path)` L63 succeeds → `$asset->delete()` L64 throws → tx rolls back row deletion → row persists pointing at deleted file; next customer download burns gate step 5 (404, credit preserved — so impact contained to inconsistency, not credit loss) | delete failure path |
| Why it matters | Laravel transactions never roll back filesystem writes/deletes; ordering must place irreversible FS mutation AFTER commit (or use compensating cleanup) | — |
| Regression tests required | Simulated create-failure → assert no file; simulated delete-failure → assert file intact or compensating removal | Phase 13/21 |

### G12 — Import/export coupling — **DEFERRED (per locked decision A5)**
Product Excel import exists but has zero digital-asset awareness (metadata or binaries). Binary embedding explicitly out of scope; metadata columns deferred with rationale (reviewable-diff control), tracked in master-todo Phase 23.

---

## 2. Capability Matrix — Current vs Required Target

| Capability (target phase) | Status today |
|---|---|
| Documents (PDF only) upload/store/deliver | WORKING |
| EPUB/DOCX/XLSX/CSV/TXT/archives/audio/video/images/software | **REGISTRY-KNOWN, pipeline-inactive** (W2 metadata; W4 activates) |
| Type/category taxonomy + validation registry | **DONE (W2)** |
| External URL assets w/ SSRF-safe validation | ABSENT — type declared inert (W5) |
| License pool, one-time reveal, consumption tracking, revocation | ABSENT (G7) |
| Access-grant content type | ABSENT |
| Checksum/integrity | ABSENT (G3) |
| SHOW endpoint | ABSENT (G5) |
| Explicit file replacement | ABSENT (G5) |
| Admin entitlement mgmt (limit/unlimited/revoke/restore) | ABSENT (G5) |
| Expiration (entitlement-level) | ABSENT (G3) |
| Preview (inline render for entitled users) | PARTIAL — attachment-forced today; inline option needed |
| Streaming (Range) audio/video | ABSENT (G6) |
| Purchase→fulfillment→entitlement→download→limit→revoke | **WORKING, race-tested, audit-logged** |
| Refund interlock (delivered blocked / pending revoked) | WORKING (two layers) |
| IDOR protection on customer access | WORKING + regression-tested |
| Signed-URL issuance/consumption/tamper/expiry | WORKING + regression-tested |
| Bilingual notifications (delivery/failure) | WORKING |
| Activity-log integration for digital events | **ABSENT** — download audit exists in dedicated table; upload/replace/delete/revoke/license events are not activity-logged (Phase 30 target) |
| Real-binary E2E fixtures/harness | ABSENT (no `tests/Fixtures/`) |

---

## 3. Locked Decision Compatibility Assessment

| ID | Locked decision | Current compatibility | Delta required |
|---|---|---|---|
| A1 | SOFTWARE category config-gated, default OFF | No SOFTWARE concept exists — nothing to conflict with | Registry introduces category behind `DIGITAL_ALLOW_SOFTWARE_ASSETS=false`; default-OFF enforced in validation, not just docs |
| A2 | Encrypted license key-pool + one-time reveal + consumption tracking | Dormant `TYPE_LICENSE` constant unusable (schema forces physical `path`) | New `digital_license_keys` table; Crypt-encrypted-at-rest cast; reveal endpoint excluded from resources; allocation hook inside fulfillment transaction |
| A3 | HTTP Range streaming for AUDIO/VIDEO | Single attachment response path | Delivery resolver branch: seekable streamed response honoring Range for entitled users only; S3 presigned path documented, not implemented |
| A4 | Hybrid permissions | CRUD already reuses product permissions (matches hybrid baseline exactly) | ADD new enum cases only where no analog exists: manage-digital-access (limit/revoke/restore), manage-digital-licenses; seed into store_owner/super_admin buckets; existing roles unaffected |
| A5 | Deferred: AV-scan hooks, transcoding, import/export asset metadata, pre-purchase samples | None of these exist | Ledger entries DEFERRED with rationale; job-stub hooks documented but NOT built (YAGNI) |

---

## 4. Architectural Risks (pre-implementation register)

| Risk | Severity | Mitigation direction |
|---|---|---|
| R1 — Widening `type` values changes customer payloads (index returns every asset incl. URL/LICENSE types once added) | HIGH | Additive `delivery_type` field; `download_url` emitted only for downloadable kinds; resource contract tests freeze shapes before change |
| R2 — `currentAssets()` product-scoping means disabling/deleting an asset instantly affects ALL historical purchasers | MEDIUM-HIGH | Asset `status` column + explicit policy decision recorded before implementation (documented in Workstream 7 design note) |
| R3 — Range streaming bypasses the "file-exists-before-credit" simplicity and adds header-parsing edge cases | MEDIUM | Reuse same 6-gate order; credit consumed once per signed redemption regardless of range count within TTL window — needs explicit design + tests |
| R4 — Executable uploads (A1) even gated create AV/hosting liability | MEDIUM | Default-OFF config + strict sniffing + explicit ops doc; ledger notes residual risk acceptance belongs to Product Owner |
| R5 — sqlite test bootstrap drift: `CreatesTestTables.php` must mirror every schema change or suites break silently | MEDIUM | Update trait in same commit as migrations (Workstream 3 checklist item) |
| R6 — Two payment generations exist (legacy Marvel gateway singleton vs MyFatoorah factory) — fulfillment listens to the canonical event so both are safe, but future payment work must not fork emission | LOW (informational) | Documented; out of scope per Rule 11 |
| R7 — `RefundApproved` event class lives in package dir with App namespace (pre-existing anomaly) | LOW | Untouched; noted to prevent accidental "cleanup" during digital work |
| R8 — Entitlement expiry (new `expires_at`) is lazy-checked at access time; no scheduler | ACCEPTED | Lazy gate consistent with existing revocation model; documented |

---

## 5. Missing Test Coverage (to be created in later workstreams)

1. Registry-driven validation matrix per asset category (valid/invalid ext×MIME×size).
2. Server-side content-sniffing negatives (polyglot/spoofed uploads) — closes G4 with regression proof.
3. Orphan-prevention tests for G11a/G11b (forced failure injection).
4. SHOW endpoint authz + contract.
5. Replacement atomicity (old file survives failed replace; old file removed after success; checksum/mime/size updated).
6. License lifecycle: bulk import → allocate-on-purchase → one-time reveal → reuse denied → consume → revoke; secret never serialized in ANY resource.
7. URL assets: scheme/private-range/metadata-host/allowlist negatives (static validation only — server never fetches).
8. Delivery-per-type dispatch + additive payload fields; Range request happy/negative cases.
9. Entitlement expiry boundary (expired → 403 translated message).
10. Admin limit override incl. unlimited sentinel + revoke/restore round-trip + activity-log assertions.
11. New permission matrix (guest/user/admin-without-new-perm/admin-with-perm/super-admin) per protected endpoint.
12. Translation assertions for every newly introduced key (EN+AR presence).
