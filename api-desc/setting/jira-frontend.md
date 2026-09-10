# Settings Module — Frontend Jira Tasks (Admin)

---

## Task 1: Admin Settings Page

**Priority:** High  
**Component:** Admin Panel  
**Story Points:** 8  

**Description:** Build admin settings page with form fields for all settings.

**API Endpoints:**
- `GET /api/v1/general/settings` — Fetch current settings (public, no auth)
- `GET /api/v1/settings` — Fetch current settings (admin, requires Sanctum + view-settings)
- `PUT /api/v1/settings` — Update settings (admin, requires Sanctum + update-settings)

**Acceptance Criteria:**
- [ ] Form fields for site_name, site_desc, meta_desc, site_copy_right (multilingual)
- [ ] Form fields for site_email, email_support, phone
- [ ] Form fields for facebook, instagram, linkedin, youtube, tiktok, snapchat URLs
- [ ] Form field for promotion_video_url
- [ ] Logo and favicon image upload
- [ ] fast_shipping_page_publish toggle
- [ ] minimumOrderAmount number input
- [ ] **Currency selection toggle** — `currency_selection_enabled` boolean switch (maps to `PUT /api/v1/settings`; send as JSON boolean; when off, the storefront currency selector is hidden)
- [ ] Fast shipping sub-section (enabled, duration, fee, hours)
- [ ] **Loading state:** Skeleton form
- [ ] **Error state:** Show error alert
- [ ] **Saving state:** Button loading spinner
- [ ] **Success state:** Toast notification

---

## Task 2: Fast Shipping Settings Page

**Priority:** Medium  
**Component:** Admin Panel  
**Story Points:** 3  

**Description:** Fast shipping configuration section within settings page.

**API Endpoints:**
- `GET /api/v1/fast-shipping/settings` — Fetch config
- `PUT /api/v1/fast-shipping/settings` — Update config

**Acceptance Criteria:**
- [ ] Enable/disable toggle
- [ ] Duration minutes input
- [ ] Fee amount input
- [ ] Start/end hour time pickers

---

## Task 3: Admin Settings Page — Public vs Admin Endpoints

**Priority:** Medium  
**Component:** Admin Panel  

**Description:** Clarify the difference between the public and admin settings endpoints for the frontend team.

**Key Differences:**
- `GET /api/v1/general/settings` — No authentication required; translatable fields returned as single locale string
- `GET /api/v1/settings` — Requires Sanctum token with `view-settings` permission; translatable fields returned as `{ar, en}` objects

**Important:** Ensure the frontend correctly uses the public endpoint for unauthenticated requests and the admin endpoint for authenticated admin requests.