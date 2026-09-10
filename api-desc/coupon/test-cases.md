# Test Coverage — Coupon Module

## Test Files Overview

| File | Type | Tests | Focus |
|------|------|-------|-------|
| `CouponSystemTest.php` | Feature | ~60+ tests | Apply, expire, limit, already_used, cart deletion, checkout, free shipping, usage counting |
| `CouponsProductionHardenTest.php` | Feature | ~80+ tests | Validation, calculation, orchestrator, assignment, apply to cart, checkout, security, concurrency |
| `AssignedCouponSystemTest.php` | Feature | ~70+ tests | Assigned coupon flow, multi-use, backward compat, product restrictions, event dispatch, cascade |
| `CouponCalculatorTest.php` | Unit | ~15+ tests | Pure math: percentage, fixed_rate, free_shipping, max cap, negative prevention |
| `CouponValidatorTest.php` | Unit | ~20+ tests | Validation: valid, disabled, expired, future, limit, already_used, product restriction |

**Approximate Total: 245+ tests**

---

## CouponSystemTest.php

Covers end-to-end coupon system flows:

- Apply valid coupon and verify discount on cart
- Apply expired coupon → error
- Apply disabled coupon → error
- Apply coupon at usage limit → error
- Apply coupon already used by user → error
- Clear coupon from cart
- Cart deletion removes coupon reference
- Apply coupon and proceed to checkout (order placement records usage)
- Free shipping coupon type
- Coupon usage counter increments on order placement
- Coupon with `limiter = null` (unlimited)
- Already_used validation across multiple orders
- Checkout re-validation (coupon expired between add-to-cart and checkout)

---

## CouponsProductionHardenTest.php

Production hardening test suite:

### Validation (10+ tests)
- Missing required fields
- Invalid discount_type
- Invalid date ranges
- Invalid image uploads
- Duplicate name constraint

### Calculator (10+ tests)
- Percentage calculation with various values
- Fixed rate capped at subtotal
- Free shipping flag
- Max discount amount cap
- Zero and negative edge cases

### CouponOrchestrator (10+ tests)
- Public coupon flow (no assignments)
- Assigned coupon flow (user has assignments)
- Assignment expired
- Assignment quota exceeded
- User not assigned
- Mixed: user has multiple assignments

### Apply to Cart (15+ tests)
- Valid coupon → success with discount
- Inactive → rejected
- Expired → rejected
- Usage limit → rejected
- Already used by user → rejected
- Product restriction: eligible → success
- Product restriction: ineligible → rejected
- No items in cart → error
- Replace existing coupon
- Free shipping application

### Checkout & Usage (10+ tests)
- Order placement records coupon_usage
- Usage counter increments
- Assignment usage increments
- AssignedCouponConsumed event dispatched
- Checkout with expired coupon (applied pre-expiry)

### Security (5+ tests)
- SQL injection in coupon code
- XSS in name field
- Unauthorized access
- Mass assignment protection

### Concurrency (5+ tests)
- Race condition: two simultaneous applies
- Race condition: two simultaneous checkouts
- Atomic increment safety

---

## AssignedCouponSystemTest.php

Assigned coupon-specific test suite:

- Apply assigned coupon successfully
- Apply assigned coupon without assignment → rejected
- Apply assigned coupon expired → rejected
- Apply assigned coupon quota exhausted → rejected
- Multi-use per assignment (max_uses > 1)
- Public coupon backward compatibility (no assignments table)
- Product restriction with assignment
- Global limiter with assignment (both must pass)
- Orchestrator validates assignments first
- Checkout records assignment usage
- Usage history (CouponAssignmentUsage)
- Audit trail via AssignedCouponConsumed event
- Cascade deletion (delete coupon → assignments + usages deleted)
- Concurrency: simultaneous assignment usage

---

## CouponCalculatorTest.php (Unit)

Pure math tests (no DB):

- Percentage: 20% of $100 = $20
- Percentage: 50% of $10 = $5
- Percentage with max cap ($30 on 50% of $100 = $30)
- Fixed rate: $15 on $100 = $15
- Fixed rate capped: $200 on $100 = $100
- Fixed rate: $5 on $3 = $3 (capped)
- Free shipping: discount = 0, freeShipping = true
- Zero price input → 0 discount
- Negative price → 0 discount

---

## CouponValidatorTest.php (Unit)

Stateless validation tests (no DB):

- Valid coupon → true
- Disabled → false
- Not yet active (future start_date) → false
- Expired (past end_date) → false
- Usage limit reached → false
- Already used by user → false
- Product restriction: eligible → true
- Product restriction: ineligible → false
- validateByCode: valid code → passes
- validateByCode: invalid code → exception

## Coverage Summary

| Category | Coverage | Notes |
|----------|----------|-------|
| Admin CRUD | ✅ Full | List, create, read, update, delete |
| Public API | ✅ Full | List, apply |
| Validation | ✅ Full | All fields, edge cases |
| Authorization | ✅ Full | Permissions, authentication |
| Calculation | ✅ Full | All discount types, caps |
| Assignment | ✅ Full | Public, assigned, quota, expiry |
| Checkout | ✅ Full | Apply, re-validate, record |
| Event | ✅ Partial | AssignedCouponConsumed tested |
| Concurrency | ✅ Partial | Apply + checkout race conditions |
| Security | ✅ Partial | SQL injection, XSS, mass assignment |

## Missing Tests

- [ ] Approval/disapproval (super admin) 
- [ ] verify endpoint (currently commented out)
- [ ] Dashboard coupon analytics
- [ ] CouponShop pivot
- [ ] Soft delete restoration
