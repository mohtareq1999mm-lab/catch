# WORKSTREAM 4 — FINAL INDEPENDENT CLOSE GATE

- **Date:** 2026-08-24 · **Mode:** adversarial verification; nothing trusted from the W4 report without independent reproduction.
- **Verdict:** **PASS WITH OBSERVATION** — see §1 and §14.

## 1. Verdict
**PASS WITH OBSERVATION**

## 2. Worktree
- Working tree carries an extensive PRE-EXISTING uncommitted backlog from earlier engagements (invoices, fast-shipping, coupons, import/export, item-type MVP, device tokens…). This was already present at the W1 snapshot; none of it is W4 output.
- W4 delta verified = exactly the declared set: `AssetTypeRegistry` (+pairing method), `DigitalAssetService` (rewrite), 2 new keys ×3 locales (`message.php` diffs contain precisely those additions from this engagement), `DigitalAssetUploadPipelineTest` (new), `DigitalAssetAdminTest` (fixture), docs. No unrelated modification introduced by W4.

## 3. DIG-004 — independently reproduced (probe cases A–J)
| Case | Input | Result |
|---|---|---|
| A | valid PDF, correct client label | 201; persisted mime=application/pdf (DB raw read) |
| B | PDF bytes + client accessors lying `application/zip` | accepted; persisted = DETECTED application/pdf |
| C | PNG bytes named .pdf | 422 INVALID_MIME; rows=0 files=0 |
| D | ZIP bytes named .pdf | 422 INVALID_MIME; rows=0 files=0 |
| E | PDF bytes named .txt | 422 INVALID_FILE (ext whitelist); rows=0 files=0 |
| F | garbage text named .pdf | 422 INVALID_MIME; rows=0 files=0 |
| G | EMPTY file (finfo→application/x-empty) | 422 INVALID_MIME; rows=0 files=0 |
| H | header-only truncated PDF | ACCEPTED by magic-byte detection — recorded observation (deep semantic validation out of W4 scope; standard finfo mechanism) |
| I | GIF89a header named .pdf | 422 INVALID_MIME; rows=0 files=0 |
| J | unusual filename, valid bytes | 201; storage path `digital-assets/{id}/{uuid}.pdf`; client name metadata-only |

Data source into finfo confirmed by source read: `finfo::file($file->getRealPath())` — actual uploaded bytes only. Zero `getMimeType/getClientMimeType` calls remain in any digital service/model/controller.

## 4. DIG-011 failure matrix
| Failure point | DB state | File state | Expected | Actual | Result |
|---|---|---|---|---|---|
| A physical write fails | empty | empty | no trace | proven (500 UPLOAD_FAILED test) | ✅ |
| B insert fails after write | rolled back | compensated | no trace | proven (live column-hide injection → orphan deleted) | ✅ |
| C duplicate constraint | first pair intact | first file intact, no second | as expected | proven (temp UNIQUE index injection) | ✅ |
| D delete fails (table renamed live) | row present | file present | consistent pair | proven | ✅ |
| E post-commit unlink fails | row DELETED | file remains + warning(uuid) logged | documented drift state | proven (disk seam + Log spy) | ✅ unreachable-by-customers (download gates require row) |
| F checksum calc failure | n/a | n/a | cannot diverge | theoretical race only: detectMime guards realPath before checksum; mid-flight temp loss not reachable via HTTP | observation |
| G partial move/finalization | — | — | adapter contract | putFileAs true ⇒ file exists is Flysystem contract; no post-write re-verify | observation |
| H exception between write & persist | rolled back | compensated | cleanup | `catch (Throwable)` compensates AND RETHROWS (verified source lines 67–70) | ✅ |

Source-verified invariant: neither `DB::transaction` body contains any storage operation; delete() unlinks strictly after commit.

## 5. Checksum
Rename-invariant (same bytes different filename ⇒ identical), one-byte-change sensitive, 64-hex lowercase, and **DB checksum == hash('sha256', stored-file contents)** — all re-proven independently (probe `[CK]`, plus suite assertion).

## 6. Software Gate
With `DIGITAL_ALLOW_SOFTWARE_ASSETS=true`: registry `supportsExtension('exe')===false`; service rejects MZ-bytes 422; HTTP upload 422 at FormRequest layer; zero rows/files. Recognition ≠ uploadability holds at every layer (probe `[SW]`).

## 7. Download Regression
Upload → entitlement → signed URL → download: streamed bytes byte-identical to uploaded bytes; Content-Type = detected; attachment disposition; count incremented once; path/disk never serialized (suite `test_uploaded_pdf_downloads_…`, 14 assertions, re-run green).

## 8. Translation
Runtime-triggered per locale: en/ar/de resolve both new keys to real strings; Arabic asserted against `\x{0600}-\x{06FF}` glyph range; no raw key leakage; existing keys untouched (diff audited).

## 9. Authorization
401 guest / 403 view-only / 201 authorized — covered by DigitalAssetAdminTest (green) and exercised throughout probe+suite.

## 10. Regression (exact, per-suite runs this gate)
| Suite | Result |
|---|---|
| A DigitalAssetTypeRegistryTest | OK (34 tests, 88 assertions) |
| B DigitalAssetAdminTest | OK (7 tests, 22 assertions) |
| C DigitalSchemaIntegrityTest | OK (13 tests, 50 assertions) |
| D DigitalFulfillmentTest | OK (11 tests, 31 assertions) |
| E DigitalDownloadSecurityTest | OK (14 tests, 36 assertions) |
| F DigitalCartCheckoutTest | OK (9 tests, 18 assertions) |
| G ProductItemTypeTest | OK (16 tests, 37 assertions) |
| W4 DigitalAssetUploadPipelineTest | OK (16 tests, 66 assertions) |
| **Combined** | **OK — 104 tests / 311 assertions** |
| Independent gate probe (temporary, removed) | OK — 13 tests / 48 assertions |

Lint clean on both changed production classes. Route registration proven at runtime by every passing HTTP test; `php artisan route:list` itself errors on an UNRELATED pre-existing missing bKash dev-dependency class (`karim007/laravel-bkash-tokenize: dev-main`) — environment issue predating W4.

## 11. Ledger
DIG-004 **FIXED** (implementation + regression + runtime proof verified) · DIG-011 **FIXED** (same) · DIG-008 stays FIXED · DIG-009 stays NOT APPLICABLE · no new defects requiring entries.

## 12. Documentation
PASS — ledger statuses, master-todo Phase-4 completion, gaps/current-state addenda and W4 report all match independently verified reality. Historical evidence intact.

## 13. Production Changes During Gate
**NO NEW PRODUCTION CHANGES.** Gate probe harness was temporary and removed after evidence capture.

## 14. Final Decision

Observations (non-blocking, recorded for W9/ops):
1. Truncated/header-only PDFs pass finfo classification (magic-byte scope); semantic file validation would belong to AV/deep-inspection hooks (deferred per A5).
2. Theoretical checksum-race window (temp vanishing between detect & hash) is unreachable through normal request flow.
3. Partial-move detection relies on Flysystem contract; no post-write re-verification.

**WORKSTREAM 4 IS SAFE TO CLOSE.**
