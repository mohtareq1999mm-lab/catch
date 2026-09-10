# Brand Module — Frontend (Public API)

## Overview

The Brand module provides public read-only endpoints for browsing product brands on the storefront. It exposes three endpoints for listing active brands, viewing a brand by slug with its products, and fetching brand products grouped by quantity.

## Key Files

| Layer | File |
|-------|------|
| Controller | `app/Http/Controllers/Api/General/BrandController.php` |
| Service | `app/Services/General/BrandService.php` |
| Resource (Brand) | `app/Http/Resources/Brand/BrandResource.php` |
| Resource (Product) | `app/Http/Resources/Brand/BrandProductResource.php` |
| Model | `packages/marvel/src/Database/Models/Brand.php` |
| Routes | `routes/api.php` (lines 45-47) |
| Translation (EN) | `resources/lang/en/message.php` |
| Translation (AR) | `resources/lang/ar/message.php` |

## Routes

| Method | Endpoint | Auth | Purpose |
|--------|----------|------|---------|
| GET | `/api/v1/general/brands` | Public | List active brands (filterable) |
| GET | `/api/v1/general/brands/{slug}` | Public | Get brand by slug with enriched products |
| GET | `/api/v1/general/brands-products` | Public | Get brand products by quantity set |

## Dependencies

- **Spatie Translatable** (`HasTranslations`) — bilingual brand names (en/ar)
- **Spatie Media Library** (`InteractsWithMedia`) — brand desktop/mobile images
- **Laravel SoftDeletes** — excludes soft-deleted brands
- **Spatie Eloquent Sortable** (`SortableTrait`) — brand ordering
- **ProductPricingService** — product price enrichment with discounts/flash sales
- **HasChannelFilter** — channel-aware product filtering
