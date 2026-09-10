# Product Feature - API Investigation

## Feature Name

Product Management

## Description

The Product feature provides comprehensive product management with translatable names and descriptions, variable/simple product types, multi-image support, pricing with discount and flash sale logic, advanced filtering, full-text search (Meilisearch), strategy-based product listing, rental pricing, CSV import/export, reviews with images, and both REST and GraphQL APIs. Supports role-based access with granular permissions.

## Architecture Overview

```
[Client]
    |
    |--- GET  /api/v1/general/products              (Public API - Strategy-based)
    |--- GET  /api/v1/general/products/{slug}       (Public API)
    |--- POST /api/v1/general/products/{id}/reviews  (Public API)
    |
    |--- GET    /api/v1/products                     (Admin API)
    |--- POST   /api/v1/products                     (Admin API)
    |--- GET    /api/v1/products/{id}                (Admin API)
    |--- PUT    /api/v1/products/{id}                (Admin API)
    |--- DELETE /api/v1/products/{id}                (Admin API)
    |--- POST   /api/v1/products/bulk-delete         (Admin API)
    |--- DELETE /api/v1/products/all                 (Admin API)
    |
    |--- GET  /api/v1/popular-products               (Public)
    |--- GET  /api/v1/best-selling-products           (Public)
    |--- GET  /api/v1/products/calculate-rental-price (Public)
    |
    |--- POST /api/v1/import-products                (Admin - CSV)
    |--- GET  /api/v1/export-products/{shop_id}      (Admin - CSV)
    |
    |--- GraphQL: products, product                 (Queries)
    |--- GraphQL: createProduct, updateProduct, deleteProduct (Mutations)
    |
    v
[ProductController (General/App)] or [ProductController (Marvel)]
    |
    v
[ProductService + ProductEngine (Strategy)] or [ProductRepository]
    |
    v
[Product Model]
    |--- type (BelongsTo Type)
    |--- shops (BelongsToMany Shop)
    |--- categories (BelongsToMany Category)
    |--- brands (BelongsToMany Brand)
    |--- tags (BelongsToMany Tag)
    |--- variations (HasMany ProductVariant)
    |--- reviews (HasMany Review)
    |--- flash_sales (BelongsToMany FlashSale)
    |--- promotions (BelongsToMany Promotion)
    |--- ... (+15 more relationships)
    |
    v
[products table + product_variants + 15+ pivot tables]
```

## Key Endpoints

### Public API (routes/api.php - prefix: `v1/general`)

| Method | URI | Controller | Auth |
|--------|-----|-----------|------|
| GET | `/v1/general/products` | `ProductController@index` | No |
| GET | `/v1/general/products/{slug}` | `ProductController@getProductBySlug` | No |
| POST | `/v1/general/products/{id}/reviews` | `ProductController@addProductReview` | Sanctum |
| PUT | `/v1/general/products/reviews/{id}` | `ProductController@updateProductReview` | Sanctum |

### Admin API (Marvel Routes)

| Method | URI | Permission |
|--------|-----|-----------|
| GET | `/v1/products` | `view-products` |
| POST | `/v1/products` | `create-product` |
| GET | `/v1/products/{id}` | `view-products` |
| PUT | `/v1/products/{id}` | `update-product` |
| DELETE | `/v1/products/{id}` | `delete-product` |
| POST | `/v1/products/bulk-delete` | `delete-product` |
| DELETE | `/v1/products/all` | `delete-product` |
| PUT | `/v1/products/{id}/fast-shipping` | Authenticated |
| GET | `/v1/draft-products` | Authenticated |
| GET | `/v1/products-stock` | Authenticated |

### Special Endpoints

| Method | URI | Auth |
|--------|-----|------|
| GET | `/v1/popular-products` | Public |
| GET | `/v1/best-selling-products` | Public |
| GET | `/v1/products/calculate-rental-price` | Public |
| GET | `/v1/products/export` | Sanctum + `view-products` |
| POST | `/v1/products/import` | Sanctum + `create-product` |
| POST | `/v1/import-products` | Sanctum (throttle) |
| GET | `/v1/export-products/{shop_id}` | Sanctum |
| POST | `/v1/import-variation-options` | Sanctum (throttle) |
| GET | `/v1/export-variation-options/{shop_id}` | Sanctum |

### GraphQL

