# Currency Feature - API Investigation

## Feature Name

Multi-Currency Management (Admin CRUD, Exchange Rates, Conversion Engine, Order/Product Price Snapshots)

## Description

The Currency module introduces full multi-currency support to the e-commerce platform:

- **Admin API** (`/api/v1/currencies`, `/api/v1/currency-rates`) — full CRUD for currencies and exchange rates, plus setting a base **or catalog** currency. Permission-gated.
- **Public API** (`/api/v1/general/currencies`) — read-only list of active currencies for storefronts, cached; `POST /currencies/select` persists a user/guest currency preference.
- **Conversion engine** — bcmath-based, database-backed rate resolution with historical lookups.
- **Price snapshots** — orders record the currency snapshot at creation time (base currency + catalog code); products, carts and orders expose catalog-to-base converted prices.
- **Payment sourcing** — gateways, QR payloads, transactions, invoice snapshots and reconciliation quote the order's base currency.

Currencies are fully translatable (name, symbol, country_name in en/ar), support soft deletes, a base currency + catalog currency concept stored in settings, and rate history keyed by `(currency, effective_date)`.

## Architecture

```
[Admin Client]                              [Storefront]
    |                                           |
    |--- GET/POST/PUT/DELETE /api/v1/currencies         |
    |--- POST /api/v1/currencies/{id}/set-base          |--- GET /api/v1/general/currencies
    |--- POST /api/v1/currencies/{id}/set-catalog       |--- POST /api/v1/general/currencies/select
    |--- PATCH /api/v1/currencies/{id}/rate-mode        |
    |--- GET/POST/PUT/DELETE /api/v1/currency-rates     |
    v                                           v
[Marvel CurrencyController / CurrencyRateController]   [App\CurrencyController (public)]
    |                                           |
    v                                           v
[CurrencyService (singleton)]                  [CurrencyResource] + HasCache (tag: currencies)
    |--- store/update/delete currency
    |--- setBaseCurrency / setCatalogCurrency / setRateMode
    |--- convert / convertPrice  -> [CurrencyConversionService]
    |--- invalidatePriceCaches (tag flush + HomeService::clearCache + rateCache)
    v
[CurrencyRateService] -> upsert / list rates (currency_id, effective_date, date_from, date_to, code) [source=manual/provider]
    v
[Models: Currency(rate_mode, manual_rate, provider_rate, provider, last_synced_at...), CurrencyRate(source,provider)]
    v
[ExchangeRateProviderInterface -> FrankfurterProvider -> ExchangeRateSnapshot] (Frankfurter https://api.frankfurter.dev/v2/rates?base=USD, sync only, never customer path)
    v
[ExchangeRateSyncService -> everySixHours UTC withoutOverlapping onOneServer] -> currency:sync-rates [--dry-run] (provider frankfurter)
    v
[OrderCreationService]  -> resolveCurrencySnapshot  (order price snapshot)
[ConvertsProductPrice]  -> convertPrice              (product price conversion)
[CartResource/CartItemResource] -> convertPrice      (cart conversion + currency field)
[Payments/Invoices/Reconciliation] -> order base currency sourcing
```

## Key Endpoints

### Admin (prefix `/api/v1`, auth:sanctum + throttle:admin)

| Method | URI | Controller Method | Permission | Notes |
|--------|-----|-------------------|------------|-------|
| GET | `/currencies` | `index` | `view-currencies` | Paginated (limit 1–100) + search/code/is_active/sort_order filters |
| POST | `/currencies` | `store` | `create-currency` | Validates ISO 3-letter code |
| GET | `/currencies/{currency}` | `show` | `view-currencies` | `{currency}` must be numeric |
| PUT | `/currencies/{currency}` | `update` | `update-currency` | |
| DELETE | `/currencies/{currency}` | `destroy` | `delete-currency` | Soft delete w/ guards |
| POST | `/currencies/{id}/set-base` | `setBase` | `set-base-currency` | `{id}` must be numeric |
| POST | `/currencies/{id}/set-catalog` | `setCatalog` | `set-catalog-currency` | `{id}` must be numeric |
| PATCH | `/currencies/{id}/rate-mode` | `setRateMode` | `update-currency` | `{mode:auto|manual, manual_rate when manual}` |
| GET | `/currency-rates` | `index` | `view-exchange-rates` | Filter currency_id / effective_date / date_from / date_to / code |
| POST | `/currency-rates` | `store` | `create-exchange-rate` | Upsert per (currency, date), sets source=manual, today's → MANUAL |
| GET | `/currency-rates/{currency_rate}` | `show` | `view-exchange-rates` | |
| PUT | `/currency-rates/{currency_rate}` | `update` | `update-exchange-rate` | Sets source=manual |
| DELETE | `/currency-rates/{currency_rate}` | `destroy` | `delete-exchange-rate` | 409 if current effective rate |

