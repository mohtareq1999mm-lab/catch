# Banner Module — Database (Public API)

## Tables

### `banners`

| Column | Type | Description |
|--------|------|-------------|
| id | bigint(20) UNSIGNED | Primary key |
| title | json (translatable) | Banner title (JSON for multi-locale) |
| slug | varchar(255) | URL slug (auto-generated from English title) |
| description | json (translatable) | Banner description |
| status | tinyint(1), default 1 | Active status |
| order | int(11), default 1 | Sort order |
| created_at | timestamp | |
| updated_at | timestamp | |
| deleted_at | timestamp, nullable | Soft delete |

### `banner_product`

Pivot table for banner-product many-to-many relationship.

| Column | Type | Description |
|--------|------|-------------|
| banner_id | bigint(20) UNSIGNED | FK to `banners.id` |
| product_id | bigint(20) UNSIGNED | FK to `products.id` |

**Indexes:**
- Composite unique: `(banner_id, product_id)`
- Foreign: `banner_id` → `banners.id` (cascade)
- Foreign: `product_id` → `products.id` (cascade)

### `media`

Spatie Media Library (`collection_name`: `banners-desktop`, `banners-mobile`).

## Query Patterns

### List Banners
```sql
SELECT * FROM `banners`
WHERE `status` = 1 AND `deleted_at` IS NULL
[AND `created_at` BETWEEN ? AND ?]
[AND `id` IN (?, ?)]
ORDER BY `id` [ASC|DESC]
LIMIT ?;
```

### Get Banner by Slug
```sql
SELECT * FROM `banners`
WHERE `status` = 1 AND `deleted_at` IS NULL
AND (`slug` LIKE '%summer-sale%' OR `slug->en` LIKE '%summer-sale%')
LIMIT 1;
```
(Optional) Followed by eager loading of products:
```sql
SELECT * FROM `products`
INNER JOIN `banner_product` ON `products`.`id` = `banner_product`.`product_id`
WHERE `banner_product`.`banner_id` = ?
  [AND channel filter]
  AND `products`.`deleted_at` IS NULL;
```

## N+1 Prevention

- **List banners:** No N+1 (no relations loaded)
- **Get banner by slug (with products):** Uses eager loading `->load(['products' => fn($q) => ...])` — single additional query

## Performance

- **List banners:** 1 query, <5ms
- **Get banner by slug:** 1-2 queries, <15ms
- **No caching** — every request hits the database
