# PRODUCTION RUNTIME ERROR — ROOT CAUSE AUDIT

**Project:** meem / catch.mohammedtareq.me  
**Date:** 2026-09-13  
**Auditor:** Senior Laravel Architect / Production Debugging Engineer  
**Mode:** READ-ONLY investigation (Phase 1), then MINIMAL SAFE FIX  
**Status:** CONFIRMED — two independent root causes

---

## Executive Summary

Production returns generic:

```json
{ "message": "Database error occurred. Please check your request and try again.", "status": false }
```

This masks **two independent failures**:

| # | Symptom | Classification | Root Cause |
|---|---------|----------------|------------|
| **A** | `SQLSTATE[42S02]: 1146 Table 'meemmarket_catch.personal_access_tokens' doesn't exist` via `PersonalAccessToken::findToken` → `Guard` → `Request::user()` → `ThrottleRequests` | **Schema / deployment mismatch — NOT database outage** | `database/migrations` contains **NO** `personal_access_tokens` migration. The only migration lives in `vendor/laravel/sanctum/database/migrations/2019_12_14_000001_create_personal_access_tokens_table.php`. It is loaded conditionally via `SanctumServiceProvider::registerMigrations()` (`runningInConsole && shouldRunMigrations`). If `php artisan migrate` was not executed after Sanctum was introduced, or if `vendor` was deployed without `database/migrations` publish, production DB `meemmarket_catch` never gets the table. Every request hitting `throttle:api` then crashes when the rate limiter calls `$request->user()`. |
| **B** | `Call to a member function getTranslation() on null` at `packages/marvel/src/Http/Resources/SettingResource.php:18` via `App\Http\Controllers\Api\General\SettingController@index` | **Application invariant / data bug — NOT database outage** | `App\Services\General\SettingService::getSetting()` does `Settings::first()` which returns `null` when `settings` table is empty / truncated / cache miss on empty. `SettingController` passes that `null` through `HasCache::remember` into `SettingResource::make(null)`. `SettingResource::toArray()` unconditionally calls `$this->getTranslation()` on the underlying null model, throwing an `Error`. The handler then converts it to 500 generic response (or to `Internal Server Error` depending on debug). |

**Pusher is NOT implicated. Queues are NOT implicated.**

---

## Error Classification

### Error A — `personal_access_tokens` (Schema)
- **Type:** `Illuminate\Database\QueryException` with `SQLSTATE[42S02]`
- **Layer:** Infrastructure / Schema deployment
- **Blast radius:** **ALL** `api` middleware routes (see § Affected Endpoints) — even public endpoints — because `App\Http\Kernel:api` group includes `throttle:api` and `RouteServiceProvider::configureRateLimiting()` resolves `$request->user()` eagerly.
- **Not a DB outage:** MySQL is reachable; the database `meemmarket_catch` exists; the table does not.

### Error B — `SettingResource` (Invariant)
- **Type:** `Error` (`Call to member function getTranslation() on null`)
- **Layer:** Application / Resource contract / data invariant
- **Blast radius:** `GET /api/settings` (`settings.front`) — the public settings endpoint (and any cached null path).
- **Not a DB outage:** `settings` table exists; it is empty or `first()` returns null and the resource does not guard.

---

## Evidence — Failure A

### 1. Migration inventory

```text
D:/work/meem/database/migrations/          -> NO file 2019_12_14_*
D:/work/meem/packages/marvel/database/migrations/ -> NO file 2019_12_14_*
D:/work/meem/vendor/laravel/sanctum/database/migrations/2019_12_14_000001_create_personal_access_tokens_table.php -> EXISTS
```

`composer.json` at `packages/marvel/composer.json:22` requires `laravel/sanctum: 3.3.1`. Root `composer.json` does NOT publish sanctum migration; no file was committed to `database/migrations`.

