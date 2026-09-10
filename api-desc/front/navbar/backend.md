# Navigation Bar — Backend Architecture

## Overview

The Navigation Bar endpoint serves the hierarchical category tree for the frontend navbar. It is a lightweight, read-only, public endpoint optimized with caching and channel-aware filtering.

## Endpoints

### Public API (`/api/v1/general/nav-data`)

| Method | URL | Auth | Purpose |
|--------|-----|------|---------|
| GET | `/api/v1/general/nav-data` | Public | Fetch hierarchical category tree for navbar |

## Route Definition

**File:** `routes/api.php` (line 38)

```php
Route::prefix('v1/general')->middleware('api')->group(function () {
    Route::get('nav-data', [HomeController::class, 'navData']);
});
```

## Middleware Stack

The `api` middleware group applies (in order):

1. **`throttle:api`** — Rate limiting
2. **`SubstituteBindings`** — Route model binding (no route parameters on this endpoint)
3. **`ChannelMiddleware`** — Parses `X-Channel` header and sets `ChannelContext`

No authentication middleware is applied. This endpoint is fully public.

## Request Flow

```
Client → GET /api/v1/general/nav-data?level=3
         ↓
    [api] middleware group
      ├─ throttle:api — rate limit check
      ├─ SubstituteBindings — no-op
      └─ ChannelMiddleware
           ├─ Reads X-Channel header
           ├─ Falls back to config('channel.default', 'home')
           └─ Sets ChannelContext
         ↓
    HomeController@navData(Request)
      ├─ Reads query param 'level' → intval, min 1
      ├─ Calls HomeService::getNavData($level)
      └─ Returns apiResponse(FETCH_DATA_SUCCESSFULLY, 200, true, $data)
         ↓
    HomeService::getNavData(?int $level)
      ├─ Generates cache key: {channel}:home-nav-bar[:level:{n}]
      ├─ Cache::remember($cacheKey, 120, callback)
      │    └─ Calls getCategoryWithChildren()
      │         └─ Category::query()
      │              → active() [where status=1]
      │              → whereNull('parent_id')
      │              → withCount('products')
      │              → with(['children' => fn($q) =>
      │                   → active()
      │                   → withCount('products')
      │                   → with(['children' => fn($q) =>
      │                        → active()
      │                        → withCount('products')
      │                   ])
      │              ])
      │              → orderByDesc('products_count')
      │              → get()
      └─ Returns CategoryNavbarResource::collection($categories)
         ↓
    CategoryNavbarResource::toArray(Request)
      ├─ $request->query('level', 3) → max depth
      ├─ Returns: id, name (translated), slug, level,
      │   image {desktop, mobile}, children (recursive)
      └─ Stops recursion when level >= maxLevel
         ↓
    Response: { status:200, message, success:true, data: [...] }
```

## Cache Strategy

| Aspect | Detail |
|--------|--------|
| Driver | Laravel Cache (configurable: redis, file, database) |
| Key Pattern | `{channel}:home-nav-bar` or `{channel}:home-nav-bar:level:{n}` |
| TTL | 120 seconds |
| Channel Scope | Cache key is prefixed with channel value (e.g., `home:home-nav-bar`) |
| Invalidation | Via `HomeService::clearCache()` |

## Channel Awareness

The `X-Channel` header affects the cache key, meaning different channels (home vs. fast-shipping) get independently cached responses.

## Key Classes

| Class | Method | Responsibility |
|-------|--------|----------------|
| `HomeController` | `navData()` | Ingest request, extract `level` param, delegate to service |
| `HomeService` | `getNavData()` | Cache orchestration, category retrieval |
| `HomeService` | `getCategoryWithChildren()` | Database query for active categories with 3-level children |
| `CategoryNavbarResource` | `toArray()` | Transform category model into navbar-friendly response |
| `ChannelContext` | `getChannel()` | Return current channel for cache key scoping |
| `Category` | `scopeActive()` | Filter by `status = 1` |

## Dependencies

- **HomeService** → `Category` model, `ChannelContext`
- **CategoryNavbarResource** → `JsonResource`, `Category` model (with `children` relation, `media` relation)
- **Cache** → `Illuminate\Support\Facades\Cache`

## Sequence Diagram

```
┌─────────┐    ┌────────────┐    ┌───────────┐    ┌───────────┐    ┌──────────────┐
│ Client  │    │Middleware  │    │HomeCtrl   │    │HomeSvc    │    │Category (DB) │
└────┬────┘    └─────┬──────┘    └─────┬─────┘    └─────┬─────┘    └──────┬───────┘
     │ GET /nav-data  │                │                 │                 │
     │───────────────>│                │                 │                 │
     │                │ ChannelMiddleware                │                 │
     │                │───────────────>│                 │                 │
     │                │                │ navData(req)    │                 │
     │                │                │────────────────>│                 │
     │                │                │                 │ Cache::get()    │
     │                │                │                 │────────────────>│
     │                │                │                 │<─── hit/miss ───│
     │                │                │                 │                 │
     │                │                │                 │ (if miss)       │
     │                │                │                 │ getCategoryWithChildren()
     │                │                │                 │────────────────>│
     │                │                │                 │                 │ SELECT ...
     │                │                │                 │<─── Collection ─│
     │                │                │                 │                 │
     │                │                │                 │ Cache::put()    │
     │                │                │                 │ CategoryNavbarResource
     │                │                │<────────────────│                 │
     │                │ apiResponse() │                 │                 │
     │                │<───────────────│                 │                 │
     │<─── JSON ──────│                │                 │                 │
┌────┴────┐    ┌─────┴──────┐    ┌─────┴─────┐    ┌─────┴─────┐    ┌──────┴───────┐
│ Client  │    │Middleware  │    │HomeCtrl   │    │HomeSvc    │    │Category (DB) │
└─────────┘    └────────────┘    └───────────┘    └───────────┘    └──────────────┘
```
