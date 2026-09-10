# Page Module — Frontend Jira Tasks

## Task 1: Public Page Renderer

**Priority:** High
**Component:** Frontend — Public Page
**Story Points:** 5

**Description:** Build the public page renderer that fetches and renders CMS pages by slug. The renderer iterates over sections and renders the matching block component.

**API Endpoint:**
- `GET /api/v1/general/pages/{slug}`

**Acceptance Criteria:**
- [ ] Page renderer fetches page by slug on mount
- [ ] Renders page title and iterates over `sections` array
- [ ] Each section reads its `type` and `endpoint` and renders the matching block component
- [ ] Section block fetches its own data from the `endpoint` URL
- [ ] **Loading state:** Full page skeleton with title placeholder and section block placeholders (3-4 blocks)
- [ ] **Empty state (no sections):** Show page title with "No content available" message
- [ ] **Error state (404):** Show "Page not found" with link to homepage
- [ ] **Network error:** Show "Failed to load page" with retry button
- [ ] Responsive: sections stack vertically on mobile

---

## Task 2: Section Block Components (Public)

**Priority:** High
**Component:** Frontend — Section Blocks
**Story Points:** 8

**Description:** Build individual block components for each section type. Each block fetches data from its section's endpoint and renders accordingly.

**Section Types:**
- `sliders` → SliderBlock
- `banners` → BannerBlock
- `categories` → CategoryBlock
- `promotions` → PromotionBlock
- `flash_sale` → FlashSaleBlock
- `popular_products` / `best_selling_products` / `products` → ProductGridBlock

**Acceptance Criteria:**
- [ ] SliderBlock: image carousel with autoplay and navigation dots (reads `setting.autoplay`, `setting.slider_speed`)
- [ ] BannerBlock: banner grid with CTA links
- [ ] CategoryBlock: category cards with images, optional parent-only filter
- [ ] PromotionBlock: promotion cards with discount badges
- [ ] FlashSaleBlock: countdown timer with product grid
- [ ] ProductGridBlock: product grid with pricing, ratings, add-to-cart
- [ ] Each block shows section title if not null
- [ ] **Loading state:** Skeleton matching block shape (carousel skeleton, grid skeleton)
- [ ] **Empty state:** Hide block entirely if data returns empty array
- [ ] **Error state:** Show "Failed to load {type}" with retry button on the block (not full page)
- [ ] Section settings merged with defaults (e.g., `limit` from setting or default 10)

---

## Task 3: Admin Content Page List

**Priority:** High
**Component:** Frontend — Admin Content Pages
**Story Points:** 5

**Description:** Build the admin content page management list with CRUD actions, toggle active, and section attachment.

**API Endpoints:**
- `GET /api/v1/content-pages`
- `DELETE /api/v1/content-pages/{id}`
- `PATCH /api/v1/content-pages/{id}/toggle-active`

**Acceptance Criteria:**
- [ ] Table renders all pages with columns: title, slug, active badge, section count, actions
- [ ] Pagination controls (page size selector, prev/next)
- [ ] "Create Page" button navigates to create form
- [ ] Each row shows edit/delete/toggle-active action buttons
- [ ] Delete opens confirmation modal
- [ ] Toggle-active switches status inline with optimistic UI
- [ ] **Loading state:** Skeleton table rows (5 rows)
- [ ] **Empty state:** "No pages yet" with "Create your first page" CTA
- [ ] **Error state:** Error message with "Retry" button
- [ ] **Delete error:** Toast "Failed to delete page"
- [ ] **Toggle error:** Revert toggle with error toast

---

## Task 4: Admin Content Page Create/Edit Form

**Priority:** High
**Component:** Frontend — Admin Page Form
**Story Points:** 8

**Description:** Build the create/edit form for content pages with translatable titles, section attachment, and settings.

**API Endpoints:**
- `POST /api/v1/content-pages` (create)
- `PUT /api/v1/content-pages/{id}` (update)
- `GET /api/v1/content-pages/{id}` (load existing for edit)
- `POST /api/v1/content-pages/{id}/attach-sections`

**Acceptance Criteria:**
- [ ] Create mode: empty form
- [ ] Edit mode: form pre-filled from API
- [ ] Translatable `title` field with language tabs (en, ar)
- [ ] Active/inactive toggle switch
- [ ] Section attachment: multi-select with drag-and-drop reorder
- [ ] Each section shows type, title, active status
- [ ] Save sends attach-sections with new order
- [ ] Validation errors displayed per field (422 response)
- [ ] **Loading state (edit):** Form skeleton while fetching page data
- [ ] **Error state:** Toast with error message
- [ ] Success redirect to page list with success toast
- [ ] Cancel button returns to list

---

## Task 5: Admin Section Management UI

**Priority:** Medium
**Component:** Frontend — Admin Sections
**Story Points:** 5

**Description:** Build the admin section management interface for creating and configuring reusable content blocks.

**API Endpoints:**
- `GET /api/v1/sections`
- `POST /api/v1/sections`
- `PUT /api/v1/sections/{id}`
- `POST /api/v1/sections/reorder`
- `PATCH /api/v1/sections/{id}/toggle-active`

**Acceptance Criteria:**
- [ ] List all sections with type badge, title, active status, order
- [ ] Drag-and-drop reorder rows (sends `POST /api/v1/sections/reorder`)
- [ ] Create/edit modal or page with fields:
  - Section type selector (dropdown, populated from section types)
  - Translatable title
  - Type-specific settings (dynamic form based on selected type)
  - Active toggle
