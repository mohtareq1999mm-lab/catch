# Flash Sale Module — Frontend Jira Tasks

---

## Task 1: Flash Sale Countdown Banner — Homepage

**Priority:** High
**Component:** Frontend — Public Homepage
**Story Points:** 5

**Description:** Display active flash sales as a countdown banner on the homepage with a timer showing remaining time.

**API Endpoint:**
- `GET /api/v1/general/flash-sales`

**Acceptance Criteria:**
- [ ] Fetch active flash sales on mount
- [ ] Display banner with flash sale name, description, and image
- [ ] Real-time countdown timer showing days/hours/minutes/seconds until end_date
- [ ] When timer reaches zero, automatically remove expired flash sale or show "Sale Ended"
- [ ] Click navigates to flash sale detail page (`/flash-sales/{slug}`)
- [ ] **Loading state:** Skeleton banner with shimmer
- [ ] **Empty state:** Hide banner entirely
- [ ] **Error state:** Hide with console warning

---

## Task 2: Flash Sale Detail Page

**Priority:** High
**Component:** Frontend — Public Flash Sale Page
**Story Points:** 5

**Description:** Build a flash sale detail page showing the campaign info and all associated products with discounted prices.

**API Endpoint:**
- `GET /api/v1/general/flash-sales/{slug}`

**Acceptance Criteria:**
- [ ] Hero section with flash sale image, name, and countdown
- [ ] Product grid showing associated products
- [ ] Product cards: image, name, original price (strikethrough), flash sale price, discount badge, rating
- [ ] "Flash Sale" badge on products
- [ ] Each product links to product detail page
- [ ] **Loading state:** Hero skeleton + product grid skeleton
- [ ] **Empty state (no products):** "No products in this flash sale" message
- [ ] **Error state (404):** "Flash sale not found" page
- [ ] **Error state (expired):** "This flash sale has ended" with "Browse Products" CTA

---

## Task 3: Flash Sale — "Ending Soon" Section

**Priority:** Medium
**Component:** Frontend — Public Homepage/Product Listing
**Story Points:** 3

**Description:** Display a section showing products from flash sales ending soon to create urgency.

**API Endpoints:**
- `GET /api/v1/general/flash-sale-products-ending-today`
- `GET /api/v1/general/flash-sale-products-ending-this-week`

**Acceptance Criteria:**
- [ ] "Ending Today" section with urgency styling (red timer, pulsing badge)
- [ ] "Ending This Week" section for less urgent items
- [ ] Product cards with countdown timer per product
- [ ] **Loading state:** Skeleton product cards
- [ ] **Empty state:** Hide section
- [ ] **Error state:** Hide with console warning

---

## Task 4: Flash Sale Product Badge

**Priority:** Medium
**Component:** Frontend — Product Card Component
**Story Points:** 2

**Description:** Show a "Flash Sale" badge on product cards when the product has an active flash sale.

**API Data:** Products enriched with `flash_sale_active: true` field

**Acceptance Criteria:**
- [ ] Product card shows "Flash Sale" badge when `flash_sale_active = true`
- [ ] Badge style: red/orange with lightning icon
- [ ] Original price shown with strikethrough, flash sale price prominent
- [ ] Badge animates/pulses subtly to draw attention
- [ ] **Loading state:** Badge hidden until product data loaded
- [ ] **No flash sale:** Regular price display, no badge

---

## Task 5: Flash Sale Components — Loading, Empty & Error States

**Priority:** High
**Component:** Frontend — State Handling
**Story Points:** 3

**Description:** Handle all non-happy-path states across flash sale components.

**Acceptance Criteria:**
- [ ] **Banner loading:** Full-width skeleton with countdown placeholder
- [ ] **Banner empty:** Section hidden, no layout shift
- [ ] **Banner error:** Hidden, console.warn
- [ ] **Detail loading:** Hero skeleton + 4 product card skeletons
- [ ] **Detail empty (not found):** 404 page
- [ ] **Detail empty (expired):** "Sale ended" page with browse CTA
- [ ] **Detail error:** Error with retry
- [ ] **Ending soon loading:** 4 skeleton product cards
- [ ] **Ending soon empty:** Section hidden
- [ ] **Ending soon error:** Hidden with console warning