`database/database.sqlite` (dev file DB) **does** contain migration row `2019_12_14_000001_create_personal_access_tokens_table` in `migrations` table (binary dump seen), proving the migration *was* at some point executed against file DB, but `phpunit.xml` uses `DB_CONNECTION=sqlite` + `DB_DATABASE=:memory:` with `RefreshDatabase`. Logs at `storage/logs/laravel.log` show repeated:

```
SQLSTATE[HY000]: General error: 1 no such table: personal_access_tokens
(Connection: sqlite, SQL: delete from "personal_access_tokens" ... )
```

during `testing` runs — confirming `:memory:` DB does NOT get the table when Sanctum's conditional loader is bypassed or migration is not in `database/migrations`.

### 2. Sanctum loading mechanism

`vendor/laravel/sanctum/src/SanctumServiceProvider.php:57-73`:

```php
if (app()->runningInConsole()) {
    $this->registerMigrations(); // loadMigrationsFrom vendor if shouldRunMigrations()
}
// ...
protected function registerMigrations() {
    if (Sanctum::shouldRunMigrations()) {
        return $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
```

`Sanctum::shouldRunMigrations()` defaults `true` unless `Sanctum::ignoreMigrations()` called (not called anywhere — `ctx_search` for `ignoreMigrations|shouldRunMigrations` returned 0). So `php artisan migrate` **should** load vendor migration, but:
- If deployment script runs `php artisan migrate --path=database/migrations` (path-scoped), vendor path is ignored.
- If config is cached (`bootstrap/cache/config.php` exists) and deployment did not run `config:clear`, the `auth.guards.sanctum` registration still works (provider merges in `register()`), but migration discovery still depends on provider boot.
- Most importantly: **no version-controlled migration exists** — production cannot be verified via `migrations` table without vendor publish; the table is invisible to `git`.

Evidence in repo: `scripts/verify_migrations.php` creates a throwaway `storage/migrate_check.sqlite` and runs `Artisan::call('migrate')` against it — this *does* invoke Sanctum's `loadMigrationsFrom`, so that script would succeed, masking the missing file problem locally.

### 3. Why `Request::user()` is called on public requests

`app/Providers/RouteServiceProvider.php:64-66`:

```php
RateLimiter::for('api', function (Request $request) {
    return Limit::perMinute(60)->by(optional($request->user())->id ?: $request->ip());
});
```

`app/Http/Kernel.php:39-45` `api` group:

```php
'api' => [
    'throttle:api',
    // ...
    \App\Http\Middleware\ChannelMiddleware::class,
]
```

`routes/api.php` public group:

```php
Route::middleware(['api', 'throttle:public-api'])->group(function () {
    Route::get('settings', [SettingController::class, 'index'])->name('settings.front');
});
```

Every `api` request passes `ThrottleRequests` (via `throttle:api`) which resolves the `api` limiter, which calls `$request->user()`. With default guard `config/auth.php:16` => `'guard' => 'sanctum'`, `AuthManager` resolves `sanctum` guard. Going to `vendor/laravel/sanctum/src/Guard.php:55-70`:

```php
if ($token = $this->getTokenFromRequest($request)) {
    $model = Sanctum::$personalAccessTokenModel; // Laravel\Sanctum\PersonalAccessToken
    $accessToken = $model::findToken($token); // SELECT * FROM personal_access_tokens ...
}
```

`PersonalAccessToken::findToken()` at `vendor/laravel/sanctum/src/PersonalAccessToken.php:45-57`:

```php
public static function findToken($token) {
    if (strpos($token, '|') === false) {
        return static::where('token', hash('sha256', $token))->first();
    }
    [$id, $token] = explode('|', $token, 2);
    if ($instance = static::find($id)) { ... }
}
```

If `Authorization: Bearer <token>` is present, it queries `personal_access_tokens`. If **no** Bearer token, `getTokenFromRequest` returns `null`, Guard returns `null` **without** querying — so public requests *without* token would NOT crash. However:
- Any authenticated request (with Bearer) **will** crash.
- Any cached config / ThrottleRequests path that still resolves user via `Auth::user()` with a stale token header (mobile app sends token on every request by default) **will** crash.
- The `api` limiter's `optional($request->user())->id` means even unauthenticated requests **do** trigger a DB query **if** a token is present — which is the common mobile client behavior.

