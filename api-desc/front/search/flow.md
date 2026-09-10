# Data Flow - Search Feature

## Current (Broken) Flow

```
Client
  |
  GET /api/v1/general/search?q=headphones
  |
  v
NO ROUTE DEFINED → 404 Not Found
```

## Intended Flow

```
Client
  |
  GET /api/v1/general/search?q=headphones&type=products,categories
  (Rate limit: 30 req/min)
  |
  v
SearchController@index(Request)
  |
  v
SearchService::search(Request)
  |
  +-- Parse search term and type filters
  +-- Query products via Scout (Meilisearch) or LIKE
  +-- Query categories/brands/pages via LIKE
  +-- Aggregate and paginate results
  |
  v
JSON Response with grouped results
```
