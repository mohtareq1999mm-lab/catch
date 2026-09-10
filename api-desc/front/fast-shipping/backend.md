# Backend - Fast Shipping Feature

## Key Files

### 1. Global Scope - `app/Models/Scopes/FastShippingScope.php`

```php
public function apply(Builder $builder, Model $model)
{
    if (ChannelContext::isFastShipping()) {
        $builder->where('is_fast_shipping_available', true);
    }
}
```

Applied in `Product::booted()` via `AppServiceProvider`.

### 2. Customer Controller - `app/Http/Controllers/Api/General/FastShippingController.php`

| Method | Description |
|--------|-------------|
| `status()` | Delegates to `FastShippingRepository::getStatus()` |
| `products(Request)` | Paginated fast-eligible products |
| `checkout(FastCheckoutRequest)` | Creates fast order |
| `orders()` | User's fast orders |

### 3. Service - `app/Services/General/FastShippingService.php`

| Method | Description |
|--------|-------------|
| `getStatus()` | Delegates to repository |
| `getFastShippingProducts($request)` | `Product::active()->fastShippingAvailable()->paginate()` |
| `createFastOrder($request)` | Transactional: validate → reserve → create order → clear cart |
| `paginateFastOrders($request)` | `Order::fast()->forUser($userId)->paginate()` |

### 4. Admin Repository - `packages/marvel/src/Database/Repositories/FastShippingRepository.php`

| Method | Description |
|--------|-------------|
| `getSettings()` | Reads from `Settings.options.fast_shipping` (cached 3600s) |
| `updateSettings($data)` | Updates settings, clears cache |
| `isGloballyEnabled()` | Check enabled flag |
| `isWithinWorkingHours()` | Check current time vs working hours |
| `isGovernorateEnabled($id)` | Check governorate flag |
| `areProductsFastEligible($ids)` | Validate all products eligible |
| `calculateEta()` | now + duration_minutes |
| `getStatus()` | Full status payload |
| `validateCheckout($request)` | All validation checks |

### 5. Permissions

| Permission | Value |
|------------|-------|
| `VIEW_FAST_SHIPPING` | `view-fast-shipping` |
| `UPDATE_FAST_SHIPPING` | `update-fast-shipping` |

### 6. Database Fields

| Table | Column | Type | Default |
|-------|--------|------|---------|
| `products` | `is_fast_shipping_available` | BOOLEAN | false |
| `governorates` | `is_fast_shipping_enabled` | BOOLEAN | true |
| `orders` | `fast_shipping_fee` | DECIMAL(10,2) | 0.00 |
| `orders` | `shipping_method` | VARCHAR | 'SCHEDULED' |
| `settings` | `options->fast_shipping` | JSON | - |
| `cart_items` | `shipping_method` | VARCHAR | 'SCHEDULED' |
