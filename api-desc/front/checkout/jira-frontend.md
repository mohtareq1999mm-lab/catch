# Checkout Module — Frontend Jira Tasks

---

## Task 1: Checkout Form Page

**Priority:** High
**Component:** Frontend — Checkout
**Story Points:** 13

**Description:** Build the complete checkout form.

**API:** POST /api/v1/general/checkout, GET /api/v1/general/checkout/promotions

**Acceptance Criteria:**
- [ ] Contact info: name, phone, email (pre-filled)
- [ ] Address field (free-form array)
- [ ] Notes textarea
- [ ] Fulfillment type: Delivery or Pickup
- [ ] Delivery → governorate dropdown with shipping fee
- [ ] Pickup → location selector (from pickLocation module)
- [ ] Payment method: online, COD, pay_at_cashier
- [ ] Order summary: subtotal, promotions, coupon, shipping, total
- [ ] Promotions section with gift product selection
- [ ] Submit button with loading state
- [ ] Handle 400 "Minimum order amount" error — show banner above form
- [ ] **Loading:** Full form skeleton
- [ ] **Error:** Inline validation on each field
- [ ] **Cart expired:** "Your cart has expired" with CTA

---

## Task 2: Online Payment Redirect

**Priority:** High
**Component:** Frontend — Payment
**Story Points:** 3

**Description:** Handle gateway redirect.

**Acceptance Criteria:**
- [ ] Redirect to payment URL from response
- [ ] "Redirecting to payment..." spinner
- [ ] Success/failure pages from callbacks
- [ ] Success page: order ID, confirmation
- [ ] Failed page: error, "Try again"

---

## Task 3: COD Success Page

**Priority:** Medium
**Component:** Frontend — Checkout
**Story Points:** 2

**Acceptance Criteria:**
- [ ] Order ID displayed
- [ ] "Pay on delivery" badge
- [ ] Order summary

---

## Task 4: Pay at Cashier Success Page

**Priority:** Medium
**Component:** Frontend — Checkout
**Story Points:** 2

**Description:** The QR code feature was removed from Pay at Cashier. Checkout returns only `order_id`; the customer pays at the store and the cashier settles the order in the admin panel.

**Acceptance Criteria:**
- [ ] Order ID displayed
- [ ] "Pay at the store" badge
- [ ] Payment stays "pending" until the cashier marks it paid (`payment-cash`)
- [ ] No QR code rendered or fetched — there is no `transaction-qr` endpoint
- [ ] **Loading:** Order confirmation placeholder
- [ ] **Error:** "Failed to place order"

---

## Task 5: Payment Callback Pages

**Priority:** High
**Component:** Frontend — Pages
**Story Points:** 5

**Acceptance Criteria:**
- [ ] Success page: checkmark, order ID, "View Order"
- [ ] Failed page: X icon, error message, "Try Again"
- [ ] Handle missing query params gracefully
