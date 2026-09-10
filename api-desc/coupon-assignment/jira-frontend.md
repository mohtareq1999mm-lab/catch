# Coupon Assignment — Frontend Jira Tasks

---

## Task 1: Coupon Edit Page — Assignments Section

**Priority:** High
**Component:** Frontend — Admin Coupon Edit Page
**Story Points:** 8

**Description:** Add a "User Assignments" section to the coupon edit/create page. This section lists all users assigned to the coupon, with their usage quota and controls to add/edit/remove assignments.

**API Endpoints:**
- `GET /api/v1/coupons/{couponId}/assignments?limit=15&page=1`
- `POST /api/v1/coupons/{couponId}/assignments`
- `PUT /api/v1/coupons/{couponId}/assignments/{id}`
- `DELETE /api/v1/coupons/{couponId}/assignments/{id}`

**Acceptance Criteria:**
- [ ] Section appears below coupon details on the admin coupon edit page
- [ ] Only visible to users with `super_admin` role
- [ ] Action buttons respect individual permissions (view/create/update/delete)
- [ ] Table columns: User (name + email), Max Uses, Used, Remaining, Expires At, Status, Actions
- [ ] Status column: green "Active" badge, red "Expired" badge (based on `is_expired`)
- [ ] Remaining column: shows remaining count, turns red when 0
- [ ] Pagination controls if more than 15 assignments
- [ ] **Loading state:** Skeleton table (5 rows) while fetching
- [ ] **Empty state:** "This coupon is public — no user restrictions. Add users below to make it restricted."
- [ ] **Error state:** Toast "Failed to load assignments" with retry button
- [ ] Responsive: stack cards on mobile (user info, usage, expiry per card)

---

## Task 2: Add Assignment Form — User Selector and Validation

**Priority:** High
**Component:** Frontend — Add Assignment Modal/Form
**Story Points:** 5

**Description:** Build a form to assign a coupon to a user. Opens as a modal or inline form with user search, max_uses input, and optional expiry date picker.

**API Endpoint:** `POST /api/v1/coupons/{couponId}/assignments`

**Acceptance Criteria:**
- [ ] User search input: typeahead/autocomplete that searches admin users
- [ ] Show user name and email in the dropdown results
- [ ] After selection, show selected user avatar/name/email with "Remove" button
- [ ] Max Uses: number input (min: 1, default: 1)
- [ ] Expires At: optional date picker with "No expiry" toggle
- [ ] Date picker only allows future dates
- [ ] Client-side validation before submit
- [ ] **Loading state:** Submit button shows spinner, inputs disabled
- [ ] **Error state (422):** Inline field errors (user not found, max_uses required, date in past)
- [ ] **Error state (409):** "This user is already assigned to this coupon" — inline error on user selector
- [ ] **Error state (404):** Toast "Coupon not found"
- [ ] **Success:** Close modal, reset form, append new row to table, show toast "User assigned successfully"
- [ ] **Network error:** Toast "Connection error. Please try again."

---

## Task 3: Edit Assignment Modal — Update Quota and Expiry

**Priority:** Medium
**Component:** Frontend — Edit Assignment Modal
**Story Points:** 3

**Description:** Build an edit modal to update max_uses and expires_at for an existing assignment.

**API Endpoint:** `PUT /api/v1/coupons/{couponId}/assignments/{id}`

**Acceptance Criteria:**
- [ ] Row action: edit icon/button opens modal
- [ ] Pre-filled with current max_uses and expires_at values
- [ ] Max Uses: number input, must be >= current `used` value
- [ ] Expires At: date picker with "No expiry" toggle (set to null)
- [ ] Client-side: if max_uses < used, show inline error immediately
- [ ] **Loading state:** Form fields disabled during submit, button shows spinner
- [ ] **Error state (422):** "max_uses cannot be less than current usage count"
- [ ] **Error state (404):** Toast "Assignment not found"
- [ ] **Success:** Close modal, update row in table, toast "Assignment updated successfully"
- [ ] **Optimistic update:** Update remaining count immediately in UI, revert on error

---

## Task 4: Delete Assignment — Confirmation and Blocked States

**Priority:** Medium
**Component:** Frontend — Delete Confirmation Dialog
**Story Points:** 3

**Description:** Handle assignment deletion with two scenarios: allowed (used = 0) and blocked (used > 0).

**API Endpoint:** `DELETE /api/v1/coupons/{couponId}/assignments/{id}`

**Acceptance Criteria:**
- [ ] Row action: delete icon/button opens confirmation dialog
- [ ] Confirmation shows: user name, max_uses, used count
- [ ] If `used = 0`: "Are you sure you want to remove this assignment?" with confirm/cancel
- [ ] If `used > 0`: Delete button is **disabled** with tooltip "Cannot delete — user has already used this coupon"
- [ ] **Loading state:** Confirm button shows spinner
- [ ] **Error state (409):** If another request increased `used` between page load and delete attempt, show "Cannot delete assignment with usage history"
- [ ] **Success:** Remove row from table, show toast "Assignment removed successfully"
- [ ] **Undo:** Provide a brief "Undo" button in the success toast that reloads the list

---

## Task 5: Assignment Status Indicators and Alerts

**Priority:** Low
**Component:** Frontend — Status Badge Component
**Story Points:** 2

**Description:** Implement visual status indicators for each assignment row: usage progress, expiry status, and bulk actions.

**Acceptance Criteria:**
- [ ] **Usage progress bar:** Visual bar showing used/max_uses ratio (green < 80%, orange 80-99%, red = 100%)
- [ ] **Expired badge:** Red "Expired" badge when `is_expired = true`
- [ ] **Expiring soon badge:** Yellow "Expiring soon" when expires within 7 days
- [ ] **Fully used badge:** Gray "Exhausted" badge when `remaining = 0`
- [ ] **Active badge:** Green "Active" badge when `remaining > 0` and not expired
- [ ] **Bulk actions:** Select multiple assignments and "Remove Selected" (only if all have `used = 0`)
- [ ] **Summary bar:** At top of section: "X of Y assignments active — Z users can use this coupon"
- [ ] **Public indicator:** When assignments list is empty, show prominent "This coupon is PUBLIC — no restrictions" alert
