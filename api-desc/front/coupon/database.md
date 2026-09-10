# Coupon Module — Database (Public API)

## Tables

### `coupons`

| Column | Type | Description |
|--------|------|-------------|
| id | bigint(20) UNSIGNED | Primary key |
| code | varchar(255) | Unique coupon code |
| slug | varchar(255) | URL slug |
| name | json (translatable) | Coupon name |
| discount_type | varchar(255) | fixed_rate or percentage |
| discount | decimal(10,2) | Discount value |
| max_discount_amount | decimal(10,2), nullable | Max cap for percentage |
| start_date | date, nullable | Validity start |
| end_date | date, nullable | Validity end |
| limiter | int(11), nullable | Max usage count |
| used | int(11), default 0 | Current usage count |
| status | tinyint(1) | Active flag |
| border_color | varchar(255), nullable | UI accent color |
| borderless | tinyint(1), default 0 | UI borderless flag |
| created_at | timestamp | |
| updated_at | timestamp | |

**Indexes:**
- Primary: `id`
- Unique: `code`
- Index: `slug`
- Index: `status`

### `coupon_product`

Pivot for coupon-specific product restrictions.

### `coupon_usages`

Tracks which users used which coupons on which orders.

### `coupon_assignments`

User-specific coupon assignments.

## Query Patterns

### List Valid Coupons
```sql
SELECT * FROM `coupons`
WHERE `status` = 1
  AND (`limiter` IS NULL OR `used` < `limiter`)
  AND (`start_date` IS NULL OR `start_date` <= CURDATE())
  AND (`end_date` IS NULL OR `end_date` >= CURDATE())
  [AND search conditions]
ORDER BY `id` DESC
LIMIT ?;
```

### Find Coupon by Code
```sql
SELECT * FROM `coupons` WHERE `code` = ? LIMIT 1;
```

## N+1 Prevention

- **List coupons:** No N+1 (no relations loaded)
- **Apply coupon:** Single query for coupon lookup, then assignment/validation checks (additional queries)

## Performance

- **List coupons:** 1 query, <5ms
- **Apply coupon:** 2-5 queries (coupon lookup + assignments + usage + cart update), <20ms
- **No caching** — listing hits DB every request
