# Currency Frontend Integration — Frankfurter

## What does frontend need to do?
Almost nothing about FX. Call backend currency APIs, use `effective_rate` as authoritative, never call `https://api.frankfurter.dev` directly, never compute rates.

## What endpoint should frontend call?
- Public: `GET /api/v1/general/currencies` (list) and `POST /api/v1/general/currencies/select` (`{currency_code: "EGP"}`) with `X-Currency: EGP` header for guest
- Admin: `GET /api/v1/currencies`, `GET /api/v1/currency-rates`, `PATCH /api/v1/currencies/{id}/rate-mode`
- No sync endpoint. Sync is CLI/scheduler owned (`currency:sync-rates`).

## What is effective_rate?
`Currency.effective_rate` from `packages/marvel/src/Http/Resources/Currency/CurrencyResource.php` (Marvel admin) and `app/Http/Resources/Currency/CurrencyResource.php` (public). For `MANUAL` it's `manual_rate`; for `AUTO` it's `provider_rate` from Frankfurter (`provider=frankfurter`). String `decimal(20,10)`. This is what product/cart/checkout use after server conversion. Do not compute it.

## Should frontend use manual_rate?
Only display in admin UI as “Administrator override”. Never as active price.

## Should frontend use provider_rate?
Only display as “Frankfurter suggested” in admin (`provider=frankfurter`). Never as active price when `rate_mode=MANUAL`.

## Does frontend call Frankfurter?
Never. Architecture: `Frankfurter → Backend sync (FrankfurterProvider) → DB → Backend API → Frontend`.

## Does frontend store the rate?
No. Backend `CurrencyService::convertPrice()` and `CurrencyConversionService` resolve rates per request from DB/cache. Frontend may cache API response but must not persist or author rates.

## Does frontend calculate the exchange rate?
No. Do not implement `if AUTO use provider_rate else manual_rate` or `target/source` division. Backend owns it. Display returned `price/current_price` and `currency` object.

## What happens when admin changes MANUAL → AUTO?
Backend `PATCH rate-mode {mode:auto}` auto-fetches Frankfurter if no fresh `provider_rate` (`provider_rate_at` ≤72h), validates, persists `provider_rate/provider/provider_rate_at/last_synced_at`, locks, sets `rate_mode=auto, manual_rate=null`, upserts today `currency_rates source=provider`, clears `HomeService` + product + currency caches, emits `LogActivityJob`. Next product/cart responses reflect Frankfurter rate. If Frankfurter fails/anomalous → `422`.

## What happens to currently logged-in users?
Their `CurrencyService::getEffectiveCode()` may return cached code; `forgetEffectiveCode()` is called on every rate/mode change, and product cache keys include `Currency::getEffectiveCode()` (e.g. `BrandController`, `ProductController currencyAwareCacheKey`). Next request recomputes with new effective rate. No reload required, but UI should refetch currency list.

## What happens to guest users?
Same: `X-Currency` header resolved via `UserCurrencyPreferenceService::getHeaderCurrencyCode()` with `CurrencyService::isCurrencySelectionEnabled()` gating. Rate change invalidates the same caches; guest sees new price on next fetch. No `guest_currency` cookie.

## What happens to checkout?
Checkout calls `OrderCreationService::resolveCurrencySnapshot()` which captures `currency_rate, currency_rate_date, converted_total_price` at order creation. Checkout total is converted at that instant via `effective_rate`. Rate changes do not retroactively change the order.

## What happens to old orders?
Immutable. `orders`, `order_products`, `transactions`, `invoices` snapshots never updated by Frankfurter sync. New orders use new effective rate.

## When should frontend refresh currency data/cache?
After any admin `PATCH rate-mode` or `POST/PUT currency-rates` (today) success, refetch `GET /api/v1/currencies` and `GET /api/v1/general/currencies`. No polling needed; backend invalidated tagged caches already.

## What happens if Frankfurter is down?
Nothing visible. Backend keeps last-known-good `exchange_rate`, `last_synced_at` ages, `is_rate_stale` becomes true after 12h. Frontend continues showing `effective_rate`. Admin may see `provider_rate` stale warning. No frontend fallback logic. `currency.sync.failed` logged, next `everySixHours` retries.

## Permissions & Headers
- `PATCH rate-mode` requires `update-currency` (401 unauth, 403 customer, 422 invalid)
- Guest transport: `X-Currency: EGP` (validated `^[A-Z]{3}$` and `is_active`), `CORS_ALLOWED_HEADERS` includes `X-Currency`
- Public list `GET /general/currencies` is `throttle:public-api`, no auth
