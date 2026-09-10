# Bug Report - Currency Feature

## Issue 1 (HIGH): Non-Numeric Route IDs Return 500

- **File:** `packages/marvel/src/Rest/Routes.php` (route definitions)
- **Description:** `{currency}` / `{currency_rate}` params were previously unconstrained; `whereNumber` was missing, so non-numeric IDs reached controllers and threw.
- **Fix:** Constrain both params with `whereNumber('currency')` / `whereNumber('currency_rate')` so non-numeric IDs return 404.
- **Status:** Fixed. Regression: `CurrencyBugRegressionTest`.

## Issue 2 (HIGH): Base Currency Without Rates Could Be Deleted

- **File:** `app/Services/Currency/CurrencyService.php` (`deleteCurrency`)
- **Description:** Guard order was wrong — the base-currency check ran after the rates check, so a base currency with no rates could be deleted.
- **Fix:** Check base currency first, then rates; each returns a distinct 409 message (`CANNOT_DELETE_BASE_CURRENCY` vs `CANNOT_DELETE_CURRENCY_IN_USE`).
- **Status:** Fixed. Regression: `CurrencyBugRegressionTest`.

## Issue 3 (MEDIUM): Date Comparisons Fail on SQLite

- **File:** `app/Services/Currency/CurrencyRateService.php` (rate resolution)
- **Description:** Raw `<=` string comparison on date columns broke under SQLite (dates stored as `'2026-08-10 00:00:00'`), so `effective_date <= today` matched nothing.
- **Fix:** Use `whereDate('effective_date', '<=', $date)` for DB-portable comparison.
- **Status:** Fixed. Regression: `CurrencyBugRegressionTest`.

## Issue 4 (LOW): Rate String Precision Differs Between SQLite and MySQL

- **File:** `app/Services/Currency/CurrencyConversionService.php`, `app/Services/Currency/CurrencyService.php` (`resolveRate`)
- **Description:** `decimal(20,10)` columns return SQLite-native strings (`'1'`, `'0.221'`) while MySQL returns padded values (`'1.0000000000'`, `'0.2210000000'`). Tests asserting exact strings become DB-dependent.
- **Impact:** Conversion math itself is fine (bcmath); only string assertions differ.
- **Options:** Normalize with `bcmul((string) $rate, '1', 10)` in `resolveRate()`, or relax test expectations to SQLite-native values.
- **Status:** Open (tests normalize to SQLite-native values).

## Issue 5 (TEST-DATA): Order Snapshot Tests Fail on Missing NOT NULL Fields

- **File:** `tests/Feature/Currency/OrderCurrencyTest.php`, `tests/Feature/Currency/CurrencyBugRegressionTest.php`
- **Description:** `OrderCreationService` leaves `user_phone` / `user_email` / `address` / `name` null when not provided, but the `orders` migration marks them NOT NULL → `QueryException: NOT NULL constraint failed: orders.user_phone`.
- **Fix:** Supply `user_phone`, `user_email`, `address` (and `name`) in order test payloads.
- **Status:** **FIXED** — payloads now include `user_phone`/`user_email`/`address`.

## Issue 6 (TEST-DATA): Missing Historical USD Rate Breaks Conversion Tests

- **File:** `tests/Feature/Currency/CurrencyConversionTest.php`
- **Description:** Seeding only creates today's KWD rate; historical/latest-lookup tests query USD on a past date and get `CurrencyRateNotFoundException`.
- **Fix:** Seed a past-day USD rate before asserting historical/latest behavior.
- **Status:** **FIXED** — past-day USD base rate seeded.

## Issue 7 (TEST): Rate Upsert Count Assertions Assume No Prior Rate

- **File:** `tests/Feature/Currency/CurrencyRateTest.php`
- **Description:** Upsert tests count rates assuming KWD has no rate for today, but seeding already creates one.
- **Fix:** Expect the seeded count (3) since `CurrencyRateService::store()` upserts on (currency_id, effective_date).
- **Status:** **FIXED** — count expectations aligned to upsert semantics.

## Issue 8 (TEST): JsonResponse::assertSame Does Not Exist

