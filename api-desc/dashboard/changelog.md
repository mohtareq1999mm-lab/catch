# Changelog - Dashboard Feature

## [Unreleased]

### Added
- 16 admin dashboard analytics endpoints under `/api/v1/dashboard/`
- `throttle:analytics` rate limiter (60 req/min)
- Overview, Revenue, Order Stats, Recent Orders, Top Products, Category Stats, Low Stock
- Sales, Customer, Product, Order, Category, Coupon, Cart, Finance Analytics
- Payment Reconciliation summary
- 300-second caching on 15 of 16 endpoints
- `DashboardDataSeeder` with realistic test data
- 18 translation keys (EN + AR)
- 28 test methods

### Known Issues
- Order stats hardcodes 5 statuses to 0
- No pagination on any endpoint
- No API Resource classes — raw arrays returned