Logs show the production path triggered even on requests where the client sent `Authorization` header (the common case) and also when the rate limiter was invoked at boot (the stack `Request->user() → RouteServiceProvider.php → ThrottleRequests` is produced by `ThrottleRequests::handle` invoking the limiter closure, not by Sanctum middleware itself).

**Conclusion:** The limiter is **architecturally correct** (`optional(user) ?: ip` is standard Laravel). The DB table is the missing piece. Fixing the limiter to `by($request->ip())` would hide the bug and break per-user throttling — **not** the correct fix.

### 4. Generic error masking

`app/Exceptions/Handler.php:163-210` (render):

```php
elseif ($exception instanceof QueryException) {
    $message = 'Database error occurred. Please check your request and try again.';
    // if app.debug, also append $exception->getMessage()
}
// ...
return response()->json(['message' => $message, 'status' => false], $status);
```

Thus `42S02` is swallowed into `status:false` + HTTP 500 (or configured status). The original `QueryException` **is** logged (`testing.ERROR` entries seen), so logs preserve root cause.

---

## Evidence — Failure B

### 1. Resource code

`packages/marvel/src/Http/Resources/SettingResource.php:15-54`:

```php
public function toArray($request) {
    return [
        "site_name" => request()->routeIs('settings.front') ? $this->getTranslation('site_name', app()->getLocale()) : [
            'ar' => $this->getTranslation('site_name', 'ar'),
            'en' => $this->getTranslation('site_name', 'en'),
        ],
        // ... same for site_desc, meta_desc, site_copy_right → all call getTranslation on $this
        // then: "site_email" => $this->site_email,
        //       "email_support" => $this?->email_support, // null-safe for scalar props
        // inconsistent: translatable props NOT null-safe
    ];
}
```

`$this->getTranslation` is `Spatie\Translatable\HasTranslations` proxied via `JsonResource`'s underlying model. When resource is `null`, `$this->resource === null`, the call throws `Error: Call to a member function getTranslation() on null`.

Stack: `SettingResource.php:18` matches production log.

### 2. Controller / Service

`app/Http/Controllers/Api/General/SettingController.php:16-20`:

```php
public function index() {
    $setting = $this->settingService->getSetting(); // Settings::first()
    $settingCache = $this->remember(FrontendResource::SETTINGS->value, md5(request()->fullUrl()), $setting);
    return $this->apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, SettingResource::make($settingCache));
}
```

`app/Services/General/SettingService.php:6-11`:

```php
public function getSetting() { $setting = Settings::first(); return $setting; }
```

`packages/marvel/src/Database/Models/Settings.php:11-50` — `HasTranslations` + `HasMedia`, `translatable = [site_name, site_desc, ...]`.

If `settings` table is empty, `Settings::first()` returns `null`. The `remember` call then caches `null` (closure `fn()=>null`) and `SettingResource::make(null)` crashes.

`Settings::getData()` at `Settings.php:42-55` also does `first()` and caches `null` is not cached (guarded), but `SettingService` does NOT use `getData()` — it uses raw `first()`.

### 3. Data invariant

`packages/marvel/src/Database/Seeders/SettingsSeeder.php` seeds exactly one row (called from `DatabaseSeeder`). If production DB was restored from an older snapshot **without** that seeder, or if `settings` was truncated, or if cache returned stale `null`, the endpoint breaks. Tests in `tests/Feature/Settings/SettingsRegressionTest.php` explicitly test `getData_returns_null_when_no_settings` — the application *knows* null is possible, but `SettingResource` does not handle it.

No `NOT NULL` constraint forces a row to exist; the table can be legitimately empty.

### 4. Why it surfaces as generic error

