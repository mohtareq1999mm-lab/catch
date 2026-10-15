# DATA-LOSS INCIDENT — catch database wiped by RefreshDatabase test run

## Status: CONFIRMED DATA LOSS — ALL WRITES HALTED

## What happened
- At ~17:5x UTC 2026-10-14, lead agent ran `php artisan test --filter=AuthenticationTest` to gather safe verification evidence.
- `--filter=AuthenticationTest` substring-matched FIVE files:
  - `tests/Feature/AuthenticationTest.php` (safe: DatabaseTransactions + CreatesTestTables early-return)
  - `tests/Feature/Categories/CategoryAuthenticationTest.php` (UNSAFE: `use RefreshDatabase`)
  - `tests/Feature/Contacts/ContactAuthenticationTest.php`, `Faqs/FaqAuthenticationTest.php`, `Settings/SettingsAuthenticationTest.php` (need trait check — presumed UNSAFE pattern)
- `CategoryAuthenticationTest.php:20` uses `RefreshDatabase` → `migrate:fresh` against the LIVE `catch` database (phpunit.xml forces DB_DATABASE=catch) → dropped all tables and re-migrated empty.
- Result (VERIFIED via tinker):
  - BEFORE: users=8, orders=13, products=4824, flows=2, statuses=22, warehouses=1, 126 tables.
  - AFTER: users=0, orders=0, products=0, flows=2 (re-seeded by migrations), 126 tables (schema re-created, data gone).
- Single-test run earlier (`--filter=test_login_with_valid_credentials_returns_token`, 1 passed) was safe and did NOT wipe (users still 8 before the full-filter run — VERIFIED).

## Root cause
- Lead agent violated mission §0 + §9: ran a `--filter` that matched uninspected test files using `RefreshDatabase` against `catch` without first grepping every matched file for `RefreshDatabase/DatabaseMigrations/migrate:fresh/truncate`.
- Initial safety check (`Select-String RefreshDatabase` across tests/) returned EMPTY due to a broken PowerShell glob (`tests/**/*.php` not recursed) — agent proceeded on false-negative instead of re-checking with correct recursion. Second check (after incident) found `RefreshDatabase` in CategoryAuthenticationTest immediately.
- `CreatesTestTables` early-return safety applies ONLY to files using that trait; `RefreshDatabase` files ignore it entirely.

## Evidence
- Failing/passing output: `Tests: 4 failed, 41 passed (70 assertions), Duration: 61.90s` (failures include `personal_access_tokens` count + `categories/1` 404 — both consistent with fresh-migrated empty DB).
- Tinker AFTER: `db=catch, users=0, orders=0, products=0` + `tables=126`.
- Unsafe file: `tests/Feature/Categories/CategoryAuthenticationTest.php:7,20` (`use RefreshDatabase`).
- Other matched files' traits: CONFIRMED post-incident via grep (all 4 Authentication* siblings use RefreshDatabase: Contacts, Faqs, Settings, Categories — see iteration-log Iter-004 + TODO DB-005 census: 241 distinct RefreshDatabase files). No further test runs occurred.
- Git status shows pre-existing modifications (AuditInventoryPlacements, FulfillmentService, Routes.php, WmsPlacementAuditTest) — NOT caused by test run; test run does not touch git. `.phpunit.cache/test-results` modified by the run (expected).

## Recovery assessment (read-only, no restore attempted)
- `meemmarket_meem.sql` in repo root (6.6MB, phpMyAdmin dump, Generation Time Oct 14 2026 05:14 PM, Server MariaDB 11.4.13, Database `meemmarket_meem`):
  - 126 CREATE TABLE, 1 users INSERT statement, 1 orders INSERT, 54 products INSERTs (statement counts, not row counts; each may be multi-row).
  - Catch had 4824 products vs dump's 54 product INSERT statements → data differs significantly OR dump uses extended inserts. DB name differs (`meemmarket_meem` vs `catch`), server differs (MariaDB 11.4 vs MySQL 8.4.3).
  - Classification: EXTERNAL SYSTEM / HOST CONTEXT, NOT a verified backup of `catch`. Restoring it into `catch` without approval + compatibility check would be a second destructive operation. NOT ATTEMPTED.
