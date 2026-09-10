# Review Module — Frontend Jira Tasks

## Task 1: Product Detail Page — Review Section

**Priority:** High
**Component:** Frontend — Public Product Detail Page
**Story Points:** 8

**Description:** Build the review section on the product detail page showing existing reviews and a form to submit new reviews.

**API Endpoints:**
- `GET /api/v1/reviews?product_id={id}&limit=15&page=1`
- `POST /api/v1/reviews`

**Acceptance Criteria:**
- [ ] Review list displayed below product details with: user name, rating stars, comment, date
- [ ] Pagination or "Load more" for reviews (15 per page)
- [ ] Review form: star rating selector (1-5), comment textarea, submit button
- [ ] Form validation: rating required, comment required (client-side)
- [ ] On submit: show loading state, then success toast or inline error
- [ ] **Loading state:** Skeleton placeholders for review cards (3 items)
- [ ] **Empty state:** "No reviews yet. Be the first to review!" with CTA to write a review
- [ ] **Error state (401):** Show "Please log in to write a review" with login link
- [ ] **Error state (400):** "You have already reviewed this product" — disable submit
- [ ] **Error state (429):** "Too many requests. Please wait a moment."
- [ ] **Error state (422):** Field-level validation errors on the form
- [ ] Responsive: stack review cards vertically on mobile

---

## Task 2: Admin Review Management Page

**Priority:** High
**Component:** Frontend — Admin Reviews Page
**Story Points:** 5

**Description:** Build the admin review management page with a data table for listing, filtering, approving, and deleting reviews.

**API Endpoints:**
- `GET /api/v1/reviews?product_id={id}&limit=15&page=1`
- `DELETE /api/v1/reviews/{id}`
- `PATCH /api/v1/reviews/{id}/toggle-approve`

**Acceptance Criteria:**
- [ ] Table renders all reviews with columns: ID, product, user, rating, comment (truncated), approved badge, actions
- [ ] Filter by product (search/select product)
- [ ] Filter by approval status (approved/pending/all)
- [ ] Pagination controls
- [ ] Approve/Unapprove toggle button per row
- [ ] Delete button with confirmation dialog per row
- [ ] **Loading state:** Skeleton table rows (5 rows)
- [ ] **Empty state:** "No reviews found" for selected product
- [ ] **Error state:** Error message with "Retry" button
- [ ] **Delete confirmation:** Modal with review preview, confirm/cancel buttons
- [ ] **Optimistic toggle:** Update UI immediately, revert on error
- [ ] **Delete error:** Toast "Failed to delete review"

---

## Task 3: Review Approval Badge and Status Indicator

**Priority:** Medium
**Component:** Frontend — Badge Component
**Story Points:** 2

**Description:** Implement a visual indicator for review approval status in both admin and public views.

**Acceptance Criteria:**
- [ ] Approved reviews: green "Approved" badge
- [ ] Pending reviews: yellow/amber "Pending" badge
- [ ] Admin view: clickable badge to toggle approval
- [ ] Public view: only show approved reviews (filtered client-side if needed)
- [ ] `is_approved` field may be absent (if user lacks permission) — handle gracefully
- [ ] Animated transition when approval status changes

---

## Task 4: Star Rating Display and Input Component

**Priority:** Medium
**Component:** Frontend — Star Rating Component
**Story Points:** 3

**Description:** Build a reusable star rating component that supports both display (read-only) and input (interactive) modes.

**Acceptance Criteria:**
- [ ] Display mode: Shows filled/half-filled/empty stars based on rating value
- [ ] Input mode: Clickable stars (1-5) with hover effect
- [ ] Accessible: keyboard navigation (arrow keys, enter to select)
- [ ] Touch-friendly: works on mobile with tap
- [ ] Shows numeric value next to stars (e.g., "4.5 / 5")
- [ ] Submits integer value (1-5) in API request
- [ ] Visual feedback on hover (stars light up up to hovered position)

---

## Task 5: Review Form — Error and Success States

**Priority:** High
**Component:** Frontend — Form State Handling
**Story Points:** 3

**Description:** Handle all form states for the review submission form.

**Acceptance Criteria:**
- [ ] **Initial state:** Empty form with placeholder text
- [ ] **Typing state:** Real-time character count on comment field
- [ ] **Validation errors (422):** Inline field errors below rating and comment
- [ ] **Rate limited (429):** Show countdown timer "Too fast! Please wait X seconds"
- [ ] **Already reviewed (400):** Disable entire form, show message
- [ ] **Success:** Clear form, show success toast, append new review to list
- [ ] **Network error:** Toast "Connection error. Please try again."
- [ ] **Submit loading:** Disable button, show spinner, prevent double-submit
- [ ] **Max length:** Enforce max comment length (client + server)
