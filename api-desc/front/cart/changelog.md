# Cart Module — Changelog (Authenticated API)

## [1.1.0] — 2026-07-21

### Added
- `subtotal` field in CartResource (alias for total_price, for clarity in coupon context)
- `coupon_discount` field in CartResource — calculated via CouponCalculator (supports percentage and fixed_rate)
- `total_after_coupon` field in CartResource — subtotal minus coupon discount (floored at 0)
- CartResource now computes coupon discount at response time using the stored coupon code
- Dependencies: CouponCalculator, CouponResource, PromotionService integrated into CartResource

### Changed
- CartResource response now includes subtotal, coupon_discount, and total_after_coupon in all cart responses

## [1.0.0] — 2026-07-20

### Added
- Comprehensive API investigation documentation (`api-desc/front/cart/`)
- 7 authenticated cart endpoints (CRUD + bulk items)
- CartInventoryService with pessimistic locking (`lockForUpdate()`) for inventory reservation
- 3-day reservation TTL with `expireCarts()` batch cleanup
- Shipping method split (SCHEDULED / FAST) in CartResource
- Promotion revalidation on cart mutations
- Gift item support via promotions (zero price, is_gift flag)
- Coupon-aware cart clearing with warning flow
- ProductPricingService integration for real-time price calculation

### Known Issues
1. **Duplicate `current_page` in index response** (BUG-CART-001)
2. **Manual pagination extraction** — not using standard resource pagination (BUG-CART-002)
3. **Generic error for auth vs not-found on item delete** (BUG-CART-003)
4. **Request cloning in bulk add** (BUG-CART-004)
5. **No tests** — zero test coverage for cart endpoints (BUG-CART-005)
6. **Aggressive promotion revalidation** resets all items (BUG-CART-006)
7. **Stranded inventory on coupon warning path** (BUG-CART-007)
