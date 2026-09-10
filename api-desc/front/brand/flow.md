# Request Flows — Brand Module (Public API)

## Flow 1: List Brands

```
Client → GET /api/v1/general/brands?limit=5&order=asc
         ↓
    [api] middleware group
         ├─ throttle:api → pass
         ├─ SubstituteBindings → no-op
         └─ ChannelMiddleware → set channel context
         ↓
    BrandController@index(Request)
         ↓
    No slug query param → proceed to listing
         ↓
    BrandService::getBrands($request)
         ↓
    Brand::active() [where status = 1]
        ->orderBy('id', 'asc')
        ->limit(5)
        ->get()
         ↓
    Collection of Brand models
         ↓
    BrandResource::collection($brands)
        → id, name (translated), slug, image {desktop, mobile}, status
         ↓
    Response: 200
    {
      "status": 200,
      "message": "Data fetched successfully",
      "success": true,
      "data": [
        { "id": 1, "name": "Adidas", "slug": "adidas", "image": {...}, "status": true },
        { "id": 2, "name": "Nike", "slug": "nike", "image": {...}, "status": true }
      ]
    }
```

## Flow 2: List Brands With Slug Query Param (Implicit Single Lookup)

```
Client → GET /api/v1/general/brands?slug=nike
         ↓
    BrandController@index(Request)
         ↓
    $slug = 'nike' → delegate to getBrandBySlug('nike')
         ↓
    ... (same as Flow 3 below) ...
         ↓
    Response: 200 with single brand object (not array)
```

## Flow 3: Get Brand by Slug

```
Client → GET /api/v1/general/brands/nike
         ↓
    [api] middleware group
         ↓
    BrandController@getBrandBySlug('nike')
         ↓
    BrandService::getBrandBySlug('nike')
         ↓
    Brand::active()->search('slug', 'nike', 'en')->first()
         ↓
    Brand found?
    ├─ YES:
    │    ↓
    │    Load products:
    │    $brand->load(['products' => fn($q) =>
    │        applyChannelHomeFilter($q)
    │        ->with(['media'])
    │        ->withAvg(['reviews' => fn($q) => $q->approved()], 'rating')
    │    ])
    │    ↓
    │    enrichCollectionWithPricing($brand->products)
    │    ↓
    │    BrandResource::make($brand) → includes products array
    │    ↓
    │    Response: 200 with brand + products
    │
    └─ NO:
         ↓
         Response: 404
         {
           "status": 404,
           "message": "Data not found",
           "success": false
         }
```

## Flow 4: Brands Products by Quantity

```
Client → GET /api/v1/general/brands-products?limit=4&limit_brand=6
         ↓
    [api] middleware group
         ↓
    BrandController@getBrandsProductsByQtySet(Request)
         ↓
    BrandService::getBrandsProductsByQtySet($request)
         ↓
    Step 1: Fetch N active brands (limit_brand = 6)
    Brand::active()
        ->with(['products' => function ($q) {
            applyChannelHomeFilter($q);
            $q->with(['media'])
              ->withAvg(['reviews' => fn($q) => $q->approved()], 'rating')
              ->limit(4);  // limit per brand
        }])
        ->limit(6)
        ->get()
         ↓
    Step 2: Extract all products from brands
    ->pluck('products')->flatten()
         ↓
    Step 3: Enrich with pricing
    enrichCollectionWithPricing($products)
         ↓
    BrandProductResource::collection($products)
        → id, name, slug, price, price_after_discount, rating, image.thumbnail
         ↓
    Response: 200 with flat product array
    {
      "status": 200,
      "message": "Data fetched successfully",
      "success": true,
      "data": [
        { "id": 10, "name": "Air Max", "price": 150, "price_after_discount": 129.99, ... },
        { "id": 11, "name": "Ultraboost", "price": 180, "price_after_discount": 180, ... },
        ...
      ]
    }
```
