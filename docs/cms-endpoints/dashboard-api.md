# Dashboard API

## Overview

The Dashboard module provides analytics and summary endpoints for admin panels. It serves 15 endpoints covering KPI overview, revenue analytics, order status distribution, recent orders, top-selling products, category statistics, low-stock alerts, plus 8 advanced analytics modules: sales, customers, products, orders, categories, coupons, cart, and finance.

**Version:** 1.0
**Base URL:** `/api/v1/dashboard`

---

## Authentication & Authorization

| Aspect | Requirement |
|--------|-------------|
| **Authentication** | `auth:sanctum` |
| **Verified Email** | `verified` |
| **Role** | Any authenticated user (platform-wide data) |

---

## Response Envelope

All endpoints return:

```json
{
    "success": true,
    "message": "Translated message string",
    "data": {}
}
```

Error Response:

```json
{
    "success": false,
    "message": "Error description"
}
```

---

## Endpoints

### 1. GET /dashboard/overview — Main Dashboard Summary

**Purpose:** Returns key KPI metrics for the dashboard overview cards in a single request.

**URL:** `/dashboard/overview`

**Query Parameters:** None

**Business Logic:**
1. Total revenue: SUM of `total_price` from orders with `status = completed`
2. Today's revenue: Same filtered to last 24 hours
3. Total refunds: SUM of `amount` from `refunds` table
4. Total orders: COUNT of all orders
5. Total products: COUNT of all products
6. Total customers: COUNT of users with `customer` permission
7. New customers: COUNT of customers created in last 30 days

**Success Response (200):**
```json
{
    "success": true,
    "message": "Dashboard overview fetched successfully",
    "data": {
        "total_revenue": 152340.50,
        "todays_revenue": 2340.00,
        "total_refunds": 1200.00,
        "total_orders": 1850,
        "total_products": 3420,
        "total_customers": 890,
        "new_customers": 45
    }
}
```

---

### 2. GET /dashboard/revenue — Revenue Analytics

**Purpose:** Revenue breakdown including total all-time revenue, today's revenue, and monthly breakdown for the current year.

**URL:** `/dashboard/revenue`

**Query Parameters:** None

**Business Logic:**
1. Total revenue: SUM of `total_price` from completed orders
2. Today's revenue: Last 24 hours completed orders
3. Monthly breakdown: 12 entries (January–December) for current year, each with month name and total

**Success Response (200):**
```json
{
    "success": true,
    "message": "Revenue data fetched successfully",
    "data": {
        "total_revenue": 152340.50,
        "todays_revenue": 2340.00,
        "monthly_breakdown": [
            { "month": "January", "total": 12500.00 },
            { "month": "February", "total": 14200.00 },
            { "month": "March", "total": 13100.00 },
            { "month": "April", "total": 15800.00 },
            { "month": "May", "total": 16200.00 },
            { "month": "June", "total": 14500.00 },
            { "month": "July", "total": 0 },
            { "month": "August", "total": 0 },
            { "month": "September", "total": 0 },
            { "month": "October", "total": 0 },
            { "month": "November", "total": 0 },
            { "month": "December", "total": 0 }
        ]
    }
}
```

---

### 3. GET /dashboard/order-stats — Order Status Distribution

**Purpose:** Order counts grouped by status for today, weekly (7 days), monthly (30 days), and yearly (365 days) time ranges.

**URL:** `/dashboard/order-stats`

**Query Parameters:** None

**Business Logic:**
- Groups orders by `status` column values: `pending`, `completed`, `delivered`, `cancelled`
- Returns 0 for statuses not present in DB (`processing`, `refunded`, `failed`, `local_facility`, `out_for_delivery`)

**Success Response (200):**
```json
{
    "success": true,
    "message": "Order statistics fetched successfully",
    "data": {
        "today": {
            "pending": 5,
            "processing": 0,
            "completed": 12,
            "cancelled": 1,
            "refunded": 0,
            "failed": 0,
            "local_facility": 0,
            "out_for_delivery": 0
        },
        "weekly": {
            "pending": 15,
            "processing": 0,
            "completed": 85,
            "cancelled": 3,
            "refunded": 0,
            "failed": 0,
            "local_facility": 0,
            "out_for_delivery": 0
        },
        "monthly": {
            "pending": 45,
            "processing": 0,
            "completed": 350,
            "cancelled": 8,
            "refunded": 0,
            "failed": 0,
            "local_facility": 0,
            "out_for_delivery": 0
        },
        "yearly": {
            "pending": 120,
            "processing": 0,
            "completed": 1500,
            "cancelled": 30,
            "refunded": 0,
            "failed": 0,
            "local_facility": 0,
            "out_for_delivery": 0
        }
    }
}
```

