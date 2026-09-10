# Database - Dashboard Feature

## Tables Queried

| Table | Fields Used |
|-------|-------------|
| `orders` | total_price, created_at, status, user_id, shipping_price, fast_shipping_fee, coupon_discount, promotion_discount |
| `order_product` | product_id, quantity |
| `products` | id, name, slug, price, sold_quantity, stock_quantity |
| `users` | id, name, email, type, created_at |
| `transactions` | amount, payment_method, type |
| `refunds` | amount |
| `coupons` | id, code, name, usage_count |
| `coupon_usages` | coupon_id, order_id |
| `carts` | id, status, total |
| `cart_items` | cart_id, product_id, quantity, total |
| `categories` | id, name, slug |
| `category_product` | category_id, product_id |
| `payment_reconciliation_results` | total_checked, mismatches, resolved_at |

## Query Patterns

All READ-ONLY aggregations.

| Use Case | Pattern |
|----------|---------|
| Revenue | `SUM(total_price) WHERE status = 'completed'` |
| Order stats | `COUNT(*) GROUP BY status WHERE date_range` |
| Top products | `ORDER BY sold_quantity DESC LIMIT 10` |
| Low stock | `WHERE stock_quantity < 10` |
| Category distribution | `COUNT(*) FROM category_product GROUP BY category_id` |
| Cart analytics | `SUM(total) GROUP BY cart.status` |
