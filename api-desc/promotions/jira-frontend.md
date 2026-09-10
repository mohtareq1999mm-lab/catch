# Promotion Module — Frontend Jira Tasks

## Task 1: Admin Promotion Listing Page — CRUD Table

**Priority:** High
**Component:** Frontend — Admin Promotions Page
**Story Points:** 8

**Description:** Build the admin promotion management page with a data table listing all promotions.

**API Endpoints:**
- `GET /api/v1/promotions?page=&limit=&search=&status=&type=&type_amount=`

**Acceptance Criteria:**
- [ ] Table renders all promotions with columns: image (thumbnail), name, code, type, discount_type, status badge, validity indicator, actions
- [ ] Pagination controls (page size selector, prev/next, page numbers)
- [ ] Search field filters by name, code, or type
- [ ] Filter dropdowns for type (price/quantity), type_amount (fixed_rate/percentage/gift), status
- [ ] Sortable columns
- [ ] Each row shows edit/delete action buttons
- [ ] Loading skeleton while fetching
- [ ] Empty state: "No promotions found" with "Create Promotion" CTA

---

## Task 2: Admin Promotion Create/Edit Form

**Priority:** High
**Component:** Frontend — Admin Promotion Form
**Story Points:** 8

**Description:** Build the create/edit form for promotions with translatable fields, image uploads, product association, and gift product configuration.

**API Endpoints:**
- `POST /api/v1/promotions` (create)
- `PUT /api/v1/promotions/{id}` (update)
- `GET /api/v1/promotions/{id}` (load existing data for edit)

**Acceptance Criteria:**
- [ ] Create mode: empty form with all fields
- [ ] Edit mode: form pre-filled from `GET /api/v1/promotions/{id}`
- [ ] Translatable `name` field with language tabs (en, ar)
- [ ] Type selector: price vs quantity (changes available fields)
- [ ] Type Amount selector: fixed_rate, percentage, gift (changes available fields)
- [ ] Dynamic form sections based on type/type_amount selection
- [ ] Image uploads: desktop and mobile with preview
- [ ] Product multi-select (conditional on apply_to=specific_products)
- [ ] Gift product configuration (conditional on type_amount=gift)
- [ ] Date pickers for start_at and end_at
- [ ] Status toggle
- [ ] Form submits as `multipart/form-data`
- [ ] Validation errors displayed per field (422 response)
- [ ] Success redirect to listing with toast

---

## Task 3: Admin Promotion — Dynamic Form Based on Type

**Priority:** High
**Component:** Frontend — Conditional Fields
**Story Points:** 5

**Description:** Implement form field visibility based on promotion type selection.

**Rules:**
- **type=price + type_amount=percentage:** Show discount %, max_discount_amount, minimum_order_amount
- **type=price + type_amount=fixed_rate:** Show discount value (EGP), minimum_order_amount
- **type=quantity + type_amount=gift:** Hide discount, show required_quantity_type, gift_products
- **apply_to=all_products:** Hide product_ids
- **apply_to=specific_products:** Show product_ids multi-select

**Acceptance Criteria:**
- [ ] Selecting type dynamically shows/hides relevant fields
- [ ] Required indicators update based on selection
- [ ] Validation rules match server-side requirements
- [ ] Switching type clears non-applicable field values

---

## Task 4: Public Promotion Banners Section

**Priority:** High
**Component:** Frontend — Public Promotions
**Story Points:** 3

**Description:** Display promotion banners on the homepage or promotion listing page.

**API Endpoint:**
- `GET /api/v1/general/promotions?limit=10`

**Acceptance Criteria:**
- [ ] Fetch valid promotions on mount
- [ ] Display promotion banners/cards with desktop/mobile image support
- [ ] Each banner links to promotion detail page (`/promotions/{slug}`)
- [ ] Show promotion name on the banner
- [ ] **Loading state:** Show skeleton placeholders (2-3 banner shapes)
- [ ] **Empty state:** Hide section if no promotions
- [ ] **Error state:** Hide section with console warning
- [ ] Responsive: different image for desktop vs mobile

---

## Task 5: Public Promotion Detail Page

**Priority:** High
**Component:** Frontend — Public Promotion Page
**Story Points:** 5

