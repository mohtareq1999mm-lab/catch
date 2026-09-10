# Cart Module — Jira Tasks (Frontend)

> This document is written for the **Frontend team only**. It intentionally avoids backend implementation details (repositories, services, models, migrations). Where a behavior affects the UI it is described in product terms.
>
> Source of truth for API contract: `api.md` / `frontend.md` in this folder. Verified on 2026-08-04 (Revision 4).

---

## Epic: Shopping Cart

**Business Goal:** Let customers add products to a persistent cart, adjust quantities, see delivery-grouped items and totals, apply coupons, and proceed to checkout.

**User Story:** "As a customer, I want to manage my shopping cart so I can review and adjust my order before checkout."

**Acceptance Criteria (epic):**
- [ ] Cart loads with all items grouped by delivery method
- [ ] Quantity adjustments, item removal, and cart clearing all work
- [ ] Coupon applied/removal reflects in the totals immediately
- [ ] All loading/empty/error states handled
- [ ] Bulk add reports skipped/failed products to the user

**Definition of Done:** All tasks below are implemented, tested, and reviewed against the API contract in `api.md`.

---

## Task 1: Cart Page — Load & Display

**Priority:** High
**Story Points:** 8

**User Story:** "As a customer, I want to see my cart items grouped by delivery method with full totals."

**API Endpoint:** `GET /api/v1/cart`
- **Auth:** Bearer token
- **Query:** `page` (default 1), `limit` (default 15) — note the parameter is `limit`, not `per_page`

**Success Response (key fields):** `data.data[]` with `normal_items` (SCHEDULED), `fast_items` (FAST), `subtotal`, `coupon_discount`, `total_after_coupon`, `expires_at`, `has_eligible_promotion`, `coupon`.

**Acceptance Criteria:**
- [ ] Render "Standard Delivery" section from `normal_items`
- [ ] Render "Express Delivery" section from `fast_items`
- [ ] Render gift items (`is_gift: true`, price 0) with a "FREE" badge
- [ ] Show item thumbnail, name, unit price, quantity, line total
- [ ] Show summary: subtotal, coupon discount, total after coupon
- [ ] Show cart reservation countdown from `expires_at`

**UI States:**
- **Loading:** skeleton rows (3–4)
- **Empty:** "Your cart is empty" + "Start Shopping" CTA
- **Error:** toast + retry
- **Offline:** keep last known state + retry

**QA Checklist:**
- [ ] Response with 0 carts shows empty state
- [ ] Response with 1 cart shows correct sections
- [ ] Decimal amounts display with 2 dp

---

## Task 2: Add to Cart — Product Pages

**Priority:** High
**Story Points:** 5

**User Story:** "As a customer, I want to add a product to my cart from the product page."

**API Endpoint:** `POST /api/v1/cart`
- **Auth:** Bearer token
- **Request body:** `{ "item": { "product_id": 10, "quantity": 2, "shipping_method": "SCHEDULED" } }`
- `shipping_method` is **required** (SCHEDULED or FAST). `product_variant_id` is required for variable products.

**Success:** HTTP 201 with the full cart object.

**Errors:**
- 422 → field errors (e.g., missing `shipping_method`, quantity 0)
- 400 → business message (e.g., stock exceeded, FAST not eligible)
- 401 → redirect to login; 429 → rate-limit message

**Acceptance Criteria:**
- [ ] Quantity selector (min 1) and shipping method selector
- [ ] For variable products, force variant selection before add
- [ ] Disable button + spinner during request
- [ ] Update header cart badge from the returned cart
- [ ] Toast on success; inline/toast on stock error

---

## Task 3: Cart Badge / Mini-Cart — Header

**Priority:** Medium
**Story Points:** 3

**User Story:** "As a customer, I want to see my cart item count in the header."

**API Endpoint:** `GET /api/v1/cart`

**Acceptance Criteria:**
- [ ] Badge shows `total_quantity` (sum of all line quantities)
- [ ] Badge updates after every cart mutation
- [ ] Click navigates to the cart page
- [ ] Badge keeps last known value on error

---

## Task 4: Quantity Update (increment/decrement)

**Priority:** Medium
**Story Points:** 3

**User Story:** "As a customer, I want to adjust item quantities without losing my place."

**API Endpoint:** `PUT /api/v1/cart/update-item`
- **Auth:** Bearer token
- **Request body:** `{ "item": { "product_id": 10, "quantity": 1, "operation": "increment" } }`
- ⚠️ `operation` is **required** — `increment` (adds `quantity`) or `decrement` (subtracts `quantity`).
- When `shipping_method` is omitted, the backend keeps the item's existing method.

**Success:** HTTP 200 with the full cart object.

**Errors:** 422 (missing/invalid `operation`), 400 (stock exceeded / item not found).

**Acceptance Criteria:**
- [ ] +/- buttons call `operation: "increment"` / `operation: "decrement"`
- [ ] Debounce rapid clicks (~300ms) to respect the 20 req/min limit
- [ ] Optimistic UI update; revert on error with toast
- [ ] When quantity would drop below 1 (decrement), remove the line (backend behavior)

---

