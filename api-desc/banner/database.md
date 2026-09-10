# Database - Banner Feature

## Table: `banners`

| Column | Type | Constraints |
|--------|------|------------|
| `id` | bigint | PK |
| `title` | json | translatable |
| `slug` | string | auto-generated from EN title |
| `description` | json | translatable, nullable |
| `status` | boolean | default true |
| `order` | integer | Sortable column |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |
| `deleted_at` | timestamp | nullable (SoftDeletes) |

## Pivot Table: `banner_product`

| Column | Type | Constraints |
|--------|------|------------|
| `id` | bigint | PK |
| `banner_id` | bigint | FK → banners.id, CASCADE |
| `product_id` | bigint | FK → products.id, CASCADE |
| | UNIQUE | (banner_id, product_id) |

## Media Table

Spatie MediaLibrary stores images in `media` table:

| Collection | Purpose |
|------------|---------|
| `banners-desktop` | Desktop banner image |
| `banners-mobile` | Mobile banner image |

## Key Queries

| Use Case | Pattern |
|----------|---------|
| List (ordered) | `SELECT * FROM banners ORDER BY order ASC` |
| List (active) | `WHERE status = 1` |
| With products | `LEFT JOIN banner_product ON ... LEFT JOIN products ON ...` |
| Reorder | `UPDATE banners SET order = ? WHERE id = ?` (per item) |
