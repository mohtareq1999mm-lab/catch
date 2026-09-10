# Dashboard Feature - API Investigation

## Feature Name

Admin Dashboard Analytics

## Description

The Dashboard feature provides 16 admin analytics endpoints covering revenue, orders, products, customers, categories, coupons, carts, sales, finance, and payment reconciliation. Uses `throttle:analytics` rate limiting (60 req/min). All endpoints are read-only aggregations with 300-second caching.

## Architecture

```
[Admin Client]
    |
    |--- GET /api/v1/dashboard/overview             (throttle:analytics)
    |--- GET /api/v1/dashboard/revenue
    |--- GET /api/v1/dashboard/order-stats
    |--- GET /api/v1/dashboard/recent-orders
    |--- GET /api/v1/dashboard/top-products
    |--- GET /api/v1/dashboard/category-stats
    |--- GET /api/v1/dashboard/low-stock
    |--- GET /api/v1/dashboard/sales
    |--- GET /api/v1/dashboard/customers
    |--- GET /api/v1/dashboard/products
    |--- GET /api/v1/dashboard/orders
    |--- GET /api/v1/dashboard/categories
    |--- GET /api/v1/dashboard/coupons
    |--- GET /api/v1/dashboard/cart
    |--- GET /api/v1/dashboard/finance
    |--- GET /api/v1/dashboard/reconciliation
    |
    v
[DashboardController]
    |--- Thin controller — delegates to DashboardService
    |
    v
[DashboardService]
    |--- Direct Eloquent queries (no Repository)
    |--- Cache::remember (300s TTL)
    |--- No FormRequest, no Policy, no API Resource
    |
    v
[Models: Order, Product, User, Transaction, Coupon, CouponUsage, Cart, Category]
```

## Key Endpoints

| Method | URI | Controller Method | Route Name |
|--------|-----|-------------------|------------|
| GET | `/dashboard/overview` | `overview` | overview |
| GET | `/dashboard/revenue` | `revenue` | revenue |
| GET | `/dashboard/order-stats` | `orderStats` | order-stats |
| GET | `/dashboard/recent-orders` | `recentOrders` | recent-orders |
| GET | `/dashboard/top-products` | `topProducts` | top-products |
| GET | `/dashboard/category-stats` | `categoryStats` | category-stats |
| GET | `/dashboard/low-stock` | `lowStock` | low-stock |
| GET | `/dashboard/sales` | `salesAnalytics` | sales |
| GET | `/dashboard/customers` | `customerAnalytics` | customers |
| GET | `/dashboard/products` | `productAnalytics` | products |
| GET | `/dashboard/orders` | `orderAnalytics` | orders |
| GET | `/dashboard/categories` | `categoryAnalytics` | categories |
| GET | `/dashboard/coupons` | `couponAnalytics` | coupons |
| GET | `/dashboard/cart` | `cartAnalytics` | cart |
| GET | `/dashboard/finance` | `financeAnalytics` | finance |
| GET | `/dashboard/reconciliation` | `reconciliation` | reconciliation |

## Key Files

| Layer | Path |
|-------|------|
| Controller | `app/Http/Controllers/Api/General/DashboardController.php` |
| Service | `app/Services/Dashboard/DashboardService.php` |
| Model (Reconciliation) | `app/Models/PaymentReconciliationResult.php` |
| Enum (UserType) | `app/Enums/UserType.php` |
| Translation (EN) | `resources/lang/en/message.php` (18 keys) |
| Translation (AR) | `resources/lang/ar/message.php` (18 keys) |
| Seeder | `database/seeders/DashboardDataSeeder.php` |
| Routes | `packages/marvel/src/Rest/Routes.php` |
| Test | `tests/Feature/DashboardTest.php` |

## Tech Stack

- **Laravel** with Eloquent ORM
- **throttle:analytics** rate limiter (60 req/min)
- **Cache::remember** (300s TTL)
- **No Repository layer** — direct Eloquent queries
- **No FormRequests** — query params only
- **No Policies** — `auth:sanctum` only
- **No API Resources** — raw arrays returned
