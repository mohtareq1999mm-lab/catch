# COMMERCE INVENTORY FLOW (Phase 1 Lock)

> Status: LOCKED. Extends: `docs/inventory-system.md`, `docs/concurrency-audit.md`.
> Verified: `OrderReservationService.php:37-221`, `InventoryRestoreService.php:26-55`,
> `Product.php:42,99,111,553,627`, `ProductVariant.php:36,116`, `CancelUnpaidOrders`.

## 1. Authority (locked, §1 human decision)

`products` / `product_variants` (`stock_quantity`, `reserved_quantity`, `sold_quantity`,
`in_stock`; available = max(0, stock − reserved)). No Stock/InventoryBalance table. EVER.
Variant rows are independent authorities (locked per-row by `lockStockRow`).

## 2. Mutation map (complete — no other writers exist in `app/`)

| Operation | Trigger | Class/Method | DB mutation | Tx/Lock | Idempotency |
|---|---|---|---|---|---|
| Reserve | checkout (`addItemsInOrder:316`, fast-shipping) | `OrderReservationService::reserveForOrder` | reserved+=qty, in_stock recompute; order active+expiry | compose/own tx; order+stock `lockForUpdate` deterministic order | re-reserve active = no-op; throw rolls back all |
| Commit | payment success / mark-paid (`changeOrderStatus`) | `::commit` | stock−, reserved−, sold+; order committed | conditional claim `active→committed` locked | non-active = false, never double-commit |
| Release | expiry reaper, unpaid cancel | `::release` | reserved− only; order released | conditional claim locked | non-active = false |
| Restore | paid cancel | `InventoryRestoreService::restore` | stock+, sold−; order restored | conditional claim locked | non-committed = false |
| Detach legacy | one-off migration | `MigrateInventoryReservations` | zero cart lines, subtract legacy | per-line locks | re-runnable |
| Admin adjust | P4 design point | new `inventory.adjust` perm flow | via Reservation service only | locked | audit + reason required |

## 3. Concurrency (MySQL proof required P14; sqlite proves logic only)

Deterministic lock order `(variant, product)`; two-pass validate-then-increment; conditional claims.
Last-unit race serializes on InnoDB row locks. Deadlock risk accepted + monitored (short tx, no
external calls inside lock scope — normative rule §40).