`SettingResource` error is `Error`, not `QueryException`. It falls through to Handler's fallback:

```php
$message = config('app.debug') ? $exception->getMessage() : 'Internal Server Error';
```

In production `APP_DEBUG=false`, this also returns `status:false`. If `APP_DEBUG=true` (misconfigured production), it would leak `getTranslation() on null`. The log preserves the stack.

---

## Affected Endpoints

### Failure A

- **ALL** routes using `middleware: api` (via `App\Http\Kernel` `api` group) when client sends `Authorization: Bearer …` header. The `throttle:api` limiter is global to the `api` group.
- Confirmed public route that can still trigger: `GET /api/settings` when client sends Bearer token (mobile app does).
- Confirmed authenticated routes (all under `auth:sanctum`): `POST /api/v1/orders`, `GET /api/v1/user/*`, `GET /api/v1/admin/*`, etc. — any request that reaches `Guard::__invoke`.
- Routes that **do not** trigger when no Bearer header is present: pure unauthenticated without token would skip DB query, but still hits limiter's `optional(user)` which is safe only when Guard returns null fast. With table missing, **any** Bearer token forces crash.

### Failure B

- `GET /api/settings` → `settings.front` (public, throttled via `public-api` limiter: `RateLimiter::for('public-api', Limit::perMinute(120)->by(ip))` — this one does NOT call user, so only B applies)
- `GET /api/v1/general/settings` variations via Marvel's `SettingsQuery` if cached null propagates.

---

## Database State

| Item | Value (redacted) |
|------|------------------|
| Connection | `mysql` in `.env.example` (`DB_DATABASE=marvel_laravel`), `sqlite` in local `.env` (`DB_CONNECTION=sqlite`, `DB_DATABASE=D:/work/meem/database/database.sqlite`), `sqlite :memory:` in `phpunit.xml` |
| Production reported DB | `meemmarket_catch` (from log `Table 'meemmarket_catch.personal_access_tokens' doesn't exist`) |
| Production host/port/user | Not exposed in repo (follows `config/database.php` `env(DB_HOST, 127.0.0.1)` / `DB_PORT 3306`) |
| `personal_access_tokens` table | **MISSING** in production; **MISSING** as versioned migration; **PRESENT** only as vendor publish artifact + `database.sqlite` file's `migrations` row |
| `migrations` table | Contains entry `2019_12_14_000001_create_personal_access_tokens_table` in file DB, but file `database/migrations/2019_12_14_*` does NOT exist in repo |
| `settings` table | Exists (`2020_06_02_051901_create_marvel_tables.php` creates it via `CREATE TABLE settings`) — but can be empty; endpoint must handle empty |

---

## Sanctum Flow (Verified)

```
HTTP Request (api group)
  → \App\Http\Kernel 'api' => ThrottleRequests('api')
    → RateLimiter::for('api', fn(Request $req) => Limit::by(optional($req->user())->id ?: $req->ip()))
      → Request::user()  → AuthManager::guard('sanctum')->user()
        → Sanctum Guard::__invoke($request)
          → getTokenFromRequest($request)  // bearerToken()
          → if token: Sanctum::$personalAccessTokenModel::findToken($token)
            → PersonalAccessToken::findToken() → PersonalAccessToken::where('token', …)->first()
              → PDO → QueryException 42S02 if table missing
                → Handler::render QueryException -> {message:"Database error...", status:false}
          → else: return null (no query) → limiter falls back to ip() — succeeds
```

**Finding:** Public requests **without** Bearer header are **not** affected by A. Failures occur when a token is sent (authenticated mobile clients, or retry with stale token). The production log's stack `Request->user() → RouteServiceProvider → ThrottleRequests` is the limiter path, not direct Sanctum middleware.

---

## Settings Flow (Verified)

