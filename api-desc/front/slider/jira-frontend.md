# Jira - Slider Feature (Frontend)

## Epic: Frontend Slider UI

### Story Points Estimate: 13

---

## User Stories

### FE-US-001: Homepage Hero Carousel (Public Shop)
**As** a customer
**I want** to see a rotating hero carousel on the homepage
**So that** I can view promoted products and campaigns

**Acceptance Criteria:**
- Fetches `GET /api/v1/general/sliders` on mount
- Displays desktop image for large screens, mobile image for small screens (responsive `<picture>`)
- Auto-rotates with configurable speed (default 5000ms)
- Pauses on hover
- Shows navigation dots/arrows
- Handles loading state (skeleton)
- Handles empty state (no sliders configured — hides section)
- Handles error state with fallback (hides carousel, logs error)
- Handles single slider (no arrows/dots, static display)
- Keyboard accessible (arrow keys navigate)

**API:**
```
GET /api/v1/general/sliders?limit=5
Response: { data: [{ id, title, slug, image: { desktop, mobile }, products }] }
```

---

### FE-US-002: Slider as Page Builder Section (Puck)
**As** a content manager
**I want** to add slider sections to content pages via the Puck page builder
**So that** I can embed hero banners within custom pages

**Acceptance Criteria:**
- Section type `'sliders'` registered in page builder
- Front settings: `autoplay` (toggle), `slider_speed` (number input, ms)
- Fetches sliders endpoint with optional limit
- Renders same HeroCarousel component with section-level config overrides
- Preview in page builder shows slider mockup

**API:**
```
GET /api/v1/component-data/sliders?limit=5
Response: { data: [...] }
```

---

### FE-US-003: Admin Slider List Page
**As** an admin user
**I want** a management page to view all sliders with controls
**So that** I can manage promotional banners efficiently

**Acceptance Criteria:**
- Fetches `GET /api/v1/sliders` (paginated, admin auth, `view-slider` permission)
- Table view with columns: thumbnail, title, slug, status, order, product count
- Status badge (Active/Inactive) with color coding
- Drag-and-drop reorder (calls `PUT /api/v1/sliders/reorder`)
- Status toggle switch per row (calls `PATCH /api/v1/sliders/change-status`)
- Row actions: Edit, Delete (with confirmation dialog)
- Search by title
- Pagination controls
- Loading skeleton, empty state ("No sliders yet — Create one"), error state with retry
- Success/error toasts for all actions

---

### FE-US-004: Admin Slider Create/Edit Form
**As** an admin user
**I want** a form to create and edit sliders with images
**So that** I can add or update promotional banners

**Acceptance Criteria:**
- Form fields:
  - Title (multi-language tabs EN / AR / DE)
  - Desktop image upload (drag & drop, preview, jpeg/png/jpg/gif, max 2MB)
  - Mobile image upload (same constraints)
  - Status toggle (active/inactive)
  - Product association (searchable multi-select)
- Image preview before upload with replace/remove option
- Validation errors displayed inline per field
- Slug auto-generated from English title (shown read-only)
- Loading state on submit button
- Success toast + redirect to list on submit
- On edit: pre-loads existing images with preview

**API (Create):**
```
POST /api/v1/sliders
Content-Type: multipart/form-data
Body: title[en]=Summer Sale, title[ar]=..., image_desktop=<file>, image_mobile=<file>, status=1, products=[1,2,3]
```

**API (Update):**
```
PUT /api/v1/sliders/{id}
Content-Type: multipart/form-data
Body: title[en]=New Name, image_desktop=<file> (optional replace), ...
```

---

### FE-US-005: Slider Status Toggle (Admin)
**As** an admin user
**I want** to activate/deactivate sliders directly from the list
**So that** I can control which banners are live without opening the edit form

**Acceptance Criteria:**
- Toggle switch in admin list table per row
- Calls `PATCH /api/v1/sliders/change-status` with `{ id }`
- Instant UI feedback (optimistic update — toggle immediately)
- Reverts toggle on error with error toast
- Requires `update-slider` permission
- Disabled state on toggle while request in flight

---

### FE-US-006: Slider Reorder (Drag & Drop)
**As** an admin user
**I want** to reorder sliders by dragging and dropping
**So that** I can control the sequence of banners on the homepage

