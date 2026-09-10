# Changelog - Currency Feature

## [1.1.0] — 2026-08-12

### Added
- Public `POST /api/v1/general/currencies/select` endpoint (`SelectCurrencyRequest`) that stores a user currency preference (`user_preferences`) and a `guest_currency` cookie, then returns `CURRENCY_SELECTED_SUCCESSFULLY` with a `CurrencyResource`.
- `UserCurrencyPreferenceService` — read/write/validate user preference + guest cookie.
- `CurrencyService::getEffectiveCode()` / `getEffectiveCurrency()` / `isCurrencySelectionEnabled()` / `forgetEffectiveCode()`.

### Changed
- `CurrencyService::getEffectiveCode()` — when the setting `currency_selection_enabled` is `false` (default) the effective currency now always resolves to the **catalog code**, ignoring any stored user preference or guest cookie. When `true`, it resolves `user preference > guest cookie > catalog code`.

## [Unreleased]

### Added
- `currencies` table (translatable, soft-deletes, unique code) + `currency_rates` table (unique per currency/day, indexed date).
- Admin currency CRUD endpoints (`GET/POST/PUT/DELETE /api/v1/currencies`) with permission gating.
- Admin exchange-rate CRUD endpoints (`/api/v1/currency-rates`) with upsert-on-same-day behavior.
- Set-base-currency endpoint `POST /api/v1/currencies/{id}/set-base`.
- Set-catalog-currency endpoint `POST /api/v1/currencies/{id}/set-catalog` + `set-catalog-currency` permission.
- Public read-only endpoint `GET /api/v1/general/currencies` (active only, tag-cached 4h).
- `CurrencyService` (singleton), `CurrencyConversionService` (bcmath scale 6), `CurrencyRateService`.
- `CurrencyConversionResult` DTO + `CurrencyRateNotFoundException`, `CurrencyInactiveException`, `CurrencyInUseException`.
- Currency snapshot columns on `orders` (currency_code, base_currency_code, catalog_currency_code, currency_rate, currency_rate_date, converted_total_price) with migration backfill.
- Order snapshot logic in `OrderCreationService`; currency fields exposed in `OrderResource`.
- Product price conversion trait `ConvertsProductPrice` (catalog → base); `discount_amount` converted only for fixed-rate discounts.
- Cart responses (`CartResource`/`CartItemResource`) convert item prices, subtotals, coupon discounts and totals to base currency and expose `currency`.
- Payment currency sourcing — MyFatoorah gateway, cashier QR, payment transactions, invoice snapshots and reconciliation job prefer the order's base currency with `config('payment.default_currency')` fallback.
- List filters: `/currencies` supports `search`, `code`, `is_active`, `sort_order`; `/currency-rates` supports `date_from`, `date_to`, `code`.
- `CurrencyResource` / `CurrencyRateResource` API resources.
- 4 form requests with validation (incl. unique translation for translatable fields).
- Spatie permissions (`view-currencies` ... `set-base-currency`, `set-catalog-currency`).
- `CurrencySeeder` (6 currencies with today's rates).
- Translation keys (en/ar) for all currency messages/errors incl. `SET_CATALOG_CURRENCY_SUCCESSFULLY`.
- Full feature test suite under `tests/Feature/Currency` (incl. `CatalogCurrencyTest`).

### Changed
- `AppServiceProvider` registers `CurrencyService` as singleton.
- `FrontendResource` enum gains `CURRENCIES` case.
- Constants file defines currency message/error keys.

### Fixed
- Route numeric constraints (`whereNumber('currency')`, `whereNumber('currency_rate')`) so non-numeric IDs return 404 instead of a 500 TypeError.
- Delete guard ordering: base-currency check runs before the rates-exists check (base currency without rates is still protected).
- SQLite-safe date comparisons via `whereDate()` in rate resolution and base-set validation.

### Known Issues
- Rate resolution returns DB-native string precision (`'1'`, `'0.221'`) on SQLite vs padded `'1.0000000000'` on MySQL — string-format assertions are DB-dependent.