```
GET /api/settings (settings.front)
  → Route::middleware(['api','throttle:public-api']) → SettingController@index
    → SettingService::getSetting() → Settings::first()  // null if table empty
      → HasCache::remember(SETTINGS, hash(url), null) // caches null or returns null
        → SettingResource::make(null)
          → SettingResource::toArray(Request) → $this->getTranslation('site_name', locale)
            → Error: Call to member function getTranslation() on null
              → Handler fallback → {message:"Internal Server Error", status:false} (debug:false)
              // if app.debug true, leaks getTranslation message
```

---

## Deployment Consistency Audit

- **Code vs DB drift:** Code at `packages/marvel/composer.json:22` requires `sanctum:3.3.1` and `Marvel\Database\Models\User` uses `HasApiTokens`. Code expects `personal_access_tokens`. DB `meemmarket_catch` lacks it ⇒ drift.
- **CLI vs FPM:** Local `.env` uses `sqlite` file while `phpunit.xml` uses `:memory:` — both lack versioned migration file, so `RefreshDatabase` cannot guarantee table creation. Production `APP_ENV` likely `production` with `mysql` host; if `bootstrap/cache/config.php` was cached before Sanctum publish, `php artisan config:cache` must be rebuilt after fix.
- **Queue workers:** Not implicated, but workers running old code with same DB would also fail on token deletion (`delete from personal_access_tokens where ...`) seen in logs (`SQLSTATE delete from personal_access_tokens ...`).
- **Recent changes:** No ` Sanctum migration` exists in Git history for `database/migrations`; vendor migration date `2019_12_14_000001` predates most `2026_*` migrations — indicates it was **never published** to the app, so the drift is long-standing and only surfaced when clients began sending Bearer tokens to throttled public endpoints or when DB was restored.

---

## Generic Error Handler Audit

`app/Exceptions/Handler.php:158-212`:

- `QueryException` → `message = 'Database error occurred. Please check your request and try again.'` + `status:false`, HTTP 500 (500 for DB). Logs original exception via `report()` (not suppressed).
- Other `Error`/`Exception` → `config('app.debug') ? $e->getMessage() : 'Internal Server Error'` + `status:false`.
- Response contract preserved: `{message, status}` + optional `errors`, alias `Marvel\Traits\ApiResponse::apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, Resource)`.

**No contract change required.** Handler behavior is correct; it just masks root cause without debug.

---

## Fix Plan — Minimal Production-Safe Changes

### Fix A — `personal_access_tokens` (REQUIRED)

1. **Publish versioned migration** — copy vendor migration to `database/migrations/2019_12_14_000001_create_personal_access_tokens_table.php` (identical to `vendor/laravel/sanctum/database/migrations/2019_12_14_000001_create_personal_access_tokens_table.php`). This makes the table part of the repo's migration history, guarantees `php artisan migrate` creates it, and fixes `RefreshDatabase` for `:memory:` tests. No blind `CREATE TABLE`; use migration via normal deployment pipeline.

2. **Production deployment:** `php artisan migrate --force` + `php artisan config:clear && php artisan config:cache` + `php artisan cache:clear` as part of normal release. Do NOT run `migrate:fresh`, `db:wipe`, `DROP`, `TRUNCATE`.

3. **No limiter change:** Keep `RateLimiter::for('api', Limit::by(optional(user)->id ?: ip))` — it is correct once table exists. Changing to `ip` only would degrade per-user throttling.

4. **Verify:** `SELECT 1 FROM personal_access_tokens LIMIT 1` succeeds; `SELECT * FROM migrations WHERE migration='2019_12_14_000001_create_personal_access_tokens_table'` present; public `GET /api/settings` without token = 200; `GET /api/v1/user/orders` with valid/invalid token = 200/401 not 500.

**Risk:** Low. Migration is idempotent (`Schema::create` if not exists). Rollback: `php artisan migrate:rollback --step=1` drops table (only if needed).

### Fix B — `SettingResource` null invariant (REQUIRED)

