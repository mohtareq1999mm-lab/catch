# Jira - Promotion Feature

## Epic: Promotion & Discount Engine

### Story Points Estimate: 34

---

## User Stories

### US-001: View Promotion Listing (Public)
**As** a customer
**I want** to see active promotions on the shop
**So that** I can discover discounts and offers

**Acceptance Criteria:**
- `GET /api/v1/general/promotions` returns active promotions
- Supports `?with_product=true` to include associated products
- Returns translated name, desktop + mobile images

---

### US-002: View Promotion Detail (Public)
**As** a customer
**I want** to view a promotion and its associated products
**So that** I can see which products are on offer

**Acceptance Criteria:**
- `GET /api/v1/general/promotions/{slug}` returns promotion with products
- Returns 404 for invalid slug

---

### US-003: View Eligible Promotions (Checkout)
**As** a customer
**I want** to see which promotions apply to my cart during checkout
**So that** I can select a discount or gift offer

**Acceptance Criteria:**
- `GET /api/v1/general/checkout/promotions` returns eligible promotions for the cart
- Requires authentication
- Returns eligibility status and discount amount for each
- Percentage promotions show max_discount_amount
- Gift promotions show available gift items

---

### US-004: Apply Promotion to Cart
**As** a customer
**I want** to apply a promotion to my cart
**So that** I get the discount or gift

**Acceptance Criteria:**
- `selected_promotion_id` field in checkout/payment requests
- `selected_gift_product_id` for gift promotions
- Promotion discount reflected in order totals
- Coupon discount applied after promotion discount

---

### US-005: Admin CRUD - Create Promotion
**As** an admin user
**I want** to create promotions with different types
**So that** I can run marketing campaigns

**Acceptance Criteria:**
- `POST /api/v1/promotions` with multipart form data
- Types: percentage, fixed_rate, gift
- Can target all products or specific products
- Gift promotions allow selecting gift products with variants
- Date range for validity window
- Usage limiter
- Unique code auto-generated

---

### US-006: Admin CRUD - Update/Delete Promotion
**As** an admin user
**I want** to modify or remove promotions
**So that** I can adjust campaigns

**Acceptance Criteria:**
- `PUT /api/v1/promotions/{id}` partial update
- `DELETE /api/v1/promotions/{id}` removes promotion
- Activity logged for all operations

---

### US-007: Promotion Eligibility Engine
**As** a system
**I want** to correctly calculate promotion eligibility and discounts
**So that** customers receive accurate pricing

**Acceptance Criteria:**
- Percentage discounts cap at max_discount_amount
- Fixed discounts do not exceed total
- Gift promotions respect stock availability
- Minimum order amount enforced
- Usage limiter enforced
- Expired/future promotions not eligible

---

## Tasks

| Task ID | Description | Estimate (h) | Dependencies |
|---------|-------------|-------------|--------------|
| T-001 | Create promotions table migration | 2 | None |
| T-002 | Create pivot tables (promotion_product, promotion_gift_products) | 2 | T-001 |
| T-003 | Create Promotion model with relationships | 3 | T-001 |
| T-004 | Create PromotionRepository | 3 | T-003 |
| T-005 | Create PromoType and PromotionMountType enums | 1 | None |
| T-006 | Create PromotionController (Marvel) with CRUD | 4 | T-004 |
| T-007 | Create PromotionController (General) | 2 | T-003 |
| T-008 | Create FormRequests (create, update) | 4 | T-003 |
| T-009 | Create API Resources (Admin + Public) | 3 | T-003 |
| T-010 | Create PromotionService (checkout logic) | 6 | T-003 |
| T-011 | Create PromotionEligibilityResolver | 6 | T-010 |
| T-012 | Create PromotionApplicator | 4 | T-010 |
| T-013 | Create Strategy classes (Percentage, Fixed, Gift) | 8 | T-011 |
| T-014 | Create DTOs (PromotionResult, PromotionEvaluation, GiftItem, CheckoutTotals) | 3 | None |
| T-015 | Integrate with Cart and Order systems | 6 | T-010 |
| T-016 | Create PromotionObserver for activity logging | 2 | T-003 |
| T-017 | Write translation keys | 1 | None |
| T-018 | Seed promotion data | 3 | T-001 |
| T-019 | Write tests (CRUD, checkout, flow, production hardening) | 16 | T-001 to T-015 |

---

## Bug Tickets

| Ticket | Description | Priority | Severity |
|--------|-------------|----------|----------|
| BUG-001 | No Policy class (uses middleware only) | Low | Low |
| BUG-002 | Missing `type_amount` combination validation edge cases | Low | Low |
| BUG-003 | Code collision not handled gracefully | Low | Low |
