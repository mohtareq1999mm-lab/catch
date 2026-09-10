# Jira - Category Feature (Frontend)

## Epic: Frontend Category UI

### Story Points Estimate: 13

---

## User Stories

### FE-US-001: Category Listing Page (Public Shop)
**As** a customer
**I want** to browse product categories in a grid/list view
**So that** I can navigate to a category and see its products

**Acceptance Criteria:**
- Fetches `GET /api/v1/general/categories` on mount
- Displays categories as cards with image, name, product count
- Supports search input that calls `?search=` query parameter
- Shows parent-only toggle (`?parentOnly=true`)
- Handles loading state (skeleton/spinner)
- Handles empty state ("No categories found")
- Handles error state with retry button
- Responsive design (mobile grid 2 cols, desktop 4+ cols)
- Pagination via "Load More" or page numbers

**API:**
```
GET /api/v1/general/categories?search={term}&parentOnly={bool}&page={n}
Response: { data: [...], meta: { current_page, last_page, per_page, total } }
```

---

### FE-US-002: Category Detail Page (Public Shop)
**As** a customer
**I want** to view a single category with its subcategories and products
**So that** I can explore products within a specific category

**Acceptance Criteria:**
- Fetches `GET /api/v1/general/categories/{slug}` on mount
- Displays category image, name, description
- Shows child categories as clickable cards
- Shows products within the category (paginated)
- Breadcrumb navigation (Home > Category > Subcategory)
- Handles 404 with "Category not found" message
- Loading and error states

**API:**
```
GET /api/v1/general/categories/{slug}
Response: { data: { id, name, slug, image, products_count, details, children: [...], products: [...] } }
```

---

### FE-US-003: Category Navbar/Mega Menu
**As** a customer
**I want** to see categories in the main navigation menu
**So that** I can quickly jump to any category

**Acceptance Criteria:**
- Fetches categories with recursive children for multi-level dropdown
- Displays up to 3 levels deep (configurable via `maxLevel`)
- Handles categories with no children (links directly)
- Responsive: desktop shows mega menu, mobile shows accordion/hamburger
- Active state highlights current category

**API:**
```
GET /api/v1/general/categories (with children loaded)
Resource: CategoryNavbarResource
Response: { data: [{ id, name, slug, level, image, children: [...] }] }
```

---

### FE-US-004: Admin Category List Page
**As** an admin user
**I want** a management page to view all categories with filters
**So that** I can manage the category tree efficiently

**Acceptance Criteria:**
- Fetches `GET /api/v1/categories` (paginated, admin auth)
- Table/card view with columns: name, slug, level, parent, status, featured, product count
- Filters: search, parent, status (active/inactive/all), featured
- Sort by name, level, product count
- Row actions: Edit, Delete, Toggle Featured
- Confirmation dialog for delete
- Success/error toasts for all actions

---

### FE-US-005: Admin Category Create/Edit Form
**As** an admin user
**I want** a form to create and edit categories
**So that** I can add or update product categories

**Acceptance Criteria:**
- Form fields:
  - Name (multi-language tabs e.g. EN / AR / DE)
  - Slug (auto-generated from English name, editable)
  - Parent category (tree select/dropdown)
  - Details (multi-language rich text or textarea, max 2500 chars)
  - Desktop image upload (drag & drop, preview, jpeg/png/jpg/gif/svg, max 2MB)
  - Mobile image upload (same constraints)
  - Status toggle (active/inactive)
  - Product association (multi-select)
- Validation errors displayed inline per field
- Shows existing images with option to replace
- Success toast + redirect to list on submit
- Loading state on submit button

**API (Create):**
```
POST /api/v1/categories
Content-Type: multipart/form-data
Body: name[en]=..., name[ar]=..., image-desktop=<file>, image-mobile=<file>, ...
```

**API (Update):**
```
PUT /api/v1/categories/{id}
Content-Type: multipart/form-data
Body: name[en]=..., parent_id=5, status=1, ...
```

---

### FE-US-006: Featured Categories Toggle (Admin)
**As** an admin user
**I want** to mark/unmark categories as featured directly from the list
**So that** I can control which categories appear on the homepage

