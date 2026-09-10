# QA - Promotion Feature

## Test Environment Setup

- **PHP Version:** 8.x
- **Laravel Version:** As defined in `composer.json`
- **Database:** MySQL with `DatabaseTransactions` trait
- **Promotion Engine:** Truth table-based test data for eligibility scenarios
- **Cart:** requires session/auth setup for checkout tests
- **Media:** Spatie Media Library

## Existing Test Coverage

**5 test files, ~2,534 lines total:**

| Test File | Lines | Focus |
|-----------|-------|-------|
| `tests/Unit/PromotionEligibilityResolverTest.php` | 244 | Unit tests for percentage, fixed, gift eligibility, math, minimum order |
| `tests/Feature/PromotionCrudTest.php` | 162 | CRUD operations, validation, unauthenticated access |
| `tests/Feature/PromotionCheckoutTest.php` | 216 | Checkout integration, cart resources, public listing |
| `tests/Feature/PromotionFlowTest.php` | 565 | Gift variant, usage tracking, eligibility checks, cart modification |
| `tests/Feature/PromotionProductionHardenTest.php` | 1,347 | Production hardening: calculations, stock, eligibility, limits, checkout, regression |

## Test Matrix (Supplemental)

### Public API Tests

| TC ID | Description | Input | Expected |
|-------|-------------|-------|----------|
| TC-FT-001 | Public promotion listing | `GET /api/v1/general/promotions` | 200, list of active promotions |
| TC-FT-002 | Public promotion with products | `?with_product=true` | Products loaded in response |
| TC-FT-003 | Public promotion by slug | `GET /api/v1/general/promotions/summer-sale` | 200, single promotion |
| TC-FT-004 | Public promotion by slug (invalid) | `GET /api/v1/general/promotions/nonexistent` | 404 |

### Admin CRUD Tests

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-CRUD-001 | Create percentage promotion | 201, correct values stored |
| TC-CRUD-002 | Create fixed rate promotion | 201 |
| TC-CRUD-003 | Create gift promotion with variants | 201, gift products synced |
| TC-CRUD-004 | Create with specific products | 201, products associated |
| TC-CRUD-005 | Create without required fields | 422 |
| TC-CRUD-006 | Update promotion type | 200, type changed |
| TC-CRUD-007 | Delete promotion | 200, removed from listing |

### Promotion Engine Tests

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-ENG-001 | Percentage discount calculation | Correct amount |
| TC-ENG-002 | Percentage with max discount cap | Capped at max |
| TC-ENG-003 | Fixed discount calculation | Correct amount |
| TC-ENG-004 | Fixed discount not exceeding total | Floor at 0 |
| TC-ENG-005 | Gift promotion returns gift items | Items with is_gift=true, price=0 |
| TC-ENG-006 | Gift variant payload | Variant data included |
| TC-ENG-007 | Minimum order not met | Not eligible |
| TC-ENG-008 | Expired promotion | Not eligible |
| TC-ENG-009 | Usage limit reached | Not eligible |
| TC-ENG-010 | Specific products match | Discount on matched items only |
| TC-ENG-011 | All products match | Discount on full subtotal |

### Checkout Integration Tests

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-CHK-001 | Eligible promotions endpoint | Returns eligible list |
| TC-CHK-002 | Apply selected promotion | Totals include discount |
| TC-CHK-003 | Apply gift promotion | Gift items added to cart |
| TC-CHK-004 | Clear promotion from cart | Totals revert |
| TC-CHK-005 | Cart modification clears promotion | Promotion removed after item change |
| TC-CHK-006 | Usage increment on order | Usage count increased |
| TC-CHK-007 | Usage decrement on cancel | Usage count decreased (floor 0) |
| TC-CHK-008 | Promotion before coupon order | Correct stacking |

### Edge Case Tests

| TC ID | Description | Expected |
|-------|-------------|----------|
| TC-EC-001 | Zero discount value | No discount applied |
| TC-EC-002 | Negative price | Handled gracefully |
| TC-EC-003 | Empty cart | No eligible promotions |
| TC-EC-004 | Null promotion ID on usage | No-op |
| TC-EC-005 | Decrement below zero | Floor at 0 |
| TC-EC-006 | Concurrent usage limit check | Blocked at DB level |
| TC-EC-007 | Gift product out of stock | Excluded from results |
| TC-EC-008 | No gift variants available | Empty gift list |

## Manual Test Checklist

- [ ] Verify public promotion listing shows active promotions with images
- [ ] Verify eligible promotions endpoint respects cart contents
- [ ] Verify percentage discount applied correctly at checkout
- [ ] Verify fixed rate discount applied correctly
- [ ] Verify gift promotion adds gift items to cart
- [ ] Verify promotion-affected product filters work
- [ ] Verify promotion usage counter increments on order
- [ ] Verify expired promotions are not eligible
- [ ] Verify max_discount_amount cap is enforced
- [ ] Verify admin can create all three promotion types
- [ ] Verify admin can associate specific products
- [ ] Verify gift promotion with specific variants works
- [ ] Verify activity log entries for all CRUD operations
