# Database - Fast Shipping Feature

## Columns

| Table | Column | Type | Default |
|-------|--------|------|---------|
| `products` | `is_fast_shipping_available` | `boolean` | `false` |
| `governorates` | `is_fast_shipping_enabled` | `boolean` | `true` |
| `orders` | `fast_shipping_fee` | `decimal(10,2)` | `0.00` |
| `orders` | `shipping_method` | `varchar` | `SCHEDULED` |
| `settings` | `options->fast_shipping` | `json` | - |
| `cart_items` | `shipping_method` | `varchar` | `SCHEDULED` |

## Indexes

| Table | Index | Columns |
|-------|-------|---------|
| `products` | `products_is_fast_shipping_available_index` | `is_fast_shipping_available` |

## Query Patterns

| Use Case | Query |
|----------|-------|
| Scope filter | `Product::where('is_fast_shipping_available', true)` |
| Governorate check | `Governorate::where('id', $id)->where('is_fast_shipping_enabled', true)` |
| Fast orders | `Order::where('shipping_method', 'FAST')->where('user_id', $userId)` |
| Settings read | `Settings::where('key', 'options')->value('value')->fast_shipping` |