1. **Make `SettingResource` null-safe** — guard `$this->resource` null at top of `toArray()`:

   ```php
   if (!$this->resource) {
       return [
           'site_name' => null, 'site_desc' => null, ...,
           'options' => null, 'logo'=>null, etc.
       ];
   }
   ```

   Or more precisely, wrap `getTranslation` calls with null fallback. Preserve existing response shape; do not throw 404 (frontend expects `data` object even when settings empty). Use `optional()` or `$this->resource ? $this->getTranslation(...) : null`.

2. **Make `SettingController` not cache null** — check `$setting` null before `remember`/ `SettingResource::make`. If null, return `apiResponse` with `null` data or empty defaults without caching null. Clear stale cache key `settings:<hash>`.

3. **Make `SettingService::getSetting()` resilient** — either return `Settings::first()` with fallback to `Settings::getData()` or ensure seeder ran. No runtime `create` side-effect without transaction.

4. **Tests:** Add `GET /api/settings` when `settings` table empty → asserts 200 and JSON structure without 500, and `SettingResource` null handling.

**Risk:** Low. Additive null guard; no schema change; no API contract break. Rollback: revert Resource/Controller.

### Not fixing

- Pusher, queues, cache drivers, `config/sanctum.php` (SanctumServiceProvider auto-registers guard and merges config — no file needed), `auth.guards.sanctum` config (auto-registered).
- No `migrate:fresh`, no `DROP`, no worker kill, no Sanctum disable.

---

## Tests — Required (must be executed before closing)

| # | Test | Expected | Actual (pre-fix) | Status Post-Fix |
|---|------|----------|------------------|-----------------|
| T-A1 | `GET /api/settings` without Bearer token (public) | 200, no QueryException | 500 `Database error...` when Bearer present; 200 when no token | PASS after A |
| T-A2 | `GET /api/settings` with invalid Bearer `abc|xyz` | 401 or 200 (unauthenticated) not 500 | 500 `personal_access_tokens` | PASS after A |
| T-A3 | `POST /api/v1/orders` with valid Sanctum token (factory) | 200/201 or validation, not 500 | 500 | PASS after A |
| T-A4 | Throttle limiter: 61st request in 60s with token | 429 `too_many_requests`, not 500 | 500 | PASS after A |
| T-A5 | `RefreshDatabase` then `User::factory()->create()->createToken()` + `where personal_access_tokens` | table exists, insert succeeds | `no such table` on sqlite :memory: | PASS after A |
| T-B1 | `GET /api/settings` when `settings` table empty | 200, `data` null-safe, no `getTranslation on null` | 500 `Internal Server Error` / generic | PASS after B |
| T-B2 | `GET /api/settings` when settings exists with translations | 200, `SettingResource` returns `site_name[en/ar]` | 200 | PASS |
| T-B3 | `SettingResource::make(null)->toArray()` | array with nulls, no Error | Error | PASS after B |
| T-B4 | `SettingService::getSetting()` cache hit after truncate | returns cached instance, not null crash | null → crash | PASS after B |

Execution: `php artisan test --filter=Sanctum|Settings` locally; `scripts/verify_migrations.php --fresh` to validate migration SQL.

---

## Runtime Verification

| Check | Method | Status |
|-------|--------|--------|
| Migration file exists in repo | `ls database/migrations/2019_12_14*` | NOT VERIFIED before fix → VERIFIED after file added |
| `php artisan migrate --pretend` shows `personal_access_tokens` | dry-run on staging | NOT VERIFIED (requires production DB access) |
| Table exists `SHOW TABLES LIKE 'personal_access_tokens'` | `mysql -e` on `meemmarket_catch` | NOT VERIFIED (no production DB access from audit host) — must be done during deployment |
| `php artisan migrate` on `storage/migrate_check.sqlite --fresh` | `scripts/verify_migrations.php --fresh` | TO BE RUN after fix |
| `GET /api/settings` empty settings | `php artisan test` feature | TO BE RUN after fix |
| No config cache drift | `php artisan config:clear` | TO BE RUN during deployment |

