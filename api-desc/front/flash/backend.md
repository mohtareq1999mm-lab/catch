# Flash Sale Module — Backend Architecture (Public API)

## Endpoints

| Method | URL | Auth | Purpose |
|--------|-----|------|---------|
| GET | `/api/v1/general/flash-sales` | Public | List active flash sales (paginated) |
| GET | `/api/v1/general/flash-sales/{slug}` | Public | Get flash sale by slug with products |
| GET | `/api/v1/general/flash-sale-products` | Public | Products from flash sales by qty |
| GET | `/api/v1/general/flash-sale-products-ending-this-week` | Public | Products ending within 7 days |
| GET | `/api/v1/general/flash-sale-products-ending-today` | Public | Products ending today |

## Route Definitions

**File:** `routes/api.php` (lines 56-60)

```php
Route::prefix('v1/general')->middleware('api')->group(function () {
    Route::get('flash-sales', [FlashSaleController::class, 'index']);
    Route::get('flash-sales/{slug}', [FlashSaleController::class, 'getFlashSaleBySlug']);
    Route::get('flash-sale-products', [FlashSaleController::class, 'getFlashSalesAndHereProductsByQtySet']);
    Route::get('flash-sale-products-ending-this-week', [FlashSaleController::class, 'getFlashSaleProductsEndingThisWeek']);
    Route::get('flash-sale-products-ending-today', [FlashSaleController::class, 'getFlashSaleProductsEndingToday']);
});
```

## Middleware

`api` group: `throttle:api`, `SubstituteBindings`, `ChannelMiddleware`. No authentication.

## Request Flows

### List Flash Sales
```
FlashSaleController@index
  → (optional) slug query → delegate to getFlashSaleBySlug
  → FlashSaleService::paginateFlashSales($request)
    → FlashSale::valid()  // status=true AND start_date<=today AND end_date>=today
      → when(start_date, end_date, flashSalesId, order)
      → paginate($limit)
  → FlashSaleResource::collection (no products loaded)
```

### Get Flash Sale by Slug
```
FlashSaleController@getFlashSaleBySlug($slug)
  → FlashSaleService::getFlashSaleBySlug($slug)
    → FlashSale::search('slug', ...)->first()  // NO valid() scope!
    → load products: channel filter, media, reviews avg, pricing enrichment
  → FlashSaleResource::make (includes products)
```

### Flash Sale Products Ending This Week
```
FlashSaleService::getFlashSaleProductsEndingThisWeek($request)
  → Product::query()
    → where('has_flash_sale', true)
    → whereExists(flash_sale_products JOIN flash_sales WHERE end_date BETWEEN today AND weekEnd)
    → limit (default 10)
    → enrichCollectionWithPricing
```

### Flash Sale Products Ending Today
```
FlashSaleService::getFlashSaleProductsEndingToday($request)
  → Same as above but whereDate('flash_sales.end_date', today())
```

## Key Classes

| Class | Method | Responsibility |
|-------|--------|----------------|
| `FlashSaleController` | `index()` | List or single by slug param |
| `FlashSaleController` | `getFlashSaleBySlug()` | Single + products |
| `FlashSaleController` | `getFlashSalesAndHereProductsByQtySet()` | Products grouped by flash sale |
| `FlashSaleController` | `getFlashSaleProductsEndingThisWeek()` | Products ending within 7 days |
| `FlashSaleController` | `getFlashSaleProductsEndingToday()` | Products ending today |
| `FlashSaleService` | `paginateFlashSales()` | Paginated, filtered, valid only |
| `FlashSaleService` | `getFlashSaleBySlug()` | Single + eager load + pricing |
| `FlashSaleService` | `getFlashSalesAndHereProductsByQtySet()` | Products from valid flash sales |
| `FlashSaleService` | `getFlashSaleProductsEndingThisWeek/Today()` | Time-based product queries |
| `FlashSaleResource` | `toArray()` | Transform with translatable fields |

## Model: FlashSale

| Column | Type | Description |
|--------|------|-------------|
| id | bigint UNSIGNED | Primary key |
| title | json (translatable) | Flash sale name |
| slug | varchar(255) | URL slug |
| description | json (translatable) | Description |
| start_date | date | Campaign start |
| end_date | date | Campaign end |
| status | boolean | Active flag |
| type | varchar(255) | discount type (percentage, fixed_rate, final_price) |
| discount | decimal | Discount value |
| max_discount_amount | decimal, nullable | Max cap for percentage |
| order | int | Sort order |

Relations:
- `products()` → BelongsToMany via `flash_sale_products`
- `flashSaleRequests()` → HasMany `FlashSaleRequests`
