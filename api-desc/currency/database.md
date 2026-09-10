# Database - Currency Feature

## Table: `currencies`

| Column | Type | Default | Constraints | Notes |
|--------|------|---------|-------------|-------|
| id | bigint unsigned | AUTO_INCREMENT | PK | |
| code | varchar(3) | | NOT NULL, UNIQUE (`currencies_code_unique`) | Uppercased ISO code |
| name | json | | NOT NULL | Translatable `{"en":..., "ar":...}` |
| symbol | json | NULL | | Translatable, nullable |
| country_name | json | NULL | | Translatable, nullable |
| numeric_code | varchar(3) | NULL | | e.g. 840 |
| decimal_places | unsignedTinyInteger | 2 | | 0–4 enforced at validation |
| icon | varchar | NULL | | |
| is_active | boolean | 1 | Index (`currencies_is_active_index`) | |
| sort_order | integer | 0 | | |
| rate_mode | varchar(10) | `manual` | NOT NULL, default `manual` (2026-09-08) | `manual`→`manual_rate` effective, `auto`→`provider_rate` |
| manual_rate | decimal(20,10) | NULL | | Administrator override, `>0`, 10dp |
| provider_rate | decimal(20,10) | NULL | | Last accepted provider rate |
| provider | varchar(50) | NULL | | `exchange_rate_api` |
| last_synced_at | timestamp | NULL | | App accepted time |
| provider_rate_at | timestamp | NULL | | Provider `time_last_update` |
| effective_rate_updated_at | timestamp | NULL | | Last effective change |
| created_at | timestamp | NULL | | |
| updated_at | timestamp | NULL | | |
| deleted_at | timestamp | NULL | | Soft deletes |

### Translatable Columns
- `name`, `symbol`, `country_name` — JSON via Spatie `HasTranslations`.

### Soft Deletes
- `delete()` sets `deleted_at`; admin `show`/`update` use `withTrashed()`.
- `currency_rates` FK uses `cascadeOnDelete` — on **force** delete rates are removed; soft delete keeps them.

---

## Table: `currency_rates`

| Column | Type | Default | Constraints | Notes |
|--------|------|---------|-------------|-------|
| id | bigint unsigned | AUTO_INCREMENT | PK | |
| currency_id | bigint unsigned | | FK → currencies.id ON DELETE CASCADE | |
| exchange_rate | decimal(20,10) | | NOT NULL | Stored as string, 10 dp |
| effective_date | date | | NOT NULL | Rate applies from this day |
| source | varchar(20) | `legacy` | NOT NULL, default `legacy` (2026-09-08) | `legacy|manual|provider` |
| provider | varchar(50) | NULL | | `exchange_rate_api` when provider |
| created_at | timestamp | NULL | | |
| updated_at | timestamp | NULL | | |

### Indexes
- Unique: `(currency_id, effective_date)` — `currency_rates_currency_date_unique` (one rate per currency per day)
- Index: `effective_date` — `currency_rates_effective_date_index`

### Foreign Keys
- `currency_id` → `currencies.id` ON DELETE CASCADE

### Query Pattern (rate resolution)
```sql
SELECT exchange_rate FROM currency_rates
JOIN currencies ON currencies.id = currency_rates.currency_id
WHERE currencies.code = ?          -- currency code lookup (whereHas)
  AND DATE(currency_rates.effective_date) <= ?   -- whereDate
ORDER BY currency_rates.effective_date DESC
LIMIT 1
```

---

## Table: `orders` (currency snapshot columns)

Added by `2026_08_10_000004_add_currency_columns_to_orders_table.php` and `2026_08_11_000001_add_catalog_currency_code_to_orders_table.php`:

| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| currency_code | varchar(3) | YES | **Base** currency at order time (== base_currency_code) |
| base_currency_code | varchar(3) | YES | Base currency at order time |
| catalog_currency_code | varchar(3) | YES | Catalog currency snapshot at order time (new) |
| currency_rate | decimal(20,10) | YES | Catalog→base ratio (target/source, 6dp computed) |
| currency_rate_date | date | YES | Effective date used |
| total_price | decimal(10,3) | YES | **Base-amount** total (converted) |
| converted_total_price | decimal(10,3) | YES | Converted base amount (kept for backward compat) |

