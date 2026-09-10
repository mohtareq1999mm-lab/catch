# Data Flow - Category Feature

## Flow 1: Public Category Listing

```
Client
  |
  GET /api/v1/general/categories?search=face&parentOnly=true
  |
  v
Route: routes/api.php → General\CategoryController@index
  |
  v
CategoryService::paginate(Request $request)
  |
  +-- Applies HasChannelFilter trait
  |     |-- If channel header present, filters categories by shop/channel
  |
  +-- Applies search filter:
  |     |-- scopeSearch('name', $searchTerm, $locale)
  |     |-- Searches within translatable JSON name field
  |
  +-- Applies parentOnly filter:
  |     |-- whereNull('parent_id')
  |
  +-- Applies ordering:
  |     |-- orderBy('pest_category', 'asc')  (custom ordering)
  |
  +-- Paginates:
        |-- Category::active()->paginate(15)
  |
  v
CategoryHomeResource collection
  |
  Maps each category to:
    - id
    - name (translated based on Accept-Language)
    - slug
    - image (desktop URL, mobile URL from media library)
    - products_count
  |
  v
JSON Response
{
  "data": [ { "id": 1, "name": "Face", ... } ],
  "meta": { "current_page": 1, ... }
}
```

## Flow 2: Public Category Detail by Slug

```
Client
  |
  GET /api/v1/general/categories/face
  |
  v
Route: routes/api.php → General\CategoryController@getCategoryBySlug($slug)
  |
  v
CategoryService::getBySlug('face')
  |
  +-- Category::active()
  |     ->where('slug', 'face')
  |     ->with(['products' => function ($q) {
  |         // channel filter applied
  |         // pricing enrichment
  |     }])
  |     ->withCount('children as products_count')
  |     ->firstOrFail()
  |
  v
CategoryWithChildResource
  |
  Maps:
    - id, name, slug, image, products_count, details
    - children (collection of CategoryHomeResource)
    - products (collection of ProductMiniResource)
  |
  v
JSON Response
```

## Flow 3: Admin Category Creation

```
Client
  |
  POST /api/v1/categories
  Authorization: Bearer <token>
  Content-Type: multipart/form-data
  Body: name[en]=New Category, image-desktop=<file>, ...
  |
  v
Middleware: auth:sanctum → verified
  |
  v
Middleware: permission:create-category
  |
  v
CategoryController@store(CategoryCreateRequest $request)
  |
  +-- CategoryCreateRequest::authorize() → true
  |
  +-- CategoryCreateRequest::rules()
  |     |-- name (required|array)
  |     |-- name.* (required|string|unique_translation)
  |     |-- image-desktop (required|file|mimes:jpeg,png,...|max:2048)
  |     |-- image-mobile (required|file|mimes|max:2048)
  |     |-- parent_id (nullable|integer|exists:categories,id)
  |     |-- details (sometimes|string|min:3|max:2500)
  |     |-- products (sometimes|array)
  |     |-- products.* (exists:products,id)
  |
  v
CategoryRepository::saveCategory($request)
  |
  +-- DB::beginTransaction()
  |
  +-- Create Category model:
  |     |-- $category = Category::create([
  |     |     'name' => $request->name,       // translatable array
  |     |     'slug' => $request->slug ?? autoGenerate(),
  |     |     'details' => $request->details,  // translatable array
  |     |     'parent_id' => $request->parent_id,
  |     |     'status' => true
  |     |   ])
  |
  +-- CategoryHierarchyService::syncHierarchy($category)
  |     |-- calculateLevel($parent_id)
  |     |     |-- If no parent: level = 1
  |     |     |-- If parent: level = parent->level + 1
  |     |-- $category->level = calculated level
  |     |-- $category->save()
  |
  +-- Upload images:
  |     |-- $category->addMedia($request->file('image-desktop'))
  |     |       ->toMediaCollection('categories-desktop')
  |     |-- $category->addMedia($request->file('image-mobile'))
  |     |       ->toMediaCollection('categories-mobile')
  |
  +-- Sync products:
  |     |-- if $request->products:
  |     |     $category->products()->sync($request->products)
  |
  +-- DB::commit()
  |
  v
CategoryObserver::created(Category $category)
  |
  +-- LogActivityJob::dispatch(
  |     subject: $category,
  |     event: 'category_created',
  |     description: __('activity.category_created')
  |   )->onQueue('medium')
  |
  v
CategoryResource response
  |
  JSON Response (201)
  {
    "data": {
      "id": 73,
      "name": "New Category",
      "slug": "new-category",
      "parent_id": null,
      "level": 1,
      "status": true,
      ...
    }
  }
```

## Flow 4: Admin Category Update (with hierarchy change)

```
Client
  |
  PUT /api/v1/categories/73
  Body: parent_id=5
  |
  v
CategoryController@update(CategoryUpdateRequest $request, $id)
  |
  +-- CategoryUpdateRequest::rules()
  |     |-- parent_id: closure validates no circular reference
  |     |   CategoryHierarchyService::createsCycle($id, $parent_id)
  |     |   --> Traverses up parent chain to detect cycles
  |
  v
CategoryRepository::updateCategory($request, $category)
  |
  +-- DB::beginTransaction()
  |
  +-- Update model fields
  |
  +-- If parent_id changed:
  |     |-- CategoryHierarchyService::syncHierarchy($category)
  |     |-- CategoryHierarchyService::updateDescendantLevels($category)
  |     |     |-- Recursively updates all children's levels
  |
  +-- Upload new images (replaces old)
  |     |-- $category->clearMediaCollection('categories-desktop')
  |     |-- $category->addMedia(...)
  |
  +-- Sync products
  |
  +-- DB::commit()
  |
  v
CategoryObserver::updated()
  +-- Logs activity
```

## Flow 5: Category Delete

```
Client
  |
  DELETE /api/v1/categories/73
  |
  v
CategoryController@destroy($id)
  |
  +-- $category = Category::findOrFail($id)
  |-- $category->delete()   // Soft delete
  |
  v
CategoryObserver::deleted()
  +-- LogActivityJob::dispatch('category_deleted')
  |
  v
Response: { "message": "Category deleted successfully" }
```

**Note:** Soft delete does NOT:
- Cascade to child categories (children remain with their parent_id)
- Remove product associations (pivot records are preserved)
- Delete media files (media is preserved until force delete)

## Flow 6: Category Hierarchy Validation (Cycle Detection)

```
CategoryUpdateRequest::rules()['parent_id']
  |
  Closure validation:
  |
  CategoryHierarchyService::createsCycle($categoryId, $newParentId)
  |
  +-- Starting from $newParentId
  +-- Traverse up parent chain:
  |     $parent = Category::find($newParentId)
  |     while ($parent) {
  |         if ($parent->id === $categoryId) {
  |             return true;  // CYCLE DETECTED
  |         }
  |         $parent = $parent->parent;
  |     }
  |
  +-- return false (no cycle)
  |
  If cycle detected → ValidationException (422)
```