## Task 5: Remove Item

**Priority:** Medium
**Story Points:** 2

**User Story:** "As a customer, I want to remove a single item from my cart."

**API Endpoint:** `DELETE /api/v1/cart/delete-item/{itemId}`

**Success:** HTTP 200 `{ success: true }`.

**Errors:** 400 (`DELETE_CART_ITEM_FAILED` — no cart / not owner / item missing / release failure).

**Acceptance Criteria:**
- [ ] Confirmation dialog before delete
- [ ] Optimistically remove the row; revert on error
- [ ] Update totals from refreshed cart (or recompute locally)

---

## Task 6: Clear Cart (with coupon confirmation)

**Priority:** Medium
**Story Points:** 3

**User Story:** "As a customer, I want to clear my entire cart after confirming."

**API Endpoint:** `DELETE /api/v1/cart/delete-items`
- **Optional body:** `{ "confirm": true }`

**Behavior:** ⚠️ **When a coupon is applied and `confirm` is not sent, the API returns HTTP 200 with `success: true` and a coupon-warning message.** It is NOT an HTTP error. The frontend must detect the warning (by message text / known applied coupon) and re-send with `{"confirm": true}` after the user confirms.

**Success:** HTTP 200 `{ success: true }`.

**Errors:** 404 (no cart), 400 (not owner).

**Acceptance Criteria:**
- [ ] "Clear Cart" opens a confirmation dialog
- [ ] If a coupon is applied, dialog warns and requires extra confirmation
- [ ] Detect coupon-warning by message, NOT by HTTP status
- [ ] Reset badge and local coupon state after clearing

---

## Task 7: Bulk Add — Wishlist / Buy Again

**Priority:** Low
**Story Points:** 2

**User Story:** "As a customer, I want to add many products to my cart at once and know which ones failed."

**API Endpoint:** `POST /api/v1/cart/bulk-items`
- **Auth:** Bearer token
- **Request body:** `{ "items": [ { "product_id": 10, "quantity": 2 }, ... ] }` — `shipping_method` optional (defaults to SCHEDULED).

**Success:** HTTP 201 with `{ cart, skipped_product_ids, failed_items }`.

**Acceptance Criteria:**
- [ ] Show a warning banner when `skipped_product_ids` is non-empty ("X products are no longer available")
- [ ] Show per-item errors from `failed_items` (product + reason)
- [ ] When `cart` is null (all items failed), show a clear failure message
- [ ] Banner dismissible; does not block the page

---

## Task 8: Cart — Loading, Empty, Error, Retry, Offline States

**Priority:** High
**Story Points:** 3

**Acceptance Criteria:**
- [ ] **Loading:** full-page skeleton on cart route mount
- [ ] **Empty:** illustration + "Browse Products" CTA
- [ ] **Error 400:** toast with the `message`
- [ ] **Error 422:** field-level inline errors
- [ ] **Error 401:** redirect to login with return URL
- [ ] **Error 429:** "Too many requests, please wait" + brief disable
- [ ] **Network/offline:** "Network error, please try again" + retry button; keep last known state
- [ ] **Expired cart:** banner "Your cart reservation has expired"

---

## Task 9: Coupon Display & Input

**Priority:** Medium
**Story Points:** 3

**User Story:** "As a customer, I want to apply a coupon and see the discount."

**API Endpoint (coupon module):** `POST /api/v1/coupons/add-to-cart` (apply), applied coupon returned in cart responses.

**Acceptance Criteria:**
- [ ] Coupon input with "Apply"
- [ ] Display applied coupon (name, code, images, border style from `coupon` object)
- [ ] Show `coupon_discount` and `total_after_coupon` breakdown
- [ ] Show coupon tag with "Remove"
- [ ] Re-render totals from the returned cart

---

## Task 10: Cart Responsive Design

**Priority:** Medium
**Story Points:** 3

**Acceptance Criteria:**
- [ ] Desktop (>1024px): items + summary side-by-side
- [ ] Tablet (768–1024px): stacked layout
- [ ] Mobile (<768px): single column, bottom-fixed summary bar
- [ ] Touch-friendly quantity buttons on mobile

---

## Risks

| Risk | Mitigation |
|------|------------|
| Rate limit (20 req/min) breaks quantity steppers | Debounce updates; batch operations |
| Coupon-warning uses HTTP 200 — could be misread as success | Detect by message / coupon presence, not status |
| Price is snapshotted at add time | Display cart `price`/`total_price` as-is; do not re-fetch product prices |
| Cart expiry removes items (3-day TTL) | Show countdown; handle expired cart state |
| Bulk add partial failure | Always surface `skipped_product_ids` + `failed_items` |

---

## Story Points Summary

| Task | Points |
|------|--------|
| 1. Cart page load & display | 8 |
| 2. Add to cart | 5 |
| 3. Header badge | 3 |
| 4. Quantity update | 3 |
| 5. Remove item | 2 |
| 6. Clear cart | 3 |
| 7. Bulk add | 2 |
| 8. States | 3 |
| 9. Coupon display | 3 |
| 10. Responsive | 3 |
| **Total** | **35** |
