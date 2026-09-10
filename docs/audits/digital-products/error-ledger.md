# Digital Products — Error Ledger

- **Date opened:** 2026-08-24 (Workstream 1)
- **Status vocabulary:** OPEN · FIXED · BLOCKED · DEFERRED · NOT APPLICABLE
- **Rule:** A defect is only recorded after reproduction against the working tree. Statuses are never upgraded without runtime evidence. Regression-test references are added when the fix lands.

---

## DIG-008 — `.env.example` missing all `DIGITAL_*` configuration variables

| Field | Entry |
|---|---|
| **Status** | FIXED (Workstream 2, 2026-08-24) |
| **Location** | `.env.example`; consumer `config/digital.php:15,22,28,33` (+ new `allow_software_assets`) |
| **Endpoint** | All digital endpoints (environment-level) |
| **Expected** | Deployment template exposes every env var the feature consumes: `DIGITAL_PRODUCTS_ENABLED`, `DIGITAL_MAX_UPLOAD_KB`, `DIGITAL_DOWNLOAD_LIMIT`, `DIGITAL_SIGNED_URL_TTL`, `DIGITAL_ALLOW_SOFTWARE_ASSETS` |
| **Actual (defect)** | Grep for `DIGITAL_` across `.env.example` returned zero matches. Ops deploying from template silently ran code defaults |
| **Root Cause** | Feature landed with config defaults only; environment documentation step skipped |
| **Impact** | Operational blind spot; misconfigured TTL/limits could not be discovered from the repo |
| **Security Impact** | Low direct risk; kill-switch (`DIGITAL_PRODUCTS_ENABLED=false`) is an incident-response control ops may not have known existed |
| **Fix** | Appended a documented "Digital Products" block to `.env.example` with all five variables and safe defaults matching config/digital.php exactly (`true/20480/5/30/false`) |
| **Regression Test** | `DigitalAssetTypeRegistryTest::test_env_example_declares_every_digital_variable_consumed_by_config` asserts each var+default via regex against `.env.example`. Runtime verification: prefix-normalized comparison proves every `env()` call in config/digital.php has a matching `.env.example` entry (COVERAGE COMPLETE, 2026-08-24) |

---

## DIG-004 — Client-supplied MIME type trusted for storage and delivery

| Field | Entry |
|---|---|
| **Status** | FIXED (Workstream 4, 2026-08-24) |
| **Location** | `app/Services/Digital/DigitalAssetService.php` — validateUpload()/detectMime()/store() |
| **Endpoint** | `POST api/v1/products/{product}/digital-assets` |
| **Expected** | Server-side finfo detection of actual bytes is the ONLY authoritative MIME; extension↔detected-MIME must agree within one registry category; detected value is what persists |
| **Actual (defect)** | Service persisted `$file->getClientMimeType()` and validated via header-derived `getMimeType()` |
| **Root Cause** | MVP validation relied on Laravel convenience accessors without a content-sniffing policy |
| **Fix** | `detectMime()` = `finfo(FILEINFO_MIME_TYPE)` over the real uploaded path; whitelist checks via registry; NEW strict pairing `AssetTypeRegistry::resolveCompatibleCategory(ext, mime)` rejects cross-category spoofing; persisted `mime` = detected value only; client accessors never consulted |
| **Regression Test** | `DigitalAssetUploadPipelineTest`: pdf-bytes-with-lying-client-accessors accepted & persisted as application/pdf (16-test suite, 66 assertions); zip/png-bytes-with-pdf-name rejected 422 with zero DB rows and zero files; checksum equals sha256 of stored bytes |

---

## DIG-011 — Filesystem operations inside DB transactions create orphan file/row windows

| Field | Entry |
|---|---|
| **Status** | FIXED (Workstream 4, 2026-08-24) |
| **Location** | `app/Services/Digital/DigitalAssetService.php` — store() and delete() lifecycles |
| **Endpoint** | `POST …/digital-assets`, `DELETE api/v1/digital-assets/{uuid}` |
| **Expected** | SUCCESS ⇒ row+file both exist; FAILURE ⇒ neither persists; no observable state where a served row lacks its file or a file lacks its row |
| **Actual (defect)** | store(): FS write inside tx before create() → rollback left orphan FILE; delete(): Storage::delete before row-delete inside tx → rollback left orphan ROW pointing at deleted file |
| **Root Cause** | Laravel transactions never roll back filesystem mutations; irreversible FS ops were sequenced inside the DB boundary |
| **Fix (store)** | Validate everything first → write file (server UUID name) → persist row → on ANY persistence failure compensate by deleting just-written file, rethrow original exception |
| **Fix (delete)** | Row deletion alone inside tx; physical removal strictly AFTER commit; post-commit FS failure logs a warning with asset context (drift surfaced for ops; unreachable-by-design since download gates require the row) |
| **Regression Test / Evidence** | Failure-injection through REAL infrastructure: forced storage-write failure → 500, no row, no files; forced INSERT failure (column hidden via live ALTER) → row absent + orphan compensated; forced UNIQUE-constraint duplicate (temp unique index) → first pair intact, failed attempt cleaned; forced DELETE failure (table rename) → row+file pair preserved; forced post-commit unlink failure → row gone + warning logged. Suite: DigitalAssetUploadPipelineTest (16 tests). Proof artifact: storage/w3-audit/w4-http-proof.txt |

