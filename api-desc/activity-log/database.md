# Database - Activity Log Feature

## Tables

### `activity_log` Table

**Migrations:**
- `2026_07_05_080102_create_activity_log_table.php`
- `2026_07_05_080103_add_event_column_to_activity_log_table.php`
- `2026_07_05_080104_add_batch_uuid_column_to_activity_log_table.php`

| Column | Type | Constraints | Default |
|--------|------|-------------|---------|
| `id` | `bigint unsigned` | PRIMARY KEY, AUTO_INCREMENT | |
| `log_name` | `varchar(255)` | NULLABLE, INDEXED | |
| `description` | `text` | NOT NULL | |
| `subject_type` | `varchar(255)` | NULLABLE | |
| `subject_id` | `bigint unsigned` | NULLABLE | |
| `causer_type` | `varchar(255)` | NULLABLE | |
| `causer_id` | `bigint unsigned` | NULLABLE | |
| `event` | `varchar(255)` | NULLABLE | |
| `properties` | `json` | NULLABLE | |
| `batch_uuid` | `char(36)` | NULLABLE | |
| `created_at` | `timestamp` | NULLABLE | |
| `updated_at` | `timestamp` | NULLABLE | |

**Indexes:** `log_name`, morph indexes on (`subject_type`, `subject_id`) and (`causer_type`, `causer_id`)

## Query Patterns

| Use Case | Query |
|----------|-------|
| All logs (paginated) | `Activity::latest()->paginate(15)` |
| Filter by entity | `Activity::where('log_name', 'products')` |
| Filter by event | `Activity::where('event', 'created')` |
| Filter by user | `Activity::where('causer_id', $userId)` |
| Search | `Activity::where('description', 'like', '%term%')->orWhere('log_name', 'like', '%term%')` |

## Storage Notes

- **Write-heavy table:** Every entity CRUD + order event creates a row
- **Retention:** 60-day configurable TTL; cleanup is manual or scheduled
- **Indexed:** `log_name` and morph columns for efficient filtering
- **No foreign keys:** Subject/causer are polymorphic — no referential integrity
