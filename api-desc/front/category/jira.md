# Jira - Category Feature

## Epic: Product Category Management

### Story Points Estimate: 21

---

## User Stories

### US-001: View Category Listing (Public)
**As** a customer
**I want** to browse all product categories on the shop front
**So that** I can find products by category

**Acceptance Criteria:**
- `GET /api/v1/general/categories` returns paginated categories
- Supports search by category name
- Supports `parentOnly=true` filter for top-level categories
- Returns translated name, slug, image, and products_count

---

### US-002: View Category Detail (Public)
**As** a customer
**I want** to view a single category with its children and products
**So that** I can explore subcategories and products within a category

**Acceptance Criteria:**
- `GET /api/v1/general/categories/{slug}` returns category by slug
- Returns children categories with their product counts
- Returns products within the category (channel-filtered)
- Returns 404 for invalid slug

---

### US-003: Admin CRUD - Create Category
**As** an admin user
**I want** to create a new product category
**So that** I can organize products into categories

**Acceptance Criteria:**
- `POST /api/v1/categories` with multipart form data
- Required: name (multi-language), image-desktop, image-mobile
- Optional: parent_id, details (multi-language), products, slug
- Auto-generates slug from English name if not provided
- Automatically calculates level from parent hierarchy
- Returns created category with all fields

---

### US-004: Admin CRUD - Update Category
**As** an admin user
**I want** to update an existing category
**So that** I can correct or improve category information

**Acceptance Criteria:**
- `PUT /api/v1/categories/{id}` with partial data
- All fields optional
- Validates no circular reference when changing parent_id
- Updates descendant levels when parent_id changes
- Returns updated category

---

### US-005: Admin CRUD - Delete Category
**As** an admin user
**I want** to delete a category
**So that** I can remove obsolete categories

**Acceptance Criteria:**
- `DELETE /api/v1/categories/{id}` soft-deletes the category
- Does NOT cascade delete to child categories or products
- Returns 404 for already-deleted category
- Force delete removes permanently (including media)

---

### US-006: View Category Admin Listing
**As** an admin user
**I want** to view all categories with filtering options
**So that** I can manage the category tree

**Acceptance Criteria:**
- `GET /api/v1/categories` returns paginated list
- Filter by parent, search, featured, status (active/inactive)
- Sorted by level
- Returns with product counts

---

### US-007: Category Details Exclusion in List
**As** a developer
**I want** the `details` field excluded from listing responses
**So that** API responses remain lightweight

**Acceptance Criteria:**
- `details` field present in `categories.show` and `categories/{id}` responses
- `details` field absent in `categories.index` response

---

### US-008: Featured Categories
**As** an admin user
**I want** to mark categories as featured
**So that** they appear prominently on the shop front

**Acceptance Criteria:**
- `PUT /api/v1/categories/feature` toggles `is_featured` flag
- Requires `update-category` permission
- `GET /api/v1/featured-categories` returns featured categories sorted by product count (public)

---

## Tasks

| Task ID | Description | Estimate (h) | Dependencies |
|---------|-------------|-------------|--------------|
| T-001 | Create categories table migration | 2 | None |
| T-002 | Create Category model with relationships | 3 | T-001 |
| T-003 | Create CategoryRepository | 2 | T-002 |
| T-004 | Create CategoryController (Marvel) with CRUD | 4 | T-003 |
| T-005 | Create CategoryController (General) | 2 | T-003 |
| T-006 | Create FormRequests (create, update, toggle) | 3 | T-002 |
| T-007 | Create API Resources (Marvel + General) | 4 | T-002 |
| T-008 | Create CategoryService | 3 | T-002 |
| T-009 | Create CategoryHierarchyService | 4 | T-002 |
| T-010 | Create GraphQL schema and mutator | 3 | T-002 |
| T-011 | Create CategoryObserver for activity logging | 2 | T-002 |
| T-012 | Create featured categories endpoint | 1 | T-002 |
| T-013 | Create analytics endpoints | 3 | T-002 |
| T-014 | Write translation keys | 1 | None |
| T-015 | Write tests (12 test files) | 16 | T-001 to T-012 |

---

## Bug Tickets

| Ticket | Description | Priority | Severity |
|--------|-------------|----------|----------|
| BUG-001 | Legacy JSON slug handling in retrieved event | Low | Low |
| BUG-002 | No rate limiting on public category endpoints | Low | Low |
