# API Documentation - Search Feature

## Status: NOT IMPLEMENTED

The Search feature is scaffolded but non-functional. See `bug-report.md` for details.

## Intended Endpoint

**GET** `/api/v1/general/search`

**Purpose:** Global search across all content types.

## Current Behavior

```
GET /api/v1/general/search
Response: 404 Not Found (route not registered)
```

## What Would Be Needed

1. Register the route in `routes/api.php`
2. Implement `SearchService::search()` with cross-model search logic
3. Apply the existing rate limiter (30 req/min)
4. Add FormRequest for validation
5. Add tests
