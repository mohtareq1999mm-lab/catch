# WORKSTREAM 5 — FINAL REPORT

- **Date:** 2026-08-24
- **Verdict:** see §11.

---

## 0. INDEPENDENT RE-CHECK ADDENDUM (hardening pass, same day)

Per the close-gate mandate, an independent black-box verification layer was built and executed AFTER implementation: `storage/w3-audit/w5_independent_check.php`.

**Design independence:** production migrations on a scratch DB (never test bootstrap) · real HTTP through the framework kernel with Sanctum bearer tokens · every expectation derived from raw PDO schema introspection, configuration, and response bodies — never from production model casts or W5 test helpers · freshly seeded users/products/assets per run.

**Result: 39/39 PASS**, covering:
- raw-schema nullability contract (path/external_url/secret nullable; encrypted_key NOT NULL)
- registry/config activation state (4 types; one-time reveal default ON)
- URL create via HTTP → 201 with **path NULL + checksum NULL + normalized external_url persisted (raw reads)**
- SSRF loopback rejected at the HTTP boundary with zero rows written
- license pool bulk import → count-only response, zero plaintext echo
- **ciphertext-at-rest proven by raw byte inspection**: distinct IVs, no plaintext substrings
- fulfillment via REAL `PaymentSucceeded` event dispatch → exactly-one entitlement, exactly-one key bound
- disclosure matrix over HTTP: owner sees URL/reveal metadata; stranger empty; guest 401; revoked hides URL & blocks reveal 403; expired hides URL & blocks reveal 403; second reveal 403; ACCESS re-revealable ×2 exact credential
- artifact scan: zero plaintext credentials in any log file

**Authorization matrix at the HTTP boundary** (`w5_authz_fresh.php`, fresh-process runs — spatie enforcement is per-request-process):
guest→401 · admin-without-permission→403 · admin-with-permission→201.

**Harness findings (observations only, NOT product defects):**
1. Reusing ONE kernel instance across many authenticated requests can leak cached guard users between requests even after `forgetGuards()`; mitigated in-harness with `guard('sanctum')->forgetUser()` pre-handle + fresh tokens. PHP-FPM isolates requests; PHPUnit and fresh-process runs enforce correctly.
2. `route:list` remains blocked by the unrelated pre-existing bKash dependency gap.

Regression after hardening: full digital matrix re-run **OK — 120 tests / 435 assertions**. Scope snapshot recorded in `workstream-5-scope-snapshot.md`.

## 1. Executive Verdict

**PASS WITH DOCUMENTED OBSERVATIONS**

## 2. Scope Implemented

**External URL:**
- Creation through the EXISTING admin endpoint (`type=URL` + `external_url`); dispatch lives in the Marvel controller but every rule in `app/Services/Digital`.
- `ExternalUrlValidator`: https-default scheme policy, hostname deny-list/suffixes/optional allowlist, userinfo rejection, literal IPv4+IPv6 public-range enforcement (incl. **v4-mapped-IPv6 unpacking**), one-time DNS resolution requiring EVERY A/AAAA record public, unresolvable→reject. Server never fetches/proxies ⇒ redirect re-validation N/A-by-design (documented).
- Delivery = controlled disclosure of `external_url` on the authenticated entitlement listing ONLY while delivered + unexpired. No fake path/checksum/size; URL rows persist `path=NULL`, `checksum=NULL`, `mime=text/uri-list`.

