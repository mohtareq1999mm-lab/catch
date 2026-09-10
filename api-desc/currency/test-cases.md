# Test Cases - Currency Feature

## Current Coverage

Dedicated feature tests live in `tests/Feature/Currency/`:

| File | Covers |
|------|--------|
| `CurrencyAdminApiTest.php` | CRUD + set-base + filters (search/code/is_active/sort_order) for currencies (admin auth/permission) |
| `CurrencyRateTest.php` | CRUD + upsert + filters (date_from/date_to/code) for exchange rates |
| `CurrencyConversionTest.php` | Conversion logic, historical/latest rate lookup |
| `OrderCurrencyTest.php` | Order snapshot persistence + OrderResource |
| `ProductCurrencyTest.php` | Product price conversion + `discount_amount` conversion for fixed-rate discounts |
| `PaymentCurrencyTest.php` | MyFatoorah invoice/refund currency, reconciliation `compareCurrency`, invoice snapshot currency sourcing |
| `BaseCurrencyTest.php` | Set-base success/guard/error/cache paths |
| `CatalogCurrencyTest.php` | Set-catalog success/guard/error/cache/permission paths |
| `CurrencyBugRegressionTest.php` | Regression coverage for BUG-001..004 |
| `CurrencySelectionEnabledTest.php` | `currency_selection_enabled` feature (disabled → catalog; enabled → preference > cookie > catalog)

## Recommended Tests

| # | Test | Type | Priority |
|---|------|------|----------|
| FT-001 | Create currency stores uppercased code | Feature | High |
| FT-002 | Create currency rejects duplicate code case-insensitively | Validation | High |
| FT-003 | Create currency rejects code not exactly 3 letters | Validation | High |
| FT-004 | Create currency rejects invalid decimal_places | Validation | Medium |
| FT-005 | Create currency persists translatable fields ({en, ar}) | Feature | High |
| FT-006 | Update currency persists changed metadata | Feature | High |
| FT-007 | Delete currency with no rates soft-deletes (deleted_at set) | Feature | High |
| FT-008 | Delete base currency returns 409 `CANNOT_DELETE_BASE_CURRENCY` | Guard | High |
| FT-009 | Delete currency referenced by rates returns 409 `CANNOT_DELETE_CURRENCY_IN_USE` | Guard | High |
| FT-010 | Set-base success updates settings options + flushes caches | Feature | High |
| FT-011 | Set-base inactive currency → 422 `CURRENCY_INACTIVE` | Validation | High |
| FT-012 | Set-base currency without rate ≤ today → 422 `EXCHANGE_RATE_NOT_FOUND` | Validation | High |
| FT-013 | Show currency resolves withTrashed (soft-deleted still viewable) | Edge | Medium |
| FT-014 | Show/delete non-numeric ID returns 404 (not 500) | Regression | High |
| FT-015 | List respects limit (default 15, max 100) | Pagination | Medium |
| FT-016 | Rate list filters by currency_id | Filter | Medium |
| FT-017 | Rate list filters by effective_date | Filter | Medium |
| FT-018 | Rate create/update upserts on (currency_id, effective_date) | Feature | High |
| FT-019 | Rate create rejects exchange_rate ≤ 0 | Validation | High |
| FT-020 | Rate create rejects non-existent currency_id | Validation | Medium |
| FT-021 | Rate delete hard-deletes | Feature | High |
| FT-022 | Same-currency conversion is identity with no rate query | Feature | High |
| FT-023 | Conversion uses latest rate with effective_date ≤ date | Feature | High |
| FT-024 | Conversion throws `CurrencyRateNotFoundException` when no rate | Failure | High |
| FT-025 | Conversion returns scale-6 ratio and round-2 converted total | Precision | High |
| FT-026 | Order snapshot stores all currency columns | Feature | High |
| FT-027 | OrderResource exposes currency/base_currency/catalog_currency/exchange_rate/converted_total | Structure | High |
| FT-028 | Update order after base change refreshes snapshot | Feature | Medium |
| FT-029 | Public list returns active currencies only, no auth | Feature | High |
| FT-030 | Public list is cached under `currencies` tag and invalidated on writes | Cache | High |
| FT-031 | Unauthenticated access to admin endpoints → 401 | Auth | High |
| FT-032 | Missing permission → 403 | Auth | High |
| FT-033 | Set-catalog success updates only catalog_currency_code option | Feature | High |
| FT-034 | Set-catalog inactive currency → 422 | Validation | High |
| FT-035 | Set-catalog currency without rate → 422 | Validation | High |
| FT-036 | Set-catalog requires permission → 403 | Auth | High |
| FT-037 | Currency list filters: code / search / is_active / sort_order | Filter | Medium |
| FT-038 | Rate list filters: date_from / date_to / code | Filter | Medium |
| FT-039 | Product `discount_amount` converted for fixed-rate, not percentage | Feature | High |
| FT-040 | MyFatoorah invoice/refund use order base currency | Feature | High |
| FT-041 | Reconciliation compares currency against order base with config fallback | Feature | High |
| FT-042 | Public `POST /currencies/select` persists user preference (authenticated) | Feature | High |
| FT-043 | Public `POST /currencies/select` sets guest cookie (unauthenticated) | Feature | High |
| FT-044 | Public `POST /currencies/select` validates currency_code (missing / inactive / length) | Validation | High |
| FT-045 | `select` returns 200 `CURRENCY_SELECTED_SUCCESSFULLY` with CurrencyResource | Structure | High |
| FT-046 | Effective currency = catalog when `currency_selection_enabled` is false (preference ignored) | Feature | High |
| FT-047 | Effective currency = user preference when selection enabled | Feature | High |
| FT-048 | Effective currency = guest cookie when selection enabled and no user | Feature | Medium |
| FT-049 | `PUT /api/v1/settings` with `currency_selection_enabled` merges into options without dropping others | Feature | High |
| FT-050 | `SettingResource` exposes top-level `currency_selection_enabled` bool (admin + public) | Structure | High |

> Note: `CurrencySelectionEnabledTest` (17 tests) already covers the `currency_selection_enabled` feature (enabled → preference > cookie > catalog; disabled → catalog).

## Missing / Weak Areas

- **Payment/checkout suites not fully green** — `PaymentCallbackStressTest` (9 fail, 401 on callback GET), `PaymentProductionHardenTest` (14 fail + 1 risky), `CartApiTest` (5 fail) and `CheckoutApiTest` (1 fail, `no such table: currencies` on GET /orders) all fail **identically on baseline** (verified via `git stash`) — pre-existing, unrelated to the currency refactor, but untriaged.
- **Combined test-file runs are unreliable** on this setup — `php artisan test file1 file2` executes only the first file; every suite must be run individually.
- **Rate string precision** assertions are DB-dependent (SQLite returns `'1'`/`'0.221'` vs MySQL `'1.0000000000'`/`'0.2210000000'`); tests normalize to SQLite-native values.
