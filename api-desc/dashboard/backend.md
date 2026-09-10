# Backend - Dashboard Feature

## Controller - `app/Http/Controllers/Api/General/DashboardController.php`

16 methods, all following the same pattern:
```php
public function endpoint(Request $request)
{
    try {
        $data = $this->dashboardService->getMethod($request);
        return response()->json(['success' => true, 'message' => __('...'), 'data' => $data]);
    } catch (QueryException $e) {
        return response()->json([...], 500);
    } catch (\Exception $e) {
        return response()->json([...], 500);
    }
}
```

## Service - `app/Services/Dashboard/DashboardService.php`

All data via direct Eloquent queries. Cached 300s via `Cache::remember("dashboard_{key}", 300, fn() => ...)`.

| Method | Model | Query | Limit |
|--------|-------|-------|-------|
| `getOverview()` | Order, Product, User | Sums + counts | - |
| `getRevenueOverview()` | Order | SUM completed orders | - |
| `getOrderStatusOverview()` | Order | GROUP BY status (4 queried, 5 hardcoded 0) | - |
| `getRecentOrders()` | Order | `with('user','pickupLocation')->take($limit)` | 10 (max 50) |
| `getTopSellingProducts()` | Product | `sold_quantity > 0 ORDER BY DESC` | 10 |
| `getCategoryStats()` | Category | Pivot counts | 15 |
| `getLowStockProducts()` | Product | `stock_quantity < 10` | 10 |
| `getSalesAnalytics()` | Order | Revenue comparisons + breakdowns | - |
| `getCustomerAnalytics()` | User, Order | Customer segmentation | - |
| `getProductAnalytics()` | Product | Best/worst/never/out-of-stock | 10 |
| `getOrderAnalytics()` | Order | Timeline + rates | - |
| `getCategoryAnalytics()` | Category | Distribution + growth | 15 |
| `getCouponAnalytics()` | Coupon, CouponUsage | Usage + revenue | - |
| `getCartAnalytics()` | Cart, CartItem | Abandonment + values | - |
| `getReconciliationSummary()` | PaymentReconciliationResult | Mismatch counts | - |
| `getFinanceAnalytics()` | Order, Transaction | Gross/net/refunds/discounts | - |

## Rate Limiter

Defined in `RouteServiceProvider`:
```php
RateLimiter::for('analytics', fn($request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));
```

## Known Issues

1. **Statuses hardcoded to 0:** Only `pending`, `completed`, `cancelled` are queried on order stats. `processing`, `refunded`, `failed`, `local_facility`, `out_for_delivery` are always 0.
2. **No orderBy on recent-orders:** `take($limit)` without `orderBy('created_at', 'desc')`.
3. **Magic numbers:** Low stock threshold (10), category limits (15), product limits (10).

## Routes - `packages/marvel/src/Rest/Routes.php`

```php
Route::middleware(['throttle:analytics'])->prefix('dashboard')->group(function () {
    Route::get('overview', [DashboardController::class, 'overview']);
    Route::get('revenue', [DashboardController::class, 'revenue']);
    // ... 14 more
});
```

Note: Route names differ from controller methods (e.g., `sales` → `salesAnalytics`).
