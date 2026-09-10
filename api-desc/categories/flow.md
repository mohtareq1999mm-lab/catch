# Request Flows — Category Module

## Flow 1: List Categories (Admin)

```
Client → GET /api/v1/categories?parent=true&search=men&feature-category=1&page=1&per_page=15
         ↓
    [auth:sanctum] middleware → authenticate token
         ↓
    [permission:view-categories] middleware → check Spatie permission
         ↓
    CategoryController@index(Request)
         ↓
    Apply filters:
      - parent=true → whereNull('parent_id')
      - exceptSelf={id} → where('id', '!=', $selfId)
      - active=1 → where('status', 1)
      - inactive=1 → where('status', 0)
      - search → where name LIKE '%men%' (translatable)
      - feature-category=1 → where('is_featured', true)
      - order / sortedBy → orderBy($order, $sortedBy)
         ↓
    CategoryRepository + withCount('products') → paginate($per_page)
         ↓
    CategoryResource::collection($categories) → transform each category
         ↓
    Return: { status:200, message, success:true, data: { data[], pagination_meta } }
```

## Flow 2: Create Category (Admin)

```
Client → POST /api/v1/categories (multipart/form-data)
         ↓
    [auth:sanctum] middleware
         ↓
    [permission:create-category] middleware
         ↓
    CategoryCreateRequest → validation rules:
      - name: required, array
      - name.*: required, string, UniqueTranslationRule
      - image-desktop: required, file, max:2MB
      - image-mobile: required, file, max:2MB
      - parent_id: nullable, integer, exists:categories,id
      - details: sometimes, string, min:3, max:2500
      - products: sometimes, array
      - products.*: exists:products,id
         ↓
    Fail? → 422 with field errors under { message, errors }
         ↓
    CategoryController@store()
         ↓
    CategoryRepository::saveCategory($request)
         ↓
    DB::beginTransaction()
      ├─ Generate slug via makeSlug($request)
      ├─ Extract data: name, slug, details, parent_id, is_featured, status
      ├─ Category::create($data)
      │    └─ Model saving event → CategoryHierarchyService::syncHierarchy()
      │         ├─ Calculates level = parent.level + 1 (or 1 if no parent)
      │         └─ Validates: not self-parent, no circular reference
      ├─ If products[] → $category->products()->sync($products)
      ├─ If image-desktop → uploadSingleImage('categories-desktop', 'categories')
      └─ If image-mobile → uploadSingleImage('categories-mobile', 'categories')
         ↓
    DB::commit()
         ↓
    $category->load('products')
         ↓
    CategoryResource::make($category)
         ↓
    CategoryObserver@created() → dispatches LogActivityJob
         ↓
    Return: { status:200, message:CATEGORY_CREATED_SUCCESSFULLY, success:true, data }
```

## Flow 3: Show Category (Admin)

```
Client → GET /api/v1/categories/3
         ↓
    [auth:sanctum] → [permission:view-categories]
         ↓
    CategoryController@show($id)
         ↓
    $this->repository->with(['parent', 'products'])
         ->withCount('products')
         ->where('id', $id)->firstOrFail()
         ↓
    CategoryHierarchyService::loadDirectChildren($category, true)
         ↓
    CategoryResource::make($category) → with children, parent, products
         ↓
    Found? → Return: { status:200, message, success:true, data }
    Not found? → MarvelException(NOT_FOUND) → 404
```

## Flow 4: Update Category (Admin)

```
Client → PUT /api/v1/categories/3 (multipart/form-data)
         ↓
    [auth:sanctum] → [permission:update-category]
         ↓
    CategoryUpdateRequest → validation:
      - name: sometimes, array
      - name.*: sometimes, string, UniqueTranslationRule (ignores current ID)
      - parent_id: nullable, integer, exists:categories,id + cycle check
      - details: sometimes, string, min:3, max:2500
      - image-desktop: sometimes, file, max:2MB
      - image-mobile: sometimes, file, max:2MB
      - products: sometimes, array
      - products.*: exists:products,id
      - status: sometimes, in:0,1
         ↓
    CategoryController@update($request, $id)
         ↓
    $request->merge(['id' => $id])
         ↓
    categoryUpdate($request) [private]
      → $this->repository->findOrFail($request->id)
      → $this->repository->updateCategory($request, $category)
         ↓
    DB::beginTransaction()
      ├─ Extract data: name, slug, details, parent_id, is_featured, status
      ├─ If name provided → regenerate slug via makeSlug() with update ID
      ├─ $category->update($data)
      │    └─ Model saving event → CategoryHierarchyService::syncHierarchy()
      │         ├─ Recalculates level if parent_id changed
      │         └─ Validates hierarchy
      │    └─ Model saved event → updateDescendantLevels() [recursive]
      ├─ If image-desktop → updateSingleImage() [clears + uploads]
      ├─ If image-mobile → updateSingleImage() [clears + uploads]
      └─ If products[] → $category->products()->sync($products) [replaces all]
         ↓
    DB::commit()
         ↓
    $category->load('products')
         ↓
    CategoryResource::make($category)
         ↓
    CategoryObserver@updated() → dispatches LogActivityJob
         ↓
    Return: { status:200, message:CATEGORY_UPDATED_SUCCESSFULLY, success:true, data }
```