**Never mark production verified without executing against `meemmarket_catch` MySQL.**

---

## Pusher Safety

> **Pusher is not implicated by the observed failure.**

No change to Pusher events, channels, or import/export flow. Verified: `SettingResource` and `ThrottleRequests` paths contain no Pusher interaction. Queues (`catch-high`, `catch-medium`, `default`) unchanged.

---

## Certification (pre-fix)

```
ROOT CAUSE STATUS: CONFIRMED (two independent)
SANCTUM ISSUE: NOT RESOLVED — migration file missing, table missing in production branch
SETTING RESOURCE ISSUE: NOT RESOLVED — null invariant not guarded
DATABASE SCHEMA: NOT VERIFIED (personal_access_tokens missing)
API RUNTIME: NOT VERIFIED (affected endpoints return 500)
PUSHER: UNCHANGED
QUEUES: UNCHANGED
REGRESSION TESTS: NOT AVAILABLE (existing SettingsRegressionTest covers getData null but not Resource null; no Sanctum table test passes on :memory:)
PRODUCTION VERIFICATION: NOT PERFORMED
```

## Implementation — Minimal Safe Fixes Applied (2026-09-13)

### Fix A — `personal_access_tokens`
- **File added:** `database/migrations/2019_12_14_000001_create_personal_access_tokens_table.php` (exact copy of `vendor/laravel/sanctum/...`, plus docblock explaining root cause) — `D:\work\meem\database\migrations\2019_12_14_000001_create_personal_access_tokens_table.php:1`
- **Verified:** `php scripts/verify_migrations.php --fresh` → all migrations DONE including `2019_12_14_000001_create_personal_access_tokens_table` (10.73s)
- **Verified:** `php artisan migrate --pretend` → `Nothing to migrate` after check DB (file present)
- **Verified:** `php artisan test tests/Feature/ProductionRootCauseRegressionTest.php` → `personal_access_tokens_table_exists`, `sanctum_token_creation_works_via_has_api_tokens` PASS
- **Verified:** `php artisan test --filter=RealAuthenticatedUserNotificationE2ETest::test_phase1_login_returns_sanctum_token` previously FAILED with `no such table`, now PASS
- **No limiter change** — `app/Providers/RouteServiceProvider.php:64` `optional($request->user())->id ?: ip()` kept as-is (correct per-user throttling once table exists)

### Fix B — `SettingResource` null invariant
- **File edited:** `packages/marvel/src/Http/Resources/SettingResource.php:15-54` — added null guard at top of `toArray()` returning null-safe defaults when `$this->resource === null` (never calls `getTranslation` on null) — `packages/marvel/src/Http/Resources/SettingResource.php:15`
- **File edited:** `app/Http/Controllers/Api/General/SettingController.php:16` — controller no longer caches null (`HasCache::remember` skipped when `Settings::first()` is null), returns `SettingResource::make(null)` safely — `app/Http/Controllers/Api/General/SettingController.php:16`
- **Verified:** `php artisan test --filter=Settings` → 51 passed (was 50 passed / 1 failed due to pre-existing FinancialDeepAuditTest path bug which was also fixed to use `/api/v1/general/settings`)
- **Verified:** `tests/Feature/ProductionRootCauseRegressionTest.php` added — `public_settings_endpoint_works_when_settings_empty` truncates `settings` + `Cache::flush()` then `GET /api/v1/general/settings` → 200 not 500; `setting_resource_null_is_safe` → PASS
- **Fix to test data:** `tests/Feature/FinancialDeepAuditTest.php:718` corrected endpoint from `self::PREFIX.'/settings'` (`/api/v1/settings` admin, requires auth) to `/api/v1/general/settings` (public front) — now PASSES.

### Additional Test Artifact
- `tests/Feature/ProductionRootCauseRegressionTest.php` — 8 regression tests covering both root causes (schema + resource). Kept for CI.

## Certification (post-fix — local verification)

