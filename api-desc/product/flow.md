# Product Module — Request Flow Diagrams

> Covers Admin `apiResource` (`Marvel\ProductController`) + Storefront `General\ProductController` (`GET /products` / `GET /products/{slug}`).

## 1. Admin — List Products (GET /api/v1/products → `products.index`)

```
Client                  Routes.php:236          Marvel\ProductController      ProductRepository/Model/Scout
  │                         │                       │                         │
  │──── GET /products ─────>│                       │                         │
  │  ?limit=15&search=...   │── index(Request) ────>│                         │
  │                         │                       │── auth:sanctum ────────>│
  │                         │                       │── permission:view-products
  │                         │                       │── with('variations') ──>│
  │                         │                       │── with('categories') ───>│
  │                         │                       │── with('flash_sales') ──>│
  │                         │                       │── HasChannelFilter ─────>│
  │                         │                       │── apply search ─────────>│
  │                         │                       │  (Scout::search or LIKE on name/desc/sku/variant sku)
  │                         │                       │── apply filters ────────>│
  │                         │                       │  (ProductFilter: category/banner/promotion/flash_sale/slider/tag/tags/date_range/status)
  │                         │                       │── paginate(limit) ──────>│
  │                         │                       │  (withQueryString)
  │                         │                       │<── ProductCollection ────│
  │                         │<── 200 {success, message:FETCH_DATA_SUCCESSFULLY, data:ProductCollection} ──│
  │<── 200 JSON ────────────│                       │                         │
```

## 2. Admin — Create Product (POST /api/v1/products → `products.store`)

```
Client                  Routes.php            Marvel\ProductController    ProductCreateRequest   ProductRepository     DB/Media
  │                         │                       │                         │                    │              │
  │──── POST /products ────>│                       │                         │                    │              │
  │  multipart + fields     │── store(CreateRequest)│                         │                    │              │
  │                         │                       │── permission:create-product
  │                         │                       │── validate() ──────────>│                    │              │
  │                         │                       │<── validated ───────────│                    │              │
  │                         │                       │── storeProduct(request)─>│                    │              │
  │                         │                       │                         │── beginTransaction >│              │
  │                         │                       │                         │── customSlugify ───>│              │
  │                         │                       │                         │── resolveFlashSale >│              │
  │                         │                       │                         │── ProductPricingService: calculate* (>roundMoney) │
  │                         │                       │                         │── create product ──>│              │
  │                         │                       │                         │── addVariants() ───>│              │
  │                         │                       │                         │  (ProductVariant + AttributeProduct)
  │                         │                       │                         │── upload images ───>│   (Spatie MediaLibrary)
  │                         │                       │                         │── sync relations ──>│              │
  │                         │                       │                         │  (categories, brands, banners, sliders, tags, flash_sales)│
  │                         │                       │                         │── commit + clear dashboard cache ─>│              │
  │                         │                       │<── ProductResource 201 ─│                    │              │
  │                         │<── 201 {CREATE_PRODUCT_SUCCESSFULLY} ──────────│                    │              │
  │<── 201 JSON ────────────│                       │                         │                    │              │
```

## 3. Admin — Show Product (GET /api/v1/products/{product} → `products.show`)

```
Client                  Routes.php            Marvel\ProductController           Repository/Model
  │                         │                       │                         │
  │──── GET /products/1 ───>│                       │                         │
  │  {product}: id or slug  │── show(Request,$id) ─>│                         │
  │                         │                       │── permission:view-products
  │                         │                       │── fetchSingleProduct($id)─>│
  │                         │                       │  (find by id, fallback slug, active+channel filter)
  │                         │                       │── load relations ───────>│
  │                         │                       │  (variations, categories, flash_sales, banners, sliders, brands, reviews, tags, related_products, media)
  │                         │                       │<── ProductResource 200 ──│
  │                         │<── 200 FETCH_DATA_SUCCESSFULLY ────────────────│
  │<── 200 JSON ────────────│                       │                         │
  │  404 when not found / soft-deleted / channel mismatch
```

## 4. Admin — Update Product (PUT|PATCH /api/v1/products/{product} → `products.update`)

