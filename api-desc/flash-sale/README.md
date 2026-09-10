# Flash Sale Module

## Overview

The Flash Sale module manages time-limited discount campaigns on the e-commerce platform. It provides two separate API surfaces:

- **Admin API** (`/api/v1/flash-sale`) — Full CRUD + reorder, protected by permissions
- **Public API** (`/api/v1/general/flash-sales`) — Read-only, no authentication required

Flash sales are fully translatable (title, description in multiple languages), support desktop + mobile images, maintain a sortable order, associate with products via a many-to-many pivot, and support three discount types: percentage, fixed rate, and final price. The module also includes a vendor request system for stores to request participation in flash sales.

## Key Files

| Layer | File |
|-------|------|
| Admin Controller | `packages/marvel/src/Http/Controllers/FlashSaleController.php` |
| Public Controller | `app/Http/Controllers/Api/General/FlashSaleController.php` |
| Repository | `packages/marvel/src/Database/Repositories/FlashSaleRepository.php` |
| Model | `packages/marvel/src/Database/Models/FlashSale.php` |
| Admin Resource | `packages/marvel/src/Http/Resources/FlashSaleResource.php` |
| Public Resource | `app/Http/Resources/FlashSale/FlashSaleResource.php` |
| Create Request | `packages/marvel/src/Http/Requests/CreateFlashSaleRequest.php` |
| Update Request | `packages/marvel/src/Http/Requests/UpdateFlashSaleRequest.php` |
| Vendor Requests Model | `packages/marvel/src/Database/Models/FlashSaleRequests.php` |
| Vendor Request Controller | `packages/marvel/src/Http/Controllers/FlashSaleVendorRequestController.php` |
| Vendor Request Repository | `packages/marvel/src/Database/Repositories/FlashSaleVendorRequestRepository.php` |
| Public Service | `app/Services/General/FlashSaleService.php` |
| Observer | `app/Observers/FlashSaleObserver.php` |
| Event | `packages/marvel/src/Events/FlashSaleProcessed.php` |
| Listener | `packages/marvel/src/Listeners/FlashSaleProductProcess.php` |
| Admin Routes | `packages/marvel/src/Rest/Routes.php` |
| Public Routes | `routes/api.php` |
| Permissions | `packages/marvel/src/Enums/Permission.php` |
| Flash Sale Type Enum | `packages/marvel/src/Enums/FlashSaleType.php` |
| Migration | `packages/marvel/database/migrations/2023_08_14_173253_create_flash_sales_table.php` |
| Seeder | `database/seeders/FlashSaleSeeder.php` |
| Import | `packages/marvel/src/Imports/Sheets/FlashSalesSheetImport.php` |
| Export | `packages/marvel/src/Exports/Sheets/FlashSalesSheetExport.php` |
| Product Engine Strategy | `app/Services/General/ProductEngine/Strategies/ProductHasFlashSale.php` |
| Product Engine Strategy | `app/Services/General/ProductEngine/Strategies/ProductHasFlashSaleEndToday.php` |
| Product Engine Strategy | `app/Services/General/ProductEngine/Strategies/ProductHasFlashSaleEndThisWeek.php` |
| Tests | `tests/Feature/FlashSales/FlashSaleApiTest.php` |
| Tests | `tests/Feature/FlashSales/FlashSaleReorderTest.php` |
| Tests | `tests/Feature/FlashSales/FlashSaleProductionHardenTest.php` |

## Dependencies

- **Spatie Translatable** (`HasTranslations`) — bilingual title/description (en/ar)
- **Spatie Media Library** (`InteractsWithMedia`) — flash sale image management
- **Spatie Eloquent Sortable** (`SortableTrait`) — draggable reorder
- **Laravel SoftDeletes** — soft delete support
- **Prettus Repository** — repository pattern with caching
- **Cviebrock Sluggable** — not currently used (slug generated manually via `makeSlug()`)

## Permissions

| Permission | Required For |
|------------|-------------|
| `view-flash-sale` | GET /flash-sale, GET /flash-sale/{id} |
| `create-flash-sale` | POST /flash-sale |
| `update-flash-sale` | PUT /flash-sale/{id}, PUT /flash-sale/reorder |
| `delete-flash-sale` | DELETE /flash-sale/{id} |

## Routes

### Admin

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/v1/flash-sale` | List flash sales (paginated, filterable, sortable) |
| POST | `/api/v1/flash-sale` | Create flash sale (with images + product associations) |
| GET | `/api/v1/flash-sale/{id}` | Show flash sale by ID or slug |
| PUT | `/api/v1/flash-sale/{id}` | Update flash sale |
| DELETE | `/api/v1/flash-sale/{id}` | Soft-delete flash sale |
| PUT | `/api/v1/flash-sale/reorder` | Reorder flash sales |
| GET | `/api/v1/product-flash-sale-info` | Get flash sale info by product ID |
| GET | `/api/v1/products-by-flash-sale` | Get products by flash sale slug |

### Vendor Requests

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/v1/vendor-requests-for-flash-sale` | List vendor requests |
| POST | `/api/v1/vendor-requests-for-flash-sale` | Create vendor request |
| GET | `/api/v1/vendor-requests-for-flash-sale/{id}` | Show vendor request |
| PUT | `/api/v1/vendor-requests-for-flash-sale/{id}` | Update vendor request |
| DELETE | `/api/v1/vendor-requests-for-flash-sale/{id}` | Delete vendor request |
| POST | `/api/v1/approve-flash-sale-requested-products` | Approve vendor request |
| POST | `/api/v1/disapprove-flash-sale-requested-products` | Disapprove vendor request |
| GET | `/api/v1/requested-products-for-flash-sale` | Get requested products for flash sale |

### Public

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/api/v1/general/flash-sales` | List active flash sales |
| GET | `/api/v1/general/flash-sales/{slug}` | Get flash sale by slug with products |
| GET | `/api/v1/general/flash-sale-products` | Get flash sale products by quantity set |
| GET | `/api/v1/general/flash-sale-products-ending-this-week` | Products ending this week |
| GET | `/api/v1/general/flash-sale-products-ending-today` | Products ending today |