**Acceptance Criteria:**
- Drag handles on each row in the admin list
- Visual feedback during drag (highlight drop target, ghost element)
- Calls `PUT /api/v1/sliders/reorder` with `{ sliders: [id1, id2, ...] }` on drop
- Revert to original order on API error
- Success toast on reorder
- Requires `update-slider` permission
- Touch-friendly for mobile admin

---

### FE-US-007: Slider Detail Modal/Page (Optional Public)
**As** a customer
**I want** to view a single promotion slider with its products
**So that** I can see all products associated with a campaign

**Acceptance Criteria:**
- Fetches `GET /api/v1/general/sliders/{slug}` on mount
- Displays full-size slider image
- Shows associated products as a grid
- Handles 404 with "Promotion not found"
- Loading and error states

---

## Frontend Tasks

| Task ID | Description | Estimate (h) | Dependencies | Component |
|---------|-------------|-------------|--------------|-----------|
| FE-T-001 | Create HeroCarousel component | 5 | None | `HeroCarousel.vue` |
| FE-T-002 | Create SliderCard component | 2 | None | `SliderCard.vue` |
| FE-T-003 | Integrate HeroCarousel into HomePage | 3 | FE-T-001 | `HomePage.vue` |
| FE-T-004 | Create AdminSliderListPage | 8 | None | `AdminSliderListPage.vue` |
| FE-T-005 | Create AdminSliderForm component | 10 | None | `AdminSliderForm.vue` |
| FE-T-006 | Create AdminSliderCreatePage | 4 | FE-T-005 | `AdminSliderCreatePage.vue` |
| FE-T-007 | Create AdminSliderEditPage | 4 | FE-T-005 | `AdminSliderEditPage.vue` |
| FE-T-008 | Create status toggle in admin list | 3 | FE-T-004 | Inline in list |
| FE-T-009 | Create drag-and-drop reorder | 5 | FE-T-004 | Inline in list |
| FE-T-010 | Create SliderDetailPage | 4 | FE-T-002 | `SliderDetailPage.vue` |
| FE-T-011 | Add slider section type to Puck builder | 3 | FE-T-001 | Puck section |
| FE-T-012 | Create API service layer (sliderApi) | 3 | None | `services/sliderApi.js` |
| FE-T-013 | Create slider store (Pinia/Vuex) | 3 | FE-T-012 | `stores/sliderStore.js` |
| FE-T-014 | Loading/Empty/Error state components | 4 | None | Shared components |
| FE-T-015 | Multi-language form inputs (i18n) | 4 | None | `LocaleInput.vue` |
| FE-T-016 | Image upload with preview | 3 | None | `ImageUpload.vue` |
| FE-T-017 | Product multi-select input | 3 | None | `ProductMultiSelect.vue` |

---

## Frontend Bug Tickets

| Ticket | Description | Priority | Severity | Component |
|--------|-------------|----------|----------|-----------|
| FE-BUG-001 | Carousel images not responsive on tablet | Medium | Medium | HeroCarousel |
| FE-BUG-002 | Slider images not caching after update (cache bust missing) | Medium | Medium | AdminSliderForm |
| FE-BUG-003 | Drag reorder doesn't persist after page refresh | High | High | AdminSliderList |
| FE-BUG-004 | Status toggle optimistic update not reverted on error | Medium | Medium | AdminSliderList |
| FE-BUG-005 | No loading state between slides on autoplay | Low | Low | HeroCarousel |

---

## API Routes for Frontend Integration

| Method | Endpoint | Auth | Frontend Usage |
|--------|----------|------|---------------|
| GET | `/api/v1/general/sliders` | No | Public homepage carousel |
| GET | `/api/v1/general/sliders/{slug}` | No | Public slider detail |
| GET | `/api/v1/sliders` | Yes (admin) | Admin list page |
| GET | `/api/v1/sliders/{id}` | Yes (admin) | Admin edit form (load existing) |
| POST | `/api/v1/sliders` | Yes (admin) | Admin create form |
| PUT | `/api/v1/sliders/{id}` | Yes (admin) | Admin edit form |
| DELETE | `/api/v1/sliders/{id}` | Yes (admin) | Admin list delete action |
| PATCH | `/api/v1/sliders/change-status` | Yes (admin) | Admin status toggle |
| PUT | `/api/v1/sliders/reorder` | Yes (admin) | Admin drag-and-drop reorder |
| GET | `/api/v1/component-data/sliders` | No | Puck page builder block |
