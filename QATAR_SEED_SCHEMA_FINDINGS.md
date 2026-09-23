# QATAR SEED — SCHEMA FINDINGS (pre-implementation)

## Actual `countries` schema (CODE WINS)
- Migration `packages/marvel/database/migrations/2026_05_23_100001_create_countries_table.php`:
  `id | name string | phone_code string(10) NULL | status bool default true | timestamps | INDEX(status)`.
- **NO iso2 / iso3 / numeric columns exist.** QA / QAT / 634 CANNOT be stored — no migration will be created for them (safety rule). Reported as unsupported-by-schema.
- Model `Country`: Spatie `HasTranslations`, translatable `['name']` → `name` stores JSON `{"en":..,"ar":..}` in a string column. Fillable: name, phone_code, status. Relation `governorates HasMany`.
- **NO unique constraint** on countries. Canonical identity (project convention, `LocationSeeder:15`): `phone_code` via `firstOrCreate(['phone_code' => '20'], …)`. Egypt = phone_code `20`, name `['en'=>'Egypt']` (en-only).
- Qatar decision: identity = `phone_code '974'` (Qatar's real calling code, same convention) with fallback name-match reuse (`en:Qatar` / `ar:قطر`) so a pre-existing Qatar row is never duplicated. Name stored `['en'=>'Qatar','ar'=>'قطر']`, status true.

## Actual `governorates` schema (CODE WINS)
- Migration `..._100002_create_governorates_table.php`: `id | country_id FK→countries CASCADE | name string | status default true | is_fast_shipping_enabled default false | UNIQUE(country_id,name) | INDEX(country_id)`.
- Model fillable: country_id, name, status, is_fast_shipping_enabled; translatable name. Delete guard: cities only; shipping rows cascade; addresses/orders null-out (verified safe, untouched).
- Lookup strategy: `firstOrCreate(['country_id'=>QATAR_ID,'name'=>['en'=>..,'ar'=>..]])` + explicit pre-check; UNIQUE backstop makes duplicates loud, not silent. JSON key order fixed en,ar.
- No shipping rows (task scope excludes shipping; unlike LocationSeeder's per-gov ShippingPrice which is Egypt-flow specific). No cities (Qatar task = municipalities only as governorate rows; no zones/districts invented).

## Intended changes (minimal)
1. NEW `database/seeders/QatarLocationSeeder.php` — Qatar country (reuse-or-create) + 8 municipalities (verify-or-create, status true, defaults preserved).
2. Register after `LocationSeeder::class` in `DatabaseSeeder.php` (one line).
3. NEW `tests/Feature/QatarLocationSeederTest.php` — idempotency, counts, names, status, no-shipping assertions.
4. `QATAR_GOVERNORATES_SEEDER_REPORT.md` — this report family.
5. NO migration. NO model/controller/request changes. NO shipping/address/order/coupon changes.
