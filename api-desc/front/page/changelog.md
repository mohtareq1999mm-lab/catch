# Changelog - Content Page Feature

All notable changes to the Content Page feature should be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

### Added
- Section-based CMS page management with dynamic block attachments
- `ContentPage` model with translatable titles (Spatie Translatable)
- `Section` model with translatable titles, sortable ordering, type-specific settings
- `SectionType` and `SectionTypeSetting` models for reusable content block definitions
- `CmsPage` model with Puck page builder support and legacy content format fallback

### Public API (App Layer)
- `GET /api/v1/general/pages` — Public content page listing
- `GET /api/v1/general/pages/{slug}` — Public content page with active sections
- Section endpoint auto-generation from type + back settings

### CMS Pages API (Marvel Package)
- `GET /api/v1/cms-pages` — List CMS pages
- `GET /api/v1/cms-pages/{slug}` — Show CMS page by slug
- `GET /api/v1/puck/page?path=/...` — Show page by path (Puck format)
- `POST /api/v1/cms-pages` — Create CMS page
- `POST /api/v1/puck/page` — Upsert Puck page by path
- `PUT /api/v1/cms-pages/{id}` — Update CMS page
- `DELETE /api/v1/cms-pages/{id}` — Delete CMS page

### Content Pages API (Admin)
- `GET /api/v1/content-pages` — Paginated list with sections
- `POST /api/v1/content-pages` — Create page (translatable title)
- `GET /api/v1/content-pages/{id}` — Show with sections
- `PUT /api/v1/content-pages/{id}` — Update page
- `DELETE /api/v1/content-pages/{id}` — Delete page
- `PATCH /api/v1/content-pages/{id}/toggle-active` — Enable/disable page
- `POST /api/v1/content-pages/{id}/attach-sections` — Sync section attachments

### Sections API (Admin)
- `GET /api/v1/sections` — List all sections
- `POST /api/v1/sections` — Create section with type, title, settings
- `GET /api/v1/sections/types` — Get unique section types
- `POST /api/v1/sections/reorder` — Drag-and-drop reorder
- `PATCH /api/v1/sections/{id}/toggle-active` — Enable/disable

### Section Types API (Admin)
- `GET /api/v1/section-types` — List all types
- `POST /api/v1/section-types` — Register new type
- `GET /api/v1/section-types/{type}/settings` — Get front/back settings
- `POST /api/v1/section-types/{type}/settings` — Update settings

### Component Data Endpoints (Puck SSR)
- `GET /api/v1/component-data/categories` — Category block data
- `GET /api/v1/component-data/collections` — Collection block data
- `GET /api/v1/component-data/flash-sale-products` — Flash sale data
- `GET /api/v1/component-data/popular-products` — Popular products
- `GET /api/v1/component-data/best-selling-products` — Best-selling products

### Infrastructure
- Permission enums for content pages, sections, section types, and CMS pages
- SectionTypeService for type and settings management
- CmsPageService with transactional create/update/delete
- CmsPageRepository with searchable slug/title
- ContentPageSeeder with "home" page and 17 sections
- SectionTypeSettingSeeder with 8 section types
- OpenAPI spec for Puck API (`packages/marvel/docs/puck-api.yaml`)
- Translation constants for section and type CRUD messages (EN + AR)

### Tests
- `ContentPageSectionTypeApiTest` — 1,069 lines covering auth, permissions, CRUD, attach, reorder, translations
- `CmsPageTest` — 127 lines covering public fetch, editor permissions, CRUD

## [Unreleased - Technical Debt]

- [ ] Add permission translation labels for page/section permissions
- [ ] Consolidate or document the relationship between ContentPage and CmsPage
- [ ] Extract Section setting fallback logic from Resource into a Service
- [ ] Add activity logging (Observer pattern) for page/section CRUD
