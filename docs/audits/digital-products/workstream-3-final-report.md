# WORKSTREAM 3 — FINAL REPORT

- **Date:** 2026-08-24
- **Engines verified:** MySQL 8.4.3 (scratch database `meem_w3_audit`, dropped after evidence capture) + SQLite 3.x (scratch file DB). Dev database `chawkbazar` was never touched.
- **Verdict:** see §14.

---

## 1. Scope

Schema + migrations + model-compat + test-bootstrap only. Implemented:

1. `digital_assets` widened to the multi-type registry table (8 new columns, path → nullable, composite status index).
2. New `digital_license_keys` pool table (A2 representation).
3. `digital_entitlements.expires_at`.
4. Model compatibility layer (fillable/casts/hidden/relations/constants) incl. `encrypted` casts for `secret` and license keys.
5. Test bootstrap parity in `tests/Concerns/CreatesTestTables.php`.
6. Runtime evidence harness `storage/w3-audit/schema_check.php` + PHPUnit capability suite.

**NOT implemented (later workstreams):** content sniffing/checksum computation/orphan-proofing (W4), upload activation of non-PDF categories (W4), URL validation/delivery (W5), LicenseService/reveal/consume logic (W5), expiration enforcement (W7), streaming (W7), entitlement admin endpoints (W6), permissions (A4 stays as-is), notifications, E2E lifecycle.

## 2. Migrations Created (additive; newest three → precise rollback targeting)

| File | Concern |
|---|---|
| `2026_08_24_120100_extend_digital_assets_for_multi_type_assets.php` | 8 columns + `(product_id,status)` index + `path` nullable |
| `2026_08_24_120200_create_digital_license_keys_table.php` | License key pool |
| `2026_08_24_120300_add_expires_at_to_digital_entitlements_table.php` | Entitlement expiry column |

FK order safe: both referenced tables (`digital_assets`, `digital_entitlements`) pre-exist from 2026_08_23; no circularity.

## 3. digital_assets — Final Shape

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id / uuid | — | — | — | uuid UNIQUE preserved |
| product_id | FK→products CASCADE | no | — | preserved |
| type | string(20) | no | FILE | URL/LICENSE/ACCESS now representable |
| **status** NEW | string(20) | no | `active` | existing rows backfilled by DEFAULT |
| disk | string(30) | no | private | preserved |
| path | varchar(255) | **NULL** ← changed | — | reason: URL/LICENSE/ACCESS have no physical file; FILE flow unchanged |
| **external_url** NEW | TEXT | yes | NULL | |
| original_name | string | no | — | preserved |
| **display_name** NEW | string | yes | NULL | |
| mime | string(100) | no | — | preserved |
| **extension** NEW | string(16) | yes | NULL | values owned by AssetTypeRegistry |
| size | uBigInt | no | — | preserved |
| **checksum** NEW | string(64) | yes | NULL | SHA-256 written by W4, not migration |
| **metadata** NEW | JSON | yes | NULL | duration/pages/etc. |
| **secret** NEW | TEXT | yes | NULL | consumed via `'encrypted'` cast |
| sort_order | uint | no | 0 | preserved |
| **expires_at** NEW | timestamp | yes | NULL | |

Indexes: existing `digital_assets_product_sort_idx` preserved; NEW `digital_assets_product_status_idx(product_id,status)` — justified by status-filtered admin grids and future access checks.

## 4. digital_license_keys — Final Shape

| Column | Type | Null | Default |
|---|---|---|---|
| id | bigint | no | — |
| uuid | char(36) UNIQUE | no | — |
| asset_id | FK→digital_assets **CASCADE** | no | — |
| encrypted_key | TEXT | **NOT NULL** | — (only ever written via `'encrypted'` cast) |
| status | string(20) | no | `available` (available/assigned/consumed/revoked) |
| allocated_entitlement_id | FK→digital_entitlements **SET NULL** | yes | NULL |
| assigned_at / revealed_at / consumed_at / revoked_at | timestamps | yes | NULL |
| created_at / updated_at | timestamps | — | — |

