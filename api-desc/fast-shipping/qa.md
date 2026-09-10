# Fast Shipping — QA Test Cases

## 1. Settings (Admin)

| # | Test Case | Steps | Expected |
|---|-----------|-------|----------|
| 1.1 | View settings without permission | GET `/api/v1/fast-shipping/settings` without `view-fast-shipping` permission | 403 Forbidden |
| 1.2 | View settings with permission | GET with `view-fast-shipping` permission | 200 + settings object |
| 1.3 | Update settings without permission | PUT without `update-fast-shipping` permission | 403 Forbidden |
| 1.4 | Update settings with valid data | PUT with `{enabled: true, fee: 30}` | 200 + success message |
| 1.5 | Update settings with invalid duration | PUT with `{duration_minutes: 0}` | 422 validation error |
| 1.6 | Update settings with invalid duration (too high) | PUT with `{duration_minutes: 9999}` | 422 validation error |
| 1.7 | Update settings with invalid hour format | PUT with `{start_hour: "25:00"}` | 422 validation error |
| 1.8 | Update settings with negative fee | PUT with `{fee: -10}` | 422 validation error |
| 1.9 | Update settings with non-boolean enabled | PUT with `{enabled: "yes"}` | 422 validation error |
| 1.10 | Verify settings persist after update | PUT then GET | GET returns updated values |
| 1.11 | Verify cache invalidation | Update settings, immediately GET | Returns updated values (not stale cache) |

## 2. Status (Public)

| # | Test Case | Steps | Expected |
|---|-----------|-------|----------|
| 2.1 | Get status when fast shipping is enabled | Enable globally, within working hours | `available: true` |
| 2.2 | Get status when outside working hours | Set start_hour to future time | `available: false`, `available_again_at` is set |
| 2.3 | Get status when disabled | Set `enabled: false` | `available: false`, `enabled: false` |
| 2.4 | Status endpoint is public | No auth token | 200 OK |

## 3. Products (Public)

| # | Test Case | Steps | Expected |
|---|-----------|-------|----------|
| 3.1 | List all fast shipping products | GET `/api/v1/fast-shipping/products` | 200 + paginated list |
| 3.2 | Filter products by search | GET `?search=keyword` | Returns matching products |
| 3.3 | Products endpoint is public | No auth token | 200 OK |
| 3.4 | Verify only eligible products returned | Create product with `is_fast_shipping_available: false` | Not in results |
| 3.5 | Pagination works | GET `?limit=5&page=2` | Returns page 2 with 5 items |
| 3.6 | Invalid limit capped | GET `?limit=200` | Returns max 100 items |

## 4. Checkout (Public, Auth)

| # | Test Case | Steps | Expected |
|---|-----------|-------|----------|
| 4.1 | Checkout without auth | POST `/api/v1/fast-shipping/checkout` without token | 401 Unauthorized |
| 4.2 | Checkout with valid data | POST with valid governorate, address, items | 200 + order |
| 4.3 | Checkout with invalid governorate | POST with non-existent governorate_id | 422 validation error |
| 4.4 | Checkout with empty cart | POST with no items in cart | 400 "Cart is empty" |
| 4.5 | Checkout with ineligible items | POST with non-fast-shipping items | 422 validation error |
| 4.6 | Checkout outside working hours | Set time outside start/end range | 422 validation error |
| 4.7 | Checkout when globally disabled | Set `enabled: false` | 422 validation error |
| 4.8 | Checkout with COD + pickup | fulfillment_type=pickup + payment_method=cod | 422 "COD not available for pickup" |
| 4.9 | Checkout with online payment | Valid request + payment_method=online | 200 + payment redirect |
| 4.10 | Verify ETA on order | After successful checkout | Order has `eta` set |
| 4.11 | Verify fast shipping fee on order | After successful checkout | Order has `fast_shipping_fee` set |
| 4.12 | Verify inventory reserved | Check stock after checkout | Reserved quantity increased |
| 4.13 | Cart cleared for fast items after checkout | Check cart after successful order | Fast items removed from cart |

## 5. Orders (Public, Auth)

| # | Test Case | Steps | Expected |
|---|-----------|-------|----------|
| 5.1 | List orders without auth | GET `/api/v1/fast-shipping/orders` without token | 401 Unauthorized |
| 5.2 | List own orders | GET with valid token | 200 + paginated orders (user's only) |
| 5.3 | Verify only FAST orders returned | Create non-fast order for same user | Not in results |
| 5.4 | Verify order items loaded | Check response | Each order has `orderItems` with products |
| 5.5 | Pagination works | GET `?limit=5&page=2` | Returns page 2 |

## 6. Toggle Product Fast Shipping (Admin)

| # | Test Case | Steps | Expected |
|---|-----------|-------|----------|
| 6.1 | Toggle product fast shipping | PUT valid product | 200 + `is_fast_shipping_available` updated |
| 6.2 | Toggle non-existent product | PUT invalid product ID | 404 |
| 6.3 | Toggle with missing field | PUT without `is_fast_shipping_available` | 422 validation error |
| 6.4 | Toggle with non-boolean | PUT with string | 422 validation error |

## 7. Toggle Governorate Fast Shipping (Admin)

| # | Test Case | Steps | Expected |
|---|-----------|-------|----------|
| 7.1 | Toggle governorate fast shipping | PUT valid governorate | 200 + `is_fast_shipping_enabled` updated |
| 7.2 | Toggle non-existent governorate | PUT invalid ID | 404 |
| 7.3 | Toggle with missing field | PUT without `is_fast_shipping_enabled` | 422 validation error |

## 8. Channel Filtering

| # | Test Case | Steps | Expected |
|---|-----------|-------|----------|
| 8.1 | X-Channel: fast-shipping header | Send header `X-Channel: fast-shipping` | Products filtered to eligible only |
| 8.2 | X-Channel: home header | Send header `X-Channel: home` | All products returned |
| 8.3 | No X-Channel header | No channel header | Defaults to home behavior |
| 8.4 | Invalid X-Channel (non-strict) | Send `X-Channel: invalid` with strict=false | Falls back to home |
| 8.5 | Invalid X-Channel (strict) | Send `X-Channel: invalid` with strict=true | 400 Bad Request |
