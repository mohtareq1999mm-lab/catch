# Brand Module — Frontend Jira Tasks

---

## Task 1: Brand Listing Page — Grid with Filters

**Priority:** High
**Component:** Frontend — Public Brand Listing
**Story Points:** 5

**Description:** Build the brand listing page showing all active brands as a grid of logo cards.

**API Endpoint:**
- `GET /api/v1/general/brands`

**Acceptance Criteria:**
- [ ] Grid layout showing brand logo/image and name
- [ ] Each card links to brand detail page (`/brands/{slug}`)
- [ ] Search bar filters by brand name
- [ ] Sort dropdown (A-Z, Z-A, newest)
- [ ] Responsive: 2 cols mobile, 4 cols tablet, 6 cols desktop
- [ ] **Loading state:** Skeleton cards grid
- [ ] **Empty state:** "No brands found" with illustration
- [ ] **Error state:** Error message with retry button
- [ ] **Image fallback:** Placeholder if brand image missing
- [ ] **Image error:** Broken image fallback

---

## Task 2: Brand Detail Page — Brand Info + Product Grid

**Priority:** High
**Component:** Frontend — Public Brand Detail
**Story Points:** 5

**Description:** Build the brand detail page showing brand information and associated products.

**API Endpoint:**
- `GET /api/v1/general/brands/{slug}`

**Acceptance Criteria:**
- [ ] Hero section with brand image and name
- [ ] Product grid below brand info showing all associated products
- [ ] Product cards: image, name, price, price_after_discount (if discounted), rating stars
- [ ] Each product links to product detail page (`/products/{slug}`)
- [ ] **Loading state:** Brand skeleton + product grid skeleton
- [ ] **Empty state (no products):** "No products from this brand yet" with "Browse All Products" CTA
- [ ] **Error state (404):** "Brand not found" page with link to brand listing
- [ ] **Error state (network):** Error message with retry button
- [ ] **Image fallback:** Placeholder for brand and product images

---

## Task 3: Homepage Brand Strip — Horizontal Scrolling

**Priority:** Medium
**Component:** Frontend — Public Homepage
**Story Points:** 3

**Description:** Display a horizontal scrolling strip of brand logos on the homepage.

**API Endpoint:**
- `GET /api/v1/general/brands?limit=8`

**Acceptance Criteria:**
- [ ] Horizontal scrollable strip of brand logos
- [ ] Auto-scroll with pause on hover
- [ ] Each logo links to brand detail page
- [ ] Left/right arrow navigation buttons
- [ ] Responsive: smaller logos on mobile
- [ ] **Loading state:** Skeleton brand circles
- [ ] **Empty state:** Hide the section entirely
- [ ] **Error state:** Hide section with console warning
- [ ] **Image fallback:** Circular placeholder

---

## Task 4: "Shop by Brand" Section — Products by Brand

**Priority:** Medium
**Component:** Frontend — Public Homepage
**Story Points:** 5

**Description:** Display a curated "Shop by Brand" section showing products from top brands.

**API Endpoint:**
- `GET /api/v1/general/brands-products?limit=4&limit_brand=6`

**Acceptance Criteria:**
- [ ] Section heading "Shop by Brand" or "Top Brands"
- [ ] Shows products grouped visually by brand
- [ ] Each product card shows: image, name, price, discount badge
- [ ] Products link to product detail page
- [ ] **Loading state:** Skeleton product grid
- [ ] **Empty state:** Hide section
- [ ] **Error state:** Hide section with console warning
- [ ] Responsive: 2 cols mobile, 3 cols tablet, 4 cols desktop

---

## Task 5: Brand Filter Dropdown — Product Listing Page

**Priority:** Medium
**Component:** Frontend — Product Listing Filters
**Story Points:** 3

**Description:** Add a brand filter dropdown to the product listing page.

**API Endpoint:**
- `GET /api/v1/general/brands` (populate dropdown)

**Acceptance Criteria:**
- [ ] Dropdown select showing brand names
- [ ] "All Brands" default option
- [ ] Selecting a brand filters products on the listing page
- [ ] Dropdown shows brand logo thumbnail next to name
- [ ] **Loading state:** Disabled dropdown with "Loading brands..."
- [ ] **Empty state:** Dropdown hidden (no brands available)
- [ ] **Error state:** Dropdown disabled with error tooltip

---

## Task 6: Brand Pages — Loading, Empty & Error States

**Priority:** High
**Component:** Frontend — State Handling
**Story Points:** 3

**Description:** Handle all non-happy-path states across all brand-related pages and components.

**Acceptance Criteria:**
- [ ] **Listing loading:** 6-8 skeleton cards with shimmer
- [ ] **Listing empty:** "No brands yet" with illustration and "Browse Products" CTA
- [ ] **Listing error:** Error banner with "Retry" button
- [ ] **Listing search empty:** "No brands match your search" with clear search button
- [ ] **Detail loading:** Brand skeleton header + 4 product card skeletons
- [ ] **Detail empty (no products):** Brand info shown + "No products" message
- [ ] **Detail error (404):** Full 404 page with "Back to Brands" link
- [ ] **Detail error (network):** Error message with retry
- [ ] **Image loading:** Shimmer placeholder while loading
- [ ] **Image error:** Fallback/placeholder icon
- [ ] **Filter dropdown loading:** "Loading..." disabled state
- [ ] **Filter dropdown error:** "Unable to load brands" with retry link
