# Flash Sale Module — Frontend (Public API)

## Overview

The Flash Sale module manages time-limited discount campaigns on the storefront. Public endpoints allow browsing active flash sales, viewing flash sale details with associated products, and fetching products from flash sales ending soon.

## Key Files

| Layer | File |
|-------|------|
| Controller | `app/Http/Controllers/Api/General/FlashSaleController.php` |
| Service | `app/Services/General/FlashSaleService.php` |
| Resource (Flash Sale) | `app/Http/Resources/FlashSale/FlashSaleResource.php` |
| Resource (Product) | `app/Http/Resources/Product/ProductMiniResource.php` |
| Model | `packages/marvel/src/Database/Models/FlashSale.php` |
| Routes | `routes/api.php` (lines 56-60) |
| Translation (EN) | `resources/lang/en/message.php` |
| Translation (AR) | `resources/lang/ar/message.php` |

## Routes

| Method | Endpoint | Auth | Purpose |
|--------|----------|------|---------|
| GET | `/api/v1/general/flash-sales` | Public | List active flash sales (paginated) |
| GET | `/api/v1/general/flash-sales/{slug}` | Public | Get flash sale by slug with products |
| GET | `/api/v1/general/flash-sale-products` | Public | Get products from flash sales by qty |
| GET | `/api/v1/general/flash-sale-products-ending-this-week` | Public | Products ending within 7 days |
| GET | `/api/v1/general/flash-sale-products-ending-today` | Public | Products ending today |

## Dependencies

- **Spatie Translatable** (`HasTranslations`) — bilingual title/description (en/ar)
- **Spatie Media Library** (`InteractsWithMedia`) — flash sale desktop/mobile images
- **Laravel SoftDeletes** — excludes soft-deleted flash sales
- **Spatie Eloquent Sortable** (`SortableTrait`) — ordering
- **HasChannelFilter** — channel-aware product filtering
- **ProductPricingService** — flash sale price calculation
- **FlashSale scope `valid()`** — filters by status + date range
