# Cart Module — Frontend Jira Tasks

---

## Task 1: Cart Listing Page

**Priority:** High
**Component:** Frontend — Cart Page
**Story Points:** 8

**Description:** Build the main cart page that lists user's cart items split by shipping method (SCHEDULED and FAST).

**API Endpoint:**
- `GET /api/v1/cart`

**Acceptance Criteria:**
- [ ] Cart items displayed grouped by shipping method (normal_items, fast_items)
- [ ] Each item shows: product thumbnail, name, price, quantity selector, shipping method badge
- [ ] Variant attributes shown (e.g., Size: L, Color: Red)
- [ ] Cart summary: total items, total quantity, total price, subtotal, coupon discount, total after coupon
- [ ] Coupon discount shown as savings line item when `coupon_discount > 0`
- [ ] `total_after_coupon` displayed as the effective total when coupon applied
- [ ] Promotion eligibility indicator (`has_eligible_promotion`)
- [ ] Applied coupon display (if `coupon` object present)
- [ ] **Loading state:** Skeleton list of items
- [ ] **Empty state:** "Your cart is empty" with "Shop now" CTA
- [ ] **Error state:** Toast with retry

---

## Task 2: Add to Cart — Product Page

**Priority:** High
**Component:** Frontend — Product Page
**Story Points:** 5

**Description:** "Add to Cart" button on product and product detail pages.

**API Endpoint:**
- `POST /api/v1/cart`

**Acceptance Criteria:**
- [ ] Button disabled until quantity and variant (if variable) selected
- [ ] Shipping method selector (SCHEDULED/FAST) when applicable
- [ ] On click: show spinner, disable button
- [ ] On success: toast "Added to cart", cart badge count updates
- [ ] On error: toast error message with reason
- [ ] **Loading state:** Button spinner, quantity input disabled
- [ ] **Error state (validation):** Inline errors for missing fields
- [ ] **Error state (stock):** "Only X in stock" message

---

## Task 3: Cart Quantity Controls

**Priority:** Medium
**Component:** Frontend — Cart Page
**Story Points:** 3

**Description:** Quantity increment/decrement with inline update on each cart item.

**API Endpoint:**
- `PUT /api/v1/cart/update-item`

**Acceptance Criteria:**
- [ ] +/- buttons with debounced API call
- [ ] Inline spinner on the item being updated
- [ ] Total price updates optimistically, then confirmed from API response
- [ ] If stock exceeded, revert to previous quantity and show error
- [ ] **Loading state:** Item shows spinner
- [ **Error state:** Revert quantity, show toast

---

## Task 4: Remove Item from Cart

**Priority:** Medium
**Component:** Frontend — Cart Page
**Story Points:** 2

**Description:** Delete button on each cart item.

**API Endpoint:**
- `DELETE /api/v1/cart/delete-item/{itemId}`

**Acceptance Criteria:**
- [ ] Trash/close icon on each item
- [ ] Confirm dialog (optional, configurable)
- [ ] On success: item fades out, totals recalculated
- [ ] On error: toast with retry
- [ ] **Loading state:** Item shows spinner during delete
- [ ] **Empty state (last item removed):** Show empty cart view

---

## Task 5: Bulk Add from Saved Items / Wishlist

**Priority:** Low
**Component:** Frontend — Wishlist / Saved Items
**Story Points:** 5

**Description:** "Add all to cart" button that sends multiple items at once.

**API Endpoint:**
- `POST /api/v1/cart/bulk-items`

**Acceptance Criteria:**
- [ ] Button "Add all X items to cart"
- [ ] On click: show spinner on button
- [ ] On success: navigate to cart page
- [ ] On error: show which items failed (all-or-nothing rollback)
- [ ] **Loading state:** Button spinner
- [ ] **Error state:** Show list of items that couldn't be added

---

## Task 6: Clear Cart Action

**Priority:** Low
**Component:** Frontend — Cart Page
**Story Points:** 2

**Description:** "Clear cart" button with confirmation.

**API Endpoint:**
- `DELETE /api/v1/cart/delete-items`

**Acceptance Criteria:**
- [ ] "Clear cart" button in cart summary
- [ ] Confirmation modal before clearing
- [ ] If coupon applied, show "Coupon will be removed" warning
- [ ] On success: show empty cart view
- [ ] On error: toast with retry
- [ ] **Loading state:** Button spinner in confirmation modal