---

### 4. GET /dashboard/recent-orders — Recent Orders

**Purpose:** Fetch the latest orders with eager-loaded relations (products, user).

**URL:** `/dashboard/recent-orders`

**Query Parameters:**
| Field | Type | Default | Max | Description |
|-------|------|---------|-----|-------------|
| `limit` | int | 10 | 50 | Number of recent orders to return |

**Business Logic:**
1. Eager loads `products` and `user` relations
2. Limit capped at 50

**Success Response (200):**
```json
{
    "success": true,
    "message": "Recent orders fetched successfully",
    "data": [
        {
            "id": 1,
            "name": "Order #1",
            "status": "completed",
            "total_price": 250.00,
            "created_at": "2024-07-04T10:30:00.000000Z",
            "products": [
                {
                    "id": 15,
                    "name": "Product Name",
                    "pivot": { "product_quantity": 2 }
                }
            ],
            "user": {
                "id": 5,
                "name": "John Doe",
                "email": "john@example.com"
            }
        }
    ]
}
```

---

### 5. GET /dashboard/top-products — Top Selling Products

**Purpose:** Products ranked by `sold_quantity` descending.

**URL:** `/dashboard/top-products`

**Query Parameters:**
| Field | Type | Default | Max | Description |
|-------|------|---------|-----|-------------|
| `limit` | int | 10 | 50 | Number of top products to return |

**Business Logic:**
1. Filters products where `sold_quantity > 0`
2. Orders by `sold_quantity` descending
3. Limit capped at 50

**Success Response (200):**
```json
{
    "success": true,
    "message": "Top selling products fetched successfully",
    "data": [
        {
            "id": 15,
            "name": "Best Selling Product",
            "slug": "best-selling-product",
            "price": 125.00,
            "sold_quantity": 450
        },
        {
            "id": 22,
            "name": "Second Best Product",
            "slug": "second-best-product",
            "price": 75.00,
            "sold_quantity": 320
        }
    ]
}
```

---

### 6. GET /dashboard/category-stats — Category Distribution

**Purpose:** Category-wise product count distribution and sales distribution.

**URL:** `/dashboard/category-stats`

**Query Parameters:**
| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `language` | string | `en` | Language filter for categories |

**Business Logic:**
1. **Product Distribution:** Count of products per category via `category_product` pivot
2. **Sales Distribution:** SUM of `product_quantity` from completed orders per category
3. Both queries limited to 15 categories, ordered descending

**Success Response (200):**
```json
{
    "success": true,
    "message": "Category statistics fetched successfully",
    "data": {
        "product_distribution": [
            {
                "category_id": 1,
                "category_name": "Fruits & Vegetables",
                "product_count": 45
            },
            {
                "category_id": 2,
                "category_name": "Dairy & Eggs",
                "product_count": 30
            }
        ],
        "sales_distribution": [
            {
                "category_id": 1,
                "category_name": "Fruits & Vegetables",
                "total_sales": 12500
            },
            {
                "category_id": 2,
                "category_name": "Dairy & Eggs",
                "total_sales": 8900
            }
        ]
    }
}
```

---

### 7. GET /dashboard/low-stock — Low Stock Products

**Purpose:** Products with quantity below 10 units.

**URL:** `/dashboard/low-stock`

**Query Parameters:**
| Field | Type | Default | Max | Description |
|-------|------|---------|-----|-------------|
| `limit` | int | 10 | 50 | Number of low stock products to return |

**Business Logic:**
1. Filters products with `quantity < 10`
2. Eager loads `type` relation
3. Limit capped at 50

**Success Response (200):**
```json
{
    "success": true,
    "message": "Low stock products fetched successfully",
    "data": [
        {
            "id": 15,
            "name": "Running Low Product",
            "slug": "running-low-product",
            "quantity": 3,
            "price": 25.00,
            "type": {
                "id": 1,
                "name": "Physical"
            }
        }
    ]
}
```

---

---

### 8. GET /dashboard/sales — Sales Analytics

**Purpose:** Comprehensive sales analytics including daily revenue comparisons, AOV, revenue by payment method, and period-over-period changes.

**URL:** `/dashboard/sales`

