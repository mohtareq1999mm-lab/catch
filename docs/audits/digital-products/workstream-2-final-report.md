# WORKSTREAM 2 — FINAL REPORT

- **Date:** 2026-08-24
- **Runtime evidence base:** PHP 8.2.28 / Laravel 10.30.1 / PHPUnit 10.0.13 (actual codebase versions govern; the task brief's "Laravel 12 / PHP 8.4" does not match this repository).
- **Verdict:** see §16.

---

## 1. Scope

Exactly what W2 implemented:

1. `app/Enums/DigitalAssetType.php` — FILE / URL / LICENSE / ACCESS (native string-backed enum, house style with `values()` helper). Values match existing DB storage (`FILE` persisted today; `LICENSE` was pre-reserved as `DigitalAsset::TYPE_LICENSE`).
2. `app/Enums/DigitalAssetCategory.php` — DOCUMENT / SPREADSHEET / PRESENTATION / ARCHIVE / AUDIO / VIDEO / IMAGE / SOFTWARE.
3. `config/digital.php` — new `asset_types` registry tree (declared surface vs **active surface** per category) + A1 gate `allow_software_assets`; legacy keys migrated (`allowed_mimes`/`allowed_mime_types` deprecated+unconsumed; `max_upload_kb` retained as live global fallback).
4. `app/Services/Digital/AssetTypeRegistry.php` — canonical metadata/validation API.
5. Consumer rewiring (the only two scattered-rule consumers): `DigitalAssetCreateRequest` and `DigitalAssetService::assertUploadAllowed()`.
6. DIG-008 fix: five `DIGITAL_*` variables documented in `.env.example` with safe matching defaults.
7. `tests/Feature/Digital/DigitalAssetTypeRegistryTest.php` — 34-test contract matrix.
8. Documentation updates (ledger / master-todo / gaps / current-state addendum).

**Explicitly NOT implemented (later workstreams):** schema changes (W3), server-side finfo/checksum/orphan-proofing/upload activation of new categories (W4), URL assets (W5), license pool (W5/A2), streaming (W7/A3), entitlement admin + permissions (W6/W7/A4), notifications, E2E lifecycle (W9–W10).

## 2. Files Created

| File | Purpose |
|---|---|
| `app/Enums/DigitalAssetType.php` | Delivery/storage type enum |
| `app/Enums/DigitalAssetCategory.php` | FILE content-family enum |
| `app/Services/Digital/AssetTypeRegistry.php` | Canonical registry service |
| `tests/Feature/Digital/DigitalAssetTypeRegistryTest.php` | Registry contract matrix (34 tests) |
| `docs/audits/digital-products/workstream-2-final-report.md` | This report |

## 3. Files Modified

| File | Change |
|---|---|
| `config/digital.php` | Added `asset_types` + `allow_software_assets`; legacy keys marked transitional/deprecated |
| `.env.example` | Added Digital Products block with 5 env vars (**DIG-008 fix**) |
| `packages/marvel/src/Http/Requests/DigitalAssetCreateRequest.php` | Rules now read from `AssetTypeRegistry` (`activeExtensions()`, `activeMaxKb()`, `creatableTypes()`); dropped `DigitalAsset::ACTIVE_TYPES` import |
| `app/Services/Digital/DigitalAssetService.php` | Constructor-injected registry; `assertUploadAllowed()` whitelists/size via registry; identical error keys/statuses |
| `app/Models/DigitalAsset.php` | Docblock only: `ACTIVE_TYPES` marked DEPRECATED → registry |
| `docs/audits/digital-products/error-ledger.md` | DIG-008 → FIXED w/ evidence; DIG-004/DIG-011 remain OPEN with W2-progress notes |
| `docs/audits/digital-products/master-todo.md` | W2 items checked with evidence |
| `docs/audits/digital-products/architecture-gaps.md` | W2 status addendum; G1/G2/G8 per-gap updates; capability matrix refreshed |
| `docs/audits/digital-products/current-state.md` | §8 W2 addendum |

## 4. Registry Architecture

```
config/digital.php
 └─ asset_types[TYPE]                     ← DigitalAssetType enum values as keys
     ├─ enabled / downloadable / streamable / previewable /
     │  url_allowed / checksum_required / requires_secret   (type-level defaults)
     └─ categories[CATEGORY]              ← DigitalAssetCategory values (FILE only)
         ├─ extensions / mime_types       ← DECLARED surface (target taxonomy)
         ├─ active_extensions / active_mime_types ← ACTIVE surface (current pipeline)
         ├─ streamable / previewable      ← category override (wins over type default)
         └─ max_kb (optional)             ← category size override ?? global max_upload_kb

App\Enums\DigitalAssetType      FILE|URL|LICENSE|ACCESS        (delivery semantics)
App\Enums\DigitalAssetCategory  DOCUMENT…SOFTWARE              (FILE content families)
App\Services\Digital\AssetTypeRegistry
 ├─ knowledge:    types(), resolveType(), categories(), resolveDeclaredCategory()
 ├─ upload truth: creatableTypes(), uploadableCategories(), activeExtensions(),
 │                activeMimeTypes(), activeMaxKb(), supportsExtension(), supportsMime(),
 │                resolveCategory()
 └─ capabilities: isEnabled(), isDownloadable(), isStreamable(), isPreviewable(),
                  allowsExternalUrl(), requiresChecksum(), requiresSecret()
```

Consumers: FormRequests (validation rules), `DigitalAssetService` (server checks), future DeliveryResolver/LicenseService, tests. Controllers hold zero extension lists.

## 5. Supported Taxonomy

Legend: ✅ active today · ◻ declared/inactive · 🔒 gate-dependent. URL/LICENSE/ACCESS are **types without categories**; all their delivery behavior is future work.

| Type | Category | Extensions (declared) | MIME families | Downloadable | Streamable | Previewable | URL allowed | Checksum req. | Secret req. | Enabled (recognition) | Uploadable now |
|---|---|---|---|---|---|---|---|---|---|---|---|
| FILE | DOCUMENT | pdf epub doc docx txt rtf odt | pdf, epub+zip, msword, docx-ml, text/plain, rtf, odt | ✅ | ◻ (A3 later) | ✅ | — | ✅ | — | ✅ | **pdf only** |
| FILE | SPREADSHEET | xls xlsx csv ods | ms-excel, xlsx-ml, text/csv, ods | ✅ | ◻ | ◻ | — | ✅* | — | ✅ (declared) | ◻ |
| FILE | PRESENTATION | ppt pptx odp | ms-powerpoint, pptx-ml, odp | ✅ | ◻ | ◻ | — | ✅* | — | ✅ (declared) | ◻ |
| FILE | ARCHIVE | zip 7z tar gz tgz | zip, 7z, tar, gzip | ✅ | ◻ | ◻ | — | ✅ (category may override) | — | ✅ (declared) | ◻ |
| FILE | AUDIO | mp3 wav m4a aac ogg flac | mpeg, wav, mp4, aac, ogg, flac | ✅ | ◻ (A3) | ◻ | — | ✅* | — | ✅ (declared) | ◻ |
| FILE | VIDEO | mp4 webm mov mkv | mp4, webm, quicktime, matroska | ✅ | ◻ (A3) | ◻ | — | ✅* | — | ✅ (declared) | ◻ |
| FILE | IMAGE | jpg jpeg png webp svg gif tif tiff | jpeg, png, webp, svg+xml, gif, tiff | ✅ | ◻ | ✅ | — | ✅* | — | ✅ (declared) | ◻ |
| FILE | SOFTWARE 🔒 | exe msi dmg apk ipa appimage | pe, msdownload, msi, dmg, apk, octet-stream | ✅* | ◻ | ◻ | — | ✅* | — | 🔒 flag-gated | **never until W4 populates active surface** |
| URL | — | — | — | ◻ | ◻ | ◻ | ✅ | ◻ | ◻ | ◻ inert (W5) | ◻ |
| LICENSE | — | — | — | ◻ | ◻ | ◻ | ◻ | ◻ | ✅ | ◻ inert (W5/A2) | ◻ |
| ACCESS | — | — | — | ◻ | ◻ | ◻ | ◻ | ◻ | ✅ | ◻ inert | ◻ |

\* inherited from type-level default. "Enabled" = recognition status (`isEnabled`); "Uploadable" = live pipeline acceptance (`uploadableCategories()`). TEXT intentionally folded into DOCUMENT (test-guarded).

## 6. Software Gate (A1)

- Default: `config('digital.allow_software_assets') === false` ← `env('DIGITAL_ALLOW_SOFTWARE_ASSETS', false)` ← `.env.example` ships `false`.
- Disabled ⇒ `isEnabled(FILE,'SOFTWARE') = false`, `resolveDeclaredCategory('exe') = null`.
- Enabled ⇒ category *recognized* (`resolveDeclaredCategory('apk') = SOFTWARE`) but **still not uploadable** — SOFTWARE's active surface is empty, so `supportsExtension('exe')` stays false until W4 deliberately populates it behind the hardened pipeline. Double protection: flag AND empty active list. Evidence: tests X/Y + `test_software_gate_defaults_to_false_and_honors_override`.

## 7. Backward Compatibility (PDF flow intact)

1. Active surface equals the legacy whitelist exactly: `activeExtensions() === ['pdf']`, `activeMimeTypes() === ['application/pdf','application/x-pdf']`, size from unchanged `max_upload_kb` (registry test asserts all three).
2. Runtime override compatibility: `DigitalAssetAdminTest` sets `config(['digital.max_upload_kb' => 10])` and expects rejection — passes unchanged (75/75 regression run includes it).
3. Full mandated regression: **OK (75 tests, 195 assertions)** across `DigitalFulfillmentTest`, `DigitalDownloadSecurityTest`, `DigitalCartCheckoutTest`, `DigitalAssetAdminTest`, `ProductItemTypeTest`, plus the new registry suite.
4. Deprecated-key decoupling proven: mutating `digital.allowed_mimes/png` cannot widen acceptance (dedicated test).
5. No routes, controllers, resources, fulfillment, entitlement, download, refund, or permission code was modified in W2.

## 8. DIG-008 — Expected FIXED ✅

Evidence chain:
- `.env.example` lines 203–212 now declare `DIGITAL_PRODUCTS_ENABLED=true`, `DIGITAL_MAX_UPLOAD_KB=20480`, `DIGITAL_DOWNLOAD_LIMIT=5`, `DIGITAL_SIGNED_URL_TTL=30`, `DIGITAL_ALLOW_SOFTWARE_ASSETS=false`.
- Prefix-normalized coverage check: every `env('DIGITAL_*')` consumed by `config/digital.php` has an `.env.example` entry → **COVERAGE COMPLETE**.
- Regression test `test_env_example_declares_every_digital_variable_consumed_by_config` locks it permanently (green).
- Ledger updated to **FIXED** with this evidence.

## 9. DIG-004 — Expected OPEN ✅ (correctly NOT fixed)

Client-MIME dependencies remain exactly where they were: `DigitalAssetService.php:46` persists `$file->getClientMimeType()` (fallback `'application/pdf'`) and `getMimeType()` at :79 is header/finfo-partial derived — no full-content sniffing exists. W2 only centralized the *whitelists*, preparing the single choke point W4 needs. Ledger remains **OPEN** with an explicit W2-progress note; no false FIXED claim.

## 10. DIG-011 — Expected OPEN ✅ (untouched by design)

Storage semantics of `store()`/`delete()` are byte-for-byte unchanged (FS ops still inside DB transactions). Fix belongs to W4 (after-commit ordering + compensating cleanup + failure-injection tests). Ledger remains **OPEN**.

## 11. Tests

| Suite | Tests | Result |
|---|---|---|
| `DigitalAssetTypeRegistryTest` (new) | 34 | ✅ OK (88 assertions) |
| `DigitalFulfillmentTest` | included below | ✅ |
| `DigitalDownloadSecurityTest` | included below | ✅ |
| `DigitalCartCheckoutTest` | included below | ✅ |
| `DigitalAssetAdminTest` | included below | ✅ |
| `ProductItemTypeTest` | included below | ✅ |
| **Combined digital + item-type run** | **75** | ✅ **OK (195 assertions)** |
| Full repository suite (blast-radius insurance) | 3,397 | 185 errors / 159 failures / 2 skipped — **all pre-existing**, zero digital/upload/config-related (see §12) |

No numbers fabricated; all runs executed locally during this session.

## 12. Regression Results

- Mandated suites + item-type: **green** (above).
- Full-suite triage: extracted all 345 failing/error entries → grep for `Digital|Upload|Asset|ItemType` → **NONE** match. Failure clusters are coupons/checkout/cart suites erroring on `no such table: device_tokens` (sqlite bootstrap gap) and similar environment/test-infra issues.
- Stash-comparison proof: representative failing suites ran identically with W2 stashed vs applied (40 errors both sides, identical cause) → pre-existing, not W2-caused. Recorded here rather than silently ignored; they belong to other modules (Rule 11: do not modify unrelated modules).

## 13. Repository Verification

Searches performed and outcomes:
1. `allowed_mimes|allowed_mime_types` runtime consumers after rewiring: **NONE** (deprecated keys unconsumed; definitions retained in config with deprecation notice).
2. `max_upload_kb`: single live consumer = `AssetTypeRegistry:196` (global fallback).
3. `DigitalAsset::ACTIVE_TYPES`: zero consumers outside model definition.
4. `application/pdf` hardcodes remaining: `InvoiceController` (unrelated module, out of scope) and `DigitalAssetService:46` (DIG-004 territory, W4).
5. `.env.example` ↔ config env-var coverage: **COMPLETE** (prefix-normalized diff).
6. Syntax lint (`php -l`) on all six touched/new PHP files: clean.

## 14. Out-of-Scope Work

**NOT implemented:** no migrations/schema changes, no server-side finfo, no checksum persistence, no filesystem transaction redesign, no multi-format uploads, no URL/LICENSE/ACCESS creation or delivery, no software uploads, no streaming/Range, no replacement endpoint, no admin entitlement management, no new permissions, no import/export, no notifications, no caching, no queues, no E2E lifecycle harness. The registry's non-PDF taxonomy is **metadata only**; no endpoint behavior changed beyond internalizing validation sources.

## 15. Master TODO Status

`master-todo.md` Phases 1–2 (W2) block marked complete with evidence notes (including honest deviations: ACTIVATION_CODE constant retained dormant; TEXT category folded into DOCUMENT). All W3–W12 items remain unchecked. Phase 0 section already closed by W1.

## 16. Final Verdict

**WORKSTREAM 2 — PASS WITH DOCUMENTED OBSERVATIONS**

Observations (none blocking W3):
1. Pre-existing full-suite failures exist in unrelated modules (device_tokens bootstrap etc.) — documented above, owned outside W2 scope.
2. DIG-004 and DIG-011 remain OPEN by design; both are W4 deliverables with prepared integration points.
3. ACTIVATION_CODE model constant kept dormant instead of deleted (avoids touching model API for zero behavioral gain); revisit at W5.
4. Task-brief version claims (Laravel 12/PHP 8.4) diverge from actual environment (Laravel 10.30.1/PHP 8.2.28); implementation follows the real codebase.

---
*STOP — Workstream 2 complete. Awaiting explicit approval before Workstream 3 (schema/migrations).*
