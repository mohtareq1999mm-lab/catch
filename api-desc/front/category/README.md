# Category Feature - API Investigation

## Feature Name

Category Management

## Description

The Category feature provides hierarchical product categorization with full CRUD operations, translatable names/details, media attachments, featured categories, pagination/filtering, and GraphQL support. Categories are organized in a tree structure via `parent_id` self-referencing, with automatic level calculation and cycle detection.

## Architecture Overview

```
[Client]
    |
    |--- GET /api/v1/general/categories           (Public API)
    |--- GET /api/v1/general/categories/{slug}     (Public API)
    |--- GET /api/v1/categories                    (Admin API - auth)
    |--- POST /api/v1/categories                   (Admin API - auth)
    |--- GET /api/v1/categories/{id}               (Admin API - auth)
    |--- PUT /api/v1/categories/{id}               (Admin API - auth)
    |--- DELETE /api/v1/categories/{id}            (Admin API - auth)
    |--- GET /api/v1/featured-categories           (Public)
    |--- GraphQL: categories, category             (Queries)
    |--- GraphQL: createCategory, updateCategory, deleteCategory (Mutations)
    |
    v
[CategoryController (Marvel)]  or  [CategoryController (General)]
    |
    v
[CategoryService / CategoryRepository]
    |
    v
[Category Model]
    |--- parent (BelongsTo self)
    |--- children (HasMany self)
    |--- products (BelongsToMany Product)
    |
    v
[categories table]  ←→  [category_product pivot]
```

## Key Endpoints

### Public API (routes/api.php - prefix: `v1/general`)

| Method | URI | Controller | Auth |
|--------|-----|-----------|------|
| GET | `/v1/general/categories` | `General\CategoryController@index` | No |
| GET | `/v1/general/categories/{slug}` | `General\CategoryController@getCategoryBySlug` | No |

### Admin API (packages/marvel/src/Rest/Routes.php - prefix: `v1`)

| Method | URI | Controller | Auth |
|--------|-----|-----------|------|
| GET | `/v1/categories` | `CategoryController@index` | `view-categories` |
| POST | `/v1/categories` | `CategoryController@store` | `create-category` |
| GET | `/v1/categories/{id}` | `CategoryController@show` | `view-categories` |
| PUT | `/v1/categories/{id}` | `CategoryController@update` | `update-category` |
| DELETE | `/v1/categories/{id}` | `CategoryController@destroy` | `delete-category` |
| PUT | `/v1/categories/feature` | `CategoryController@addOrRemoveCategoryFromFeature` | `update-category` |
| GET | `/v1/featured-categories` | `CategoryController@fetchFeaturedCategories` | No |

### GraphQL

| Operation | Resolver |
|-----------|----------|
| `categories` (query) | `@paginate` (Lighthouse built-in) |
| `category` (query) | `@find` (Lighthouse built-in) |
| `createCategory` (mutation) | `CategoryMutator@storeCategory` |
| `updateCategory` (mutation) | `CategoryMutator@updateCategory` |
| `deleteCategory` (mutation) | `@delete` (Lighthouse built-in) |

## Key Files

| Layer | Path |
|-------|------|
| Model | `packages/marvel/src/Database/Models/Category.php` |
| Repository | `packages/marvel/src/Database/Repositories/CategoryRepository.php` |
| Controller (Admin) | `packages/marvel/src/Http/Controllers/CategoryController.php` |
| Controller (Public) | `app/Http/Controllers/Api/General/CategoryController.php` |
| Service (Public) | `app/Services/General/CategoryService.php` |
| Hierarchy Service | `app/Services/General/CategoryHierarchyService.php` |
| Dashboard Service | `app/Services/Dashboard/DashboardService.php` |
| Create Request | `packages/marvel/src/Http/Requests/CategoryCreateRequest.php` |
| Update Request | `packages/marvel/src/Http/Requests/CategoryUpdateRequest.php` |
| Feature Toggle Request | `packages/marvel/src/Http/Requests/CategoryFeatureToggleRequest.php` |
| Resource (Marvel) | `packages/marvel/src/Http/Resources/CategoryResource.php` |
| Resource (General) | `app/Http/Resources/Category/CategoryHomeResource.php` |
| Resource (With Child) | `app/Http/Resources/Category/CategoryWithChildResource.php` |
| Resource (Navbar) | `app/Http/Resources/Category/CategoryNavbarResource.php` |
| GraphQL Schema | `packages/marvel/src/GraphQL/Schema/models/category.graphql` |
| GraphQL Mutator | `packages/marvel/src/GraphQL/Mutations/CategoryMutator.php` |
| Observer | `app/Observers/CategoryObserver.php` |
| Enum (Permissions) | `packages/marvel/src/Enums/Permission.php` |
| Routes (Marvel) | `packages/marvel/src/Rest/Routes.php` |
| Routes (General) | `routes/api.php` |
| Migration | `packages/marvel/database/migrations/2020_06_02_051901_create_marvel_tables.php` |
| Seeder | `database/seeders/CategorySeeder.php` |

## Tech Stack

- **Laravel** with Eloquent ORM
- **Spatie Translatable** for localized name/details
- **Spatie Media Library** for image attachments
- **Lighthouse PHP** for GraphQL
- **Soft Deletes** for safe removal
- **Activity Log** (Spatie) via Observer pattern
