# Settings Module — Frontend Jira Tasks

---

## Task 1: Global SEO Integration

**Priority:** High
**Component:** Frontend — Layout/SEO
**Story Points:** 3

**Description:** Integrate `GET /api/v1/general/settings` response into the app's SEO meta tags, title, and favicon.

**API Endpoint:**
- `GET /api/v1/general/settings`

**Acceptance Criteria:**
- [ ] `site_name` used in `<title>` tag
- [ ] `site_desc` used in `<meta name="description">`
- [ ] `meta_desc` used in `<meta name="keywords">` or Open Graph
- [ ] `favicon` used as `<link rel="icon">`
- [ ] **Loading state:** Default title/meta from env shown
- [ ] **Error state:** Fall back to env defaults
- [ ] **Empty state:** Handle null fields gracefully

---

## Task 2: Header Component — Logo & Branding

**Priority:** High
**Component:** Frontend — Header
**Story Points:** 5

**Description:** Use settings data to render the site header with logo and site name.

**API Endpoint:**
- `GET /api/v1/general/settings`

**Acceptance Criteria:**
- [ ] `logo` URL rendered as <img> in header
- [ ] `site_name` shown as alt text and fallback text
- [ ] Click navigates to home
- [ ] **Loading state:** Placeholder rectangle for logo
- [ ] **Error state:** Show site_name as text only
- [ ] **Empty state (no logo):** Show site_name text

---

## Task 3: Footer Component — Social Links & Contact

**Priority:** Medium
**Component:** Frontend — Footer
**Story Points:** 5

**Description:** Render footer social media icons and contact information from settings.

**API Endpoint:**
- `GET /api/v1/general/settings`

**Acceptance Criteria:**
- [ ] Social icons for facebook, instagram, linkedin, youtube (only if URL present)
- [ ] Contact email and phone displayed
- [ ] `site_copy_right` shown as copyright text
- [ ] `promotion_video_url` linked as "Watch our video" button
- [ ] **Loading state:** Skeleton footer blocks
- [ ] **Error state:** Minimal footer with hardcoded copyright
- [ ] **Empty state:** Hide social section if no URLs configured

---

## Task 4: Minimum Order Amount — Checkout Guard

**Priority:** High
**Component:** Frontend — Checkout
**Story Points:** 3

**Description:** Read `minimumOrderAmount` from settings and enforce it on the checkout page. If cart total is below this amount, disable the checkout/submit button and show a message.

**API Endpoint:**
- `GET /api/v1/general/settings`

**Acceptance Criteria:**
- [ ] `minimumOrderAmount` compared against cart total before checkout
- [ ] If cart total < minimumOrderAmount, show message: "Minimum order amount is {amount}"
- [ ] Checkout button disabled until minimum is met
- [ ] Default to 0 (no minimum) if field is missing
- [ ] **Loading state:** Assume 0 (allow checkout while loading)

---

## Task 5: Feature Flag — Fast Shipping Page

**Priority:** Medium
**Component:** Frontend — Navigation
**Story Points:** 2

**Description:** Conditionally show/hide the "Fast Shipping" page link in navigation based on `fast_shipping_page_publish`.

**API Endpoint:**
- `GET /api/v1/general/settings`

**Acceptance Criteria:**
- [ ] If `fast_shipping_page_publish` is true, show link in nav
- [ ] If false, hide the link
- [ ] Default to hidden if settings not loaded yet