Indexes: `digital_license_keys_asset_status_idx(asset_id,status)` — pool lookup ("next available key per asset") doubling as the MySQL FK index; `digital_license_keys_allocation_idx(allocated_entitlement_id)`.

**Partial-unique portability decision:** the business rule "one live allocation per entitlement" cannot be expressed as a portable partial unique index across MySQL 8.0-class DDL and SQLite. Per instructions it was NOT faked: schema enforces integrity (FKs, unique uuid, cascade/set-null); allocation-uniqueness is documented as a service-layer responsibility (W5). Recorded in architecture-gaps §3.

## 5. digital_entitlements

Existing columns untouched; added `expires_at timestamp NULL`. NULL expiry = today's behavior exactly (no code reads it yet).

## 6. Model Changes (schema-required only)

| Model | Change |
|---|---|
| `DigitalAsset` | fillable +8 fields; casts `metadata→array`, `expires_at→datetime`, `secret→encrypted`; `$hidden += secret`; `STATUS_ACTIVE` constant (+reserved-state docblock) |
| `DigitalEntitlement` | fillable/casts + `expires_at` |
| `DigitalLicenseKey` (NEW) | schema-representation model: statuses available/assigned/consumed/revoked; boot uuid+default; casts incl. `encrypted_key→encrypted`; `$hidden=[encrypted_key]`; relations asset()/allocatedEntitlement(). No business methods. |
| Product / Order / OrderProduct | unchanged (no schema impact) |

Security note: neither `secret` nor `encrypted_key` can leak through default serialization or existing resources (both hidden at model level; resources never re-declare them).

## 7. Test Bootstrap Changes

`CreatesTestTables.php`: digital_assets block rebuilt to full W3 contract (nullable path, all new columns, both named indexes); entitlements + expires_at; new digital_license_keys block inserted between entitlements and pivot (FK order). This is THE canonical mirror used by DigitalAssetAdminTest/Registry/Schema suites. Observation: `DigitalFulfillmentTest` and `DigitalDownloadSecurityTest` carry pre-existing self-contained minimal schemas that are subsets of the contract; they pass unchanged and were intentionally not rewritten (no divergence introduced by W3).

## 8. Fresh Migration Evidence (runtime, both engines)

Harness mode `fresh` (migrate:fresh → 75 metadata assertions over real information_schema / PRAGMA):

| Engine | Result |
|---|---|
| MySQL 8.4.3 (`meem_w3_audit`) | **75/75 PASS** |
| SQLite scratch file | **75/75 PASS** |

Assertions include: all 5 tables exist; every target column with exact nullability; status NOT NULL default active (proven by insert-probe row reading back `active`); type defaults to FILE; unique uuid indexes; named composites present; FK rules read back as products→CASCADE, digital_assets→CASCADE, digital_entitlements→SET NULL.

## 9. Rollback + Existing-Data Compatibility Evidence

Harness mode `lifecycle` (94 checks/engine): fresh → rollback --step=3 → assert pre-W3 schema restored (all 8 columns gone, license table gone, expires_at gone, **path back to NOT NULL**) → seed legacy PDF row on OLD schema → migrate → assert row survived with original_name/mime/size/type/path intact AND status auto-backfilled `active` → rollback again over existing data (row survives) → double migrate:fresh → full 75-check schema suite re-run.

| Engine | Result |
|---|---|
| MySQL 8.4.3 | **94/94 PASS** |
| SQLite | **94/94 PASS** |

Rollback design notes:
- MySQL `down()` restores `path NOT NULL` via house-precedent raw `ALTER TABLE … MODIFY`, which naturally refuses while any NULL-path (non-file) row exists — a documented self-guarding limitation, never silent data loss.
- SQLite `->change()` reversal proved lossy during development (path returned CLOB-nullable) → replaced with explicit faithful table rebuild using the exact original blueprint. SQLite global index-name collisions were eliminated by building the replacement bare, dropping the original (releasing canonical names), renaming, then re-attaching `unique(uuid)` + `product_sort_idx`.

