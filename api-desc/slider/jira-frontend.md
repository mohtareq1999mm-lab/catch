# Jira - Slider Feature (Frontend)

## Epic: Slider Management UI

### Story Points Estimate: 8

---

## User Stories

### FE-US-001: Slider List with Drag-and-Drop Reorder
**As** an admin
**I want** a sortable slider list with drag-and-drop
**So that** I can manage display order

**Acceptance Criteria:**
- Data table with image thumbnail, title, status, order
- Drag-and-drop reorder with auto-save
- Status toggle switch per row

### FE-US-002: Slider Create/Edit Form
**As** an admin
**I want** a form with image upload and product assignment
**So that** I can create promotional sliders

**Acceptance Criteria:**
- Desktop + mobile image upload with preview
- Translatable title (EN/AR tabs)
- Product multi-select
- Image size/format validation (2MB, jpeg/png/jpg/gif)

### FE-US-003: Homepage Slider Display
**As** a customer
**I want** to see sliders on the home page
**So that** I can view promotions

**Acceptance Criteria:**
- Responsive carousel from public API
- Desktop and mobile images
- Clickable to product pages

---

## Frontend Tasks

| ID | Description | h | Component |
|----|-------------|---|-----------|
| FE-T-001 | Create SlidersList with reorder | 6 | `SlidersList.vue` |
| FE-T-002 | Create SliderFormModal | 6 | `SliderFormModal.vue` |
| FE-T-003 | Create HomeSliderCarousel | 4 | `HomeSliderCarousel.vue` |
| FE-T-004 | Create API services | 1 | `services/sliderApi.js` |

## API Routes

| Method | Endpoint | Permission |
|--------|----------|-----------|
| GET/POST | `/api/v1/sliders` | view-slider / create-slider |
| GET/PUT/DELETE | `/api/v1/sliders/{id}` | view/update/delete-slider |
| PATCH | `/api/v1/sliders/change-status` | update-slider |
| PUT | `/api/v1/sliders/reorder` | update-slider |
| GET | `/api/v1/general/sliders` | None |
| GET | `/api/v1/general/sliders/{slug}` | None |
