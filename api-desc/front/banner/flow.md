# Request Flows — Banner Module (Public API)

## Flow 1: List Banners

```
Client → GET /api/v1/general/banners?limit=5
         ↓
    [api] middleware group
         ├─ throttle:api → pass
         ├─ SubstituteBindings → no-op
         └─ ChannelMiddleware → set channel context
         ↓
    BannerController@index(Request)
         ↓
    No slug query param → proceed to listing
         ↓
    BannerService::getBanners($request)
         ↓
    Banner::active() [where status = 1]
        ->orderBy('id', 'desc')
        ->limit(5)
        ->get()
         ↓
    Collection of Banner models
         ↓
    BannerResource::collection($banners)
        → id, title (translated), slug, description (translated),
          image {desktop, mobile}, status
         ↓
    Response: 200
    {
      "status": 200,
      "message": "Data fetched successfully",
      "success": true,
      "data": [
        { "id": 1, "title": "Summer Sale", "slug": "summer-sale", ... },
        { "id": 2, "title": "New Arrivals", "slug": "new-arrivals", ... }
      ]
    }
```

## Flow 2: List Banners With Slug Query (Implicit Single Lookup)

```
Client → GET /api/v1/general/banners?slug=summer-sale
         ↓
    BannerController@index(Request)
         ↓
    $slug = 'summer-sale' → delegate to getBannerBySlug('summer-sale', $request)
         ↓
    ... (same as Flow 3) ...
         ↓
    Response: 200 with single banner object (not array)
```

## Flow 3: Get Banner by Slug (With Products)

```
Client → GET /api/v1/general/banners/summer-sale
         ↓
    BannerController@getBannerBySlug('summer-sale', Request)
         ↓
    $with_products = true (default, no param sent)
         ↓
    BannerService::getBannerBySlug('summer-sale', true)
         ↓
    Banner::active()->search('slug', 'summer-sale', 'en')->first()
         ↓
    Found?
    ├─ YES:
    │    ↓
    │    $with_products !== 'false' → true → load products:
    │    $banner->load(['products' => fn($q) =>
    │        applyChannelHomeFilter($q)
    │    ])
    │    ↓
    │    enrichCollectionWithPricing($banner->products)
    │    ↓
    │    BannerResource::make($banner) → includes products
    │    ↓
    │    Response: 200 with banner + products
    │
    └─ NO:
         ↓
         Response: 404
         { "status": 404, "message": "Data not found", "success": false }
```

## Flow 4: Get Banner by Slug (Without Products)

```
Client → GET /api/v1/general/banners/summer-sale?with_products=false
         ↓
    BannerService::getBannerBySlug('summer-sale', 'false')
         ↓
    $with_products !== 'false' → false → skip product loading
         ↓
    BannerResource::make($banner) → no products key
         ↓
    Response: 200 with banner only (no products)
```
