# Flash Sale Module — Frontend Jira Tasks

## Task 1: Public Homepage — Flash Sale Banner Section

**Priority:** High
**Component:** Frontend — Public Homepage
**Story Points:** 5

**Description:** Display active flash sale banners on the homepage with countdown timers.

**API Endpoints:**
- `GET /api/v1/general/flash-sales?limit=5`

**Acceptance Criteria:**
- [ ] Fetch active flash sales on mount via public API
- [ ] Display flash sale banners with desktop/mobile responsive images
- [ ] Show countdown timer to `end_date` for each active sale
- [ ] Each banner links to flash sale detail page (`/flash-sales/{slug}`)
- [ ] Show discount badge (e.g., "25% OFF", "$10 OFF", "Final Price $50")
- [ ] **Loading state:** Skeleton banner placeholders (2-3 items)
- [ ] **Empty state:** Hide section entirely if no active flash sales
- [ ] **Error state:** Hide section with console warning (non-critical content)
- [ ] Responsive: full-width banner on mobile, side-by-side on desktop

---

## Task 2: Public Flash Sale Detail Page — Products Grid

**Priority:** High
**Component:** Frontend — Public Flash Sale Page
**Story Points:** 5

**Description:** Build the flash sale detail page showing sale info and discounted products.

**API Endpoints:**
- `GET /api/v1/general/flash-sales/{slug}`

**Acceptance Criteria:**
- [ ] Display flash sale header: image, title, description, date range
- [ ] Prominent countdown timer to sale end date
- [ ] Product grid showing all associated products with discounted prices
- [ ] Show original price crossed out + flash sale price
- [ ] Show discount percentage or amount badge on each product
- [ ] Products link to product detail pages
- [ ] **Loading state:** Full page skeleton with header and product grid placeholders
- [ ] **Empty state (no products):** Show "No products in this sale yet"
- [ ] **Error state (404):** Show "Flash sale not found" page

---

## Task 3: Admin Flash Sale Listing Page — CRUD Table

**Priority:** High
**Component:** Frontend — Admin Flash Sales Page
**Story Points:** 8

**Description:** Build the admin flash sale management page with a data table.

**API Endpoints:**
- `GET /api/v1/flash-sale?page=&per_page=&search=&active=&inactive=&order=&sortedBy=`
- `PUT /api/v1/flash-sale/reorder`

**Acceptance Criteria:**
- [ ] Table with columns: order handle, image, title, type, discount, dates, status badge, validity badge, actions
- [ ] Pagination controls
- [ ] Search by title
- [ ] Filter toggles for active/inactive
- [ ] Sortable columns
- [ ] Drag-and-drop reorder
- [ ] **Loading state:** Skeleton table rows
- [ ] **Empty state:** "No flash sales yet" with "Create Flash Sale" CTA
- [ ] **Error state:** Error message with retry

---

## Task 4: Admin Flash Sale Create/Edit Form

**Priority:** High
**Component:** Frontend — Admin Flash Sale Form
**Story Points:** 8

**Description:** Build the create/edit form for flash sales with translatable fields, image uploads, date picker, discount type selector, and product association.

**API Endpoints:**
- `POST /api/v1/flash-sale` (create)
- `PUT /api/v1/flash-sale/{id}` (update)
- `GET /api/v1/flash-sale/{id}` (load existing)

**Acceptance Criteria:**
- [ ] Translatable fields: title, description with language tabs (en, ar)
- [ ] Date pickers for start_date and end_date
- [ ] Discount type selector (percentage, fixed_rate, final_price)
- [ ] Discount amount input
- [ ] Max discount amount field (shown only when type = percentage)
- [ ] Image uploads: desktop + mobile with preview (jpeg,png,jpg,webp)
- [ ] Product multi-select: searchable dropdown
- [ ] Status toggle
- [ ] Validation errors per field (422)
- [ ] Success redirect with toast

---

## Task 5: Admin Flash Sale — Countdown and Validity Indicators

**Priority:** Medium
**Component:** Frontend — Status Badges
**Story Points:** 3

**Description:** Visual indicators for flash sale status and validity across admin views.

**Acceptance Criteria:**
- [ ] Active sale: green "Active" badge + countdown
- [ ] Scheduled (future): blue "Scheduled" badge with start date
- [ ] Expired: gray "Expired" badge
- [ ] Invalid (status=0): red "Disabled" badge
- [ ] `is_valid` field used to determine current state
- [ ] Auto-refresh badges on page (no hard reload required)

---

## Task 6: Public Urgency Sections — Ending This Week / Today

**Priority:** Medium
**Component:** Frontend — Public Homepage
**Story Points:** 3

**Description:** Display urgency-driven product sections for flash sales ending soon.

**API Endpoints:**
- `GET /api/v1/general/flash-sale-products-ending-this-week`
- `GET /api/v1/general/flash-sale-products-ending-today`

**Acceptance Criteria:**
- [ ] "Ending Today" section with high urgency styling (red countdown)
- [ ] "Ending This Week" section with moderate urgency styling
- [ ] Product cards with discounted prices and countdown timers
- [ ] **Loading state:** Product skeleton cards
- [ ] **Empty state:** Hide section if no products ending soon
- [ ] **Error state:** Hide section with console warning

---

## Task 7: Admin Flash Sale — Product Pricing Preview

**Priority:** Medium
**Component:** Frontend — Pricing Display
**Story Points:** 3

**Description:** Show a preview of how product prices will be affected by the flash sale before saving.

**Acceptance Criteria:**
- [ ] When selecting products and discount, show calculated prices inline
- [ ] Show original price → flash sale price for each selected product
- [ ] For percentage type: show max_discount_amount cap applied
- [ ] For fixed_rate: show price - discount
- [ ] For final_price: show the final price directly
- [ ] Update preview in real-time as discount values change