**Query Parameters:** None

**Business Logic:**
1. Daily revenue broken into today, yesterday, last 7/30 days
2. Revenue comparison (today vs yesterday, this month vs last month, this year vs last year)
3. Average Order Value (AOV) = completed revenue / completed order count
4. Revenue grouped by payment method from transactions table

**Success Response (200):**
```json
{
    "success": true,
    "message": "Sales analytics fetched successfully",
    "data": {
        "daily_revenue": {
            "today": 2340.00,
            "yesterday": 1890.00,
            "last_7_days": 15200.00,
            "last_30_days": 58300.00
        },
        "revenue_comparison": {
            "today_vs_yesterday": {
                "today": 2340.00,
                "yesterday": 1890.00,
                "change": 23.81
            },
            "this_month_vs_last_month": {
                "this_month": 45000.00,
                "last_month": 42000.00,
                "change": 7.14
            },
            "this_year_vs_last_year": {
                "this_year": 250000.00,
                "last_year": 220000.00,
                "change": 13.64
            }
        },
        "average_order_value": 85.50,
        "revenue_by_payment_method": [
            { "method": "stripe", "total": 150000.00 },
            { "method": "paypal", "total": 100000.00 }
        ]
    }
}
```

---

### 9. GET /dashboard/customers — Customer Analytics

**Purpose:** Customer segmentation, growth trends, top customers, lifetime value, and activity levels.

**URL:** `/dashboard/customers`

**Query Parameters:** None

**Business Logic:**
1. New vs returning customers (last 30 days)
2. Monthly customer growth over the past 12 months
3. Top 10 customers by order count and revenue
4. Customer Lifetime Value (CLV) — top 10 by lifetime spend
5. Active customer counts for 7, 30, and 90-day windows

**Success Response (200):**
```json
{
    "success": true,
    "message": "Customer analytics fetched successfully",
    "data": {
        "new_vs_returning": {
            "new_customers": 45,
            "returning_customers": 120
        },
        "monthly_growth": [
            { "month": "2024-01", "count": 30 },
            { "month": "2024-02", "count": 35 }
        ],
        "top_customers": {
            "by_orders": [
                { "id": 1, "name": "John Doe", "email": "john@example.com", "orders": 25 }
            ],
            "by_revenue": [
                { "id": 1, "name": "John Doe", "email": "john@example.com", "revenue": 5000.00 }
            ]
        },
        "customer_lifetime_value": [
            { "id": 1, "name": "John Doe", "email": "john@example.com", "lifetime_value": 15000.00 }
        ],
        "active_customers": {
            "last_7_days": 85,
            "last_30_days": 320,
            "last_90_days": 780
        }
    }
}
```

---

### 10. GET /dashboard/products — Product Analytics

**Purpose:** Product performance data including best/worst sellers, never-sold items, out-of-stock, and inventory valuation.

**URL:** `/dashboard/products`

**Query Parameters:** None

**Business Logic:**
1. Best selling: products with `sold_quantity > 0`, sorted descending
2. Worst selling: products with `sold_quantity > 0`, sorted ascending
3. Never sold: products with `sold_quantity = 0` or null
4. Out of stock: products with `quantity = 0`
5. Inventory value: SUM of `price * quantity` for items with stock

**Success Response (200):**
```json
{
    "success": true,
    "message": "Product analytics fetched successfully",
    "data": {
        "best_selling": [
            { "id": 1, "name": "Popular Item", "slug": "popular-item", "price": 25.00, "sold_quantity": 450 }
        ],
        "worst_selling": [
            { "id": 2, "name": "Slow Item", "slug": "slow-item", "price": 10.00, "sold_quantity": 1 }
        ],
        "never_sold": [
            { "id": 3, "name": "New Item", "slug": "new-item", "price": 15.00, "sold_quantity": 0 }
        ],
        "out_of_stock": [
            { "id": 4, "name": "Unavailable", "slug": "unavailable", "price": 20.00, "quantity": 0 }
        ],
        "inventory_value": 125000.00
    }
}
```

---

### 11. GET /dashboard/orders — Order Analytics

**Purpose:** Order timeline data and success/cancellation/refund rate analysis.

**URL:** `/dashboard/orders`

**Query Parameters:** None

**Business Logic:**
1. Timeline: daily (30 days), weekly (6 months), monthly (2 years) — each with order count and revenue
2. Success rate: completed / total orders
3. Cancelled rate: cancelled / total orders
4. Refund rate: approved refunds / completed orders
5. Dates use smart formatting compatible with both MySQL and SQLite

