# Brand Module — Database (Public API)

## Tables

### `brands`

Primary table for the brand module.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint(20) UNSIGNED | Primary key |
| name | json (translatable) | Brand name (JSON for multi-locale) |
| slug | varchar(255) | URL-friendly identifier |
| details | json (translatable) | Brand description |
| status | tinyint(1), default 1 | Active status |
| order | int(11), default 1 | Sort order (SortableTrait) |
| created_at | timestamp | Creation timestamp |
| updated_at | timestamp | Last update timestamp |
| deleted_at | timestamp, nullable | Soft delete timestamp |

**Indexes:**
- Primary: `id`
- Index: `slug` (unique)
- Index: `status` (for filtering active)
- Index: `deleted_at` (soft delete)

### `brand_product`

Pivot table for the many-to-many relationship between brands and products.

| Column | Type | Description |
|--------|------|-------------|
| brand_id | bigint(20) UNSIGNED | FK to `brands.id` |
| product_id | bigint(20) UNSIGNED | FK to `products.id` |

**Indexes:**
- Composite unique: `(brand_id, product_id)`
- Foreign: `brand_id` → `brands.id` (on delete cascade)
- Foreign: `product_id` → `products.id` (on delete cascade)

### `media`

Spatie Media Library table for brand images.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint(20) UNSIGNED | Primary key |
| model_type | varchar(255) | `Marvel\Database\Models\Brand` |
| model_id | bigint(20) UNSIGNED | FK to `brands.id` |
| collection_name | varchar(255) | `brands-desktop` or `brands-mobile` |
| ... | ... | Standard Spatie media columns |

## Query Patterns

### List Brands
```sql
SELECT * FROM `brands`
WHERE `status` = 1
  AND `deleted_at` IS NULL
  [AND `created_at` >= ?]
  [AND `created_at` <= ?]
  [AND `id` IN (?, ?, ...)]
ORDER BY `id` [ASC|DESC]
LIMIT ?;
```

### Get Brand by Slug
```sql
SELECT * FROM `brands`
WHERE `status` = 1
  AND `deleted_at` IS NULL
  AND (`slug` LIKE '%nike%' OR `slug->en` LIKE '%nike%')
LIMIT 1;
```
Followed by eager loading of products:
```sql
SELECT * FROM `products`
INNER JOIN `brand_product` ON `products`.`id` = `brand_product`.`product_id`
WHERE `brand_product`.`brand_id` = ?
  [AND channel filter]
  AND `products`.`deleted_at` IS NULL;
```

### Brands Products by Quantity
```sql
SELECT * FROM `brands`
WHERE `status` = 1
  AND `deleted_at` IS NULL
  [AND `created_at` BETWEEN ? AND ?]
LIMIT ?;  -- limit_brand

-- Then for each brand:
SELECT * FROM `products`
INNER JOIN `brand_product` ON `products`.`id` = `brand_product`.`product_id`
WHERE `brand_product`.`brand_id` = ?
  [AND channel filter]
LIMIT ?;  -- limit per brand
```

## N+1 Prevention

- **List brands:** No N+1 (no relations loaded)
- **Get brand by slug:** Uses eager loading `->load(['products' => fn($q) => ...])` — single additional query
- **Brands-products:** Uses `with(['products' => fn($q) => ...])` — 1 query for brands + 1 query per brand for products. This CAN cause N+1 if many brands are returned. The current implementation limits brands via `limit($qtyBrand)` (default 10) to mitigate this.

## Performance

- **List brands:** 1 query, <5ms
- **Get brand by slug:** 2 queries (brand + products/media/reviews), <20ms
- **Brands-products:** 1 + N queries (N = number of brands, limited to 10 by default). Potential performance concern if `limit_brand` is set high.
- **No caching** — every request hits the database
