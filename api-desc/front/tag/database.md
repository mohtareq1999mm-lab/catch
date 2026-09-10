# Tag Module — Database (Public API)

## Tables

### `tags`

| Column | Type | Description |
|--------|------|-------------|
| id | bigint(20) UNSIGNED | Primary key |
| name | varchar(255) | Tag name |
| slug | varchar(255) | URL slug (auto-generated via Sluggable) |
| details | text, nullable | Tag description |
| image | json, nullable | Image data |
| icon | varchar(255), nullable | Icon identifier |
| language | varchar(30) | Language code |
| type_id | int(10) UNSIGNED, nullable | FK to `types.id` |
| created_at | timestamp | |
| updated_at | timestamp | |

**Indexes:**
- Primary: `id`
- Index: `slug`
- Index: `type_id`
- Index: `language`

### `product_tag`

Pivot table for tag-product association.

| Column | Type | Description |
|--------|------|-------------|
| product_id | bigint(20) UNSIGNED | FK to `products.id` |
| tag_id | bigint(20) UNSIGNED | FK to `tags.id` |

**Indexes:**
- Composite unique: `(product_id, tag_id)`

### `types`

Related table for tag type classification.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint(20) UNSIGNED | Primary key |
| name | varchar(255) | Type name |
| slug | varchar(255) | URL slug |
| ... | ... | Other type fields |

## Query Patterns

### List Tags
```sql
SELECT * FROM `tags`;
```
Plus lazy-loaded type (N+1):
```sql
SELECT * FROM `types` WHERE `id` = ?;
```

### Get Tag by Slug
```sql
SELECT * FROM `tags` WHERE `slug` = ? LIMIT 1;
```
Plus lazy-loaded type.

## N+1 Prevention

Currently both endpoints have N+1 on the `type` relationship. The `type` is not eager loaded.

## Performance

- **List tags:** 1 + N queries (N = number of tags), could be slow with 100+ tags
- **Get tag by slug:** 2 queries (tag + type lazy load)
- **No caching** — every request hits the database
