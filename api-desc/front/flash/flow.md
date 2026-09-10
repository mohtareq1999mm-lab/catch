# Request Flows — Flash Sale Module (Public API)

## Flow 1: List Flash Sales

```
Client → GET /api/v1/general/flash-sales?limit=5
         ↓
    [api] middleware group
         ↓
    FlashSaleController@index(Request)
         ↓
    No slug param → proceed to listing
         ↓
    FlashSaleService::paginateFlashSales($request)
         ↓
    FlashSale::valid()  // status=1, start<=today, end>=today
        ->when(flashSalesId) → whereIn('id', $ids)
        ->when(start_date, end_date) → filter created_at
        ->orderBy('id', 'desc')
        ->paginate(5)
         ↓
    Collection of FlashSale models (paginated)
         ↓
    FlashSaleResource::collection($flashSales)
        → id, name (translated), discription (typo), slug,
          start_date, end_date, image {desktop, mobile}
         ↓
    Response: 200 with paginated flash sales
```

## Flow 2: Get Flash Sale by Slug

```
Client → GET /api/v1/general/flash-sales/summer-flash-sale
         ↓
    FlashSaleController@getFlashSaleBySlug('summer-flash-sale')
         ↓
    FlashSaleService::getFlashSaleBySlug('summer-flash-sale')
         ↓
    FlashSale::search('slug', 'summer-flash-sale', 'en')->first()
    ⚠️ Note: No valid() scope — expired flash sales still accessible
         ↓
    Found?
    ├─ YES:
    │    ↓
    │    Load products: channel filter, media, reviews avg, pricing
    │    ↓
    │    FlashSaleResource::make($flashSale) → includes products
    │    ↓
    │    Response: 200 with flash sale + products
    │
    └─ NO:
         ↓
         Response: 404
```

## Flow 3: Flash Sale Products Ending Today

```
Client → GET /api/v1/general/flash-sale-products-ending-today?limit=10
         ↓
    FlashSaleController@getFlashSaleProductsEndingToday(Request)
         ↓
    FlashSaleService::getFlashSaleProductsEndingToday($request)
         ↓
    Product::query()
        ->with(['categories', 'variations', 'brands', 'media', 'flash_sales'])
        ->withAvg(['reviews' => fn($q) => $q->approved()], 'rating')
        ->whereNull('deleted_at')
        ->activeStatus()
        ->where('has_flash_sale', true)
        ->whereExists(function ($q) {
            // Subquery: product in flash_sale_products
            // JOIN flash_sales WHERE end_date = today()
        })
        ->orderByDesc('id')
        ->limit(10)
        ->get()
         ↓
    enrichCollectionWithPricing($products)
         ↓
    ProductMiniResource::collection($products)
         ↓
    Response: 200 with product array
```