**Description:** Build the public promotion detail page showing promotion info and associated products.

**API Endpoint:**
- `GET /api/v1/general/promotions/{slug}`

**Acceptance Criteria:**
- [ ] Page loads promotion info (name, images)
- [ ] Display desktop/mobile images with responsive handling
- [ ] Show product grid of associated products
- [ ] Product cards show: image, name, price, discount badge
- [ ] Products link to product detail pages
- [ ] **Loading state:** Page skeleton with product grid placeholders
- [ ] **Empty state (no products):** Show promotion info with "No products available"
- [ ] **Error state (404):** Show "Promotion not found" page

---

## Task 6: Checkout — Promotion Selection UI

**Priority:** High
**Component:** Frontend — Checkout Page
**Story Points:** 5

**Description:** Show eligible promotions on the checkout page and allow the user to select one.

**API Endpoint:**
- `GET /api/v1/checkout/promotions`

**Acceptance Criteria:**
- [ ] Fetch eligible promotions when checkout loads
- [ ] Display promotion cards with: name, type, discount amount/value, gift items
- [ ] User can select one promotion (radio/select UI)
- [ ] Selected promotion shows discount breakdown on order summary
- [ ] For gift promotions, show gift product selection
- [ ] Applied promotion is visible in cart summary
- [ ] **Loading state:** Skeleton for promotion cards
- [ ] **Empty state:** "No promotions available for this cart"
- [ ] **Error state:** Toast with error, hide promotion section
- [ ] Changing cart items re-fetches eligible promotions

---

## Task 7: Cart — Promotion Display

**Priority:** Medium
**Component:** Frontend — Cart Page
**Story Points:** 3

**Description:** Show applied promotion details on the cart page, including per-item discounts and gift items.

**API Endpoints:**
- Cart GET endpoint (with promoted `CartItemResource`)

**Acceptance Criteria:**
- [ ] Cart page shows applied promotion name and discount
- [ ] Each cart item shows discount_amount if promotion is applied
- [ ] Gift items display with "GIFT" badge and 0 price
- [ ] Total calculation reflects promotion discount
- [ ] "Remove promotion" button to clear promotion from cart
- [ ] Cart modification refreshes promotion state

---

## Task 8: Admin Promotion — Delete Confirmation

**Priority:** Medium
**Component:** Frontend — Delete Modal
**Story Points:** 2

**Description:** Implement confirmation dialog before deleting a promotion.

**API Endpoint:**
- `DELETE /api/v1/promotions/{id}`

**Acceptance Criteria:**
- [ ] Clicking delete opens confirmation modal
- [ ] Modal shows promotion name and warning
- [ ] "Confirm" submits DELETE request
- [ ] "Cancel" closes modal
- [ ] Loading spinner on confirm during deletion
- [ ] On success: remove row with success toast
- [ ] On error: show error toast, keep modal open

---

## Task 9: Admin Promotion — Loading, Empty & Error States

**Priority:** High
**Component:** Frontend — State Handling
**Story Points:** 3

**Description:** Handle all non-happy-path states across the promotion admin pages.

**Acceptance Criteria:**
- [ ] **Listing loading:** Skeleton table rows
- [ ] **Listing empty:** "No promotions yet" with "Create" button
- [ ] **Listing error:** Error message with "Retry" button
- [ ] **Form loading (edit):** Form skeleton while fetching
- [ ] **Form error:** Toast with error message
- [ ] **Form validation:** Inline field errors from 422 response
- [ ] **Delete error:** Toast "Failed to delete promotion"
- [ ] **Network error:** Toast for all API calls

---

## Task 10: Admin Promotion — Multilingual Fields

**Priority:** Medium
**Component:** Frontend — i18n
**Story Points:** 2

**Description:** Handle translatable `name` field sent/received as language-keyed object.

**Format:**
```json
{ "en": "Summer Sale", "ar": "تخفيضات الصيف" }
```

**Acceptance Criteria:**
- [ ] Language tabs for each supported locale
- [ ] Each locale shows separate input
- [ ] On save, serialize to `{ "en": "...", "ar": "..." }`
- [ ] On load, each tab shows correct translation
- [ ] Validation errors shown per-language (e.g., `name.en`)
