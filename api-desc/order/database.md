# Database - Order Feature

## Tables Queried

| Table | Usage | Relations |
|-------|-------|-----------|
| `orders` | Primary table | `user`, `pickup_location`, `transactions`, `invoices` |
| `users` | Eager loaded | `order.user` |
| `order_products` | Eager loaded (via `orderItems`) | `order.orderItems` |
| `products` | Eager loaded (via `orderItems.product`) | `orderItems.product` |
| `product_variants` | Eager loaded (via `orderItems.productVariant`) | `orderItems.productVariant` |
| `attribute_products` | Eager loaded (via `productVariant.attributeProducts`) | `productVariant.attributeProducts` |
| `attribute_values` | Eager loaded (via `attributeProducts.attributeValue`) | `attributeProducts.attributeValue` |
| `transactions` | Eager loaded + locked during payment flows | `order.transactions` |
| `pickup_locations` | Eager loaded (via `pickupLocation`) | `order.pickupLocation` |
| `invoices` | Eager loaded (`latestInvoice`) | `order.invoices` |
| `coupon_usages`, `coupon_assignment_usages` | Written on completion | — |
| `activity_log` | Written by queued listeners | — |

## Lifecycle columns on `orders`

Added by `2026_07_27_081603_add_order_status_columns_to_orders_table.php` and related migrations:

| Column | Type | Purpose |
|--------|------|---------|
| `status` | ENUM(`pending`,`processing`,`completed`,`delivered`,`cancelled`) NOT NULL DEFAULT `pending` | Order lifecycle (enum extended by migration `2026_08_19_000001`; SQLite skips MODIFY and behaves as string) |
| `payment_status` | string nullable | `payment-pending\|payment-success\|payment-failed\|payment-refunded` |
| `fulfillment_status` | string nullable | `pending\|processing\|ready_for_pickup\|out_for_delivery\|delivered\|cancelled` |
| `coupon_consumed` | boolean default false | coupon idempotency guard |
| `promotion_consumed` | boolean default false | promotion idempotency guard |
| `paid_at` | timestamp nullable | set when transaction paid |
| `completed_at` | timestamp nullable | set on `completed` |
| `cancelled_at` | timestamp nullable | set on first-time cancel |
| `inventory_restored_at` | timestamp nullable | prevents double inventory restoration |

## Query Pattern

### List (admin shown; customer identical minus non-status filters)

```sql
SELECT * FROM orders
WHERE status = ?              -- if status filter (pending|processing|completed|delivered|cancelled)
  AND user_id = ?             -- if user_id filter
  AND user_email LIKE ?       -- if user_email filter
  AND promotion_id IN (       -- if promotion_name filter
    SELECT code FROM promotions WHERE name LIKE ?
  )
  AND EXISTS (                -- if product_id filter
    SELECT 1 FROM order_products WHERE order_id = orders.id AND product_id = ?
  )
  AND shipping_method = ?     -- if shipping_method filter
  AND DATE(created_at) >= ?   -- if created_from filter
  AND DATE(created_at) <= ?   -- if created_to filter
  AND (name LIKE ? OR user_email LIKE ? OR user_phone LIKE ?) -- if search filter
ORDER BY created_at DESC      -- model global scope
LIMIT ? OFFSET ?
```

### Show

```sql
SELECT * FROM orders WHERE id = ? LIMIT 1        -- also matches tracking number in Marvel controller
```

### Status change (PATCH /orders/{id}/status)

```sql
-- inside one transaction
SELECT * FROM orders WHERE id = ? LIMIT 1 FOR UPDATE;         -- lockForUpdate
UPDATE orders SET status = ?, fulfillment_status = ?, ...lifecycle timestamps... WHERE id = ?;
UPDATE transactions SET status='paid', paid_at=NOW() WHERE order_id = ? AND <matching tx>;   -- on completed
UPDATE transactions SET status='failed' WHERE order_id = ?;                                  -- on cancelled
-- plus coupon usage inserts / promotion counter updates as applicable
```

Events are dispatched inside this transaction; listeners execute asynchronously afterwards.

## Eager Loaded Relations

5 top-level relations, with nested sub-relations for products and attributes — up to 9 tables joined.