```
Client                  Routes.php            Marvel\ProductController      ProductUpdateRequest   ProductRepository     DB
  │                         │                       │                         │                    │              │
  │─── PUT /products/1 ────>│                       │                         │                    │              │
  │  {partial fields}       │── update(UpdateReq,$id)                         │                    │              │
  │                         │                       │── permission:update-product
  │                         │                       │── validate() ──────────>│ (sometimes, UniqueTranslation ignore self)│
  │                         │                       │<── validated ───────────│                    │              │
  │                         │                       │── updateProduct(req,id)->│                    │              │
  │                         │                       │                         │── find product ────>│              │
  │                         │                       │                         │── guard item_type immutability (422 if order/digital-asset exists) │
  │                         │                       │                         │── if variants: delete old, addVariants()─>│              │
  │                         │                       │                         │── recalc pricing (fallback to existing) ─>│ (ProductPricingService) │
  │                         │                       │                         │── update images (delete removed, upload new) │
  │                         │                       │                         │── sync relations (categories,brands,banners,sliders,tags replacive) │
  │                         │                       │                         │<── product ────────│              │
  │                         │                       │<── ProductResource 200 ─│                    │              │
  │                         │<── 200 UPDATE_PRODUCT_SUCCESSFULLY ────────────│                    │              │
  │<── 200 JSON ────────────│                       │                         │                    │              │
```

## 5. Admin — Delete Product (DELETE /api/v1/products/{product} → `products.destroy`)

```
Client                  Routes.php            Marvel\ProductController           Repository/Model
  │                         │                       │                         │
  │─── DELETE /products/1 ──>│                       │                         │
  │                         │── destroy($id) ───────>│                         │
  │                         │                       │── permission:delete-product
  │                         │                       │── findOrFail(id) ───────>│
  │                         │                       │── softDelete() ─────────>│
  │                         │<── 200 DELETE_PRODUCT_SUCCESSFULLY ────────────│
  │<── 200 JSON ────────────│                       │                         │
```

## 6. Admin — Bulk Delete (POST /api/v1/products/bulk-delete)

```
Client                  Routes.php            Marvel\ProductController           DB
  │                         │                       │                      │
  │── POST /products/bulk-delete ──>│               │                      │
  │    { ids: [1,2,3] }            │── destroyBulk()>│                      │
  │                               │── permission:delete-product           │
  │                               │── validate ids:required|array|exists ─>│
  │                               │── whereIn->delete() hard ─────────────>│
  │                               │<── 200 PRODUCTS_DELETED_SUCCESSFULLY ──│
  │<── 200 JSON ──────────────────│                 │                      │
```

## 7. Admin — Destroy All (DELETE /api/v1/products/all)

```
Client                  Routes.php            Marvel\ProductController           DB
  │                         │                       │                      │
  │── DELETE /products/all ─>│                       │                      │
  │                         │── destroyAll() ───────>│                      │
  │                         │── permission:delete-product                  │
  │                         │── throttle:admin ─────>│                      │
  │                         │── delete() all hard ──>│                      │
  │                         │<── 200 PRODUCTS_DELETED_SUCCESSFULLY ───────│
  │<── 200 JSON ────────────│                       │                      │
```

## 8. Storefront — List Products (GET /api/v1/general/products → `General\ProductController@index`)

```
Client                  routes/api.php:82         General\ProductController    ProductIndexRequest   ProductService+Scout+ProductStrategyResolver
  │                         │                       │                         │                    │
  │── GET /general/products ─────>│                 │                         │                    │
  │  ?type=index&limit=15&search=…  │── index(Request) ──────>│                 │                    │
  │  (public, throttle:public-api)  │               │── ProductIndexRequest.validate(type/order) ─>│
  │                         │                       │── shouldCache? ─────────>│  (false when search present)
  │                         │                       │── currencyAwareCacheKey ─>│  (hash path+query+currency+channel)
  │                         │                       │── Cache::has? ──────────>│  (per-currency entry)
  │                         │                       │   if hit ───────────────>│  return cached 200
  │                         │                       │                         │                    │
  │                         │                       │  if type && type !== 'all' ─────────────────>│ buildStrategyResponse(type)
  │                         │                       │                         │── ProductStrategyResolver.resolve(type)
  │                         │                       │                         │  → AllProduct | BestProduct | ProductForBrand | NewArrivals | …
  │                         │                       │                         │── getProducts(request) ────────────>│ (LengthAwarePaginator|Collection of ids)
  │                         │                       │                         │── whereIn ids → Product query ─────>│
  │                         │                       │                         │── getDynamicFilters(clone query) ──>│ facets: price/brands/categories/tags/ratings
  │                         │                       │                         │── getCollectionCategories(ids) ────>│ category facets
  │                         │                       │                         │── ProductCollectionMini ───────────>│ (ProductMiniResource per item)
  │                         │                       │                         │                    │
  │                         │                       │  else (type missing/all) ─────────────────>│ buildFallbackResponse(order)
  │                         │                       │                         │── buildScoutSearchQuery(request)
  │                         │                       │                         │  if search && Scout enabled → Scout search
  │                         │                       │                         │── scoutQuery.orderBy(id,order).paginate(limit)
  │                         │                       │                         │── getDynamicFilters(clone scoutQuery)
  │                         │                       │                         │  else
  │                         │                       │                         │── buildFilteredBaseQuery(request)
  │                         │                       │                         │  (active+with(productRelations)+withAvg rating+HasChannelFilter+applyProductFilters+applyIdsFilter(productsId)+applyRelationIdsFilters)
  │                         │                       │                         │── optional orderBy(price, order_price)
  │                         │                       │                         │── orderBy(id,order).paginate(limit)
  │                         │                       │                         │── ProductCollectionMini + filters + categories
  │                         │                       │── Cache::put(key, responseData, ttl) ──────>│
  │                         │                       │<── 200 {success, message, data:{data[], meta, links, filters, categories}} ──│
  │                         │<── 200 JSON ──────────────────────────────────│                    │
  │<── 200 JSON ────────────│                       │                         │                    │
```

