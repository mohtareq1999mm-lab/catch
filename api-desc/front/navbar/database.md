# Navigation Bar — Database

## Tables

### `categories`

The nav-data endpoint queries the `categories` table. This is the primary table involved.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint(20) UNSIGNED | Primary key |
| name | json (translatable) | Category name (stored as JSON for multi-locale) |
| slug | varchar(255) | URL-friendly identifier |
| details | json (translatable) | Category description (not used by nav-data) |
| parent_id | bigint(20) UNSIGNED, nullable | Self-referencing FK to `categories.id` |
| level | int(11), default 0 | Hierarchy depth (auto-calculated) |
| is_featured | tinyint(1), default 0 | Featured flag (not used by nav-data) |
| status | tinyint(1), default 1 | Active status (nav-data filters by `status = 1`) |
| created_at | timestamp | Creation timestamp |
| updated_at | timestamp | Last update timestamp |
| deleted_at | timestamp, nullable | Soft delete timestamp |

**Indexes:**
- Primary: `id`
- Foreign: `parent_id` → `categories.id`
- Index: `slug` (likely unique)
- Index: `status` (for filtering active categories)
- Index: `deleted_at` (for soft delete filtering)

### `media`

Spatie Media Library table for category images.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint(20) UNSIGNED | Primary key |
| model_type | varchar(255) | `Marvel\Database\Models\Category` |
| model_id | bigint(20) UNSIGNED | FK to `categories.id` |
| collection_name | varchar(255) | `categories-desktop` or `categories-mobile` |
| name | varchar(255) | File name |
| file_name | varchar(255) | Stored file name |
| disk | varchar(255) | Storage disk |
| conversions_disk | varchar(255) | Conversions disk |
| size | int(10) UNSIGNED | File size in bytes |
| manipulations | json | Image manipulations |
| custom_properties | json | Custom metadata |
| generated_conversions | json | Conversion status |
| responsive_images | json | Responsive image data |
| order_column | int(10) UNSIGNED, nullable | Sort order |
| created_at | timestamp | Creation timestamp |
| updated_at | timestamp | Last update timestamp |

**Indexes:**
- Composite: `(model_type, model_id, collection_name)` — polymorphic lookup

## Query Pattern

The nav-data endpoint executes one SQL query per request (when cache is cold):

```sql
SELECT *
FROM `categories`
WHERE `parent_id` IS NULL
  AND `status` = 1
  AND `deleted_at` IS NULL
ORDER BY `products_count` DESC;
```

Plus eager-loaded relationships (executed as separate queries):

```sql
-- Children of each parent category
SELECT *
FROM `categories`
WHERE `parent_id` IN (?, ?, ...)
  AND `status` = 1
  AND `deleted_at` IS NULL
ORDER BY `products_count` DESC;

-- Grandchildren of each child category
SELECT *
FROM `categories`
WHERE `parent_id` IN (?, ?, ...)
  AND `status` = 1
  AND `deleted_at` IS NULL
ORDER BY `products_count` DESC;
```

Plus `products_count` subquery:

```sql
SELECT `categories`.*,
  (SELECT COUNT(*)
   FROM `category_product`
   WHERE `category_product`.`category_id` = `categories`.`id`
  ) AS `products_count`
FROM `categories`
...
```

**Total queries when cache is cold:** 4 (1 parent + 1 children + 1 grandchildren + 1 withCount)

**Total queries when cache is warm:** 0 (entirely served from cache)

## N+1 Prevention

The query uses eager loading via `with()` for children and grandchildren, preventing N+1 queries. The `withCount('products')` is a single aggregated subquery per level, not an N+1.

## Cache Invalidation

Cached data is stored in the Laravel cache (typically Redis or file). Cache keys:

- `{channel}:home-nav-bar` — Default (no level)
- `{channel}:home-nav-bar:level:{n}` — Level-specific caches

TTL: 120 seconds.

## Performance

- Worst case (cold cache, 200 categories): ~4 queries, ~20ms
- Best case (warm cache): 0 queries, <5ms
- Scale concern: If categories exceed 500+, consider paginating children or limiting max depth