- **File:** `tests/Feature/Currency/CurrencyAdminApiTest.php:38`
- **Description:** `$response->assertSame(3, ...)` called a nonexistent method on `Illuminate\Testing\TestResponse` (JsonResponse wrapper).
- **Fix:** Use `$this->assertSame(3, ...)`.
- **Status:** **FIXED**.

## Issue 9 (TEST): getRawOriginal() Assertions Are DB-Format Dependent

- **File:** `tests/Feature/Currency/CurrencyBugRegressionTest.php`
- **Description:** Asserting raw `getRawOriginal()` values fails on SQLite (trailing zeros stripped, dates as datetime strings).
- **Fix:** Assert casted values: `currency_code`, `base_currency_code`, `(float) currency_rate`, `currency_rate_date->toDateString()`, `(float) converted_total_price`.
- **Status:** **FIXED**.

## Issue 10 (HIGH): PaymentReconciliationJob compareCurrency Null TypeError

- **File:** `app/Jobs/PaymentReconciliationJob.php:60`
- **Description:** Catalog/base refactor replaced `config('payment.default_currency')` with `base_currency_code ?? currency_code` without a config fallback; legacy orders (no currency columns) passed `null` to `recordMismatch(string $expected)` → `TypeError` on 18 reconciliation tests.
- **Fix:** `base_currency_code ?? currency_code ?? config('payment.default_currency')`.
- **Status:** **FIXED** — `PaymentReconciliationTest` green (26 passed). Regression: `PaymentCurrencyTest::payment_reconciliation_compares_currency_in_the_orders_base_currency`.

## Issue 11 (TEST): Reconciliation Queue Name Stale

- **File:** `tests/Feature/PaymentReconciliationTest.php:672`
- **Description:** Test asserted the job is on the `low` queue, but the whole codebase convention is `meem-medium` (no `low` queue exists).
- **Fix:** Update assertion to `meem-medium`.
- **Status:** **FIXED**.

## Issue 12 (HIGH): SET_CATALOG_CURRENCY_SUCCESSFULLY Undefined

- **File:** `packages/marvel/src/Http/Controllers/CurrencyController.php`, `packages/marvel/config/constants.php`, `resources/lang/{en,ar}/message.php`
- **Description:** `setCatalog()` referenced `SET_CATALOG_CURRENCY_SUCCESSFULLY` but the constant and its translations were never defined → undefined-constant on success responses.
- **Fix:** Define constant + add en/ar translation keys.
- **Status:** **FIXED**. Regression: `CatalogCurrencyTest`.

## Issue 13 (SECURITY): set-catalog Had No Permission Gate

- **File:** `packages/marvel/src/Http/Controllers/CurrencyController.php`, `packages/marvel/src/Enums/Permission.php`, `database/seeders/PermissionSeeder.php`
- **Description:** `POST /currencies/{id}/set-catalog` was reachable by any authenticated admin (no Spatie middleware), unlike `set-base`.
- **Fix:** Add `SET_CATALOG_CURRENCY` permission + middleware + seeder entries (admin + store owner lists).
- **Status:** **FIXED**. Regression: `CatalogCurrencyTest::set_catalog_requires_permission`.

## Issue 14 (DOC): CurrencyDocs Claimed Standard Pagination Envelope

- **File:** `api-desc/currency/api.md`
- **Description:** Admin list docs described Laravel's default `{data, meta, links}` pagination, but `CurrencyController::index()` / `CurrencyRateController::index()` return a **custom flattened envelope** (`data, page, current_page, from, to, last_page, path, per_page, total, next_page_url, prev_page_url, last_page_url, first_page_url`).
- **Status:** Documentation corrected (no code change).

## Issue 15 (DOC): Rate Docs Claimed Nested `currency` in Response

- **File:** `api-desc/currency/api.md`
- **Description:** `CurrencyRateResource` does **not** serialize the `currency` relation (only `id, currency_id, exchange_rate, effective_date, created_at`), but docs implied a nested `currency` object.
- **Status:** Documentation corrected (no code change).

## Issue 16 (DOC): Admin `GET /api/v1/settings` Auth Status

- **File:** `api-desc/currency/*`, `api-desc/setting/*`
- **Description:** Docs described `GET /api/v1/settings` as public. In reality it sits inside the `auth:sanctum` group (`Routes.php:114`); the public endpoint is `GET /api/v1/general/settings`.
- **Status:** Documentation corrected (no code change).
