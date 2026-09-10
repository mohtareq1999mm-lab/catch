# Currency Exchange Rate System — Frankfurter

## 1. Purpose

The currency exchange-rate system provides a single source of truth for all monetary conversion. Catalog prices are stored in `catalog_currency_code`; customer-visible prices, cart totals, checkout totals, and order snapshots are derived via the database-backed effective rate. The provider **Frankfurter (https://frankfurter.dev)** is the **only** synchronization source (`frankfurter`).

## 2. Architecture

```
Provider (Frankfurter https://api.frankfurter.dev)
   ↓ bulk GET /v2/rates?base={anchor} (&quotes=...)
Provider Adapter (FrankfurterProvider implements ExchangeRateProviderInterface)
   ↓ normalized ExchangeRateSnapshot
Validation (complete batch)
   ↓
Sync Service (ExchangeRateSyncService)
   ↓ atomic DB transaction
Database (currencies + currency_rates)
   ↓ effective_rate = (MANUAL? manual_rate : provider_rate)
Pricing (CurrencyService / CurrencyConversionService)
   ↓ BCMath target/source
Product/Cart/Checkout
   ↓ snapshot
Order / Transaction / Invoice (immutable)
```

Customer path: `request → DB/cache → effective_rate`. No provider HTTP.

## 3. Database

### currencies
| column | type | meaning |
|---|---|---|
| `rate_mode` | varchar(10) `manual|auto` default `manual` | who owns effective_rate |
| `manual_rate` | decimal(20,10) null | admin override |
| `provider_rate` | decimal(20,10) null | last accepted Frankfurter rate |
| `provider` | varchar(50) null | `frankfurter` |
| `last_synced_at` | timestamp null | app accepted time |
| `provider_rate_at` | timestamp null | Frankfurter `date` (UTC) |
| `effective_rate_updated_at` | timestamp null | last effective change |

Helpers: `effectiveRate()`, `isRateStale()` (stale if `last_synced_at` >12h or null).

### currency_rates
| column | type | meaning |
|---|---|---|
| `exchange_rate` | decimal(20,10) | effective daily rate |
| `effective_date` | date | business date |
| `source` | varchar(20) `legacy|manual|provider` | row origin |
| `provider` | varchar(50) null | `frankfurter` when provider |
| `unique(currency_id,effective_date)` | constraint | daily history preserved |

Migrations: `2026_09_08_000004` (currencies), `2026_09_08_000005` (currency_rates). Both nullable-compatible.

## 4. Rate Modes

- **MANUAL** `effective_rate = manual_rate`. Sync updates `provider_rate` metadata only, no pricing cache invalidation.
- **AUTO** `effective_rate = provider_rate`, invariant `AUTO => provider_rate != null` and `provider_rate_at` fresh ≤72h. Sync updates today row `source=provider`. `MANUAL→AUTO` auto-fetches Frankfurter if stale/missing.

## 5. Legacy Migration

Existing currencies backfilled to `MANUAL` with `manual_rate = latest exchange_rate`, `effective_rate_updated_at` copied, existing `currency_rates` set `source=legacy`. No `exchange_rate` changed.

## 6. Provider — Frankfurter

- **Website:** https://frankfurter.dev/ — Docs https://frankfurter.dev/docs/
- **Endpoint:** `GET https://api.frankfurter.dev/v2/rates?base={CURRENCY_RATE_ANCHOR}` (default `USD`) with optional `&quotes=KWD,SAR,AED,EGP,EUR,GBP`. Also supports `GET /v1/latest?base={BASE}` but v2 is used for 200+ currencies including SAR/KWD/AED/EGP.
- **Auth:** None — no API key. `FRANKFURTER_BASE_URL` defaults to `https://api.frankfurter.dev`.
- **Timeout:** 10s, connect 3s, retries 2 with 500ms sleep, retry on `429/5xx` or `ConnectionException`.
- **Response:** v2 array `[{base, quote, rate, date}]` or v1 object `{base, date, rates:{CODE:rate}}`. `providerRateAt` is `date` (UTC).
- **Normalization:** If `providerBase==anchor` use directly; else `normalized[target]=providerRate[target]/providerRate[anchor]` (BCMath 10dp, `anchor=1.0000000000`).
- **Validation:** code match, finite numeric `^\d+(\.\d{1,10})?$`, `>0`, `≤1_000_000`, precision ≤10dp, base/anchor check, future/stale `>72h` rejected.
- **Hardening:** `isDecimalString` rejects `INF`, `providerRateAt` future check, excessive precision rejected.

## 7. Synchronization

```
Cache::lock currency-rate-sync (3600) → load active currencies → resolve anchor USD → FrankfurterProvider->getLatestRates(anchor, active codes) → validateSnapshot(anchor, timestamp, all active codes, hard_max, precision) → compareRates() → rejectEstablishedAnomalies() (initial null allowed, >30% rejected) → DB::transaction { lock currencies ORDER BY id, lock today rates, re-read rate_mode, update provider metadata, MANUAL save+continue, AUTO upsert today row } → forgetRateCache() + invalidatePriceCaches() if changed → log
```

HTTP before transaction. Any validation failure → zero writes. v2 `quotes` ensures single bulk request.

## 8. Failure Behavior

| failure | visible effect |
|---|---|
| timeout/connection/429/5xx/malformed/wrong base/missing currency/infinite/excessive precision/future/stale/anomaly/DB exception | `status=failed`, zero DB writes, LKG preserved, existing effective rate used |
| LKG | `CurrencyRate::whereDate <= today orderByDesc effective_date` keeps last good row |
| dry-run | no DB, no cache |

## 9. LKG

Last Known Good is the latest `currency_rates` row `<= today`. Frankfurter failure never nulls/zeroes rates.

## 10. Scheduler

OS: `* * * * * php artisan schedule:run`. Laravel: `everySixHours() timezone UTC withoutOverlapping(60) onOneServer when(enabled)` → 00:00,06:00,12:00,18:00 UTC. `onOneServer`+`withoutOverlapping`+app lock `currency-rate-sync` require shared Redis; if unavailable fail-closed.

## 11. MANUAL → AUTO

`PATCH /currencies/{id}/rate-mode {mode:auto}` → if no fresh `provider_rate` (`provider_rate_at` ≤72h), **auto-fetches Frankfurter** for that currency (anchor identity `1` for USD), validates, persists `provider_rate/provider/provider_rate_at/last_synced_at`, then locks, sets `rate_mode=auto, manual_rate=null`, upserts today `source=provider`, `effective_rate_updated_at` if changed, invalidates caches, `LogActivityJob rateModeChanged`. Missing/stale/anomalous → `422 EXCHANGE_RATE_NOT_FOUND`.

## 12. AUTO → MANUAL

`PATCH {mode:manual, manual_rate:"50.2500000000"}` validates `regex ^\d+(\.\d{1,10})?$ >0 finite ≤1_000_000`, locks, sets `MANUAL`, upserts today `source=manual provider=null`, invalidates caches, audit.

## 13. Cache

On effective change: `Cache::tags([currencies, products + 9 strategy tags + optional settings])->flush()` + `HomeService::clearCache()` + `CurrencyService::$rateCache=[]`. Dry-run: none. MANUAL provider-only: none. Never `Cache::flush()`.

## 14. Orders and Payments

`OrderCreationService` snapshots `currency_code, base_currency_code, catalog_currency_code, currency_rate, currency_rate_date, converted_total_price, order_products.catalog_*`. New rate affects new `CurrencyService::convertPrice` calls. Old order/invoice/transaction never updated. `MyFatoorahGateway` uses `order->currency_code ?? base`.

## 15. Frontend Contract

Admin: `GET /api/v1/currencies` and `currency_rates` plus `PATCH /api/v1/currencies/{id}/rate-mode` (`update-currency`). Admin resource exposes `effective_rate, rate_mode, manual_rate, provider_rate, provider=frankfurter, last_synced_at, provider_rate_at, effective_rate_updated_at, is_rate_stale`. Public `GET /api/v1/general/currencies` exposes `effective_rate` only. Never Frankfurter credentials.

## 16. Admin Flow

Create currency → `MANUAL` automatically. Edit rate via `POST/PUT currency-rates` today → becomes `MANUAL`. Switch via `PATCH rate-mode {mode:auto}` (auto-fetches Frankfurter if needed) or `{mode:manual, manual_rate}`. Sync: dry-run → live MANUAL → verify `provider_rate` → per-currency AUTO.

## 17. Production Deployment

```env
CURRENCY_RATE_ANCHOR=USD
CURRENCY_RATE_SYNC_ENABLED=false
FRANKFURTER_BASE_URL=https://api.frankfurter.dev
CURRENCY_RATE_TIMEOUT=10
CURRENCY_RATE_STALE_AFTER_HOURS=12
CURRENCY_RATE_PROVIDER_MAX_AGE_HOURS=72
CURRENCY_RATE_MAX_CHANGE_PERCENT=30
```
No `EXCHANGE_RATE_API_KEY`. Enable `SYNC_ENABLED=true` only after dry-run + MANUAL live sync + per-currency AUTO approval. Frankfurter requires no key.

## 18. Safe Rollout

Deploy nullable schema → backfill MANUAL → verify effective unchanged → deploy Frankfurter code with scheduler disabled → `currency:sync-rates --dry-run` review all (KWD 0.221→~0.307 =39% anomaly) → live sync while MANUAL (populates `provider_rate=frankfurter`) → verify DB/logs → activate AUTO per currency → monitor product/cart/checkout/payment/invoice → enable scheduler → monitor 48h.

## 19. Troubleshooting

- `provider unavailable` → check `FRANKFURTER_BASE_URL`, `last_synced_at`, logs `currency.sync.failed`
- `stale rate` → `is_rate_stale=true` if `last_synced_at>12h`
- `scheduler not running` → `schedule:list` + OS cron `schedule:run`
- `duplicate execution` → `currency.sync.skipped` lock_unavailable
- `currency missing` → Frankfurter unsupported → batch rejected `422`, add mapping or handle via validation
- `rate rejected` → malformed/stale/anomaly → `currency.sync.validation_failed`
- `AUTO activation rejected` → missing/stale `provider_rate`, run live sync first

## 20. Testing

- `vendor/bin/phpunit tests/Feature/Currency` (190 tests sqlite memory, Frankfurter faked via `Http::fake` for both v1 map and v2 list, plus live `v2/rates?base=USD&quotes=KWD,SAR,AED,EGP` verified 200)
- `currency:sync-rates --dry-run` must show table and exit 2 on anomaly
- `currency:sync-rates` live updates AUTO only, MANUAL preserved, zero partial writes