---

## DIG-009 — Claimed Arabic translation gap — NOT REPRODUCED

| Field | Entry |
|---|---|
| **Status** | NOT APPLICABLE |
| **Location** | `resources/lang/ar/message.php` |
| **Endpoint** | Product item_type change (`PUT products/{id}` via ItemTypePolicy) |
| **Expected (claim)** | Initial discovery pass reported `ERROR.ITEM_TYPE_IMMUTABLE_ORDERED/_ASSETS` missing from Arabic, which would render raw keys to ar-locale admins |
| **Actual (verification)** | Keys PRESENT at ar lines 289–290 with valid UTF-8 Arabic values; byte-level read confirms file integrity. The `???` seen in console output was PowerShell codepage display artifact, not file corruption. All nine digital/ITEM_TYPE keys verified across en (484–492), ar (289–297), de (7–15) |
| **Root Cause** | False positive from encoding-obscured discovery scan |
| **Impact** | None |
| **Security Impact** | None |
| **Fix** | None required. Recorded per Rule 14/15 discipline: a refuted finding is documented as such, never silently dropped nor converted into a PASS claim for anything else |
| **Regression Test** | Translation-presence assertions for all nine keys will be added to the suite during Workstream 8 to lock this invariant |

## DIG-012 — SQLite rollback of `path` change was lossy (Laravel 10 `->change()` rebuild fidelity)

| Field | Entry |
|---|---|
| **Status** | FIXED (Workstream 3, 2026-08-24) |
| **Location** | `database/migrations/2026_08_24_120100_extend_digital_assets_for_multi_type_assets.php` (down) |
| **Endpoint** | Migration infrastructure (no HTTP surface) |
| **Expected** | `down()` restores the pre-W3 `digital_assets.path` definition exactly: VARCHAR(255) NOT NULL, plus drops the 8 added columns |
| **Actual (defect)** | Using `$table->string('path')->change()` on SQLite produced a rebuilt table with `path CLOB NULL` — type AND nullability fidelity lost through Laravel's native sqlite table-rebuild; a second collision class surfaced because SQLite index names are database-global (rebuild-time named indexes clashed with the still-present original table) |
| **Root Cause** | Laravel 10 SQLite `->change()` fidelity limits + global index-name namespace |
| **Impact** | Rollback on non-production engines would silently diverge from the canonical pre-W3 schema |
| **Security Impact** | None (test/ops tooling path only) |
| **Fix** | Driver-aware down(): MySQL uses house-precedent raw `ALTER TABLE … MODIFY path VARCHAR(255) NOT NULL` (self-guarding against NULL-path rows); SQLite performs an explicit faithful rebuild — create replacement table bare under a temporary name, copy original columns, drop original (releases canonical names), rename, re-attach `unique(uuid)` + `digital_assets_product_sort_idx` |
| **Regression Test / Evidence** | `storage/w3-audit/schema_check.php lifecycle`: post-rollback PRAGMA asserts `notnull=1` + VARCHAR type; full battery **94/94 on SQLite and MySQL**; discovered live during W3 execution and repaired before closure |

---

## Ledger Rules Going Forward

1. Every defect discovered in Workstreams 2–12 enters here with ID, location, endpoint, expected/actual, root cause, impact, security impact, status, fix, regression test — before or together with the fix.
2. `FIXED` requires: code fix + green regression test + evidence reference.
3. Environment limitations are recorded as `BLOCKED` with the specific constraint — never as application PASS (Rule 15).

---

## W5 Independent Verification Footnote (2026-08-24)

Post-hardening re-verification of all statuses against the live working tree:

| ID | Claimed | Re-verified via |
|---|---|---|
| DIG-004 FIXED | ✅ zero client-MIME calls on digital surface; byte-truth tests green |
| DIG-008 FIXED | ✅ env coverage test green |
| DIG-011 FIXED | ✅ failure-injection tests green (upload+delete) |
| DIG-012 FIXED | ✅ W3 lifecycle harness retained |
| DIG-009 N/A | ✅ unchanged |

New harnesses retained under storage/w3-audit/: w5_independent_check.php (black-box HTTP + raw-PDO, 39 checks), w5_authz_fresh.php (fresh-process permission matrix), w5_concurrency_check.php (+worker, real MySQL race).

Harness finding (NOT a product defect): running many authenticated requests through ONE kernel instance in-process can leak cached guard users between requests (uth()->forgetGuards()/orgetUser() mitigations applied inside harnesses). Production PHP-FPM serves each request in isolation; PHPUnit and fresh-process runs enforce permissions correctly. Classified: observation only.

---

## W8 Verification Footnote (2026-08-25)

Closure battery (10 tests / 171 assertions), independent final gate (25/25), MySQL concurrency re-runs (W5 11/11, W6 5/5), real-worker queue proof re-run (5/5) all executed post-W7. All ledger statuses re-verified against runtime evidence: DIG-004/008/011/012 FIXED, DIG-009 N/A, zero OPEN.

New ENVIRONMENT OBSERVATION (not a digital defect): stale bootstrap/cache/config.php captured without Pusher credentials broke broadcast-channel notification tests and masked ~110 unrelated repo-wide failures as errors; removal restored green. Ops guidance: never run php artisan config:cache from a shell whose environment differs from the serving user, or ensure config:clear in deploy hooks.
