# Backend - Category Feature

## Overview

The Category feature is implemented across two layers:

1. **App Layer (`app/`)**: Public-facing API consumed by the shop frontend
2. **Package Layer (`packages/marvel/`)**: Admin API consumed by the admin panel, plus GraphQL support

## Key Files

### 1. Model - `packages/marvel/src/Database/Models/Category.php`

**Table:** `categories`

**Traits:** `HasTranslations` (Spatie), `InteractsWithMedia` (Spatie Media Library), `SoftDeletes`

**Translatable:** `['name', 'details']`

**Fillable:**
- `name`, `details`, `slug`, `is_featured`, `parent_id`, `level`, `status`

**Relationships:**

| Method | Type | Related | Pivot |
|--------|------|---------|-------|
| `products()` | `BelongsToMany` | `Product` | `category_product` |
| `children()` | `HasMany` | `Category` (self) | — |
| `parent()` | `BelongsTo` | `Category` (self) | — |

**Scopes:** `active()`, `inactive()`, `search($field, $term, $locale)`

**Booted Events:**
- `saving`: Calls `CategoryHierarchyService::syncHierarchy`, auto-generates slug
- `saved`: Updates descendant levels when `parent_id` changes
- `retrieved`: Legacy JSON slug fix

### 2. Repository - `packages/marvel/src/Database/Repositories/CategoryRepository.php`

**Extends:** `BaseRepository`

**Methods:**

| Method | Description |
|--------|-------------|
| `model()` | Returns `Category::class` |
| `boot()` | Pushes `RequestCriteria` |
| `saveCategory(Request): Category` | Creates category in transaction (model + images + product sync) |
| `updateCategory($request, $category): Category` | Updates category in transaction |

**Searchable fields:** `['name' => 'like']`

### 3. Controller (Admin) - `packages/marvel/src/Http/Controllers/CategoryController.php`

**Extends:** `CoreController`

**Permissions (via middleware):**

| Method | Permission |
|--------|-----------|
| `index` | `view-categories` |
| `store` | `create-category` |
| `show` | `view-categories` |
| `update` | `update-category` |
| `destroy` | `delete-category` |
| `fetchFeaturedCategories` | Public |
| `addOrRemoveCategoryFromFeature` | `update-category` |

**Methods:**

| Method | Signature | Description |
|--------|-----------|-------------|
| `index` | `(Request $request)` | Paginated list with filtering (parent, search, featured, status) |
| `store` | `(CategoryCreateRequest $request)` | Creates via repository |
| `show` | `(Request $request, $id)` | Single category with parent, products, children |
| `update` | `(Request $request, $id)` | Updates via repository |
| `destroy` | `($id)` | Soft deletes |
| `fetchFeaturedCategories` | `(Request $request)` | Top N by product count (public) |
| `addOrRemoveCategoryFromFeature` | `(CategoryFeatureToggleRequest $request)` | Toggles `is_featured` |

### 4. Controller (Public) - `app/Http/Controllers/Api/General/CategoryController.php`

**Methods:**

| Method | Signature | Description |
|--------|-----------|-------------|
| `index` | `(Request $request)` | Lists categories with optional slug, returns `CategoryHomeResource` |
| `getCategoryBySlug` | `($slug)` | Single category by slug with children + products |

### 5. Service (Public) - `app/Services/General/CategoryService.php`

**Uses trait:** `HasChannelFilter`

| Method | Description |
|--------|-------------|
| `paginate(Request)` | Paginates active categories with search, parent-only filter |
| `getBySlug($slug)` | Fetches active category by slug with products, children, enriched pricing |

### 6. Hierarchy Service - `app/Services/General/CategoryHierarchyService.php`

| Method | Description |
|--------|-------------|
| `calculateLevel(?int $parentId): int` | Level = parent level + 1 (root = 1) |
| `syncHierarchy(Category): void` | Validates hierarchy, sets level before save |
| `ensureHierarchyIsValid(Category, ?int $parentId): void` | Prevents self-parenting and circular refs |
| `createsCycle(int $categoryId, int $parentId): bool` | Traverses parent chain to detect cycles |
| `updateDescendantLevels(Category): void` | Recursively updates descendant levels |
| `loadRecursiveChildren(Collection, bool): Collection` | BFS eager-loads child tree |
| `loadDirectChildren(Category, bool): Category` | Loads direct children only |
| `loadRecursiveTree(Category, bool): Category` | Loads full recursive child tree |

### 7. Dashboard Service - `app/Services/Dashboard/DashboardService.php`

| Method | Description |
|--------|-------------|
| `getCategoryStats(Request): array` | Top/bottom categories by product count (cached 5 min) |
| `getCategoryAnalytics(Request): array` | Per-category product count, revenue, growth (cached 5 min) |

### 8. Form Requests

**CategoryCreateRequest** (`packages/marvel/src/Http/Requests/CategoryCreateRequest.php`):
- `name` (required, array of locale strings)
- `name.*` (required, string, unique translation)
- `image-desktop` (required, file, mimes:jpeg/png/jpg/gif/svg, max:2MB)
- `image-mobile` (required, file, mimes, max:2MB)
- `parent_id` (nullable, integer, exists:categories,id)
- `details` (sometimes, string, min:3, max:2500)
- `products` (sometimes, array of existing product IDs)

