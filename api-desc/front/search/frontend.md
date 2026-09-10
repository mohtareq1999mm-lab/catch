# Frontend - Search Feature

## Status: NOT IMPLEMENTED

No search endpoint is available for frontend consumption.

## What a Frontend Implementation Would Need

```
SearchBar.vue
  Fetches: GET /api/v1/general/search?q=term&type=products,categories,brands
  Renders: Autocomplete dropdown with grouped results
  Sections: Products, Categories, Brands, Pages
  Rate-limited: 30 requests/min per IP

SearchResultsPage.vue
  Fetches: GET /api/v1/general/search?q=term&page=1
  Renders: Paginated results with filters
  Loading/empty/error states
```