**Success Response (200):**
```json
{
    "success": true,
    "message": "Order analytics fetched successfully",
    "data": {
        "timeline": {
            "daily": [
                { "date": "2024-07-01", "orders": 12, "revenue": 2400.00 }
            ],
            "weekly": [
                { "week": 27, "orders": 85, "revenue": 17000.00 }
            ],
            "monthly": [
                { "month": "2024-01", "orders": 350, "revenue": 70000.00 }
            ]
        },
        "success_rate": {
            "completed": 85.5,
            "cancelled": 5.2,
            "refunded": 2.1,
            "total": 1850
        },
        "refund_rate": 2.1
    }
}
```

---

### 12. GET /dashboard/categories — Category Analytics

**Purpose:** Category-level performance with product distribution, revenue ranking, and month-over-month growth.

**URL:** `/dashboard/categories`

**Query Parameters:** None

**Business Logic:**
1. Product distribution: product count per category
2. Highest/lowest revenue categories (top/bottom 5 by revenue)
3. Category growth: current month vs previous month revenue with percentage change

**Success Response (200):**
```json
{
    "success": true,
    "message": "Category analytics fetched successfully",
    "data": {
        "product_distribution": [
            { "category_id": 1, "category_name": "Fruits", "product_count": 45 }
        ],
        "highest_revenue": [
            { "category_id": 1, "category_name": "Fruits", "revenue": 25000.00 }
        ],
        "lowest_revenue": [
            { "category_id": 5, "category_name": "Spices", "revenue": 500.00 }
        ],
        "category_growth": [
            {
                "category_id": 1,
                "category_name": "Fruits",
                "current_month": 25000.00,
                "previous_month": 22000.00,
                "change": 13.64
            }
        ]
    }
}
```

---

### 13. GET /dashboard/coupons — Coupon Analytics

**Purpose:** Coupon usage statistics, top coupons by usage count, revenue generated by coupon, and total discount given.

**URL:** `/dashboard/coupons`

**Query Parameters:** None

**Business Logic:**
1. Total coupon usages count
2. Top 10 most-used coupons
3. Revenue per coupon code (from orders with `coupon` field set)
4. Total discount amount (sum of `coupon_discount`)

**Success Response (200):**
```json
{
    "success": true,
    "message": "Coupon analytics fetched successfully",
    "data": {
        "total_usage": 450,
        "top_coupons": [
            { "id": 1, "code": "SUMMER20", "name": "Summer Sale", "usage_count": 150 }
        ],
        "revenue_by_coupon": [
            { "code": "SUMMER20", "revenue": 15000.00 }
        ],
        "total_discount": 3200.00
    }
}
```

---

### 14. GET /dashboard/cart — Cart Analytics

**Purpose:** Shopping cart abandonment tracking, most-added products, average cart value, and checkout funnel analysis.

**URL:** `/dashboard/cart`

**Query Parameters:** None

**Business Logic:**
1. Abandonment rate = (active + expired carts) / total carts
2. Most added products: top 10 products by sum of cart item quantities
3. Average cart value: average `total_price` of active/checked-out carts
4. Checkout drop-off rate: percentage of carts that never checked out

**Success Response (200):**
```json
{
    "success": true,
    "message": "Cart analytics fetched successfully",
    "data": {
        "abandonment_rate": 65.5,
        "most_added_products": [
            { "id": 1, "name": "Popular Item", "slug": "popular-item", "price": 25.00, "total_added": 320 }
        ],
        "average_cart_value": 85.00,
        "checkout_dropoff_rate": 35.2
    }
}
```

---

### 15. GET /dashboard/finance — Finance Analytics

**Purpose:** Financial summary including gross/net revenue, refunds, discounts, and shipping income.

**URL:** `/dashboard/finance`

**Query Parameters:** None

**Business Logic:**
1. Gross revenue: SUM of `total_price` from completed orders
2. Refund amount: SUM of `amount` from approved refunds
3. Total discount: SUM of `coupon_discount` from orders
4. Net revenue = gross revenue - refunds - discounts (min 0)
5. Shipping revenue = SUM of `shipping_price + fast_shipping_fee`

