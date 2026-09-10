# Checkout Module — Database

## Tables

### `orders`

| Column | Type | Description |
|--------|------|-------------|
| id | bigint UNSIGNED | PK |
| user_id | bigint UNSIGNED | FK to users |
| governorate_id | int, nullable | FK to governorates |
| name | varchar(255) | Customer snapshot |
| user_phone | varchar(255) | Customer snapshot |
| user_email | varchar(255) | Customer snapshot |
| address | json | Delivery address |
| fulfillment_type | varchar(255) | delivery/pickup |
| payment_method | varchar(255) | online/cod/pay_at_cashier |
| pickup_location_id | bigint, nullable | FK |
| pickup_location_name/address/phone/coordinates | — | Snapshot |
| price, shipping_price, total_price | decimal(10,2) | Snapshot |
| coupon, coupon_discount, coupon_discount_type, coupon_discount_max_amount | — | Snapshot |
| promotion_id, promotion_code, promotion_type, promotion_discount | — | Snapshot |
| status | varchar(255) | pending/processing/completed/delivered/cancelled |
| deleted_at | timestamp, nullable | Soft delete |

### `transactions`

| Column | Type | Description |
|--------|------|-------------|
| id | bigint UNSIGNED | PK |
| order_id | bigint UNSIGNED | FK |
| user_id | bigint UNSIGNED | FK |
| invoice_id | varchar(255), nullable | Gateway invoice |
| gateway_transaction_id | varchar(255), nullable | Gateway transaction |
| payment_method | varchar(255) | cod/online/pay_at_cashier |
| uuid | varchar(255) | Unique UUID (auto-generated on create) |
| status | varchar(255) | pending/paid/failed |
| amount | decimal(10,2) | |
| currency | varchar(10) | Default currency |
| gateway_response | json, nullable | Gateway raw response + `_callback_type` |
| error_message | varchar/text, nullable | Failure reason |
| paid_at | timestamp, nullable | |

## Key Queries

### Create Order (transactional)
```sql
BEGIN;
SELECT * FROM carts WHERE user_id = ? FOR UPDATE;
SELECT * FROM coupons WHERE code = ? FOR UPDATE;
INSERT INTO orders (...) VALUES (...);
INSERT INTO order_items (...) VALUES (...);
UPDATE cart_items SET quantity = ? WHERE cart_id = ?;
COMMIT;
```

### Payment Callback
```sql
SELECT * FROM transactions WHERE gateway_transaction_id = ? OR invoice_id = ?;
UPDATE transactions SET status = 'paid', paid_at = NOW() WHERE id = ?;
UPDATE orders SET status = 'completed' WHERE id = ?;
```

## N+1 Prevention

- **Order list:** Eager loads orderItems.product (+avg rating, media), productVariant.attributeProducts, transactions, pickupLocation
- **Checkout:** Cart loaded with items, product, productVariant, flash_sales

## Performance

- **Eligible promotions:** 2-5 queries
- **Checkout:** 10-20 queries (locks, inserts, inventory finalization)
- **Callback:** 3-8 queries
- **All mutations use pessimistic locking** (FOR UPDATE) on cart, coupon, order rows
