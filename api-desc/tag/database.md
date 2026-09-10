# Tag Module — Database Schema

## Table: `tags`

**Migration:** `packages/marvel/database/migrations/2020_06_02_051901_create_marvel_tables.php`

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | bigint unsigned | PK, auto-increment | Primary key |
| `name` | json | NOT NULL | Translatable name (e.g., `{"en": "Organic", "ar": "عضوي"}`) |
| `slug` | string | NOT NULL | URL-friendly identifier, auto-generated from name |
| `icon` | string | NULLABLE | Icon identifier or file path |
| `image` | json | NULLABLE | Image metadata (JSON object) |
| `created_at` | timestamp | NULLABLE | Creation timestamp |
| `updated_at` | timestamp | NULLABLE | Last update timestamp |

**Indexes:**
- `PRIMARY KEY (id)`
- No unique index on slug (potential for duplicate slugs across languages)

**Notes:**
- No `deleted_at` column — tags use hard deletes (no soft deletes)
- `name` is stored as JSON for translatable support
- `image` is stored as a JSON object (not a media library relationship like categories)

---

## Table: `product_tag` (pivot)

**Migration:** `packages/marvel/database/migrations/2020_06_02_051901_create_marvel_tables.php`

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | bigint unsigned | PK, auto-increment | Primary key |
| `product_id` | bigint unsigned | FK → products.id ON DELETE CASCADE | Associated product |
| `tag_id` | bigint unsigned | FK → tags.id ON DELETE CASCADE | Associated tag |

**Indexes:**
- `PRIMARY KEY (id)`
- No unique constraint on (product_id, tag_id) — duplicates possible

**Foreign Keys:**

| FK | ON DELETE | ON UPDATE |
|----|-----------|-----------|
| `product_tag.product_id` → `products.id` | **CASCADE** | — |
| `product_tag.tag_id` → `tags.id` | **CASCADE** | — |

**Notes:**
- Cascade delete means deleting a tag automatically removes all its product associations
- No `created_at` or `updated_at` timestamps on pivot (standard Laravel pivot)
- No unique constraint — the same product-tag pair could theoretically be inserted multiple times
- No composite index on (product_id, tag_id) for reverse lookup performance

---

## Entity Relationship

```
tags (1) ─────< product_tag >───── (M) products
  │                                    │
  │ id (PK)                            │ id (PK)
  │ name (json, translatable)          │ name (json, translatable)
  │ slug (string)                      │ slug (string)
  │ icon (string, nullable)            │ ...
  │ image (json, nullable)             │
  │ created_at                         │
  │ updated_at                         │
```

## Migration Commands

```bash
# Create tags table
php artisan make:migration create_tags_table

# Add pivot table (if not included in main migration)
php artisan make:migration create_product_tag_table
```

The tags table and `product_tag` pivot are created in the main Marvel migration:
`packages/marvel/database/migrations/2020_06_02_051901_create_marvel_tables.php`
