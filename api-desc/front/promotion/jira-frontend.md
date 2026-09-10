# Promotion Module — Frontend Jira Tasks

## Task 1: Public Promotion Banner Section (Homepage)

**Priority:** High
**Component:** Frontend — Public Homepage
**Story Points:** 3

**Description:** Display promotion banners on the homepage as a scrollable/horizontal section.

**API Endpoint:**
- `GET /api/v1/general/promotions?limit=10`

**Acceptance Criteria:**
- [ ] Fetch active promotions on mount
- [ ] Display promotion banners/cards with desktop/mobile image support
- [ ] Each banner links to promotion detail page (`/promotions/{slug}`)
- [ ] Show promotion name on the banner
- [ ] **Loading state:** Skeleton placeholders (2-3 banner shapes)
- [ ] **Empty state:** Hide section if no promotions returned
- [ ] **Error state:** Hide section with console warning (non-critical content)
- [ ] Responsive: use `image.desktop` for desktop, `image.mobile` for mobile

---

## Task 2: Public Promotion Detail Page

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
- [ ] **Empty state (no products):** Show promotion info with "No products available for this promotion" message
- [ ] **Error state (404):** Show "Promotion not found" page with link to promotions listing
- [ ] **Network error:** Show "Failed to load promotion" with retry button

---

## Task 3: Checkout — Eligible Promotions Panel

**Priority:** High
**Component:** Frontend — Checkout Page
**Story Points:** 5

**Description:** Show eligible promotions on the checkout page and allow the user to select one.

**API Endpoint:**
- `GET /api/v1/general/checkout/promotions`

**Acceptance Criteria:**
- [ ] Fetch eligible promotions when checkout loads
- [ ] Display promotion cards with: name, type, discount amount/value, gift items
- [ ] User can select one promotion via radio/select UI
- [ ] Selected promotion shows discount breakdown on order summary
- [ ] For gift promotions, show gift product selection dropdown
- [ ] Applied promotion is visible in cart summary
- [ ] **Loading state:** Skeleton for promotion cards (2-3 cards)
- [ ] **Empty state:** "No promotions available for this cart" message
- [ ] **Error state:** Toast with error, hide promotion section
- [ ] Changing cart items re-fetches eligible promotions

---

## Task 4: Checkout — Apply Promotion to Order

**Priority:** High
**Component:** Frontend — Checkout Flow
**Story Points:** 3

**Description:** Allow the user to apply a selected promotion when placing an order.

**API Endpoint:**
- `POST /api/v1/checkout`

**Acceptance Criteria:**
- [ ] Selected promotion ID sent in checkout request as `selected_promotion_id`
- [ ] Gift product ID sent as `selected_gift_product_id` for gift promotions
- [ ] Order totals reflect promotion discount:
  - `subtotal`, `promotion_discount`, `final_total`
- [ ] Gift items listed with "GIFT" badge and 0 price
- [ ] Promotion name displayed on order confirmation
- [ ] **Loading state:** Disable promotion selection during checkout submission
- [ ] **Error state:** Toast "Failed to apply promotion" if checkout fails

---

## Task 5: Cart — Promotion Display

**Priority:** Medium
**Component:** Frontend — Cart Page
**Story Points:** 3

**Description:** Show applied promotion details on the cart page, including per-item discounts and gift items.

**API Endpoints:**
- Cart GET endpoint (with promoted `CartItemResource`)

**Acceptance Criteria:**
- [ ] Cart page shows applied promotion name and discount amount
- [ ] Each cart item shows `discount_amount` if promotion is applied
- [ ] Gift items display with "GIFT" badge and 0 price
- [ ] Total calculation reflects promotion discount
- [ ] "Remove promotion" button to clear promotion from cart
- [ ] Cart modification refreshes promotion state
- [ ] **Loading state:** Skeleton cart with promotion section
- [ ] **Empty state:** Cart empty as usual (no promotion to show)

---