| Operation | Resolver |
|-----------|----------|
| `products` (query) | `ProductQuery@fetchProducts` (paginated) |
| `product` (query) | `@find` (Lighthouse built-in) |
| `createProduct` (mutation) | `ProductMutator@storeProduct` |
| `updateProduct` (mutation) | `ProductMutator@updateProduct` |
| `deleteProduct` (mutation) | `ProductMutator@deleteProduct` |
| `calculateRentalPrice` (mutation) | `ProductMutator@calculateRentalPrice` |
| `importProducts` (mutation) | `ProductMutator@importProducts` |
| `importVariationOptions` (mutation) | `ProductMutator@importVariationOptions` |

## Key Files

| Layer | Path |
|-------|------|
| Model (Main) | `packages/marvel/src/Database/Models/Product.php` |
| Model (Meem) | `packages/marvel/src/Database/Models/MeemProduct.php` |
| Repository | `packages/marvel/src/Database/Repositories/ProductRepository.php` |
| Controller (Admin) | `packages/marvel/src/Http/Controllers/ProductController.php` |
| Controller (Public) | `app/Http/Controllers/Api/General/ProductController.php` |
| Controller (Import) | `packages/marvel/src/Http/Controllers/ProductImportController.php` |
| Controller (Export) | `packages/marvel/src/Http/Controllers/ProductExportController.php` |
| Service (Public) | `app/Services/General/ProductService.php` |
| Service (Filter) | `app/Services/General/ProductFilter.php` |
| Service (Pricing) | `packages/marvel/src/Services/Pricing/ProductPricingService.php` |
| Service (Import) | `packages/marvel/src/Services/Import/ProductImportService.php` |
| Engine (Strategy) | `app/Services/General/ProductEngine/ProductStrategyResolver.php` |
| Strategy Interface | `app/Services/General/ProductEngine/Contract/ProductTypeStrategy.php` |
| Strategies (9) | `app/Services/General/ProductEngine/Strategies/*.php` |
| Create Request | `packages/marvel/src/Http/Requests/ProductCreateRequest.php` |
| Update Request | `packages/marvel/src/Http/Requests/ProductUpdateRequest.php` |
| Import Request | `packages/marvel/src/Http/Requests/ProductImportRequest.php` |
| Export Request | `packages/marvel/src/Http/Requests/ProductExportRequest.php` |
| Resource (Admin) | `packages/marvel/src/Http/Resources/product/ProductResource.php` |
| Resource (Public Detail) | `app/Http/Resources/Product/ProductResource.php` |
| Resource (Public List) | `app/Http/Resources/Product/ProductMiniResource.php` |
| GraphQL Schema | `packages/marvel/src/GraphQL/Schema/models/product.graphql` |
| GraphQL Query | `packages/marvel/src/GraphQL/Queries/ProductQuery.php` |
| GraphQL Mutator | `packages/marvel/src/GraphQL/Mutations/ProductMutator.php` |
| Enums (3) | `packages/marvel/src/Enums/ProductType.php`, `ProductStatus.php`, `ProductVisibilityStatus.php` |
| Permissions | `packages/marvel/src/Enums/Permission.php` |
| Events | `packages/marvel/src/Events/DigitalProductUpdateEvent.php` |
| Listeners (3) | `packages/marvel/src/Listeners/` |
| Jobs (2) | `packages/marvel/src/Jobs/ImportProductsJob.php`, `ExportProductsJob.php` |
| Notifications (3) | `packages/marvel/src/Notifications/` |
| Global Scope | `app/Models/Scopes/FastShippingScope.php` |
| Traits | `app/Traits/HasChannelFilter.php`, `app/Traits/HasProductFilters.php` |
| Routes | `routes/api.php`, `packages/marvel/src/Rest/Routes.php` |
| Config | `packages/marvel/config/constants.php` |
| Migration (Main) | `packages/marvel/database/migrations/2020_06_02_051901_create_marvel_tables.php` |
| Factory | `database/factories/ProductVariantFactory.php` |

## Tech Stack

- **Laravel** with Eloquent ORM
- **Spatie Translatable** for localized name and description
- **Spatie Media Library** for product images
- **Soft Deletes** for safe removal
- **Laravel Scout (Meilisearch)** for full-text search
- **Lighthouse PHP** for GraphQL
- **Spatie Permission** for authorization (no Policy class)
- **Strategy Pattern** via ProductEngine for flexible product listing
- **Period (Spatie)** for rental availability calculations
- **Laravel Excel** for CSV import/export
