# Test Cases - Dashboard Feature

## Current Coverage

**File:** `tests/Feature/DashboardTest.php` (860 lines, 28 tests)

| Category | Tests |
|----------|-------|
| Authentication | 1 |
| Original 7 endpoints | 7 |
| Sales Analytics | 2 |
| Customer Analytics | 1 |
| Product Analytics | 2 |
| Order Analytics | 2 |
| Category Analytics | 1 |
| Coupon Analytics | 1 |
| Cart Analytics | 2 |
| Finance Analytics | 2 |
| Edge Cases | 4 |
| Translation Resolution | 2 |
| Route Security | 2 |

## Known Bug: Tests Hit Wrong URLs

Tests use `const PREFIX = '/api/v1'` but actual route is `/api/v1/dashboard/{endpoint}`. However, the test URL format is correct for the admin routes. The bug is that analytics endpoint names in tests don't match:

| Test URL (used) | Actual Route |
|----------------|-------------|
| `/dashboard/sales` | `/dashboard/sales` ✅ matches |
| `/dashboard/customers` | `/dashboard/customers` ✅ matches |
| `/dashboard/products` | `/dashboard/products` ✅ matches |
| `/dashboard/orders` | `/dashboard/orders` ✅ matches |
| `/dashboard/categories` | `/dashboard/categories` ✅ matches |
| `/dashboard/coupons` | `/dashboard/coupons` ✅ matches |
| `/dashboard/cart` | `/dashboard/cart` ✅ matches |
| `/dashboard/finance` | `/dashboard/finance` ✅ matches |

The route group shown by the user uses short names that match the test URLs. The bug was only in the `api-desc/front/dashboard/` analysis which used `/general/` prefix.

## Recommended Additional Tests

| # | Test | Description |
|---|------|-------------|
| FT-001 | Rate limit exceeded returns 429 | throttle:analytics |
| FT-002 | All 16 endpoints return 200 | Smoke test |
| FT-003 | Cache invalidation on data change | 300s TTL |
| FT-004 | Unauthenticated returns 401 | All endpoints |