## Task 6: Admin Promotion Listing Page — CRUD Table

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
- [ ] Sortable columns (click header to sort)
- [ ] Each row shows edit/delete action buttons
- [ ] **Loading state:** Skeleton table rows (5 rows)
- [ ] **Empty state:** "No promotions found" with "Create Promotion" CTA
- [ ] **Error state:** Error message with "Retry" button

---

## Task 7: Admin Promotion Create/Edit Form

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
- [ ] Dynamic form sections based on type/type_amount selection:
  - `type=price + type_amount=percentage`: discount %, max_discount_amount, minimum_order_amount
  - `type=price + type_amount=fixed_rate`: discount value (EGP), minimum_order_amount
  - `type=quantity + type_amount=gift`: gift product config, required_quantity_type
- [ ] Image uploads: desktop and mobile with preview
- [ ] Product multi-select (conditional on `apply_to=specific_products`)
- [ ] Gift product configuration with variant and quantity (conditional on `type_amount=gift`)
- [ ] Date pickers for start_at and end_at
- [ ] Status toggle
- [ ] Form submits as `multipart/form-data`
- [ ] Validation errors displayed per field (422 response)
- [ ] **Loading state (edit):** Form skeleton while fetching promotion data
- [ ] **Error state:** Toast with error message
- [ ] Success redirect to listing with success toast
- [ ] Cancel button returns to listing

---

## Task 8: Admin Promotion — Product Association Multi-Select

**Priority:** Medium
**Component:** Frontend — Product Selector
**Story Points:** 3

**Description:** Build a searchable multi-select for associating products with a promotion (when `apply_to=specific_products`).

**API Endpoints:**
- `GET /api/v1/products?search=&limit=` (fetch products)
- `GET /api/v1/product-variants?product_id=&search=` (fetch variants for gift config)

**Acceptance Criteria:**
- [ ] Search input filters products by name
- [ ] Selected products shown as tags/chips with remove button
- [ ] On create/update, sends `product_ids` array of IDs
- [ ] For gift promotions, each product shows variant selector and quantity input
- [ ] Gift config sends `gift_products[0][product_id]`, `gift_products[0][product_variant_id]`, `gift_products[0][quantity]`
- [ ] On edit mode, pre-select already-associated products and gift configs
- [ ] **Loading state:** Spinner in search dropdown while fetching
- [ ] **Empty state:** "No products found" when search yields no results

---

## Task 9: Admin Promotion — Delete Confirmation

**Priority:** Medium
**Component:** Frontend — Delete Modal
**Story Points:** 2

**Description:** Implement confirmation dialog before deleting a promotion.

**API Endpoint:**
- `DELETE /api/v1/promotions/{id}`

**Acceptance Criteria:**
- [ ] Clicking delete opens confirmation modal
- [ ] Modal shows promotion name and warning text
- [ ] "Confirm" button submits DELETE request
- [ ] "Cancel" closes modal
- [ ] Loading spinner on confirm during deletion
- [ ] On success: remove row from table with success toast
- [ ] On error: show error toast, keep modal open
- [ ] Disable confirm button during API call to prevent double-submit

---

## Task 10: Admin Promotion — Loading, Empty & Error States

**Priority:** High
**Component:** Frontend — State Handling
**Story Points:** 3

**Description:** Handle all non-happy-path states across the promotion admin pages.

**Acceptance Criteria:**
- [ ] **Listing loading:** Skeleton table rows (5 rows)
- [ ] **Listing empty:** "No promotions yet" with "Create your first promotion" button
- [ ] **Listing error:** Error message with "Retry" button
- [ ] **Form loading (edit):** Form skeleton while fetching promotion data
- [ ] **Form validation:** Inline field errors from 422 response, per-language for name
- [ ] **Form error:** Toast with error message
- [ ] **Delete error:** Toast "Failed to delete promotion"
- [ ] **Network error:** "Network error, please try again" for all API calls

---

## Task 11: Admin Promotion — Multilingual Translatable Fields

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
- [ ] Each locale shows separate input for name
- [ ] On save, serialize to `{ "en": "...", "ar": "..." }` format
- [ ] On load, each tab shows correct translation
- [ ] Default language tab pre-selected
- [ ] Validation errors shown per-language (e.g., `name.en` error displays on English tab)
