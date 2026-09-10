# Frontend - Currency Feature

## Status

Admin SPA + storefront consume these endpoints for currency/rate management and price display.

## Consumption

```javascript
// Admin
export const currencyApi = {
  list(params)        // GET /api/v1/currencies?limit=&page=&search=&code=&is_active=&sort_order=
  show(id)            // GET /api/v1/currencies/{id}
  create(payload)     // POST /api/v1/currencies
  update(id, payload) // PUT /api/v1/currencies/{id}
  remove(id)          // DELETE /api/v1/currencies/{id}
  setBase(id)         // POST /api/v1/currencies/{id}/set-base
  setCatalog(id)      // POST /api/v1/currencies/{id}/set-catalog
  setRateMode(id, {mode, manual_rate}) // PATCH /api/v1/currencies/{id}/rate-mode (update-currency)
}

export const currencyRateApi = {
  list(params)            // GET /api/v1/currency-rates?currency_id=&effective_date=&date_from=&date_to=&code=&limit=
  show(id)                // GET /api/v1/currency-rates/{id}
  create(payload)         // POST /api/v1/currency-rates (source=manual, today's→MANUAL)
  update(id, exchange_rate) // PUT /api/v1/currency-rates/{id} (source=manual)
  remove(id)              // DELETE /api/v1/currency-rates/{id} (409 if current effective)
}

// Storefront
export const publicCurrencyApi = {
  list()                          // GET /api/v1/general/currencies
  select(currency_code)           // POST /api/v1/general/currencies/select
}
```

## Expected Frontend Components

```
CurrenciesTable.vue      → index    (table, search, pagination, code/is_active/sort_order filters)
CurrencyFormDialog.vue   → create/update (translatable name/symbol/country_name)
CurrencyDetailDrawer.vue → show    (base/catalog flags)
SetBaseCurrencyDialog.vue → setBase (confirmation)
SetCatalogCurrencyDialog.vue → setCatalog (confirmation)
ExchangeRatesTable.vue   → index    (filter by currency, date range, code)
ExchangeRateFormDialog.vue → create/update
```

## UI Mapping

| UI Concept | API Field / Endpoint |
|------------|---------------------|
| Currency code input | `code` (3 letters, uppercased) |
| Name / Symbol / Country | `name`, `symbol`, `country_name` (locale objects `{en, ar}`) |
| Decimals selector | `decimal_places` (0–4) |
| Active toggle | `is_active` |
| Base currency badge | `is_base` in `CurrencyResource` |
| Catalog currency badge | `is_catalog` in `CurrencyResource` |
| Exchange rate form | `currency_id`, `exchange_rate`, `effective_date` |
| Base currency switch | `POST /currencies/{id}/set-base` (shows 422 error for inactive/no-rate) |
| Catalog currency switch | `POST /currencies/{id}/set-catalog` (shows 422 error for inactive/no-rate) |
| Rate mode switch | `PATCH /currencies/{id}/rate-mode {mode:auto|manual, manual_rate when manual}` → 200 (audit `rateModeChanged`), 422 if AUTO without fresh provider_rate |
| Currency delete | 409 with `CANNOT_DELETE_BASE_CURRENCY` or `CANNOT_DELETE_CURRENCY_IN_USE` |
| Exchange rate delete | 409 if current effective rate, else 200 |
| Currency selector (storefront) | `GET /api/v1/general/currencies` + `POST /api/v1/general/currencies/select` (now `X-Currency` header, not cookie) |
| Effective rate display | `currency.effective_rate` (authoritative), `rate_mode` decides `manual_rate` vs `provider_rate`; frontend MUST NOT branch |
| Stale indicator | `is_rate_stale` (true if `last_synced_at` null or >12h) + `last_synced_at/provider_rate_at` |

## Notes

- `code` is stored uppercase; server uppercases it during validation.
- `is_base` / `is_catalog` are computed against current settings, not stored flags.
- `is_rate_stale` is computed (`last_synced_at` null or >12h); admin should show warning.
- Storefront currency selector uses the cached `GET /api/v1/general/currencies` list (active only, now includes `effective_rate`).
- Storefront selection should call `POST /api/v1/general/currencies/select { currency_code }` to persist the choice (user preference + `X-Currency` header, cookie deprecated, `CORS_ALLOWED_HEADERS` includes `X-Currency`). The persisted selection only takes effect when the admin setting `currency_selection_enabled` is `true`; otherwise the effective currency stays the catalog code.
- The currency selector's effective-currency read should be taken from the settings flag: when `currency_selection_enabled` is `false`, hide/disable the selector (the selection is ignored).
- Provider sync runs `everySixHours UTC withoutOverlapping(60) onOneServer when(currency.enabled)` via `currency:sync-rates`; frontend never calls `ExchangeRate-API` directly. `effective_rate` is authoritative.
