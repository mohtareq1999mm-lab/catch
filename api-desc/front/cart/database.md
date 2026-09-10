# Cart Module — Database (Authenticated API)

## Tables

### `carts`

| Column | Type | Description |
|--------|------|-------------|
| id | bigint(20) UNSIGNED | Primary key |
| user_id | bigint(20) UNSIGNED | FK to users |
| coupon | varchar(255), nullable | Applied coupon code |
| total_price | decimal(10,2), default 0 | Sum of all items' total_price |
| status | varchar(255) | active, checked_out, expired |
| reserved_at | timestamp, nullable | Last inventory reservation timestamp |
| expires_at | timestamp, nullable | Reservation expiry (3 days after last touch) |
| created_at | timestamp | |
| updated_at | timestamp | |

**Indexes:**
- Primary: `id`
- Index: `user_id`
- Index: `status`

### `cart_items`

| Column | Type | Description |
|--------|------|-------------|
| id | bigint(20) UNSIGNED | Primary key |
| cart_id | bigint(20) UNSIGNED | FK to carts |
| product_id | bigint(20) UNSIGNED | FK to products (withTrashed) |
| product_variant_id | bigint(20) UNSIGNED, nullable | FK to product_variants |
| quantity | int(11) | Desired quantity |
| reserved_quantity | int(11) | Currently reserved in inventory |
| price | decimal(10,2) | Unit price at time of add/update |
| total_price | decimal(10,2) | price * quantity |
| discount_amount | decimal(10,2), default 0 | Promotion discount on this item |
| promotion_id | bigint(20) UNSIGNED, nullable | FK to promotions |
| shipping_method | varchar(255) | SCHEDULED or FAST |
| attributes | json, nullable | Variant attribute key-value pairs |
| is_gift | tinyint(1), default 0 | Gift item flag |
| created_at | timestamp | |
| updated_at | timestamp | |

**Indexes:**
- Primary: `id`
- Index: `cart_id`
- Index: `product_id`
- Index: `promotion_id`

## Query Patterns

### Get User's Active Cart
```sql
SELECT * FROM `carts`
WHERE `user_id` = ? AND `status` = 'active'
ORDER BY `id` DESC
LIMIT 1;
```

### List User's Carts (Paginated)
```sql
SELECT * FROM `carts`
WHERE `user_id` = ?
ORDER BY `id` DESC
LIMIT ? OFFSET ?;
```

### Lock Cart for Update (Pessimistic)
```sql
SELECT * FROM `carts`
WHERE `user_id` = ?
LIMIT 1
FOR UPDATE;
```

### Lock Inventory Row
```sql
SELECT * FROM `products` WHERE `id` = ? FOR UPDATE;
-- or
SELECT * FROM `product_variants` WHERE `id` = ? FOR UPDATE;
```

## N+1 Prevention

- **List carts:** Eager loads `items.product`, `items.productVariant.attributeProducts.attributeValue.attribute`
- **Show cart:** Same eager load
- **Store/Update:** Returns cart with items loaded via `$cart->load(...)`

## Performance

- **List carts:** 2 queries (carts pagination + items with relations)
- **Store item:** 5-8 queries (cart lock, inventory lock, item create/update, cart total, promotion check)
- **Delete item:** 4-6 queries (auth check, item lock, stock release, coupon check, cart total)
- **Clear cart:** 2 + (2 * N) queries (cart lock, N items released + N stock rows unlocked)
- **All mutations use `lockForUpdate()`** — row-level locks held until transaction commit
