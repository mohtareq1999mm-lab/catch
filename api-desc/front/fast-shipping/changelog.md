# Changelog - Fast Shipping Feature

## [Unreleased]

### Added
- Fast Shipping dual-channel system (Home + Fast Shipping)
- Global Eloquent scope `FastShippingScope` on Product model
- Channel middleware, context, and enum infrastructure
- `X-Channel` HTTP header support with configurable behavior
- Customer-facing endpoints: status, products, checkout, orders
- Admin endpoints: settings CRUD, product toggle, governorate toggle
- `FastShippingRepository` with settings caching (3600s)
- `FastShippingService` with transactional checkout flow
- `CartInventoryService` for inventory locking
- Permission enums: `view-fast-shipping`, `update-fast-shipping`
- ShippingMethod enum: `FAST`, `SCHEDULED`
- HasChannelFilter trait for home-channel exclusion
- Database columns: `products.is_fast_shipping_available`, `governorates.is_fast_shipping_enabled`, `orders.fast_shipping_fee`, `orders.shipping_method`
- 57 test methods across 6 test files

### Known Issues
- Cross-channel cache pollution (no channel context in HomeService cache keys)
- Checkout lockForUpdate() crashes under FastShippingScope when product eligibility changes
- Promotion gift product lookup affected by same scope issue
- FREE_SHIPPING coupon handling incomplete in fast checkout
- Missing English translation keys (5 keys in AR but not EN)
- Redundant WHERE clause in FastShippingRepository
