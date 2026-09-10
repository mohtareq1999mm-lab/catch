# Coupon Module — Session Continuation Prompt

Copy the block below into a new opencode session to continue the coupon pipeline with the full context:

---

```
You are continuing work on the meem-commerce Laravel project at D:\meem-commerce.

## Module: Coupons (coupon)

## What Has Been Done (13 Documentation Files)
All files in api-desc/coupon/ are complete:
- README.md, backend.md, api.md, database.md, flow.md
- bug-report.md, changelog.md, frontend.md
- jira.md, jira-frontend.md, qa.md, test-cases.md
- CONTINUE.md

## Architecture Summary
- CouponOrchestrator pattern: CouponAssignmentValidator → CouponValidator (separated concerns)
- CouponCalculator (pure math): percentage (with max cap), fixed_rate (capped at subtotal), free_shipping
- Monetary: decimal floats (PHP native), capped at subtotal for fixed_rate
- Cart-level, one per order, applied after promotion
- Usage tracking via CouponUsage table (unique per user+coupon)
- Assignment system: per-user quota + expiry via CouponAssignment
- ~245+ existing tests (CouponSystemTest, CouponsProductionHardenTest, AssignedCouponSystemTest, CouponCalculatorTest, CouponValidatorTest)

## Key Files
- Admin Controller: packages/marvel/src/Http/Controllers/CouponController.php
- Public Controller: app/Http/Controllers/Api/General/CouponController.php
- Model: packages/marvel/src/Database/Models/Coupon.php (HasTranslations, InteractsWithMedia)
- Repository: packages/marvel/src/Database/Repositories/CouponRepository.php
- Service: app/Services/General/CouponService.php
- Orchestrator: app/Services/Coupon/CouponOrchestrator.php
- Validator: app/Services/Coupon/CouponValidator.php
- Calculator: app/Services/Coupon/CouponCalculator.php
- Assignment Validator: app/Services/Coupon/CouponAssignmentValidator.php
- Observer: app/Observers/CouponObserver.php
- Event: app/Events/AssignedCouponConsumed.php
- Create Request: packages/marvel/src/Http/Requests/CouponRequest.php
- Update Request: packages/marvel/src/Http/Requests/UpdateCouponRequest.php
- Admin Resource: packages/marvel/src/Http/Resources/CouponResource.php
- Public Resource: app/Http/Resources/Coupons/CouponResource.php
- Assignment Resource: app/Http/Resources/Coupons/CouponAssignmentResource.php
- Routes Admin: packages/marvel/src/Rest/Routes.php
- Routes Public: routes/api.php
- Tests: tests/Feature/CouponSystemTest.php
- Tests: tests/Feature/CouponsProductionHardenTest.php
- Tests: tests/Feature/AssignedCouponSystemTest.php
- Tests: tests/Unit/CouponCalculatorTest.php
- Tests: tests/Unit/CouponValidatorTest.php

## Known Bugs (api-desc/coupon/bug-report.md)
- BUG-CP-001: Guest already_used check (FIXED)
- BUG-CP-002: CouponAssignmentValidator null user (FIXED)
- BUG-CP-003: Free shipping enum initially missing (FIXED)
- BUG-CP-004: Cart coupon not cleared on deletion (OPEN)
- BUG-CP-005: Usage counter not atomic (MITIGATED)
- BUG-CP-006: Apply coupon returns success on empty cart (OPEN)

## Backend Jira Tasks (api-desc/coupon/jira.md)
1. CouponRepository::addCouponToCart() with full validation (DONE)
2. Cart coupon revalidation on checkout (DONE)
3. Expose is_assigned and assignments in CouponResource (DONE)
4. Add is_valid to CouponResource (DONE)
5. Implement CouponAssignmentValidator (DONE)
6. Add free shipping support (DONE)
7. Comprehensive test suite (DONE)

## Frontend Jira Tasks (api-desc/coupon/jira-frontend.md)
1. Admin coupon listing table
2. Admin create/edit form
3. Dynamic conditional form fields
4. Public coupon banner display
5. Coupon apply/remove UI
6. Cart coupon display
7. Delete confirmation dialog
8. Loading/empty/error states
9. Multilingual translatable fields
10. Coupon approval workflow UI

## Next Actions (Recommended Order)
1. Run existing coupon tests: php vendor/bin/phpunit --filter "Coupon"
2. Fix BUG-CP-004: Clear cart coupon on coupon deletion (cascade or job)
3. Fix BUG-CP-006: Validate cart not empty before applying coupon
4. Implement missing tests (approval, verify endpoint, analytics)
5. Frontend implementation (admin CRUD, public banners, checkout UI)

## Key Technical Constraints
- PREFIX = /api/v1
- Classmap autoloading for packages/marvel
- SQLite in-memory for tests (phpunit.xml)
- PHPUnit 10.0.13, PHP 8.2.28
- Translation files: resources/lang/en/coupon.php + resources/lang/ar/coupon.php
- Permission enum values must match middleware strings exactly
```
