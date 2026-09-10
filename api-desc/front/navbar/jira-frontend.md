# Navigation Bar — Frontend Jira Tasks

---

## Task 1: Public Navbar — Mega Menu with Category Tree

**Priority:** High
**Component:** Frontend — Public Navbar
**Story Points:** 8

**Description:** Build the main navigation bar that displays the hierarchical category tree as a mega-menu with multi-level dropdowns.

**API Endpoint:**
- `GET /api/v1/general/nav-data?level=3`

**Acceptance Criteria:**
- [ ] Fetch nav-data on app mount (cached client-side for session duration)
- [ ] Render top-level categories (`level: 1`) as main nav items
- [ ] Hover/click on a main item shows a mega-dropdown with children (`level: 2`) as columns
- [ ] Each column shows grandchildren (`level: 3`) as links
- [ ] Each category link routes to `/categories/{slug}`
- [ ] Show category image/icon alongside name (use `image.desktop` URL)
- [ ] **Loading state:** Skeleton nav items (4-5 grey bars)
- [ ] **Empty state (no categories):** Hide entire navigation bar section
- [ ] **Error state:** Hide nav with console warning (non-critical UI — site still works)
- [ ] Responsive: mobile hamburger menu shows full tree as accordion
- [ ] Desktop: show on hover, hide on mouse leave (with 300ms delay to prevent accidental close)
- [ ] Active/current category highlighted in nav
- [ ] Keyboard navigation: Tab through items, Enter to open dropdown, Escape to close

---

## Task 2: Mobile Navigation — Hamburger Menu with Accordion

**Priority:** High
**Component:** Frontend — Mobile Nav
**Story Points:** 5

**Description:** Build the mobile-responsive hamburger menu that renders the full category tree as an accordion with expand/collapse.

**API Endpoint:**
- `GET /api/v1/general/nav-data?level=3`

**Acceptance Criteria:**
- [ ] Hamburger icon visible on mobile (breakpoint < 768px)
- [ ] Click opens full-screen or slide-in drawer from left
- [ ] Top-level categories shown as accordion headers
- [ ] Click accordion header expands to show children (indented)
- [ ] Third level also expandable within second level
- [ ] Each category links to `/categories/{slug}`
- [ ] Close button or swipe to close
- [ ] Backdrop overlay when menu is open
- [ ] **Loading state:** Skeleton accordion items
- [ ] **Empty state:** "Shop by Category" section hidden, show "All Products" link instead
- [ ] **Error state:** Menu shows "Browse Categories" fallback link to all categories page

---

## Task 3: Navbar — Category Image Display

**Priority:** Medium
**Component:** Frontend — Navbar Images
**Story Points:** 3

**Description:** Display category images in the navbar mega-menu. Each `image.desktop` URL should be shown as a thumbnail next to the category name.

**Acceptance Criteria:**
- [ ] Thumbnail image shown next to each top-level category name in nav bar
- [ ] Column headers in mega-dropdown show image thumbnail
- [ ] Lazy load images (Intersection Observer)
- [ ] Fallback to placeholder/default icon if `image.desktop` is null
- [ ] Handle broken image URLs gracefully (show placeholder)
- [ ] Optimized: use responsive image sizes for different viewports
- [ ] **Loading state:** Placeholder skeleton circle/square while image loads
- [ ] **Error state:** Image load failure shows fallback icon

---

## Task 4: Category Sidebar — Expandable Tree Navigation

**Priority:** Medium
**Component:** Frontend — Category Page Sidebar
**Story Points:** 5

**Description:** Build a sidebar category navigation for category listing pages with expandable tree and active state tracking.

**API Endpoint:**
- `GET /api/v1/general/nav-data?level=3`

**Acceptance Criteria:**
- [ ] Sidebar shows full category tree (same nav-data endpoint)
- [ ] Expand/collapse chevron icon per parent category
- [ ] Active category (current page) highlighted with accent color
- [ ] Parent of active category auto-expanded
- [ ] Children indented relative to parent
- [ ] Product count badge next to category name (if available from response)
- [ ] Sticky positioning on scroll
- [ ] **Loading state:** Skeleton tree structure
- [ ] **Empty state:** Sidebar hidden entirely, products fill full width
- [ ] **Error state:** Sidebar shows "Categories unavailable" with retry link

