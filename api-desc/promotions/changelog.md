# Promotion Module — Changelog

## [1.0.0] — 2026-07-19

### Added
- Promotion Engine with Strategy Pattern (Percentage, Fixed Rate, Gift)
- Admin CRUD: full create, read, update, delete with permissions
- Public API: list valid promotions + get by slug with products
- Checkout API: eligible promotions for cart
- Comprehensive API investigation documentation (`api-desc/promotions/`)
- PromotionObserver with activity logging for created/updated/deleted
- Usage tracking with increment/decrement and lockForUpdate safety
- Gift product support with variant payload and stock checking
- Proportional discount allocation (largest remainder method) across cart items
- PromotionEligibilityResolver — single source of truth for eligibility

### Changed
- N/A (initial comprehensive documentation)

### Fixed
- Gift items missing `shipping_method` — P1 bug fixed
- `getCheckoutTotalsFromCart()` re-validation issue — P1 bug fixed (method deprecated, `addItemsInOrder()` uses `calculateCheckoutTotals()`)
- Cart modification clears promotion data — confirmed by test coverage

## Known Issues

1. **`CartRepository::revalidatePromotion()` not implemented** — No single orchestration point for promotion revalidation after cart modification.
2. **Cart controllers don't revalidate promotion** — `store()`, `update()`, `deleteItemFromCart()` do not re-check promotion eligibility after modifying cart contents.
3. **`CartItemResource` missing promotion fields** — `promotion_id`, `discount_amount`, `is_gift` not exposed in API response.
4. **`CartResource` missing `has_eligible_promotion`** — Frontend cannot determine promotion availability without separate API call.
5. **Redundant `matchedEligibility()` call** — `PromotionService::applySelectedPromotion()` calls resolver twice.
6. **Update request missing `->ignore($id)`** — Updating a promotion without changing name would incorrectly fail with "name already taken".
7. **Empty migration stub** — `2026_07_18_000001_make_promotion_gift_product_variant_nullable.php` is an empty file with no logic.
