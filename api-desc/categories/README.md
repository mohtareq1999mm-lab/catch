# Category Module

## Overview

The Category module manages hierarchical product categories for the e-commerce platform. It provides:

- **Admin API** (`/api/v1/categories`) — Full CRUD + feature toggle, protected by permissions
- **Public API** (`/api/v1/general/categories`) — Read-only, no authentication required
- **Featured Categories** (`/api/v1/featured-categories`) — Public, top N categories by product count

Categories support a self-referencing parent-child hierarchy with automatic level calculation and cycle detection. They are fully translatable (name, details), support media uploads (desktop + mobile images), featured toggling, and associate with products via a many-to-many pivot.

## Key Files

| Layer | File |
|-------|------|
| Admin Controller | `packages/marvel/src/Http/Controllers/CategoryController.php` |
| Public Controller | `app/Http/Controllers/Api/General/CategoryController.php` |
| Repository | `packages/marvel/src/Database/Repositories/CategoryRepository.php` |
| Model | `packages/marvel/src/Database/Models/Category.php` |
| Admin Resource | `packages/marvel/src/Http/Resources/CategoryResource.php` |
| Public Resource | `app/Http/Resources/Category/CategoryHomeResource.php` |
| Detail Resource | `app/Http/Resources/Category/CategoryWithChildResource.php` |
| Navbar Resource | `app/Http/Resources/Category/CategoryNavbarResource.php` |
| Create Request | `packages/marvel/src/Http/Requests/CategoryCreateRequest.php` |
| Update Request | `packages/marvel/src/Http/Requests/CategoryUpdateRequest.php` |
| Public Service | `app/Services/General/CategoryService.php` |
| Hierarchy Service | `app/Services/General/CategoryHierarchyService.php` |
| Observer | `app/Observers/CategoryObserver.php` |
| Admin Routes | `packages/marvel/src/Rest/Routes.php` (lines 229-233, 678-679) |
| Public Routes | `routes/api.php` |
| Permissions | `packages/marvel/src/Enums/Permission.php` |
| Migration | `packages/marvel/database/migrations/2020_06_02_051901_create_marvel_tables.php` |
| Level Migration | `packages/marvel/database/migrations/2026_05_18_000001_add_level_to_categories_table.php` |
| Seeder | `database/seeders/CategorySeeder.php` |
| Import | `packages/marvel/src/Imports/Sheets/CategoriesSheetImport.php` |
| Export | `packages/marvel/src/Exports/Sheets/CategoriesSheetExport.php` |
| Strategy | `app/Services/General/ProductEngine/Strategies/ProductForCategory.php` |

## Dependencies

- **Spatie Translatable** (`HasTranslations`) — bilingual name/details (en/ar)
- **Spatie Media Library** (`InteractsWithMedia`) — category image management
- **Laravel SoftDeletes** — soft delete support
- **Prettus Repository** — repository pattern with caching

## Permissions

| Permission | Required For |
|------------|-------------|
| `view-categories` | GET /categories, GET /categories/{id} |
| `create-category` | POST /categories |
| `update-category` | PUT /categories/{id}, PUT /categories/feature |
| `delete-category` | DELETE /categories/{id} |

## Routes

### Admin

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/v1/categories` | List categories (paginated, filterable) |
| POST | `/api/v1/categories` | Create category (with images + products) |
| GET | `/api/v1/categories/{id}` | Show category with parent, children, products |
| PUT | `/api/v1/categories/{id}` | Update category |
| DELETE | `/api/v1/categories/{id}` | Soft-delete (restricted if children exist) |
| PUT | `/api/v1/categories/feature` | Toggle category featured status |
| GET | `/api/v1/featured-categories` | Top N categories by product count (public) |

### Public

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/v1/general/categories` | List active categories (paginated, searchable) |
| GET | `/api/v1/general/categories/{slug}` | Get category by slug with children + products |

### Analytics / Dashboard

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/v1/category-wise-product` | Category-wise product count |
| GET | `/api/v1/category-wise-product-sale` | Category-wise product sales |
| GET | `/api/v1/category-stats` | Category statistics |
| GET | `/api/v1/dashboard/categories` | Category analytics |
