# Fast Shipping — Test Coverage

## Test Files

| File | Type | Lines | Focus |
|------|------|-------|-------|
| `tests/Feature/FastShippingControllerTest.php` | Feature | 1079 | All public endpoints |
| `tests/Feature/FastShippingHardenTest.php` | Feature | 843 | Edge cases, security, validation |
| `tests/Unit/FastShippingRepositoryTest.php` | Unit | 239 | Repository logic |
| `tests/Unit/FastShippingScopeTest.php` | Unit | 86 | Global scope behavior |

## Coverage Summary

### Feature Tests (FastShippingControllerTest)

- Status endpoint (available, unavailable, disabled, working hours)
- Products listing (with search, pagination, empty results)
- Checkout flow (success, empty cart, ineligible items, invalid governorate)
- Orders listing (own orders, pagination, no orders)
- Coupon re-validation during checkout
- Promotion application during checkout
- Payment method routing (online, cod, pay_at_cashier)
- COD + pickup validation
- Order item creation verification
- ETA and fast_shipping_fee verification

### Feature Tests (FastShippingHardenTest)

- Auth required on protected endpoints
- Validation edge cases (missing fields, invalid types)
- XSS and injection attempts
- Boundary values (limit, pages)
- Working hours edge cases
- Governorate enable/disable during checkout flow
- Product eligibility changes during checkout flow
- Empty cart attempt
- Rate limiting compliance
- Concurrent checkout attempts (race conditions)

### Unit Tests (FastShippingRepositoryTest)

- `getSettings()` returns defaults when no settings exist
- `getSettings()` returns merged values from settings table
- `updateSettings()` persists and invalidates cache
- `isWithinWorkingHours()` with various times
- `isGloballyEnabled()` based on settings
- `areProductsFastEligible()` with mixed eligibility
- `calculateEta()` respects duration_minutes setting
- `getFee()` returns correct value
- `getNextAvailableTime()` before/after hours

### Unit Tests (FastShippingScopeTest)

- Scope applies `is_fast_shipping_available` filter when channel is fast-shipping
- Scope does not apply filter when channel is home
- Scope does not apply filter when channel is default (no header)
- Scope handles disabled channel config (channel.enabled = false)

## Missing Tests

| Area | Priority | Reason |
|------|----------|--------|
| Cache invalidation after settings update | Medium | Important for correctness |
| Governorate toggle endpoint separately | Medium | Test admin controller directly |
| Product toggle endpoint separately | Medium | Test admin controller directly |
| Settings endpoint permission tests | Medium | Important for security |
| XSS in checkout fields (name, address) | Low | Already covered in harden test |
| Large payload in checkout request | Low | Edge case |
| Bulk product eligibility toggle | Low | Not implemented |
