# Data Flow - Dashboard Feature

## Flow: Overview Endpoint

```
Admin Client
  |
  GET /api/v1/dashboard/overview
  Authorization: Bearer <token>
  |
  v
throttle:analytics middleware (60 req/min)
  |
  v
auth:sanctum middleware
  |
  v
DashboardController@overview()
  |
  v
DashboardService::getOverview()
  |
  +-- Cache::remember("dashboard_overview", 300):
  |     |-- total_revenue:   Order::completed()->sum('total_price')
  |     |-- todays_revenue:  Order::completed()->whereDate('created_at', today())->sum('total_price')
  |     |-- total_refunds:   Refund::sum('amount') ?? 0
  |     |-- total_orders:    Order::count()
  |     |-- total_products:  Product::count()
  |     |-- total_customers: User::where('type', 'user')->count()
  |     |-- new_customers:   User::where('type', 'user')->where('created_at', '>=', -30days)->count()
  |
  v
JSON Response
```
