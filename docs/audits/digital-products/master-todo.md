# Digital Products — Master TODO (34-Phase Map → Execution Plan)

- **Created:** 2026-08-24
- **Decision log:** A1 software config-gated OFF · A2 encrypted license key-pool + one-time reveal · A3 HTTP Range streaming (A/V) · A4 hybrid permissions · A5 explicit deferrals
- **Workstream key:** W1 audit/docs · W2 registry · W3 schema · W4 upload pipeline · W5 URL/license/access assets · W6 CRUD/replacement/entitlement-admin · W7 unified delivery · W8 cross-cutting (perms/translations/cache/queue/notifications) · W9 test matrix · W10 E2E harness + proof · W11 performance/observability/contracts · W12 closure

Legend: `[ ]` open · `[x]` done · `(B)` blocked · `(D)` deferred-by-decision

---

## Phase 0 — Discovery / current-state audit — **W1** ✅ COMPLETE
- [x] Discover all digital product routes (`current-state.md` §2)
- [x] Discover all digital asset routes (§2)
- [x] Inspect controllers (§1, §3.3–3.5)
- [x] Inspect request/validation classes (§1)
- [x] Inspect repositories/services/actions (§1, §3)
- [x] Inspect models/relations (§3.1)
- [x] Inspect migrations/tables/indexes/FKs (§3.2)
- [x] Inspect enums/constants/config (§3.6)
- [x] Inspect permissions (§3.7)
- [x] Inspect translations EN/AR (+DE) with line-level verification (§3.8)
- [x] Cache-key audit (§3.9 — zero usage found)
- [x] Queue/job/listener audit (§3.9)
- [x] Existing tests inventory (§4)
- [x] Purchase→fulfillment→entitlement→delivery trace (§3.4)
- [x] Hardcoded PDF assumptions identified (G1)
- [x] MIME/PDF hardcode locations enumerated (G1, G4)
- [x] Download logic physical-file-only assumption assessed (G6)
- [x] Access/revocation/limit multi-type readiness assessed (G2, G3, G6)

## Phases 1–2 — Domain model & asset-type registry — **W2** ✅ COMPLETE (2026-08-24)
- [x] `config/digital.php` asset_types registry — FILE categories DOCUMENT/SPREADSHEET/PRESENTATION/ARCHIVE/AUDIO/VIDEO/IMAGE/SOFTWARE with per-category extensions, server MIME sets, delivery flags; URL/LICENSE/ACCESS declared as inert metadata-only types (evidence: config + `DigitalAssetTypeRegistryTest`, 34 tests green)
- [x] `app/Enums/DigitalAssetType.php` (FILE/URL/LICENSE/ACCESS) + `app/Enums/DigitalAssetCategory.php` (native backed enums per house style). Note: TEXT folded into DOCUMENT by design; ACTIVATION_CODE model constant intentionally left dormant (removal is a breaking-touch on the model for zero behavioral gain — revisit at W5 license work)
- [x] `AssetTypeRegistry` service = single validation truth source; both former scattered consumers rewired (`DigitalAssetCreateRequest` mimes/max/type rules, `DigitalAssetService::assertUploadAllowed`); grep proof: zero runtime consumers of deprecated `allowed_mimes`/`allowed_mime_types` remain
- [x] A1 gate: SOFTWARE recognized only when `DIGITAL_ALLOW_SOFTWARE_ASSETS=true` AND still not uploadable until its active surface is populated (W4); default false everywhere
- [x] Legacy key migration: `allowed_mimes`/`allowed_mime_types` marked DEPRECATED and unconsumed; `max_upload_kb` retained as LIVE global size fallback (runtime override proven by existing suite + new override tests) — PDF flow byte-for-byte compatible
- [x] DIG-008 FIXED here (see error-ledger): all five env vars documented in `.env.example`, coverage test added

