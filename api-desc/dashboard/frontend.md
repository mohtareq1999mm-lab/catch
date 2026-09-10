# Frontend - Dashboard Feature

## Status

**No dedicated frontend Vue/React components** found. Frontend is a separate SPA.

## Consumption Patterns

All 16 endpoints consumed by admin SPA with `throttle:analytics` (60 req/min).

```javascript
export const dashboardApi = {
  overview()                    // GET /api/v1/dashboard/overview
  revenue()                     // GET /api/v1/dashboard/revenue
  orderStats()                  // GET /api/v1/dashboard/order-stats
  recentOrders(limit)           // GET /api/v1/dashboard/recent-orders
  topProducts(limit)            // GET /api/v1/dashboard/top-products
  categoryStats()               // GET /api/v1/dashboard/category-stats
  lowStock(limit)               // GET /api/v1/dashboard/low-stock
  sales()                       // GET /api/v1/dashboard/sales
  customers()                   // GET /api/v1/dashboard/customers
  products()                    // GET /api/v1/dashboard/products
  orders()                      // GET /api/v1/dashboard/orders
  categories()                  // GET /api/v1/dashboard/categories
  coupons()                     // GET /api/v1/dashboard/coupons
  cart()                        // GET /api/v1/dashboard/cart
  finance()                     // GET /api/v1/dashboard/finance
  reconciliation()              // GET /api/v1/dashboard/reconciliation
}
```

## Frontend Components

```
DashboardPage.vue         → overview   (KPI cards)
RevenueChart.vue          → revenue    (bar/line chart)
OrderStatsWidget.vue      → order-stats (status breakdown)
RecentOrdersTable.vue     → recent-orders
TopProductsTable.vue      → top-products
LowStockAlert.vue         → low-stock
SalesAnalyticsPanel.vue   → sales
CustomerAnalytics.vue     → customers
ProductAnalytics.vue      → products
OrderAnalytics.vue        → orders
CategoryAnalytics.vue     → categories
CouponAnalytics.vue       → coupons
CartAnalytics.vue         → cart
FinanceAnalytics.vue      → finance
ReconciliationPanel.vue   → reconciliation
```
