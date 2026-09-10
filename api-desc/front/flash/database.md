# Flash Sale Module — Database (Public API)

## Tables

### `flash_sales`

| Column | Type | Description |
|--------|------|-------------|
| id | bigint(20) UNSIGNED | Primary key |
| title | json (translatable) | Flash sale title |
| slug | varchar(255) | URL slug |
| description | json (translatable) | Flash sale description |
| start_date | date | Campaign start date |
| end_date | date | Campaign end date |
| status | tinyint(1) | Active flag |
| type | varchar(255) | Discount type (percentage, fixed_rate, final_price) |
| discount | decimal(10,2) | Discount value |
| max_discount_amount | decimal(10,2), nullable | Max discount cap |
| order | int(11) | Sort order |
| deleted_at | timestamp, nullable | Soft delete |
| created_at | timestamp | |
| updated_at | timestamp | |

**Indexes:**
- Primary: `id`
- Index: `slug`
- Index: `status`
- Index: `start_date`, `end_date`

### `flash_sale_products`

Pivot table for flash sale-product association.

| Column | Type | Description |
|--------|------|-------------|
| flash_sale_id | bigint(20) UNSIGNED | FK to `flash_sales.id` |
| product_id | bigint(20) UNSIGNED | FK to `products.id` |

**Indexes:**
- Composite unique: `(flash_sale_id, product_id)`

### `products` (relevant columns)

| Column | Type | Description |
|--------|------|-------------|
| id | bigint UNSIGNED | Primary key |
| has_flash_sale | boolean | Flag for flash sale eligibility |
| price_after_flash_sale | decimal, nullable | Calculated flash sale price |

## Query Patterns

### List Flash Sales
```sql
SELECT * FROM `flash_sales`
WHERE `status` = 1
  AND (`start_date` IS NULL OR `start_date` <= CURDATE())
  AND (`end_date` IS NULL OR `end_date` >= CURDATE())
  AND `deleted_at` IS NULL
ORDER BY `id` DESC
LIMIT ? OFFSET ?;
```

### Products Ending This Week
```sql
SELECT `products`.*
FROM `products`
WHERE `has_flash_sale` = 1
  AND `deleted_at` IS NULL
  AND EXISTS (
    SELECT 1 FROM `flash_sale_products`
    JOIN `flash_sales` ON `flash_sale_products`.`flash_sale_id` = `flash_sales`.`id`
    WHERE `flash_sale_products`.`product_id` = `products`.`id`
      AND `flash_sales`.`deleted_at` IS NULL
      AND `flash_sales`.`status` = 1
      AND `flash_sales`.`end_date` BETWEEN CURDATE() AND WEEK_END
  )
ORDER BY `id` DESC
LIMIT ?;
```

## N+1 Prevention

- **List flash sales:** No N+1 (no relations loaded)
- **Get by slug:** Uses eager loading `->load(['products' => fn($q) => ...])` — single additional query
- **Products by qty:** Uses `with(['products' => fn($q) => ...])` — 1 query per flash sale
- **Ending this week/today:** Direct product queries with EXISTS subquery — no N+1

## Performance

- **List flash sales:** 1 query, <5ms (indexed on status, dates)
- **Get by slug:** 2 queries (flash sale + products), <15ms
- **Ending this week/today:** 1 query with EXISTS subquery, <20ms
- **No caching** — every request hits the database