**CategoryUpdateRequest** (`packages/marvel/src/Http/Requests/CategoryUpdateRequest.php`):
- Same as create but all fields optional, plus `status` (in:0,1) and circular reference closure on `parent_id`

**CategoryFeatureToggleRequest** (`packages/marvel/src/Http/Requests/CategoryFeatureToggleRequest.php`):
- `id` (required, integer, exists:categories,id)

### 9. API Resources

| Resource | Route | Fields |
|----------|-------|--------|
| `CategoryResource` (Marvel) | All admin routes | id, name, slug, parent_id, level, image, is_featured, products_count, status, details (excl. index), children, products |
| `CategoryCollection` (Marvel) | Index paginated | data + pagination links |
| `ChildrenCategoryResource` (Marvel) | Nested children | id, name, slug, products_count, image |
| `CategoryHomeResource` (app) | General index | id, name, slug, image, products_count, details |
| `CategoryWithChildResource` (app) | General by-slug | id, name, slug, image, products_count, details, children, products |
| `CategoryNavbarResource` (app) | Navbar | id, name, slug, level, image, children (recursive) |
| `CategoryWithChildNameResource` (app) | Navbar alternative | id, name, slug, level, image, children (recursive, level-limited) |

### 10. GraphQL

**Schema:** `packages/marvel/src/GraphQL/Schema/models/category.graphql`

**Queries:**
- `categories(orderBy, language, name, text, parent, hasType): [Category!]! @paginate`
- `category(id, slug, language): Category @find`

**Mutations:**
- `createCategory(input: CreateCategoryInput!): Category` — `@can(ability: "super_admin")`
- `updateCategory(input: UpdateCategoryInput!): Category` — `@can(ability: "super_admin")`
- `deleteCategory(id: ID!): Category @delete` — `@can(ability: "super_admin")`

**Mutator:** `packages/marvel/src/GraphQL/Mutations/CategoryMutator.php`
- Delegates to `CategoryController@store` and `CategoryController@categoryUpdate`

### 11. Observer - `app/Observers/CategoryObserver.php`

| Event | Action |
|-------|--------|
| `created` | Dispatches `LogActivityJob('category_created')` |
| `updated` | Dispatches `LogActivityJob('category_updated')` or `category_activated`/`category_deactivated` |
| `deleted` | Dispatches `LogActivityJob('category_deleted')` |

### 12. Permissions - `packages/marvel/src/Enums/Permission.php`

| Constant | Value |
|----------|-------|
| `VIEW_CATEGORIES` | `view-categories` |
| `CREATE_CATEGORY` | `create-category` |
| `UPDATE_CATEGORY` | `update-category` |
| `DELETE_CATEGORY` | `delete-category` |
| `VIEW_CATEGORY` | `view-category` |

### 13. Config Constants - `packages/marvel/config/constants.php`

| Constant | Message Key |
|----------|-------------|
| `CATEGORY_CREATED_SUCCESSFULLY` | `message.CATEGORY_CREATED_SUCCESSFULLY` |
| `CATEGORY_UPDATED_SUCCESSFULLY` | `message.CATEGORY_UPDATED_SUCCESSFULLY` |
| `CATEGORY_DELETED_SUCCESSFULLY` | `message.CATEGORY_DELETED_SUCCESSFULLY` |
| `CATEGORY_FEATURE_TOGGLED_SUCCESSFULLY` | `message.CATEGORY_FEATURE_TOGGLED_SUCCESSFULLY` |
| `CANNOT_DELETE_CATEGORY_WITH_ASSOCIATED_RESOURCES` | `error.CANNOT_DELETE_CATEGORY_WITH_ASSOCIATED_RESOURCES` |

## Data Flow (Public Category Listing)

```
Client
  |
  GET /api/v1/general/categories?search=shoes&parentOnly=true
  |
  v
General\CategoryController@index(Request $request)
  |
  v
CategoryService::paginate(Request $request)
  |--- Applies channel filter (trait: HasChannelFilter)
  |--- Searches translatable 'name' field
  |--- Filters by parent_id IS NULL (if parentOnly=true)
  |--- Orders by pest_category ordering
  |--- Paginates results
  |
  v
CategoryHomeResource collection
  |--- Maps each category to: id, name (translated), slug, image, products_count
  |
  v
JSON Response
```

## Data Flow (Admin Category Creation)

```
Client
  |
  POST /api/v1/categories
  Authorization: Bearer <token>
  Body: name[en]=Shoes, image-desktop=<file>, ...
  |
  v
CategoryController@store(CategoryCreateRequest $request)
  |--- Validates input via FormRequest
  |
  v
CategoryRepository::saveCategory($request)
  |--- DB::transaction
  |--- Creates Category model with translated name, slug, details
  |--- syncHierarchy (calculates level)
  |--- Uploads desktop/mobile images via Spatie Media Library
  |--- Syncs product associations (if provided)
  |--- Commits
  |
  v
CategoryObserver::created()
  |--- Dispatches LogActivityJob
  |
  v
CategoryResource response
```