---

## Task 5: Navbar — Loading, Empty & Error States

**Priority:** High
**Component:** Frontend — State Handling
**Story Points:** 3

**Description:** Handle all non-happy-path states across all navbar components.

**Acceptance Criteria:**
- [ ] **Navbar loading:** 4-5 skeleton nav items (grey shimmer bars matching nav item width)
- [ ] **Navbar empty:** Nav hides categories section, shows only logo + cart + account icons
- [ ] **Navbar error:** Categories section hidden, console.warn logged (non-critical)
- [ ] **Mobile loading:** Skeleton accordion items with 3-4 nested shimmer bars
- [ ] **Mobile empty:** Hamburger menu shows only "All Categories" link to `/categories`
- [ ] **Mobile error:** Hamburger shows "Browse All Categories" fallback link
- [ ] **Sidebar loading:** Skeleton tree with 5-6 indented shimmer lines
- [ ] **Sidebar empty:** Sidebar hidden, main content uses full width
- [ ] **Sidebar error:** Error message with "Retry" CTA
- [ ] **Image loading:** Circular skeleton placeholder while image loads
- [ ] **Image error:** Fallback icon on broken image
- [ ] **Network error (all components):** Toast "Network error" on retry failure

---

## Task 6: Navbar — Client-Side Caching and Performance

**Priority:** Medium
**Component:** Frontend — Performance
**Story Points:** 3

**Description:** Implement client-side caching for the nav-data response to avoid redundant API calls and improve perceived performance.

**API Endpoint:**
- `GET /api/v1/general/nav-data?level=3`

**Acceptance Criteria:**
- [ ] Cache nav-data response in memory/sessionStorage on first load
- [ ] Do not re-fetch within same browser session (or TTL 5 minutes)
- [ ] Cache busting: re-fetch on page refresh (or after TTL expires)
- [ ] Avoid layout shift: reserve nav bar height before data loads
- [ ] Preload nav-data request early in page load (or link rel=preload)
- [ ] **Cache miss:** Show skeleton, fetch from API, populate nav
- [ ] **Cache hit:** Instant render from cache, no loading state shown
- [ ] **Stale cache (after TTL):** Show cached data immediately, background refresh

---

## Task 7: Navbar — Breadcrumb Navigation from Category Tree

**Priority:** Medium
**Component:** Frontend — Breadcrumbs
**Story Points:** 3

**Description:** Build breadcrumb navigation on category detail pages by traversing the nav-data tree to find the current category's ancestors.

**API Endpoint:**
- `GET /api/v1/general/nav-data?level=3` (walk the tree client-side)

**Acceptance Criteria:**
- [ ] On category detail page, find the current category in cached nav-data tree
- [ ] Walk up parents to build breadcrumb: Home > Electronics > Laptops > Gaming
- [ ] Each breadcrumb segment links to the respective category page
- [ ] Last segment (current page) is plain text (not linked)
- [ ] **Loading state:** Skeleton breadcrumb (3 grey bars separated by "/" )
- [ ] **Empty state (category not found in tree):** Show just "Home" > "Category Name"
- [ ] **Error state (nav-data failed):** Show "Home" > "Category Name" without hierarchy

---

## Task 8: Navbar — Multi-Language Category Names

**Priority:** Medium
**Component:** Frontend — i18n
**Story Points:** 2

**Description:** Handle bilingual category names throughout all navbar and sidebar components.

**API Response Format:**
```json
{
  "name": "Electronics"
}
```
Names arrive already translated by the backend based on `app()->getLocale()`.

**Acceptance Criteria:**
- [ ] Verify nav-data endpoint respects `Accept-Language` header
- [ ] Arabic locale: all category names show Arabic text
- [ ] English locale: all category names show English text
- [ ] No hardcoded category names in frontend code
- [ ] RTL support for Arabic: nav items, dropdowns, sidebar aligned correctly
- [ ] Mixed content: numbers/prices display correctly in RTL layout
