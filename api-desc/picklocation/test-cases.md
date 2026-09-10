# Test Cases - Pickup Location Feature

## Current Coverage

### PickupLocationTest (825 lines, 29 tests)

| Category | Tests | File |
|----------|-------|------|
| Admin CRUD | 5 | `PickupLocationTest.php` |
| Validation | 4 | `PickupLocationTest.php` |
| Authorization | 3 | `PickupLocationTest.php` |
| Public API | 3 | `PickupLocationTest.php` |
| Edge Cases | 3 | `PickupLocationTest.php` |
| Default location | 11 | `PickupLocationTest.php` |

### PickupLocationPricingIntegrationTest (1232 lines, 40+ tests)

| Category | Tests | File |
|----------|-------|------|
| Pickup API | 8 | `PickupLocationPricingIntegrationTest.php` |
| Pricing | 7 | `PickupLocationPricingIntegrationTest.php` |
| Checkout with pickup | 11 | `PickupLocationPricingIntegrationTest.php` |
| Checkout hardening | 7 | `PickupLocationPricingIntegrationTest.php` |
| Auth/permissions | 4 | `PickupLocationPricingIntegrationTest.php` |
| Order resource | 2 | `PickupLocationPricingIntegrationTest.php` |
| Validation | 2 | `PickupLocationPricingIntegrationTest.php` |

### Test Count: ~69 tests total

## What's Covered

✅ Admin list with pagination
✅ Admin create/store with validation
✅ Admin show
✅ Admin update
✅ Store requires store_name, address, phone
✅ Store validates email format
✅ Store validates display_order is integer (non-negative)
✅ Update validates email format
✅ Update validates display_order is integer (non-negative)
✅ Update allows partial fields (sometimes)
✅ Update accepts translatable working_hours.day.ar / day.en (Arabic + English)
✅ Unauthenticated access blocked (401)
✅ Customer cannot create/update/delete (403)
✅ Public list returns only active
✅ Public show returns 404 for inactive
✅ 404 for non-existent ID
✅ Ordering by display_order
✅ Search by store_name
✅ Integration with checkout (pickup snapshot)
✅ Order resource includes pickup_location for pickup orders
✅ Order resource excludes pickup_location for delivery orders
✅ Working hours validation
✅ Soft-deleted pickup location preserves snapshot in existing orders
✅ Create with is_default=true resets previous default
✅ Update setting is_default=true resets others
✅ Updating default's other fields preserves is_default
✅ Exactly one default at a time
✅ Deleting default promotes next by id
✅ Deleting non-default keeps default
✅ Admin resource exposes is_default
✅ Public resource exposes is_default
✅ Service returns active default (null when none active)
✅ Store validates is_default is boolean
✅ Create without is_default defaults to false

## Recommended Additional Tests

| # | Test | Priority |
|---|------|----------|
| FT-001 | `inactive=true` filter only returns inactive | Low |
| FT-002 | Both active + inactive filters together | Low |
| FT-003 | Public show returns 404 for soft-deleted | Medium |
| FT-004 | Export/import pickup locations | Low |
| FT-005 | Delete default when it's the only location (no promotion, no error) | Medium |
| FT-006 | Soft-deleted default doesn't get promoted from (withTrashed reset verified) | Medium |
| FT-007 | Observer dispatches LogActivityJob on created/deleted (queued, action + translation key) | Medium |
| FT-008 | Observer dispatches `statusChanged` job on status toggle with old/new payload | Medium |
| FT-009 | Store request rejects `working_hours.*.day` as string/object (must be array) | High |
| FT-010 | Public list respects `limit` query param (default 10) and is not cached | Low |
| FT-011 | Public list `?default=1` filters to the default branch; falsy/omitted returns all active | High |
| FT-012 | Public list `?default=1` when no active default exists → empty list, 200 | Medium |
