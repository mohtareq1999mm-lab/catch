# Brand Module — Backend Architecture (Public API)

## Overview

The public Brand API provides read-only access to brands and their associated products. It is used by the storefront to display brand listings, brand detail pages, and branded product collections.

## Endpoints

| Method | URL | Auth | Purpose |
|--------|-----|------|---------|
| GET | `/api/v1/general/brands` | Public | List active brands (filterable by date/IDs) |
| GET | `/api/v1/general/brands/{slug}` | Public | Get brand by slug with enriched products |
| GET | `/api/v1/general/brands-products` | Public | Get brand products by quantity set |

## Route Definitions

**File:** `routes/api.php` (lines 45-47)

```php
Route::prefix('v1/general')->middleware('api')->group(function () {
    Route::get('brands', [BrandController::class, 'index']);
    Route::get('brands/{slug}', [BrandController::class, 'getBrandBySlug']);
    Route::get('brands-products', [BrandController::class, 'getBrandsProductsByQtySet']);
});
```

## Middleware Stack

The `api` middleware group applies:
1. **`throttle:api`** — Rate limiting
2. **`SubstituteBindings`** — Route model binding (no-op on these routes)
3. **`ChannelMiddleware`** — Channel context via `X-Channel` header

No authentication. Fully public.

## Request Flow

### Flow 1: List Brands

```
Client → GET /api/v1/general/brands?limit=10&order=desc
         ↓
    BrandController@index(Request)
         ↓
    (Optional) If slug query param present → delegate to getBrandBySlug()
         ↓
    BrandService::getBrands($request)
         ↓
    Brand::active()
        → when(start_date) → where('created_at', '>=', $start_date)
        → when(end_date) → where('created_at', '<=', $end_date)
        → when(brandsId) → whereIn('id', $ids)
        → orderBy('id', $order)
        → limit($limit)
        → get()
         ↓
    BrandResource::collection($brands)
         ↓
    Response: { status:200, message, success:true, data: [...] }
```

### Flow 2: Get Brand by Slug

```
Client → GET /api/v1/general/brands/nike
         ↓
    BrandController@getBrandBySlug('nike')
         ↓
    BrandService::getBrandBySlug('nike')
         ↓
    Brand::active()->search('slug', 'nike', $locale)->first()
         ↓
    (If found) Load products with:
        → channel filter
        → media (images)
        → reviews avg rating (approved only)
        → enrichCollectionWithPricing (apply discounts/flash sales)
         ↓
    BrandResource::make($brand) → includes products relation
         ↓
    Response: { status:200, message, success:true, data: {...} }
         ↓
    (If not found)
    Response: { status:404, message, success:false }
```

### Flow 3: Brands Products by Quantity

```
Client → GET /api/v1/general/brands-products?limit=4&limit_brand=6
         ↓
    BrandController@getBrandsProductsByQtySet(Request)
         ↓
    BrandService::getBrandsProductsByQtySet($request)
         ↓
    Brand::active()
        → when(start_date) → filter by created_at
        → when(end_date) → filter by created_at
        → with(['products' => fn($q) =>
             applyChannelHomeFilter($q)
             ->with(['media'])
             ->withAvg(['reviews' => fn($q) => $q->approved()], 'rating')
             ->limit($qty)  // products per brand
           ])
        → limit($qtyBrand)  // number of brands
        → get()
        → pluck('products')->flatten()
         ↓
    enrichCollectionWithPricing → apply discounts/flash sales
         ↓
    BrandProductResource::collection($products)
         ↓
    Response: { status:200, message, success:true, data: [...] }
```

## Key Classes

| Class | Method | Responsibility |
|-------|--------|----------------|
| `BrandController` | `index()` | List brands or get by slug query param |
| `BrandController` | `getBrandBySlug()` | Get single brand with products |
| `BrandController` | `getBrandsProductsByQtySet()` | Get products grouped by brand |
| `BrandService` | `getBrands()` | Query builder for brand listing |
| `BrandService` | `getBrandBySlug()` | Query single brand + load products |
| `BrandService` | `getBrandsProductsByQtySet()` | Query brands with limited products |
| `BrandResource` | `toArray()` | Transform brand (with optional products) |
| `BrandProductResource` | `toArray()` | Transform product for brand context |
| `ProductService` | `enrichCollectionWithPricing()` | Apply pricing/discount/flash sale data |

## Dependencies

- **BrandService** → `Brand` model, `HasChannelFilter`, `ProductService`
- **ProductService** → `ProductPricingService` for price enrichment
- **BrandResource** → `BrandProductResource` (when products relation loaded)
- **BrandProductResource** → `Product` model (media, reviews relation)

## Cache

The public brand endpoints are **not cached** at the service level (unlike the home/navbar endpoints). Each request hits the database.
