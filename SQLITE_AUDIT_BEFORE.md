# SQLITE AUDIT — BEFORE (2026-09-27)

Scope: full repo `D:\work\meem`. Method: repo-wide grep for
`sqlite|SQLite|:memory:|pdo_sqlite|PRAGMA|sqlite_master|strftime|database.sqlite`
plus config/env/CI/scripts/docs/filesystem inspection. Each hit classified below.

## Current DB configuration

- `.env`: `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=3306`,
  `DB_DATABASE=catch`, `DB_USERNAME=root`, `DB_PASSWORD` empty. No `.env.testing`.
- `.env.example`: `DB_CONNECTION=mysql` BUT `DB_DATABASE=marvel_laravel` (stale name).
- `config/database.php`: default `env('DB_CONNECTION','mysql')`; contains a
  `sqlite` connection block (driver sqlite, `database.sqlite` default).
- `phpunit.xml`: forces `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`.
- `phpunit.mysql.xml` (tracked, prior-session): mysql `catch_verify:3306`.
- No `DB::connection('sqlite')` / `Schema::connection('sqlite')` runtime usage
  anywhere in `app/`, `routes/`, `bootstrap/`, seeders, factories, jobs.
- Dockerfile: `pdo_mysql` only. No `.github` CI, no docker-compose, no Pest.php.
- composer.json / package.json scripts: no sqlite references.

## ACTIVE SQLite dependencies (require change)

| # | File | Behavior | Action |
|---|------|----------|--------|
| 1 | `config/database.php:38-44` | `sqlite` connection definition | REMOVE block |
| 2 | `phpunit.xml:21-22` | forces sqlite `:memory:` for all tests | mysql/`catch` via non-force `<env>` (shell can still override) |
| 3 | `app/Services/Analytics/OrderAnalyticsService.php:232,247,263,276-286` | sqlite `strftime` branches + `sqliteDateFormat()` | REMOVE branches/method, keep MySQL `DATE_FORMAT` |
| 4 | `app/Services/Dashboard/DashboardService.php:850-862` | `dateFormat()` sqlite branches + strftime fallback | MySQL-only map |
| 5 | `scripts/verify_migrations.php` | throwaway sqlite DB utility (`storage/migrate_check.sqlite`) | Retarget to MySQL `catch_verify` |
| 6 | `.env.example:21` | `DB_DATABASE=marvel_laravel` | `catch` |
| 7 | `database/database.sqlite` | sqlite file on disk (gitignored, untracked) | DELETE |
| 8 | `storage/migrate_check.sqlite` | sqlite file (tracked) | DELETE |
| 9 | `storage/w3-audit/w6q.sqlite` | sqlite file (tracked, regenerable by `w6_qdump.php`) | DELETE |
| 10 | `phpunit.mysql.xml` | redundant once phpunit.xml is MySQL | DELETE (single standard) |

## Dead-but-harmless (NO change — no-ops on MySQL, edits = churn/conflict risk)

- ~25 test files: `if (config('database.default') === 'sqlite') DB::statement('PRAGMA foreign_keys = ON;')`
  plus `tests/Concerns/WithAdminInvoiceContext.php:41-42`,
  `tests/Feature/Digital/{DigitalSchemaIntegrityTest,DigitalExternalUrlLicenseTest}`,
  `tests/Feature/Database/FilteredUniqueIndexTest.php:53-68` (driver probe + informational `dump()`),
  `FcmIntegrationTest.php:25`, `ForUpdateLockTest.php:79-86`, `CouponConcurrencyProofTest.php:20-21`,
  `MultiConnectionLockTest.php:16`, `ConcurrencyAttackTest.php:20`, comments in
  `CartOrderLifecycleTest:47`, `AssignedCouponSystemTest:1246`, `OrderTrackingTest:95`,
  `AnalyticsServiceTest:80-84`, `RealProductionLeakCheckTest:33`, `DigitalExternalUrlLicenseTest:20`.
- All migrations' `if ($driver === 'sqlite')` branches + `sqlite_master` probes
  (`app/database` + `packages/marvel/database`): migration HISTORY, MySQL paths proven by
  fresh `migrate:fresh` on MySQL 8.4. Rewriting history = risk, zero benefit.
- `tests/Concerns/CreatesTestTables.php:588-595` partial-index attempt with try/catch
  fallback (fails gracefully on MySQL, which lacks partial `WHERE` indexes; real
  constraint comes from production migration `2026_08_31_130000` under RefreshDatabase).
- `(int)` casts in `OrderAnalyticsService:141-143` (driver-agnostic; only the comment mentions SQLite — comment removed as part of #3 cleanup).

## Historical (NO change — rewriting = falsifying evidence)

- `storage/w3-audit/*.php`, `storage/zerotrust/_setup.php`, `storage/realtest/_setup.php`:
  frozen one-off audit scripts (documented `DB_CONNECTION=sqlite` usage lines).
- All `*.md` under `docs/`, `api-desc/`, `COUPON_*`, root reports: dated verification evidence.
- `docs/implementation-design.md`, `api-desc/*`: design rationale mentioning sqlite.
- `packages/marvel/.../Coupon.php:88` collation comment; `mime-db` node_modules data.

## Verified clean (no action)

- Seeders, factories: no sqlite assumptions. Commands/Jobs/Listeners: no explicit
  connection selection (default → mysql). Routes/bootstrap: none.
- No `*.sqlite` files besides the 3 listed. `.gitignore`: no sqlite rules to remove.

## Risks

- R1: Switching phpunit.xml to mysql/`catch` makes RefreshDatabase suites wipe+rebuild
  `catch` on every run (E2E marker rows + demo data destroyed; re-seedable via
  `PaymentE2ESeeder` + `DatabaseSeeder`). Accepted per task directive; `catch` is local dev.
- R2: Full-suite MySQL run may surface pre-existing failures (e.g. currency-format
  assertions); each will be classified, none silently fixed.
- R3: `storage/*.sqlite` deletions are tracked-file deletions — intended ("remove obsolete files").

## Files that must NOT be changed

- `vendor/` (package capability ≠ project dependency).
- Migrations (history). Business logic outside the two date-format helpers.
- Unrelated working-tree modifications (OrderFlow feature, coupon docs, routes/api.php alias removal).
- `.env` (environment instance config; only `.env.example` template changes).
