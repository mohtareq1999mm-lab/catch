# Request Flows — Flash Sale Module

## Flow 1: List Flash Sales (Admin)

```
Client → GET /api/v1/flash-sale?active=true&search=summer&page=1&per_page=10
         ↓
    [auth:sanctum] middleware → authenticate token
         ↓
    [permission:view-flash-sale] middleware
         ↓
    FlashSaleController@index()
         ↓
    fetchFlashSales($request):
      - active=true → scopeValid() (status=1 + date range)
      - inactive=true → scopeInvalid()
      - search → where title LIKE '%summer%' (translatable)
      - order / sortedBy → orderBy($order, $sortedBy)
         ↓
    $query->paginate($per_page)->withQueryString()
         ↓
    FlashSaleResource::collection($flashSales) → pagination meta
         ↓
    Return: { status:200, message, success:true, data: { data[], pagination_meta } }
```

## Flow 2: Create Flash Sale (Admin)

```
Client → POST /api/v1/flash-sale (multipart/form-data)
         ↓
    [auth:sanctum] → [permission:create-flash-sale]
         ↓
    CreateFlashSaleRequest → validation rules
         ↓
    Fail? → 422 with field errors
         ↓
    FlashSaleController@store()
         ↓
    FlashSaleRepository::storeFlashSale($request)
         ↓
    DB::beginTransaction()
      ├─ Generate slug via makeSlug($request)
      ├─ Extract data: title, slug, description, start_date, end_date, type, status, discount, max_discount_amount
      ├─ FlashSale::create($data)
      ├─ Upload image-desktop → 'flash-sales-desktop'
      ├─ Upload image-mobile → 'flash-sales-mobile'
      ├─ If products[] → sync + setProductInFlashSale()
      └─ DB::commit()
         ↓
    $flashSale->load('products')
         ↓
    FlashSaleResource::make($flashSale)
         ↓
    FlashSaleObserver@created() → LogActivityJob
         ↓
    Return: { status:200, message, success:true, data }
```

## Flow 3: Show Flash Sale (Admin)

```
Client → GET /api/v1/flash-sale/1  OR  GET /api/v1/flash-sale/summer-sale
         ↓
    [auth:sanctum] → [permission:view-flash-sale]
         ↓
    FlashSaleController@show($params)
         ↓
    is_numeric($params)?
      ├─ Yes → with('products')->where('id', (int)$params)->first()
      └─ No  → with('products')->where('slug', $params)->first()
         ↓
    Found? → FlashSaleResource::make($flashSale) → 200
    Not found? → MarvelException(NOT_FOUND) → 404
```

## Flow 4: Update Flash Sale (Admin)

```
Client → PUT /api/v1/flash-sale/1 (multipart/form-data)
         ↓
    [auth:sanctum] → [permission:update-flash-sale]
         ↓
    UpdateFlashSaleRequest → validation (unique ignores current ID)
         ↓
    FlashSaleController@update($request, $id)
         ↓
    $request->merge(['id' => $id])
         ↓
    updateFlashSale($request) [public]
      → FlashSaleRepository::updateFlashSale($request, $id)
         ↓
    DB::beginTransaction()
      ├─ findOrFail($id)
      ├─ Regenerate slug via makeSlug()
      ├─ $flashSale->update($data)
      ├─ Update image-desktop if provided
      ├─ Update image-mobile if provided
      ├─ If products[]:
      │   ├─ Get old IDs → unsetProductFromFlashSale()
      │   ├─ Sync new products
      │   └─ setProductInFlashSale()
      ├─ updateFlashSaleProductPrices($flashSale)
      └─ DB::commit()
         ↓
    $flashSale->load('products')
         ↓
    FlashSaleResource::make($flashSale)
         ↓
    FlashSaleObserver@updated() → LogActivityJob
         ↓
    Return: { status:200, message, success:true, data }
```

## Flow 5: Delete Flash Sale (Admin)

```
Client → DELETE /api/v1/flash-sale/1
         ↓
    [auth:sanctum] → [permission:delete-flash-sale]
         ↓
    FlashSaleController@destroy($id, Request)
         ↓
    $request->merge(['id' => $id])
         ↓
    deleteFlashSale($request) [public]
      → findOrFail($id)
      → $flashSale->delete()  → sets deleted_at
         ↓
    FlashSaleObserver@deleted() → LogActivityJob
         ↓
    Return: { status:200, message, success:true }
```

## Flow 6: Reorder Flash Sales (Admin)

```
Client → PUT /api/v1/flash-sale/reorder { "flash_sales": [3, 1, 2] }
         ↓
    [auth:sanctum] → [permission:update-flash-sale]
         ↓
    Inline validation: flash_sales required|array, flash_sales.* exists:flash_sales,id
         ↓
    Fail? → 422
         ↓
    FlashSaleRepository::reorder($flashSales)
      → $this->setNewOrder($flashSales)  [Spatie SortableTrait]
         ↓
    Return: { status:200, message, success:true }
```

## Flow 7: Get Flash Sale Info by Product ID (Admin)

```
Client → GET /api/v1/product-flash-sale-info?id=5
         ↓
    [auth:sanctum]
         ↓
    FlashSaleController@getFlashSaleInfoByProductID(Request)
         ↓
    Product::find($request->id)
         ↓
    Return $product->flash_sales  (BelongsToMany collection)
         ↓
    Return: { status:200, message, success:true, data[] }
```

## Flow 8: List Flash Sales (Public)

```
Client → GET /api/v1/general/flash-sales?limit=10
         ↓
    FlashSaleController@index(Request)
         ↓
    If slug query param → delegate to getFlashSaleBySlug()
         ↓
    FlashSaleService::paginateFlashSales($request)
         ↓
    FlashSaleResource::collection($flashSales)
         ↓
    Return: { status:200, message, success:true, data[] }
```

## Flow 9: Get Flash Sale Products Ending This Week (Public)

```
Client → GET /api/v1/general/flash-sale-products-ending-this-week
         ↓
    FlashSaleController@getFlashSaleProductsEndingThisWeek()
         ↓
    FlashSaleService::getFlashSaleProductsEndingThisWeek($request)
      → FlashSale::valid() with end_date <= 7 days
      → Load products with media, pricing
         ↓
    ProductMiniResource::collection($products)
         ↓
    Return: { status:200, message, success:true, data[] }
```

## Flow 10: Vendor Request Approve Flow

```
Client → POST /api/v1/approve-flash-sale-requested-products
         ↓
    FlashSaleVendorRequestController@approveFlashSaleProductsRequest($request)
         ↓
    FlashSaleVendorRequestRepository::approveFlashSaleVendorRequestFunc($id)
      ├─ findOrFail($id) → set request_status = true
      ├─ For each requested product:
      │   ├─ If not already in flash_sale_products → attach
      │   └─ Collect attached product IDs
      ├─ Dispatch FlashSaleProcessed event ('append_attached_products')
      │   └─ Listener: FlashSaleProductProcess
      │       ├─ Update product pricing
      │       └─ Set has_flash_sale = true
         ↓
    Return: approved request
```
