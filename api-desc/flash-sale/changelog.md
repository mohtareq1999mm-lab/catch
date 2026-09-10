# Flash Sale Module — Changelog

## [1.0.0] — 2026-07-19

### Added
- Comprehensive API investigation documentation (`api-desc/flash-sale/`)
- Flash Sales API: full CRUD (index, store, show, update, destroy) + reorder
- Vendor request system for store participation in flash sales
- Approve/disapprove vendor request workflow with queued event processing
- Automatic product pricing recalculation on flash sale update
- Product engine strategies: ProductHasFlashSale, ProductHasFlashSaleEndToday, ProductHasFlashSaleEndThisWeek
- Public endpoints: flash sales listing, products by flash sale, ending this week, ending today
- Flash sale types: percentage (with max_discount_amount cap), fixed_rate, final_price

### Known Issues

1. **Missing English translation keys** — `MESSAGE.CREATE_FLASH_SALE_SUCCESSFULLY`, `MESSAGE.UPDATE_FLASH_SALE_SUCCESSFULLY`, `MESSAGE.DELETE_FLASH_SALE_SUCCESSFULLY`, `MESSAGE.FLASH_SALE_REORDERED_SUCCESSFULLY` are missing from `resources/lang/en/message.php`. Arabic translations exist.

2. **Inline validation in `reorder()`** — Uses `$request->validate()` instead of a dedicated Form Request (unlike brand's `BrandsReorderRequest`).

3. **`updateFlashSale()` and `deleteFlashSale()` are public** — Should be `private` since they're only called internally.

4. **No DB transaction on `reorder()`** — Unlike `storeFlashSale()` and `updateFlashSale()`, the `reorder()` method is not wrapped in a transaction.

5. **`getFlashSaleInfoByProductID()` returns raw data** — No Resource wrapper, returns 200 with empty array for missing products.

6. **Inconsistent constant naming** — `VIEW_FlASH_SALE`, `CREATE_FlASH_SALE` use `FlASH` instead of `FLASH`.

7. **Resource returns raw title on index, translated on detail** — Inconsistent response format depending on route.

8. **No restore/force-delete endpoints** — Flash sales are soft-deleted only.
