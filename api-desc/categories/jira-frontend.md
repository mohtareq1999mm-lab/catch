# Category Module — Frontend Jira Tasks

## Task 1: Admin Category Listing Page — Tree table with CRUD

**Priority:** High
**Component:** Frontend — Admin Categories Page
**Story Points:** 8

**Description:** Build the admin category management page. Categories are hierarchical, so the listing must display a tree/indented table showing parent-child relationships.

**API Endpoints:**
- `GET /api/v1/categories?page=&per_page=&parent=&search=&feature-category=&order=&sortedBy=`

**Acceptance Criteria:**
- [ ] Tree table with indentation showing hierarchy level
- [ ] Columns: expand/collapse toggle, name, slug, level badge, products_count, is_featured badge, status badge, actions
- [ ] Pagination (categories at same level)
- [ ] Search field filters by name
- [ ] Filter toggles: parent (top-level), feature-category
- [ ] Each row shows edit/delete action buttons
- [ ] Loading skeleton while fetching
- [ ] Empty state: "No categories found" with "Create Category" CTA

---

## Task 2: Admin Category Create/Edit Form

**Priority:** High
**Component:** Frontend — Admin Category Form
**Story Points:** 5

**Description:** Build the create/edit form for categories with parent selector (hierarchy aware), translatable fields, image uploads, and product association.

**API Endpoints:**
- `POST /api/v1/categories`
- `PUT /api/v1/categories/{id}`
- `GET /api/v1/categories/{id}` (load existing)

**Acceptance Criteria:**
- [ ] Create mode: empty form, Edit mode: pre-filled
- [ ] Translatable fields: `name` with language tabs (en, ar)
- [ ] `details` as plain text (not translatable array — unlike brands)
- [ ] Parent selector: dropdown showing hierarchical tree (indented by level)
- [ ] Parent selector excludes current category (prevent self-parent)
- [ ] Parent selector loads top-level categories with children count
- [ ] Image uploads: `image-desktop` and `image-mobile` with preview
- [ ] Product multi-select: searchable dropdown
- [ ] Status toggle: active/inactive switch
- [ ] Form submits as `multipart/form-data`
- [ ] Validation errors displayed per field

---

## Task 3: Admin Category — Parent Selector with Cycle Prevention

**Priority:** High
**Component:** Frontend — Parent Selector
**Story Points:** 5

**Description:** Build a hierarchical category selector that prevents circular references.

**Acceptance Criteria:**
- [ ] Dropdown shows categories indented by level (e.g., `Face > Moisturizers > Creams`)
- [ ] Create mode: all categories available as parent
- [ ] Edit mode: current category and its descendants are disabled/greyed out (prevent cycle)
- [ ] Option "None (top-level)" for root categories
- [ ] Selected parent shows full breadcrumb path
- [ ] Loading state while fetching category tree
- [ ] Empty state: "No parent categories available" when only root categories exist

---

## Task 4: Admin Category — Featured Toggle

**Priority:** Medium
**Component:** Frontend — Featured Toggle
**Story Points:** 2

**Description:** Implement a featured toggle button on the category listing.

**API Endpoint:**
- `PUT /api/v1/categories/feature` with body `{ "id": 1 }`

**Acceptance Criteria:**
- [ ] Toggle button/switch on each category row
- [ ] Visual indicator for featured vs non-featured (star icon filled/empty)
- [ ] Click toggles immediately with optimistic UI update
- [ ] Revert on API error with error toast
- [ ] Disable toggle during API call
- [ ] Loading spinner on the toggling row

---

## Task 5: Admin Category — Delete with Children Warning

**Priority:** Medium
**Component:** Frontend — Delete Modal
**Story Points:** 3

**Description:** Implement delete confirmation that informs the user when a category cannot be deleted.

**API Endpoint:**
- `DELETE /api/v1/categories/{id}`

**Acceptance Criteria:**
- [ ] Clicking delete opens confirmation modal
- [ ] Modal shows category name and checks if has children
- [ ] If category has children: show warning "This category has subcategories. It cannot be deleted until all subcategories are removed."
- [ ] Disable confirm button if category has children (prevent 400 error)
- [ ] If no children: normal delete flow
- [ ] On success: remove row with success toast
- [ ] On 400 error: show specific error message about associated resources
- [ ] On 404 error: row already deleted, remove from table