**License/Access:**
- LICENSE = key-pool container (A2): new `POST digital-assets/{uuid}/license-keys` bulk import (batch-capped 500; count-only response; plaintext never echoed), gated by NEW permission `manage-digital-licenses` (enum case + master/staff/owner/super-admin seeder buckets + en/ar labels).
- Allocation inside the existing fulfillment transaction via `lockForUpdate`; idempotent (existing-allocation guard + UNIQUE(order_product_id) anchor); pool exhaustion delivers without a key (translated reveal error).
- Customer reveal `GET general/digital/license/{entitlement}/{asset}`: auth-scoped (never signed — secrets don't belong in URLs), ownership at read time, delivered+expiry gate (`accessAllowed()`), product binding; config-driven one-time reveal (`DIGITAL_LICENSE_ONE_TIME_REVEAL`, default true). Plaintext exists only in that single JSON response.
- ACCESS = single encrypted credential on the asset row, re-revealable through the same endpoint.
- Entitlement expiry enforced additively across listing disclosure, signed-URL issuance, download gate, and reveal (NULL expiry = unchanged behavior).
- Zero migrations, zero new statuses, zero parallel pipelines.

## 3. Files Changed

**Created:** `app/Services/Digital/ExternalUrlValidator.php` · `packages/marvel/src/Http/Requests/StoreLicenseKeysRequest.php` · `tests/Feature/Digital/DigitalExternalUrlLicenseTest.php` · this report · concurrency harness `storage/w3-audit/w5_concurrency_check.php` (+worker)

**Modified:** `config/digital.php` (types live + url/license policy) · `DigitalAssetService` (createUrl/createLicense/createAccess/addLicenseKeys) · `DigitalFulfillmentService` (allocateLicenseKeys in-tx) · `DigitalDownloadController` (accessAllowed gate, additive index fields, reveal) · `routes/api.php` (reveal route) · `Rest/Routes.php` (license-keys route) · `DigitalAssetController` (type-aware store + storeLicenseKeys + permission wiring) · `DigitalAssetCreateRequest` (conditional file/url/secret rules) · `DigitalAssetResource` (external_url for URL type only) · `Permission` enum · `PermissionSeeder` · `resources/lang/{en,ar}/{message,permissions}.php` + `de/message.php` (7 message keys + label ×3 locales) · `.env.example` (+1 consumed var) · legacy bootstrap parity patch in `DigitalDownloadSecurityTest`

**Tests:** W5 suite 16 tests / 118 assertions; SSRF probe 20/20; MySQL concurrency harness 11/11.

**Documentation:** master-todo Phase 5–6 block closed; architecture-gaps G7 → RESOLVED; current-state §11; production-history entry.

## 4. Architecture Compliance

Existing DigitalAsset abstraction ✅ · AssetTypeRegistry remains sole taxonomy truth (URL/LICENSE/ACCESS activated THROUGH it) ✅ · existing entitlement flow reused (allocation hooks INTO fulfillment tx; revoke/expiry reuse D7 machinery) ✅ · no second pipeline (one service, type-dispatched) ✅ · Marvel = CRUD+dispatch only ✅ · unrelated modules untouched (scope audit §W5-12 clean) ✅ · pricing untouched ✅.

## 5. Security

| Gate | Result | Evidence |
|---|---|---|
| URL SSRF | PASS | probe 20/20 (loopback/private/link-local/metadata/userinfo/v4-mapped/NXDOMAIN) |
| Redirect validation | N/A-by-design | server never fetches; documented |
| Authorization | PASS | owner-only disclosure; attacker listing empty; guest 401 |
| Secret protection | PASS | ciphertext-at-rest asserted raw; plaintext only in reveal body; model `$hidden`; resources exclude |
| IDOR | PASS | foreign entitlement reveal → 404 |
| Revocation | PASS | revoked → 403 translated (both URL disclosure & reveal) |
| Refund interlock (D7) | PASS | revoke() path tested; refund approval guard untouched |
| Log leakage | PASS | Log spy asserts zero writes during lifecycle |

## 6. Concurrency

Allocation **PASS** · duplicate allocation **PASS** (zero duplicates across 12-worker scarce-pool race) · idempotency **PASS** (8-way same-order race + sequential replays allocate nothing new). Real MySQL cross-process proof; SQLite acknowledged insufficient for lock semantics.

## 7. Regression

W1–W4 **PASS**: combined digital matrix **120 tests / 435 assertions OK** vs W4 baseline 104/311 (+16 W5 tests). Newly introduced failures: **0**. Two W2-era RegistryTest expectations updated to the superseding W5 contract (protective intent preserved); legacy DownloadSecurity local bootstrap patched for parity (documented pre-existing divergence).

## 8. Error Ledger

No new OPEN defects. Authoring-time self-caught test bugs (translation group prefix, Mockery API misuse) fixed before any green claim and therefore not ledgered as product defects. Ledger state unchanged: DIG-004/008/011/012 FIXED, DIG-009 N/A.

## 9. Observations

1. DNS TOCTOU/rebinding is inherent to the no-fetch model (no app-side connection to hijack; customer browser connects directly) — documented in validator docblock.
2. Truncated/header-only PDFs remain accepted (W4 observation, unchanged scope).
3. `consumed` license state reserved; activation-code semantics deferred.
4. Audited redirect-style URL delivery can be added by W7's DeliveryResolver without contract change.
5. `php artisan route:list` still blocked by the pre-existing bKash dependency gap (unrelated).

## 10. Documentation

PASS — all six documents updated consistently; scratch evidence DB dropped; harnesses retained.

## 11. Final Decision

**WORKSTREAM 5 — PASS WITH DOCUMENTED OBSERVATIONS**

---
*HARD STOP honored: Workstream 6 not started.*
