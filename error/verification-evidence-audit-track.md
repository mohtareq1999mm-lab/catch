# Verification Evidence — audit track (this track only; parallel-track files excluded)

> `error/verification-evidence.md` is owned by the parallel fix track (isolated `catch_verify` tests). This file records ONLY this audit track's evidence. No secrets/PII. Honesty rule: uncited numbers are downgraded, not claimed.

## DB identity (VERIFIED, pre-incident; identity still holds post-wipe)
- `.env`: `DB_CONNECTION=mysql, DB_HOST=127.0.0.1, DB_PORT=3306, DB_DATABASE=catch, DB_USERNAME=root, PASSWORD=[REDACTED]` (Get-Content redacted).
- Tinker: `{"d":"catch","h":"M-tareq","p":3306,"v":"8.4.3"}` — catch@3306, MySQL 8.4.3. See `database-target-verification.md` (safety gate CORRECTED to BLOCKED; identity proof VALID).

## Pre-wipe aggregates (HISTORICAL, VOID for re-execution)
- 126 tables; orders=13; products=4824; users=8; flows=2; statuses=22; warehouses=1 (tinker COUNT, read-only). Current catch: 0/0/0 (post-wipe). See DATA-LOSS-INCIDENT.md.

## Route census (VERIFIED, unaffected by wipe)
- Route facade count: 533 total, 515 api/v1. Full table: `error/route-inventory-full-table.md` (539 lines, 533 rows — this track, append-only). Canonical summary `error/route-inventory.md` owned by parallel track.
- Per-domain buckets (Route facade script): general 76, admin 108 (Agent A family-sum 115, ±7 grouping diff — ESTIMATE, not reconciled), dashboard 21, coupons 20, products 19, categories 18, brands 16, etc. Sums never reconciled to 533 — treat per-domain counts as ESTIMATES (SHOULD-FIX follow-up).

## Test execution (this track)
### Single-test claim — DOWNGRADED to UNVERIFIED (no preserved log)
- Recalled output `1 passed (8 assertions)` for `--filter=test_login_with_valid_credentials_returns_token` was observed in shell but NO log file was preserved. Per evidence rule it is UNVERIFIED and must NOT be cited as proof. (Reviewer MUST-FIX applied.)
### Broad-filter run — INVALID, quarantined (see DATA-LOSS-INCIDENT.md)
- `--filter=AuthenticationTest` → `4 failed, 41 passed (70 assertions)` — executed while `migrate:fresh` wiped the DB; numbers are INVALID and never cited as verification.

## Translation parity (PARTIAL, scoped)
- Method: PHP script read `resources/lang/en/*.php` vs `ar/*.php`, top-level + 1-nested key diff, JSON output observed in shell. Script file was deleted after run (`Remove-Item`) so result is UNREPRODUCIBLE from artifact — scoped as PARTIAL, not VERIFIED-proof.
- Result as observed: 0 missing both directions, 15 files (activity 99/99 … message 592/592 … wms 12/12). `ar.json` UNVERIFIED. Deep (2+) recursion UNVERIFIED. Placeholders UNVERIFIED. `tax 0/0` = both files define zero top-level keys (empty arrays) — noted, not an error.
- Quarantine census commands (preserved here for reproducibility):
  - `Get-ChildItem tests -Recurse -Filter "*.php" | Select-String -Pattern "RefreshDatabase" | Select-Object -Unique Path | Measure-Object` → 241 distinct files.
  - Same for `DatabaseTransactions` → 110. Total `*Test.php` → 480.
  - Prior false-negative cause: `Select-String -Path "tests/**/*.php"` does not recurse in PowerShell — documented.
- Legacy reachability: REST `Routes.php` imports only `Order\OrderController`; legacy `Marvel\Http\Controllers\OrderController` unreferenced in REST (grep 4 hits all Order\OrderController) BUT GraphQL-reachable (`OrderQuery.php:14,19`, `OrderMutator.php:20` via `Shop::call`) — VERIFIED file+line; trait bypass semantics INFERRED (needs audit).

## Not verified (this track)
- All success-branch integration, concurrency, payments/refunds crypto, signed-URL redemption, import/export, dashboard analytics — BLOCKED.
- Full suite (479-480 files) — NOT RUN. Static analysis/lint — NOT RUN. `catch_verify` isolation — not used by this track (no claim).