**Acceptance Criteria:**
- Toggle button/switch in the admin category list
- Calls `PUT /api/v1/categories/feature` with `{ id }`
- Instant UI feedback (optimistic update)
- Reverts on error with error message
- Requires `update-category` permission
- Public endpoint `GET /api/v1/featured-categories` displays featured on shop front

---

### FE-US-007: Featured Categories Display (Public)
**As** a customer
**I want** to see featured categories highlighted on the homepage
**So that** I can discover popular product categories

**Acceptance Criteria:**
- Fetches `GET /api/v1/featured-categories` (no auth)
- Displays as a horizontal carousel or grid section
- Shows category image, name, product count
- Clickable to navigate to category detail
- Handles empty state (no featured categories configured)

---

## Frontend Tasks

| Task ID | Description | Estimate (h) | Dependencies | Component |
|---------|-------------|-------------|--------------|-----------|
| FE-T-001 | Create CategoryCard component | 3 | None | `CategoryCard.vue` |
| FE-T-002 | Create CategoryGrid component | 2 | FE-T-001 | `CategoryGrid.vue` |
| FE-T-003 | Create CategoryListingPage | 6 | FE-T-002 | `CategoryListingPage.vue` |
| FE-T-004 | Create CategoryDetailPage | 8 | FE-T-001 | `CategoryDetailPage.vue` |
| FE-T-005 | Create CategoryNavbar component | 5 | None | `CategoryNavbar.vue` |
| FE-T-006 | Create AdminCategoryListPage | 8 | None | `AdminCategoryListPage.vue` |
| FE-T-007 | Create CategoryForm component | 10 | None | `CategoryForm.vue` |
| FE-T-008 | Create AdminCategoryCreatePage | 4 | FE-T-007 | `AdminCategoryCreatePage.vue` |
| FE-T-009 | Create AdminCategoryEditPage | 4 | FE-T-007 | `AdminCategoryEditPage.vue` |
| FE-T-010 | Create FeaturedCategoriesSection | 3 | FE-T-001 | `FeaturedCategoriesSection.vue` |
| FE-T-011 | Create featured toggle in admin list | 2 | FE-T-006 | Inline in list |
| FE-T-012 | Create API service layer (categoryApi) | 3 | None | `services/categoryApi.js` |
| FE-T-013 | Create category store (Pinia/Vuex) | 3 | FE-T-012 | `stores/categoryStore.js` |
| FE-T-014 | Loading/Empty/Error state components | 4 | None | Shared components |
| FE-T-015 | Multi-language form inputs (i18n) | 4 | None | `LocaleInput.vue` |
| FE-T-016 | Image upload with preview | 3 | None | `ImageUpload.vue` |
| FE-T-017 | Tree select for parent category | 4 | None | `CategoryTreeSelect.vue` |

---

## Frontend Bug Tickets

| Ticket | Description | Priority | Severity | Component |
|--------|-------------|----------|----------|-----------|
| FE-BUG-001 | Category images not showing after update (cache bust) | Medium | Medium | CategoryForm |
| FE-BUG-002 | Slug not regenerated when name changes | Low | Low | CategoryForm |
| FE-BUG-003 | Parent select shows self as option on edit | High | High | CategoryTreeSelect |
| FE-BUG-004 | Featured toggle optimistic update not reverted on error | Medium | Medium | AdminCategoryList |
| FE-BUG-005 | No pagination on public listing (all categories loaded at once) | Medium | Medium | CategoryListingPage |

---

## API Routes for Frontend Integration

| Method | Endpoint | Auth | Frontend Usage |
|--------|----------|------|---------------|
| GET | `/api/v1/general/categories` | No | Public listing, navbar |
| GET | `/api/v1/general/categories/{slug}` | No | Public detail page |
| GET | `/api/v1/categories` | Yes (admin) | Admin list page |
| GET | `/api/v1/categories/{id}` | Yes (admin) | Admin edit form (load existing) |
| POST | `/api/v1/categories` | Yes (admin) | Admin create form |
| PUT | `/api/v1/categories/{id}` | Yes (admin) | Admin edit form |
| DELETE | `/api/v1/categories/{id}` | Yes (admin) | Admin list delete action |
| PUT | `/api/v1/categories/feature` | Yes (admin) | Admin featured toggle |
| GET | `/api/v1/featured-categories` | No | Public homepage section |
| GET | `/api/v1/component-data/categories` | No | Puck page builder block |
