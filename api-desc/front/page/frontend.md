# Frontend - Content Page Feature

## Status

**No dedicated frontend Vue/React components** found in `resources/js/`. The frontend is a separate SPA with a well-defined API contract for the **Puck** page builder.

## Consumption Patterns

### 1. Page Rendering (Public Shop)

The shop frontend fetches pages by slug to render CMS content:

```
GET /api/v1/general/pages/home

Response:
{
  "data": {
    "id": 1,
    "title": "Home",
    "slug": "home",
    "is_active": true,
    "sections": [
      {
        "id": 1,
        "type": "sliders",
        "title": "Hero Sliders",
        "is_active": true,
        "endpoint": "/api/v1/general/sliders?limit=5",
        "order": 0,
        "setting": {
          "autoplay": true,
          "slider_speed": 5000
        }
      },
      {
        "id": 2,
        "type": "categories",
        "title": "Shop by Category",
        "is_active": true,
        "endpoint": "/api/v1/general/categories?parentOnly=true",
        "order": 1,
        "setting": {}
      }
    ]
  }
}
```

The frontend iterates over `sections`, reads the `type` and `endpoint`, fetches data from the component data endpoint, and renders the appropriate component block.

### 2. Puck Page Builder (Admin)

The Puck page builder is a drag-and-drop editor that saves pages as structured content JSON:

```
POST /api/v1/puck/page
Authorization: Bearer <admin_token>
Body: {
  "path": "/about",
  "title": "About Us",
  "slug": "about-us",
  "data": {
    "root": { "props": {} },
    "content": [
      { "type": "HeroBlock", "props": { "heading": "About Us", "subtitle": "..." } },
      { "type": "TextBlock", "props": { "body": "<p>Our story...</p>" } }
    ]
  },
  "meta": { "author": "admin" }
}
```

Puck retrieves pages by path:

```
GET /api/v1/puck/page?path=/about
```

### 3. Component Data (Puck SSR)

Puck components fetch their data from dedicated endpoints:

```
GET /api/v1/component-data/categories?limit=10
GET /api/v1/component-data/flash-sale-products?limit=8
GET /api/v1/component-data/collections?limit=6
GET /api/v1/component-data/popular-products?limit=12
GET /api/v1/component-data/best-selling-products?limit=12
```

## What a Frontend Implementation Would Need

### Public Components

```
PageRenderer.vue
  Props: slug (string)
  Fetches: GET /api/v1/general/pages/{slug}
  Renders: iterates over sections and renders the matching block component

SectionBlock.vue
  Props: section (object)
  Renders: a section wrapper (title, conditional visibility)
  Fetches the section's endpoint to get data

SliderBlock.vue  (type: sliders)
CategoryBlock.vue (type: categories)
PromotionBlock.vue (type: promotions)
FlashSaleBlock.vue (type: flash_sale)
ProductGridBlock.vue (type: popular_products / best_selling_products / products)

PageNotFound.vue
  Renders: 404 page when slug not found
```

### Admin Components (Puck Integration)

```
PuckEditorPage.vue
  Fetches: GET /api/v1/puck/page?path={path}
  Integrates with Puck React editor
  Saves: POST /api/v1/puck/page

CmsPageList.vue
  Fetches: GET /api/v1/cms-pages
  Actions: create, edit, delete

CmsPageForm.vue
  Fields: title, slug, path, content (JSON editor or visual), meta
  Submit: POST /api/v1/cms-pages or PUT /api/v1/cms-pages/{id}

ContentPageList.vue
  Fetches: GET /api/v1/content-pages
  Actions: create, edit, delete, toggle active, attach sections

ContentPageForm.vue
  Fields: title (multi-language)
  Sections: drag-and-drop section assignment via attach-sections endpoint
  Submit: POST /api/v1/content-pages or PUT /api/v1/content-pages/{id}
```

### Page Structure for Sections-Based Pages

```
{
  "page": {
    "id": 1,
    "title": "Home",
    "slug": "home",
    "sections": [
      { "type": "sliders",     "endpoint": "...", "setting": { "autoplay": true } },
      { "type": "banners",     "endpoint": "...", "setting": {} },
      { "type": "categories",  "endpoint": "...", "setting": { "parentOnly": true } },
      { "type": "promotions",  "endpoint": "...", "setting": {} },
      { "type": "flash_sale",  "endpoint": "...", "setting": { "limit": 8 } }
    ]
  }
}
```

Each section type maps to a frontend block component that:
1. Reads the section's `endpoint` URL
2. Fetches data from that endpoint
3. Renders the component using the data and `setting` configuration
