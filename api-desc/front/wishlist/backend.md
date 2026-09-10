# Wishlist Module — Backend Architecture (Authenticated API)

## Endpoints

| Method | URL | Auth | Middleware | Purpose |
|--------|-----|------|-----------|---------|
| GET | `/api/v1/wishlists` | auth:sanctum | api | List current user's wishlist products |
| POST | `/api/v1/wishlists` | auth:sanctum | api | Add product (duplicate → 400) |
| DELETE | `/api/v1/wishlists/{product_id}` | auth:sanctum | api | Remove product (`?product_variant_id=` for variants) |
| POST | `/api/v1/wishlists/toggle` | auth:sanctum | api | Add or remove product |
| GET | `/api/v1/wishlists/in_wishlist/{product_id}` | none | api | Guest-safe in-wishlist check |
| GET | `/api/v1/my-wishlists` | auth:sanctum | api | Paginated wishlist products (ProductResource) |

## Route Definitions

**File:** `packages/marvel/src/Rest/Routes.php` (lines 380-386)

```php
Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('wishlists/toggle', [WishlistController::class, 'toggle']);
    Route::apiResource('wishlists', WishlistController::class)->only(['index', 'store', 'destroy']);
    Route::get('my-wishlists', [ProductController::class, 'myWishlists']);
});
Route::get('wishlists/in_wishlist/{product_id}', [WishlistController::class, 'in_wishlist']);
```

Loaded via `RestApiServiceProvider` with `prefix('api/v1')` + `middleware('api')`. The authenticated group wraps everything except the intentionally guest-safe `in_wishlist` check.

`apiResource` is restricted to `index`, `store`, `destroy` — the controller has no `show`/`update` methods, so those resource routes are NOT registered (they previously 500'd).

## Request Flow

### Flow 1: List Wishlist
```
GET /api/v1/wishlists?limit=15&page=1
  → auth:sanctum
  → WishlistController@index
    → repository->where('user_id', $user->id)->get()        // user-scoped
    → pluck product_id + product_variant_id
    → Product::whereIn('id', $productIds)
        ->with('variations' filtered by variantIds,
               'variations.attributeProducts.attributeValue.attribute')
        ->paginate($limit)
    → WishlistResource::collection($products)
    → Response: 200 { status, message, success, data: [WishlistResource...] }
```

### Flow 2: Add Product
```
POST /api/v1/wishlists { product_id, product_variant_id? }
  → WishlistCreateRequest validation
    → product_id required + exists
    → product_variant_id requiredIf(product has variations)
  → WishlistRepository::storeWishlist($request)
    → findUserWishlistItem(user_id, product_id, variant_id)
        → uses explicit whereNull('product_variant_id') for simple products
    → exists? throw HttpException(400, ALREADY_ADDED_TO_WISHLIST_FOR_THIS_PRODUCT)
    → not exists? create({user_id, product_id, product_variant_id})
  → Controller catches HttpException → apiResponse(ALREADY_ADDED..., 400, false)  // translated
  → Response: 200 added / 400 duplicate
```

### Flow 3: Toggle Product
```
POST /api/v1/wishlists/toggle { product_id, product_variant_id? }
  → WishlistCreateRequest validation
  → WishlistRepository::toggleWishlist($request)
    → findUserWishlistItem(user_id, product_id, variant_id)
    → not exists? create → return true  → 200 "Added..."
    → exists? delete(row) → return false → 200 "Removed..."
```

### Flow 4: Remove Product
```
DELETE /api/v1/wishlists/{product_id}?product_variant_id={variantId}
  → WishlistController@destroy
    → merge id, product_variant_id
  → WishlistController@delete($request)
    → user check (defense in depth)
    → Product::where('id', $request->id)->first()  → 404 NOT_FOUND if missing
    → repository->where('product_id', ...)->where('user_id', auth()->id())
        ->when(product_variant_id, where('product_variant_id', ...), whereNull('product_variant_id'))
        ->first()
    → row? delete → 200 "Removed..."
    → no row? 404 NOT_FOUND
```

### Flow 5: In-Wishlist Check (public)
```
GET /api/v1/wishlists/in_wishlist/{product_id}
  → WishlistController@in_wishlist → inWishlist
    → auth()->user()? NO  → false
    → YES → repository->where('product_id', ...)->where('user_id', ...)->first() ? true : false
  → Response: 200 { "data": true|false }
```

### Flow 6: My Wishlists (paginated)
```
GET /api/v1/my-wishlists?limit=10&page=1
  → auth:sanctum
  → ProductController@myWishlists
    → fetchWishlists($request): Wishlist::where('user_id', $user->id)->pluck('product_id')
    → ProductRepository::whereIn('id', $wishlist)->paginate($limit)
    → ProductResource::collection(paginator)   // standard paginated shape
  → Response: 200 { data: [...], meta, links }
```

## Key Classes

| Class | Key Methods | Responsibility |
|-------|-------------|----------------|
| `WishlistController` | index, store, toggle, destroy, delete, in_wishlist, inWishlist | HTTP entry points |
| `WishlistRepository` | storeWishlist, toggleWishlist, findUserWishlistItem | Persistence + duplicate detection |
| `WishlistResource` | toArray | Response transformation (serialization only) |
| `WishlistCreateRequest` | rules | Validation (requiredIf variant) |
| `Wishlist` | belongsTo Product/ProductVariant/User | Model |
| `ProductPricingService` | calculateProductCurrentPrice, calculateProductPricing | Single pricing authority (Frozen ADR) |
| `ProductController` | myWishlists, fetchWishlists | Paginated wishlist products endpoint |

## Model Schema

### `wishlists`

| Column | Type | Description |
|--------|------|-------------|
| id | bigint UNSIGNED | Primary key |
| user_id | bigint UNSIGNED | FK to users (cascade) |
| product_id | bigint UNSIGNED | FK to products (cascade) |
| product_variant_id | bigint UNSIGNED, nullable | FK to product_variants (cascade) |
| created_at | timestamp | |
| updated_at | timestamp | |

> No unique index on `(user_id, product_id, product_variant_id)` — duplicates prevented at the application layer (see bug report recommendation).

## Response Envelope

Success (via `ApiResponse::apiResponse`): `{ status, message, success, data? }`
Errors (via `app/Exceptions/Handler.php`): `{ message, status, errors? }`
