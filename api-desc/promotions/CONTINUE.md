# Promotion Module — Session Continuation Prompt

Copy the block below into a new opencode session to continue the promotions pipeline with the full context:

---

```
You are continuing work on the meem-commerce Laravel project at D:\meem-commerce.

## Module: Promotions (promotion)

## What Has Been Done (12 Documentation Files)
All files in api-desc/promotions/ are complete:
- README.md, backend.md, api.md, database.md, flow.md
- bug-report.md, changelog.md, frontend.md
- jira.md, jira-frontend.md, qa.md, test-cases.md

## Architecture Summary
- Strategy Pattern engine: PercentagePromotionStrategy, FixedPromotionStrategy, GiftPromotionStrategy
- All monetary: integer cents, proportional allocation (largest remainder)
- Cart-level, no stacking (one per order), applied before coupon
- Eligibility: PromotionEligibilityResolver (single source of truth)
- Application: PromotionApplicator → DB transaction with lockForUpdate
- Orchestration: PromotionService
- ~57 existing tests (PromotionFlowTest, PromotionProductionHardenTest, PromotionEligibilityResolverTest)

## Key Files
- Admin: packages/marvel/src/Http/Controllers/PromotionController.php
- Public: app/Http/Controllers/Api/General/PromotionController.php
- Model: packages/marvel/src/Database/Models/Promotion.php (HasTranslations, InteractsWithMedia, Sluggable)
- Repository: packages/marvel/src/Database/Repositories/PromotionRepository.php
- Service: app/Services/General/PromotionService.php
- Engine: app/Services/General/PromotionEngine/ (Resolve, Applicate, Strategies, Outcomes, DTOs)
- Observer: app/Observers/PromotionObserver.php
- Data: app/Services/General/PromotionDataService.php
- Create Request: packages/marvel/src/Http/Requests/PromotionRequest.php
- Update Request: packages/marvel/src/Http/Requests/UpdatePromotionRequest.php
- Admin Resource: packages/marvel/src/Http/Resources/PromotionResource.php
- Public Resource: app/Http/Resources/Promotion/PromotionResource.php
- Routes Admin: packages/marvel/src/Rest/Routes.php (line 238 full, 628 vendor update, 688 store owner create+destroy)
- Routes Public: routes/api.php (lines 54-55)
- Routes Checkout: routes/api.php (line 77)
- Tests: tests/Feature/PromotionFlowTest.php, PromotionProductionHardenTest.php, tests/Unit/PromotionEligibilityResolverTest.php

## Known Bugs (api-desc/promotions/bug-report.md)
- PR-001: Gift shipping_method (FIXED)
- PR-002: getCheckoutTotalsFromCart re-validation (FIXED, deprecated)
- PR-003: reserveItem clears promotion data (CONFIRMED by test)
- PR-004: CartRepository::revalidatePromotion() NOT IMPLEMENTED
- PR-005: Cart controllers don't revalidate promotion
- PR-006: CartItemResource missing promotion_id, discount_amount, is_gift
- PR-007: CartResource missing has_eligible_promotion
- PR-008: Redundant matchedEligibility() call in applySelectedPromotion()
- PR-009: UpdatePromotionRequest missing ->ignore($id) for unique name
- PR-010: Empty migration stub (variant nullable already applied)

## Backend Jira Tasks (api-desc/promotions/jira.md)
1. Implement CartRepository::revalidatePromotion()
2. Wire revalidation into CartController (store/update/deleteItemFromCart)
3. Expose promotion fields in CartItemResource
4. Add has_eligible_promotion to CartResource
5. Remove redundant matchedEligibility() call
6. Fix UpdatePromotionRequest unique name validation (->ignore($id))
7. Add comprehensive test suite (PromotionCrudTest, PromotionCheckoutTest, PromotionStrategyTest)

## Frontend Jira Tasks (api-desc/promotions/jira-frontend.md)
1. Admin promotion listing CRUD table
2. Admin create/edit form with dynamic conditional fields
3. Dynamic form based on type/type_amount selection
4. Public promotion banners on homepage
5. Public promotion detail page with products
6. Checkout promotion selection UI
7. Cart promotion display with per-item discounts
8. Delete confirmation dialog
9. Loading/empty/error states
10. Multilingual translatable fields

## Next Actions (Recommended Order)
1. Run existing tests to confirm baseline: vendor/bin/phpunit tests/Feature/PromotionFlowTest.php tests/Feature/PromotionProductionHardenTest.php tests/Unit/PromotionEligibilityResolverTest.php
2. Fix PR-009 (UpdatePromotionRequest ignore)
3. Fix PR-008 (redundant matchedEligibility)
4. Implement PR-004 + PR-005 (CartRepository revalidatePromotion + CartController wiring)
5. Implement PR-006 + PR-007 (CartItemResource + CartResource)
6. Create new test file for admin CRUD (PromotionCrudTest)
7. Create test file for checkout integration (PromotionCheckoutTest)

## Key Technical Constraints
- PREFIX = /api/v1
- Classmap autoloading for packages/marvel — manual edits to vendor/composer/autoload_classmap.php + autoload_static.php needed when adding new PHP files
- SQLite in-memory for tests (phpunit.xml)
- PHPUnit 10.0.13, PHP 8.2.28
- Translation keys use APP_NOTICE_DOMAIN . 'MESSAGE.*' pattern
- Permission enum values must match middleware strings exactly
```
