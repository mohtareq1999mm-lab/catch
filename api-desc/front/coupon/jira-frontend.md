# Coupon Module — Frontend Jira Tasks

---

## Task 1: Coupon Input — Cart/Checkout Page

**Priority:** High
**Component:** Frontend — Cart/Checkout
**Story Points:** 5

**Description:** Build a coupon code input field on the cart and checkout pages that allows users to apply discount codes.

**API Endpoint:**
- `POST /api/v1/general/coupons/apply`

**Acceptance Criteria:**
- [ ] Text input with "Apply" button for coupon code entry
- [ ] Button shows loading spinner during API call
- [ ] On success: show discount amount, update cart total, show success message
- [ ] On already applied: show "Coupon already applied" info message
- [ ] On invalid/expired: show error message in red below input
- [ ] Applied coupon shown as a removable tag/badge with "Remove" option
- [ ] Input disabled after successful apply until coupon is removed
- [ ] **Loading state:** Button shows spinner, input disabled
- [ ] **Empty state:** Input placeholder "Enter coupon code"
- [ ] **Error state (network):** Toast with "Network error, please try again"

---

## Task 2: Available Coupons Display — Cart Page

**Priority:** Medium
**Component:** Frontend — Cart Page
**Story Points:** 3

**Description:** Display available coupons on the cart page so users can see what discounts are available.

**API Endpoint:**
- `GET /api/v1/general/coupons`

**Acceptance Criteria:**
- [ ] Horizontal scrollable list of coupon cards below cart summary
- [ ] Each card shows coupon name, image, border color/style
- [ ] Clicking a coupon auto-fills the code input
- [ ] "Copy code" button for manual entry
- [ ] **Loading state:** Skeleton coupon cards
- [ ] **Empty state:** Hide section
- [ ] **Error state:** Hide with console warning

---

## Task 3: Coupon Display — Applied Coupon Badge

**Priority:** Medium
**Component:** Frontend — Cart Summary
**Story Points:** 2

**Description:** Show the applied coupon as a visual badge in the cart summary with discount breakdown.

**API Data:** Response from `POST /coupons/apply`

**Acceptance Criteria:**
- [ ] Badge shows coupon code and discount amount
- [ ] Border styling (borderColor, borderless) applied to badge
- [ ] "Remove" button on badge to clear coupon
- [ ] Discount line item shown in price breakdown
- [ ] Free shipping indicator shown when applicable
- [ ] **Loading state:** Skeleton badge during apply
- [ ] **No coupon:** No badge shown, original prices displayed

---

## Task 4: Coupon Components — Loading, Empty & Error States

**Priority:** High
**Component:** Frontend — State Handling
**Story Points:** 2

**Description:** Handle all non-happy-path states across coupon components.

**Acceptance Criteria:**
- [ ] **Coupon listing loading:** 3 skeleton cards with border shimmer
- [ ] **Coupon listing empty:** Section hidden
- [ ] **Coupon listing error:** Hidden with console.warn
- [ ] **Apply loading:** Button spinner, input disabled
- [ ] **Apply success:** Green success toast, cart total updates
- [ ] **Apply already applied:** Blue info message
- [ ] **Apply invalid/expired:** Red error below input, shake animation
- [ ] **Apply network error:** Red toast with retry
- [ ] **Apply unauthenticated:** Redirect to login page
