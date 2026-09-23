# GOVERNORATE — DATABASE PLAN (Phase 3)

Status: COMPLETE — **ZERO migrations required**
Date: 2026-09-23
Scope: governorates, shipping_prices, address, orders

## Inspection answers (from actual migrations)

1. `governorates` exists? YES (`packages/marvel/database/migrations/2026_05_23_100002`). Columns: id, country_id FK CASCADE, name, status default true, is_fast_shipping_enabled default false, UNIQUE(country,name), INDEX(country_id).
2. Shipping governorate config table exists? YES — `shipping_prices` (`..._100004`): governorate_id FK CASCADE + UNIQUE, price, estimated_days null, free_shipping_over null, status default true.
3. Shipping price stored where? `shipping_prices.price` (decimal 10,2); consumed ONLY by `OrderService::resolveShippingPrice` (+fast path via `getGovernorateShippingInfo`).
4. Availability stored? `shipping_prices.status` (+ row presence) for scheduled; `governorates.is_fast_shipping_enabled` + settings for fast. No other flags found.
5. Address FK valid? YES: `address.governorate_id` nullable, FK→governorates nullOnDelete, INDEX(customer_id,governorate_id) (`2026_09_28_000001`).
6-9. Orphans/duplicates/NULLs: **NOT QUERIED** — no database access in this environment (neither prod nor local DB reachable from audit). Phase 7 data audit therefore runs against seeder evidence + test scaffolding only: seeder creates 1:1 rows (no orphans by construction); `CreatesTestTables`/feature tests recreate UNIQUE constraints (duplicates impossible at DB level); NULL addresses are BY DESIGN (legacy rows) and must NOT be backfilled (D5).

## Migration decisions

| Item | Decision |
|---|---|
| New tables | NONE (D1) |
| Alter columns (nullability/types/defaults) | NONE — all three FKs already nullable-safe; statuses defaulted |
| New FKs/indexes/uniques | NONE — required constraints exist (`shipping_prices.UNIQUE(governorate_id)`, address composite index, orders FK) |
| Data migration / backfill | NONE (D5; historical orders untouched by policy) |
| Rollback strategy | N/A (no migration). Code fix G1 rolls back by revert (single method). |

## Concurrency / integrity notes (Phase 12, schema level)

- `shipping_prices.UNIQUE(governorate_id)` ⇒ duplicate configs impossible (ambiguous pricing excluded at DB level; request validation mirrors it).
- `address.governorate_id` FK ⇒ invalid references impossible (DB-enforced; `exists` rule mirrors it).
- Governorate delete: cities block (repo) + cascade (shipping/cities) + null-out (addresses/orders) — verified safe; coupon evaluation degrades fail-closed (no rows match).
- Cache: governorate list cached per-URL (`FrontendResource::GOVERNORATES`, flushed on CUD + bulk + fast-toggle). Shipping rows have no separate cache key — `allActive` eager-loads fresh per miss; no master/config cache mixing beyond the shared payload (display-only, accepted C2).
