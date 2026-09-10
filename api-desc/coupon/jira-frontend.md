# Coupon Module — Frontend Jira Tasks

## Task 1: Admin Coupon Listing Table

**Description:** Create admin table showing all coupons with columns: Code, Name, Discount, Type, Start Date, End Date, Used/Limiter, Status, Actions.

**Requirements:**
- Server-side pagination (page, limit)
- Search by code/name
- Filter by status
- Filter by validity (valid/invalid)
- Sortable columns
- Loading skeleton state
- Empty state when no coupons found

---

## Task 2: Admin Coupon Create/Edit Form

**Description:** Create form for creating and editing coupons.

**Fields:**
- `name` — multilingual (en, ar) text inputs
- `image-desktop` — file upload with preview
- `image-mobile` — file upload with preview
- `discount_type` — radio/select (percentage, fixed_rate, free_shipping)
- `discount` — number input
- `max_discount_amount` — number input (conditional: show when discount_type=percentage)
- `product_ids` — multi-select product search (optional)
- `start_date` — date picker
- `end_date` — date picker
- `limiter` — number input (optional)
- `status` — toggle switch
- `border_color` — color picker
- `borderless` — toggle switch

---

## Task 3: Dynamic Conditional Form Fields

**Description:** Form fields should dynamically show/hide based on selections:

- `discount_type = percentage` → show `max_discount_amount`
- `discount_type = free_shipping` → hide `discount`, `max_discount_amount`
- `discount_type = fixed_rate` → hide `max_discount_amount`

---

## Task 4: Public Coupon Banner Display

**Description:** Display active coupon banners on the homepage or promotions page.

**Data source:** `GET /api/v1/general/coupons`

**Display:**
- Responsive image (desktop/mobile)
- Border color accent from API
- Borderless style support
- Link to coupon details (if applicable)

---

## Task 5: Coupon Apply/Remove UI (Checkout)

**Description:** Add coupon input field in the checkout page.

**States:**
- Empty: Text input with "Apply" button
- Applying: Loading spinner on button
- Applied: Show applied coupon code + discount amount with "Remove" button
- Error: Show error message (invalid, expired, already applied, not eligible)
- Already applied: Show "Coupon already applied" toast

---

## Task 6: Cart Coupon Display

**Description:** Show applied coupon information in the cart summary.

- Applied coupon code and description
- Discount amount line item
- Remove coupon button

---

## Task 7: Delete Confirmation Dialog

**Description:** Add confirmation dialog before deleting a coupon.

- Modal: "Are you sure you want to delete this coupon?"
- Shows coupon code/name
- Confirm/Cancel buttons
- Loading state on delete

---

## Task 8: Loading, Empty, Error States

**Description:** Implement consistent states across all coupon UI components.

- **Loading:** Skeleton loaders for table rows, form fields, and card components
- **Empty:** Friendly empty state with illustration for "No coupons"
- **Error:** Inline error messages for API failures with retry option

---

## Task 9: Multilingual Translatable Fields

**Description:** Ensure all coupon name fields support bilingual input.

- Tab/segment toggle for language (en/ar)
- Send as JSON object `{"en": "...", "ar": "..."}`
- Display translated name based on current locale

---

## Task 10: Coupon Approval Workflow UI

**Description:** Admin UI for coupon approval/disapproval.

- Show approval status in table
- Approve/Disapprove action buttons for super admin
- Status badge (pending/approved/rejected)
