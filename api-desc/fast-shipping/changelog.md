# Fast Shipping — Changelog

## Version 1.0.0 (2026-07-20)

### Added
- Admin settings endpoint (GET/PUT `/api/v1/fast-shipping/settings`)
- Public status endpoint (GET `/api/v1/fast-shipping/status`)
- Public products listing (GET `/api/v1/fast-shipping/products`)
- Public checkout endpoint (POST `/api/v1/fast-shipping/checkout`)
- Public orders listing (GET `/api/v1/fast-shipping/orders`)
- Toggle endpoints for Product and Governorate fast shipping
- Channel-based filtering via X-Channel header
- Global Eloquent scope for fast-shipping product filtering
- FormRequest validation for fast checkout
- Feature tests (1079 lines)
- Hardening/edge case tests (843 lines)
- Unit tests for repository (239 lines)
- Unit tests for scope (86 lines)

### Architecture
- Two-layer: Public API (app/) + Admin API (packages/marvel/)
- Settings stored in `settings.options` JSON column
- Cached for 3600 seconds with invalidation on update
- DB transaction with `lockForUpdate` for checkout
- Integration with CouponValidator, PromotionService, PaymentCheckoutHandler
- Translation support in en, ar, de

### Security
- Sanctum auth required for checkout and orders
- Permission-based access for admin settings
- FormRequest validation for all inputs
- Working hours enforcement
- Governorate eligibility check
- Product eligibility check
