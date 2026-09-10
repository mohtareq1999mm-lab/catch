# Jira - Banner Feature (Frontend)

## Epic: Banner Management UI

### Story Points Estimate: 8

---

## User Stories

### FE-US-001: Banner List with Drag-and-Drop Reorder
**As** an admin
**I** want a sortable banner list with drag-and-drop
**So that** I can manage banner display order

**Acceptance Criteria:**
- Data table with columns: image thumbnail, title, status, order
- Drag-and-drop reorder with auto-save
- Status toggle switch per row
- Search by title

### FE-US-002: Banner Create/Edit Form
**As** an admin
**I** want a form with image upload and product assignment
**So that** I can create promotional banners

**Acceptance Criteria:**
- Desktop + mobile image upload with preview
- Translatable title and description (EN/AR tabs)
- Product multi-select / autocomplete
- Image size/format validation (2MB, jpeg/png/jpg/gif)

---

## Frontend Tasks

| ID | Description | h | Component |
|----|-------------|---|-----------|
| FE-T-001 | Create BannersList with drag-and-drop | 6 | `BannersList.vue` |
| FE-T-002 | Create BannerFormModal with image upload | 6 | `BannerFormModal.vue` |
| FE-T-003 | Create API service | 1 | `services/bannerApi.js` |

## API Routes

| Method | Endpoint | Permission | Usage |
|--------|----------|-----------|-------|
| GET | `/api/v1/banners` | view-banners | Data table |
| POST | `/api/v1/banners` | create-banners | Create |
| GET/PUT/DELETE | `/api/v1/banners/{id}` | view/update/delete-banners | Edit/delete |
| PUT | `/api/v1/banner/change-status` | update-banners | Toggle |
| POST | `/api/v1/banner/reorder` | update-banners | Reorder |
