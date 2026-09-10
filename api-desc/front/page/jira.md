# Jira - Content Page Feature

## Epic: Content Management & Page Builder

### Story Points Estimate: 21

---

## User Stories

### US-001: View Content Page (Public)
**As** a customer
**I want** to view CMS pages (About Us, Contact, etc.)
**So that** I can read store information

**Acceptance Criteria:**
- `GET /api/v1/general/pages/{slug}` returns page with active sections
- Sections include type-specific data (sliders, banners, products, etc.)
- Returns 404 for invalid/disabled pages

---

### US-002: View CMS Page via Puck (Public)
**As** a customer
**I want** to view pages built with the Puck page builder
**So that** I can see custom-designed pages

**Acceptance Criteria:**
- `GET /api/v1/cms-pages/{slug}` returns page content
- `GET /api/v1/puck/page?path=/about` returns page by path
- Content is rendered in order
- Supports both Puck format and legacy content format

---

### US-003: Admin Manage Content Pages
**As** an admin user
**I want** to create, edit, and manage content pages with sections
**So that** I can build CMS pages with dynamic blocks

**Acceptance Criteria:**
- `POST /api/v1/content-pages` with translatable title
- `PUT /api/v1/content-pages/{id}` update title/active status
- `PATCH /api/v1/content-pages/{id}/toggle-active` enable/disable
- `POST /api/v1/content-pages/{id}/attach-sections` assign/reorder sections
- `DELETE /api/v1/content-pages/{id}` remove page

---

### US-004: Admin Manage Sections
**As** an admin user
**I want** to create and configure content sections
**So that** I can build reusable content blocks

**Acceptance Criteria:**
- `POST /api/v1/sections` create section with type, title, settings
- `PUT /api/v1/sections/{id}` update section config
- `POST /api/v1/sections/reorder` drag-and-drop reorder
- `PATCH /api/v1/sections/{id}/toggle-active` enable/disable
- Sections have translatable titles and type-specific settings

---

### US-005: Admin Manage Section Types
**As** an admin user
**I want** to define available section types and their settings
**So that** the page builder supports the right content blocks

**Acceptance Criteria:**
- `POST /api/v1/section-types` register new section type
- `POST /api/v1/section-types/{type}/settings` configure front/back settings
- `GET /api/v1/section-types/{type}/settings` retrieve settings

---

### US-006: CMS Pages (Puck) Management
**As** an editor
**I want** to create and edit pages using the Puck page builder
**So that** I can design custom page layouts

**Acceptance Criteria:**
- `POST /api/v1/puck/page` upsert page by path
- `GET /api/v1/puck/page?path=/about` retrieve page
- Supports structured JSON `data` field for Puck components
- Legacy `content` format supported via fallback

---

### US-007: Component Data Endpoints (Puck SSR)
**As** a frontend developer
**I want** dedicated endpoints for Puck component data
**So that** components can be rendered server-side

**Acceptance Criteria:**
- `GET /api/v1/component-data/categories?limit=10&topLevelOnly=true`
- `GET /api/v1/component-data/flash-sale-products?limit=8`
- `GET /api/v1/component-data/collections?limit=6`
- `GET /api/v1/component-data/popular-products?limit=12`
- `GET /api/v1/component-data/best-selling-products?limit=12`

---

## Tasks

| Task ID | Description | Estimate (h) | Dependencies |
|---------|-------------|-------------|--------------|
| T-001 | Create content_pages, sections, section_types, section_type_settings migrations | 4 | None |
| T-002 | Create ContentPage, Section, SectionType, SectionTypeSetting models | 4 | T-001 |
| T-003 | Create ContentPageController (Marvel) with CRUD + toggle + attach | 4 | T-002 |
| T-004 | Create ContentPageController (General/Public) | 2 | T-002 |
| T-005 | Create SectionController with reorder and toggle | 4 | T-002 |
| T-006 | Create SectionTypeController with settings management | 3 | T-002 |
| T-007 | Create FormRequests (StoreContentPage, UpdateContentPage, AttachSections, StoreSection, UpdateSection, StoreSectionType, UpdateSectionType) | 4 | T-002 |
| T-008 | Create API Resources (ContentPageResource, SectionResource) | 2 | T-002 |
| T-009 | Create SectionTypeService | 2 | T-002 |
| T-010 | Create CmsPage model, migration, and repository | 3 | None |
| T-011 | Create CmsPageController and CmsPageService | 4 | T-010 |
| T-012 | Create CmsPageResource | 1 | T-010 |
| T-013 | Create CmsPageRequest | 1 | T-010 |
| T-014 | Create ComponentDataController and ComponentDataService | 4 | None |
| T-015 | Write translation keys | 1 | None |
| T-016 | Seed page, section, and section type data | 3 | T-001 |
| T-017 | Write tests (ContentPageSectionTypeApiTest + CmsPageTest) | 10 | T-001 to T-014 |

---

## Bug Tickets

| Ticket | Description | Priority | Severity |
|--------|-------------|----------|----------|
| BUG-001 | Permission labels missing for page/section permissions | Medium | Medium |
| BUG-002 | Two parallel page systems (ContentPage vs CmsPage) causing confusion | Medium | Medium |
| BUG-003 | Section setting fallback logic in Resource (not testable independently) | Low | Low |
