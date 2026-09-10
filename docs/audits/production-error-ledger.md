# Production Error Ledger — Full Real-World E2E Validation (2026-08-24)

All issues discovered during live execution are recorded below with final status. No unfixed application defects remain from this pass; items classified ENVIRONMENT or ARCH are documented rather than forced.

---

## E2E-ERR-001
- **Severity:** P1 · **Category:** ENV · **Module:** Auth/OTP mail
- **Endpoint:** POST /api/v1/register (+ any OTP send path)
- **File:** vendor resend transport / .env MAIL credentials
- **Expected:** OTP notification delivered via configured mail service
- **Actual:** `Resend email send failed {"error":"API key is invalid"}` → 3–5 stuck retrying jobs on meem-high
- **Reproduction:** register a user with valid payload while QUEUE_CONNECTION=database; drain queue
- **Evidence:** storage/logs/laravel.log entry "Resend email send failed"; stuck jobs payload `Marvel\Notifications\OneTimePasswordNotification`
- **Root Cause:** external Resend API key invalid/absent in local environment
- **Impact:** registration still succeeds (account created); verification code cannot be emailed locally
- **Security Impact:** none
- **Business Impact:** OTP-based flows unusable until valid mail credentials deployed
- **Recommended Fix:** provision production Resend key via environment secrets
- **Fix Status:** [!] Blocked (external credential) · **Regression Test:** n/a · **Verification:** queue mechanics proven separately

## E2E-ERR-002
- **Severity:** P2 · **Category:** I18N/ENV · **Module:** Settings bootstrap
- **Endpoint:** GET /api/v1/general/settings
- **File:** SettingResource / settings table seed
- **Expected:** public settings render on a migrated-but-unseeded database
- **Actual:** HTTP 500 `getTranslation() on null` when the settings row is absent (SettingSeeder not run)
- **Reproduction:** fresh DB + migrate only → GET /general/settings
- **Evidence:** observed during first E2E run; resolved after `db:seed --class=SettingSeeder` (PUB-SETTINGS PASS)
- **Root Cause:** resource assumes singleton settings row exists
- **Impact:** none on properly seeded installs (DatabaseSeeder includes SettingSeeder)
- **Security Impact:** none
- **Business Impact:** fresh deployments must run full seeders (standard practice)
- **Recommended Fix:** optional null-guard in SettingResource for empty installs
- **Fix Status:** [x] Resolved by seeding (observation documented) · **Verification:** PUB-SETTINGS PASS in final clean run

## E2E-ERR-003 … E2E-ERR-010 — HARNESS ARTIFACTS (not application bugs)
During calibration, the following were proven to be test-harness issues, each fixed inside `storage/e2e/*` and re-verified green:
- Register payload contract (first_name/last_name/policy/password_confirmation) and unique phone reuse across runs.
- admin-login token key is `token` (with permissions array) — initial assertion used wrong key.
- Locale mechanism is the custom `lang:` header, not Accept-Language.
- Multipart fields require Illuminate\Http\UploadedFile instances; each field needs its own temp file (first media add consumes its source file).
- Category/product/governorate unique translation names collide across repeated runs → randomized suffixes.
- Export start returns 202 (async contract) and downloads use Binary/Streamed responses requiring stream capture to validate bytes.
- Rate-limit proof requires pinned REMOTE_ADDR (harness otherwise rotates IPs per request).

**Ledger totals:** 0 open application defects introduced or left by this pass · 1 environment blocker (mail credentials) · 1 optional hardening observation (settings null guard) · architectural blockers continue to live in `/error.md` (ERR-001..ERR-004).