## Iter-006 verification (code fixes, no DB writes, no test runs)
- `php -l` 5/5 clean: Rest/Routes.php, api.php, UserController.php, RoleAndPermissionController.php, UpdateContactRequest.php.
- `route:list`: 533 total unchanged (no route changes this round).
- Caller grep: verifyLoginOtp (definition + otpLogin delegate), checkResetToken (2 sites) — all hooked; GraphQL AuthMutator funnels into same methods (lockout applies; pre-existing token-vs-otp param mismatch noted, not conflated).
- Independent review round 2: no BLOCKER/MUST-FIX; F3/F4/F5 + N1/N3/N4 applied; F1/F2 + S3 + guest-redemption + N7 documented residuals.
- Runtime payloads (200/404/429 bodies, lockout timing, cache driver behavior, concurrency) BLOCKED: seeded fixtures must not be wiped; RefreshDatabase quarantine stands. No test executed.
- `php -l` 5/5 clean: Rest/Routes.php, api.php, UserController.php, RoleAndPermissionController.php, UpdateContactRequest.php.
- `route:list`: 533 total unchanged; `api/v1/me` single (throttle:admin winner retained); `digital/download` single; `POST /contacts` public named contacts.store (single) + index behind sanctum+permission; `contact-us` public; delete-all sanctum+admin+permission; update-contact/change-password sanctum+sensitive; address sanctum+authenticated; assign-role PATCH + permission intact.
- Independent review: 1 BLOCKER (B1 public store) + S1-S4/N1-N3 — all applied (B1 alias; S1 404 catch; S2 dropped exists; S4 explicit 'api'; N1 comments; N2 is_string + clamp note). S3 clone-role + guest-redemption + banner split recorded as open product decisions.
- Runtime behavior (401/403/422/429 bodies, OTP E2E, role-escalation exploit, concurrency) BLOCKED: DB empty (users=0) + 241 RefreshDatabase files. No test executed this session.

## Iter-009 verification ("fix all" round: OTP + lockout + shipment cap; no DB writes, no test runs)
- `php -l` clean: UserController.php, ShipmentController.php (re-run after review corrections F3/F4/F5/N1/N3/N4).
- `route:list`: 533 total unchanged (no route changes this round).
- Caller grep: verifyLoginOtp (definition + otpLogin delegate), checkResetToken (2 sites) — all hooked; GraphQL AuthMutator funnels into same methods (lockout applies; pre-existing token-vs-otp param mismatch noted, not conflated).
- Independent review round 2: no BLOCKER/MUST-FIX; F3/F4/F5 + N1/N3/N4 applied; F1/F2 + S3 + guest-redemption + N7 documented residuals.
- Runtime payloads (200/404/429 bodies, lockout timing, cache driver behavior, concurrency) BLOCKED: seeded fixtures must not be wiped; RefreshDatabase quarantine stands. No test executed.

## Iter-010 test loop (real MySQL catch, certified files only, per-file commands)
| File | Result | Root causes fixed |
|---|---|---|
| AuthenticationTest | 19/19 (45 assertions) | relative token baselines + token-id missing proof |
| UserPasswordResetTest | 13/13 (22 assertions) | settings ensure + contact-us 201 |
| OtpLocalLoginTest | 4/4 (8 assertions) | none needed (UserController change preserves 404/400) |
| RoleAndPermissionTest | 34/34 (369 assertions) | role reuse+sync, customer type, POST to PATCH x4, guard_name correction, 2 new SEC-004 tests |
| UserControllerTest | 68/71 | 3 BLOCKED: make-admin route COMMENTED disabled (route:list ERROR) |
| UserAuthAdminTest | 12/19 | 7 BLOCKED: update-email x4 + social-login-token x3 unregistered (route:list ERROR each) |
- Total: 150 pass / 10 BLOCKED (all 10 = tests against non-endpoints, proven, implementation refused as new-feature scope).
- Residue zero: 7/12/3/2/9 + test_user=0 identical before/after every run.
- Env incident: MySQL down mid-loop, 1 false-red run discarded (conn-refused, 0 assertions); restarted 8.4.3 on live datadir; re-runs green.
- Full suite (241 RefreshDatabase files) NEVER run: would wipe fixtures; forbidden as evidence anyway.
