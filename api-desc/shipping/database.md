# Database - Shipping Feature

## Tables

### countries

| Column | Type | Constraints |
|--------|------|------------|
| `id` | bigint | PK, auto-increment |
| `name` | string (JSON) | translatable |
| `phone_code` | string(10) | nullable |
| `status` | boolean | default true, indexed |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

### governorates

| Column | Type | Constraints |
|--------|------|------------|
| `id` | bigint | PK, auto-increment |
| `country_id` | bigint | FK → countries.id, CASCADE |
| `name` | string (JSON) | translatable |
| `status` | boolean | default true |
| `is_fast_shipping_enabled` | boolean | default false |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |
| | UNIQUE | (country_id, name) |
| | INDEX | country_id |

### cities

| Column | Type | Constraints |
|--------|------|------------|
| `id` | bigint | PK, auto-increment |
| `governorate_id` | bigint | FK → governorates.id, CASCADE |
| `name` | string (JSON) | translatable |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |
| | UNIQUE | (governorate_id, name) |
| | INDEX | governorate_id |

## Key Queries

| Use Case | Pattern |
|----------|---------|
| List (paginated) | `SELECT * FROM {table} WHERE ... ORDER BY id DESC LIMIT ? OFFSET ?` |
| Search (translatable) | `WHERE LOWER(name->"$.en") LIKE ? OR LOWER(name->"$.ar") LIKE ?` |
| Bulk status | `UPDATE {table} SET status = ? WHERE id IN (?)` |
| Governorate create | Transaction: INSERT governorate + optional INSERT shipping_prices |
| Cascade delete | `DELETE FROM countries WHERE id = ?` — deletes governorates + cities via FK |
| Active scope | `WHERE status = 1` |
| Fast shipping scope | `WHERE is_fast_shipping_enabled = 1` |
