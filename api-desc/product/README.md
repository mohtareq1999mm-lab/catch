# Product Module — API Documentation

## Overview

The Product module manages the entire product catalog including simple and variable products, inventory, pricing with discounts and flash sales, product-media (images), categories, brands, tags, reviews, and more.

## Key Files

| File | Purpose |
|------|---------|
| `ProductController.php` | 5 CRUD endpoints + bulk-delete, destroy-all, import/export, toggle-fast-shipping |
| `ProductRepository.php` | storeProduct, updateProduct, addVariants, syncRelation, pricing, availability |
| `ProductCreateRequest.php` | Validation for create |
| `ProductUpdateRequest.php` | Validation for update (with unique ignore) |
| `ProductResource.php` | Response shape with 40+ fields |
| `ProductCollection.php` | Paginated list response |
| `Product.php` | Model with 59 fillable fields, 25+ relations, SoftDeletes, HasTranslations |
| `ProductPricingService.php` | Pricing logic: discounts, flash sales, variants |
| `ProductFilter.php` | Category, banner, flash_sale, slider, status, date_range, tag, tags filters |
| `ReviewController.php` | 6 review endpoints (CRUD + toggle-approve) |
| `ReviewRepository.php` | storeReview, updateReview, toggleApprove |
| `ReviewCreateRequest.php` | Validation for review create |
| `ProductImportController.php` | 4 import endpoints (import, status, cancel, download-errors) |
| `TagController.php` | Full CRUD for tags (with permissions) |
| `TagRepository.php` | Tag CRUD with image upload support |
| `TagCreateRequest.php` | Validation for tag create |
| `TagUpdateRequest.php` | Validation for tag update |
| `TagResource.php` | Tag response shape (name, slug, image, icon) |

## Permissions

| Permission | Methods |
|------------|---------|
| `view-products` | index, show |
| `create-product` | store, import routes |
| `update-product` | update |
| `delete-product` | destroy, destroyAll, destroyBulk |
| `delete-reviews` | review destroy |
| `approve-reviews` | review toggle-approve |
| `view-tags` | tag index, show |
| `create-tags` | tag store |
| `update-tags` | tag update |
| `delete-tags` | tag destroy |

## Routes

| Method | URI | Controller@function | Auth | Purpose |
|--------|-----|---------------------|------|---------|
| GET | `/products` | `ProductController@index` | Public | List paginated products |
| GET | `/tags` | `TagController@index` | auth:sanctum | List tags |
| POST | `/tags` | `TagController@store` | auth:sanctum | Create tag |
| GET | `/tags/{id}` | `TagController@show` | auth:sanctum | Show tag |
| PUT | `/tags/{id}` | `TagController@update` | auth:sanctum | Update tag |
| DELETE | `/tags/{id}` | `TagController@destroy` | auth:sanctum | Delete tag |
| POST | `/products` | `ProductController@store` | auth:sanctum | Create product |
| GET | `/products/{id}` | `ProductController@show` | Public | Show product by ID/slug |
| PUT | `/products/{id}` | `ProductController@update` | auth:sanctum | Update product |
| DELETE | `/products/{id}` | `ProductController@destroy` | auth:sanctum | Delete product (soft) |
| POST | `/products/bulk-delete` | `ProductController@destroyBulk` | auth:sanctum | Delete multiple products |
| DELETE | `/products/all` | `ProductController@destroyAll` | auth:sanctum | Delete ALL products |
| POST | `/products/import` | `ProductImportController@import` | auth:sanctum | Import via Excel |
| GET | `/products/import/{id}` | `ProductImportController@status` | auth:sanctum | Import status |
| POST | `/products/import/{id}/cancel` | `ProductImportController@cancel` | auth:sanctum | Cancel import |
| GET | `/products/import/{id}/download-errors` | `ProductImportController@downloadErrors` | auth:sanctum | Download error rows |
| GET | `/reviews` | `ReviewController@index` | Public | List reviews (requires product_id) |
| POST | `/reviews` | `ReviewController@store` | auth:sanctum | Create review |
| GET | `/reviews/{id}` | `ReviewController@show` | Public | Show review |
| PUT | `/reviews/{id}` | `ReviewController@update` | auth:sanctum | Update review |
| DELETE | `/reviews/{id}` | `ReviewController@destroy` | auth:sanctum | Delete review |
| PATCH | `/reviews/{id}/toggle-approve` | `ReviewController@toggleApproveReview` | auth:sanctum | Toggle approval |

### GET /products — Query Parameters

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `page` | int | 1 | Page number |
| `limit` | int | 15 | Results per page |
| `search` | string | — | Search in product name, description, SKU, and variant SKUs |
| `sort` | string | `desc` | Legacy sort direction by `created_at` (`asc` or `desc`) |
| `orderBy` | string | `created_at` | Column to sort by. Supported: `created_at`, `updated_at`, `name`, `price`, `sold_quantity`, `sku`, `id` |
| `orderDir` | string | `desc` | Sort direction (`asc` or `desc`) |
| `date_range` | string | — | Date range `YYYY-MM-DD//YYYY-MM-DD` for availability filtering |
| `status` | int | — | Filter by product status (`0` or `1`) |
| `category` | string | — | Filter by category slug (e.g. `?category=electronics`) |
| `banner` | string | — | Filter by banner slug (e.g. `?banner=summer-sale`) |
| `flash_sale` | string | — | Filter by flash sale slug (e.g. `?flash_sale=flash-01`) |
| `promotion` | string | — | Filter by promotion slug (e.g. `?promotion=summer-deal`) |
| `slider` | string | — | Filter by slider slug (e.g. `?slider=hero-banner`) |
| `tags` | string | — | Filter by tag slug or ID (e.g. `?tags=summer` or `?tags=1,2`). Comma-separated. Supports slug or numeric ID lookup. AND logic. |

## Additional Endpoints (not CRUD)

| Method | URI | Purpose |
|--------|-----|---------|
| PUT | `/products/{id}/fast-shipping` | Toggle fast shipping flag |
| GET | `/products/calculate-rental-price` | Calculate rental price |
| GET | `/popular-products` | Popular products list |
| GET | `/best-selling-products` | Best selling products |
| GET | `/export-products/{shop_id}` | Export products CSV |
| POST | `/import-products` | Import products CSV |
| GET | `/products/export` | Export via Excel (admin) |