## 9. Storefront — Product Detail (GET /api/v1/general/products/{slug} → `getProductBySlug`)

```
Client                  routes/api.php:83         General\ProductController    ProductService       Model/Resource/HasCache
  │                         │                       │                         │                    │
  │── GET /general/products/t-shirt ─────>│         │                         │                    │
  │  (public, throttle:public-api)        │── getProductBySlug(Request) ─────>│                    │
  │                         │                       │── currencyAwareCacheKey ─>│
  │                         │                       │── Cache::has? ──────────>│  (per slug+currency+channel)
  │                         │                       │── query: Product.where(slug).active().tap(HasChannelFilter) ──>│
  │                         │                       │── with(variations.attributeProducts, categories, brands, tags, banners, sliders, flash_sales, reviews, related_products, media) ─>│
  │                         │                       │── withAvg('reviews','rating')
  │                         │                       │── firstOrFail() ────────>│  404 if missing/soft-deleted/channel mismatch
  │                         │                       │── enrichProductWithPricing(product) ──────>│  ProductPricingService+ProductTaxPresenter
  │                         │                       │── ProductResource::make(product) ────────>│  ConvertsProductPrice(→effectiveCurrency)+HasProductFilters
  │                         │                       │── merge filters: getDynamicFilters(whereIn[id]) when !routeIs(general-product-show)
  │                         │                       │── Cache::put(key, resource, ttl) ───────>│
  │                         │                       │<── 200 {success, message:FETCH_DATA_SUCCESSFULLY, data:ProductResource} ──│
  │                         │<── 200 JSON ──────────────────────────────────│                    │
  │<── 200 JSON ────────────│                       │                         │                    │
  │  404 NOT_FOUND on unknown slug
```

## 10. Product Import (POST /api/v1/products/import)

```
Client                Routes.php          ProductImportController      ImportProductsJob     DB/SignalFile
  │                       │                       │                      │                  │
  │── POST /products/import ──>│                  │                      │                  │
  │    (file: .xlsx)          │── import(Request)>│                      │                  │
  │  auth:sanctum, create-product/super_admin         │── store file ────────>│                  │
  │                           │                    │── create Import ─────>│                  │
  │                           │                    │   {status: pending}  │                  │
  │                           │                    │── writeSignalFile ───>│                  │
  │                           │                    │── dispatch job ──────>│                  │
  │                           │                    │  (queue high/medium) │── process rows ──>│
  │                           │                    │                       │── update Import ──>│
  │                           │                    │<── 202 {import_id, status:pending} ───│
  │                           │<── 202 JSON ───────│                       │                  │
  │<── 202 JSON ──────────────│                    │                       │                  │
```

## 11. Import Status / Cancel / Download Errors

```
GET /products/import/{id}              → status($id) → findOrFail → readSignalFile → calc progress% → 200 {status,total_rows,processed_rows,success_rows,failed_rows,progress}
POST /products/import/{id}/cancel      → cancel($id) → if completed→409 else writeSignalFile + update status cancelled → 200
GET /products/import/{id}/download-errors → downloadErrors($id) → if no errors → 404 else generate XLSX → binary download
```

## 12. Reviews (supplemental)

```
GET /reviews?product_id=1              → ReviewController@index → validate exists:products,id → paginate → collection 200 (public when product_id scoped, else auth)
POST /reviews                          → store(CreateRequest) → storeReview → 200 REVIEW_CREATED_SUCCESSFULLY (auth:sanctum)
PATCH /reviews/{id}/toggle-approve     → toggleApprove → flip approved bool → 200 (permission:approve-reviews)
DELETE /reviews/{id}                   → destroy → delete → 200 (permission:delete-reviews)
```
