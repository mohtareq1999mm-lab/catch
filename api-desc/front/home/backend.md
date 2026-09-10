# Backend - Home Feature

## Overview

The Home feature is a public, read-only, multi-section API that composes data from 7 models. It uses channel-aware caching with 120-minute TTL and supports section filtering via query parameters.

## Key Files

### 1. Controller - `app/Http/Controllers/Api/General/HomeController.php`

| Method | Description |
|--------|-------------|
| `index(Request)` | Returns home page data (all or filtered sections) |
| `navData(Request)` | Returns category tree for nav bar |
| `resolveSections(Request)` | Private: parses sections/keys param |

### 2. Service - `app/Services/General/HomeService.php`

**Dependencies:** `CategoryHierarchyService`, `ProductService`, `ChannelContext`

**Key Methods:**

| Method | Description |
|--------|-------------|
| `getHomeData($parentCategoryId, $sections)` | Main aggregator - returns 13 section arrays |
| `getNavData($level)` | Category tree for navbar |
| `cacheKey($key)` | Returns channel-prefixed cache key |
| `availableSections()` | Static: returns 14 section filter keys |
| `clearCache($channel)` | Static: clears all home cache keys |

**Data Fetching Methods:**

| Method | Source | Limit |
|--------|--------|-------|
| `getActiveSliders()` | Slider::active()->ordered() | All |
| `getActiveBanners()` | Banner::active()->ordered() | All |
| `getBrands()` | Brand::active() | All |
| `getFlashSalesForOneDay(9)` | FlashSale::valid() where today | 9 |
| `getDiscountEndingTodayOrLowStockProducts()` | Product with has_discount + date/qty | 10 |
| `getNewArrivals(10)` | Product where created_at >= -15 days | 10 |
| `getFlashSaleProductsEndingThisWeek()` | Product with flash sale ending this week | - |
| `getWeeklyCategoryProducts($tree)` | Discounted products in parent categories | - |
| `getAllDiscountProducts()` | Product where has_discount | 10 |
| `getLatestValidCoupons(5)` | Coupon::valid()->orderByDesc('id') | 5 |

### 3. Resources

**CategoryHomeResource:** id, name, slug, image, products_count, details
**CategoryNavbarResource:** id, name, slug, level, image, children (recursive)

### 4. Supporting Infrastructure

**ChannelContext:** Singleton holding current Channel enum (HOME/FAST_SHIPPING)
**ChannelMiddleware:** Reads X-Channel header, sets context
**HasChannelFilter:** Adds WHERE is_fast_shipping_available = false in home channel
**CategoryHierarchyService:** Category tree operations
**ProductService:** Pricing enrichment for product collections