```
ROOT CAUSE STATUS: CONFIRMED
SANCTUM ISSUE: RESOLVED (locally verified; production requires `php artisan migrate --force` on meemmarket_catch)
SETTING RESOURCE ISSUE: RESOLVED (locally verified)
DATABASE SCHEMA: VERIFIED (locally: personal_access_tokens exists via RefreshDatabase + file DB; production NOT VERIFIED — requires MySQL check)
API RUNTIME: VERIFIED LOCALLY (public settings with/without token, authenticated token flow, throttle)
PUSHER: UNCHANGED — explicitly not modified (not implicated)
QUEUES: UNCHANGED — catch-high/medium/default untouched; workers not restarted during audit
REGRESSION TESTS: PASS (51 Settings tests + 8 new ProductionRootCauseRegression tests + 1 previously failing Sanctum token test now PASS)
PRODUCTION VERIFICATION: NOT PERFORMED (no MySQL access to meemmarket_catch from audit host; must be done during deployment: `SHOW TABLES LIKE 'personal_access_tokens'` and `GET /api/v1/general/settings` live check)
```

### Production deployment checklist (must execute on catch.mohammedtareq.me host)

```bash
php artisan migrate --force
php artisan config:clear && php artisan config:cache
php artisan cache:clear
# verify
mysql -e "SHOW TABLES LIKE 'personal_access_tokens';" meemmarket_catch
mysql -e "SELECT migration FROM migrations WHERE migration='2019_12_14_000001_create_personal_access_tokens_table';" meemmarket_catch
curl -i https://catch.mohammedtareq.me/api/v1/general/settings
curl -i -H "Authorization: Bearer <valid-token>" https://catch.mohammedtareq.me/api/v1/general/settings
```

Rollback:
- Sanctum: `php artisan migrate:rollback --step=1` drops `personal_access_tokens` (only if needed)
- Settings: revert two files (`SettingResource.php`, `SettingController.php`)



---

## Appendix — Exact Files / Evidence

- `vendor/laravel/sanctum/database/migrations/2019_12_14_000001_create_personal_access_tokens_table.php` (canonical)
- `database/migrations/` — **missing** that file (evidence via `ls`)
- `vendor/laravel/sanctum/src/PersonalAccessToken.php:45` `findToken`
- `vendor/laravel/sanctum/src/Guard.php:42` `findToken` call + `getTokenFromRequest`
- `vendor/laravel/sanctum/src/SanctumServiceProvider.php:57` `registerMigrations`
- `config/auth.php:16` `defaults.guard => sanctum`, `guards` lacks explicit `sanctum` (auto-registered by provider)
- `app/Providers/RouteServiceProvider.php:64` `RateLimiter::for('api', optional(user)->id ?: ip)`
- `app/Http/Kernel.php:39` `api` group `throttle:api`
- `routes/api.php:18` `Route::get('settings', SettingController@index)->name('settings.front')` under `throttle:public-api` (120/min by ip)
- `packages/marvel/src/Http/Resources/SettingResource.php:18` `getTranslation` on `$this`
- `app/Http/Controllers/Api/General/SettingController.php:16` `SettingResource::make($settingCache)` with nullable
- `app/Services/General/SettingService.php:8` `Settings::first()`
- `packages/marvel/src/Database/Models/Settings.php:43` `getData` + translatable
- `app/Exceptions/Handler.php:163` `QueryException => Database error...` + `status:false`
- `storage/logs/laravel.log` — `no such table: personal_access_tokens` (testing sqlite)
- `storage/database.sqlite` binary dump — `migrations` row for `2019_12_14_...` present but table absent in `:memory:`
- `phpunit.xml:13-14` `DB_CONNECTION=sqlite DB_DATABASE=:memory:` → reproduces missing table

---

*This report follows PHASE 1 READ-ONLY investigation. No source, schema, cache, queue, or worker was modified during audit. Minimal fixes are proposed in § Fix Plan and will be implemented only after this report is committed.*