**Success Response (200):**
```json
{
    "success": true,
    "message": "Finance analytics fetched successfully",
    "data": {
        "gross_revenue": 250000.00,
        "net_revenue": 245600.00,
        "refund_amount": 1200.00,
        "total_discount": 3200.00,
        "shipping_revenue": 15000.00
    }
}
```

---

## Error Responses

| Status | Condition |
|--------|-----------|
| 401 | Unauthenticated |
| 409 | Database query error |
| 500 | Internal server error |

---

## Database Impact

| Endpoint | Tables | Query Type |
|----------|--------|------------|
| `/dashboard/overview` | `orders`, `refunds`, `products`, `users` | Multiple aggregate queries |
| `/dashboard/revenue` | `orders` | Aggregate with date grouping |
| `/dashboard/order-stats` | `orders` | Aggregate with status grouping + date filtering |
| `/dashboard/recent-orders` | `orders`, `order_products`, `products`, `users` | Select with eager loads |
| `/dashboard/top-products` | `products` | Select with sort + limit |
| `/dashboard/category-stats` | `categories`, `category_product`, `products`, `order_products`, `orders` | Aggregate with joins |
| `/dashboard/low-stock` | `products`, `types` | Select with filters |
| `/dashboard/sales` | `orders`, `transactions` | Multiple aggregate queries |
| `/dashboard/customers` | `users`, `orders` | Aggregate with joins |
| `/dashboard/products` | `products` | Multiple selects with filters |
| `/dashboard/orders` | `orders`, `refunds` | Aggregate with date grouping |
| `/dashboard/categories` | `categories`, `category_product`, `products`, `order_products`, `orders` | Aggregate with joins |
| `/dashboard/coupons` | `coupon_usages`, `coupons`, `orders` | Aggregate with joins |
| `/dashboard/cart` | `carts`, `cart_items`, `products` | Aggregate with joins |
| `/dashboard/finance` | `orders`, `refunds` | Multiple aggregate queries |

---

## Dependencies

| Class | Type | File |
|-------|------|------|
| `DashboardController` | Controller | `app/Http/Controllers/Api/DashboardController.php` |
| `DashboardService` | Service | `app/Services/Dashboard/DashboardService.php` |
| `Permission` | Enum | `packages/marvel/src/Enums/Permission.php` |
| `Order` | Model | `packages/marvel/src/Database/Models/Order.php` |
| `Product` | Model | `packages/marvel/src/Database/Models/Product.php` |
| `Category` | Model | `packages/marvel/src/Database/Models/Category.php` |
| `User` | Model | `packages/marvel/src/Database/Models/User.php` |
| `Coupon` | Model | `packages/marvel/src/Database/Models/Coupon.php` |
| `Transaction` | Model | `packages/marvel/src/Database/Models/Transaction.php` |
| `Cart` | Model | `packages/marvel/src/Database/Models/Cart.php` |
| `CartItem` | Model | `packages/marvel/src/Database/Models/CartItem.php` |

---

## Route Definitions

```php
Route::prefix('dashboard')->group(function () {
    Route::get('overview', [DashboardController::class, 'overview']);
    Route::get('revenue', [DashboardController::class, 'revenue']);
    Route::get('order-stats', [DashboardController::class, 'orderStats']);
    Route::get('recent-orders', [DashboardController::class, 'recentOrders']);
    Route::get('top-products', [DashboardController::class, 'topProducts']);
    Route::get('category-stats', [DashboardController::class, 'categoryStats']);
    Route::get('low-stock', [DashboardController::class, 'lowStock']);
    // Advanced Analytics
    Route::get('sales', [DashboardController::class, 'salesAnalytics']);
    Route::get('customers', [DashboardController::class, 'customerAnalytics']);
    Route::get('products', [DashboardController::class, 'productAnalytics']);
    Route::get('orders', [DashboardController::class, 'orderAnalytics']);
    Route::get('categories', [DashboardController::class, 'categoryAnalytics']);
    Route::get('coupons', [DashboardController::class, 'couponAnalytics']);
    Route::get('cart', [DashboardController::class, 'cartAnalytics']);
    Route::get('finance', [DashboardController::class, 'financeAnalytics']);
});
```

Source: `packages/marvel/src/Rest/Routes.php` (inside `auth:sanctum`, `verified` middleware group)

---

## Notes

- All dashboard queries run against real database data only
- Revenue calculated as SUM of `total_price` from orders with `status = completed`
- Empty states return `0` for numeric fields or empty arrays for collections
- All endpoints use `GET` method — no data mutation
- Database errors return 409 with a generic error message
