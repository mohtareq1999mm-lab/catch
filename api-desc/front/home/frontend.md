# Frontend - Home Feature

## Status

**No dedicated frontend Vue/React components** found in `resources/js/`. The frontend is a separate SPA.

## Consumption Patterns

### 1. Home Page

```
GET /api/v1/general/home?keys=sliders,best_categories,brands,coupons

Response:
{
  "data": {
    "sliders": [{ "id": 1, "title": "...", "image": { "desktop": "...", "mobile": "..." } }],
    "bestCategories": [{ "id": 1, "name": "Electronics", "products_count": 50 }],
    "brands": [{ "id": 1, "name": "Nike", "slug": "nike", "image": "..." }],
    "coupons": [{ "id": 1, "code": "SAVE10", "discount": 10 }]
  }
}
```

### 2. Navigation Bar

```
GET /api/v1/general/nav-data?level=3

Response: Category tree with up to 3 levels of children
```

## What a Frontend Implementation Would Need

```
HomePage.vue
  Fetches: GET /api/v1/general/home
  Renders: Slider carousel, category grid, brand strip, product cards
  Section filtering via URL params for performance

NavBar.vue
  Fetches: GET /api/v1/general/nav-data
  Renders: Mega-menu with category tree
  Level-depth controlled by config
```
