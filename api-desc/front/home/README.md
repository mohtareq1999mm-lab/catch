# Home Feature - API Investigation

## Feature Name

Home Page & Navigation

## Description

The Home feature provides public home page data aggregation, dynamic navigation data, and channel-aware content delivery. It composes data from 7 different models (sliders, banners, brands, categories, products, coupons, flash sales) into a structured home page response with 14 configurable sections. Supports section filtering, parent category scoping, and channel-based caching (Home vs Fast Shipping).

## Architecture Overview

```
[Client]
    |
    |--- GET  /api/v1/general/home          (Public - Home page sections)
    |--- GET  /api/v1/general/nav-data      (Public - Navigation categories)
    |
    v
[HomeController]
    |
    v
[HomeService]
    |--- Cache layer (120 min TTL, channel-aware keys)
    |--- ProductService (for pricing enrichment)
    |--- CategoryHierarchyService
    |--- HasChannelFilter trait
    |
    v
[Models: Slider, Banner, Brand, Category, Product, Coupon, FlashSale]
    |
    v
[Resources: CategoryHomeResource, CategoryNavbarResource, ProductMiniResource,
            SliderResource, BannerResource, BrandResource, CouponResource, FlashSaleResource]
```

## Key Endpoints

| Method | URI | Controller | Auth |
|--------|-----|-----------|------|
| GET | `/v1/general/home` | `HomeController@index` | No |
| GET | `/v1/general/nav-data` | `HomeController@navData` | No |

## Key Files

| Layer | Path |
|-------|------|
| Controller | `app/Http/Controllers/Api/General/HomeController.php` |
| Service | `app/Services/General/HomeService.php` |
| CategoryHomeResource | `app/Http/Resources/Category/CategoryHomeResource.php` |
| CategoryNavbarResource | `app/Http/Resources/Category/CategoryNavbarResource.php` |
| Channel Context | `app/Contexts/ChannelContext.php` |
| Channel Enum | `app/Enums/Channel.php` |
| Channel Middleware | `app/Http/Middleware/ChannelMiddleware.php` |
| Channel Config | `config/channel.php` |
| HasChannelFilter Trait | `app/Traits/HasChannelFilter.php` |
| CategoryHierarchyService | `app/Services/General/CategoryHierarchyService.php` |
| ProductService | `app/Services/General/ProductService.php` |
| Routes | `routes/api.php` |
| Seeder | `database/seeders/ContentPageSeeder.php` |

## Tech Stack

- **Laravel** with Eloquent ORM
- **Channel Context** system for Home vs Fast Shipping
- **Aggressive Caching** (120 min TTL, per-channel isolation)
- **Section Filtering** via query parameters
- **Spatie Translatable** for category names
- **Spatie Media Library** for images
