# GOVERNORATE / SHIPPING DECOUPLING — DISCOVERY (Phase 0)

Status: COMPLETE (read-only, no code/DB changes)
Date: 2026-09-23
Scope: governorates, shipping_prices, address.governorate_id, orders.governorate_id, coupon area_in, checkout shipping

## 1. Governorates (master geography) — VERIFIED

- Table `governorates` (`packages/marvel/database/migrations/2026_05_23_100002_create_governorates_table.php`):
  `id | country_id FK→countries CASCADE | name (string, translatable via model cast) | status bool default true | is_fast_shipping_enabled bool default false | timestamps | UNIQUE(country_id,name) | INDEX(country_id)`.
- Model `Marvel\Database\Models\Governorate`: fillable `country_id,name,status,is_fast_shipping_enabled`; `country BelongsTo`, `cities HasMany`, `shippingPrice HasOne(ShippingPrice)`; scopes `active()` (`status=true`), `fastShippingEnabled()`.
- Admin API `Marvel\Http\Controllers\GovernorateController` (permission-gated CRUD + `cities`, `shippingPrice`, `bulkStatus`, `toggleFastShipping` sub-endpoints). Customer API `App\Http\Controllers\Api\General\GovernorateController`: `index` → `GovernorateRepository::allActive()` (**status=true only, NO shipping filter**); `show` → 404 when `!status`. Cached per-URL (`FrontendResource::GOVERNORATES`).
- Validation (store/update): country exists, names unique per translation, `status in:1,0`; nested `shipping_price.*` optional (price/estimated_days/free_shipping_over/status).
- Seed: `database/seeders/LocationSeeder.php` creates 27 Egyptian governorates (status=true) + cities + one `shipping_prices` row each + fast flags. Seeding is 1:1 by convenience, NOT a structural coupling.

## 2. Shipping configuration — VERIFIED (already a separate table)

- Table `shipping_prices` (`..._100004_create_shipping_prices_table.php`):
  `id | governorate_id FK→governorates CASCADE | price decimal(10,2) | estimated_days uint null | free_shipping_over decimal(10,2) null | status bool default true | timestamps | UNIQUE(governorate_id)`.
  This IS the "shipping_governorate_config" the task asks for — **no new table needed**.
- Model `ShippingPrice`: fillable `governorate_id,price,estimated_days,free_shipping_over,status`; `governorate BelongsTo`; `active()` scope (`status=true`).
- Admin CRUD: `ShippingPriceController` + `ShippingPriceRepository`; store requires `governorate_id exists + unique`, update keeps uniqueness-ignoring-self.
- `Shipping` model (`shipping_classes`) is product shipping classes — unrelated to geography. No coupling.
- Fast shipping: `governorates.is_fast_shipping_enabled` + settings; `FastShippingRepository::validateCheckout` reports explicit unavailability errors (global off / hours / governorate / items). Only subsystem with a true "unavailable" signal.

## 3. Customer addresses — VERIFIED (already decoupled)

- Table `address` (base `2020_06_02_051901...` + `2026_09_28_000001_add_governorate_id_to_address_table.php`):
  `address.governorate_id` **nullable**, FK→governorates **nullOnDelete**, INDEX(customer_id,governorate_id). Migration comment: "Nullable + no backfill by design … fail closed".
- Model `Address`: fillable includes `governorate_id`; `governorate BelongsTo`; `customer BelongsTo(User,customer_id)`.
- Validation (`AddressRequest` / `AddressRequestUpdate`): `governorate_id: nullable|integer|exists:governorates,id` — **no `status` check, no shipping check**. A shipping-disabled (or even inactive) governorate id passes validation if the row exists.
- Controller `AddressController@store/@update`: owner-scoped; `store` merges `customer_id` from token (identity never client-supplied).
- Resource `AddressResource` exposes `governorate_id` (raw id, no shipping data leaked).

## 4. Checkout shipping resolution — VERIFIED (single choke point)

- `OrderService::resolveShippingPrice(?int $governorateId)` (`app/Services/General/OrderService.php:419-443`) used by BOTH `calcInvoicePrice` (preview) and `addItemsInOrder` (checkout) and via `getGovernorateShippingInfo` by fast-shipping checkout. Behavior matrix (current):
  | input | result |
  |---|---|
  | null/0 | `{price:0, free:null, governorate_id:null}` |
  | nonexistent id | same null triple |
  | existent but `status=false` | **same null triple — governorate_id DROPPED** ← gap G1 |
  | existent+active, no active shipping row | `{price:0, free:null, governorate_id:ID}` (id kept) |
  | existent+active + active shipping row | `{price, free_shipping_over, governorate_id:ID}` |
- Checkout NEVER blocks on shipping: unshippable ⇒ price 0, order proceeds. No "shipping unavailable" error exists in scheduled checkout (fast-shipping excepted). Order validation (`OrderCreateRequest:58-62`): `governorate_id` required-iff delivery, `exists:governorates,id` only.
- Order snapshot: `OrderCreationService::createOrder` stores `governorate_id` (+ `shipping_price` money snapshot); update path falls back to `$order->governorate_id` (snapshot preserved on retry — VERIFIED lines 51/130/164). `orders.governorate_id` FK nullOnDelete (migration `2026_07_11_000001`).
- Coupon interplay at checkout: `CouponOrchestrator::validate` NEVER reads `governorate_id`/shipping (three separate ignore-comments in engine/orchestrator/service). VERIFIED decoupled.

## 5. Coupon area_in — VERIFIED (already independent of shipping)

- `EligibilityEngine::evalAreaIn`: source = `address WHERE customer_id=user AND governorate_id IN (allowed ∩ governorates.status=true)`; ANY-match; NULL never matches; checkout/delivery input ignored; zero reads of `shipping_prices`/`status` there. `RuleTreeValidator` likewise (strict positive ints only).
- Master gate used: `governorates.status` (geography active), NOT shipping. Matches the task's "valid/active master governorate" rule.

## 6. Files / tables inspected

Models: Governorate, ShippingPrice, Shipping, Address, City, Country, Order (governorate usage), Coupon (n/a). Repositories: GovernorateRepository (incl. nested shipping create/update, delete-guard=cities only), ShippingPriceRepository, AddressRepository, FastShippingRepository, City/CountryRepository. Controllers: Marvel Governorate/ShippingPrice/Address/City/Country; app General Governorate; OrderController (callbacks). Services: OrderService (419-443, 168-169, 265-270), FastShippingService (68-122), OrderCreationService (51/130/164). Requests: AddressRequest(+Update), GovernorateStore/UpdateRequest, ShippingPriceStore/UpdateRequest, OrderCreateRequest (58-62), FastCheckoutRequest:25. Resources: Governorate/ShippingPrice/Address/City/CountryResource. Migrations: listed in §1-3 + `2026_07_11_000001` (orders FK). Seeder: LocationSeeder. Tests: ProductionReadinessAuditTest:1234-48, FinancialDeepAuditTest:538-74 (happy-path only).
