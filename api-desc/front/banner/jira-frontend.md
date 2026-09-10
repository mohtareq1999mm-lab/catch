# Banner Module — Frontend Jira Tasks

---

## Task 1: Homepage Hero Carousel

**Priority:** High
**Component:** Frontend — Public Homepage
**Story Points:** 8

**Description:** Build the main hero carousel/slider on the homepage that displays active promotional banners.

**API Endpoint:**
- `GET /api/v1/general/banners`

**Acceptance Criteria:**
- [ ] Fetch banners on mount
- [ ] Full-width hero slider with auto-play (5s interval)
- [ ] Navigation dots and prev/next arrows
- [ ] Pause on hover
- [ ] Responsive image switching: `image.desktop` for desktop, `image.mobile` for mobile
- [ ] Banner title and description overlay on each slide
- [ ] Click on banner navigates to associated products or banner slug page
- [ ] Fade or slide transition animation
- [ ] **Loading state:** Full-width skeleton shimmer (aspect ratio preserved)
- [ ] **Empty state:** Hide entire hero section, show default welcome content
- [ ] **Error state:** Hide section with console.warn (non-critical)
- [ ] **Image loading:** Progressive loading or blur-up placeholder

---

## Task 2: Promotional Banner Strip

**Priority:** Medium
**Component:** Frontend — Public Homepage
**Story Points:** 3

**Description:** Display a horizontal banner strip (below hero or in sections) for promotional content.

**API Endpoint:**
- `GET /api/v1/general/banners?limit=4`

**Acceptance Criteria:**
- [ ] Horizontal row of banner cards
- [ ] Each card shows banner image with title overlay
- [ ] Click navigates to banner detail or product
- [ ] Responsive: 1 col mobile, 2 cols tablet, 4 cols desktop
- [ ] **Loading state:** Skeleton banner cards
- [ ] **Empty state:** Hide section
- [ ] **Error state:** Hide section with console warning
- [ ] **Image fallback:** Placeholder for missing images

---

## Task 3: Banner Detail Page

**Priority:** Low
**Component:** Frontend — Public Banner Page
**Story Points:** 5

**Description:** Build a banner detail/landing page showing banner content and associated products.

**API Endpoint:**
- `GET /api/v1/general/banners/{slug}?with_products=true`

**Acceptance Criteria:**
- [ ] Full-width banner hero image at top
- [ ] Banner title and description displayed prominently
- [ ] Product grid below showing associated products (if any)
- [ ] Product cards: image, name, price, current_price, discount badge, rating
- [ ] Each product links to product detail page
- [ ] **Loading state:** Hero skeleton + product grid skeleton
- [ ] **Empty state (no products):** "Shop our collection" CTA instead of product grid
- [ ] **Error state (404):** "Promotion not found" page
- [ ] **Error state (network):** Error with retry button

---

## Task 4: Banner Components — Loading, Empty & Error States

**Priority:** High
**Component:** Frontend — State Handling
**Story Points:** 3

**Description:** Handle all non-happy-path states across all banner components.

**Acceptance Criteria:**
- [ ] **Hero loading:** Full-width skeleton with aspect ratio (e.g., 21:9 desktop, 1:1 mobile)
- [ ] **Hero empty:** Section hidden, no layout shift
- [ ] **Hero error:** Hidden, console.warn, no broken UI
- [ ] **Banner strip loading:** 4 skeleton cards with shimmer
- [ ] **Banner strip empty:** Section hidden
- [ ] **Banner strip error:** Hidden with console warning
- [ ] **Detail loading:** Hero skeleton + 4 product card skeletons
- [ ] **Detail empty (no banner):** 404 page with "Browse Products" link
- [ ] **Detail empty (no products):** Banner shown + "No products" message
- [ ] **Detail error:** Error message with retry button
- [ ] **Image loading:** Blur-up or shimmer placeholder
- [ ] **Image error:** Fallback placeholder image

---

## Task 5: Banner Image Optimization

**Priority:** Medium
**Component:** Frontend — Performance
**Story Points:** 3

**Description:** Optimize banner image loading for performance using responsive images, lazy loading, and proper aspect ratio handling.

**Acceptance Criteria:**
- [ ] Use `<picture>` element or `srcset` for desktop/mobile image switching
- [ ] Lazy load non-hero banners (below the fold)
- [ ] Set explicit aspect ratio to prevent layout shift
- [ ] Use modern image formats if available (WebP)
- [ ] **Loading state:** Blur-up placeholder with correct aspect ratio
- [ ] **Error state:** Fallback to other format or placeholder
