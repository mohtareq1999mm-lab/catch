# Banner Module — Frontend (Public API)

## Overview

The Banner module manages promotional banners displayed on the storefront. Banners are used for hero sections, promotional campaigns, and marketing content. Each banner has translatable title/description, desktop + mobile images, and optional product associations.

## Key Files

| Layer | File |
|-------|------|
| Controller | `app/Http/Controllers/Api/General/BannerController.php` |
| Service | `app/Services/General/BannerService.php` |
| Resource (Public) | `app/Http/Resources/Banner/BannerResource.php` |
| Resource (Product) | `app/Http/Resources/Product/ProductMiniResource.php` |
| Model | `packages/marvel/src/Database/Models/Banner.php` |
| Routes | `routes/api.php` (lines 48-49) |
| Translation (EN) | `resources/lang/en/message.php` |
| Translation (AR) | `resources/lang/ar/message.php` |

## Routes

| Method | Endpoint | Auth | Purpose |
|--------|----------|------|---------|
| GET | `/api/v1/general/banners` | Public | List active banners (filterable) |
| GET | `/api/v1/general/banners/{slug}` | Public | Get banner by slug with optional products |

## Dependencies

- **Spatie Translatable** (`HasTranslations`) — bilingual title/description (en/ar)
- **Spatie Media Library** (`InteractsWithMedia`) — banner desktop/mobile images
- **Laravel SoftDeletes** — excludes soft-deleted banners
- **Spatie Eloquent Sortable** (`SortableTrait`) — banner ordering
- **HasChannelFilter** — channel-aware product filtering
- **ProductPricingService** — product price enrichment