## Flow 5: Delete Category (Admin)

```
Client → DELETE /api/v1/categories/3
         ↓
    [auth:sanctum] → [permission:delete-category]
         ↓
    CategoryController@destroy($id)
         ↓
    $this->repository->findOrFail($id)
         ↓
    Has children?  → parent FK is RESTRICT → QueryException
         ├─ Yes → MarvelException(CANNOT_DELETE_CATEGORY_WITH_ASSOCIATED_RESOURCES) → 409
         └─ No  → $category->delete() → sets deleted_at (soft delete)
              ↓
         CategoryObserver@deleted() → dispatches LogActivityJob
              ↓
         Return: { status:200, message:CATEGORY_DELETED_SUCCESSFULLY, success:true }
```

## Flow 6: Toggle Featured (Admin)

```
Client → PUT /api/v1/categories/feature { "id": 3 }
         ↓
    [auth:sanctum] → [permission:update-category] middleware
         ↓
    CategoryFeatureToggleRequest → validation:
      - id: required, integer, exists:categories,id
         ↓
    Fail? → 422 with field errors under { message, errors }
         ↓
    CategoryController@addOrRemoveCategoryFromFeature($request)
         ↓
    Category::find($request->id)
         ↓
    $category->is_featured = !$category->is_featured
         ↓
    $category->save()
         ↓
    Return: { status:200, message:CATEGORY_FEATURE_TOGGLED_SUCCESSFULLY, success:true }
```

## Flow 7: Featured Categories (Public)

```
Client → GET /api/v1/featured-categories?limit=3
         ↓
    No middleware (public)
         ↓
    CategoryController@fetchFeaturedCategories(Request)
         ↓
    $this->repository->with(['products'])
         ->withCount('products')
         ->orderByDesc('products_count')
         ->limit($limit)          [default: 3]
         ->get()
         ↓
    CategoryResource::collection($categories)
         ↓
    Return: { status:200, message, success:true, data[] }
```

## Flow 8: List Categories (Public)

```
Client → GET /api/v1/general/categories?limit=15&search=men&parent=true&pest_category=1&categoriesId=1,2,3
         ↓
    No middleware (public)
         ↓
    General\CategoryController@index(Request)
         ↓
    If slug query param → delegate to getCategoryBySlug()
         ↓
    CategoryService::paginate($request)
         ↓
    Category::active()->withCount('products')
      ├─ Filter by categoriesId (comma-separated or array)
      ├─ Search by name OR details (translatable LIKE on JSON + raw)
      ├─ If parent=true → whereNull('parent_id')
      ├─ If pest_category → orderBy('products_count', $order)
      └─ Else → orderBy('id', $order)
         ↓
    paginate($limit, max 100)
         ↓
    CategoryHomeResource::collection($categories)
         ↓
    Return: { status:200, message, success:true, data, pagination_meta }
```

## Flow 9: Get Category by Slug (Public)

```
Client → GET /api/v1/general/categories/men
         ↓
    No middleware (public)
         ↓
    General\CategoryController@getCategoryBySlug($slug)
         ↓
    CategoryService::getBySlug($slug)
         ↓
    Category::active()
      ├─ with('products' → channel filter)
      ├─ with('children' → active, withCount('products'))
      ├─ withCount('products')
      └─ where('slug', $slug)->firstOrFail()
         ↓
    ProductService::enrichCollectionWithPricing($category->products)
         ↓
    Found?
      ├─ Yes → CategoryWithChildResource::make($category)
      │          Return: { status:200, message, success:true, data }
      └─ No  → Return: { status:404, message:NOT_FOUND, success:false }
```

## Flow 10: Category-Wise Product Count (Analytics)

```
Client → GET /api/v1/category-wise-product
         ↓
    [auth:sanctum] middleware
         ↓
    AnalyticsController@categoryWiseProduct()
         ↓
    Category::withCount('products')->get()
         ↓
    Transform: { label: category_name, value: products_count }
         ↓
    Return: { status:200, message, success:true, data[] }
```

## Flow 11: Category-Wise Product Sale (Analytics)

```
Client → GET /api/v1/category-wise-product-sale
         ↓
    [auth:sanctum] middleware
         ↓
    AnalyticsController@categoryWiseProductSale()
         ↓
    Category::withCount(['products' with sale filters])->get()
         ↓
    Transform: { label: category_name, value: sale_count }
         ↓
    Return: { status:200, message, success:true, data[] }
```

## Flow 12: Category Stats (Dashboard)

```
Client → GET /api/v1/category-stats
         ↓
    [auth:sanctum] middleware
         ↓
    DashboardController@categoryStats()
         ↓
    Aggregate stats: total categories, active, inactive, featured
         ↓
    Return: { status:200, message, success:true, data }
```

## Flow 13: Category Analytics (Dashboard)

```
Client → GET /api/v1/dashboard/categories
         ↓
    [auth:sanctum] middleware
         ↓
    DashboardController@categoryAnalytics()
         ↓
    Category analytics: trends, top categories by product count
         ↓
    Return: { status:200, message, success:true, data }
```
