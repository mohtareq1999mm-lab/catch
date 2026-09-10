# Coupon Module — Changelog (Public API)

## [1.0.0] — 2026-07-20

### Added
- Comprehensive API investigation documentation (`api-desc/front/coupon/`)
- Public coupon listing endpoint: `GET /api/v1/general/coupons`
- Authenticated coupon apply endpoint: `POST /api/v1/general/coupons/apply`
- CouponResource with name, image, border styling
- CouponOrchestrator with full validation pipeline
- CouponValidator for date, usage, product, and quantity checks
- CouponAssignmentValidator for user-specific assignments
- CouponCalculator for fixed-rate and percentage calculations
- Extensive test coverage in `CouponSystemTest.php` and `AssignedCouponSystemTest.php`

### Known Issues
1. **No validation on `code` field** — Empty/missing code passes through to service (BUG-COUPON-001)
2. **No pagination on listing** — Flat collection, no pagination metadata (BUG-COUPON-002)
3. **CouponResource omits discount info** — No discount_type, discount value, code, or dates (BUG-COUPON-004)
4. **No caching** — Listing hits DB on every request (Jira Task 4)