- No other backup verified. Binlog/point-in-time recovery UNKNOWN (needs DBA + host access).
- Schema is intact (126 tables, migrations re-ran). Only DATA is lost. Seeded flows=2 exist post-migrate.

## Immediate containment (done)
- ALL test executions HALTED. No further `php artisan test`, no tinker writes, no migrations.
- Only read-only `SELECT COUNT` queries since incident (to prove state).
- No code edits made in this phase (error/ docs only).
- No restore attempted (requires explicit approval per autonomy policy: destructive DB operation).

## Impact on audit mission
- All real-DB integration claims based on the 13 orders / 4824 products / 8 users are now VOID for re-execution (data gone). Prior read-only evidence (counts, route census, code traces) remains valid.
- Every write/integration/concurrency test is now BLOCKED (no safe eligible records + no DB to test against until recovery decided).
- Read-only audit (routes, code, schema, translations, security code review) can continue safely.
- Test-matrix execution counts: 1 passed (safe single test BEFORE wipe) + 41 passed/4 failed DURING wipe run — the 41/4 numbers are INVALID as proof (executed against a database being migrated under them) and must NOT be cited as verification evidence.

## Required user decisions (asked via question tool, blocking)
1. How to recover `catch` data (restore from authoritative backup / re-import / accept empty / point-in-time)? Provide the authoritative dump + confirm compatibility.
2. Whether to continue the audit read-only against the current empty `catch`, or pause until recovery.
3. Whether to quarantine `RefreshDatabase` tests (add `--exclude` / guard to refuse running when DB_DATABASE=catch) — proposed as mandatory follow-up.

## Lessons / follow-up TODOs
- DB-005 (new): quarantine RefreshDatabase tests; add pre-run guard that refuses when DB is `catch` unless explicit flag.
- TEST-005 (new): never use `--filter` substring without listing matched files first (`--list-tests`).
- Never trust a negative grep from a single untested glob; re-verify with recursion + count.
- Record exact commands + DB before/after counts for EVERY test run (done from now on).

## INCIDENT #2 (2026-10-15) — shared-DB wipe during certified loop (CORRECTED ATTRIBUTION)
- BEFORE (my b6 residue check): users=7/orders=12/products=3/tokens=2/roles=9, 126 tables.
- AFTER: users=0/orders=0/products=0/tokens=0, flows=2/statuses=22, roles=1, 127 tables, migrations=194 rows.
- The WIPE (drops + 194 migration rows): NOT mine — my c2 file uses DatabaseTransactions only (verified zero RefreshDatabase/migrate/truncate strings); transactions cannot drop tables or insert migration rows. External actor (parallel track documents its own migrate/seed activity on catch in C-003/C-005) — INFERRED, no per-process audit either way.
- The 127th TABLE (`content_page_section`): LIKELY MINE — my c2 setUp runs `createAllTestTables` + guarded `Schema::create` blocks (MeemForensicFullTest.php:62-267, incl. lines 95-96) whenever tables are missing. On the already-emptied DB it additively created the one test-only table migrations don't define (126 migrated + 1). Additive DDL only; zero data touched; left in place deliberately (parallel cites it as forensic artifact — dropping others' evidence would be worse).
- CORRECTION to my earlier claim: "transactional runs can't create tables" is FALSE for the missing-table path — setUp DDL runs OUTSIDE/AROUND the per-test transaction. Lesson: verify table presence pre-run, not just traits.
- My c2 98-fail INVALID (ran on emptied DB), discarded. Prior loop results stand (recorded with intact before/after counts).