## Phase 3 — Database/migrations — **W3** ✅ COMPLETE (2026-08-24)
- [x] Additive migration: digital_assets += display_name, extension, checksum(64), status(default active), metadata JSON, external_url (path made NULLable — required for URL/LICENSE/ACCESS representation), secret (encrypted cast), expires_at; index `(product_id,status)` (evidence: workstream-3-final-report §3, §8)
- [x] New table `digital_license_keys`: uuid UNIQUE, asset_id FK CASCADE, encrypted_key NOT NULL (encrypted-at-rest via model cast), status available/assigned/consumed/revoked default available, allocated_entitlement_id FK **SET NULL** nullable, assignment/reveal/consumption/revocation timestamps; indexes `(asset_id,status)` + allocation idx (evidence: report §4; allocation-uniqueness deferred to service layer — documented portability decision)
- [x] Additive migration: digital_entitlements += expires_at (lazy access-time check remains future; NULL = unchanged behavior) (evidence: report §5)
- [x] `tests/Concerns/CreatesTestTables.php` updated in same change set to full production contract incl. license table (evidence: report §7)
- [x] Fresh migrate-from-zero verified on MySQL 8.4.3 AND SQLite (75/75 each); rollback + existing-data survival verified both engines (94/94 each); double-fresh proof included (evidence: report §8–§10; harness retained at storage/w3-audit/schema_check.php)

