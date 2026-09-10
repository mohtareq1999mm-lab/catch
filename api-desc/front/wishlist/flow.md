# Request Flows — Wishlist Module (Authenticated API)

## Flow 1: Add Product to Wishlist — Success

```
Client → POST /api/v1/wishlists { product_id: 10 }
         ↓
    [auth:sanctum] → authenticate user
         ↓
    WishlistCreateRequest validation
      product_id: required + exists:products
      product_variant_id: requiredIf(product has variations) — absent for simple product
         ↓
    WishlistController@store(WishlistCreateRequest)
         ↓
    WishlistRepository::storeWishlist($request)
         ↓
    findUserWishlistItem(user_id, product_id, null)
      → WHERE user_id=? AND product_id=? AND product_variant_id IS NULL
      → no existing row
         ↓
    Wishlist::create({ user_id, product_id, product_variant_id: null })
         ↓
    Response: 200 { status: 200, message: "Added to wishlist successfully", success: true, data: {wishlist row} }
```

## Flow 2: Add Product — Duplicate

```
Client → POST /api/v1/wishlists { product_id: 10 }   (already in wishlist)
         ↓
    [auth:sanctum] + validation (passes)
         ↓
    storeWishlist($request)
      → findUserWishlistItem → existing row found
         ↓
    throw HttpException(400, ERROR.ALREADY_ADDED_TO_WISHLIST_FOR_THIS_PRODUCT)
         ↓
    WishlistController@store catch(HttpException)
      → apiResponse(ERROR.ALREADY_ADDED_TO_WISHLIST_FOR_THIS_PRODUCT, 400, false)
      → message translated via ApiResponse::translateNotice
         ↓
    Response: 400 { status: 400, message: "This product is already added to the wishlist", success: false }
```

## Flow 3: Add Product — Variable Product Without Variant (422)

```
Client → POST /api/v1/wishlists { product_id: 20 }   (variable product, no variant)
         ↓
    WishlistCreateRequest validation
      product_variant_id → requiredIf(closure) = true → rule becomes 'required'
      (the 'sometimes' rule was removed so 'required' is NOT skipped for absent fields)
         ↓
    field missing → validation error → 422
         ↓
    Response: 422 { product_variant_id: ["The product variant id field is required."] }
```

## Flow 4: Toggle Product — Add Then Remove

```
Client → POST /api/v1/wishlists/toggle { product_id: 10 }
         ↓
    [auth:sanctum] + validation
         ↓
    WishlistRepository::toggleWishlist($request)
         ↓
    Call 1: findUserWishlistItem → no row
      → create → return true
      → Response: 200 { message: "Added to wishlist successfully", success: true }
         ↓
    Call 2: findUserWishlistItem → row found (whereNull branch matches simple products)
      → delete(row) → return false
      → Response: 200 { message: "Removed from wishlist successfully", success: true }
      → DB has exactly 1 row total (no duplicates)
```

## Flow 5: Remove Product (Simple)

```
Client → DELETE /api/v1/wishlists/10
         ↓
    [auth:sanctum]
         ↓
    WishlistController@destroy(Request, 10)
      → merge id=10 (no product_variant_id query param)
         ↓
    WishlistController@delete($request)
      → $request->user() present (auth middleware)
      → Product::where('id', 10)->first() → exists
      → repository->where('product_id', 10)->where('user_id', authId())
          ->when(null, ..., whereNull('product_variant_id'))
          ->first()
      → row found → delete
         ↓
    Response: 200 { status: 200, message: "Removed from wishlist successfully", success: true, data: true }
```

## Flow 6: Remove Product — Variant Item

```
Client → DELETE /api/v1/wishlists/20?product_variant_id=5
         ↓
    destroy(Request, 20)
      → merge id=20, product_variant_id=5 (from query)
         ↓
    delete($request)
      → Product 20 exists
      → repository->where('product_id', 20)->where('user_id', authId())
          ->when(5, where('product_variant_id', 5))
          ->first()
      → variant wishlist row found → delete
         ↓
    Response: 200 (Removed) — only the variant row is removed
```

## Flow 7: Remove Product — Not Found (404)

```
Client → DELETE /api/v1/wishlists/999
         ↓
    delete($request)
      → Product 999 not found
      → throw MarvelException(NOT_FOUND)
         ↓
    app/Exceptions/Handler → translateNotice(NOT_FOUND) → 404
    Response: 404 { message: "Not found", status: false }
```

## Flow 8: In-Wishlist Check (Public, Guest-Safe)

```
Client → GET /api/v1/wishlists/in_wishlist/10
         ↓
    (no auth middleware)
         ↓
    WishlistController@in_wishlist → inWishlist($request)
      → auth()->user()? 
         ├─ NO (guest) → false
         └─ YES → repository->where('product_id',10)->where('user_id',uid)->first()
                   ├─ found → true
                   └─ not found → false
         ↓
    Response: 200 { "data": true | false }
```

## Flow 9: My Wishlists (Paginated Products)

```
Client → GET /api/v1/my-wishlists?limit=10&page=1
         ↓
    [auth:sanctum]
         ↓
    ProductController@myWishlists(Request)
      → fetchWishlists($request)
          → Wishlist::where('user_id', $user->id)->pluck('product_id')
          → ProductRepository::whereIn('id', $wishlist)->paginate($limit)
      → ProductResource::collection($paginator)
         ↓
    Response: 200 { data: [...], meta: { current_page, per_page, total, ... }, links: {...} }
```
