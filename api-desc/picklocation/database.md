# Database - Pickup Location Feature

## Table: `pickup_locations`

| Column | Type | Constraints |
|--------|------|------------|
| `id` | bigint | PK, auto-increment |
| `store_name` | string | |
| `address` | text | |
| `phone` | string(50) | |
| `email` | string(255) | nullable |
| `latitude` | string(50) | nullable |
| `longitude` | string(50) | nullable |
| `working_hours` | json | nullable |
| `status` | boolean | default true |
| `display_order` | integer | default 0 |
| `is_default` | boolean | default false (included in create migration `2026_07_11_000003_create_pickup_locations_table`) |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |
| `deleted_at` | timestamp | nullable (SoftDeletes) |

## Related Table: `orders`

Columns appended by migration `2026_07_11_000004_add_pickup_location_snapshot_to_orders`:

| Column | Type | Description |
|--------|------|-------------|
| `pickup_location_id` | bigint | nullable FK |
| `pickup_location_name` | string | nullable snapshot |
| `pickup_location_address` | text | nullable snapshot |
| `pickup_location_phone` | string | nullable snapshot |
| `pickup_location_coordinates` | string | nullable snapshot (lat,lng) |

## Key Queries

| Use Case | Pattern |
|----------|---------|
| Admin list (ordered) | `SELECT * FROM pickup_locations ORDER BY display_order ASC, id ASC` |
| Admin list (search) | `WHERE store_name LIKE '%search%'` |
| Active filter | `WHERE status = 1` |
| Inactive filter | `WHERE status = 0` |
| Public list | `WHERE status = 1 AND deleted_at IS NULL ORDER BY display_order ASC, id ASC` |
| Public show | `WHERE id = ? AND status = 1 AND deleted_at IS NULL` |
| Default location | `WHERE is_default = 1 AND status = 1 AND deleted_at IS NULL` |
| Set default (atomic) | `UPDATE pickup_locations SET is_default = 0 WHERE is_default = 1 AND id <> ?` then persist `is_default = 1` on target |
| Promote on delete | `UPDATE pickup_locations SET is_default = 1 WHERE id = (SELECT id FROM pickup_locations WHERE id <> ? AND deleted_at IS NULL ORDER BY id ASC LIMIT 1)` |
| Soft delete | `UPDATE pickup_locations SET deleted_at = NOW() WHERE id = ?` |
