# Request Flows — Brand Module

## Flow 1: List Brands (Admin)

```
Client → GET /api/v1/brands?active=true&search=apple&page=1&per_page=15
         ↓
    [auth:sanctum] middleware → authenticate token
         ↓
    [permission:view-brands] middleware → check Spatie permission
         ↓
    BrandController@index()
         ↓
    Apply filters:
      - active=true → where('status', 1)
      - inactive=true → where('status', 0)
      - search → where name LIKE '%apple%' (translatable)
      - order / sortedBy → orderBy($order, $sortedBy)
         ↓
    BrandRepository::ordered() → paginate($per_page)
         ↓
    BrandResource::collection($brands) → transform each brand
         ↓
    Return: { status:200, message, success:true, data: { data[], pagination_meta } }
```

## Flow 2: Create Brand (Admin)

```
Client → POST /api/v1/brands (multipart/form-data)
         ↓
    [auth:sanctum] middleware
         ↓
    [permission:create-brand] middleware
         ↓
    BrandCreateRequest → validation rules:
      - name: required, array, unique_translation
      - image-desktop: required, file, max:2MB
      - image-mobile: required, file, max:2MB
      - details: sometimes, array
      - status: sometimes, in:1,0
      - products.*: integer, exists:products,id
         ↓
    Fail? → 422 with field errors
         ↓
    BrandController@store()
         ↓
    BrandRepository::saveBrand($request)
         ↓
    DB::beginTransaction()
      ├─ Extract data: $request->only(['name', 'slug', 'details', 'status'])
      ├─ Generate slug via makeSlug($request)
      ├─ Brand::create($data)
      ├─ If products[] → $brand->products()->sync($products)
      ├─ If image-desktop → uploadSingleImage('brands-desktop', 'brands')
      └─ If image-mobile → uploadSingleImage('brands-mobile', 'brands')
         ↓
    DB::commit()
         ↓
    $brand->load('products')
         ↓
    BrandResource::make($brand)
         ↓
    BrandObserver@created() → dispatches LogActivityJob
         ↓
    Return: { status:201, message, success:true, data }
```

## Flow 3: Show Brand (Admin)

```
Client → GET /api/v1/brands/1  OR  GET /api/v1/brands/apple
         ↓
    [auth:sanctum] → [permission:view-brands]
         ↓
    BrandController@show($params)
         ↓
    is_numeric($params)?
      ├─ Yes → $this->repository->with('products')->where('id', (int)$params)->firstOrFail()
      └─ No  → $this->repository->with('products')->where('slug', $params)->firstOrFail()
         ↓
    Found? → BrandResource::make($brand) → 200
    Not found? → MarvelException(NOT_FOUND) → 404
```

## Flow 4: Update Brand (Admin)

```
Client → PUT /api/v1/brands/1 (multipart/form-data)
         ↓
    [auth:sanctum] → [permission:update-brand]
         ↓
    BrandUpdateRequest → validation (unique ignores current ID)
         ↓
    BrandController@update($request, $id)
         ↓
    $request->merge(['id' => $id])
         ↓
    brandUpdate($request) [private]
      → BrandRepository::findOrFail($id)
      → BrandRepository::updateBrand($request, $brand)
         ↓
    DB::beginTransaction()
      ├─ Extract data from request
      ├─ If name changed → regenerate slug via makeSlug() with update ID
      ├─ $brand->update($data)
      ├─ If products[] → $brand->products()->sync($products) [replaces all]
      ├─ If image-desktop → updateSingleImage() [clears + uploads]
      └─ If image-mobile → updateSingleImage() [clears + uploads]
         ↓
    DB::commit()
         ↓
    $brand->load('products')
         ↓
    BrandResource::make($brand)
         ↓
    BrandObserver@updated() → dispatches LogActivityJob
         ↓
    Return: { status:200, message, success:true, data }
```

## Flow 5: Delete Brand (Admin)

```
Client → DELETE /api/v1/brands/1
         ↓
    [auth:sanctum] → [permission:delete-brand]
         ↓
    BrandController@destroy($id)
         ↓
    BrandRepository::findOrFail($id)
         ↓
    $brand->delete()  → sets deleted_at (soft delete)
         ↓
    BrandObserver@deleted() → dispatches LogActivityJob
         ↓
    Return: { status:200, message, success:true }
```

## Flow 6: Reorder Brands (Admin)

```
Client → PUT /api/v1/brands/reorder { "brands": [3, 1, 2] }
         ↓
    [auth:sanctum] → [permission:update-brand]
         ↓
    BrandsReorderRequest → validation: brands required|array, brands.* required|integer|exists:brands,id
         ↓
    Fail? → 422
         ↓
    BrandController@reorder($request)
         ↓
    BrandRepository::reorder($request->brands)
         ↓
    $this->setNewOrder($brands)  [Spatie SortableTrait]
      → Updates 'order' column: brand 3 → order 0, brand 1 → order 1, brand 2 → order 2
         ↓
    Return: { status:200, message, success:true }
```

## Flow 7: List Brands (Public)

```
Client → GET /api/v1/general/brands?limit=10&brandsId=1,2,3
         ↓
    BrandController@index(Request)
         ↓
    If slug query param → delegate to getBrandBySlug()
         ↓
    BrandService::getBrands($request)
         ↓
    Brand::active()
      ├─ Filter by start_date / end_date
      └─ Filter by brandsId (comma-separated)
         ↓
    orderBy('id', $order) → limit($limit) → get()
         ↓
    BrandResource::collection($brands)
         ↓
    Return: { status:200, message, success:true, data[] }
```

## Flow 8: Get Brand by Slug (Public)

```
Client → GET /api/v1/general/brands/apple
         ↓
    BrandController@getBrandBySlug($slug)
         ↓
    BrandService::getBrandBySlug($slug)
         ↓
    Brand::active()->search('slug', $slug, locale)->first()
         ↓
    If found:
      ├─ Load products with channel filter, media, review averages
      └─ ProductService::enrichCollectionWithPricing()
         ↓
    BrandResource::make($brand) → 200
         ↓
    Not found → Return: { status:404, message, success:false }
```

## Flow 9: Get Brands Products by Quantity (Public)

```
Client → GET /api/v1/general/brands-products?limit=10&limit_brand=5
         ↓
    BrandController@getBrandsProductsByQtySet(Request)
         ↓
    BrandService::getBrandsProductsByQtySet($request)
         ↓
    Brand::active()
      ├─ Filter by start_date / end_date
      ├─ Load products (limit per brand) with channel filter, media, review averages
      └─ Limit brands
         ↓
    Flatten all products into single collection
         ↓
    ProductService::enrichCollectionWithPricing()
         ↓
    ProductMiniResource::collection($products)
         ↓
    Return: { status:200, message, success:true, data[] }
```
