# API Documentation - Dashboard Feature

All endpoints require `auth:sanctum`, are rate-limited by `throttle:analytics` (60 req/min), and are under prefix `/api/v1/dashboard`.

---

### 1. Overview

**GET** `/api/v1/dashboard/overview`

```json
{
    "total_revenue": 800.00,
    "todays_revenue": 0.00,
    "total_refunds": 0.00,
    "total_orders": 150,
    "total_products": 45,
    "total_customers": 120,
    "new_customers": 5
}
```

---

### 2. Revenue

**GET** `/api/v1/dashboard/revenue`

`total_revenue`, `todays_revenue`, `monthly_breakdown` (12 months)

---

### 3. Order Stats

**GET** `/api/v1/dashboard/order-stats`

Status breakdown per period (today, weekly, monthly, yearly). Statuses: `pending`, `processing`, `completed`, `cancelled`, `refunded`, `failed`, `local_facility`, `out_for_delivery`.

---

### 4. Recent Orders

**GET** `/api/v1/dashboard/recent-orders?limit=10`

**Query:** `limit` (default 10, max 50). Returns orders with `user` and `pickupLocation` relations.

---

### 5. Top Products

**GET** `/api/v1/dashboard/top-products?limit=10`

Products with `sold_quantity > 0`, ordered by `sold_quantity DESC`.

---

### 6. Category Stats

**GET** `/api/v1/dashboard/category-stats`

`product_distribution` (top 15 by product count), `sales_distribution` (top 15 by sales).

---

### 7. Low Stock

**GET** `/api/v1/dashboard/low-stock?limit=10`

Products where `stock_quantity < 10`.

---

### 8. Sales Analytics

**GET** `/api/v1/dashboard/sales`

`daily_revenue`, `revenue_comparison` (DoD, MoM, YoY), `average_order_value`, `revenue_by_payment_method`, `revenue_by_fulfillment_type`.

---

### 9. Customer Analytics

**GET** `/api/v1/dashboard/customers`

`new_vs_returning`, `monthly_growth`, `top_customers` (by orders + revenue), `customer_lifetime_value`, `active_customers`.

---

### 10. Product Analytics

**GET** `/api/v1/dashboard/products`

`best_selling`, `worst_selling`, `never_sold`, `out_of_stock`, `inventory_value`.

---

### 11. Order Analytics

**GET** `/api/v1/dashboard/orders`

`timeline` (daily/weekly/monthly), `success_rate`, `refund_rate`.

---

### 12. Category Analytics

**GET** `/api/v1/dashboard/categories`

`product_distribution`, `highest_revenue`, `lowest_revenue`, `category_growth`.

---

### 13. Coupon Analytics

**GET** `/api/v1/dashboard/coupons`

`total_usage`, `top_coupons`, `revenue_by_coupon`, `total_coupon_discount`.

---

### 14. Cart Analytics

**GET** `/api/v1/dashboard/cart`

`abandonment_rate`, `most_added_products`, `average_cart_value`, `checkout_dropoff_rate`.

---

### 15. Finance Analytics

**GET** `/api/v1/dashboard/finance`

`gross_revenue`, `net_revenue`, `refund_amount`, `total_discount`, `shipping_revenue`.

---

### 16. Reconciliation

**GET** `/api/v1/dashboard/reconciliation`

`total_checked`, `total_mismatches`, `pending_mismatches`, `resolved_mismatches`, `last_run`.

---

## Business Rules

1. **Rate Limit:** 60 requests/min via `throttle:analytics`
2. **Caching:** 300s TTL on all endpoints except `/reconciliation`
3. **No Pagination:** All results are aggregated summaries
4. **Query Params:** Only `limit` accepted (recent-orders, top-products, low-stock)