## 10. Double Fresh-Migrate Proof

`migrate:fresh → migrate:fresh` executed twice consecutively inside both engines' lifecycle runs followed by the complete assertion battery — **PASS both engines**. No dependency on prior state, manual tables, or cached schema.

## 11. Capability Smoke Tests (PHPUnit, sqlite)

`tests/Feature/Digital/DigitalSchemaIntegrityTest.php` — **13 tests / 50 assertions OK**:

| Capability proven | Method |
|---|---|
| Target columns exist w/ correct semantics | Schema API matrix |
| Defaults: status=active (DB-level via refresh), type=FILE | insert probe |
| Legacy PDF row still representable verbatim | raw old-schema-shape insert |
| URL asset: path NULL + external_url persisted | direct record (NO fetch/redirect/validation — W5 owns those) |
| License pool lifecycle available→assigned→consumed→revoked incl. reveal/consume/revoke timestamps | direct state transitions ONLY (explicitly not a feature claim) |
| Keys encrypted-at-rest (raw value ≠ plaintext) + cast round-trip + never serialized | model create vs raw DB read |
| Asset `secret` same guarantees | ditto |
| Duplicate license-key uuid rejected | QueryException |
| Unknown asset_id rejected (FK enforcement) | QueryException |
| Entitlement future/past/NULL expiry persist | direct writes |
| Delete asset → its keys cascade; sibling keys survive | delete + assertions |
| Delete entitlement → allocation SET NULL, inventory survives | delete + assertions |
| Pivot + product cascade intact; product forceDelete cascades line incl. entitlements (documented pre-existing products→order_products→entitlements chain) | delete chain |

## 12. Regression Results

| Suite | Result |
|---|---|
| All digital suites + ProductItemTypeTest + Registry + Schema suites | **OK — 88 tests / 245 assertions** |
| Full repository suite vs W2 baseline (identifier-normalized diff of 341 vs 345 failing entries) | **ZERO newly failing tests**; 4 unrelated flaky failures recovered |

No test was modified to hide a regression. Two schema-suite expectations were corrected during authoring against the REAL contracts (Eloquent doesn't surface DB defaults pre-refresh; soft-deletes don't fire physical cascades — `forceDelete()` required; products→order_products→entitlements cascade is pre-existing behavior now documented in-test).

## 13. Ledger / Defect Status

| ID | Status | Note |
|---|---|---|
| DIG-008 | FIXED (unchanged) | — |
| DIG-004 | OPEN (unchanged) | W4 owns sniffing; untouched here |
| DIG-011 | OPEN (unchanged) | W4 owns FS atomicity; storage semantics untouched |
| DIG-012 (NEW) | FIXED | SQLite down() fidelity gap discovered & repaired in-workstream; regression = lifecycle harness 94/94 on sqlite incl. post-rollback PRAGMA verification |

## 14. Final Verdict

**WORKSTREAM 3 — PASS WITH DOCUMENTED OBSERVATIONS**

Observations:
1. Rollback restores `path NOT NULL` but refuses (by design, loudly) if non-file rows exist at rollback time on MySQL; on SQLite the rebuild drops any such rows' extra fields (they cannot exist on the rolled-back schema). Documented limitation, zero silent data loss.
2. "One live allocation per entitlement" is deferred to service-layer enforcement (W5) — portable partial unique index unavailable; recorded in architecture-gaps.
3. Two legacy digital suites keep their own minimal schema bootstraps (pre-existing pattern, strict subsets of contract, green). Canonical mirror remains CreatesTestTables.
4. Scratch evidence databases/files were destroyed after capture; harness retained at `storage/w3-audit/schema_check.php` for reproduction (`DB_CONNECTION=… DB_DATABASE=… php schema_check.php [fresh|lifecycle]`).

---
*STOP — Workstream 3 complete. Awaiting explicit approval before Workstream 4.*
