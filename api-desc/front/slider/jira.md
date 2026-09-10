# Jira - Slider Feature

## Epic: Hero Slider Management

### Story Points Estimate: 13

---

## User Stories

### US-001: View Slider Listing (Public)
**As** a customer
**I want** to see slider banners on the homepage
**So that** I can see promoted products and offers

**Acceptance Criteria:**
- `GET /api/v1/general/sliders` returns active sliders ordered by position
- Supports `?limit=` to control count
- Returns translated title, desktop + mobile images, associated products
- Supports date range filtering

---

### US-002: View Slider by Slug (Public)
**As** a customer
**I want** to view a single slider with its products
**So that** I can see products associated with a promotion

**Acceptance Criteria:**
- `GET /api/v1/general/sliders/{slug}` returns single slider
- Loads associated products with pricing
- Returns 404 for invalid slug

---

### US-003: Admin CRUD - Create Slider
**As** an admin user
**I want** to create a new slider banner
**So that** I can promote products or campaigns on the homepage

**Acceptance Criteria:**
- `POST /api/v1/sliders` with multipart form data
- Required: title (multi-language), image_desktop, image_mobile
- Optional: status, products
- Slug auto-generated from English title
- Default sort order assigned automatically
- Returns created slider

---

### US-004: Admin CRUD - Update Slider
**As** an admin user
**I want** to update a slider's content or images
**So that** I can refresh promotional banners

**Acceptance Criteria:**
- `PUT /api/v1/sliders/{id}` with partial data
- All fields optional
- Replaces images when new files provided
- Title unique check ignores current slider
- Returns updated slider

---

### US-005: Admin CRUD - Delete Slider
**As** an admin user
**I want** to delete a slider
**So that** I can remove outdated promotions

**Acceptance Criteria:**
- `DELETE /api/v1/sliders/{id}` soft-deletes the slider
- Returns 404 for already-deleted slider
- Product pivot records preserved on soft delete

---

### US-006: Toggle Slider Status
**As** an admin user
**I want** to activate/deactivate a slider without deleting it
**So that** I can control which promotions are live

**Acceptance Criteria:**
- `PATCH /api/v1/sliders/change-status` with `{ id }`
- Toggles `status` between 1 and 0
- Requires `update-slider` permission
- Returns 422 for missing or invalid ID

---

### US-007: Reorder Sliders
**As** an admin user
**I want** to drag and drop sliders to reorder them
**So that** I can control the sequence of banners on the homepage

**Acceptance Criteria:**
- `PUT /api/v1/sliders/reorder` with `{ sliders: [id1, id2, ...] }`
- Updates order column for all sliders atomically
- Requires `update-slider` permission
- Returns 422 for missing or invalid IDs

---

## Tasks

| Task ID | Description | Estimate (h) | Dependencies |
|---------|-------------|-------------|--------------|
| T-001 | Create sliders table migration | 1 | None |
| T-002 | Create slider_product pivot migration | 1 | T-001 |
| T-003 | Create Slider model with relationships | 2 | T-001 |
| T-004 | Create SliderRepository | 2 | T-003 |
| T-005 | Create SliderController (Marvel) with CRUD | 4 | T-004 |
| T-006 | Create SliderController (General) | 2 | T-003 |
| T-007 | Create FormRequests (create, update) | 2 | T-003 |
| T-008 | Create API Resources (Admin + Public) | 2 | T-003 |
| T-009 | Create SliderService | 2 | T-003 |
| T-010 | Add changeStatus and reorder methods | 2 | T-004 |
| T-011 | Create import/export sheets | 3 | T-003 |
| T-012 | Write translation keys (en, ar, de) | 1 | None |
| T-013 | Seed slider data | 2 | T-001 |
| T-014 | Write tests (SliderApiTest) | 8 | T-001 to T-010 |

---

## Bug Tickets

| Ticket | Description | Priority | Severity |
|--------|-------------|----------|----------|
| BUG-001 | Duplicate route registration for GET /sliders | Low | Low |
| BUG-002 | Media collection naming inconsistency (create vs update) | Low | Low |
| BUG-003 | Missing German translations for slider messages | Low | Low |
| BUG-004 | No activity logging for slider CRUD operations | Low | Low |