### Public (prefix `/api/v1/general`, throttle:public-api, NO auth)

| Method | URI | Controller Method | Permission | Notes |
|--------|-----|-------------------|------------|-------|
| GET | `/currencies` | `index` | — | Active currencies only, tag-cached 4h |
| POST | `/currencies/select` | `select` | — | Persist user/guest currency preference + `X-Currency` header; gated by `currency_selection_enabled` |

## Key Files

| Layer | Path |
|-------|------|
| Admin Currency Controller | `packages/marvel/src/Http/Controllers/CurrencyController.php` |
| Admin Rate Controller | `packages/marvel/src/Http/Controllers/CurrencyRateController.php` |
| Public Controller | `app/Http/Controllers/Api/Currency/CurrencyController.php` |
| Service (singleton) | `app/Services/Currency/CurrencyService.php` |
| Conversion Service | `app/Services/Currency/CurrencyConversionService.php` |
| Rate Service | `app/Services/Currency/CurrencyRateService.php` |
| Preference Service | `app/Services/Currency/UserCurrencyPreferenceService.php` |
| Select Currency Request | `app/Http/Requests/SelectCurrencyRequest.php` |
| Model | `app/Models/Currency.php` |
| Model | `app/Models/CurrencyRate.php` |
| Resource | `app/Http/Resources/Currency/CurrencyResource.php` |
| Resource | `app/Http/Resources/Currency/CurrencyRateResource.php` |
| Store Request | `app/Http/Requests/Currency/StoreCurrencyRequest.php` |
| Update Request | `app/Http/Requests/Currency/UpdateCurrencyRequest.php` |
| Store Rate Request | `app/Http/Requests/Currency/StoreCurrencyRateRequest.php` |
| Update Rate Request | `app/Http/Requests/Currency/UpdateCurrencyRateRequest.php` |
| DTO | `app/DTOs/CurrencyConversionResult.php` |
| Exception | `app/Exceptions/CurrencyInUseException.php` |
| Exception | `app/Exceptions/CurrencyInactiveException.php` |
| Exception | `app/Exceptions/CurrencyRateNotFoundException.php` |
| Enum | `app/Enums/FrontendResource.php` (case `CURRENCIES`) |
| Enum | `packages/marvel/src/Enums/Permission.php` (lines 92–99) |
| Constants | `packages/marvel/config/constants.php` (lines 525–566) |
| Admin Routes | `packages/marvel/src/Rest/Routes.php` (lines 186–191) |
| Public Routes | `routes/api.php` (lines 100-101) |
| Migration | `database/migrations/2026_08_10_000002_create_currencies_table.php` |
| Migration | `database/migrations/2026_08_10_000003_create_currency_rates_table.php` |
| Migration | `database/migrations/2026_08_10_000004_add_currency_columns_to_orders_table.php` |
| Migration | `database/migrations/2026_08_11_000001_add_catalog_currency_code_to_orders_table.php` |
| Seeder | `database/seeders/CurrencySeeder.php` |
| Order snapshot service | `app/Services/Checkout/OrderCreationService.php` |
| Order snapshot resource | `app/Http/Resources/Order/OrderResource.php` |
| Cart conversion | `packages/marvel/src/Http/Resources/CartResource.php` / `CartItemResource.php` |
| Product price trait | `app/Http/Resources/Product/ConvertsProductPrice.php` |
| Payment sourcing | `app/Services/Payment/PaymentCheckoutHandler.php`, `app/Services/Gateway/MyFatoorahGateway.php`, `app/Services/Gateway/CashierQrService.php`, `app/Services/Invoice/InvoiceService.php`, `app/Services/Invoice/InvoiceSnapshotService.php`, `app/Jobs/PaymentReconciliationJob.php` |
| Registration | `app/Providers/AppServiceProvider.php` (line 26) |
| Tests | `tests/Feature/Currency/*` (incl. `CatalogCurrencyTest`, `PaymentCurrencyTest`) |
| Test base | `tests/Feature/Currency/CurrencyTestCase.php` |
| Response changes | `api-desc/currency/change-response.md` |

## Tech Stack

- **Laravel** + Eloquent ORM
- **Sanctum** authentication + **Spatie permissions**
- **bcmath** arbitrary-precision arithmetic (scale 6)
- **Spatie Translatable** (`HasTranslations`) — name/symbol/country_name JSON
- **SoftDeletes** — currencies only (rates are hard-deleted via FK cascade)
- **Cache tags** — public list cached 4h under `currencies` tag; invalidated on any write
- **API Resources** — `CurrencyResource`, `CurrencyRateResource`