---

## Task 6: Public Category Navigation — Navbar Tree

**Priority:** High
**Component:** Frontend — Public Navbar
**Story Points:** 5

**Description:** Render the multi-level category tree in the navigation bar.

**API Endpoint:**
- `GET /api/v1/featured-categories?limit=10` (backend navbar endpoint)

**Acceptance Criteria:**
- [ ] Fetch categories on mount (cached client-side)
- [ ] Render top-level categories as main nav items
- [ ] Hover/click shows dropdown with children (up to configured max level, default: 3)
- [ ] Each category links to category detail page (`/categories/{slug}`)
- [ ] Show category image/icon alongside name
- [ ] **Loading state:** Skeleton nav items
- [ ] **Empty state:** Hide category navigation
- [ ] **Error state:** Hide with console warning (non-critical UI)
- [ ] Responsive: mobile hamburger menu shows full tree

---

## Task 7: Public Category Detail Page — Category with Products

**Priority:** High
**Component:** Frontend — Public Category Page
**Story Points:** 5

**Description:** Build the public category detail page with subcategories and products.

**API Endpoint:**
- `GET /api/v1/general/categories/{slug}`

**Acceptance Criteria:**
- [ ] Page loads category info (name, image, details)
- [ ] Display subcategories as a grid/list (if any)
- [ ] Display products in a grid (if any)
- [ ] Product cards: image, name, price, rating, discount badge
- [ ] Products link to product detail pages
- [ ] Subcategories link to their own detail pages
- [ ] Breadcrumb navigation: Home > Category > Subcategory
- [ ] **Loading state:** Full skeleton
- [ ] **Empty state (no children, no products):** Show "No products in this category"
- [ ] **Error state (404):** "Category not found" with link to browse

---

## Task 8: Admin Category — Loading, Empty & Error States

**Priority:** High
**Component:** Frontend — State Handling
**Story Points:** 3

**Description:** Handle all non-happy-path states across the category admin pages.

**Acceptance Criteria:**
- [ ] **Listing loading:** Skeleton tree rows
- [ ] **Listing empty:** "No categories yet" with "Create your first category" button
- [ ] **Listing error:** Error message with "Retry" button
- [ ] **Form loading (edit):** Skeleton while fetching category data
- [ ] **Form loading (parent tree):** Parent selector shows "Loading categories..."
- [ ] **Form error:** Toast with error message
- [ ] **Form validation:** Inline field errors from API 422 response
- [ ] **Cycle detection error:** Toast "Circular reference detected" when changing parent
- [ ] **Delete error (has children):** Toast "Cannot delete: category has subcategories"
- [ ] **Delete error (not found):** Toast "Category already deleted"
- [ ] **Network error:** Toast "Network error, please try again" for all API calls

---

## Task 9: Admin Category — Multilingual Translatable `name` Field

**Priority:** Medium
**Component:** Frontend — i18n
**Story Points:** 2

**Description:** Handle the translatable `name` field (sent/received as language-keyed object).

**Request/Response format:**
```json
{
  "name": { "en": "Face", "ar": "وجه" }
}
```

**Acceptance Criteria:**
- [ ] Language tabs for each supported locale
- [ ] Each tab shows separate input for `name`
- [ ] On save, serialized to `{ "en": "...", "ar": "..." }` format
- [ ] On load, each tab shows correct translation
- [ ] Default language tab pre-selected
- [ ] Validation errors shown per-language

> **Note:** Unlike brands, `details` in categories is a plain string (not translatable array).

---

## Task 10: Homepage Featured Categories Section

**Priority:** Medium
**Component:** Frontend — Public Homepage
**Story Points:** 3

**Description:** Display featured categories section on the homepage, ordered by product count.

**API Endpoint:**
- `GET /api/v1/featured-categories?limit=5`

**Acceptance Criteria:**
- [ ] Fetch featured categories on mount
- [ ] Display as a grid of category cards
- [ ] Each card shows category image and name
- [ ] Cards ordered by product count (most products first)
- [ ] Each card links to category detail page
- [ ] **Loading state:** Skeleton cards
- [ ] **Empty state:** Hide section
- [ ] **Error state:** Hide section with console warning
- [ ] Responsive: 2 columns mobile, 4 columns tablet, 5 columns desktop
