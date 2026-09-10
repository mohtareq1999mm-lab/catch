# Banner Module — Backend Architecture (Public API)

## Endpoints

| Method | URL | Auth | Purpose |
|--------|-----|------|---------|
| GET | `/api/v1/general/banners` | Public | List active banners (filterable) |
| GET | `/api/v1/general/banners/{slug}` | Public | Get banner by slug with optional products |

## Route Definitions

**File:** `routes/api.php` (lines 48-49)

```php
Route::prefix('v1/general')->middleware('api')->group(function () {
    Route::get('banners', [BannerController::class, 'index']);
    Route::get('banners/{slug}', [BannerController::class, 'getBannerBySlug']);
});
```

## Middleware

The `api` middleware group applies: `throttle:api`, `SubstituteBindings`, `ChannelMiddleware`. No authentication.

## Request Flow

### Flow 1: List Banners

```
Client → GET /api/v1/general/banners?limit=5
         ↓
    BannerController@index(Request)
         ↓
    (Optional) If slug query param → delegate to getBannerBySlug()
         ↓
    BannerService::getBanners($request)
         ↓
    Banner::active()
        → when(start_date, end_date) → filter dates
        → when(bannersId) → whereIn('id', $ids)
        → orderBy('id', $order)
        → limit($limit)
        → get()
         ↓
    BannerResource::collection($banners)
        → id, title (translated), slug, description (translated),
          image {desktop, mobile}, status
         ↓
    Response: { status:200, message, success:true, data: [...] }
```

### Flow 2: Get Banner by Slug

```
Client → GET /api/v1/general/banners/summer-sale?with_products=false
         ↓
    BannerController@getBannerBySlug('summer-sale', Request)
         ↓
    BannerService::getBannerBySlug('summer-sale', 'false')
         ↓
    Banner::active()->search('slug', 'summer-sale', $locale)->first()
         ↓
    Found?
    ├─ YES: Check $with_products !== 'false'
    │    ├─ false → skip product loading
    │    └─ true → load products:
    │         channel filter → with('media') → enrichCollectionWithPricing
    │    ↓
    │    BannerResource::make($banner)
    │    ↓
    │    Response: 200
    │
    └─ NO: Response: 404
```

## Key Classes

| Class | Method | Responsibility |
|-------|--------|----------------|
| `BannerController` | `index()` | List banners or get by slug param |
| `BannerController` | `getBannerBySlug()` | Get single banner with optional products |
| `BannerService` | `getBanners()` | Query builder for banner listing |
| `BannerService` | `getBannerBySlug()` | Query single banner + optional product load |
| `BannerResource` | `toArray()` | Transform banner with translatable fields |
| `ProductMiniResource` | `toArray()` | Transform product for banner context |
| `ProductService` | `enrichCollectionWithPricing()` | Apply pricing/discount/flash sale |

## Model: Banner

| Field | Type | Details |
|-------|------|---------|
| id | bigint UNSIGNED | Primary key |
| title | json (translatable) | Banner heading |
| slug | varchar(255) | URL slug (auto-generated from English title) |
| description | json (translatable) | Banner body text |
| status | boolean | Active status |
| order | int | Sortable order (SortableTrait) |
| created_at | timestamp | |
| updated_at | timestamp | |
| deleted_at | timestamp, nullable | Soft delete |

Relations:
- `products()` → BelongsToMany via `banner_product` pivot
- `media()` → Spatie Media Library (`banners-desktop`, `banners-mobile` collections)

## Dependencies

- **BannerService** → `Banner` model, `HasChannelFilter`, `ProductService`
- **BannerResource** → `ProductMiniResource` (when products loaded)
- **ProductMiniResource** → `Product` model (media, reviews)
