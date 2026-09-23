# GOVERNORATE / SHIPPING — TARGET ARCHITECTURE (Phase 2)

Status: APPROVED FOR IMPLEMENTATION (minimal delta; no redesign)
Date: 2026-09-23

## Canonical model (already matches the required separation)

```text
                 GOVERNORATES (governorates)
                 Master Geography: id, country_id, name, status, is_fast_shipping_enabled
                     │
          ┌──────────┼──────────┐
          │          │          │
          ▼          ▼          ▼
  customer_addresses  shipping_prices   coupon area_in
  .governorate_id     .governorate_id   address ∩ status=true
  (nullable FK,       (UNIQUE FK,       (zero shipping reads)
   nullOnDelete)       cascade,
                       status/price/
                       threshold per row)
```

Responsibilities:
- `governorates.status` = master geography lifecycle (listable/selectable/evaluable). NEVER means "shippable".
- `shipping_prices.status` + row presence = shipping availability; `price`/`free_shipping_over`/`estimated_days` = shipping economics. NEVER means "exists".
- `address.governorate_id` = customer location truth. Validated `exists` only.
- `orders.governorate_id` = immutable delivery-area snapshot (fix G1: keep existent ids).
- Coupon `area_in` = `address.governorate_id ∩ active governorates`. No shipping input (already true).

## Decisions

| # | Decision | Evidence | Alternatives | Trade-off | Confidence | Revisit trigger |
|---|---|---|---|---|---|---|
| D1 | No new table; `shipping_prices` IS the shipping config | UNIQUE(governorate_id), status+price columns exist | new `shipping_governorate_config` | new table = proliferation, migration + dual-write risk | HIGH | shipping needs multi-method rows per governorate |
| D2 | Fix G1 (keep existent id in snapshot) | snapshot-truth erosion, no dependent branches | leave nulling | 4-line change vs permanent area loss on inactive | HIGH | any consumer found branching on null-for-inactive |
| D3 | Do NOT add scheduled-checkout "unavailable" error | breaking API change; contradicts preserve-behavior | blocking error | safety vs contract breakage | HIGH | product decision + frontend UX ready |
| D4 | Keep nested admin shipping payload + resource embedding | admin-only convenience, rows separate | split endpoints | churn vs zero runtime effect | HIGH | admin UX simplification request |
| D5 | No backfill of NULL `address.governorate_id` | migration comment: fail-closed by design; guessing location = integrity violation | heuristic backfill | coverage vs false location + coupon fraud surface | HIGH | user-confirmed address update flow |

## Changes (complete list — one code change + tests + docs)

1. `app/Services/General/OrderService.php::resolveShippingPrice` — G1 fix (4 lines + comment).
2. `tests/Feature/GovernorateShippingDecouplingTest.php` — NEW: cases A–F (Phase 11).
3. Docs (7 required files).
4. NO migrations. NO route/controller/request/resource/model changes. NO shipping price logic changes. NO coupon changes.
