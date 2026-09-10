# Address Module — Database Schema

## Table: `address`

**Migration:** `packages/marvel/database/migrations/2020_06_02_051901_create_marvel_tables.php`

| Column | Type | Constraints | Default |
|--------|------|-------------|---------|
| `id` | `bigint unsigned` | PRIMARY KEY, AUTO_INCREMENT | |
| `title` | `varchar(255)` | NOT NULL | |
| `type` | `varchar(255)` | NOT NULL | |
| `default` | `tinyint(1)` | NOT NULL | `0` |
| `address` | `json` | NOT NULL | |
| `location` | `json` | NULLABLE | |
| `customer_id` | `bigint unsigned` | NOT NULL, FK → users.id | |
| `created_at` | `timestamp` | NULLABLE | |
| `updated_at` | `timestamp` | NULLABLE | |

**Indexes:**

| Index Name | Columns | Type |
|-----------|---------|------|
| PRIMARY | `id` | BTREE |
| `address_customer_id_foreign` | `customer_id` | BTREE (FK) |

**Foreign Keys:**

| Column | Reference | On Delete |
|--------|-----------|-----------|
| `customer_id` | `users.id` | No CASCADE specified (default RESTRICT) |

## Query Patterns

### Read Patterns

| Use Case | Query | Notes |
|----------|-------|-------|
| List user addresses | `Address::where('customer_id', $userId)->get()` | No pagination, returns all |
| Single address | `Address::where('customer_id', $userId)->find($id)` | Ownership scoped |

### Write Patterns

| Use Case | Type |
|----------|------|
| Create address | INSERT (with auth user's customer_id) |
| Update address | UPDATE (excluding customer_id) |
| Delete address | DELETE (hard delete, no SoftDeletes) |

## Performance Notes

- **Low complexity**: Single table with no joins or pivots.
- **No pagination**: Address count per user is typically small (< 10).
- **No caching**: No cache layer — direct DB reads.
- **FK index**: `customer_id` is indexed via the foreign key constraint.
