# Pages Module — Frontend Jira Tasks

---

## Task 1: Product Type Labels Display

**Priority:** Medium
**Component:** Frontend — Public Pages / Admin Panel
**Story Points:** 2

**Description:** Fetch localized labels for product types and use them throughout the UI (section type selector, product section rendering).

**API Endpoints:**
- `GET /api/v1/product-type`

**Response Format:**
```json
{
  "best_product_sales": "Best Product Sales",
  "brands_product": "Brands Product",
  "new_arrivals": "New Arrivals",
  "all_product_discounts": "All Product Discounts",
  "product_discount_today_or_low_qty": "Product Discount Today or Low Quantity",
  "flash_sales_product": "Flash Sales Product",
  "flash_sales_end_today": "Flash Sales End Today",
  "product_for_parent_category": "Product for Parent Category",
  "flash_sales_end_week": "Flash Sales End Week"
}
```

Locale is controlled via `lang` header. Supports `en`, `ar`.

**Acceptance Criteria:**
- [ ] Fetch labels on app load and cache for the session
- [ ] Display human-readable labels instead of raw keys in section type selectors
- [ ] Labels update when locale changes (re-fetch with new lang header)
- [ ] Fallback to raw key if translation missing
- [ ] **Loading state:** Show raw keys while loading
- [ ] **Error state:** Show raw keys with console warning
- [ ] **Empty state:** Not applicable (always returns 9 keys)

---

## Task 2: Public Content Page Renderer

**Priority:** High
**Component:** Frontend — Public Pages
**Story Points:** 8

**Description:** Build the page renderer that fetches and renders dynamic content pages with their sections.

**API Endpoints:**
- `GET /api/v1/general/content-pages`
- `GET /api/v1/general/content-pages/{slug}`

**Acceptance Criteria:**
- [ ] Fetch page by slug from URL or configuration
- [ ] Render sections in order (ordered by `order` field)
- [ ] Each section type maps to a specific UI component (slider, banner, category, product grid, etc.)
- [ ] Section title rendered only when non-null (title_visible flag)
- [ ] Section data fetched from dynamic `endpoint` URL
- [ ] Section display configured by `setting.front` (columns, autoplay, layout style)
- [ ] **Loading state:** Skeleton per section
- [ ] **Empty state:** "No content" placeholder
- [ ] **Error state:** Toast with retry, continue rendering other sections
- [ ] **Section error:** Graceful fallback for individual section failures

---

## Task 2: Admin — Content Page CRUD

**Priority:** High
**Component:** Frontend — Admin Panel
**Story Points:** 5

**API Endpoints:**
- `GET /api/v1/content-pages`
- `POST /api/v1/content-pages`
- `PUT /api/v1/content-pages/{id}`
- `DELETE /api/v1/content-pages/{id}`
- `PATCH /api/v1/content-pages/{id}/toggle-active`

**Acceptance Criteria:**
- [ ] Table listing all pages with title, slug, status badge, actions
- [ ] Create form: title (multi-locale input), auto-generated slug
- [ ] Edit form: title, slug, active toggle
- [ ] Delete with confirmation modal
- [ ] Toggle active status with inline toggle
- [ ] **Loading state:** Table skeleton, form spinner
- [ ] **Empty state:** "No pages yet" with "Create Page" CTA
- [ ] **Error state:** Toast with error message

---

## Task 3: Admin — Section Manager

**Priority:** High
**Component:** Frontend — Admin Panel
**Story Points:** 8

**API Endpoints:**
- `GET /api/v1/sections`
- `POST /api/v1/sections`
- `PUT /api/v1/sections/{id}`
- `DELETE /api/v1/sections/{id}`
- `PATCH /api/v1/sections/{id}/toggle-active`
- `POST /api/v1/sections/reorder`

**Acceptance Criteria:**
- [ ] Table listing all sections with type, title, order, status
- [ ] Create form: type selector (from section-types), title (multi-locale), settings JSON editor
- [ ] Edit form: all fields editable
- [ ] Drag-and-drop reorder with save button
- [ ] Delete with confirmation
- [ ] Toggle active status with inline toggle
- [ ] **Loading state:** Table skeleton, form spinner
- [ ] **Empty state:** "No sections yet" with "Create Section" CTA
- [ ] **Error state:** Toast with error message

---

## Task 4: Admin — Page Section Attachment

**Priority:** Medium
**Component:** Frontend — Admin Panel
**Story Points:** 5

**API Endpoint:**
- `POST /api/v1/content-pages/{id}/attach-sections`

**Acceptance Criteria:**
- [ ] In page edit form, show list of currently attached sections
- [ ] Multi-select or transfer interface to add/remove sections
- [ ] On save, send array of section IDs
- [ ] If all removed, send empty array (detaches all)
- [ ] **Loading state:** Spinner on save
- [ ] **Error state:** Toast with error message

---

## Task 5: Admin — Section Types & Settings

**Priority:** Medium
**Component:** Frontend — Admin Panel
**Story Points:** 5

**API Endpoints:**
- `GET /api/v1/section-types`
- `POST /api/v1/section-types`
- `PUT /api/v1/section-types/{type}`
- `DELETE /api/v1/section-types/{type}`
- `GET /api/v1/section-types/{type}/settings`
- `POST /api/v1/section-types/{type}/settings`

**Acceptance Criteria:**
- [ ] Table listing all section types
- [ ] Create type: text input for type key
- [ ] Settings editor: front (display config) and back (query params) JSON editors
- [ ] Settings are displayed as key-value pairs with appropriate input types
- [ ] **Loading state:** Form spinner
- [ ] **Empty state:** "No types yet" with "Create Type" CTA
- [ ] **Error state:** Toast with error message
