# Database - Slider Feature

## Table: `sliders`

Migration is only in test helper (`tests/Concerns/CreatesTestTables.php`), **not** in `database/migrations/`.

| Column | Type | Constraints |
|--------|------|------------|
| `id` | bigint | PK |
| `title` | json | translatable |
| `slug` | string | auto-generated from EN title |
| `status` | boolean | default true |
| `order` | integer | Sortable column |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |
| `deleted_at` | timestamp | nullable (SoftDeletes) |

## Pivot Table: `slider_product`

| Column | Type | Constraints |
|--------|------|------------|
| `id` | bigint | PK |
| `slider_id` | bigint | FK → sliders.id, CASCADE |
| `product_id` | bigint | FK → products.id, CASCADE |
| | UNIQUE | (slider_id, product_id) |

## Media Collections

| Collection | Purpose | Fallback |
|------------|---------|----------|
| `sliders-desktop` | Desktop slider image | `slider-image-desktop` |
| `sliders-mobile` | Mobile slider image | `slider-image-mobile` |

## Key Queries

| Use Case | Pattern |
|----------|---------|
| List (admin) | `SELECT * FROM sliders ORDER BY order ASC` |
| List (public) | `SELECT * FROM sliders WHERE status = 1 ORDER BY order ASC` |
| Active filter | `WHERE status = 1` |
| Sortable by column | `ORDER BY {column} {dir}` |