## Phase 4 — Upload pipeline — **W4** ✅ COMPLETE (2026-08-24)
- [x] Registry-driven validation: type/category/ext/MIME/size per asset class (active surface; single source of truth — service holds zero whitelists)
- [x] Server-side finfo full-content detection; client headers untrusted (fixes DIG-004) + strict ext↔MIME pairing via `resolveCompatibleCategory()`
- [x] sha256 checksum persisted at store (lowercase hex 64, computed from real bytes, proven equal to stored-file hash)
- [x] Orphan-proof lifecycles (fixes DIG-011 a+b): store = validate→write→persist→compensate-on-failure; delete = row-in-tx → file-after-commit with drift warning; failure-injection suite green
- [x] Secure storage naming retained (UUID.ext); traversal/malicious-name negatives green
- [x] A1 executable gate enforced at validation layer (defense in depth on top of empty active surface)
- [x] Existing PDF flow compatible: legacy AdminTest fixture modernized to real PDF bytes (dummy random bytes were never valid PDFs); all prior suites green (104 tests / 311 assertions incl. W4's 16/66)
- Note: active surface intentionally still DOCUMENT(pdf)-only; widening to the declared taxonomy is a deliberate later activation, not part of hardening

## Phases 5–6 — External URL & license/access assets — **W5** ✅ COMPLETE (2026-08-24)
- [x] ExternalUrlValidator: https-default, deny localhost/private ranges/link-local/metadata hosts + userinfo + unresolvable; one-time DNS resolution with ALL records public; v4-mapped-v6 unpacking; optional allowlist; **server never fetches** ⇒ redirect re-validation N/A-by-design (evidence: independent probe 20/20 + suite)
- [x] License pool service: bulk import (batch-capped, count-only responses), Crypt-at-rest via cast, allocate-on-fulfillment inside the fulfillment transaction using `lockForUpdate`, one-time reveal (config `digital.licenses.one_time_reveal`, env `DIGITAL_LICENSE_ONE_TIME_REVEAL`), `consumed` state reserved for future flows; revocation/expiration inherited from entitlement (D7) — evidence: suite 16/16 + MySQL concurrency harness 11/11
- [x] Reveal endpoint authorization: ownership filter at read time, delivered+expiry gate (`accessAllowed`), product binding; secrets decrypted ONLY in reveal response; never serialized by models/resources/logs (leakage tests green)
- [x] Lifecycle tests: import→allocate→reveal→reuse-denied→revoke-blocks; empty/exhausted pools deliver without keys and reveal translated 404; failed-fulfillment rollback leaves nothing allocated and retry allocates once; REAL cross-process MySQL race (8 workers/order + 12 workers over 3 scarce keys)
- [x] Admin surface: `POST digital-assets/{uuid}/license-keys` gated NEW permission `manage-digital-licenses` (enum + master/staff/owner/super-admin seeder buckets + en/ar labels); Marvel stays CRUD
- [x] URL disclosure model = controlled field on authenticated entitlement listing (Option A), gated delivered+expiry; no proxy, no fetch, no fake path/checksum
- Deferred to later workstreams: audited redirect delivery (W7 DeliveryResolver), consumption semantics for `consumed` state, admin masked key listing

## Phases 7-8 - CRUD, replacement - **W6** COMPLETE (2026-08-25)
- [x] SHOW endpoint GET digital-assets/{uuid} (view-products; collection-entry contract; path/disk/secret hidden) - evidence: DigitalAdminManagementTest + w6_independent_check
- [x] UPDATE widened to display_name/status(active|inactive; revoked/expired reserved)/metadata; bytes+checksum immutable through metadata path; raw-PDO proof
- [x] Explicit REPLACE endpoint POST digital-assets/{uuid}/replace: validate-new-bytes -> checksum -> write-new -> tx-swap -> compensate-on-failure -> retire-old-after-commit; uuid/original_name stable; write-fail + DB-fail injections green
- [x] Admin entitlement management: GET digital-entitlements (uuid/status/order_id/user_id/search filters), PATCH .../limit incl. UNLIMITED sentinel 0 enforced atomically in the download gate, POST .../revoke (delegates to W1 revoke), POST .../restore (revoked->delivered only); gated NEW permission manage-digital-access (enum+seeder+en/ar labels); all mutations activity-logged (digital.entitlement.*)
- [x] Status semantics: inactive assets leave customer disclosure + download gate 404; allocation already active-only
- [x] Queue proof: real worker consumed revoke activity job from meem-medium -> activity_log row (w6_queue_proof.php 5/5)
- [x] Concurrency proof: real MySQL cross-process download race 5/5 (cap=1 race; unlimited sentinel)
- Deferred: none from this block. Restore = admin override, activity-audited.

## Phases 9-14 - Unified delivery - **W7** COMPLETE (2026-08-25)
- [x] DeliveryResolver single chokepoint: FILE attachment (unchanged contract) / AUDIO+VIDEO Range streaming (A3 activated in registry active surfaces) / PDF inline preview / URL audited redirect / LICENSE+ACCESS reveal delegation
- [x] All deliveries behind existing signed route + gate order preserved verbatim (kill-switch -> exists -> status/expiry -> binding -> file-check -> atomic-limit); controller reduced to thin wrappers
- [x] Entitlement expiry lazy gate already enforced via shared accessAllowed() authority (W5) - re-verified through resolver
- [x] Unlimited sentinel + atomic enforcement re-verified post-refactor (W6 concurrency harness rerun 5/5 on MySQL)
- [x] Customer payload additive delivery_type per asset; download_url emitted for FILE kinds only (R1 contract freeze)
- [x] Preview never bypasses entitlement: inline mode requires every gate EXCEPT credit consumption (spec does not authorise preview consumption - documented)
- [x] Refund interlocks D7 untouched and green (fulfilment suite)
- [x] IDOR matrix re-run across resolver surfaces (stranger/guest/revoked/expired/inactive/cross-product)
- Deferred: IMAGE upload-surface activation stays closed (inline dispatch is ready for it); audited redirect counts accesses without credits by design

## Phases 15–16 — Security hardening & permissions — **W7+W8** COMPLETE (2026-08-25)
- [x] Full negative battery executed across W4/W5/W7 suites + closure battery (traversal, oversize, malformed, executables, deleted-asset credit-preservation, no-public-storage-URL) - evidence: storage/e2e/digital-products/*
- [x] Runtime evidence for every security PASS claim (all claims map to executed HTTP tests/harness outputs)
- [x] New permission enum cases: manage-digital-access, manage-digital-licenses; seeder buckets store_owner/super_admin; existing roles untouched (A4)
- [x] Permission matrix tests ×5 personas × every protected endpoint

## Phases 17–20 — Translations, cache, queues, notifications — **W8** COMPLETE (2026-08-25)
- [x] EN+AR(+DE where contract exists) strings for all digital messages; closure battery runtime-probes 19 keys x3 locales incl. Arabic glyph assertions
- [x] Legacy-nine-key lock + full key set locked by test_all_digital_translation_keys_resolve_in_en_ar_de (133 assertions)
- [x] Cache audit documented N/A unless justified use introduced (G10 stance held)
- [x] Queue audit: async candidates assessed; AV-scan/transcode stubs DEFERRED per A5 (documented, not built)
- [x] Notifications: reuse bilingual pattern for revoked/expired/limit-reached/license-assigned where Product-Owner-justified; queued on meem-medium

## Phases 21–23 — Storage audit, resource contracts, import/export — **W6+W11** COMPLETE (2026-08-25)
- [x] DB↔filesystem agreement harness (exists/size/MIME/checksum/path/disk/delete/replace) incl. failure scenarios
-[x] Resource contract frozen: SHOW/listing payloads assert absence of path/disk/secret; automated payload scans green
- [x] Import/export asset-metadata coupling: DEFERRED per A5 with ledger entry (Phase 23 disposition recorded)

## Phases 24–28 — Test matrix, negatives, real E2E, proof — **W9+W10** COMPLETE (2026-08-25)
- [x] Real binary fixtures generated (pdf/epub/docx/xlsx/csv/zip/mp3/mp4/jpg/png/webp/txt) under tests/Fixtures/digital/
- [x] Per-class CREATE/LIST/SHOW/UPDATE/REORDER/DOWNLOAD/DELETE (+REPLACE/PREVIEW/STREAM/LICENSE/ACCESS where applicable)
- [x] Negative battery executed (list above) with artifacts
- [x] Real customer E2E: create user→digital product→assets→cart→checkout→payment (project-supported flow)→fulfillment→notification→download per type→count/limit/audit verification→expiry→revocation→refund behavior→restore
- [x] Real admin E2E: full management lifecycle incl. licenses, revocation, permission boundaries
- [x] Independent DB/filesystem/cache/queue/notification state proofs (HTTP status alone never counts)
- [x] Evidence tree under storage/e2e/digital-products/
- [x] Environment-impossible items logged BLOCKED (never converted to PASS)

## Phases 29–30 — Performance & observability — **W11** COMPLETE (2026-08-25)
- [x] N+1 sweep (entitlement list, admin lists, OrderResource path)
- [x] Streaming memory verification (no full-file buffering on large media)
- [x] Repeated sniff/storage-call audit in hot paths
- [x] Index usage validated against new query patterns
- [x] Activity-log wiring via LogActivityJob for upload/replace/delete/revoke/reveal/grant/failures; secrets excluded from properties

## Phases 31–33 — Artifacts, ledger, master todo — **continuous**
- [x] Evidence directory strategy defined (storage/e2e/digital-products/)
- [x] Error ledger created and seeded with VERIFIED defects (DIG-008, DIG-004, DIG-011 OPEN; DIG-009 refuted/N-A)
- [x] Ledger maintained through every workstream (Rule 16)
- [x] This master TODO maps all 34 phases
- [x] Every completed item checked off as it lands (Rule 17)

## Phase 34 — Production closure — **W12**
- [x] Fresh database works; migrations up/down verified
- [x] All routes respond correctly incl. new surfaces
- [x] Permissions verified end-to-end
- [x] Translations complete EN/AR
- [x] Storage integrity proven; no orphan files/rows after failure-path runs
- [x] Cache posture documented; queue flows consumed by real worker where applicable
- [x] Purchase→access→download→revocation→limits all green with evidence
- [x] Security battery passed with runtime proof
- [x] Existing regression suites green (57-test baseline preserved/extended)
- [x] No sensitive leakage (resources/logs/responses)
- [x] Zero unresolved P0/P1 defects in ledger
- [x] Final verdict issued: PRODUCTION READY / PRODUCTION READY WITH DOCUMENTED OBSERVATIONS / NOT PRODUCTION READY
