# Backend - Content Page Feature

## Overview

The Page feature spans two parallel systems and supporting infrastructure:

1. **Content Pages** — Sections-based CMS with dynamic section attachments
2. **CMS Pages** — Puck-compatible pages with structured content JSON
3. **Component Data** — SSR-optimized endpoints for Puck block rendering
4. **Sections & Section Types** — Reusable content blocks configurable per page

## Key Files

### 1. Models

#### ContentPage - `packages/marvel/src/Database/Models/ContentPage.php`
**Table:** `content_pages`

**Traits:** `HasTranslations` (Spatie)

**Translatable:** `['title']`

**Fillable:** `['title', 'slug', 'is_active']`

**Casts:** `['is_active' => 'boolean']`

**Relationships:**
- `sections()` — `hasMany(Section::class)->orderBy('order')`

**Methods:**
- `attachSectionsByIds(array $sectionIds)` — Syncs section attachments

#### Section - `packages/marvel/src/Database/Models/Section.php`
**Table:** `sections`

**Traits:** `SortableTrait` (Spatie), `HasTranslations`

**Translatable:** `['title']`

**Fillable:** `['type', 'title', 'order', 'endpoint', 'is_active', 'content_page_id', 'title_visible', 'setting']`

**Casts:** `['is_active' => 'boolean', 'order' => 'integer', 'title_visible' => 'boolean', 'setting' => 'array']`

**Relationships:**
- `contentPage()` — `belongsTo(ContentPage::class)`

#### CmsPage - `packages/marvel/src/Database/Models/CmsPage.php`
**Table:** `cms_pages`

**Traits:** `SoftDeletes`

**Fillable:** `['path', 'slug', 'title', 'content', 'data', 'meta']`

**Casts:** `['content' => 'array', 'data' => 'array', 'meta' => 'array']`

**Accessor:** `getPuckDataAttribute()` — returns Puck-format data with legacy fallback

#### SectionType - `packages/marvel/src/Database/Models/SectionType.php`
**Table:** `section_types`

**Fillable:** `['type']`

**Route Key:** `type` (custom route key name)

**Relationships:**
- `settings()` — `hasMany(SectionTypeSetting::class)`

#### SectionTypeSetting - `packages/marvel/src/Database/Models/SectionTypeSetting.php`
**Table:** `section_type_settings`

**Fillable:** `['section_type_id', 'setting_key', 'value']`

**Casts:** `['value' => 'array']`

### 2. Controllers

| Controller | Methods | Auth |
|-----------|---------|------|
| `General\ContentPageController` | `index()`, `show($slug)` | Public |
| `ContentPageController` (Marvel) | `index`, `store`, `show`, `update`, `destroy`, `toggleActive`, `attachSections` | Admin + permissions |
| `CmsPageController` (Marvel) | `index`, `show`, `showByPath`, `store`, `storePuckPage`, `update`, `destroy` | Public read, Admin write |
| `SectionController` (Marvel) | `index`, `store`, `show`, `update`, `destroy`, `reorder`, `toggleStatus`, `getTypeSection` | Admin + permissions |
| `SectionTypeController` (Marvel) | `index`, `store`, `show`, `update`, `destroy`, `settings`, `updateSettings`, `byType` | Admin + permissions |
| `ComponentDataController` (Marvel) | `flashSaleProducts`, `categories`, `collections`, `popularProducts`, `bestSellingProducts` | Public |

### 3. Services

| Service | Key Methods |
|---------|-------------|
| `CmsPageService` | `paginate()`, `getBySlug()`, `getByPath()`, `create()`, `update()`, `delete()` |
| `ComponentDataService` | `getFlashSaleProducts()`, `getCategories()`, `getCollections()`, `getPopularProducts()`, `getBestSellingProducts()` |
| `SectionTypeService` (app) | `getAll()`, `getById()`, `getByType()`, `getSettingsGrouped()`, `createType()`, `updateType()`, `deleteType()`, `upsertSettings()` |

### 4. Repository

**CmsPageRepository** - `packages/marvel/src/Database/Repositories/CmsPageRepository.php`
- Extends `BaseRepository`, model: `CmsPage`
- Searchable: `slug` (like), `title` (like)

### 5. Form Requests

| Request | Rules Highlights |
|---------|-----------------|
| `StoreContentPageRequest` | `title.*` nullable, string, max:30, unique translation |
| `UpdateContentPageRequest` | `title.*` sometimes, unique (ignore self); `is_active` sometimes |
| `AttachSectionsRequest` | `sections` present, array; `sections.*` exists:sections,id |
| `StoreSectionRequest` | `type` required, exists; `title.*` required, unique; `setting.back` slug validation |
| `UpdateSectionRequest` | All fields sometimes; unique (ignore self) |
| `StoreSectionTypeRequest` | `type` required, unique:section_types |
| `UpdateSectionTypeRequest` | `type` sometimes, unique (ignore self) |
| `CmsPageRequest` | `path` required, unique (ignore self); `slug` nullable, unique; `title` required |

### 6. API Resources

| Resource | Key Fields |
|----------|-----------|
| `ContentPageResource` (app) | `id`, `title`, `slug`, `is_active`, `sections` (when loaded) |
| `SectionResource` (app) | `id`, `type`, `title` (conditional), `is_active`, `endpoint`, `order`, `setting` |
| `CmsPageResource` (Marvel) | `id`, `slug`, `title`, `content` (sorted), `meta`, `created_at`, `updated_at` |

### 7. Permissions

| Permission | Usage |
|-----------|-------|
| `view-content-pages` | ContentPage index/show |
| `create-content-pages` | ContentPage store |
| `update-content-pages` | ContentPage update/toggle/attach |
| `delete-content-pages` | ContentPage destroy |
| `view-sections` | Section index/show/types |
| `create-sections` | Section store |
| `update-sections` | Section update/reorder/toggle |
| `delete-sections` | Section destroy |
| `view-section-types` | SectionType index/show |
| `create-section-types` | SectionType store |
| `update-section-types` | SectionType update/settings |
| `delete-section-types` | SectionType destroy |
| `create-cms-page` | CmsPage store |
| `update-cms-page` | CmsPage update |
| `delete-cms-page` | CmsPage destroy |
| `save-puck-page` | Puck page store |

## Data Flow (Public Page Rendering)

```
Client
  |
  GET /api/v1/general/pages/home
  |
  v
General\ContentPageController@show('home')
  |
  v
ContentPage::where('slug', 'home')
    ->where('is_active', true)
    ->with(['sections' => fn($q) => $q->where('is_active', true)->ordered()])
    ->firstOrFail()
  |
  v
ContentPageResource::make($page)
  |
  Maps:
    - id, title, slug, is_active
    - sections → SectionResource collection
      Each section:
        - id, type, title (if title_visible)
        - is_active
        - endpoint: built from 'general/{type}?{back_params}'
        - order
        - setting: merged from section + SectionType defaults
  |
  v
JSON Response { data: { id, title, slug, sections: [...] } }
```

## Data Flow (Puck Page Save)

```
Client (Puck Editor)
  |
  POST /api/v1/puck/page
  Body: { path: "/about", title: "About Us", data: { root: {}, content: [...] } }
  |
  v
CmsPageController@storePuckPage(CmsPageRequest $request)
  |
  +-- Upsert by path: find existing or create new
  +-- Set: path, title, slug (from path), data (Puck JSON), content (null)
  |
  v
Response: { data: { id, slug, title, content, meta, created_at, updated_at } }
```
