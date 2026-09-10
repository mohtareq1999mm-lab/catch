# Frontend - Category Feature

## Status

**No dedicated frontend Vue/React components** were found in `resources/js/` for categories.

## Consumption Patterns

The Category feature is consumed in the following ways on the frontend:

### 1. Puck Page Builder - CategoryBlock Component

The `component-data/categories` endpoint (`packages/marvel/src/Services/ComponentDataService.php`) provides category data for the **Puck** page builder's `CategoryBlock` component. This is a server-rendered or headless component that displays categories as a block on pages.

- **Endpoint:** `GET /api/v1/component-data/categories?limit=10&language=en&topLevelOnly=true`
- **Returns:** Array of categories with id, name, slug, and image

### 2. API-Driven Frontend (Headless/SPA)

The public REST endpoints are designed for headless frontends (Vue/React/Next.js):

- **Category Listings:** `GET /api/v1/general/categories` — displays grid/list of categories on the shop front
- **Category Detail:** `GET /api/v1/general/categories/{slug}` — shows category with children and products
- **Navbar:** `GET /api/v1/general/categories` with recursive children — drives navigation menus

### 3. Admin Dashboard

The admin API endpoints are consumed by a yet-to-be-identified admin panel (likely Inertia or separate SPA):

- CRUD forms for category management
- Featured category toggle
- Image upload for desktop/mobile category images

## What a Frontend Implementation Would Need

### Public Shop Pages

```
CategoryList.vue (or similar)
  Props: none (fetches from API)
  Fetches: GET /api/v1/general/categories
  Renders: category cards with image, name, product count
  
CategoryDetail.vue
  Props: slug (route param)
  Fetches: GET /api/v1/general/categories/{slug}
  Renders: category info, child categories list, product grid
  
CategoryNavbar.vue
  Props: maxLevel (default: 3)
  Fetches: GET /api/v1/general/categories (with recursive children)
  Renders: nested dropdown/mega menu
```

### Admin Pages

```
CategoryList.vue
  Fetches: GET /api/v1/categories (paginated)
  Actions: create, edit, delete, toggle featured
  
CategoryForm.vue
  Fields: name (multi-lang), slug, details (multi-lang), parent_id,
          image-desktop (upload), image-mobile (upload), status toggle
  Submit: POST /api/v1/categories (create) or PUT /api/v1/categories/{id} (update)
```

### Request/Response Examples

**List Categories (Public):**
```
GET /api/v1/general/categories?search=shoes&parentOnly=true
Response:
{
  "data": [
    {
      "id": 1,
      "name": "Shoes",
      "slug": "shoes",
      "image": { "desktop": "url", "mobile": "url" },
      "products_count": 45
    }
  ]
}
```

**Category Detail (Public):**
```
GET /api/v1/general/categories/shoes
Response:
{
  "data": {
    "id": 1,
    "name": "Shoes",
    "slug": "shoes",
    "image": { "desktop": "url", "mobile": "url" },
    "products_count": 45,
    "details": "Category description",
    "children": [ ... ],
    "products": [ ... ]
  }
}
```