Backfills on migrate: `converted_total_price = total_price` where null; `catalog_currency_code = currency_code`.

Model casts (`Order`): `currency_rate => string`, `currency_rate_date => date`, `converted_total_price => float`. `catalog_currency_code` added to `$fillable`.

---

## Related Tables

| Table | Relation | Column |
|-------|----------|--------|
| `settings` | options JSON | `base_currency_code`, `catalog_currency_code`, `currency`, `currency_selection_enabled` (bool) |
| `orders` | snapshot | currency snapshot columns (above) |
| `products` | price conversion | via `ConvertsProductPrice` (no DB column) |
| `user_preferences` | preference | stores the user's selected currency code (`UserCurrencyPreferenceService`) |
| `currency` | config | `config/currency.php` (`anchor=USD`, `enabled=false`, `provider=frankfurter`, `stale 12h`, `provider_max_age 72h`) |

> `currency_selection_enabled` lives in `settings.options`. `UserCurrencyPreferenceService` reads/writes `user_preferences` table and reads `X-Currency` header (legacy `guest_currency` cookie deprecated). When `currency_selection_enabled` is `false` (default), the stored preference/header is **ignored** by `CurrencyService::getEffectiveCode()`.

> `sync` metadata lives on `currencies` (`rate_mode, manual_rate, provider_rate, provider, last_synced_at, provider_rate_at, effective_rate_updated_at`) and `currency_rates` (`source, provider`). Sync uses `ExchangeRateSyncService` with `Cache::lock currency-rate-sync` and `DB::transaction` + row locks; never `Cache::flush()`.

---

## Seeder — `database/seeders/CurrencySeeder.php`

Seeds 6 currencies + today's rate via `firstOrCreate` (idempotent). As of 2026-09-08, seeder also backfills `rate_mode=manual, manual_rate=today_rate` if null:

| Code | Rate | rate_mode | source |
|------|------|-----------|--------|
| USD | 1.0000000000 | manual | legacy→manual |
| KWD | 0.2210000000 | manual | legacy→manual |
| SAR | 3.7500000000 | manual | legacy→manual |
| AED | 3.6725000000 | manual | legacy→manual |
| EUR | 0.9990000000 | manual | legacy→manual |
| GBP | 0.8600000000 | manual | legacy→manual |

---

## Migration Files

| File | Purpose |
|------|---------|
| `2026_08_10_000002_create_currencies_table.php` | `currencies` table |
| `2026_08_10_000003_create_currency_rates_table.php` | `currency_rates` table |
| `2026_08_10_000004_add_currency_columns_to_orders_table.php` | order snapshot columns + backfill |
| `2026_08_11_000001_add_catalog_currency_code_to_orders_table.php` | `catalog_currency_code` on orders + backfill from `currency_code` |
| `2026_09_08_000004_add_rate_sync_fields_to_currencies_table.php` | `rate_mode, manual_rate, provider_rate, provider, last_synced_at, provider_rate_at, effective_rate_updated_at` + backfill MANUAL |
| `2026_09_08_000005_add_rate_provenance_to_currency_rates_table.php` | `source, provider` on `currency_rates` (default legacy) |

---

## Performance Notes

- Rate resolution query covered by unique `(currency_id, effective_date)` + `effective_date` index.
- Latest-rate lookup uses `whereDate(...) <= date` + `orderByDesc(effective_date)` — the index on `effective_date` serves both filter and sort.
- Public currency list is cached (tag `currencies`, 4h), avoiding repeated reads.
- `CurrencyService` holds an in-memory rate cache keyed by `code|date` (`forgetRateCache()` after every effective-rate mutation) to avoid duplicate queries within a request.
- Manual CRUD and `PATCH rate-mode` use `lockForUpdate` on `currencies` and today's `currency_rates` plus `DB::transaction` to prevent `effective_rate != mode-selected rate` races.
- Sync uses `Cache::lock currency-rate-sync (3600)` + `withoutOverlapping(60)` + `onOneServer()` (requires shared Redis) for distributed safety; HTTP before transaction, zero partial writes.
