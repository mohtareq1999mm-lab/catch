# Coupon Module — Changelog

## [1.0.0] — 2026-07-19

### Added
- Coupon CRUD: full create, read, update, delete with permissions
- Public API: list valid coupons
- Apply coupon to cart (admin + public endpoints)
- Coupon usage tracking with usage limiter
- Coupon assignment system (per-user quota, expiry)
- CouponOrchestrator with assignment-aware validation flow
- CouponValidator — stateless validation (status, dates, limits, products)
- CouponCalculator — pure math (percentage with max cap, fixed_rate, free_shipping)
- CouponAssignmentValidator — user-scoped assignment checks
- CouponObserver with activity logging for created/updated/deleted
- AssignedCouponConsumed event dispatched on assignment usage
- Free shipping discount type support
- Multiple discount types: percentage, fixed_rate, free_shipping
- Comprehensive documentation (`api-desc/coupon/`)

### Changed
- N/A (initial comprehensive documentation)

### Fixed
- Guest user already_used check — skips when user is null
- CouponAssignmentValidator null user handling
- Free shipping type added to enum + migration

## Known Issues

1. **Cart `coupon` not cleared on coupon deletion** — Deleted coupon still referenced in active carts.
2. **Coupon usage counter not atomic** — Race condition under high concurrency (mitigated by transaction).
3. **Apply coupon returns success on empty cart** — No validation for empty cart before applying.
