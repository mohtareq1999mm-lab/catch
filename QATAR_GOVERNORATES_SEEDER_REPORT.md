# QATAR GOVERNORATES SEEDER REPORT

Status: IMPLEMENTED + VERIFIED (data seeding only; no architecture/migration changes)
Date: 2026-09-23

## 1. Files inspected

- `packages/marvel/database/migrations/2026_05_23_100001_create_countries_table.php` — countries schema
- `packages/marvel/database/migrations/2026_05_23_100002_create_governorates_table.php` — governorates schema
- `packages/marvel/database/migrations/2026_05_23_100004_create_shipping_prices_table.php` — shipping schema (untouched)
- `packages/marvel/src/Database/Models/Country.php` / `Governorate.php` — models, relations, fillables
- `database/seeders/LocationSeeder.php` — existing convention (Egypt)
- `database/seeders/DatabaseSeeder.php` — registration point
- `packages/marvel/src/Database/Repositories/GovernorateRepository.php::allActive` — country-filtered API path

## 2. Actual country schema (CODE WINS)

`countries: id | name string (Spatie-translatable JSON) | phone_code string(10) NULL | status bool default true | INDEX(status)`.
**No unique constraint. No iso2/iso3/numeric columns** — QA / QAT / 634 cannot be stored; no migration created per safety rule.

## 3. Actual governorate schema (CODE WINS)

`governorates: id | country_id FK→countries CASCADE | name string (translatable JSON) | status default true | is_fast_shipping_enabled default false | UNIQUE(country_id,name) | INDEX(country_id)`.

## 4. Qatar identification

Canonical mechanism = project convention from `LocationSeeder` (phone_code `firstOrCreate`).
Qatar: `phone_code '974'` (real calling code) + fallback reuse by stored `en:Qatar` / `ar:قطر` name match. No hard-coded IDs — country resolved dynamically at runtime.

## 5. Multilingual storage

Spatie `HasTranslations`: `name` column holds `{"en":"…","ar":"…"}` (key order fixed en,ar). Country: `['en'=>'Qatar','ar'=>'قطر']`. Municipalities: en+ar pairs per spec (cities-convention, unlike Egypt governorates which are en-only).

## 6. Files changed

| File | Change |
|---|---|
| `database/seeders/QatarLocationSeeder.php` (NEW) | `resolveQatar()` (phone→name fallback reuse, else create) + `seedMunicipality()` per 8 rows (explicit en/ar pre-check, status/name normalize-only on matched rows, UNIQUE-race reload); municipalities list as `municipalities()`; no cities/shipping/deletes |
| `database/seeders/DatabaseSeeder.php` | +1 line: `QatarLocationSeeder::class` after `LocationSeeder::class` |
| `tests/Feature/QatarLocationSeederTest.php` (NEW) | 5 tests / 45 assertions |
| `QATAR_SEED_SCHEMA_FINDINGS.md`, this file | reports |

NOT changed: migrations, models, controllers, requests, resources, shipping, coupons, addresses, orders.

## 7. Records (real DB, after seeder ×2)

- Country: exactly 1× `phone_code 974` → id=1, Qatar/قطر, status=1.
- Governorates under `country_id=1`, all status=1, fast=0:
  1 Doha/الدوحة · 2 Al Rayyan/الريان · 3 Al Wakra/الوكرة · 4 Al Shahaniya/الشحانية ·
  5 Al Daayen/الظعاين · 6 Umm Salal/أم صلال · 7 Al Khor and Al Thakhira/الخور والذخيرة · 8 Al Shamal/الشمال.

## 8. Idempotency strategy

Country: phone_code hit → reuse; else name hit → reuse; else create. Municipalities: country-scoped en/ar match → normalize status/name only; else create; UNIQUE-violation race → reload winner. Proven: seeder ×2 on real DB → counts unchanged (1 country, 8 govs); test suite runs seeder twice with same assertion.

## 9. Verification commands + results

- `php artisan test tests/Feature/QatarLocationSeederTest.php` → **5 passed, 45 assertions** (identity, 8 rows + names + active + defaults, double-run counts, zero shipping rows, pre-existing Egypt/Cairo untouched + `allActive($qatarId)` returns 8 + `$qatar->governorates()` returns 8).
- `php artisan db:seed --class=QatarLocationSeeder` ×2 → clean, no errors.
- Direct DB read: QATAR_ROWS=1, GOVS=8, SHIP=0 (output in §7).
- API path: customer `GET general/governorates` uses `allActive()` (status-only, no country arg → includes Qatar); `allActive($qatarId)` + `Country::governorates()` verified in tests. No API change needed.

## 10. Confirmations

- Shipping: 0 `shipping_prices` rows created/changed (asserted in test + real DB).
- Migration: none required, none created.
- Existing data: dev DB held no prior rows (totals 1 country / 8 govs); pre-existing-data safety additionally proven by test (Egypt/Cairo fixture survives double seeding byte-identical). No deletes/truncates/updates to unrelated rows (seeder contains none).
- ISO2/ISO3/numeric: unsupported by schema — omitted by design, not an oversight.
