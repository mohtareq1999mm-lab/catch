# Content Page Feature - API Investigation

## Feature Name

Content Pages & Page Builder (CMS Pages, Sections, Puck Integration)

## Description

The Page feature provides a full page management system with three interconnected subsystems:

1. **Content Pages** (`content_pages`) — Sections-based CMS pages with dynamic section attachments, ordering, and content type support (sliders, banners, promotions, products, etc.)
2. **CMS Pages** (`cms_pages`) — Supports the **Puck** drag-and-drop page builder format with rendered content JSON and legacy content format fallback
3. **Component Data** — SSR-optimized endpoints serving structured data for Puck components (categories, collections, flash sales, popular/best-selling products)

## Architecture Overview

```
[Client / Puck Editor]
    |
    |--- GET /api/v1/general/pages                    (Public - Content Pages)
    |--- GET /api/v1/general/pages/{slug}             (Public - Content Pages)
    |
    |--- GET    /api/v1/cms-pages                     (Public - CMS Pages)
    |--- GET    /api/v1/cms-pages/{slug}              (Public - CMS Pages)
    |--- GET    /api/v1/puck/page                     (Public - Puck by path)
    |--- POST   /api/v1/cms-pages                     (Admin - CMS create)
    |--- POST   /api/v1/puck/page                     (Admin - Puck upsert)
    |--- PUT    /api/v1/cms-pages/{id}                (Admin - CMS update)
    |--- DELETE /api/v1/cms-pages/{id}                (Admin - CMS delete)
    |
    |--- GET    /api/v1/content-pages                 (Admin)
    |--- POST   /api/v1/content-pages                 (Admin)
    |--- GET    /api/v1/content-pages/{id}            (Admin)
    |--- PUT    /api/v1/content-pages/{id}            (Admin)
    |--- DELETE /api/v1/content-pages/{id}            (Admin)
    |--- PATCH  /api/v1/content-pages/{id}/toggle-active (Admin)
    |--- POST   /api/v1/content-pages/{id}/attach-sections (Admin)
    |
    |--- GET    /api/v1/sections                      (Admin)
    |--- POST   /api/v1/sections                      (Admin)
    |--- PUT    /api/v1/sections/reorder              (Admin)
    |--- GET    /api/v1/sections/types                (Admin)
    |
    |--- GET    /api/v1/section-types                 (Admin)
    |--- GET    /api/v1/component-data/*              (Public - Puck SSR)
    |
    v
[ContentPageController / CmsPageController / SectionController / ComponentDataController]
    |
    v
[CmsPageService / SectionTypeService / ComponentDataService]
    |
    v
[ContentPage / Section / CmsPage / SectionType / SectionTypeSetting Models]
    |
    v
[content_pages / sections / cms_pages / section_types / section_type_settings tables]
```

## Key Endpoints

### Public API (routes/api.php - prefix: `v1/general`)

| Method | URI | Controller | Auth |
|--------|-----|-----------|------|
| GET | `/v1/general/pages` | `General\ContentPageController@index` | No |
| GET | `/v1/general/pages/{slug}` | `General\ContentPageController@show` | No |

### CMS Pages (packages/marvel/src/Rest/Routes.php - prefix: `v1`)

| Method | URI | Controller | Auth |
|--------|-----|-----------|------|
| GET | `/v1/cms-pages` | `CmsPageController@index` | No |
| GET | `/v1/cms-pages/{slug}` | `CmsPageController@show` | No |
| GET | `/v1/puck/page` | `CmsPageController@showByPath` | No |
| POST | `/v1/cms-pages` | `CmsPageController@store` | Admin (super_admin/editor) |
| PUT | `/v1/cms-pages/{id}` | `CmsPageController@update` | Admin |
| DELETE | `/v1/cms-pages/{id}` | `CmsPageController@destroy` | Admin |
| POST | `/v1/puck/page` | `CmsPageController@storePuckPage` | Admin (upsert by path) |

### Content Pages (Admin)

| Method | URI | Permission |
|--------|-----|-----------|
| GET | `/v1/content-pages` | `view-content-pages` |
| POST | `/v1/content-pages` | `create-content-pages` |
| GET | `/v1/content-pages/{id}` | `view-content-pages` |
| PUT | `/v1/content-pages/{id}` | `update-content-pages` |
| DELETE | `/v1/content-pages/{id}` | `delete-content-pages` |
| PATCH | `/v1/content-pages/{id}/toggle-active` | `update-content-pages` |
| POST | `/v1/content-pages/{id}/attach-sections` | `update-content-pages` |

### Sections & Section Types (Admin)

| Method | URI | Permission |
|--------|-----|-----------|
| GET | `/v1/sections` | `view-sections` |
| POST | `/v1/sections` | `create-sections` |
| GET | `/v1/sections/types` | `view-sections` |
| POST | `/v1/sections/reorder` | `update-sections` |
| GET | `/v1/section-types` | `view-section-types` |
| POST | `/v1/section-types` | `create-section-types` |

### Component Data (Public - Puck SSR)

| Method | URI | Auth |
|--------|-----|------|
| GET | `/v1/component-data/flash-sale-products` | No |
| GET | `/v1/component-data/categories` | No |
| GET | `/v1/component-data/collections` | No |
| GET | `/v1/component-data/popular-products` | No |
| GET | `/v1/component-data/best-selling-products` | No |

## Key Files

| Layer | Path |
|-------|------|
| Model (ContentPage) | `packages/marvel/src/Database/Models/ContentPage.php` |
| Model (Section) | `packages/marvel/src/Database/Models/Section.php` |
| Model (SectionType) | `packages/marvel/src/Database/Models/SectionType.php` |
| Model (SectionTypeSetting) | `packages/marvel/src/Database/Models/SectionTypeSetting.php` |
| Model (CmsPage) | `packages/marvel/src/Database/Models/CmsPage.php` |
| Service (CmsPage) | `packages/marvel/src/Services/CmsPageService.php` |
| Service (ComponentData) | `packages/marvel/src/Services/ComponentDataService.php` |
| Service (SectionType) | `app/Services/General/SectionTypeService.php` |
| Repository (CmsPage) | `packages/marvel/src/Database/Repositories/CmsPageRepository.php` |
| Controller (Public) | `app/Http/Controllers/Api/General/ContentPageController.php` |
| Controller (Admin ContentPage) | `packages/marvel/src/Http/Controllers/ContentPageController.php` |
| Controller (CmsPage) | `packages/marvel/src/Http/Controllers/CmsPageController.php` |
| Controller (Section) | `packages/marvel/src/Http/Controllers/SectionController.php` |
| Controller (SectionType) | `packages/marvel/src/Http/Controllers/SectionTypeController.php` |
| Controller (ComponentData) | `packages/marvel/src/Http/Controllers/ComponentDataController.php` |
| Resource (ContentPage) | `app/Http/Resources/Pages/ContentPageResource.php` |
| Resource (Section) | `app/Http/Resources/Pages/SectionResource.php` |
| Resource (CmsPage) | `packages/marvel/src/Http/Resources/CmsPageResource.php` |
| Enum (Permission) | `packages/marvel/src/Enums/Permission.php` |

## Tech Stack

- **Laravel** with Eloquent ORM
- **Spatie Translatable** for localized titles
- **Spatie Sortable** for section ordering
- **Puck** headless page builder (OpenAPI spec in `packages/marvel/docs/puck-api.yaml`)
- **Soft Deletes** on `CmsPage` model