- [ ] Toggle active inline with optimistic UI
- [ ] **Loading state:** Skeleton list
- [ ] **Empty state:** "No sections yet" with "Create Section" CTA
- [ ] **Reorder error:** Revert with error toast
- [ ] Validation errors on form fields

---

## Task 6: Admin Section Type Settings UI

**Priority:** Medium
**Component:** Frontend — Admin Section Types
**Story Points:** 3

**Description:** Build the UI for managing section type configurations and their front/back settings schemas.

**API Endpoints:**
- `GET /api/v1/section-types`
- `POST /api/v1/section-types`
- `POST /api/v1/section-types/{type}/settings`

**Acceptance Criteria:**
- [ ] List all registered section types
- [ ] Create new section type with name and slug
- [ ] Settings editor: dynamic form fields for front_settings and back_settings
- [ ] Settings schema supports: text, number, boolean, select, multi-select
- [ ] Each setting field has: key, label, type, default value, options (for selects)
- [ ] **Loading state:** Skeleton
- [ ] **Empty state:** "No section types registered"
- [ ] Validation: type slug must be unique

---

## Task 7: Puck Page Builder Integration (Admin)

**Priority:** High
**Component:** Frontend — Puck Editor
**Story Points:** 8

**Description:** Integrate the Puck page builder for drag-and-drop page design. Puck saves structured JSON content and retrieves pages by path.

**API Endpoints:**
- `GET /api/v1/puck/page?path={path}`
- `POST /api/v1/puck/page`
- `GET /api/v1/component-data/*` (SSR data)

**Acceptance Criteria:**
- [ ] Puck editor page loads page data from `GET /api/v1/puck/page?path={path}`
- [ ] Drag-and-drop components from available component list
- [ ] Components supported: HeroBlock, TextBlock, ImageBlock, ProductGridBlock, CategoryBlock
- [ ] Each component's data fetched from corresponding component-data endpoint
- [ ] Save sends upsert via `POST /api/v1/puck/page`
- [ ] **Loading state:** Puck editor skeleton while page loads
- [ ] **Empty state:** Blank canvas with "Start building your page" prompt
- [ ] **Error state (404):** Create new page flow
- [ ] **Save error:** Toast "Failed to save page" with retry
- [ ] Autosave with debounce (30s or on explicit save button)

---

## Task 8: Admin CMS Pages (Puck) List

**Priority:** Medium
**Component:** Frontend — Admin CMS Pages
**Story Points:** 3

**Description:** Build the admin list for Puck-based CMS pages with create, edit, delete actions.

**API Endpoints:**
- `GET /api/v1/cms-pages`
- `DELETE /api/v1/cms-pages/{id}`

**Acceptance Criteria:**
- [ ] Table lists all CMS pages: title, slug, path, last updated, actions
- [ ] "Create Page" opens Puck editor (blank)
- [ ] Click row or "Edit" opens Puck editor with existing data
- [ ] Delete confirmation modal
- [ ] **Loading state:** Skeleton table
- [ ] **Empty state:** "No CMS pages" with "Create your first page" CTA
- [ ] **Delete error:** Toast with error message

---

## Task 9: Page Feature — State Handling

**Priority:** High
**Component:** Frontend — State Handling
**Story Points:** 5

**Description:** Handle all loading, empty, error, and edge case states across the page management feature.

**Acceptance Criteria:**
- [ ] **Page renderer loading:** Skeleton with title bar + 3 section block placeholders of varying heights
- [ ] **Page renderer error:** Error illustration with "Failed to load page" and "Go Home" button
- [ ] **Page renderer 404:** "Page not found" with link to homepage
- [ ] **Section block loading:** Individual block skeleton (carousel placeholder for sliders, grid for products)
- [ ] **Section block error:** Block-level error with retry (does not break other sections)
- [ ] **Section block empty:** Block hidden gracefully
- [ ] **Admin list loading:** 5-row skeleton table
- [ ] **Admin list empty:** Illustration with CTA button
- [ ] **Admin list error:** Error message with retry
- [ ] **Admin form loading (edit):** Form skeleton (title field, sections list placeholders)
- [ ] **Admin form validation:** Inline field errors from 422 response, per-language for translatable fields
- [ ] **Admin form error:** Toast with error message
- [ ] **Network error:** "Network error, please try again" for all API calls
- [ ] **Delete modal:** Confirmation with brand name, loading spinner on confirm, disable during API call

---

## Task 10: Page Feature — i18n & Multilingual

**Priority:** Medium
**Component:** Frontend — i18n
**Story Points:** 3

**Description:** Handle translatable fields and multilingual content across page management.

**Request/Response format:**
```json
{
  "title": { "en": "About Us", "ar": "من نحن" }
}
```

**Acceptance Criteria:**
- [ ] Read supported languages from app config
- [ ] Language tabs for each supported locale on translatable fields
- [ ] Each translatable field shows separate input per language tab
- [ ] On save, fields serialized to `{ "en": "...", "ar": "..." }` format
- [ ] On load, each tab shows correct translation
- [ ] Default language tab pre-selected
- [ ] Validation errors shown per-language (e.g., `title.en` error on English tab)
- [ ] Public page renderer respects current app locale for translations
